import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../domains/renewal_batch.dart';
import '../domains/renewal_execution.dart';
import '../theme/app_spacing.dart';
import 'renewal_confirm_screen.dart';
import 'renewal_debit_sheet.dart';
import 'renewal_quote_sheet.dart' show renewalTaxNote;

/// "2026-11-10 14:05" in the device's time zone, or "Not available".
String formatBatchDate(DateTime? value) {
  if (value == null) return 'Not available';
  final d = value.toLocal();
  String two(int n) => n.toString().padLeft(2, '0');
  return '${d.year}-${two(d.month)}-${two(d.day)} ${two(d.hour)}:${two(d.minute)}';
}

/// Status chip. The text is the backend's value; colour never implies success
/// for `pending`.
class StatusChip extends StatelessWidget {
  const StatusChip(this.status, {super.key, this.label});

  final String status;

  /// Overrides the default [statusLabel] text.
  final String? label;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final (Color bg, Color fg) = switch (status) {
      'succeeded' ||
      'completed' => (scheme.primaryContainer, scheme.onPrimaryContainer),
      'failed' => (scheme.errorContainer, scheme.onErrorContainer),
      'uncertain' ||
      'needs_review' ||
      'partial' => (scheme.tertiaryContainer, scheme.onTertiaryContainer),
      _ => (scheme.surfaceContainerHighest, scheme.onSurfaceVariant),
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(AppRadius.pill),
      ),
      child: Text(
        label ?? statusLabel(status),
        style: Theme.of(context).textTheme.labelMedium?.copyWith(color: fg),
      ),
    );
  }
}

/// Chip for the DEBIT VERIFICATION status. Only `confirmed` is styled as
/// positive; it is a separate fact from the renewal result.
class DebitStatusChip extends StatelessWidget {
  const DebitStatusChip(this.status, {super.key});

  final String status;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final (Color bg, Color fg) = switch (status) {
      'confirmed' => (scheme.primaryContainer, scheme.onPrimaryContainer),
      'disputed' => (scheme.errorContainer, scheme.onErrorContainer),
      'not_found' => (scheme.tertiaryContainer, scheme.onTertiaryContainer),
      _ => (scheme.surfaceContainerHighest, scheme.onSurfaceVariant),
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(AppRadius.pill),
      ),
      child: Text(
        debitStatusLabel(status),
        style: Theme.of(context).textTheme.labelMedium?.copyWith(color: fg),
      ),
    );
  }
}

/// One renewal batch with its items, from `GET renewal-batches/{id}`. Status
/// values are the backend's: a pending batch is shown as pending, never as a
/// completed renewal.
class RenewalBatchScreen extends StatefulWidget {
  const RenewalBatchScreen({
    super.key,
    required this.controller,
    this.replayed = false,
    this.api,
    this.attempts,
  });

  final RenewalBatchDetailController controller;

  /// True when the backend answered a repeated submission with the existing batch.
  final bool replayed;

  /// Needed to offer "Review & renew". Without it the screen is read-only.
  final ApiClient? api;

  /// Attempt markers. Defaults to secure storage.
  final RenewalAttemptStore? attempts;

  @override
  State<RenewalBatchScreen> createState() => _RenewalBatchScreenState();
}

class _RenewalBatchScreenState extends State<RenewalBatchScreen> {
  RenewalBatchDetailController get _c => widget.controller;
  late final RenewalAttemptStore _store =
      widget.attempts ?? SecureRenewalAttemptStore();

  /// Pending items with an unresolved attempt marker. Null until read: until
  /// then no pending item is offered Renew (fail closed).
  Set<int>? _blocked;
  int _syncs = 0;

  @override
  void initState() {
    super.initState();
    _c.addListener(_syncMarkers);
    _c.load();
    _syncMarkers();
  }

  @override
  void dispose() {
    _c.removeListener(_syncMarkers);
    _c.dispose();
    super.dispose();
  }

  Future<void> _syncMarkers() async {
    final b = _c.batch;
    if (b == null) return;
    final run = ++_syncs;
    final blocked = await blockedItemIds(_store, b);
    if (!mounted || run != _syncs) return;
    setState(() => _blocked = blocked);
  }

  Future<void> _review(RenewalBatchItem i) async {
    final api = widget.api;
    final b = _c.batch;
    if (api == null || b == null) return;
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => RenewalConfirmScreen(
          controller: RenewalExecutionController(
            api: api,
            store: _store,
            batch: b,
            itemId: i.id,
          ),
        ),
      ),
    );
    if (!mounted) return;
    await _c.load();
    await _syncMarkers();
  }

  Future<void> _verifyDebit(RenewalBatchItem i) async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => RenewalDebitSheet(item: i, controller: _c),
    );
    if (!mounted || saved != true) return;
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(const SnackBar(content: Text('Debit verification saved.')));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text('Renewal batch #${_c.batchId}')),
      body: SafeArea(
        child: ListenableBuilder(
          listenable: _c,
          builder: (context, _) {
            final b = _c.batch;
            if (b == null) {
              if (_c.loading) {
                return const Center(child: CircularProgressIndicator());
              }
              return _ErrorBox(
                message: _c.error ?? 'Could not load the renewal batch.',
                onRetry: _c.load,
              );
            }
            return RefreshIndicator(
              onRefresh: _c.load,
              child: ListView(
                padding: const EdgeInsets.all(AppSpacing.md),
                children: [
                  _banner(context, b),
                  if (_c.error != null)
                    Padding(
                      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                      child: Text(
                        _c.error!,
                        key: const ValueKey('batch-refresh-error'),
                        style: TextStyle(
                          color: Theme.of(context).colorScheme.error,
                        ),
                      ),
                    ),
                  _summary(context, b),
                  if (b.items.isNotEmpty) _taxNoteCard(context),
                  const SizedBox(height: AppSpacing.sm),
                  for (final i in b.items) _itemCard(context, i),
                ],
              ),
            );
          },
        ),
      ),
    );
  }

  /// Honest wording: saved is not renewed.
  Widget _banner(BuildContext context, RenewalBatch b) {
    final theme = Theme.of(context);
    final pending = b.status == 'pending';
    final justCreated = _c.initial != null;
    final text = pending
        ? (!justCreated
              ? 'This batch is pending: no renewal has been carried out yet.'
              : widget.replayed
              ? 'This renewal request was already saved. It is pending: no '
                    'renewal has been carried out yet.'
              : 'Renewal request saved. It is pending: no renewal has been '
                    'carried out yet.')
        : 'Batch status: ${statusLabel(b.status)}.';
    return Card(
      key: const ValueKey('batch-banner'),
      color: theme.colorScheme.surfaceContainerHighest,
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.component),
        child: Text(text, style: theme.textTheme.bodyMedium),
      ),
    );
  }

  Widget _summary(BuildContext context, RenewalBatch b) {
    return Card(
      key: const ValueKey('batch-summary'),
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.component),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _row(context, 'Batch ID', '#${b.id}'),
            _row(context, 'Created', formatBatchDate(b.createdAt)),
            _row(
              context,
              'Invoice option',
              '${b.option?.label ?? 'Unknown option'} (${b.invoiceOption})',
            ),
            _row(context, 'Renewal years', '${b.years}'),
            _row(context, 'Status', '', trailing: StatusChip(b.status)),
            _row(context, 'Total items', '${b.total}'),
            _row(context, 'Pending', '${b.count('pending')}'),
            _row(context, 'Succeeded', '${b.count('succeeded')}'),
            _row(context, 'Failed', '${b.count('failed')}'),
            _row(context, 'Uncertain', '${b.count('uncertain')}'),
          ],
        ),
      ),
    );
  }

  /// The GST, VAT and total figures below are stored backend estimates.
  Widget _taxNoteCard(BuildContext context) {
    final theme = Theme.of(context);
    return Card(
      key: const ValueKey('batch-tax-note'),
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.component),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'GST, VAT and Total below are backend-calculated estimates, not '
              'verified amounts.',
              style: theme.textTheme.bodySmall,
            ),
            const SizedBox(height: AppSpacing.xs),
            Text(
              renewalTaxNote,
              style: theme.textTheme.bodyMedium?.copyWith(
                fontWeight: FontWeight.w600,
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// Manual actions for one item. A pending item with an unresolved attempt
  /// marker, and every `submitting` / `uncertain` item, gets Check status and
  /// Refresh only: never Renew.
  List<Widget> _actions(BuildContext context, RenewalBatchItem i) {
    final theme = Theme.of(context);
    final blocked = _blocked;
    if (i.state == 'pending' && blocked == null) return const [];
    final unresolved =
        i.state == 'submitting' ||
        i.state == 'uncertain' ||
        (i.state == 'pending' && blocked!.contains(i.id));
    if (unresolved) {
      final text = i.state == 'pending'
          ? 'An earlier renewal attempt from this device could not be '
                'confirmed. Renewal is blocked for this item.'
          : 'The outcome of this renewal is not confirmed. Do not renew '
                'again. It may need manual review in ResellerClub.';
      return [
        const SizedBox(height: AppSpacing.xs),
        Text(
          text,
          key: ValueKey('unresolved-${i.id}'),
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: AppSpacing.xs),
        Wrap(
          spacing: AppSpacing.sm,
          children: [
            FilledButton.tonal(
              key: ValueKey('check-status-${i.id}'),
              onPressed: _c.checking ? null : _c.checkStatus,
              child: Text(_c.checking ? 'Checking...' : 'Check status'),
            ),
            TextButton(
              key: ValueKey('refresh-${i.id}'),
              onPressed: _c.checking ? null : _c.load,
              child: const Text('Refresh'),
            ),
          ],
        ),
      ];
    }
    if (i.state == 'pending' && widget.api != null) {
      return [
        const SizedBox(height: AppSpacing.xs),
        FilledButton(
          key: ValueKey('review-renew-${i.id}'),
          onPressed: () => _review(i),
          child: const Text('Review & renew'),
        ),
      ];
    }
    return const [];
  }

  /// Saved manual verification details, shown only once the backend returned them.
  List<Widget> _debitDetails(BuildContext context, RenewalBatchItem i) {
    if (i.debitStatus == 'unverified') return const [];
    return [
      Column(
        key: ValueKey('debit-details-${i.id}'),
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (i.debitTransactionId != null)
            _row(context, 'Transaction ID', i.debitTransactionId!),
          if (i.debitAmount != null)
            _row(
              context,
              'Verified amount',
              '${i.debitAmount} ${i.debitCurrency ?? ''}'.trim(),
            ),
          if (i.debitVerifiedAt != null)
            _row(context, 'Verified at', formatBatchDate(i.debitVerifiedAt)),
          if (i.debitNote != null) _row(context, 'Note', i.debitNote!),
        ],
      ),
    ];
  }

  Widget _itemCard(BuildContext context, RenewalBatchItem i) {
    final theme = Theme.of(context);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.component),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    i.domainName,
                    style: theme.textTheme.bodyLarge?.copyWith(
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
              ],
            ),
            _row(
              context,
              'Domain renewal',
              '',
              trailing: StatusChip(i.state, label: renewalStateLabel(i.state)),
            ),
            _row(
              context,
              'Debit verification',
              '',
              trailing: DebitStatusChip(i.debitStatus),
            ),
            _row(context, 'Renewal price', i.money(i.amount)),
            // Backend-calculated at the time the batch was stored: estimates,
            // not confirmed payable amounts.
            _row(context, 'GST (estimate)', i.money(i.gst)),
            _row(context, 'VAT (estimate)', i.money(i.vat)),
            _row(context, 'Total (estimate)', i.money(i.total), strong: true),
            if (i.actionId != null) _row(context, 'Action ID', i.actionId!),
            if (i.invoiceId != null) _row(context, 'Invoice ID', i.invoiceId!),
            ..._debitDetails(context, i),
            ..._actions(context, i),
            if (i.canVerifyDebit)
              Padding(
                padding: const EdgeInsets.only(top: AppSpacing.xs),
                child: OutlinedButton(
                  key: ValueKey('verify-debit-${i.id}'),
                  onPressed: _c.verifying ? null : () => _verifyDebit(i),
                  child: const Text('Verify debit manually'),
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _row(
    BuildContext context,
    String label,
    String value, {
    bool strong = false,
    Widget? trailing,
  }) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(width: 120, child: Text(label)),
          Expanded(
            child: Align(
              alignment: Alignment.centerRight,
              child:
                  trailing ??
                  Text(
                    value,
                    textAlign: TextAlign.end,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      fontWeight: strong ? FontWeight.w700 : FontWeight.w500,
                    ),
                  ),
            ),
          ),
        ],
      ),
    );
  }
}

class _ErrorBox extends StatelessWidget {
  const _ErrorBox({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(message, textAlign: TextAlign.center),
            const SizedBox(height: AppSpacing.sm),
            FilledButton(onPressed: onRetry, child: const Text('Retry')),
          ],
        ),
      ),
    );
  }
}

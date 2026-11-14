import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../domains/domain_selection.dart';
import '../domains/renewal_batch.dart';
import '../domains/renewal_execution.dart';
import '../domains/renewal_quote.dart';
import '../domains/renewal_submit.dart';
import '../domains/selected_renewal.dart';
import '../theme/app_spacing.dart';
import 'renewal_batch_screen.dart';
import 'renewal_quote_sheet.dart' show renewalTaxNote;

const _notAvailable = 'Not available';

const _taxNote = renewalTaxNote;

/// Opens the read-only renewal details for the currently selected domains.
Future<void> showSelectedRenewalDetails(
  BuildContext context, {
  required ApiClient api,
  required DomainSelection selection,
}) {
  final controller = SelectedRenewalController(
    api: api,
    selected: selection.entries,
  );
  return Navigator.of(context).push(
    MaterialPageRoute<void>(
      builder: (_) => SelectedRenewalScreen(
        controller: controller,
        submit: RenewalSubmitController(api: api, selected: controller),
        api: api,
      ),
    ),
  );
}

/// Lists every selected domain with its renewal period and cost, split into
/// priced and not-priced groups, plus the combined renewal cost. When [submit]
/// is given it also offers the two invoice options and "Create renewal batch",
/// which only stores a local batch. Nothing here renews, pays or invoices.
class SelectedRenewalScreen extends StatefulWidget {
  const SelectedRenewalScreen({
    super.key,
    required this.controller,
    this.submit,
    this.api,
    this.attempts,
  });

  final SelectedRenewalController controller;

  /// When non-null the invoice options and batch creation are shown.
  final RenewalSubmitController? submit;

  /// Needed to open the created batch.
  final ApiClient? api;

  /// Attempt markers, passed on to the batch screen. Defaults to secure storage.
  final RenewalAttemptStore? attempts;

  @override
  State<SelectedRenewalScreen> createState() => _SelectedRenewalScreenState();
}

class _SelectedRenewalScreenState extends State<SelectedRenewalScreen> {
  SelectedRenewalController get _c => widget.controller;
  RenewalSubmitController? get _s => widget.submit;

  @override
  void initState() {
    super.initState();
    _c.load();
  }

  @override
  void dispose() {
    _s?.dispose();
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final n = _c.entries.length;
    return Scaffold(
      appBar: AppBar(
        title: Text('Renewal details ($n ${n == 1 ? 'domain' : 'domains'})'),
      ),
      body: SafeArea(
        child: ListenableBuilder(
          listenable: Listenable.merge([_c, ?_s]),
          builder: (context, _) {
            final priced = _c.priced;
            final unpriced = _c.unpriced;
            return ListView(
              padding: const EdgeInsets.all(AppSpacing.md),
              children: [
                if (priced.isNotEmpty) ...[
                  _taxNoteCard(context),
                  _heading(context, 'Price available (${priced.length})'),
                  for (final e in priced) _pricedCard(context, e),
                ],
                if (_c.blocked.isNotEmpty) ...[
                  _heading(
                    context,
                    'Cannot be renewed here (${_c.blocked.length})',
                  ),
                  for (final e in _c.blocked) _blockedCard(context, e),
                ],
                if (unpriced.isNotEmpty) ...[
                  _heading(context, 'No price available (${unpriced.length})'),
                  for (final e in unpriced) _unpricedCard(context, e),
                ],
                if (priced.length > 1 ||
                    (priced.isNotEmpty && unpriced.isNotEmpty))
                  _summary(context, priced.length),
                if (_s != null) ..._submitSection(context),
              ],
            );
          },
        ),
      ),
    );
  }

  /// The renewal cost shown here is the only verified figure; tax and the final
  /// payable amount are not shown and must be confirmed in ResellerClub.
  Widget _taxNoteCard(BuildContext context) {
    final theme = Theme.of(context);
    return Card(
      key: const ValueKey('tax-note'),
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.component),
        child: Row(
          children: [
            Icon(Icons.info_outline, color: theme.colorScheme.primary),
            const SizedBox(width: AppSpacing.component),
            Expanded(
              child: Text(
                _taxNote,
                style: theme.textTheme.bodyMedium?.copyWith(
                  fontWeight: FontWeight.w600,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  // ---- invoice option and batch creation ------------------------------------

  Future<void> _create() async {
    final s = _s;
    final api = widget.api;
    if (s == null || api == null) return;
    final ok = await s.submit();
    if (!mounted) return;
    if (!ok) {
      // An existing active batch is opened (navigation only: nothing is
      // created or executed).
      if (s.existing != null) await _openExisting(s.existing!);
      return;
    }
    final result = s.result!;
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => RenewalBatchScreen(
          controller: RenewalBatchDetailController(
            api: api,
            batchId: result.batch.id,
            initial: result.batch,
          ),
          replayed: result.replayed,
          api: api,
          attempts: widget.attempts,
        ),
      ),
    );
  }

  Future<void> _openExisting(RenewalBatch batch) {
    final api = widget.api;
    if (api == null) return Future.value();
    return Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => RenewalBatchScreen(
          controller: RenewalBatchDetailController(
            api: api,
            batchId: batch.id,
            initial: batch,
          ),
          replayed: true,
          api: api,
          attempts: widget.attempts,
        ),
      ),
    );
  }

  /// The user chooses how customers' invoices are handled BEFORE the batch is
  /// created. The choice is stored in the batch and cannot be changed later.
  /// Both options renew the domain; they only control customer invoices.
  List<Widget> _submitSection(BuildContext context) {
    final theme = Theme.of(context);
    final s = _s!;
    final canCreate =
        widget.api != null &&
        s.option != null &&
        !s.submitting &&
        !s.quoting &&
        s.submittable.isNotEmpty &&
        s.totals != null;
    return [
      _heading(context, "Customers' invoices"),
      Card(
        key: const ValueKey('invoice-options'),
        child: Padding(
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.component,
            vertical: AppSpacing.xs,
          ),
          child: RadioGroup<InvoiceOption>(
            groupValue: s.option,
            onChanged: (v) {
              if (v != null) s.choose(v);
            },
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                for (final o in InvoiceOption.values)
                  RadioListTile<InvoiceOption>(
                    key: ValueKey('invoice-option-${o.apiValue}'),
                    value: o,
                    contentPadding: EdgeInsets.zero,
                    visualDensity: VisualDensity.compact,
                    title: Text(o.label),
                    subtitle: Text(o.detail),
                  ),
              ],
            ),
          ),
        ),
      ),
      if (s.blocked.isNotEmpty) ...[
        const SizedBox(height: AppSpacing.xs),
        Text(
          '${s.blocked.length} selected '
          '${s.blocked.length == 1 ? 'domain cannot' : 'domains cannot'} be '
          'renewed from this app and will not be included in the batch.',
          key: const ValueKey('review-blocked'),
          style: theme.textTheme.bodySmall,
        ),
      ],
      if (s.excluded.isNotEmpty) ...[
        const SizedBox(height: AppSpacing.xs),
        Text(
          '${s.excluded.length} selected '
          '${s.excluded.length == 1 ? 'domain has' : 'domains have'} no '
          'verified price and will not be included in the batch.',
          key: const ValueKey('review-excluded'),
          style: theme.textTheme.bodySmall,
        ),
      ],
      const SizedBox(height: AppSpacing.xs),
      FilledButton(
        key: const ValueKey('submit-renewal'),
        onPressed: canCreate ? _create : null,
        child: s.submitting
            ? const SizedBox(
                height: 18,
                width: 18,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            : const Text('Create renewal batch'),
      ),
      const SizedBox(height: AppSpacing.xs),
      Text(
        'Creating a batch only saves a local record. Nothing is renewed, '
        'charged or invoiced until you review and confirm each domain.',
        key: const ValueKey('submit-note'),
        style: theme.textTheme.bodySmall,
      ),
      if (s.existing != null)
        Padding(
          padding: const EdgeInsets.only(top: AppSpacing.xs),
          child: OutlinedButton(
            key: const ValueKey('open-existing-batch'),
            onPressed: () => _openExisting(s.existing!),
            child: Text('Open existing batch #${s.existing!.id}'),
          ),
        ),
      if (s.error != null)
        Padding(
          padding: const EdgeInsets.only(top: AppSpacing.xs),
          child: Text(
            s.error!,
            key: const ValueKey('submit-error'),
            style: TextStyle(color: theme.colorScheme.error),
          ),
        ),
    ];
  }

  Widget _heading(BuildContext context, String text) => Padding(
    padding: const EdgeInsets.only(top: AppSpacing.sm, bottom: AppSpacing.xs),
    child: Text(text, style: Theme.of(context).textTheme.titleSmall),
  );

  Widget _name(BuildContext context, SelectedRenewalEntry e) => Text(
    e.name,
    style: Theme.of(
      context,
    ).textTheme.bodyLarge?.copyWith(fontWeight: FontWeight.w600),
  );

  Widget _pricedCard(BuildContext context, SelectedRenewalEntry e) {
    final q = e.quote.quote!;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.component),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _name(context, e),
            ..._expiryLines(context, q),
            ..._figures(context, q),
          ],
        ),
      ),
    );
  }

  /// Expiry date and, for an expired domain, the note that ResellerClub decides (as sent
  /// by the backend). Nothing here decides eligibility.
  List<Widget> _expiryLines(BuildContext context, RenewalQuote q) {
    final el = q.eligibility;
    final line = el?.expiryLine;
    final warning = el?.warning;
    final theme = Theme.of(context);
    return [
      if (line != null) _line(context, 'Expiry', line, right: true),
      if (warning != null)
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 3),
          child: Text(
            warning,
            key: ValueKey('expiry-warning-${q.domain}'),
            style: theme.textTheme.bodyMedium?.copyWith(
              color: theme.colorScheme.error,
              fontWeight: FontWeight.w600,
            ),
          ),
        ),
    ];
  }

  /// A priced domain the backend refuses to renew: name, expiry and the reason.
  Widget _blockedCard(BuildContext context, SelectedRenewalEntry e) {
    final q = e.quote.quote!;
    return Card(
      key: ValueKey('blocked-${e.id}'),
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.component),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _name(context, e),
            ..._expiryLines(context, q),
            Text(
              'Not included in the batch.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
        ),
      ),
    );
  }

  /// Period and renewal cost exactly as the backend returned them. No tax or
  /// total is shown (see [_taxNote]).
  List<Widget> _figures(BuildContext context, RenewalQuote q) {
    return [
      _line(
        context,
        'Renewal period',
        q.years == 1 ? '1 year' : '${q.years} years',
        right: true,
      ),
      _line(
        context,
        'Renewal cost',
        q.formattedAmount!,
        strong: true,
        right: true,
      ),
    ];
  }

  /// Totals across the priced domains, summed from the individual quotes.
  Widget _summary(BuildContext context, int count) {
    final t = _c.totals;
    return Card(
      key: const ValueKey('renewal-summary'),
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.component),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'All priced domains ($count)',
              style: Theme.of(
                context,
              ).textTheme.bodyLarge?.copyWith(fontWeight: FontWeight.w600),
            ),
            if (_c.unpriced.isNotEmpty)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 3),
                child: Text(
                  'Not included (no price): '
                  '${_c.unpriced.map((e) => e.name).join(', ')}',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ),
            _line(
              context,
              'Combined renewal cost',
              t == null ? _notAvailable : t.money(t.amount),
              strong: true,
              right: true,
            ),
          ],
        ),
      ),
    );
  }

  Widget _unpricedCard(BuildContext context, SelectedRenewalEntry e) {
    final String message;
    if (e.loading) {
      message = 'Loading price…';
    } else if (e.failed) {
      message = e.quote.error!;
    } else if (e.unsupported) {
      message = 'Price unsupported: this extension has no verified price yet.';
    } else if (e.quote.quote?.status == 'domain_not_listed') {
      message = 'Not listed by the provider, so it cannot be priced.';
    } else {
      message = 'A renewal price is not available right now.';
    }
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.component),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _name(context, e),
            _line(
              context,
              'Renewal period',
              _c.years == 1 ? '1 year' : '${_c.years} years',
            ),
            Row(
              children: [
                if (e.loading)
                  const Padding(
                    padding: EdgeInsets.only(right: AppSpacing.sm),
                    child: SizedBox(
                      width: 14,
                      height: 14,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    ),
                  ),
                Expanded(child: Text(message)),
                if (e.failed)
                  TextButton(
                    onPressed: () => _c.retry(e),
                    child: const Text('Retry'),
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _line(
    BuildContext context,
    String label,
    String value, {
    bool strong = false,
    bool right = false,
  }) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 120,
            child: Text(label, style: theme.textTheme.bodyMedium),
          ),
          Expanded(
            child: Text(
              value,
              textAlign: right ? TextAlign.end : TextAlign.start,
              style: theme.textTheme.bodyMedium?.copyWith(
                fontWeight: strong ? FontWeight.w700 : FontWeight.w500,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

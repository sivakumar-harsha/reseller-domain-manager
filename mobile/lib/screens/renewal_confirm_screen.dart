import 'package:flutter/material.dart';

import '../domains/renewal_batch.dart';
import '../domains/renewal_execution.dart';
import '../theme/app_spacing.dart';
import 'renewal_batch_screen.dart' show StatusChip;

/// Confirmation of ONE stored batch item. Every figure and the invoice option
/// come from the stored batch, unchanged. Amounts are estimates, never a final
/// invoice. Renew is a manual action behind an acknowledgement: nothing here
/// runs by itself, retries, or resends.
class RenewalConfirmScreen extends StatefulWidget {
  const RenewalConfirmScreen({super.key, required this.controller});

  final RenewalExecutionController controller;

  @override
  State<RenewalConfirmScreen> createState() => _RenewalConfirmScreenState();
}

class _RenewalConfirmScreenState extends State<RenewalConfirmScreen> {
  RenewalExecutionController get _c => widget.controller;

  @override
  void initState() {
    super.initState();
    _c.start(); // reads the attempt marker only; sends nothing
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Review renewal')),
      body: SafeArea(
        child: ListenableBuilder(
          listenable: _c,
          builder: (context, _) {
            final item = _c.item;
            if (item == null) {
              return Center(
                child: Text(_c.message ?? 'This renewal item was not found.'),
              );
            }
            return ListView(
              padding: const EdgeInsets.all(AppSpacing.md),
              children: [
                _detailsCard(context, item),
                const SizedBox(height: AppSpacing.sm),
                _invoiceCard(context),
                const SizedBox(height: AppSpacing.sm),
                _statusSection(context, item),
                ..._actionSection(context),
              ],
            );
          },
        ),
      ),
    );
  }

  Widget _detailsCard(BuildContext context, RenewalBatchItem item) {
    final theme = Theme.of(context);
    final years = _c.batch.years;
    return Card(
      key: const ValueKey('confirm-details'),
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.component),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    item.domainName,
                    key: const ValueKey('confirm-domain'),
                    style: theme.textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
                StatusChip(item.state),
              ],
            ),
            _row(
              context,
              'Renewal period',
              '$years ${years == 1 ? 'year' : 'years'}',
            ),
            const Divider(),
            Text(
              'Estimates, not a final invoice',
              key: const ValueKey('confirm-estimate-heading'),
              style: theme.textTheme.titleSmall,
            ),
            _row(context, 'Renewal cost (base)', item.money(item.amount)),
            _row(context, 'GST (estimate)', item.money(item.gst)),
            _row(context, 'VAT (estimate)', item.money(item.vat)),
            _row(
              context,
              'Estimated total',
              item.money(item.total),
              strong: true,
              valueKey: const ValueKey('confirm-total'),
            ),
            const SizedBox(height: AppSpacing.xs),
            Text(
              'These amounts were calculated when the batch was created. The '
              'final amount is confirmed by the provider and may differ.',
              key: const ValueKey('confirm-estimate-note'),
              style: theme.textTheme.bodySmall,
            ),
          ],
        ),
      ),
    );
  }

  Widget _invoiceCard(BuildContext context) {
    final theme = Theme.of(context);
    final batch = _c.batch;
    final option = batch.option;
    return Card(
      key: const ValueKey('confirm-invoice-option'),
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.component),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text("Customers' invoices", style: theme.textTheme.titleSmall),
            const SizedBox(height: AppSpacing.xs),
            Row(
              children: [
                const Icon(Icons.radio_button_checked, size: 20),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: Text(
                    option?.label ?? 'Unknown option',
                    style: theme.textTheme.bodyLarge,
                  ),
                ),
              ],
            ),
            const SizedBox(height: AppSpacing.xs),
            Text(
              'API value: ${batch.invoiceOption}',
              key: const ValueKey('confirm-invoice-api-value'),
              style: theme.textTheme.bodyMedium?.copyWith(
                fontWeight: FontWeight.w600,
              ),
            ),
            const SizedBox(height: AppSpacing.xs),
            Text(
              'Chosen when the batch was created; it cannot be changed. Both '
              'options renew the domain and only control how customers\' '
              'invoices are handled.',
              style: theme.textTheme.bodySmall,
            ),
          ],
        ),
      ),
    );
  }

  Widget _statusSection(BuildContext context, RenewalBatchItem item) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final text = _c.message;
    final phase = _c.phase;
    final Widget? headline = switch (phase) {
      RenewPhase.succeeded => Text(
        'Renewal succeeded.',
        key: const ValueKey('confirm-result'),
        style: theme.textTheme.titleSmall,
      ),
      RenewPhase.failed => Text(
        'Renewal failed${item.errorCode == null ? '' : ' (${item.errorCode})'}. '
        'It will not be retried.',
        key: const ValueKey('confirm-result'),
        style: theme.textTheme.titleSmall?.copyWith(color: scheme.error),
      ),
      RenewPhase.unconfirmed || RenewPhase.inProgress => Text(
        'Outcome not confirmed. Do not renew again.',
        key: const ValueKey('confirm-result'),
        style: theme.textTheme.titleSmall?.copyWith(color: scheme.error),
      ),
      RenewPhase.checking => const Text(
        'Checking this item...',
        key: ValueKey('confirm-result'),
      ),
      _ => null,
    };
    final ids = <Widget>[
      if (item.actionId != null) _row(context, 'Action ID', item.actionId!),
      if (item.invoiceId != null) _row(context, 'Invoice ID', item.invoiceId!),
      if (item.providerMessage != null)
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 3),
          child: Text(
            'ResellerClub response: ${item.providerMessage}',
            key: const ValueKey('provider-message'),
            style: theme.textTheme.bodyMedium?.copyWith(
              fontWeight: FontWeight.w600,
            ),
          ),
        ),
    ];
    if (headline == null && text == null && ids.isEmpty) {
      return const SizedBox.shrink();
    }
    return Card(
      key: const ValueKey('confirm-status'),
      color: scheme.surfaceContainerHighest,
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.component),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            ?headline,
            if (text != null)
              Padding(
                padding: EdgeInsets.only(top: headline == null ? 0 : 4),
                child: Text(text, key: const ValueKey('confirm-message')),
              ),
            ...ids,
          ],
        ),
      ),
    );
  }

  List<Widget> _actionSection(BuildContext context) {
    final theme = Theme.of(context);
    if (_c.showRenew) {
      final total = _c.item!.money(_c.item!.total);
      return [
        const SizedBox(height: AppSpacing.sm),
        CheckboxListTile(
          key: const ValueKey('confirm-ack'),
          value: _c.acknowledged,
          contentPadding: EdgeInsets.zero,
          controlAffinity: ListTileControlAffinity.leading,
          onChanged: _c.phase == RenewPhase.ready
              ? (v) => _c.setAcknowledged(v ?? false)
              : null,
          title: Text(
            'I understand this will charge my reseller account approximately '
            '$total and cannot be undone.',
          ),
        ),
        const SizedBox(height: AppSpacing.sm),
        FilledButton(
          key: const ValueKey('confirm-renew'),
          onPressed: _c.canRenew ? _c.renew : null,
          child: _c.phase == RenewPhase.sending
              ? const SizedBox(
                  height: 18,
                  width: 18,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : const Text('Renew'),
        ),
      ];
    }
    if (_c.showRecovery) {
      return [
        const SizedBox(height: AppSpacing.sm),
        Wrap(
          spacing: AppSpacing.sm,
          children: [
            FilledButton.tonal(
              key: const ValueKey('confirm-check-status'),
              onPressed: _c.busy ? null : _c.checkStatus,
              child: Text(_c.checkingStatus ? 'Checking...' : 'Check status'),
            ),
            OutlinedButton(
              key: const ValueKey('confirm-refresh'),
              onPressed: _c.busy ? null : _c.refresh,
              child: const Text('Refresh'),
            ),
          ],
        ),
        const SizedBox(height: AppSpacing.xs),
        Text(
          'Check status only looks up recorded actions at the provider. It '
          'never renews. An unconfirmed renewal may need manual review in '
          'ResellerClub.',
          style: theme.textTheme.bodySmall,
        ),
      ];
    }
    return [
      const SizedBox(height: AppSpacing.sm),
      Wrap(
        spacing: AppSpacing.sm,
        runSpacing: AppSpacing.xs,
        children: [
          OutlinedButton(
            key: const ValueKey('confirm-refresh'),
            onPressed: _c.busy ? null : _c.refresh,
            child: const Text('Refresh'),
          ),
          // Navigation only: returns to the first route (the domain list).
          // It never calls the controller, so nothing is renewed or resent.
          FilledButton.tonal(
            key: const ValueKey('confirm-back-to-domains'),
            onPressed: _backToDomains,
            child: const Text('Back to Domains'),
          ),
        ],
      ),
    ];
  }

  void _backToDomains() {
    Navigator.of(context).popUntil((route) => route.isFirst);
  }

  Widget _row(
    BuildContext context,
    String label,
    String value, {
    bool strong = false,
    Key? valueKey,
  }) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(flex: 5, child: Text(label)),
          Expanded(
            flex: 4,
            child: Text(
              value,
              key: valueKey,
              textAlign: TextAlign.end,
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

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../domains/domain.dart';
import '../domains/renewal_quote.dart';
import '../theme/app_spacing.dart';

/// Shown wherever a renewal cost appears: tax and the final payable amount are
/// not verified by this app.
const renewalTaxNote =
    'Tax and final payable amount must be confirmed in ResellerClub.';

/// Opens the read-only renewal quote for [domain]. Fetches the quote (GET only)
/// when shown; it offers no way to renew, pay or schedule anything.
Future<void> showRenewalQuoteSheet(
  BuildContext context, {
  required ApiClient api,
  required Domain domain,
}) {
  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    showDragHandle: true,
    builder: (_) => RenewalQuoteSheet(
      controller: RenewalQuoteController(api: api, domainId: domain.id),
      domainName: domain.domainName,
    ),
  );
}

class RenewalQuoteSheet extends StatefulWidget {
  const RenewalQuoteSheet({
    super.key,
    required this.controller,
    required this.domainName,
  });

  final RenewalQuoteController controller;
  final String domainName;

  @override
  State<RenewalQuoteSheet> createState() => _RenewalQuoteSheetState();
}

class _RenewalQuoteSheetState extends State<RenewalQuoteSheet> {
  RenewalQuoteController get _c => widget.controller;

  @override
  void initState() {
    super.initState();
    _c.load();
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(
          AppSpacing.md,
          0,
          AppSpacing.md,
          AppSpacing.md,
        ),
        child: ListenableBuilder(
          listenable: _c,
          builder: (context, _) => Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('Renewal quote', style: theme.textTheme.titleMedium),
              const SizedBox(height: AppSpacing.xs),
              Text(
                widget.domainName,
                style: theme.textTheme.bodyLarge?.copyWith(
                  fontWeight: FontWeight.w600,
                ),
              ),
              const SizedBox(height: AppSpacing.component),
              _content(context),
            ],
          ),
        ),
      ),
    );
  }

  Widget _content(BuildContext context) {
    if (_c.loading) {
      return const Padding(
        padding: EdgeInsets.all(AppSpacing.lg),
        child: Center(child: CircularProgressIndicator()),
      );
    }
    final error = _c.error;
    if (error != null) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(error),
          const SizedBox(height: AppSpacing.component),
          FilledButton(onPressed: _c.load, child: const Text('Retry')),
        ],
      );
    }
    final q = _c.quote;
    if (q == null) return const SizedBox.shrink();
    if (!q.available) {
      return Text(_unavailableText(q.status));
    }
    final theme = Theme.of(context);
    final expiry = q.eligibility?.expiryLine;
    final warning = q.eligibility?.warning;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (expiry != null) _row(context, 'Expiry', expiry),
        if (warning != null)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 3),
            child: Text(
              warning,
              key: const ValueKey('expiry-warning'),
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.error,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        _row(
          context,
          'Renewal period',
          q.years == 1 ? '1 year' : '${q.years} years',
        ),
        _row(context, 'Renewal cost', q.formattedAmount!, strong: true),
        const SizedBox(height: AppSpacing.component),
        Text(
          renewalTaxNote,
          key: const ValueKey('tax-note'),
          style: theme.textTheme.bodyMedium?.copyWith(
            fontWeight: FontWeight.w600,
          ),
        ),
      ],
    );
  }

  Widget _row(
    BuildContext context,
    String label,
    String value, {
    bool strong = false,
  }) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 130,
            child: Text(label, style: theme.textTheme.bodyMedium),
          ),
          Expanded(
            child: Text(
              value,
              style: theme.textTheme.bodyMedium?.copyWith(
                fontWeight: strong ? FontWeight.w700 : FontWeight.w500,
              ),
            ),
          ),
        ],
      ),
    );
  }

  static String _unavailableText(String status) {
    switch (status) {
      case 'unsupported_tld':
        return 'No price is available for this domain extension.';
      case 'domain_not_listed':
        return 'This domain is not listed by the provider, so it cannot be quoted.';
      default:
        return 'A renewal price is not available for this domain right now.';
    }
  }
}

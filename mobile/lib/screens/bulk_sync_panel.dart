import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../domains/balance.dart';
import '../theme/app_colors.dart';
import '../theme/app_spacing.dart';
import '../domains/bulk_sync.dart';

/// The more-actions menu ("Re-sync all domain details") plus the progress, summary and
/// failed-domain list of a running or finished batch.
class BulkSyncPanel extends StatelessWidget {
  const BulkSyncPanel({
    super.key,
    required this.controller,
    this.summary,
    this.trailing,
  });

  final BulkSyncController controller;

  /// Shown at the start of the top row, beside the Sync button.
  final Widget? summary;

  /// Shown after the Sync button (the search toggle).
  final Widget? trailing;

  /// Deliberate confirmation for the one operation that can make a provider
  /// request per stored domain.
  Future<void> _confirmResyncAll(BuildContext context) async {
    final int all;
    try {
      all = await controller.eligibleAll();
    } on ApiException catch (e) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            e.code == 'network_error'
                ? 'Cannot reach the server.'
                : 'Could not check the domains (${e.code}).',
          ),
        ),
      );
      return;
    }
    if (!context.mounted) return;
    final go = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        key: const ValueKey('resync-all-dialog'),
        title: const Text('Re-sync all domain details?'),
        content: Text(
          all == 0
              ? 'No stored domains are eligible.'
              : 'This asks ResellerClub for the contacts and nameservers of '
                    '$all ${all == 1 ? 'domain' : 'domains'}, one request each, '
                    'one at a time. It can take several minutes. Keep the app '
                    'open. Normal Refresh does not need this.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            key: const ValueKey('resync-all-confirm'),
            onPressed: all == 0 ? null : () => Navigator.pop(ctx, true),
            child: const Text('Re-sync all'),
          ),
        ],
      ),
    );
    if (go == true) controller.start(refresh: true);
  }

  /// Summary, the more-actions menu and the trailing control. At normal text sizes they share
  /// one row; once the text is large (or the summary needs the width) the
  /// summary takes the full width and the controls move to their own line, so
  /// nothing overflows. Both controls are at least 48dp.
  Widget _topRow(BuildContext context) {
    final c = controller;
    final stacked = MediaQuery.textScalerOf(context).scale(1) > 1.3;
    final sync = c.phase == BulkPhase.idle || c.phase == BulkPhase.running
        ? PopupMenuButton<String>(
            key: const ValueKey('more-actions'),
            tooltip: 'More actions',
            icon: const Icon(Icons.more_vert),
            onSelected: (_) => _confirmResyncAll(context),
            itemBuilder: (_) => [
              PopupMenuItem<String>(
                key: const ValueKey('resync-all'),
                value: 'resync',
                enabled: !c.busy,
                child: const Text('Re-sync all domain details'),
              ),
            ],
          )
        : null;
    final controls = [?sync, ?trailing];
    if (stacked) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ?summary,
          if (controls.isNotEmpty) ...[
            const SizedBox(height: AppSpacing.xs),
            Wrap(
              alignment: WrapAlignment.end,
              crossAxisAlignment: WrapCrossAlignment.center,
              runSpacing: AppSpacing.xs,
              children: controls,
            ),
          ],
        ],
      );
    }
    return Row(
      children: [
        Expanded(child: summary ?? const SizedBox.shrink()),
        const SizedBox(width: AppSpacing.sm),
        ...controls,
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: controller,
      builder: (context, _) {
        final c = controller;
        final p = c.progress;
        final scheme = Theme.of(context).colorScheme;
        return Card(
          margin: const EdgeInsets.fromLTRB(
            AppSpacing.sm,
            AppSpacing.xs,
            AppSpacing.sm,
            0,
          ),
          child: Padding(
            padding: const EdgeInsets.symmetric(
              horizontal: AppSpacing.sm,
              vertical: 2,
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (c.phase == BulkPhase.idle ||
                    c.phase == BulkPhase.running ||
                    summary != null)
                  _topRow(context),
                if (c.message != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 6),
                    child: Text(c.message!),
                  ),
                if (p != null) ...[
                  const SizedBox(height: 8),
                  if (c.phase == BulkPhase.running ||
                      c.phase == BulkPhase.paused)
                    LinearProgressIndicator(
                      value: p.total == 0 ? null : p.processed / p.total,
                    ),
                  const SizedBox(height: 6),
                  if (p.discovering)
                    const Text(
                      'Step 1 of 2: checking ResellerClub for new domains...',
                    )
                  else ...[
                    if (p.discoveryState == 'done')
                      Text(
                        'Step 1 of 2 done: ${p.newDomains} new '
                        '${p.newDomains == 1 ? 'domain' : 'domains'} found'
                        '${p.discoveryRunStatus == 'success' ? '' : ' (domain list check ${p.discoveryRunStatus ?? 'incomplete'})'}.',
                      )
                    else if (p.discoveryState == 'failed')
                      const Text(
                        'Step 1 of 2: the domain list could not be '
                        'checked; continuing with stored domains.',
                      ),
                    if (c.phase != BulkPhase.finished)
                      Text(
                        p.discoveryState == 'skipped'
                            ? 'Synchronising domain details'
                            : 'Step 2 of 2: synchronising details',
                      ),
                  ],
                  if (!p.discovering)
                    Text(
                      'Completed ${p.processed} of ${p.total} · '
                      '${p.succeeded} successful · ${p.failed} failed · '
                      '${p.remaining} remaining'
                      '${p.skipped > 0 ? ' · ${p.skipped} skipped' : ''}',
                    ),
                  if (c.phase == BulkPhase.running && p.currentName != null)
                    Text(
                      'Processing ${p.currentName}',
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  if (c.phase == BulkPhase.paused)
                    Padding(
                      padding: const EdgeInsets.only(top: 6),
                      child: Wrap(
                        spacing: 8,
                        children: [
                          FilledButton(
                            onPressed: c.resume,
                            child: const Text('Resume'),
                          ),
                          TextButton(
                            onPressed: c.dismiss,
                            child: const Text('Dismiss'),
                          ),
                        ],
                      ),
                    ),
                  if (c.phase == BulkPhase.finished) ...[
                    Padding(
                      padding: const EdgeInsets.only(top: 4),
                      child: Text(
                        p.status == 'completed'
                            ? 'Finished. ${p.newDomains} new '
                                  '${p.newDomains == 1 ? 'domain' : 'domains'} '
                                  'discovered; details: ${p.succeeded} synchronised, '
                                  '${p.failed} failed, ${p.skipped} skipped.'
                            : 'Stopped before finishing.',
                        style: Theme.of(context).textTheme.titleSmall,
                      ),
                    ),
                    if (p.failures.isNotEmpty) _failures(context, p, scheme),
                    TextButton(
                      onPressed: c.dismiss,
                      child: const Text('Close summary'),
                    ),
                  ],
                ],
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _failures(BuildContext context, BatchProgress p, ColorScheme scheme) {
    final c = controller;
    return ExpansionTile(
      tilePadding: EdgeInsets.zero,
      title: Text(
        'Failed domains (${p.failures.where((f) => !c.recovered.contains(f.domainId)).length})',
        style: TextStyle(color: scheme.error),
      ),
      children: [
        ConstrainedBox(
          constraints: const BoxConstraints(maxHeight: 220),
          child: ListView(
            shrinkWrap: true,
            children: [
              for (final f in p.failures)
                ListTile(
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  title: Text(f.domainName),
                  subtitle: Text(
                    c.recovered.contains(f.domainId)
                        ? 'Synchronised'
                        : BulkSyncController.failureText(f.error),
                  ),
                  trailing: c.recovered.contains(f.domainId)
                      ? const Icon(Icons.check, size: 20)
                      : c.retrying.contains(f.domainId)
                      ? const SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : TextButton(
                          onPressed: () => c.retryFailed(f.domainId),
                          child: const Text('Retry'),
                        ),
                ),
            ],
          ),
        ),
      ],
    );
  }
}

/// Compact available balance for the top row: "USD 6,244.40" (selling
/// currency only), or loading / "unavailable" (tap to retry) states. The
/// accounting figures stay in [Balance] but are not shown here.
class BalanceInline extends StatelessWidget {
  const BalanceInline({super.key, required this.controller});

  final BalanceController controller;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final status = theme.extension<StatusColors>()!;
    return ListenableBuilder(
      listenable: controller,
      builder: (context, _) {
        final b = controller.balance;
        if (b != null) {
          return Semantics(
            label: 'Selling Balance (API) ${b.selling}',
            excludeSemantics: true,
            child: FittedBox(
              fit: BoxFit.scaleDown,
              alignment: Alignment.centerLeft,
              child: Text.rich(
                TextSpan(
                  children: [
                    TextSpan(
                      text: '${b.sellingCurrency} ',
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                    TextSpan(
                      text: Balance.format('', b.sellingBalance).trimLeft(),
                      style: theme.textTheme.titleSmall?.copyWith(
                        color: status.success,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ],
                ),
                maxLines: 1,
              ),
            ),
          );
        }
        if (controller.unavailable) {
          // Wraps instead of cutting, and is a full 48dp tap target.
          return InkWell(
            onTap: controller.load,
            child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: 48),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Flexible(
                    child: Text(
                      'Balance unavailable',
                      style: theme.textTheme.bodySmall,
                    ),
                  ),
                  const SizedBox(width: 4),
                  Icon(
                    Icons.refresh,
                    size: 16,
                    color: theme.colorScheme.primary,
                  ),
                ],
              ),
            ),
          );
        }
        return Text('Balance loading...', style: theme.textTheme.bodySmall);
      },
    );
  }
}

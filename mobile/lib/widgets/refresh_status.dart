import 'package:flutter/material.dart';

import '../domains/domain.dart';
import '../domains/refresh.dart';
import '../theme/app_spacing.dart';

/// One compact status line for the unified Refresh:
/// "Refreshing…" while it runs, otherwise when the inventory and the balance
/// were last refreshed successfully, plus the last failure if there was one.
/// A failed refresh never updates these times.
class RefreshStatusLine extends StatelessWidget {
  const RefreshStatusLine({super.key, required this.controller});

  final RefreshController controller;

  /// "Domains synced 2026-11-14 09:30 · Balance 2026-11-14 09:30".
  static String summary(RefreshController c) {
    final inv = c.lastInventoryAt;
    final bal = c.lastBalanceAt;
    return 'Domains synced ${inv == null ? 'not yet' : formatDateTime(inv)}'
        ' · Balance ${bal == null ? 'not yet' : formatDateTime(bal)}';
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return ListenableBuilder(
      listenable: Listenable.merge([
        controller,
        controller.balance,
        ?controller.inventory,
      ]),
      builder: (context, _) {
        final c = controller;
        final style = theme.textTheme.bodySmall?.copyWith(
          color: theme.colorScheme.onSurfaceVariant,
        );
        return Padding(
          key: const ValueKey('refresh-status'),
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.sm + 2,
            vertical: 2,
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              // The progress bar is the global one under the app bar.
              if (c.refreshing)
                Text('Refreshing domains and balance…', style: style)
              else
                Text(summary(c), style: style),
              if (!c.refreshing && c.message != null && c.messageIsError)
                Text(
                  c.message!,
                  key: const ValueKey('refresh-error'),
                  style: style?.copyWith(color: theme.colorScheme.error),
                ),
            ],
          ),
        );
      },
    );
  }
}

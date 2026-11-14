import 'package:flutter/material.dart';

import '../theme/app_spacing.dart';
import '../theme/app_typography.dart';

/// A label / value row. The value wraps (up to [maxLines]) instead of
/// overflowing, and the label takes a flexible share rather than a fixed
/// width, so it holds up at large font scales and on 320dp screens.
class InfoRow extends StatelessWidget {
  const InfoRow({
    super.key,
    required this.label,
    required this.value,
    this.valueKey,
    this.strong = false,
    this.tabular = false,
    this.selectable = false,
    this.maxLines = 3,
    this.stacked = false,
  });

  final String label;
  final String value;
  final Key? valueKey;
  final bool strong;

  /// Use tabular figures (amounts, counts).
  final bool tabular;

  /// Let the user select and copy the value (IDs, nameservers, addresses).
  final bool selectable;
  final int maxLines;

  /// Puts the label above a full-width, start-aligned value that is never
  /// truncated ([maxLines] is ignored). Use it for long values (nameservers,
  /// IDs, postal addresses) that a side-by-side row would squeeze.
  final bool stacked;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    var valueStyle = theme.textTheme.bodyMedium!.copyWith(
      fontWeight: strong ? FontWeight.w700 : FontWeight.w500,
    );
    if (tabular) valueStyle = AppTypography.tabular(valueStyle);
    if (stacked) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              label,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: 2),
            selectable
                ? SelectableText(value, key: valueKey, style: valueStyle)
                : Text(value, key: valueKey, style: valueStyle),
          ],
        ),
      );
    }
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            flex: 5,
            child: Text(
              label,
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            flex: 6,
            child: selectable
                ? SelectableText(
                    value,
                    key: valueKey,
                    textAlign: TextAlign.end,
                    maxLines: maxLines,
                    style: valueStyle,
                  )
                : Text(
                    value,
                    key: valueKey,
                    textAlign: TextAlign.end,
                    maxLines: maxLines,
                    overflow: TextOverflow.ellipsis,
                    style: valueStyle,
                  ),
          ),
        ],
      ),
    );
  }
}

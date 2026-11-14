import 'package:flutter/material.dart';

import '../theme/app_spacing.dart';

/// A flat outlined card with an optional heading. [emphasis] switches to a
/// tinted container; use it only where money is committed or a result is shown.
class SectionCard extends StatelessWidget {
  const SectionCard({
    super.key,
    this.title,
    this.trailing,
    this.emphasis = false,
    required this.child,
  });

  final String? title;

  /// Shown at the end of the heading row (e.g. a badge).
  final Widget? trailing;
  final bool emphasis;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return Card(
      color: emphasis ? scheme.primaryContainer.withValues(alpha: 0.5) : null,
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.component),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (title != null || trailing != null) ...[
              Row(
                children: [
                  if (title != null)
                    Expanded(
                      child: Semantics(
                        header: true,
                        child: Text(title!, style: theme.textTheme.titleSmall),
                      ),
                    )
                  else
                    const Spacer(),
                  ?trailing,
                ],
              ),
              const SizedBox(height: AppSpacing.sm),
            ],
            child,
          ],
        ),
      ),
    );
  }
}

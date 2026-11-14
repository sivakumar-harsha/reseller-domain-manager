import 'package:flutter/material.dart';

import '../theme/app_colors.dart';
import '../theme/app_spacing.dart';

/// Meaning of a badge. Colour is never the only signal: every tone carries an
/// icon and a text label.
enum BadgeTone { neutral, accent, success, caution, warning, danger }

/// A compact status pill: icon + label. Never shrinks below its content (the
/// label wraps instead), so it stays readable at large font scales.
class StatusBadge extends StatelessWidget {
  const StatusBadge({
    super.key,
    required this.label,
    required this.tone,
    this.icon,
  });

  final String label;
  final BadgeTone tone;

  /// Overrides the tone's default icon.
  final IconData? icon;

  static IconData defaultIcon(BadgeTone tone) => switch (tone) {
    BadgeTone.neutral => Icons.schedule,
    BadgeTone.accent => Icons.sync,
    BadgeTone.success => Icons.check_circle_outline,
    BadgeTone.caution => Icons.hourglass_bottom,
    BadgeTone.warning => Icons.warning_amber_rounded,
    BadgeTone.danger => Icons.cancel_outlined,
  };

  /// (foreground, background) for [tone].
  static (Color, Color) colors(BuildContext context, BadgeTone tone) {
    final scheme = Theme.of(context).colorScheme;
    final s = Theme.of(context).extension<StatusColors>()!;
    return switch (tone) {
      BadgeTone.neutral => (s.neutral, s.neutralContainer),
      BadgeTone.accent => (scheme.onPrimaryContainer, scheme.primaryContainer),
      BadgeTone.success => (s.success, s.successContainer),
      BadgeTone.caution => (s.caution, s.warningContainer),
      BadgeTone.warning => (s.warning, s.warningContainer),
      BadgeTone.danger => (scheme.onErrorContainer, scheme.errorContainer),
    };
  }

  @override
  Widget build(BuildContext context) {
    final (fg, bg) = colors(context, tone);
    final style = Theme.of(
      context,
    ).textTheme.labelMedium?.copyWith(color: fg, fontWeight: FontWeight.w600);
    return Semantics(
      container: true,
      label: label,
      child: ExcludeSemantics(
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: bg,
            borderRadius: BorderRadius.circular(AppRadius.pill),
          ),
          child: Padding(
            padding: const EdgeInsets.symmetric(
              horizontal: AppSpacing.sm,
              vertical: AppSpacing.xs,
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(icon ?? defaultIcon(tone), size: 14, color: fg),
                const SizedBox(width: AppSpacing.xs),
                Flexible(child: Text(label, style: style, softWrap: true)),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

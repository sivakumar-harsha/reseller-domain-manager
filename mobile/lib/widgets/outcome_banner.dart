import 'package:flutter/material.dart';

import '../theme/app_motion.dart';
import '../theme/app_spacing.dart';
import 'status_badge.dart';

/// A full-width result banner (icon + title + optional message). When the
/// content changes it fades and settles from 0.97 (200ms, transform and
/// opacity only; instant under reduced motion). No bounce, no celebration.
/// Announced to screen readers as a live region.
class OutcomeBanner extends StatelessWidget {
  const OutcomeBanner({
    super.key,
    required this.tone,
    required this.title,
    this.message,
    this.icon,
  });

  final BadgeTone tone;
  final String title;
  final String? message;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    return AnimatedSwitcher(
      duration: AppMotion.of(context, AppMotion.settle),
      switchInCurve: AppMotion.easeOut,
      transitionBuilder: (child, animation) => FadeTransition(
        opacity: animation,
        child: ScaleTransition(
          scale: Tween<double>(
            begin: AppMotion.enterScale,
            end: 1,
          ).animate(animation),
          child: child,
        ),
      ),
      layoutBuilder: (current, previous) => Stack(
        alignment: Alignment.topCenter,
        children: [...previous, ?current],
      ),
      child: _Banner(
        key: ValueKey('$tone|$title|$message'),
        tone: tone,
        title: title,
        message: message,
        icon: icon,
      ),
    );
  }
}

class _Banner extends StatelessWidget {
  const _Banner({
    super.key,
    required this.tone,
    required this.title,
    this.message,
    this.icon,
  });

  final BadgeTone tone;
  final String title;
  final String? message;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final (fg, bg) = StatusBadge.colors(context, tone);
    return Semantics(
      liveRegion: true,
      container: true,
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: bg,
          borderRadius: AppRadius.card,
          border: Border.all(color: fg.withValues(alpha: 0.4)),
        ),
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(icon ?? StatusBadge.defaultIcon(tone), color: fg, size: 24),
              const SizedBox(width: AppSpacing.component),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      title,
                      style: theme.textTheme.titleSmall?.copyWith(color: fg),
                    ),
                    if (message != null) ...[
                      const SizedBox(height: AppSpacing.xs),
                      Text(
                        message!,
                        style: theme.textTheme.bodyMedium?.copyWith(color: fg),
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

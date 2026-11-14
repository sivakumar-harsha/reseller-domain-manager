import 'package:flutter/material.dart';

import '../theme/app_spacing.dart';

/// Shared loading / empty / error presentation. Static (no shimmer), so it is
/// cheap on low-end devices.
class StateView extends StatelessWidget {
  const StateView.loading({super.key, this.message})
    : icon = null,
      onRetry = null,
      isError = false,
      isLoading = true;

  const StateView.empty({super.key, required this.message, this.icon})
    : onRetry = null,
      isError = false,
      isLoading = false;

  const StateView.error({super.key, required this.message, this.onRetry})
    : icon = Icons.error_outline,
      isError = true,
      isLoading = false;

  final String? message;
  final IconData? icon;
  final VoidCallback? onRetry;
  final bool isError;
  final bool isLoading;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 400),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (isLoading)
                const SizedBox(
                  width: 28,
                  height: 28,
                  child: CircularProgressIndicator(strokeWidth: 3),
                )
              else if (icon != null)
                Icon(
                  icon,
                  size: 40,
                  color: isError ? scheme.error : scheme.onSurfaceVariant,
                ),
              if (message != null) ...[
                const SizedBox(height: AppSpacing.component),
                Semantics(
                  liveRegion: isError,
                  child: Text(
                    message!,
                    textAlign: TextAlign.center,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: isError ? scheme.error : null,
                    ),
                  ),
                ),
              ],
              if (onRetry != null) ...[
                const SizedBox(height: AppSpacing.md),
                OutlinedButton(onPressed: onRetry, child: const Text('Retry')),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// Static placeholder rows shown while a list loads.
class SkeletonRows extends StatelessWidget {
  const SkeletonRows({super.key, this.count = 6});

  final int count;

  @override
  Widget build(BuildContext context) {
    final bar = Theme.of(context).colorScheme.surfaceContainerHighest;
    Widget line(double factor, double height) => FractionallySizedBox(
      alignment: Alignment.centerLeft,
      widthFactor: factor,
      child: Container(
        height: height,
        decoration: BoxDecoration(
          color: bar,
          borderRadius: BorderRadius.circular(AppRadius.sm / 2),
        ),
      ),
    );
    return Semantics(
      label: 'Loading',
      child: ExcludeSemantics(
        child: Column(
          children: [
            for (var i = 0; i < count; i++)
              Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: AppSpacing.md,
                  vertical: AppSpacing.component,
                ),
                child: Column(
                  children: [
                    line(0.6, 16),
                    const SizedBox(height: AppSpacing.sm),
                    line(0.35, 12),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }
}

import 'package:flutter/material.dart';

import '../domains/domain_controller.dart';
import '../domains/expiry.dart';
import '../theme/app_spacing.dart';
import '../widgets/outcome_banner.dart';
import '../widgets/pressable.dart';
import '../widgets/section_card.dart';
import '../widgets/status_badge.dart';

/// User-facing names for the list filters and sort orders.
extension ExpiryFilterLabel on ExpiryFilter {
  String get label => switch (this) {
    ExpiryFilter.all => 'All',
    ExpiryFilter.expired => 'Expired',
    ExpiryFilter.within7 => 'Within 7 days',
    ExpiryFilter.within30 => 'Within 30 days',
    ExpiryFilter.within60 => 'Within 60 days',
    ExpiryFilter.within90 => 'Within 90 days',
    ExpiryFilter.unknown => 'Unknown expiry',
  };
}

extension DomainSortLabel on DomainSort {
  String get label => switch (this) {
    DomainSort.defaultOrder || DomainSort.expiryAsc => 'Nearest expiry',
    DomainSort.expiryDesc => 'Farthest expiry',
    DomainSort.nameAsc => 'Domain name',
  };
}

/// Expiry overview: six tiles with the backend's cumulative counts. Tapping a
/// tile applies that filter to the domain list. It only reads
/// [DomainController.summary]; loading it is the owner's job.
class ExpiryDashboard extends StatelessWidget {
  const ExpiryDashboard({
    super.key,
    required this.controller,
    required this.onSelected,
  });

  final DomainController controller;

  /// Called with the tapped tile's filter; the owner applies it and navigates.
  final void Function(ExpiryFilter filter) onSelected;

  static const _tiles = [
    ExpiryFilter.expired,
    ExpiryFilter.within7,
    ExpiryFilter.within30,
    ExpiryFilter.within60,
    ExpiryFilter.within90,
    ExpiryFilter.unknown,
  ];

  /// Severity of a tile. Colour is never the only signal: every tile also has
  /// its label, an icon and the count.
  static (BadgeTone, IconData) _style(ExpiryFilter f) => switch (f) {
    ExpiryFilter.expired => (
      BadgeTone.danger,
      StatusBadge.defaultIcon(BadgeTone.danger),
    ),
    ExpiryFilter.within7 => (
      BadgeTone.warning,
      StatusBadge.defaultIcon(BadgeTone.warning),
    ),
    ExpiryFilter.unknown => (BadgeTone.neutral, Icons.help_outline),
    _ => (BadgeTone.neutral, Icons.schedule),
  };

  /// Narrowest comfortable tile at 1.0x text; it grows with the font scale so
  /// labels wrap instead of being cut, and the grid drops to one column first.
  static const _minTileWidth = 120.0;

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: controller,
      builder: (context, _) {
        final theme = Theme.of(context);
        final loadingFirst =
            controller.summary == null && controller.summaryLoading;
        return SectionCard(
          title: 'Expiry overview',
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _caption(theme, loadingFirst),
              _grid(context),
              if (controller.summaryError != null) _error(),
            ],
          ),
        );
      },
    );
  }

  Widget _caption(ThemeData theme, bool loadingFirst) {
    final asOf = controller.summary?.asOfDate;
    final zone = controller.summary?.timezone;
    final String text;
    if (loadingFirst) {
      text = 'Loading counts…';
    } else if (asOf != null) {
      text = zone == null ? 'As of $asOf' : 'As of $asOf ($zone)';
    } else {
      return const SizedBox.shrink();
    }
    final child = Text(
      text,
      key: const ValueKey('expiry-caption'),
      style: theme.textTheme.bodySmall?.copyWith(
        color: theme.colorScheme.onSurfaceVariant,
      ),
    );
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.component),
      child: loadingFirst
          ? Semantics(
              container: true,
              label: 'Loading expiry counts',
              liveRegion: true,
              excludeSemantics: true,
              child: child,
            )
          : child,
    );
  }

  Widget _grid(BuildContext context) {
    final scale = MediaQuery.textScalerOf(context).scale(1);
    final minWidth = _minTileWidth * (scale < 1 ? 1 : scale);
    return LayoutBuilder(
      builder: (context, box) {
        const gap = AppSpacing.sm;
        final cols = box.maxWidth >= 2 * minWidth + gap ? 2 : 1;
        return Column(
          children: [
            for (var i = 0; i < _tiles.length; i += cols) ...[
              if (i > 0) const SizedBox(height: gap),
              IntrinsicHeight(
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    for (var c = 0; c < cols; c++) ...[
                      if (c > 0) const SizedBox(width: gap),
                      Expanded(child: _tile(_tiles[i + c])),
                    ],
                  ],
                ),
              ),
            ],
          ],
        );
      },
    );
  }

  Widget _tile(ExpiryFilter filter) {
    final count = controller.summary?.countFor(filter);
    final (tone, icon) = _style(filter);
    return _SummaryTile(
      key: ValueKey('expiry-tile-${filter.apiValue}'),
      label: filter.label,
      value: count?.toString() ?? '–',
      loading: count == null && controller.summaryLoading,
      tone: tone,
      icon: icon,
      onTap: () => onSelected(filter),
    );
  }

  Widget _error() {
    final stale = controller.summary != null;
    return Padding(
      padding: const EdgeInsets.only(top: AppSpacing.component),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          OutcomeBanner(
            key: const ValueKey('expiry-error'),
            tone: stale ? BadgeTone.warning : BadgeTone.danger,
            title: stale
                ? 'Could not refresh the counts. Showing the last known values.'
                : 'Could not load the expiry counts.',
          ),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton(
              onPressed: controller.summaryLoading
                  ? null
                  : controller.loadSummary,
              child: const Text('Retry summary'),
            ),
          ),
        ],
      ),
    );
  }
}

class _SummaryTile extends StatelessWidget {
  const _SummaryTile({
    super.key,
    required this.label,
    required this.value,
    required this.loading,
    required this.tone,
    required this.icon,
    required this.onTap,
  });

  final String label;
  final String value;
  final bool loading;
  final BadgeTone tone;
  final IconData icon;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final (fg, bg) = StatusBadge.colors(context, tone);
    final toned = tone != BadgeTone.neutral;
    final labelColor = toned ? fg : scheme.onSurfaceVariant;
    final valueColor = toned ? fg : scheme.onSurface;
    final valueStyle = theme.textTheme.titleLarge?.copyWith(
      color: valueColor,
      fontWeight: FontWeight.w700,
      fontFeatures: const [FontFeature.tabularFigures()],
    );
    return Semantics(
      button: true,
      selected: false,
      label: '$label: ${loading ? 'loading' : value}',
      excludeSemantics: true,
      child: Pressable(
        child: Material(
          color: toned ? bg : scheme.surface,
          shape: RoundedRectangleBorder(
            borderRadius: AppRadius.control,
            side: BorderSide(
              color: toned ? fg.withValues(alpha: 0.4) : scheme.outlineVariant,
            ),
          ),
          child: InkWell(
            borderRadius: AppRadius.control,
            onTap: onTap,
            child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: 56),
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.component),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Icon(icon, size: 18, color: labelColor),
                        const SizedBox(width: AppSpacing.xs),
                        Expanded(
                          child: Text(
                            label,
                            style: theme.textTheme.bodySmall?.copyWith(
                              color: labelColor,
                            ),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: AppSpacing.xs),
                    if (loading)
                      // Static placeholder: no shimmer, no animation.
                      DecoratedBox(
                        key: const ValueKey('tile-loading'),
                        decoration: BoxDecoration(
                          color: scheme.surfaceContainerHighest,
                          borderRadius: AppRadius.control,
                        ),
                        child: const SizedBox(width: 32, height: 20),
                      )
                    else
                      Text(value, style: valueStyle),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// One compact row under the search box: expiry filter chips (scrolls
/// sideways) and a sort menu. Selecting either goes through the controller,
/// which reloads page 1 and keeps the search text and the other option.
class ExpiryFilterBar extends StatelessWidget {
  const ExpiryFilterBar({super.key, required this.controller});

  final DomainController controller;

  static const _sorts = [
    DomainSort.expiryAsc,
    DomainSort.expiryDesc,
    DomainSort.nameAsc,
  ];

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: controller,
      builder: (context, _) {
        final current = controller.sort == DomainSort.defaultOrder
            ? DomainSort.expiryAsc
            : controller.sort;
        // The start inset lives inside the scroll view: as outer padding it
        // became a hard clip edge, cutting a scrolled chip mid-label.
        // Chips follow the app chip theme, are at least 48dp tall, and the
        // selected one also shows a check mark (not colour alone).
        final scheme = Theme.of(context).colorScheme;
        final sortActive = controller.sort != DomainSort.defaultOrder;
        return Row(
          crossAxisAlignment: CrossAxisAlignment.center,
          children: [
            Expanded(
              child: SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.only(left: AppSpacing.sm),
                child: Row(
                  children: [
                    for (final f in ExpiryFilter.values)
                      Padding(
                        padding: const EdgeInsets.only(right: AppSpacing.sm),
                        child: ChoiceChip(
                          key: ValueKey('expiry-chip-${f.apiValue}'),
                          label: Text(f.label),
                          selected: controller.expiry == f,
                          showCheckmark: true,
                          materialTapTargetSize: MaterialTapTargetSize.padded,
                          labelStyle: Theme.of(context).textTheme.labelLarge
                              ?.copyWith(
                                color: scheme.onSurface,
                                fontWeight: controller.expiry == f
                                    ? FontWeight.w700
                                    : FontWeight.w500,
                              ),
                          side: BorderSide(
                            color: controller.expiry == f
                                ? scheme.primary
                                : scheme.outlineVariant,
                            width: controller.expiry == f ? 2 : 1,
                          ),
                          onSelected: (_) => controller.setExpiry(f),
                        ),
                      ),
                  ],
                ),
              ),
            ),
            PopupMenuButton<DomainSort>(
              key: const ValueKey('sort-menu'),
              tooltip: 'Sort: ${current.label}',
              // A dot badge (and the tint) marks a non-default sort.
              icon: Badge(
                isLabelVisible: sortActive,
                smallSize: 8,
                backgroundColor: scheme.primary,
                child: Icon(
                  Icons.sort,
                  color: sortActive ? scheme.primary : null,
                ),
              ),
              initialValue: current,
              onSelected: (value) {
                // "Nearest expiry" is already the server default.
                if (value == DomainSort.expiryAsc &&
                    controller.sort == DomainSort.defaultOrder) {
                  return;
                }
                controller.setSort(value);
              },
              itemBuilder: (_) => [
                for (final s in _sorts)
                  CheckedPopupMenuItem<DomainSort>(
                    value: s,
                    checked: s == current,
                    child: Text(s.label),
                  ),
              ],
            ),
          ],
        );
      },
    );
  }
}

import 'package:flutter/material.dart';

import '../domains/balance.dart';
import '../domains/bulk_sync.dart';
import '../domains/domain.dart';
import '../domains/domain_controller.dart';
import '../domains/domain_selection.dart';
import '../domains/expiry.dart';
import '../domains/refresh.dart';
import '../widgets/refresh_status.dart';
import 'bulk_sync_panel.dart';
import '../theme/app_colors.dart';
import '../theme/app_spacing.dart';
import '../theme/app_typography.dart';
import '../widgets/outcome_banner.dart';
import '../widgets/state_view.dart';
import '../widgets/status_badge.dart';
import 'expiry_dashboard.dart';

/// Read-only list of cached domains with search, pull-to-refresh and
/// load-more. Owns no state beyond the scroll position and search box.
class DomainListScreen extends StatefulWidget {
  const DomainListScreen({
    super.key,
    required this.controller,
    this.onOpen,
    this.now,
    this.bulk,
    this.balance,
    this.refresh,
    this.onRefresh,
    this.onRenewSelected,
    this.selection,
  });

  final DomainController controller;

  /// Which domains are ticked. Checkboxes appear only when set (there is no
  /// Select All); the owner keeps it alive so selections survive tab switches
  /// and reloads.
  final DomainSelection? selection;

  /// Called when the Renew button above the list is tapped with at least one
  /// domain ticked; opens the read-only renewal details.
  final VoidCallback? onRenewSelected;

  /// Pull-to-refresh action; defaults to reloading the list only.
  final Future<void> Function()? onRefresh;

  /// When set, the details panel (progress, more-actions menu) is shown above the list.
  final BulkSyncController? bulk;

  /// The unified Refresh; its status line is shown under the top row when set.
  final RefreshController? refresh;

  /// Shown beside the more-actions menu when set.
  final BalanceController? balance;

  /// Called when a row is tapped; rows are not tappable when null.
  final void Function(Domain domain)? onOpen;

  /// Clock override for tests.
  final DateTime Function()? now;

  @override
  State<DomainListScreen> createState() => _DomainListScreenState();
}

class _DomainListScreenState extends State<DomainListScreen> {
  /// Widest the list content grows; wider screens centre it instead of
  /// stretching the cards across the whole width.
  static const double _maxContentWidth = 640;

  /// Share of the screen height the panel and search field may use before they
  /// scroll, so the list always keeps room at large text sizes.
  static const double _topShare = 0.4;

  final _scroll = ScrollController();
  final _search = TextEditingController();
  bool _searching = false;

  DomainController get _c => widget.controller;
  DomainSelection? get _sel => widget.selection;

  Future<void> _refresh() => (widget.onRefresh ?? _c.refresh)();

  @override
  void initState() {
    super.initState();
    _scroll.addListener(_maybeLoadMore);
    _c.addListener(_pruneStale);
  }

  @override
  void didUpdateWidget(covariant DomainListScreen old) {
    super.didUpdateWidget(old);
    if (old.controller != widget.controller) {
      old.controller.removeListener(_pruneStale);
      widget.controller.addListener(_pruneStale);
    }
  }

  /// Drops selections whose domains no longer exist. Only a complete, unfiltered
  /// list proves that, so it runs only then; any other view (filter, search, an
  /// unfinished page load) keeps every selection.
  void _pruneStale() {
    final sel = _sel;
    if (sel == null || sel.isEmpty) return;
    final c = _c;
    if (!c.loaded ||
        c.loading ||
        c.refreshing ||
        c.loadingMore ||
        c.error != null ||
        c.expiry != ExpiryFilter.all ||
        c.query.isNotEmpty ||
        c.hasMore) {
      return;
    }
    sel.retainOnly({for (final d in c.items) d.id});
  }

  @override
  void dispose() {
    _c.removeListener(_pruneStale);
    _scroll.dispose();
    _search.dispose();
    super.dispose();
  }

  void _maybeLoadMore() {
    if (_scroll.hasClients &&
        _scroll.position.extentAfter < 300 &&
        _c.loadMoreError == null) {
      _c.loadMore();
    }
  }

  /// The one way the search is cleared (suffix button and the empty state).
  void _clearSearch() {
    _search.clear();
    _c.setQuery('');
  }

  @override
  Widget build(BuildContext context) {
    // Centred and capped at 640dp; phones are unaffected.
    return Align(
      alignment: Alignment.topCenter,
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: _maxContentWidth),
        child: LayoutBuilder(
          builder: (context, box) {
            final topCap = box.maxHeight.isFinite
                ? box.maxHeight * _topShare
                : double.infinity;
            return Column(
              children: [
                // Scrolls inside its share, so a long sync summary or the
                // search field never pushes the list off a small screen.
                ConstrainedBox(
                  constraints: BoxConstraints(maxHeight: topCap),
                  child: SingleChildScrollView(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        if (widget.bulk != null)
                          BulkSyncPanel(
                            controller: widget.bulk!,
                            summary: _summary(context),
                            trailing: Padding(
                              padding: const EdgeInsets.only(
                                left: AppSpacing.xs,
                              ),
                              child: ListenableBuilder(
                                listenable: _search,
                                builder: (context, _) => _searchToggle(context),
                              ),
                            ),
                          ),
                        if (widget.refresh != null)
                          RefreshStatusLine(controller: widget.refresh!),
                        if (_searching) _searchField(context),
                      ],
                    ),
                  ),
                ),
                ExpiryFilterBar(controller: _c),
                if (_sel != null) _selectBar(context),
                Expanded(
                  child: ListenableBuilder(
                    listenable: _sel == null
                        ? _c
                        : Listenable.merge([_c, _sel!]),
                    builder: (context, _) => _body(context),
                  ),
                ),
              ],
            );
          },
        ),
      ),
    );
  }

  OutlineInputBorder _searchBorder(BuildContext context, {double width = 1}) =>
      OutlineInputBorder(
        borderRadius: BorderRadius.circular(AppRadius.pill),
        borderSide: BorderSide(
          color: width > 1
              ? Theme.of(context).colorScheme.primary
              : Theme.of(context).colorScheme.outlineVariant,
          width: width,
        ),
      );

  /// One compact row: globe + total, then the available balance (the menu and
  /// search controls follow in the panel's row). The total and balance scale
  /// down before they can force anything wider, so a long total or a narrow
  /// screen never overflows. The active filter and sort stay visible through
  /// the chips and the sort menu.
  Widget _summary(BuildContext context) {
    final theme = Theme.of(context);
    return Row(
      children: [
        Icon(Icons.public, size: 20, color: theme.colorScheme.primary),
        const SizedBox(width: AppSpacing.xs),
        Flexible(
          child: ListenableBuilder(
            listenable: _c,
            builder: (context, _) => Semantics(
              label:
                  '${_c.total} ${_c.total == 1 ? 'domain' : 'domains'}'
                  '${_c.lastSyncedAt == null ? ', never synced' : ', last synced ${formatDateTime(_c.lastSyncedAt!)}'}',
              excludeSemantics: true,
              child: FittedBox(
                fit: BoxFit.scaleDown,
                alignment: Alignment.centerLeft,
                child: Text(
                  _c.loaded ? '${_c.total}' : '–',
                  style: theme.textTheme.titleMedium,
                ),
              ),
            ),
          ),
        ),
        if (widget.balance != null) ...[
          const SizedBox(width: AppSpacing.component),
          Flexible(flex: 2, child: BalanceInline(controller: widget.balance!)),
        ],
      ],
    );
  }

  /// Selected-domain count under the filter chips (all filters included).
  Widget _selectBar(BuildContext context) {
    final theme = Theme.of(context);
    final sel = _sel!;
    return ListenableBuilder(
      listenable: sel,
      builder: (context, _) => Padding(
        padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md),
        child: Row(
          children: [
            Expanded(
              child: Text(
                '${sel.count} selected',
                key: const ValueKey('selected-count'),
                style: theme.textTheme.labelLarge?.copyWith(
                  color: sel.count > 0 ? theme.colorScheme.primary : null,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ),
            // 36dp button; the tap area is still padded out to 48dp.
            FilledButton(
              key: const ValueKey('renew-selected'),
              style: FilledButton.styleFrom(
                minimumSize: const Size(80, 36),
                padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md),
                tapTargetSize: MaterialTapTargetSize.padded,
              ),
              onPressed: sel.isEmpty ? null : widget.onRenewSelected,
              child: const Text('Renew'),
            ),
          ],
        ),
      ),
    );
  }

  /// Opens and closes the field. While a query is applied it carries a dot
  /// badge, so the active search is visible without relying on colour. A 48dp
  /// button.
  Widget _searchToggle(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final active = _search.text.isNotEmpty;
    return IconButton(
      tooltip: _searching ? 'Close search' : 'Search domains',
      style: IconButton.styleFrom(
        fixedSize: const Size(48, 48),
        minimumSize: const Size(48, 48),
        padding: EdgeInsets.zero,
        tapTargetSize: MaterialTapTargetSize.padded,
        side: BorderSide(color: scheme.primary, width: active ? 2 : 1),
        backgroundColor: active ? scheme.primaryContainer : null,
        shape: const RoundedRectangleBorder(borderRadius: AppRadius.control),
      ),
      icon: Badge(
        isLabelVisible: active,
        smallSize: 10,
        backgroundColor: scheme.primary,
        child: Icon(
          _searching ? Icons.close : Icons.search,
          color: scheme.primary,
        ),
      ),
      onPressed: () => setState(() => _searching = !_searching),
    );
  }

  /// Field below the top row. Closing it only hides it: the query stays
  /// applied (the toggle is badged) until cleared.
  Widget _searchField(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.sm,
        AppSpacing.sm,
        AppSpacing.sm,
        0,
      ),
      child: TextField(
        controller: _search,
        autofocus: true,
        textInputAction: TextInputAction.search,
        autocorrect: false,
        style: Theme.of(context).textTheme.bodyLarge,
        decoration: InputDecoration(
          hintText: 'Search domains',
          prefixIcon: const Icon(Icons.search),
          constraints: const BoxConstraints(minHeight: 48),
          contentPadding: const EdgeInsets.symmetric(
            vertical: AppSpacing.component,
            horizontal: AppSpacing.md,
          ),
          border: _searchBorder(context),
          enabledBorder: _searchBorder(context),
          focusedBorder: _searchBorder(context, width: 2),
          suffixIcon: ValueListenableBuilder<TextEditingValue>(
            valueListenable: _search,
            builder: (context, value, _) => value.text.isEmpty
                ? const SizedBox.shrink()
                : IconButton(
                    tooltip: 'Clear search',
                    constraints: const BoxConstraints(
                      minWidth: 48,
                      minHeight: 48,
                    ),
                    icon: const Icon(Icons.clear),
                    onPressed: _clearSearch,
                  ),
          ),
        ),
        onChanged: _c.setQuery,
      ),
    );
  }

  Widget _body(BuildContext context) {
    final filtered = _c.expiry != ExpiryFilter.all;
    if (_c.loading) {
      return Semantics(
        liveRegion: true,
        child: StateView.loading(message: 'Loading domains…'),
      );
    }
    if (_c.error != null && _c.items.isEmpty) {
      return _scrollable([
        StateView.error(message: _c.error!, onRetry: _c.retry),
      ]);
    }
    if (_c.isEmpty) {
      return _scrollable([
        StateView.empty(
          icon: Icons.dns_outlined,
          message: filtered
              ? (_c.query.isEmpty
                    ? 'No domains in "${_c.expiry.label}".'
                    : 'No domains match "${_c.query}" in "${_c.expiry.label}".')
              : _c.query.isEmpty
              ? 'No domains yet. They appear here after the first sync.'
              : 'No domains match "${_c.query}".',
        ),
        if (filtered || _c.query.isNotEmpty)
          Center(
            child: Wrap(
              alignment: WrapAlignment.center,
              spacing: AppSpacing.sm,
              children: [
                if (filtered)
                  TextButton(
                    onPressed: () => _c.setExpiry(ExpiryFilter.all),
                    child: const Text('Show all domains'),
                  ),
                if (_c.query.isNotEmpty)
                  TextButton(
                    onPressed: _clearSearch,
                    child: const Text('Clear search'),
                  ),
              ],
            ),
          ),
      ]);
    }

    final now = (widget.now ?? DateTime.now)();
    final header = <Widget>[
      if (_c.error != null) _banner(_c.error!, _c.retry),
      const SizedBox(height: AppSpacing.xs),
    ];

    return RefreshIndicator(
      onRefresh: _refresh,
      child: ListView.builder(
        controller: _scroll,
        physics: const AlwaysScrollableScrollPhysics(),
        itemCount: header.length + _c.items.length + 1,
        itemBuilder: (context, i) {
          if (i < header.length) return header[i];
          final index = i - header.length;
          if (index < _c.items.length) {
            final domain = _c.items[index];
            return _DomainTile(
              domain: domain,
              now: now,
              onTap: widget.onOpen == null
                  ? null
                  : () => widget.onOpen!(domain),
              selected: _sel?.contains(domain.id),
              onSelect: _sel == null
                  ? null
                  : (value) =>
                        _sel!.set(domain.id, value, name: domain.domainName),
            );
          }
          return _footer();
        },
      ),
    );
  }

  /// A kept-rows problem: the message with its Retry, above or below the list.
  Widget _banner(String text, VoidCallback onRetry) => Padding(
    padding: const EdgeInsets.symmetric(
      horizontal: AppSpacing.md,
      vertical: AppSpacing.xs,
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        OutcomeBanner(tone: BadgeTone.warning, title: text),
        TextButton(onPressed: onRetry, child: const Text('Retry')),
      ],
    ),
  );

  Widget _footer() {
    if (_c.loadingMore) {
      return Semantics(
        label: 'Loading more domains',
        liveRegion: true,
        child: const Padding(
          padding: EdgeInsets.all(AppSpacing.md),
          child: Center(child: CircularProgressIndicator()),
        ),
      );
    }
    if (_c.loadMoreError != null) {
      return _banner(_c.loadMoreError!, _c.loadMore);
    }
    if (_c.hasMore) {
      return Center(
        child: TextButton(
          onPressed: _c.loadMore,
          child: const Text('Load more'),
        ),
      );
    }
    return const SizedBox(height: AppSpacing.md);
  }

  Widget _scrollable(List<Widget> children) => RefreshIndicator(
    onRefresh: _refresh,
    child: ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      children: children,
    ),
  );
}

/// Rounded card: the domain name, then the expiry date with a relative-expiry
/// badge, then the provider status. Everything wraps, so nothing important is
/// cut at large text. The badge and the expiry text come from [ExpiryInfo]
/// (the only expiry calculation). The checkbox sits outside the tappable area,
/// so ticking never opens the domain.
class _DomainTile extends StatelessWidget {
  const _DomainTile({
    required this.domain,
    required this.now,
    this.onTap,
    this.selected,
    this.onSelect,
  });

  final Domain domain;

  /// Checkbox state; no checkbox is drawn when [onSelect] is null.
  final bool? selected;
  final ValueChanged<bool>? onSelect;
  final DateTime now;
  final VoidCallback? onTap;

  static const missingExplanation =
      'Not listed by the provider in the last complete sync';

  // Kept as the documented colour ladder (the badge tone mirrors it).
  // ignore: unused_element
  static Color expiryColor(BuildContext context, ExpiryInfo info) {
    final theme = Theme.of(context);
    final status = theme.extension<StatusColors>()!;
    if (info.isUnknown) return status.neutral;
    if (info.isExpired) return theme.colorScheme.error;
    if (info.expiresWithin(7)) return status.warning;
    if (info.expiresWithin(30)) return status.caution;
    return status.success;
  }

  /// The severity ladder as a badge tone; mirrors [expiryColor].
  static BadgeTone expiryTone(ExpiryInfo info) {
    if (info.isUnknown) return BadgeTone.neutral;
    if (info.isExpired) return BadgeTone.danger;
    if (info.expiresWithin(7)) return BadgeTone.warning;
    if (info.expiresWithin(30)) return BadgeTone.caution;
    return BadgeTone.success;
  }

  /// Relative expiry for the badge, from the same [ExpiryInfo].
  static String badgeText(ExpiryInfo info) {
    final d = info.daysRemaining;
    if (d == null) return 'Unknown';
    if (d < -1) return '${-d} days ago';
    if (d == -1) return 'Yesterday';
    if (d == 0) return 'Today';
    if (d == 1) return 'Tomorrow';
    return 'in $d days';
  }

  /// "Expires 2026-11-07" / "Expired 2026-08-22" / "Expiry unknown".
  static String dateText(Domain domain, ExpiryInfo info) {
    final at = domain.expiresAt;
    if (at == null) return 'Expiry unknown';
    return '${info.isExpired ? 'Expired' : 'Expires'} ${formatDate(at)}';
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final status = theme.extension<StatusColors>()!;
    final info = ExpiryInfo.of(domain.expiresAt, now);
    final date = dateText(domain, info);
    final relative = badgeText(info);
    final providerStatus = domain.status ?? 'Status unknown';

    // One reading for a screen reader; the checkbox keeps its own label.
    final label = [
      domain.domainName,
      'status $providerStatus',
      date,
      relative,
      if (domain.isMissing) missingExplanation,
    ].join(', ');

    // Two lines: name + relative badge, then date and status. The missing
    // warning joins the second line only when it applies. At large text the
    // badge drops under the name instead of squeezing it.
    final stacked = MediaQuery.textScalerOf(context).scale(1) > 1.3;
    final name = Text(
      domain.domainName,
      style: AppTypography.domainName(context),
    );
    final badge = StatusBadge(label: relative, tone: expiryTone(info));
    final details = Padding(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.component,
        vertical: 6,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (stacked) ...[
            name,
            const SizedBox(height: 2),
            badge,
          ] else
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(child: name),
                const SizedBox(width: AppSpacing.sm),
                badge,
              ],
            ),
          const SizedBox(height: 2),
          Wrap(
            spacing: AppSpacing.component,
            runSpacing: 2,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              Text(
                date,
                style: theme.textTheme.bodyMedium?.copyWith(
                  fontWeight: FontWeight.w500,
                ),
              ),
              Text(
                providerStatus,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: scheme.onSurfaceVariant,
                ),
              ),
              if (domain.isMissing)
                // Domains the provider no longer lists: icon, words, tooltip.
                Tooltip(
                  message: missingExplanation,
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(
                        Icons.warning_amber,
                        size: 16,
                        color: status.warning,
                      ),
                      const SizedBox(width: AppSpacing.xs),
                      Flexible(
                        child: Text(
                          'Missing from provider',
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: status.warning,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
            ],
          ),
        ],
      ),
    );

    return Card(
      margin: const EdgeInsets.symmetric(
        horizontal: AppSpacing.md,
        vertical: 2,
      ),
      clipBehavior: Clip.antiAlias,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          if (onSelect != null)
            Padding(
              padding: const EdgeInsets.only(left: AppSpacing.xs),
              child: SizedBox(
                width: 48,
                height: 48,
                child: Semantics(
                  label: 'Select ${domain.domainName}',
                  child: Checkbox(
                    key: ValueKey('select-${domain.id}'),
                    value: selected ?? false,
                    // Domains the provider no longer lists cannot be picked.
                    onChanged: domain.isMissing
                        ? null
                        : (v) => onSelect!(v ?? false),
                  ),
                ),
              ),
            ),
          Expanded(
            child: InkWell(
              onTap: onTap,
              child: Semantics(
                label: label,
                excludeSemantics: true,
                child: details,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

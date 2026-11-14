import 'package:flutter/material.dart';

import '../auth/auth_controller.dart';
import '../domains/auto_refresh.dart';
import '../domains/balance.dart';
import '../domains/bulk_sync.dart';
import '../domains/domain_controller.dart';
import '../domains/domain_selection.dart';
import '../domains/refresh.dart';
import '../domains/domain.dart';
import 'domain_detail_screen.dart';
import 'domain_list_screen.dart';
import 'dashboard_screen.dart';
import 'renewal_history_screen.dart';
import 'selected_renewal_screen.dart';
import '../domains/expiry.dart';
import '../theme/app_spacing.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({
    super.key,
    required this.auth,
    this.domains,
    this.autoRefresh,
  });

  final AuthController auth;

  /// Injected by tests; the app builds its own from the auth controller's API.
  final DomainController? domains;

  /// Automatic inventory refresh; not run when null.
  final AutoRefreshController? autoRefresh;

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> with WidgetsBindingObserver {
  _Screen _screen = _Screen.dashboard;

  late final DomainController _domains =
      widget.domains ?? DomainController(api: widget.auth.api);

  // Reload the list and the expiry counts once, when a details batch
  // completes, so refreshed contacts and expiry dates show without a manual
  // refresh. Both keep their previous data if the reload fails. (The balance
  // is not involved: a details batch does not change it.)
  late final BulkSyncController _bulk = BulkSyncController(
    api: widget.auth.api,
    onCompleted: _reloadLocal,
  );

  // Ticked domains. Owned here (the screens sit in an IndexedStack) so the
  // selection survives tab switches and list reloads.
  final DomainSelection _selection = DomainSelection();

  // Independent of the list: a balance failure never affects domains or sync.
  late final BalanceController _balance = BalanceController(
    api: widget.auth.api,
  );

  // The one Refresh (pull-to-refresh and launch/resume): inventory sync and
  // balance in parallel, then the local reload, then new domains' details.
  late final RefreshController _refresh = RefreshController(
    inventory: widget.autoRefresh,
    balance: _balance,
    bulk: _bulk,
    reload: _reloadLocal,
  );

  // List and summary reload together (local database only); each keeps its
  // previous data on failure and reports errors through its own state.
  Future<void> _reloadLocal() =>
      Future.wait([_domains.refresh(), _domains.loadSummary()]);

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _domains.load();
    _domains.loadSummary();
    _autoRefresh();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // The controllers' interval, window and single-flight guards make this
    // cheap and safe.
    if (state == AppLifecycleState.resumed) _autoRefresh();
  }

  // Automatic refresh on launch and resume (inventory at most every 15
  // minutes, balance at most once a minute). The cached list is already on
  // screen. Only a failure is announced.
  Future<void> _autoRefresh() async {
    if (widget.autoRefresh == null) {
      // No provider sync wired (tests): still keep the balance current.
      await _balance.loadOnce();
      return;
    }
    await _refresh.refresh(manual: false);
    if (mounted && _refresh.message != null && _refresh.messageIsError) {
      _announce(_refresh.message!);
    }
  }

  // Pull-to-refresh on the Dashboard and the Domain List.
  Future<void> _pullRefresh() async {
    await _refresh.refresh();
    final message = _refresh.message;
    if (mounted && message != null) _announce(message);
  }

  void _announce(String message) {
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(content: Text(message)));
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _refresh.dispose();
    _bulk.dispose();
    _balance.dispose();
    _domains.dispose();
    _selection.dispose();
    super.dispose();
  }

  void _open(Domain domain) {
    Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => DomainDetailScreen(
          api: widget.auth.api,
          auth: widget.auth,
          domainId: domain.id,
          title: domain.domainName,
        ),
      ),
    );
  }

  /// Dashboard tile: apply the filter to the shared controller (a no-op when it
  /// is already active) and show the Domains screen. Sort and search are kept.
  void _showFiltered(ExpiryFilter filter) {
    _domains.setExpiry(filter);
    _show(_Screen.domains);
  }

  // Switches screen; opening Domains from elsewhere refreshes a stale balance.
  void _show(_Screen screen) {
    final opening = screen == _Screen.domains && _screen != _Screen.domains;
    setState(() => _screen = screen);
    if (opening) _balance.loadOnce();
  }

  void _go(_Screen screen) {
    Navigator.of(context).pop(); // close the drawer
    _show(screen);
  }

  @override
  Widget build(BuildContext context) {
    final onDashboard = _screen == _Screen.dashboard;
    final theme = Theme.of(context);
    // The two-line title needs more than the default 56dp at large font sizes.
    final titleHeight = 44 * MediaQuery.textScalerOf(context).scale(1);
    return PopScope(
      // Back from Domains returns to the Dashboard; from the Dashboard it leaves.
      canPop: onDashboard,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) setState(() => _screen = _Screen.dashboard);
      },
      child: Scaffold(
        appBar: AppBar(
          toolbarHeight: titleHeight > kToolbarHeight
              ? titleHeight
              : kToolbarHeight,
          title: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'ResellerClub Domain Manager',
                style: theme.textTheme.labelMedium,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
              Text(
                _screen.title,
                style: theme.textTheme.titleMedium,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ],
          ),
          actions: [
            IconButton(
              tooltip: 'Log out',
              icon: const Icon(Icons.logout),
              onPressed: () async {
                await widget.autoRefresh?.reset();
                await widget.auth.logout();
              },
            ),
          ],
        ),
        drawer: Drawer(
          // Scrolls so large fonts on small screens never overflow it.
          child: SafeArea(
            child: ListView(
              padding: const EdgeInsets.only(bottom: AppSpacing.md),
              children: [
                Padding(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: Semantics(
                    header: true,
                    child: Text(
                      'ResellerClub Domain Manager',
                      style: theme.textTheme.titleMedium,
                    ),
                  ),
                ),
                const Divider(height: 1),
                const SizedBox(height: AppSpacing.sm),
                for (final s in _Screen.values) _destination(theme, s),
                Padding(
                  padding: _drawerItemPadding,
                  child: ListTile(
                    key: const ValueKey('drawer-renewal-history'),
                    leading: const Icon(Icons.history),
                    title: const Text('Renewal history'),
                    onTap: () {
                      Navigator.of(context).pop(); // close the drawer
                      showRenewalHistory(context, api: widget.auth.api);
                    },
                  ),
                ),
              ],
            ),
          ),
        ),
        // Both screens stay mounted so list position, search text and loaded
        // pages survive switching; they share one controller.
        body: Column(
          children: [
            // One global progress bar for the whole Refresh (inventory,
            // balance and the local reload).
            ListenableBuilder(
              listenable: _refresh,
              builder: (context, _) => SizedBox(
                height: 2,
                child: _refresh.refreshing
                    ? const LinearProgressIndicator(
                        key: ValueKey('refresh-progress'),
                      )
                    : null,
              ),
            ),
            Expanded(child: _body()),
          ],
        ),
      ),
    );
  }

  static const _drawerItemPadding = EdgeInsets.symmetric(
    horizontal: AppSpacing.sm,
    vertical: 2,
  );

  /// A drawer destination. The selected one is marked by more than colour: a
  /// filled icon, bold label, a trailing check and an outlined indicator.
  Widget _destination(ThemeData theme, _Screen s) {
    final scheme = theme.colorScheme;
    final selected = s == _screen;
    return Padding(
      padding: _drawerItemPadding,
      child: ListTile(
        leading: Icon(selected ? s.selectedIcon : s.icon),
        title: Text(
          s.title,
          style: selected
              ? theme.textTheme.bodyLarge?.copyWith(fontWeight: FontWeight.w700)
              : null,
        ),
        trailing: selected ? const Icon(Icons.check, size: 20) : null,
        selected: selected,
        selectedColor: scheme.onPrimaryContainer,
        selectedTileColor: scheme.primaryContainer,
        shape: RoundedRectangleBorder(
          borderRadius: AppRadius.control,
          side: selected
              ? BorderSide(color: scheme.primary, width: 1.5)
              : BorderSide.none,
        ),
        onTap: () => _go(s),
      ),
    );
  }

  Widget _body() {
    return IndexedStack(
      index: _screen.index,
      children: [
        DashboardScreen(
          controller: _domains,
          onRefresh: _pullRefresh,
          refresh: _refresh,
          onSelected: _showFiltered,
        ),
        DomainListScreen(
          controller: _domains,
          onOpen: _open,
          onRenewSelected: () => showSelectedRenewalDetails(
            context,
            api: widget.auth.api,
            selection: _selection,
          ),
          selection: _selection,
          bulk: _bulk,
          balance: _balance,
          onRefresh: _pullRefresh,
          refresh: _refresh,
        ),
      ],
    );
  }
}

enum _Screen {
  dashboard('Dashboard', Icons.dashboard_outlined, Icons.dashboard),
  domains('Domains', Icons.dns_outlined, Icons.dns);

  const _Screen(this.title, this.icon, this.selectedIcon);

  final String title;
  final IconData icon;
  final IconData selectedIcon;
}

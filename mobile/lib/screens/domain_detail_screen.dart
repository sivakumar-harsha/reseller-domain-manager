import 'package:flutter/material.dart';

import '../auth/auth_controller.dart';
import '../core/api_client.dart';
import '../domains/domain.dart';
import '../domains/domain_controller.dart';
import '../domains/expiry.dart';
import '../theme/app_spacing.dart';
import '../widgets/info_row.dart';
import '../widgets/outcome_banner.dart';
import '../widgets/section_card.dart';
import '../widgets/state_view.dart';
import '../widgets/status_badge.dart';

/// Read-only details of one cached domain. Closes itself when the session ends
/// so a protected screen never stays visible over the login form.
class DomainDetailScreen extends StatefulWidget {
  const DomainDetailScreen({
    super.key,
    required this.api,
    required this.auth,
    required this.domainId,
    required this.title,
    this.now,
  });

  final ApiClient api;
  final AuthController auth;
  final int domainId;

  /// Name from the list row, shown in the app bar until the details load.
  final String title;

  /// Clock override for tests.
  final DateTime Function()? now;

  @override
  State<DomainDetailScreen> createState() => _DomainDetailScreenState();
}

class _DomainDetailScreenState extends State<DomainDetailScreen> {
  DomainDetail? _detail;
  String? _error;
  bool _gone = false;
  bool _loading = true;
  int _generation = 0;

  @override
  void initState() {
    super.initState();
    widget.auth.addListener(_onAuth);
    _load();
  }

  @override
  void dispose() {
    widget.auth.removeListener(_onAuth);
    super.dispose();
  }

  // Domain ids whose provider refresh is running, across screen instances, so
  // the same domain is never enriched twice at once.
  static final Set<int> _enriching = {};

  bool _refreshing = false;
  String? _refreshError;

  /// Asks the backend to re-read this one domain's contacts and nameservers
  /// from the provider (`POST domains/{id}/enrich`, stored ids only), then
  /// reloads the local details. Never triggers an inventory sync.
  Future<void> _refreshDetails() async {
    final id = widget.domainId;
    if (_refreshing || !_enriching.add(id)) return;
    setState(() {
      _refreshing = true;
      _refreshError = null;
    });
    try {
      await widget.api.enrichDomain(id);
      await _reload();
    } on ApiException catch (e) {
      if (mounted) setState(() => _refreshError = _refreshText(e));
    } finally {
      _enriching.remove(id);
      if (mounted) setState(() => _refreshing = false);
    }
  }

  /// Re-reads the local details without blanking the screen; a failure keeps
  /// what is shown.
  Future<void> _reload() async {
    final gen = ++_generation;
    try {
      final detail = await widget.api.getDomain(widget.domainId);
      if (!mounted || gen != _generation) return;
      setState(() => _detail = detail);
    } on ApiException catch (e) {
      if (!mounted || gen != _generation) return;
      setState(
        () => _refreshError =
            'Details were refreshed but could not be reloaded. '
            '${DomainController.messageFor(e)}',
      );
    }
  }

  static String _refreshText(ApiException e) => switch (e.code) {
    'network_error' => 'Cannot reach the server. The domain was not refreshed.',
    'unauthorized' ||
    'storage_error' => 'Your session is no longer valid. Please sign in again.',
    'too_many_requests' => 'Too many requests. Wait a moment and try again.',
    'provider_error' => 'ResellerClub could not return the details. Try again.',
    'provider_not_configured' =>
      'The provider connection is not configured on the server.',
    'customer_unknown' || 'customer_mismatch' =>
      'Customer details do not match the provider; nothing changed.',
    'not_found' => 'This domain is no longer available.',
    _ => 'Could not refresh this domain (${e.code}).',
  };

  void _onAuth() {
    if (widget.auth.status != AuthStatus.signedIn && mounted) {
      Navigator.of(context).popUntil((route) => route.isFirst);
    }
  }

  Future<void> _load() async {
    final gen = ++_generation;
    setState(() {
      _loading = true;
      _error = null;
      _gone = false;
    });
    try {
      final detail = await widget.api.getDomain(widget.domainId);
      if (!mounted || gen != _generation) return;
      setState(() => _detail = detail);
    } on ApiException catch (e) {
      if (!mounted || gen != _generation) return;
      setState(() {
        if (e.statusCode == 404) {
          _gone = true;
        } else {
          _error = DomainController.messageFor(e);
        }
      });
    } finally {
      if (mounted && gen == _generation) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final canRefresh = !_loading && !_gone && _detail != null;
    return Scaffold(
      appBar: AppBar(
        title: Text(_detail?.domain.domainName ?? widget.title),
        actions: [
          if (canRefresh)
            IconButton(
              key: const ValueKey('refresh-details'),
              tooltip: 'Refresh details',
              icon: const Icon(Icons.refresh),
              onPressed: _refreshing ? null : _refreshDetails,
            ),
        ],
        bottom: _refreshing
            ? const PreferredSize(
                preferredSize: Size.fromHeight(2),
                child: LinearProgressIndicator(
                  key: ValueKey('details-refreshing'),
                ),
              )
            : null,
      ),
      body: widget.auth.status != AuthStatus.signedIn
          ? const SizedBox.shrink()
          : Column(
              children: [
                if (_refreshError != null)
                  Padding(
                    padding: const EdgeInsets.fromLTRB(
                      AppSpacing.md,
                      AppSpacing.sm,
                      AppSpacing.md,
                      0,
                    ),
                    child: OutcomeBanner(
                      key: const ValueKey('details-refresh-error'),
                      tone: BadgeTone.danger,
                      title: 'Refresh failed',
                      message: _refreshError,
                      icon: Icons.error_outline,
                    ),
                  ),
                Expanded(child: _body()),
              ],
            ),
    );
  }

  Widget _body() {
    if (_loading) return const StateView.loading();
    if (_gone) {
      return const StateView.empty(
        icon: Icons.search_off,
        message: 'This domain is no longer available.',
      );
    }
    final d = _detail;
    if (_error != null || d == null) {
      return StateView.error(
        message: _error ?? 'Could not load domain details.',
        onRetry: _load,
      );
    }
    return _Details(detail: d, now: (widget.now ?? DateTime.now)());
  }
}

String _dateTime(DateTime v) => formatDateTime(v);

/// Status badge tone. Only `active` and `expired` carry a known meaning; any
/// other provider status is shown neutrally rather than guessed at.
BadgeTone _statusTone(String status) => switch (status.toLowerCase()) {
  'active' => BadgeTone.success,
  'expired' => BadgeTone.danger,
  _ => BadgeTone.neutral,
};

/// The expiry colour ladder of the domain list (expired, within 7 days, within
/// 30 days, otherwise fine), expressed as badge tones from [ExpiryInfo].
BadgeTone _expiryTone(ExpiryInfo info) {
  if (info.isUnknown) return BadgeTone.neutral;
  if (info.isExpired) return BadgeTone.danger;
  if (info.expiresWithin(7)) return BadgeTone.warning;
  if (info.expiresWithin(30)) return BadgeTone.caution;
  return BadgeTone.success;
}

/// Persistent summary above the tabs, then Details / Contacts / Sync. The tabs
/// only switch what is shown; the data was loaded once by the screen.
class _Details extends StatelessWidget {
  const _Details({required this.detail, required this.now});

  final DomainDetail detail;
  final DateTime now;

  @override
  Widget build(BuildContext context) {
    final d = detail.domain;
    final scheme = Theme.of(context).colorScheme;
    final height = MediaQuery.sizeOf(context).height;
    return DefaultTabController(
      length: 3,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // Scrolls on its own so a large font cannot push the tabs off screen.
          ConstrainedBox(
            constraints: BoxConstraints(maxHeight: height * 0.4),
            child: SingleChildScrollView(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (d.isMissing)
                    Container(
                      color: scheme.errorContainer,
                      padding: const EdgeInsets.all(AppSpacing.md),
                      child: Row(
                        children: [
                          Icon(Icons.warning_amber, color: scheme.error),
                          const SizedBox(width: AppSpacing.component),
                          const Expanded(
                            child: Text(
                              'Not listed by the provider in the last complete '
                              'sync. This domain may have been removed or '
                              'transferred away.',
                            ),
                          ),
                        ],
                      ),
                    ),
                  _Summary(domain: d, now: now),
                ],
              ),
            ),
          ),
          const _Tabs(),
          Expanded(
            child: TabBarView(
              children: [
                _DetailsTab(detail: detail),
                _ContactsTab(detail: detail),
                _SyncTab(detail: detail),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Domain name, provider status and expiry: what matters most, always visible.
class _Summary extends StatelessWidget {
  const _Summary({required this.domain, required this.now});

  final Domain domain;
  final DateTime now;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final info = ExpiryInfo.of(domain.expiresAt, now);
    final status = domain.status;
    return ColoredBox(
      color: theme.colorScheme.surface,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(
          AppSpacing.md,
          AppSpacing.component,
          AppSpacing.md,
          AppSpacing.component,
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Semantics(
              header: true,
              child: Text(
                domain.domainName,
                key: const ValueKey('summary-domain'),
                style: theme.textTheme.titleLarge,
              ),
            ),
            const SizedBox(height: AppSpacing.sm),
            Wrap(
              spacing: AppSpacing.sm,
              runSpacing: AppSpacing.sm,
              children: [
                if (status != null)
                  StatusBadge(label: status, tone: _statusTone(status)),
                StatusBadge(
                  label: describeExpiry(domain.expiresAt, now),
                  tone: _expiryTone(info),
                  icon: Icons.event,
                ),
                if (domain.isMissing)
                  const StatusBadge(
                    label: 'Missing from provider',
                    tone: BadgeTone.danger,
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _Tabs extends StatelessWidget {
  const _Tabs();

  @override
  Widget build(BuildContext context) {
    // Three tabs share the width until a large font would clip their labels;
    // then they scroll instead.
    final big = MediaQuery.textScalerOf(context).scale(14) > 18;
    return Material(
      color: Theme.of(context).colorScheme.surface,
      child: TabBar(
        isScrollable: big,
        tabAlignment: big ? TabAlignment.start : TabAlignment.fill,
        tabs: const [
          Tab(height: 48, key: ValueKey('tab-details'), text: 'Details'),
          Tab(height: 48, key: ValueKey('tab-contacts'), text: 'Contacts'),
          Tab(height: 48, key: ValueKey('tab-sync'), text: 'Sync'),
        ],
      ),
    );
  }
}

/// Shared scaffold of a tab: padded and scrollable.
class _TabList extends StatelessWidget {
  const _TabList({required this.children});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: AppSpacing.screenPadding,
      children: [
        for (var i = 0; i < children.length; i++) ...[
          if (i > 0) const SizedBox(height: AppSpacing.component),
          children[i],
        ],
      ],
    );
  }
}

String _flag(bool v) => v ? 'Yes' : 'No';

class _DetailsTab extends StatelessWidget {
  const _DetailsTab({required this.detail});

  final DomainDetail detail;

  @override
  Widget build(BuildContext context) {
    final d = detail.domain;
    final ns = detail.nameservers;
    final registered = detail.registeredAt;
    final protection = <(String, bool)>[
      if (d.autoRenew != null) ('Auto-renew', d.autoRenew!),
      if (d.privacyEnabled != null) ('Privacy protection', d.privacyEnabled!),
      if (d.registrarLock != null) ('Registrar lock', d.registrarLock!),
    ];
    final muted = Theme.of(context).colorScheme.onSurfaceVariant;
    return _TabList(
      children: [
        // Status and expiry live in the summary above; only what it lacks here.
        if (registered != null)
          SectionCard(
            title: 'Overview',
            child: InfoRow(label: 'Registered', value: formatDate(registered)),
          ),
        if (protection.isNotEmpty)
          SectionCard(
            title: 'Protection',
            child: Column(
              children: [
                for (final p in protection)
                  InfoRow(label: p.$1, value: _flag(p.$2)),
              ],
            ),
          ),
        SectionCard(
          title: 'Nameservers',
          child: ns == null || ns.isEmpty
              ? Text(
                  ns == null ? 'Not synced' : 'None listed',
                  style: Theme.of(
                    context,
                  ).textTheme.bodyMedium?.copyWith(color: muted),
                )
              : Column(
                  children: [
                    for (var i = 0; i < ns.length; i++)
                      InfoRow(
                        label: 'Nameserver ${i + 1}',
                        value: ns[i],
                        stacked: true,
                        selectable: true,
                      ),
                  ],
                ),
        ),
        if (detail.rcOrderId != null || detail.rcCustomerId != null)
          SectionCard(
            title: 'Provider IDs',
            child: Column(
              children: [
                if (detail.rcOrderId != null)
                  InfoRow(
                    label: 'Order ID',
                    value: detail.rcOrderId!,
                    stacked: true,
                    selectable: true,
                  ),
                if (detail.rcCustomerId != null)
                  InfoRow(
                    label: 'Customer ID',
                    value: detail.rcCustomerId!,
                    stacked: true,
                    selectable: true,
                  ),
              ],
            ),
          ),
      ],
    );
  }
}

const _roleTitles = {
  'registrant': 'Registrant Contact',
  'admin': 'Administrative Contact',
  'tech': 'Technical Contact',
  'billing': 'Billing Contact',
};

class _ContactsTab extends StatelessWidget {
  const _ContactsTab({required this.detail});

  final DomainDetail detail;

  @override
  Widget build(BuildContext context) {
    return _TabList(
      children: [
        for (final role in contactRoles)
          _ContactCard(
            role: role,
            title: _roleTitles[role]!,
            contact: detail.contacts[role],
          ),
      ],
    );
  }
}

/// One contact role. Three distinct states: not synchronised (no contact),
/// synchronised but empty, and available (collapsible).
class _ContactCard extends StatelessWidget {
  const _ContactCard({
    required this.role,
    required this.title,
    required this.contact,
  });

  final String role;
  final String title;
  final ContactInfo? contact;

  @override
  Widget build(BuildContext context) {
    final c = contact;
    if (c == null) {
      return SectionCard(
        child: InfoRow(
          key: ValueKey('contact-$role'),
          label: title,
          value: 'Not synchronised',
          maxLines: 6,
        ),
      );
    }
    final rows = <(String, String?, bool)>[
      ('Name', c.name, false),
      ('Company', c.company, false),
      ('Email', c.email, true),
      ('Telephone', c.telephone, true),
      ('Address', c.address, true),
    ].where((r) => r.$2 != null).toList();
    if (rows.isEmpty) {
      return SectionCard(
        child: InfoRow(
          key: ValueKey('contact-$role'),
          label: title,
          value: 'Synchronised — no details on file',
          maxLines: 6,
        ),
      );
    }
    final theme = Theme.of(context);
    final summary = [
      c.name,
      c.company,
      c.email,
    ].whereType<String>().take(2).join(' · ');
    return Card(
      clipBehavior: Clip.antiAlias,
      child: ExpansionTile(
        key: ValueKey('contact-$role'),
        shape: const Border(),
        collapsedShape: const Border(),
        tilePadding: const EdgeInsets.symmetric(horizontal: AppSpacing.md),
        childrenPadding: const EdgeInsets.fromLTRB(
          AppSpacing.md,
          0,
          AppSpacing.md,
          AppSpacing.md,
        ),
        expandedCrossAxisAlignment: CrossAxisAlignment.start,
        title: Text(title, style: theme.textTheme.titleSmall),
        subtitle: Text(
          summary,
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
          style: theme.textTheme.bodySmall,
        ),
        children: [
          for (final r in rows)
            InfoRow(label: r.$1, value: r.$2!, stacked: true, selectable: r.$3),
        ],
      ),
    );
  }
}

/// Diagnostics, kept apart from the domain's own details.
class _SyncTab extends StatelessWidget {
  const _SyncTab({required this.detail});

  final DomainDetail detail;

  @override
  Widget build(BuildContext context) {
    final d = detail.domain;
    return _TabList(
      children: [
        SectionCard(
          title: 'Synchronisation',
          child: Column(
            children: [
              if (d.lastSyncedAt != null)
                InfoRow(
                  label: 'Last synced',
                  value: _dateTime(d.lastSyncedAt!),
                ),
              InfoRow(
                label: 'Contact details synced',
                value: detail.detailsSyncedAt == null
                    ? 'Not yet synchronised'
                    : _dateTime(detail.detailsSyncedAt!),
              ),
              if (detail.firstSeenAt != null)
                InfoRow(
                  label: 'First seen',
                  value: _dateTime(detail.firstSeenAt!),
                ),
              InfoRow(
                label: 'Missing from provider',
                value: d.isMissing ? 'Yes' : 'No',
              ),
            ],
          ),
        ),
      ],
    );
  }
}

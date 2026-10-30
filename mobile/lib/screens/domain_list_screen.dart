import 'package:flutter/material.dart';

import '../domains/domain.dart';
import '../domains/domain_controller.dart';

/// Read-only list of cached domains with search, pull-to-refresh and
/// load-more. Owns no state beyond the scroll position and search box.
class DomainListScreen extends StatefulWidget {
  const DomainListScreen(
      {super.key, required this.controller, this.onOpen, this.now});

  final DomainController controller;

  /// Called when a row is tapped; rows are not tappable when null.
  final void Function(Domain domain)? onOpen;

  /// Clock override for tests.
  final DateTime Function()? now;

  @override
  State<DomainListScreen> createState() => _DomainListScreenState();
}

class _DomainListScreenState extends State<DomainListScreen> {
  final _scroll = ScrollController();
  final _search = TextEditingController();

  DomainController get _c => widget.controller;

  @override
  void initState() {
    super.initState();
    _scroll.addListener(_maybeLoadMore);
  }

  @override
  void dispose() {
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

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
          child: TextField(
            controller: _search,
            textInputAction: TextInputAction.search,
            autocorrect: false,
            decoration: InputDecoration(
              hintText: 'Search domains',
              prefixIcon: const Icon(Icons.search),
              border: const OutlineInputBorder(),
              isDense: true,
              suffixIcon: ValueListenableBuilder<TextEditingValue>(
                valueListenable: _search,
                builder: (context, value, _) => value.text.isEmpty
                    ? const SizedBox.shrink()
                    : IconButton(
                        tooltip: 'Clear search',
                        icon: const Icon(Icons.clear),
                        onPressed: () {
                          _search.clear();
                          _c.setQuery('');
                        },
                      ),
              ),
            ),
            onChanged: _c.setQuery,
          ),
        ),
        Expanded(
          child: ListenableBuilder(
            listenable: _c,
            builder: (context, _) => _body(context),
          ),
        ),
      ],
    );
  }

  Widget _body(BuildContext context) {
    if (_c.loading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_c.error != null && _c.items.isEmpty) {
      return _scrollable([
        _Message(
          icon: Icons.error_outline,
          text: _c.error!,
          action: FilledButton(onPressed: _c.retry, child: const Text('Retry')),
        ),
      ]);
    }
    if (_c.isEmpty) {
      return _scrollable([
        _Message(
          icon: Icons.dns_outlined,
          text: _c.query.isEmpty
              ? 'No domains yet. They appear here after the first sync.'
              : 'No domains match "${_c.query}".',
        ),
      ]);
    }

    final now = (widget.now ?? DateTime.now)();
    final header = <Widget>[
      if (_c.error != null) _Banner(text: _c.error!, onRetry: _c.retry),
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 8),
        child: Text(
          '${_c.total} ${_c.total == 1 ? 'domain' : 'domains'} · '
          '${_c.lastSyncedAt == null ? 'never synced' : 'last synced ${formatDateTime(_c.lastSyncedAt!)}'}',
          style: Theme.of(context).textTheme.bodySmall,
        ),
      ),
    ];

    return RefreshIndicator(
      onRefresh: _c.refresh,
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
              onTap: widget.onOpen == null ? null : () => widget.onOpen!(domain),
            );
          }
          return _footer();
        },
      ),
    );
  }

  Widget _footer() {
    if (_c.loadingMore) {
      return const Padding(
        padding: EdgeInsets.all(16),
        child: Center(child: CircularProgressIndicator()),
      );
    }
    if (_c.loadMoreError != null) {
      return _Banner(text: _c.loadMoreError!, onRetry: _c.loadMore);
    }
    if (_c.hasMore) {
      return TextButton(onPressed: _c.loadMore, child: const Text('Load more'));
    }
    return const SizedBox(height: 16);
  }

  Widget _scrollable(List<Widget> children) => RefreshIndicator(
        onRefresh: _c.refresh,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          children: children,
        ),
      );
}

class _DomainTile extends StatelessWidget {
  const _DomainTile({required this.domain, required this.now, this.onTap});

  final Domain domain;
  final DateTime now;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final expired = domain.expiresAt != null && domain.expiresAt!.isBefore(now);
    return ListTile(
      onTap: onTap,
      title: Text(domain.domainName),
      subtitle: Text([
        domain.status ?? 'Status unknown',
        describeExpiry(domain.expiresAt, now),
      ].join('\n')),
      isThreeLine: true,
      trailing: domain.isMissing
          ? Tooltip(
              message: 'Not listed by the provider in the last complete sync',
              child: Icon(Icons.warning_amber, color: scheme.error),
            )
          : null,
      textColor: expired ? scheme.error : null,
    );
  }
}

class _Message extends StatelessWidget {
  const _Message({required this.icon, required this.text, this.action});

  final IconData icon;
  final String text;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.all(32),
      child: Column(
        children: [
          const SizedBox(height: 48),
          Icon(icon, size: 48),
          const SizedBox(height: 16),
          Text(text, textAlign: TextAlign.center),
          if (action != null) ...[const SizedBox(height: 16), action!],
        ],
      ),
    );
  }
}

class _Banner extends StatelessWidget {
  const _Banner({required this.text, required this.onRetry});

  final String text;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return MaterialBanner(
      content: Text(text),
      actions: [TextButton(onPressed: onRetry, child: const Text('Retry'))],
    );
  }
}

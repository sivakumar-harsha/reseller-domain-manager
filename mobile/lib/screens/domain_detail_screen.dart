import 'package:flutter/material.dart';

import '../auth/auth_controller.dart';
import '../core/api_client.dart';
import '../domains/domain.dart';
import '../domains/domain_controller.dart';

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
    return Scaffold(
      appBar: AppBar(title: Text(_detail?.domain.domainName ?? widget.title)),
      body: widget.auth.status != AuthStatus.signedIn
          ? const SizedBox.shrink()
          : _body(),
    );
  }

  Widget _body() {
    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_gone) {
      return const _Message(
        icon: Icons.search_off,
        text: 'This domain is no longer available.',
      );
    }
    final d = _detail;
    if (_error != null || d == null) {
      return _Message(
        icon: Icons.error_outline,
        text: _error ?? 'Could not load domain details.',
        action: FilledButton(onPressed: _load, child: const Text('Retry')),
      );
    }
    return _Details(detail: d, now: (widget.now ?? DateTime.now)());
  }
}

class _Details extends StatelessWidget {
  const _Details({required this.detail, required this.now});

  final DomainDetail detail;
  final DateTime now;

  static String _flag(bool? v) => v == null ? 'Unknown' : (v ? 'Yes' : 'No');
  static String _date(DateTime? v) => v == null ? 'Unknown' : formatDate(v);
  static String _dateTime(DateTime? v) =>
      v == null ? 'Unknown' : formatDateTime(v);

  @override
  Widget build(BuildContext context) {
    final d = detail.domain;
    final ns = detail.nameservers;
    final scheme = Theme.of(context).colorScheme;
    return ListView(
      padding: const EdgeInsets.symmetric(vertical: 8),
      children: [
        if (d.isMissing)
          Container(
            color: scheme.errorContainer,
            padding: const EdgeInsets.all(16),
            child: Row(
              children: [
                Icon(Icons.warning_amber, color: scheme.error),
                const SizedBox(width: 12),
                const Expanded(
                  child: Text(
                    'Not listed by the provider in the last complete sync. '
                    'This domain may have been removed or transferred away.',
                  ),
                ),
              ],
            ),
          ),
        _Field('Domain', d.domainName),
        _Field('Status', d.status ?? 'Unknown'),
        _Field('Expiry', describeExpiry(d.expiresAt, now)),
        _Field('Auto-renew', _flag(d.autoRenew)),
        _Field('Privacy protection', _flag(d.privacyEnabled)),
        _Field('Registrar lock', _flag(d.registrarLock)),
        _Field(
          'Nameservers',
          ns == null
              ? 'Not synced'
              : (ns.isEmpty ? 'None listed' : ns.join('\n')),
        ),
        _Field('Registered', _date(detail.registeredAt)),
        _Field('First seen', _dateTime(detail.firstSeenAt)),
        _Field('Last synced', _dateTime(d.lastSyncedAt)),
        _Field('Missing from provider', d.isMissing ? 'Yes' : 'No'),
        _Field('Order ID', detail.rcOrderId ?? 'Unknown'),
        _Field('Customer ID', detail.rcCustomerId ?? 'Unknown'),
      ],
    );
  }
}

class _Field extends StatelessWidget {
  const _Field(this.label, this.value);

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return ListTile(
      title: Text(label, style: Theme.of(context).textTheme.bodySmall),
      subtitle: Text(value, style: Theme.of(context).textTheme.bodyLarge),
      dense: true,
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
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 48),
            const SizedBox(height: 16),
            Text(text, textAlign: TextAlign.center),
            if (action != null) ...[const SizedBox(height: 16), action!],
          ],
        ),
      ),
    );
  }
}

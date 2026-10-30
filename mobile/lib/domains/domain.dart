/// One row of `GET /api/v1/domains`. Provider-derived fields stay null when the
/// backend does not know them; nothing is defaulted or invented.
class Domain {
  const Domain({
    required this.id,
    required this.domainName,
    this.status,
    this.expiresAt,
    this.autoRenew,
    this.privacyEnabled,
    this.registrarLock,
    this.isMissing = false,
    this.lastSyncedAt,
  });

  final int id;
  final String domainName;
  final String? status;

  /// UTC instant, or null when unknown or unparsable.
  final DateTime? expiresAt;
  final bool? autoRenew;
  final bool? privacyEnabled;
  final bool? registrarLock;

  /// True when the last complete sync no longer listed this domain.
  final bool isMissing;
  final DateTime? lastSyncedAt;

  /// Returns null when the row has no usable id or name, so one bad row cannot
  /// hide the rest of a page.
  static Domain? tryParse(Object? json) {
    if (json is! Map) return null;
    final id = json['id'];
    final name = json['domain_name'];
    if (id is! num || id != id.truncate() || name is! String || name.isEmpty) {
      return null;
    }
    final status = json['status'];
    return Domain(
      id: id.toInt(),
      domainName: name,
      status: status is String && status.isNotEmpty ? status : null,
      expiresAt: _date(json['expires_at']),
      autoRenew: _flag(json['auto_renew']),
      privacyEnabled: _flag(json['privacy_enabled']),
      registrarLock: _flag(json['registrar_lock']),
      isMissing: _flag(json['is_missing']) ?? false,
      lastSyncedAt: _date(json['last_synced_at']),
    );
  }
}

class DomainPage {
  const DomainPage({
    required this.items,
    required this.page,
    required this.perPage,
    required this.total,
    required this.totalPages,
    this.lastSyncedAt,
    this.skipped = 0,
  });

  final List<Domain> items;
  final int page;
  final int perPage;
  final int total;
  final int totalPages;

  /// Most recent successful row sync, or null if nothing was ever synced.
  final DateTime? lastSyncedAt;

  /// Rows dropped because they had no usable id or name.
  final int skipped;

  bool get hasMore => page < totalPages;

  /// Throws [FormatException] when the envelope is not the documented shape.
  factory DomainPage.fromJson(Map<String, dynamic> json) {
    final data = json['data'];
    final meta = json['meta'];
    if (data is! List || meta is! Map) {
      throw const FormatException('bad domain page');
    }
    final items = <Domain>[];
    for (final row in data) {
      final d = Domain.tryParse(row);
      if (d != null) items.add(d);
    }
    int number(String key, int min) {
      final v = meta[key];
      if (v is! num || v != v.truncate() || v < min) {
        throw const FormatException('bad domain page meta');
      }
      return v.toInt();
    }

    return DomainPage(
      items: items,
      page: number('page', 1),
      perPage: number('per_page', 1),
      total: number('total', 0),
      totalPages: number('total_pages', 0),
      lastSyncedAt: _date(json['last_synced_at']),
      skipped: data.length - items.length,
    );
  }
}

/// `GET /api/v1/domains/{id}`: the list fields plus provider references and
/// history. Every nullable value stays null when the backend does not know it.
class DomainDetail {
  const DomainDetail({
    required this.domain,
    this.nameservers,
    this.rcOrderId,
    this.rcCustomerId,
    this.registeredAt,
    this.firstSeenAt,
  });

  final Domain domain;

  /// Null when nameservers have not been synced; an empty list means the
  /// backend returned none.
  final List<String>? nameservers;
  final String? rcOrderId;
  final String? rcCustomerId;
  final DateTime? registeredAt;
  final DateTime? firstSeenAt;

  /// Throws [FormatException] when `id` or `domain_name` is missing or invalid.
  factory DomainDetail.fromJson(Map<String, dynamic> json) {
    final domain = Domain.tryParse(json);
    if (domain == null) throw const FormatException('bad domain detail');
    final ns = json['nameservers'];
    return DomainDetail(
      domain: domain,
      nameservers: ns is List ? ns.whereType<String>().toList() : null,
      rcOrderId: _text(json['rc_order_id']),
      rcCustomerId: _text(json['rc_customer_id']),
      registeredAt: _date(json['registered_at']),
      firstSeenAt: _date(json['first_seen_at']),
    );
  }
}

String? _text(Object? v) => v is String && v.isNotEmpty ? v : null;

DateTime? _date(Object? v) => v is String ? DateTime.tryParse(v)?.toUtc() : null;

/// Accepts real booleans and 0/1; anything else is "unknown".
bool? _flag(Object? v) {
  if (v is bool) return v;
  if (v is num && (v == 0 || v == 1)) return v == 1;
  return null;
}

String _two(int n) => n.toString().padLeft(2, '0');

/// Local calendar date as `YYYY-MM-DD`.
String formatDate(DateTime utc) {
  final l = utc.toLocal();
  return '${l.year}-${_two(l.month)}-${_two(l.day)}';
}

/// Local date and time as `YYYY-MM-DD HH:MM`.
String formatDateTime(DateTime utc) {
  final l = utc.toLocal();
  return '${formatDate(utc)} ${_two(l.hour)}:${_two(l.minute)}';
}

/// "Expires 2026-12-01 (in 34 days)", "Expired 2026-01-01 (3 days ago)",
/// or "Expiry unknown". Compares local calendar days.
String describeExpiry(DateTime? expiresAt, DateTime now) {
  if (expiresAt == null) return 'Expiry unknown';
  final e = expiresAt.toLocal();
  final n = now.toLocal();
  final days = DateTime.utc(e.year, e.month, e.day)
      .difference(DateTime.utc(n.year, n.month, n.day))
      .inDays;
  final date = formatDate(expiresAt);
  if (days > 1) return 'Expires $date (in $days days)';
  if (days == 1) return 'Expires $date (tomorrow)';
  if (days == 0) return 'Expires $date (today)';
  if (days == -1) return 'Expired $date (yesterday)';
  return 'Expired $date (${-days} days ago)';
}

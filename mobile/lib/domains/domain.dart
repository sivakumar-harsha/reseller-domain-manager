import 'expiry.dart';

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
    this.detailsSyncedAt,
    this.contacts = const {},
  });

  final Domain domain;

  /// When contact details were last synchronised; null when never.
  final DateTime? detailsSyncedAt;

  /// Stored contacts by role (`registrant`, `admin`, `tech`, `billing`). A
  /// role is absent when the backend has no contact for it.
  final Map<String, ContactInfo> contacts;

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
      detailsSyncedAt: _date(json['details_synced_at']),
      contacts: _contacts(json['contacts']),
    );
  }
}

Map<String, ContactInfo> _contacts(Object? json) {
  if (json is! Map) return const {};
  final out = <String, ContactInfo>{};
  for (final role in contactRoles) {
    final c = ContactInfo.tryParse(json[role]);
    if (c != null) out[role] = c;
  }
  return out;
}

/// Contact roles in display order.
const contactRoles = ['registrant', 'admin', 'tech', 'billing'];

/// One stored contact. Every field is optional; blanks are normalised to null.
class ContactInfo {
  const ContactInfo({
    this.name,
    this.company,
    this.email,
    this.telCountryCode,
    this.telNumber,
    this.address1,
    this.address2,
    this.address3,
    this.city,
    this.state,
    this.country,
    this.postalCode,
  });

  final String? name,
      company,
      email,
      telCountryCode,
      telNumber,
      address1,
      address2,
      address3,
      city,
      state,
      country,
      postalCode;

  static ContactInfo? tryParse(Object? json) {
    if (json is! Map) return null;
    return ContactInfo(
      name: _text(json['name']),
      company: _text(json['company']),
      email: _text(json['email']),
      telCountryCode: _text(json['tel_country_code']),
      telNumber: _text(json['tel_number']),
      address1: _text(json['address1']),
      address2: _text(json['address2']),
      address3: _text(json['address3']),
      city: _text(json['city']),
      state: _text(json['state']),
      country: _text(json['country']),
      postalCode: _text(json['postal_code']),
    );
  }

  /// "+CC number", or null when there is no number.
  String? get telephone {
    final number = telNumber;
    if (number == null) return null;
    final cc = telCountryCode;
    return cc == null ? number : '+$cc $number';
  }

  /// Non-empty address lines, one per line.
  String? get address {
    final cityLine = [city, state, postalCode].whereType<String>().join(', ');
    final lines = [
      address1,
      address2,
      address3,
      cityLine.isEmpty ? null : cityLine,
      country,
    ].whereType<String>();
    return lines.isEmpty ? null : lines.join('\n');
  }
}

String? _text(Object? v) => v is String && v.isNotEmpty ? v : null;

DateTime? _date(Object? v) =>
    v is String ? DateTime.tryParse(v)?.toUtc() : null;

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
  final info = ExpiryInfo.of(expiresAt, now);
  if (expiresAt == null) return 'Expiry unknown';
  final days = info.daysRemaining!;
  final date = formatDate(expiresAt);
  return switch (info.status) {
    ExpiryStatus.upcoming => 'Expires $date (in $days days)',
    ExpiryStatus.tomorrow => 'Expires $date (tomorrow)',
    ExpiryStatus.today => 'Expires $date (today)',
    ExpiryStatus.expired when days == -1 => 'Expired $date (yesterday)',
    ExpiryStatus.expired => 'Expired $date (${-days} days ago)',
    ExpiryStatus.unknown => 'Expiry unknown',
  };
}

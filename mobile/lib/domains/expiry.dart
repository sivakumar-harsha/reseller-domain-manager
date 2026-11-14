/// Expiry filter of the domain list. The upcoming windows are cumulative
/// (7 days is a subset of 30 days) and, like every filter except [all],
/// exclude domains the provider no longer lists.
enum ExpiryFilter {
  all('all'),
  expired('expired'),
  within7('within_7_days'),
  within30('within_30_days'),
  within60('within_60_days'),
  within90('within_90_days'),
  unknown('unknown');

  const ExpiryFilter(this.apiValue);

  /// The `expiry` query value.
  final String apiValue;
}

/// Sort order of the domain list. [defaultOrder] sends nothing and keeps the
/// server's default (nearest expiry first, no expiry last).
enum DomainSort {
  defaultOrder(null),
  expiryAsc('expiry_asc'),
  expiryDesc('expiry_desc'),
  nameAsc('name_asc');

  const DomainSort(this.apiValue);

  /// The `sort` query value, or null to omit it.
  final String? apiValue;
}

/// `GET /api/v1/expiry/summary`: cumulative counts of non-missing domains. The
/// upcoming windows overlap on purpose, and the day boundaries are those of
/// [timezone] on [asOfDate].
class ExpirySummary {
  const ExpirySummary({
    required this.expired,
    required this.within7Days,
    required this.within30Days,
    required this.within60Days,
    required this.within90Days,
    required this.unknown,
    this.asOfDate,
    this.timezone,
  });

  final int expired, within7Days, within30Days, within60Days, within90Days;
  final int unknown;

  /// Server's `YYYY-MM-DD` calendar day the counts were computed for.
  final String? asOfDate;
  final String? timezone;

  /// The count behind a list filter; null for [ExpiryFilter.all], which has none.
  int? countFor(ExpiryFilter filter) => switch (filter) {
    ExpiryFilter.all => null,
    ExpiryFilter.expired => expired,
    ExpiryFilter.within7 => within7Days,
    ExpiryFilter.within30 => within30Days,
    ExpiryFilter.within60 => within60Days,
    ExpiryFilter.within90 => within90Days,
    ExpiryFilter.unknown => unknown,
  };

  /// Throws [FormatException] unless every count is a non-negative whole number.
  factory ExpirySummary.fromJson(Map<String, dynamic> json) {
    int count(String key) {
      final v = json[key];
      if (v is! num || v != v.truncate() || v < 0) {
        throw FormatException('bad expiry summary: $key');
      }
      return v.toInt();
    }

    String? text(String key) {
      final v = json[key];
      return v is String && v.isNotEmpty ? v : null;
    }

    return ExpirySummary(
      expired: count('expired'),
      within7Days: count('within_7_days'),
      within30Days: count('within_30_days'),
      within60Days: count('within_60_days'),
      within90Days: count('within_90_days'),
      unknown: count('unknown'),
      asOfDate: text('as_of_date'),
      timezone: text('timezone'),
    );
  }
}

/// Where a domain stands relative to its expiry date.
enum ExpiryStatus {
  /// The expiry calendar day is before today.
  expired,

  /// The expiry calendar day is today, however early or late in the day.
  today,

  /// The expiry calendar day is tomorrow.
  tomorrow,

  /// The expiry calendar day is two or more days away.
  upcoming,

  /// No expiry date is known.
  unknown,
}

/// The one expiry calculation used by every screen.
///
/// Expiry instants are UTC. Both the expiry and [now] are converted to the
/// device's local calendar, and only the calendar dates are compared, so a
/// domain expiring later today is "today" (never "expired") and the result does
/// not depend on the time of day or on daylight-saving changes.
class ExpiryInfo {
  const ExpiryInfo._(this.status, this.daysRemaining);

  factory ExpiryInfo.of(DateTime? expiresAt, DateTime now) {
    if (expiresAt == null) {
      return const ExpiryInfo._(ExpiryStatus.unknown, null);
    }
    final e = expiresAt.toLocal();
    final n = now.toLocal();
    // Date-only values in UTC, so the difference is a whole number of days.
    final days = DateTime.utc(
      e.year,
      e.month,
      e.day,
    ).difference(DateTime.utc(n.year, n.month, n.day)).inDays;
    final status = days < 0
        ? ExpiryStatus.expired
        : days == 0
        ? ExpiryStatus.today
        : days == 1
        ? ExpiryStatus.tomorrow
        : ExpiryStatus.upcoming;
    return ExpiryInfo._(status, days);
  }

  final ExpiryStatus status;

  /// Whole calendar days until expiry: positive in the future, 0 today,
  /// negative once expired (-1 is yesterday). Null when the expiry is unknown.
  final int? daysRemaining;

  bool get isExpired => status == ExpiryStatus.expired;
  bool get isUnknown => status == ExpiryStatus.unknown;

  /// True when the domain has not expired yet (today counts) and the date is known.
  bool get isActive => !isExpired && !isUnknown;

  /// True when it is known to expire within [days] calendar days from today,
  /// inclusive (so exactly 7 days is within 7). False when expired or unknown.
  bool expiresWithin(int days) => isActive && daysRemaining! <= days;
}

import 'package:flutter/foundation.dart';

import '../core/api_client.dart';
import 'balance.dart';
import 'domain_controller.dart';

/// Read-only renewal cost as returned by `GET domains/{id}/renewal-quote`.
/// The amount stays a decimal string. [gst], [vat], [total], the rates and
/// [taxStatus] are always null when built from the API ([fromJson] ignores
/// them): nothing here reads or calculates tax or a final payable amount.
class RenewalQuote {
  const RenewalQuote({
    required this.domain,
    required this.years,
    required this.status,
    this.currency,
    this.amount,
    this.gst,
    this.vat,
    this.total,
    this.gstRate,
    this.vatRate,
    this.taxStatus,
    this.cached = false,
    this.eligibility,
  });

  final String domain;
  final int years;

  /// `available`, `price_unavailable`, `unsupported_tld` or `domain_not_listed`.
  final String status;
  final String? currency;
  final String? amount;

  /// Backend-calculated GST (`tax`), VAT and total, plus the rates in percent.
  final String? gst, vat, total, gstRate, vatRate;

  /// `estimated` when the backend calculated the tax, else `not_established`.
  final String? taxStatus;
  final bool cached;

  /// The backend's renewal-eligibility verdict (status, expiry).
  /// Null when the backend did not send one: nothing is then assumed.
  final RenewalEligibility? eligibility;

  /// True unless the backend said this domain cannot be renewed.
  bool get renewable => eligibility?.eligible != false;

  bool get available =>
      status == 'available' && currency != null && amount != null;

  /// True only when the backend returned a complete, well-formed tax result.
  /// Anything missing means "not available": a zero is never assumed.
  bool get taxEstimated =>
      available &&
      taxStatus == 'estimated' &&
      isMoney(amount) &&
      isMoney(gst) &&
      isMoney(vat) &&
      isMoney(total) &&
      isRate(gstRate) &&
      isRate(vatRate);

  static final _money = RegExp(r'^\d{1,12}\.\d{2}$');
  static final _rate = RegExp(r'^\d{1,3}(\.\d{1,4})?$');

  static bool isMoney(String? v) => v != null && _money.hasMatch(v);
  static bool isRate(String? v) => v != null && _rate.hasMatch(v);

  factory RenewalQuote.fromJson(Map<String, dynamic> json) {
    final domain = json['domain'];
    final years = json['years'];
    final status = json['status'];
    if (domain is! String || years is! num || status is! String) {
      throw const FormatException('bad quote');
    }
    String? text(String key) {
      final v = json[key];
      return v is String ? v : (v is num ? '$v' : null);
    }

    final currency = json['currency'];
    // Only the renewal cost is read. Tax and total are deliberately NOT parsed:
    // they stay null because they are not verified, and the final payable
    // amount must be confirmed in ResellerClub.
    return RenewalQuote(
      domain: domain,
      years: years.toInt(),
      status: status,
      currency: currency is String ? currency : null,
      amount: text('renewal_amount'),
      cached: json['cached'] == true,
      eligibility: RenewalEligibility.tryParse(json['eligibility']),
    );
  }

  /// "₹1,219.00" for INR, otherwise "USD 1,219.00" (code, never a guessed
  /// symbol); null when no amount was returned.
  String? get formattedAmount => available ? money(amount!) : null;

  /// [value] in this quote's currency, formatted for display.
  String money(String value) {
    final c = currency ?? '';
    if (c == 'INR') return '₹${Balance.format('', value).trimLeft()}';
    return Balance.format(c, value);
  }

  /// "GST (18%)" for the rate sent by the backend.
  static String rateLabel(String name, String? rate) =>
      rate == null ? name : '$name ($rate%)';
}

/// Loads one quote. Only ever issues the GET quote request; [load] is also the
/// retry. A newer load supersedes an older one.
class RenewalQuoteController extends ChangeNotifier {
  RenewalQuoteController({
    required ApiClient api,
    required this.domainId,
    this.years = 1,
  }) : _api = api;

  final ApiClient _api;
  final int domainId;
  final int years;

  RenewalQuote? quote;
  String? error;
  bool loading = false;

  int _generation = 0;
  bool _disposed = false;

  Future<void> load() async {
    final gen = ++_generation;
    quote = null;
    error = null;
    loading = true;
    _notify();
    try {
      final result = await _api.getRenewalQuote(domainId, years: years);
      if (_disposed || gen != _generation) return;
      quote = result;
    } on ApiException catch (e) {
      if (_disposed || gen != _generation) return;
      error = messageFor(e);
    } catch (_) {
      if (_disposed || gen != _generation) return;
      error = 'Could not load the renewal quote. Try again.';
    } finally {
      if (!_disposed && gen == _generation) {
        loading = false;
        _notify();
      }
    }
  }

  void _notify() {
    if (!_disposed) notifyListeners();
  }

  @override
  void dispose() {
    _disposed = true;
    super.dispose();
  }

  static String messageFor(ApiException e) {
    switch (e.code) {
      case 'not_found':
        return 'This domain no longer exists.';
      case 'provider_not_configured':
      case 'provider_error':
        return 'The price source is unavailable right now. Try again later.';
      case 'invalid_request':
        return 'That quote request was not valid.';
      case 'network_error':
      case 'unauthorized':
      case 'storage_error':
      case 'too_many_requests':
        return DomainController.messageFor(e);
      default:
        return 'Could not load the renewal quote (${e.code}). Try again.';
    }
  }
}

/// The backend's renewal-eligibility verdict for one domain, shown as is. The
/// app never decides eligibility itself. An expired domain is NOT refused: like
/// the ResellerClub website, the request goes to ResellerClub, which accepts
/// or rejects it.
class RenewalEligibility {
  const RenewalEligibility({
    required this.eligible,
    required this.state,
    this.reason,
    this.expired = false,
    this.expiresAt,
    this.daysSinceExpiry,
  });

  final bool eligible;

  /// `pre_expiry`, `expired`, `domain_not_active` or `expiry_unknown`.
  final String state;
  final String? reason;
  final bool expired;
  final DateTime? expiresAt;
  final int? daysSinceExpiry;

  static RenewalEligibility? tryParse(Object? json) {
    if (json is! Map<String, dynamic>) return null;
    final eligible = json['eligible'];
    final state = json['state'];
    if (eligible is! bool || state is! String) return null;
    DateTime? date(String key) {
      final v = json[key];
      return v is String ? DateTime.tryParse(v)?.toUtc() : null;
    }

    int? whole(String key) {
      final v = json[key];
      return v is int ? v : null;
    }

    return RenewalEligibility(
      eligible: eligible,
      state: state,
      reason: json['reason'] is String ? json['reason'] as String : null,
      expired: json['expired'] == true,
      expiresAt: date('expires_at'),
      daysSinceExpiry: whole('days_since_expiry'),
    );
  }

  static String _date(DateTime d) {
    final l = d.toLocal();
    String two(int n) => n.toString().padLeft(2, '0');
    return '${l.year}-${two(l.month)}-${two(l.day)}';
  }

  /// "Expired 2026-11-12" or "Expires 2026-12-01"; null when unknown.
  String? get expiryLine {
    final d = expiresAt;
    if (d == null) return null;
    return '${expired ? 'Expired' : 'Expires'} ${_date(d)}';
  }

  /// A clear warning for an expired or blocked domain; null when the domain
  /// is simply renewable before its expiry.
  String? get warning {
    switch (state) {
      case 'expired':
        return 'This domain has expired. The renewal request will be sent to '
            'ResellerClub, which decides whether it can still be renewed. '
            'ResellerClub\'s answer will be shown.';
      case 'domain_not_active':
        return 'This domain is not Active (it may be suspended, locked, '
            'deleted or in redemption), so it cannot be renewed from this app.';
      case 'expiry_unknown':
        return 'The expiry date of this domain is unknown, so it cannot be '
            'renewed from this app.';
      default:
        return null;
    }
  }
}

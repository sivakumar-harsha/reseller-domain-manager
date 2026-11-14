import 'package:flutter/foundation.dart';

import '../core/api_client.dart';

/// Read-only reseller balance as returned by `GET balance`. Amounts stay
/// decimal strings so nothing is rounded or reinterpreted on the way.
class Balance {
  const Balance({
    required this.sellingBalance,
    required this.sellingCurrency,
    required this.accountingBalance,
    required this.accountingCurrency,
    this.sellingLockedBalance,
    this.accountingLockedBalance,
  });

  /// Selling and accounting figures are separate and never converted into
  /// each other. Locked balances are null when the server did not send them.
  final String sellingBalance, sellingCurrency;
  final String accountingBalance, accountingCurrency;
  final String? sellingLockedBalance, accountingLockedBalance;

  factory Balance.fromJson(Map<String, dynamic> json) {
    String s(String key) {
      final v = json[key];
      if (v is! String || v.isEmpty) throw const FormatException('bad balance');
      return v;
    }

    String? optional(String key) => json[key] == null ? null : s(key);

    return Balance(
      sellingBalance: s('selling_balance'),
      sellingCurrency: s('selling_currency'),
      accountingBalance: s('accounting_balance'),
      accountingCurrency: s('accounting_currency'),
      sellingLockedBalance: optional('selling_locked_balance'),
      accountingLockedBalance: optional('accounting_locked_balance'),
    );
  }

  /// "USD 6,244.40": currency code, thousands separators, amount as sent.
  static String format(String currency, String amount) {
    final negative = amount.startsWith('-');
    final digits = negative ? amount.substring(1) : amount;
    final dot = digits.indexOf('.');
    final whole = dot < 0 ? digits : digits.substring(0, dot);
    final frac = dot < 0 ? '' : digits.substring(dot);
    final grouped = whole.replaceAllMapped(
      RegExp(r'\B(?=(\d{3})+(?!\d))'),
      (_) => ',',
    );
    return '$currency ${negative ? '-' : ''}$grouped$frac';
  }

  String get selling => format(sellingCurrency, sellingBalance);
  String get accounting => format(accountingCurrency, accountingBalance);
  String? get sellingLocked => sellingLockedBalance == null
      ? null
      : format(sellingCurrency, sellingLockedBalance!);
  String? get accountingLocked => accountingLockedBalance == null
      ? null
      : format(accountingCurrency, accountingLockedBalance!);
}

/// Loads the balance. A failed or in-flight load shows no amount: a previous
/// value is never presented as current. Failures stay inside this controller.
class BalanceController extends ChangeNotifier {
  BalanceController({required ApiClient api, DateTime Function()? now})
    : _api = api,
      _now = now ?? DateTime.now;

  final ApiClient _api;
  final DateTime Function() _now;

  Balance? balance;
  bool loading = false;

  /// True when the last load failed.
  bool unavailable = false;

  /// When the balance last loaded successfully; a failure never sets it.
  DateTime? lastLoadedAt;

  int _generation = 0;
  bool _disposed = false;
  Future<void>? _inflight;

  /// Loads unless a request is already running (joined, not repeated) or the
  /// current value is younger than [within]. Used wherever several triggers
  /// (resume, tab switch, refresh) can fire together. [load] always requests.
  Future<void> loadOnce({Duration within = const Duration(seconds: 60)}) {
    final running = _inflight;
    if (running != null) return running;
    final at = lastLoadedAt;
    if (balance != null && at != null && _now().difference(at) < within) {
      return Future.value();
    }
    return load();
  }

  Future<void> load() {
    final f = _load();
    _inflight = f;
    f.whenComplete(() {
      if (identical(_inflight, f)) _inflight = null;
    });
    return f;
  }

  Future<void> _load() async {
    final gen = ++_generation;
    balance = null;
    loading = true;
    unavailable = false;
    _notify();
    try {
      final result = await _api.getBalance();
      if (_disposed || gen != _generation) return;
      balance = result;
      lastLoadedAt = _now();
    } catch (_) {
      // Any failure, expected or not: show "unavailable", keep no details.
      if (_disposed || gen != _generation) return;
      unavailable = true;
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
}

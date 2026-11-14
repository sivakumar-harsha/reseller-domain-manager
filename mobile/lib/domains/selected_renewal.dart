import 'package:flutter/foundation.dart';

import '../core/api_client.dart';
import 'renewal_quote.dart';

/// One selected domain and its quote state.
class SelectedRenewalEntry {
  SelectedRenewalEntry({
    required this.id,
    required this.name,
    required this.quote,
  });

  final int id;
  final String name;
  final RenewalQuoteController quote;

  bool get loading =>
      quote.loading || (quote.quote == null && quote.error == null);
  bool get failed => quote.error != null;

  /// A verified price from the existing quote service.
  bool get priced => quote.quote?.available == true;

  /// Priced AND not refused by the backend's eligibility rule (status and a
  /// known expiry; an expired date alone is not a refusal). A priced domain
  /// that is not renewable is shown with the
  /// reason and never submitted.
  bool get renewable => priced && quote.quote!.renewable;

  /// Priced but the backend says it cannot be renewed.
  bool get blocked => priced && !quote.quote!.renewable;

  /// The quote service said the extension has no verified product key.
  bool get unsupported => quote.quote?.status == 'unsupported_tld';
}

/// Read-only renewal details for the selected domains. Each domain is quoted
/// through the existing [RenewalQuoteController] (GET only), a few at a time.
/// Pricing rules live in the backend; nothing here prices, renews or pays.
class SelectedRenewalController extends ChangeNotifier {
  SelectedRenewalController({
    required ApiClient api,
    required Iterable<({int id, String? name})> selected,
    this.concurrency = 3,
    this.years = 1,
  }) : entries = [
         for (final s in selected)
           SelectedRenewalEntry(
             id: s.id,
             name: s.name ?? 'Domain #${s.id}',
             quote: RenewalQuoteController(
               api: api,
               domainId: s.id,
               years: years,
             ),
           ),
       ] {
    for (final e in entries) {
      e.quote.addListener(_notify);
    }
  }

  final List<SelectedRenewalEntry> entries;
  final int concurrency;
  final int years;
  bool _disposed = false;

  List<SelectedRenewalEntry> get priced => [
    for (final e in entries)
      if (e.renewable) e,
  ];

  /// Priced domains the backend refuses to renew (not Active, or no known
  /// expiry). Shown with the reason, never
  /// submitted and never part of the totals.
  List<SelectedRenewalEntry> get blocked => [
    for (final e in entries)
      if (e.blocked) e,
  ];

  /// Everything without a verified price: unsupported, unavailable, failed or
  /// still loading. Never dropped from the details.
  List<SelectedRenewalEntry> get unpriced => [
    for (final e in entries)
      if (!e.priced) e,
  ];

  /// Combined renewal cost of the priced domains (cost only, no tax), or null
  /// when they cannot be summed (for example mixed currencies).
  RenewalTotals? get totals =>
      RenewalTotals.of([for (final e in priced) e.quote.quote!]);

  Future<void> load() async {
    final queue = List.of(entries);
    Future<void> worker() async {
      while (queue.isNotEmpty && !_disposed) {
        await queue.removeAt(0).quote.load();
      }
    }

    await Future.wait([for (var i = 0; i < concurrency; i++) worker()]);
  }

  Future<void> retry(SelectedRenewalEntry e) => e.quote.load();

  void _notify() {
    if (!_disposed) notifyListeners();
  }

  @override
  void dispose() {
    _disposed = true;
    for (final e in entries) {
      e.quote.removeListener(_notify);
      e.quote.dispose();
    }
    super.dispose();
  }
}

/// Sum of the per-domain renewal costs the backend returned (integer minor
/// units). Only the cost is added: no tax or final payable amount exists here.
class RenewalTotals {
  const RenewalTotals({
    required this.currency,
    required this.amount,
    required this.count,
  });

  final String currency;
  final String amount;
  final int count;

  /// Null unless at least one domain is priced and every priced domain has a
  /// well-formed cost in one common currency.
  static RenewalTotals? of(Iterable<RenewalQuote> priced) {
    final quotes = priced.toList();
    if (quotes.isEmpty || quotes.any((q) => !RenewalQuote.isMoney(q.amount))) {
      return null;
    }
    final currency = quotes.first.currency!;
    if (quotes.any((q) => q.currency != currency)) return null;
    final cents = quotes.fold<int>(
      0,
      (a, q) => a + int.parse(q.amount!.replaceAll('.', '')),
    );
    return RenewalTotals(
      currency: currency,
      amount: '${cents ~/ 100}.${(cents % 100).toString().padLeft(2, '0')}',
      count: quotes.length,
    );
  }

  String money(String value) => RenewalQuote(
    domain: '',
    years: 1,
    status: 'available',
    currency: currency,
    amount: value,
  ).money(value);
}

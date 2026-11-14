import 'dart:async';
import 'dart:convert';

import 'package:domain_manager/auth/auth_controller.dart';
import 'package:domain_manager/auth/token_storage.dart';
import 'package:domain_manager/core/api_client.dart';
import 'package:domain_manager/domains/bulk_sync.dart';
import 'package:domain_manager/domains/domain_controller.dart';
import 'package:domain_manager/domains/domain_selection.dart';
import 'package:domain_manager/domains/expiry.dart';
import 'package:domain_manager/domains/renewal_quote.dart';
import 'package:domain_manager/domains/selected_renewal.dart';
import 'package:domain_manager/screens/domain_list_screen.dart';
import 'package:domain_manager/screens/renewal_quote_sheet.dart';
import 'package:domain_manager/screens/selected_renewal_screen.dart';
import 'package:domain_manager/theme/app_theme.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

class _Storage implements TokenStorage {
  TokenPair? tokens = const TokenPair(accessToken: 'a1', refreshToken: 'r1');
  @override
  Future<TokenPair?> read() async => tokens;
  @override
  Future<void> write(TokenPair tokens) async => this.tokens = tokens;
  @override
  Future<void> clear() async => tokens = null;
}

Map<String, Object?> _row(
  int id, {
  bool missing = false,
  bool nonCom = false,
  String? name,
}) => {
  'id': id,
  'domain_name': name ?? (nonCom ? 'site$id.in' : 'site$id.com'),
  'status': 'Active',
  'expires_at': '2030-01-15T00:00:00Z',
  'auto_renew': null,
  'privacy_enabled': true,
  'registrar_lock': false,
  'is_missing': missing,
  'last_synced_at': '2026-05-01T10:30:00Z',
};

/// Fake server: ids 1..[all] for filter "all" (3 pages of 20 when 45), and
/// ids 1..[expired] for "expired". Row [missingId] is flagged missing.
class _Server {
  _Server({this.all = 45, this.expired = 5, this.missingId});
  final int all;
  final int expired;
  final int? missingId;
  final requests = <http.Request>[];

  /// Rows whose extension has no verified price (the backend says so).
  final unsupportedIds = <int>{4};

  /// Quote behaviour knobs: .org rows (1,419.00), rows whose tax is missing,
  /// rows whose quote request fails, and the quote currency.
  final orgIds = <int>{};
  final noTaxIds = <int>{};
  final errorIds = <int>{};
  String currency = 'INR';
  static const longName =
      'a-very-long-subdomain-name-for-wrapping-test.example-company-holdings.com';

  http.Response handle(http.Request r) {
    requests.add(r);
    final quote = RegExp(r'domains/(\d+)/renewal-quote').firstMatch(r.url.path);
    if (quote != null) {
      final id = int.parse(quote.group(1)!);
      final unsupported = unsupportedIds.contains(id);
      final years = int.parse(r.url.queryParameters['years'] ?? '1');
      if (errorIds.contains(id)) {
        return http.Response(
          jsonEncode({'error': 'provider_error'}),
          502,
          headers: {'content-type': 'application/json'},
        );
      }
      // Fixed fixtures standing in for the backend's calculation: the app
      // under test only displays them.
      final f = orgIds.contains(id)
          ? ['1419.00', '255.42', '1674.42']
          : (years == 2
                ? ['2438.00', '438.84', '2876.84']
                : ['1219.00', '219.42', '1438.42']);
      final taxed = !unsupported && !noTaxIds.contains(id);
      return http.Response(
        jsonEncode({
          'domain': 'site$id',
          'years': years,
          'status': unsupported ? 'unsupported_tld' : 'available',
          if (!unsupported) 'currency': currency,
          if (!unsupported) 'renewal_amount': f[0],
          'tax': taxed ? f[1] : null,
          'vat': taxed ? '0.00' : null,
          'total': taxed ? f[2] : null,
          'gst_rate': taxed ? '18' : null,
          'vat_rate': taxed ? '0' : null,
          'tax_status': taxed ? 'estimated' : 'not_established',
        }),
        200,
        headers: {'content-type': 'application/json'},
      );
    }
    final q = r.url.queryParameters;
    final total = q['expiry'] == 'expired' ? expired : all;
    final perPage = int.parse(q['per_page'] ?? '20');
    final page = int.parse(q['page'] ?? '1');
    final from = (page - 1) * perPage + 1;
    final to = (from + perPage - 1).clamp(0, total);
    return http.Response(
      jsonEncode({
        'data': [
          for (var i = from; i <= to; i++)
            _row(
              i,
              missing: i == missingId,
              nonCom: unsupportedIds.contains(i),
              name: i == 5 ? longName : null,
            ),
        ],
        'meta': {
          'page': page,
          'per_page': perPage,
          'total': total,
          'total_pages': (total / perPage).ceil(),
        },
        'last_synced_at': '2026-05-01T10:30:00Z',
      }),
      200,
      headers: {'content-type': 'application/json'},
    );
  }
}

class _Harness {
  _Harness(this.server) {
    auth = AuthController(
      api: ApiClient(
        client: MockClient((r) async => server.handle(r)),
        baseUrl: 'http://test/api/v1/',
      ),
      storage: _Storage(),
    );
    auth.status = AuthStatus.signedIn;
    controller = DomainController(api: auth.api);
    bulk = BulkSyncController(api: auth.api);
  }

  final _Server server;
  late final AuthController auth;
  late final DomainController controller;
  late final BulkSyncController bulk;
  final selection = DomainSelection();

  Widget get widget => MaterialApp(
    theme: AppTheme.light,
    home: Scaffold(
      body: Builder(
        builder: (context) => DomainListScreen(
          controller: controller,
          selection: selection,
          bulk: bulk,
          onRenewSelected: () => showSelectedRenewalDetails(
            context,
            api: auth.api,
            selection: selection,
          ),
        ),
      ),
    ),
  );

  Future<void> pump(WidgetTester t) async {
    await t.pumpWidget(widget);
    controller.load();
    await t.pumpAndSettle();
  }
}

Finder _box(int id) => find.byKey(ValueKey('select-$id'));

bool? _value(WidgetTester t, Finder f) => t.widget<Checkbox>(f).value;

String _count(WidgetTester t) =>
    t.widget<Text>(find.byKey(const ValueKey('selected-count'))).data!;

const _taxNote =
    'Tax and final payable amount must be confirmed in ResellerClub.';

void main() {
  late _Harness h;

  setUp(() => h = _Harness(_Server()));
  tearDown(() {
    h.controller.dispose();
    h.selection.dispose();
  });

  testWidgets('a checkbox is drawn on every loaded card, unticked', (t) async {
    await h.pump(t);
    for (var id = 1; id <= 5; id++) {
      expect(_box(id), findsOneWidget);
      expect(_value(t, _box(id)), isFalse);
    }
    expect(_count(t), '0 selected');
  });

  testWidgets('1. selecting one domain', (t) async {
    await h.pump(t);
    await t.tap(_box(2));
    await t.pump();
    expect(h.selection.ids, {2});
    expect(_value(t, _box(2)), isTrue);
    expect(_value(t, _box(1)), isFalse);
  });

  testWidgets('2. deselecting one domain', (t) async {
    await h.pump(t);
    await t.tap(_box(2));
    await t.pump();
    await t.tap(_box(2));
    await t.pump();
    expect(h.selection.ids, isEmpty);
    expect(_value(t, _box(2)), isFalse);
  });

  testWidgets('selection persists across filter changes and back', (t) async {
    await h.pump(t);
    h.selection.set(10, true); // not in the "expired" filter
    h.selection.set(40, true);
    await t.pump();

    h.controller.setExpiry(ExpiryFilter.expired); // ids 1..5
    await t.pumpAndSettle();
    expect(h.controller.items.map((d) => d.id), [1, 2, 3, 4, 5]);
    expect(_count(t), '2 selected'); // hidden selections still counted

    await t.tap(_box(2));
    await t.pump();
    expect(h.selection.ids, {2, 10, 40});

    await t.tap(_box(2));
    await t.pump();
    expect(h.selection.ids, {10, 40}); // deselect touches only that row

    h.controller.setExpiry(ExpiryFilter.all);
    await t.pumpAndSettle();
    expect(h.selection.ids, {10, 40});
  });

  testWidgets('selection persists across search and sort', (t) async {
    await h.pump(t);
    await t.tap(_box(3));
    await t.pump();
    h.controller.setQuery('site1');
    await t.pumpAndSettle();
    expect(h.selection.ids, {3});
    h.controller.setQuery('');
    await t.pumpAndSettle();
    await h.controller.setSort(DomainSort.expiryDesc);
    await t.pumpAndSettle();
    expect(h.selection.ids, {3});
    expect(_value(t, _box(3)), isTrue);
  });

  testWidgets('7. the count is exact after every change', (t) async {
    await h.pump(t);
    expect(_count(t), '0 selected');
    await t.tap(_box(1));
    await t.pump();
    expect(_count(t), '1 selected');
    await t.tap(_box(2));
    await t.pump();
    expect(_count(t), '2 selected');
    await t.tap(_box(1));
    await t.pump();
    expect(_count(t), '1 selected');
  });

  test('8. a domain cannot be selected twice', () {
    final s = DomainSelection();
    s.set(1, true);
    s.set(1, true);
    s.set(2, true);
    expect(s.count, 2);
    s.toggle(1);
    expect(s.ids, {2});
    s.dispose();
  });

  testWidgets('selection is stored by id: rows survive a refresh', (t) async {
    await h.pump(t);
    await t.tap(_box(3));
    await t.pump();
    await h.controller.refresh();
    await t.pumpAndSettle();
    expect(h.selection.ids, {3});
    expect(_value(t, _box(3)), isTrue);
  });

  testWidgets('stale ids are pruned only against a complete unfiltered list', (
    t,
  ) async {
    final small = _Harness(_Server(all: 8, expired: 3));
    addTearDown(() {
      small.controller.dispose();
      small.selection.dispose();
    });
    small.selection.set(2, true);
    small.selection.set(999, true);
    await small.pump(t);
    expect(small.selection.ids, {2}); // 999 no longer exists

    // Under a filter nothing is pruned (hidden rows prove nothing).
    small.selection.set(7, true);
    small.selection.set(998, true);
    small.controller.setExpiry(ExpiryFilter.expired);
    await t.pumpAndSettle();
    expect(small.selection.ids, {2, 7, 998});
  });

  testWidgets('no pruning while more pages are unloaded', (t) async {
    h.selection.set(999, true);
    await h.pump(t);
    expect(h.selection.ids, {999});
  });

  testWidgets('a domain missing at the provider cannot be ticked', (t) async {
    final m = _Harness(_Server(all: 5, missingId: 3));
    addTearDown(() {
      m.controller.dispose();
      m.selection.dispose();
    });
    await m.pump(t);
    expect(t.widget<Checkbox>(_box(3)).onChanged, isNull);
    expect(t.widget<Checkbox>(_box(2)).onChanged, isNotNull);
  });

  testWidgets('there is no Select All control', (t) async {
    await h.pump(t);
    expect(find.byKey(const ValueKey('select-all')), findsNothing);
    expect(find.textContaining('Select all'), findsNothing);
    expect(find.byType(Checkbox), findsWidgets);
  });

  testWidgets('without a selection controller there are no checkboxes', (
    t,
  ) async {
    final auth = AuthController(
      api: ApiClient(
        client: MockClient((r) async => _Server().handle(r)),
        baseUrl: 'http://test/api/v1/',
      ),
      storage: _Storage(),
    );
    auth.status = AuthStatus.signedIn;
    final c = DomainController(api: auth.api);
    addTearDown(c.dispose);
    await t.pumpWidget(
      MaterialApp(
        theme: AppTheme.light,
        home: Scaffold(body: DomainListScreen(controller: c)),
      ),
    );
    c.load();
    await t.pumpAndSettle();
    expect(find.byType(Checkbox), findsNothing);
    expect(find.byKey(const ValueKey('selected-count')), findsNothing);
  });

  testWidgets('filter row stays horizontally scrollable on a 360 dp phone', (
    t,
  ) async {
    t.view.physicalSize = const Size(360, 640);
    t.view.devicePixelRatio = 1;
    addTearDown(t.view.reset);
    await h.pump(t);
    expect(t.takeException(), isNull); // no layout exception thrown
    expect(find.byType(SingleChildScrollView), findsWidgets);
    expect(find.byKey(const ValueKey('selected-count')), findsOneWidget);
  });

  // ---- Step 2: selected-domain renewal UI --------------------------------

  Finder renewButton() => find.byKey(const ValueKey('renew-selected'));

  testWidgets('no per-card renew icon remains; the Sync button is gone', (t) async {
    await h.pump(t);
    expect(find.byIcon(Icons.autorenew), findsNothing);
    expect(
      find.byWidgetPredicate(
        (w) => w is IconButton && (w.tooltip ?? '').startsWith('Renew'),
      ),
      findsNothing,
    );
    expect(find.text('Sync'), findsNothing);
    expect(find.byKey(const ValueKey('more-actions')), findsOneWidget);
  });

  testWidgets('Renew sits beside the count; disabled at 0, enabled at 1+', (
    t,
  ) async {
    await h.pump(t);
    FilledButton button() => t.widget<FilledButton>(renewButton());
    expect(button().onPressed, isNull);
    final row = t.getCenter(find.byKey(const ValueKey('selected-count'))).dy;
    expect(t.getCenter(renewButton()).dy, closeTo(row, 4)); // same row
    await t.tap(_box(1));
    await t.pump();
    expect(button().onPressed, isNotNull);
    expect(_count(t), '1 selected');
    await t.tap(_box(1));
    await t.pump();
    expect(button().onPressed, isNull);
  });

  testWidgets('a long domain name wraps instead of truncating', (t) async {
    t.view.physicalSize = const Size(320, 1400);
    t.view.devicePixelRatio = 1;
    addTearDown(t.view.reset);
    await h.pump(t);
    final text = find.text(_Server.longName);
    expect(text, findsOneWidget);
    expect(t.widget<Text>(text).maxLines, isNull);
    expect(t.widget<Text>(text).overflow, isNot(TextOverflow.ellipsis));
    final short = t.getSize(find.text('site2.com')).height;
    expect(t.getSize(text).height, greaterThan(short * 1.5)); // 2+ lines
    expect(t.takeException(), isNull);
  });

  Future<void> openDetails(WidgetTester t, List<int> ids) async {
    await h.pump(t);
    for (final id in ids) {
      await t.tap(_box(id));
      await t.pump();
    }
    await t.tap(renewButton());
    await t.pumpAndSettle();
  }

  testWidgets('Renew lists every selected domain; .com uses the quote', (
    t,
  ) async {
    t.view.physicalSize = const Size(800, 1600);
    t.view.devicePixelRatio = 1;
    addTearDown(t.view.reset);
    await openDetails(t, [1, 2, 4]);
    expect(find.text('Renewal details (3 domains)'), findsOneWidget);
    for (final n in ['site1.com', 'site2.com', 'site4.in']) {
      expect(find.text(n), findsWidgets);
    }
    // Priced group: the two .com rows, from the existing quote endpoint.
    expect(find.text('Price available (2)'), findsOneWidget);
    expect(find.text('₹1,219.00'), findsNWidgets(2));
    expect(find.text('1 year'), findsNWidgets(3));
    // The unsupported .in row is shown, flagged, and has no invented price.
    expect(find.text('No price available (1)'), findsOneWidget);
    expect(find.textContaining('Price unsupported'), findsOneWidget);
    expect(find.textContaining('INR'), findsNothing);
    // Each priced row shows only the renewal cost; the fixtures still carry
    // tax, which the app must ignore. The note says why.
    expect(find.text('Renewal cost'), findsNWidgets(2));
    expect(find.text('Combined renewal cost'), findsOneWidget);
    expect(find.text('₹2,438.00'), findsOneWidget); // 1219 + 1219
    expect(find.text(_taxNote), findsOneWidget);
    expect(find.textContaining('GST'), findsNothing);
    expect(find.textContaining('VAT'), findsNothing);
    expect(find.text('₹219.42'), findsNothing);
    expect(find.text('₹1,438.42'), findsNothing);
    // Details are read-only: nothing to confirm or pay.
    // The only button is the batch button, disabled until an invoice option is chosen.
    expect(find.byType(FilledButton), findsOneWidget);
    expect(find.byKey(const ValueKey('submit-renewal')), findsOneWidget);
    expect(t.widget<FilledButton>(find.byType(FilledButton)).onPressed, isNull);
    expect(find.text('Create renewal batch'), findsOneWidget);
    for (final word in ['Pay', 'Confirm', 'Renew now', 'Invoice now']) {
      expect(find.text(word), findsNothing);
    }
  });

  testWidgets('only unsupported selected: nothing priced, none omitted', (
    t,
  ) async {
    await openDetails(t, [4]);
    expect(find.text('Price available (1)'), findsNothing);
    expect(find.text('No price available (1)'), findsOneWidget);
    expect(find.text('site4.in'), findsWidgets);
    expect(find.textContaining('₹'), findsNothing);
  });

  // ---- renewal cost display: tax and total are never shown or calculated ----

  Future<void> openScreen(
    WidgetTester t,
    List<int> ids, {
    int years = 1,
  }) async {
    t.view.physicalSize = const Size(800, 1600);
    t.view.devicePixelRatio = 1;
    addTearDown(t.view.reset);
    await t.pumpWidget(
      MaterialApp(
        theme: AppTheme.light,
        home: SelectedRenewalScreen(
          controller: SelectedRenewalController(
            api: h.auth.api,
            selected: [for (final id in ids) (id: id, name: 'site$id.com')],
            years: years,
          ),
        ),
      ),
    );
    await t.pumpAndSettle();
  }

  testWidgets(
    'shows the renewal cost and the tax note, no tax or total (.org)',
    (t) async {
      h.server.orgIds.add(1);
      await openScreen(t, [1]);
      expect(find.text('site1.com'), findsOneWidget);
      expect(find.text('Renewal period'), findsOneWidget);
      expect(find.text('1 year'), findsOneWidget);
      expect(find.text('Renewal cost'), findsOneWidget);
      expect(find.text('₹1,419.00'), findsOneWidget);
      // The explanation is visible, exactly worded.
      expect(find.byKey(const ValueKey('tax-note')), findsOneWidget);
      expect(find.text(_taxNote), findsOneWidget);
      // The fixture carries GST 255.42 / total 1674.42; none of it is shown.
      for (final hidden in ['GST', 'VAT', 'Estimated total', 'Renewal price']) {
        expect(find.textContaining(hidden), findsNothing);
      }
      for (final money in ['₹255.42', '₹0.00', '₹1,674.42']) {
        expect(find.text(money), findsNothing);
      }
      // One priced domain: no separate combined card.
      expect(find.byKey(const ValueKey('renewal-summary')), findsNothing);
      // Amounts are right-aligned.
      expect(t.widget<Text>(find.text('₹1,419.00')).textAlign, TextAlign.end);
    },
  );

  testWidgets('multiple domains: own cost per row and summed renewal cost', (
    t,
  ) async {
    h.server.orgIds.add(2);
    await openScreen(t, [1, 2, 3]);
    expect(find.text('₹1,419.00'), findsOneWidget);
    expect(find.text('₹1,219.00'), findsNWidgets(2));
    // Combined = sum of the three individual costs (1219 + 1419 + 1219).
    final summary = find.byKey(const ValueKey('renewal-summary'));
    expect(summary, findsOneWidget);
    Finder inSummary(String text) =>
        find.descendant(of: summary, matching: find.text(text));
    expect(inSummary('Combined renewal cost'), findsOneWidget);
    expect(inSummary('₹3,857.00'), findsOneWidget);
    expect(find.text('All priced domains (3)'), findsOneWidget);
    expect(find.text(_taxNote), findsOneWidget); // shown once
    expect(find.textContaining('GST'), findsNothing);
    expect(find.text('₹694.26'), findsNothing);
    expect(find.text('₹4,551.26'), findsNothing);
  });

  testWidgets('selected renewal period is requested and displayed', (t) async {
    await openScreen(t, [1], years: 2);
    final q = h.server.requests.where(
      (r) => r.url.path.endsWith('renewal-quote'),
    );
    expect(q.single.url.queryParameters['years'], '2');
    expect(find.text('2 years'), findsOneWidget);
    expect(find.text('₹2,438.00'), findsOneWidget);
    expect(find.text('₹438.84'), findsNothing);
    expect(find.text('₹2,876.84'), findsNothing);
  });

  testWidgets('a quote without tax still shows its cost and the note', (
    t,
  ) async {
    h.server.noTaxIds.add(1);
    await openScreen(t, [1]);
    expect(find.text('₹1,219.00'), findsOneWidget);
    expect(find.text('Renewal cost'), findsOneWidget);
    expect(find.text(_taxNote), findsOneWidget);
    expect(find.text('Not available'), findsNothing);
    expect(find.text('₹0.00'), findsNothing);
  });

  testWidgets('one domain without tax no longer suppresses the combined cost', (
    t,
  ) async {
    h.server.noTaxIds.add(2);
    await openScreen(t, [1, 2]);
    final summary = find.byKey(const ValueKey('renewal-summary'));
    expect(
      find.descendant(of: summary, matching: find.text('₹2,438.00')),
      findsOneWidget,
    );
    expect(find.text('Not available'), findsNothing);
  });

  testWidgets('API error shows a message and Retry, no figures, no note', (
    t,
  ) async {
    h.server.errorIds.add(1);
    await openScreen(t, [1]);
    expect(find.textContaining('price source is unavailable'), findsOneWidget);
    expect(find.text('Retry'), findsOneWidget);
    expect(find.textContaining('₹'), findsNothing);
    expect(find.text('Renewal cost'), findsNothing);
    expect(find.text(_taxNote), findsNothing);
  });

  testWidgets('Retry reloads the quote and then shows the cost', (t) async {
    h.server.errorIds.add(1);
    await openScreen(t, [1]);
    expect(find.text('Retry'), findsOneWidget);
    h.server.errorIds.clear();
    await t.tap(find.text('Retry'));
    await t.pumpAndSettle();
    expect(find.text('Retry'), findsNothing);
    expect(find.text('₹1,219.00'), findsOneWidget);
    expect(find.text(_taxNote), findsOneWidget);
  });

  Future<void> openSheet(WidgetTester t, int id) async {
    await t.pumpWidget(
      MaterialApp(
        theme: AppTheme.light,
        home: Scaffold(
          body: RenewalQuoteSheet(
            controller: RenewalQuoteController(api: h.auth.api, domainId: id),
            domainName: 'site$id.com',
          ),
        ),
      ),
    );
    await t.pumpAndSettle();
  }

  testWidgets('quote sheet shows the cost and the confirmation note only', (
    t,
  ) async {
    await openSheet(t, 1);
    expect(find.text('Renewal cost'), findsOneWidget);
    expect(find.text('₹1,219.00'), findsOneWidget);
    expect(find.text(_taxNote), findsOneWidget);
    expect(find.byKey(const ValueKey('tax-note')), findsOneWidget);
    // No misleading "Not available" tax rows, and no tax or total at all.
    expect(find.text('Not available'), findsNothing);
    for (final hidden in ['GST', 'VAT', 'Estimated total', 'Renewal price']) {
      expect(find.textContaining(hidden), findsNothing);
    }
    expect(find.text('₹1,438.42'), findsNothing);
    expect(find.byType(FilledButton), findsNothing);
  });

  testWidgets('quote sheet: unsupported domain has no cost and no note', (
    t,
  ) async {
    await openSheet(t, 4);
    expect(find.textContaining('No price is available'), findsOneWidget);
    expect(find.text(_taxNote), findsNothing);
    expect(find.textContaining('₹'), findsNothing);
  });

  testWidgets('quote sheet: error shows Retry, which reloads the quote', (
    t,
  ) async {
    h.server.errorIds.add(1);
    await openSheet(t, 1);
    expect(find.textContaining('price source is unavailable'), findsOneWidget);
    expect(find.text(_taxNote), findsNothing);
    h.server.errorIds.clear();
    await t.tap(find.text('Retry'));
    await t.pumpAndSettle();
    expect(find.text('₹1,219.00'), findsOneWidget);
    expect(find.text(_taxNote), findsOneWidget);
  });

  testWidgets('loading state shows a spinner and no figures', (t) async {
    final gate = Completer<http.Response>();
    final auth = AuthController(
      api: ApiClient(
        client: MockClient((r) => gate.future),
        baseUrl: 'http://test/api/v1/',
      ),
      storage: _Storage(),
    )..status = AuthStatus.signedIn;
    await t.pumpWidget(
      MaterialApp(
        theme: AppTheme.light,
        home: SelectedRenewalScreen(
          controller: SelectedRenewalController(
            api: auth.api,
            selected: [(id: 1, name: 'site1.com')],
          ),
        ),
      ),
    );
    await t.pump();
    expect(find.text('Loading price…'), findsOneWidget);
    expect(find.byType(CircularProgressIndicator), findsOneWidget);
    expect(find.textContaining('₹'), findsNothing);
    gate.complete(
      h.server.handle(
        http.Request(
          'GET',
          Uri.parse('http://test/api/v1/domains/1/renewal-quote'),
        ),
      ),
    );
    await t.pumpAndSettle();
    expect(find.text('₹1,219.00'), findsOneWidget);
    expect(find.text('₹1,438.42'), findsNothing);
  });

  testWidgets('non-INR currency shows its code, never a guessed symbol', (
    t,
  ) async {
    h.server.currency = 'USD';
    await openScreen(t, [1]);
    expect(find.text('USD 1,219.00'), findsOneWidget);
    expect(find.text('USD 1,438.42'), findsNothing);
    expect(find.textContaining('₹'), findsNothing);
  });

  testWidgets('mixed list: all domains on one screen, unsupported named and '
      'excluded from the combined total', (t) async {
    t.view.physicalSize = const Size(800, 1600);
    t.view.devicePixelRatio = 1;
    addTearDown(t.view.reset);
    h.server.orgIds.add(2);
    await openScreen(t, [1, 2, 4]);
    expect(find.text('site1.com'), findsOneWidget);
    expect(find.text('site2.com'), findsOneWidget);
    expect(find.text('site4.com'), findsOneWidget);
    expect(find.textContaining('no verified price'), findsOneWidget);
    final summary = find.byKey(const ValueKey('renewal-summary'));
    expect(summary, findsOneWidget);
    expect(
      find.descendant(
        of: summary,
        matching: find.text('Not included (no price): site4.com'),
      ),
      findsOneWidget,
    );
    // Only the two priced costs: 1219 + 1419 = 2638. No tax is added.
    expect(
      find.descendant(of: summary, matching: find.text('₹2,638.00')),
      findsOneWidget,
    );
  });

  test('RenewalTotals sums only the cost, in one currency', () {
    RenewalQuote q(String c, {String amount = '0.25'}) =>
        RenewalQuote.fromJson({
          'domain': 'a',
          'years': 1,
          'status': 'available',
          'currency': c,
          'renewal_amount': amount,
          // Present in the response, ignored by the model.
          'tax': '0.05',
          'vat': '0.00',
          'total': '0.30',
          'gst_rate': '18',
          'vat_rate': '0',
          'tax_status': 'estimated',
        });
    final sum = RenewalTotals.of([q('INR'), q('INR'), q('INR')])!;
    expect([sum.currency, sum.amount, sum.count], ['INR', '0.75', 3]);
    expect(RenewalTotals.of([q('INR'), q('USD')]), isNull);
    expect(RenewalTotals.of([q('INR', amount: '1,00')]), isNull);
    expect(RenewalTotals.of([]), isNull);
  });

  test(
    'tax and total stay null in the quote model, whatever the API sends',
    () {
      final quote = RenewalQuote.fromJson({
        'domain': 'a',
        'years': 1,
        'status': 'available',
        'currency': 'INR',
        'renewal_amount': '1419.00',
        'tax_status': 'estimated',
        'gst_rate': '18',
        'vat_rate': '0',
        'tax': '255.42',
        'vat': '0.00',
        'total': '1674.42',
      });
      expect(quote.amount, '1419.00');
      expect(quote.formattedAmount, '₹1,419.00');
      expect([
        quote.gst,
        quote.vat,
        quote.total,
        quote.gstRate,
        quote.vatRate,
        quote.taxStatus,
      ], everyElement(isNull));
      expect(quote.taxEstimated, isFalse);
    },
  );

  testWidgets('details only ever GET quotes: no renew, payment or POST', (
    t,
  ) async {
    await openDetails(t, [1, 4]);
    final after = h.server.requests;
    expect(after.where((r) => r.method != 'GET').toList(), isEmpty);
    expect(
      after.where((r) => r.url.path.contains('renew.json')).toList(),
      isEmpty,
    );
    final quotes = after.where((r) => r.url.path.endsWith('renewal-quote'));
    expect(quotes.map((r) => r.url.path).toSet(), {
      '/api/v1/domains/1/renewal-quote',
      '/api/v1/domains/4/renewal-quote',
    });
    expect(
      after.where(
        (r) => RegExp('pay|invoice|balance|fund').hasMatch(r.url.path),
      ),
      isEmpty,
    );
  });

  for (final width in [320.0, 360.0, 800.0]) {
    testWidgets('no overflow at ${width.toInt()} dp (list and details)', (
      t,
    ) async {
      t.view.physicalSize = Size(width, 1400);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.reset);
      h.selection.set(5, true, name: _Server.longName);
      await openDetails(t, [1, 4]);
      expect(find.text('Renewal details (3 domains)'), findsOneWidget);
      expect(t.takeException(), isNull);
      await t.pageBack();
      await t.pumpAndSettle();
      expect(t.takeException(), isNull);
    });
  }
}

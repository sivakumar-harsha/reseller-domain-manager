import 'dart:convert';

import 'package:domain_manager/auth/auth_controller.dart';
import 'package:domain_manager/auth/token_storage.dart';
import 'package:domain_manager/core/api_client.dart';
import 'package:domain_manager/domains/expiry.dart';
import 'package:domain_manager/screens/home_screen.dart';
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

http.Response _json(int status, Object body) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

Map<String, Object?> _page(List<String> names) => {
  'data': [
    for (var i = 0; i < names.length; i++)
      {
        'id': i + 1,
        'domain_name': names[i],
        'status': 'Active',
        'expires_at': '2030-01-15T00:00:00Z',
        'auto_renew': null,
        'privacy_enabled': true,
        'registrar_lock': false,
        'is_missing': false,
        'last_synced_at': '2026-05-01T10:30:00Z',
      },
  ],
  'meta': {'page': 1, 'per_page': 20, 'total': names.length, 'total_pages': 1},
  'last_synced_at': '2026-05-01T10:30:00Z',
};

const _summary = {
  'expired': 11,
  'within_7_days': 22,
  'within_30_days': 33,
  'within_60_days': 44,
  'within_90_days': 55,
  'unknown': 66,
  'as_of_date': '2026-10-30',
  'timezone': 'Asia/Kolkata',
};

void main() {
  late List<Uri> listCalls;
  late int summaryCalls;
  late int balanceCalls;
  late bool summaryFails;

  Future<void> open(WidgetTester tester) async {
    listCalls = [];
    summaryCalls = 0;
    balanceCalls = 0;
    final client = MockClient((req) async {
      if (req.url.path.endsWith('expiry/summary')) {
        summaryCalls++;
        return summaryFails
            ? _json(500, {'error': 'server_error'})
            : _json(200, _summary);
      }
      if (req.url.path.endsWith('/balance')) {
        // Opening Domains refreshes the balance; it is not a list request.
        balanceCalls++;
        return _json(500, {'error': 'server_error'});
      }
      listCalls.add(req.url);
      return _json(200, _page(['alpha.com']));
    });
    final auth = AuthController(
      api: ApiClient(client: client, baseUrl: 'http://test/api/v1/'),
      storage: _Storage(),
    );
    auth.status = AuthStatus.signedIn;
    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.light,
        home: HomeScreen(auth: auth),
      ),
    );
    await tester.pumpAndSettle();
  }

  Future<void> openDomains(WidgetTester tester) async {
    await tester.tap(find.byTooltip('Open navigation menu'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(ListTile, 'Domains'));
    await tester.pumpAndSettle();
  }

  Future<void> openDashboard(WidgetTester tester) async {
    await tester.tap(find.byTooltip('Open navigation menu'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(ListTile, 'Dashboard'));
    await tester.pumpAndSettle();
  }

  Finder tile(ExpiryFilter f) =>
      find.byKey(ValueKey('expiry-tile-${f.apiValue}'));
  Finder chip(ExpiryFilter f) =>
      find.byKey(ValueKey('expiry-chip-${f.apiValue}'));

  setUp(() => summaryFails = false);

  testWidgets('shows the six backend counts and loads the summary once', (
    tester,
  ) async {
    await open(tester);
    for (final (f, n) in [
      (ExpiryFilter.expired, '11'),
      (ExpiryFilter.within7, '22'),
      (ExpiryFilter.within30, '33'),
      (ExpiryFilter.within60, '44'),
      (ExpiryFilter.within90, '55'),
      (ExpiryFilter.unknown, '66'),
    ]) {
      expect(
        find.descendant(of: tile(f), matching: find.text(n)),
        findsOneWidget,
      );
    }
    expect(summaryCalls, 1);
    expect(listCalls.length, 1);
  });

  testWidgets(
    'tapping a tile applies the filter, selects the chip, keeps sort',
    (tester) async {
      await open(tester);
      await openDomains(tester);
      await tester.tap(find.byKey(const ValueKey('sort-menu')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Domain name'));
      await tester.pumpAndSettle();
      await openDashboard(tester);

      await tester.tap(tile(ExpiryFilter.within30));
      await tester.pumpAndSettle();
      expect(tile(ExpiryFilter.within30), findsNothing); // now on Domains

      expect(listCalls.last.queryParameters['expiry'], 'within_30_days');
      expect(listCalls.last.queryParameters['sort'], 'name_asc');
      expect(listCalls.last.queryParameters['page'], '1');
      expect(
        tester.widget<ChoiceChip>(chip(ExpiryFilter.within30)).selected,
        isTrue,
      );
      expect(
        tester.widget<ChoiceChip>(chip(ExpiryFilter.all)).selected,
        isFalse,
      );
      expect(summaryCalls, 1); // filtering never reloads the summary
    },
  );

  testWidgets('a chip filters, keeps the search, and All returns', (
    tester,
  ) async {
    await open(tester);
    await openDomains(tester);
    // The search field is behind the "Search domains" toggle.
    await tester.tap(find.byTooltip('Search domains'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'alp');
    await tester.pump(const Duration(milliseconds: 500));
    await tester.pumpAndSettle();

    await tester.tap(chip(ExpiryFilter.expired));
    await tester.pumpAndSettle();
    expect(listCalls.last.queryParameters['q'], 'alp');
    expect(listCalls.last.queryParameters['expiry'], 'expired');

    await tester.tap(chip(ExpiryFilter.all));
    await tester.pumpAndSettle();
    expect(listCalls.last.queryParameters.containsKey('expiry'), isFalse);
    expect(listCalls.last.queryParameters['q'], 'alp');
  });

  testWidgets('sort options send the matching value and keep the filter', (
    tester,
  ) async {
    await open(tester);
    await openDomains(tester);
    // The last chip sits beyond the edge of the sideways-scrolling row.
    await tester.ensureVisible(chip(ExpiryFilter.unknown));
    await tester.pumpAndSettle();
    await tester.tap(chip(ExpiryFilter.unknown));
    await tester.pumpAndSettle();

    for (final (label, value) in [
      ('Farthest expiry', 'expiry_desc'),
      ('Domain name', 'name_asc'),
      ('Nearest expiry', 'expiry_asc'),
    ]) {
      await tester.tap(find.byKey(const ValueKey('sort-menu')));
      await tester.pumpAndSettle();
      await tester.tap(find.text(label));
      await tester.pumpAndSettle();
      expect(listCalls.last.queryParameters['sort'], value);
      expect(listCalls.last.queryParameters['expiry'], 'unknown');
    }
  });

  testWidgets('choosing Nearest expiry on the default order does not reload', (
    tester,
  ) async {
    await open(tester);
    await openDomains(tester);
    final before = listCalls.length;
    await tester.tap(find.byKey(const ValueKey('sort-menu')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Nearest expiry'));
    await tester.pumpAndSettle();
    expect(listCalls.length, before);
  });

  testWidgets('a failed summary shows Retry summary and a retry recovers', (
    tester,
  ) async {
    summaryFails = true;
    await open(tester);
    expect(find.text('Could not load the expiry counts.'), findsOneWidget);
    expect(listCalls.length, 1); // list unaffected

    summaryFails = false;
    await tester.tap(find.text('Retry summary'));
    await tester.pumpAndSettle();
    expect(find.text('Retry summary'), findsNothing);
    expect(
      find.descendant(
        of: tile(ExpiryFilter.expired),
        matching: find.text('11'),
      ),
      findsOneWidget,
    );
    expect(summaryCalls, 2);
  });

  testWidgets('switching screens does not reload and Domains has no tiles', (
    tester,
  ) async {
    await open(tester);
    await openDomains(tester);
    expect(tile(ExpiryFilter.expired), findsNothing);
    expect(find.text('Expiry overview'), findsNothing);
    expect(find.text('alpha.com'), findsOneWidget);
    await openDashboard(tester);
    await openDomains(tester);
    expect((listCalls.length, summaryCalls), (1, 1));
    // The balance loads once at launch and again whenever Domains is opened
    // from elsewhere (home_screen.dart `_show`); here it always fails, and a
    // failed balance is retried rather than treated as fresh: 1 + 2.
    expect(balanceCalls, 3);
  });

  testWidgets('back from Domains returns to the Dashboard', (tester) async {
    await open(tester);
    await openDomains(tester);
    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(tile(ExpiryFilter.expired), findsOneWidget);
  });
}

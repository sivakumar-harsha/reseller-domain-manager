import 'dart:async';
import 'dart:convert';

import 'package:domain_manager/app.dart';
import 'package:domain_manager/auth/auth_controller.dart';
import 'package:domain_manager/auth/token_storage.dart';
import 'package:domain_manager/core/api_client.dart';
import 'package:domain_manager/domains/domain.dart';
import 'package:domain_manager/screens/domain_detail_screen.dart';
import 'package:domain_manager/theme/app_theme.dart';
import 'package:domain_manager/widgets/status_badge.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

class MemoryStorage implements TokenStorage {
  TokenPair? tokens;
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

Map<String, Object?> _row(int id, String name) => {
  'id': id,
  'domain_name': name,
  'status': 'Active',
  'expires_at': '2030-01-15T00:00:00Z',
  'auto_renew': true,
  'privacy_enabled': true,
  'registrar_lock': false,
  'is_missing': false,
  'last_synced_at': '2026-05-01T10:30:00Z',
};

Map<String, Object?> _page(List<Map<String, Object?>> rows) => {
  'data': rows,
  'meta': {'page': 1, 'per_page': 20, 'total': rows.length, 'total_pages': 1},
  'last_synced_at': '2026-05-01T10:30:00Z',
};

Map<String, Object?> _detail({Map<String, Object?>? extra}) => {
  ..._row(1, 'alpha.com'),
  'nameservers': ['ns1.example.net', 'ns2.example.net'],
  'rc_order_id': '1001',
  'rc_customer_id': '2002',
  'registered_at': '2020-03-04T05:06:07Z',
  'first_seen_at': '2026-04-01T08:00:00Z',
  ...?extra,
};

/// Nullable detail response: everything the backend may legitimately not know.
Map<String, Object?> _bareDetail() => {
  'id': 1,
  'domain_name': 'alpha.com',
  'status': null,
  'expires_at': null,
  'auto_renew': null,
  'privacy_enabled': null,
  'registrar_lock': null,
  'is_missing': false,
  'last_synced_at': null,
  'nameservers': null,
  'rc_order_id': '1001',
  'rc_customer_id': null,
  'registered_at': null,
  'first_seen_at': null,
};

ApiClient _api(MockClient client) =>
    ApiClient(client: client, baseUrl: 'http://test/api/v1/');

AuthController _auth(MockClient client, MemoryStorage storage) {
  storage.tokens = const TokenPair(accessToken: 'a1', refreshToken: 'r1');
  final auth = AuthController(api: _api(client), storage: storage);
  auth.status = AuthStatus.signedIn;
  return auth;
}

void main() {
  group('DomainDetail parsing', () {
    test('a full response keeps every field', () {
      final d = DomainDetail.fromJson(_detail());
      expect(d.domain.id, 1);
      expect(d.domain.domainName, 'alpha.com');
      expect(d.domain.autoRenew, true);
      expect(d.nameservers, ['ns1.example.net', 'ns2.example.net']);
      expect(d.rcOrderId, '1001');
      expect(d.rcCustomerId, '2002');
      expect(d.registeredAt, DateTime.utc(2020, 3, 4, 5, 6, 7));
      expect(d.firstSeenAt, DateTime.utc(2026, 4, 1, 8));
    });

    test('nullable fields stay null and are never invented', () {
      final d = DomainDetail.fromJson(_bareDetail());
      expect(d.nameservers, isNull);
      expect(d.rcCustomerId, isNull);
      expect(d.registeredAt, isNull);
      expect(d.firstSeenAt, isNull);
      expect(d.domain.autoRenew, isNull);
      expect(d.domain.privacyEnabled, isNull);
      expect(d.domain.registrarLock, isNull);
      expect(d.domain.status, isNull);
    });

    test('a missing or invalid id or name is rejected', () {
      for (final bad in [
        {..._detail()}..remove('id'),
        {..._detail()}..remove('domain_name'),
        _detail(extra: {'id': 'x'}),
        _detail(extra: {'id': 1.5}),
        _detail(extra: {'domain_name': ''}),
        _detail(extra: {'domain_name': 7}),
      ]) {
        expect(() => DomainDetail.fromJson(bad), throwsFormatException);
      }
    });

    test('non-string nameservers are dropped, a non-list becomes null', () {
      expect(
        DomainDetail.fromJson(
          _detail(
            extra: {
              'nameservers': ['ns1.example.net', 3, null],
            },
          ),
        ).nameservers,
        ['ns1.example.net'],
      );
      expect(
        DomainDetail.fromJson(_detail(extra: {'nameservers': 'x'})).nameservers,
        isNull,
      );
    });
  });

  group('getDomain request', () {
    test('sends GET domains/{id}/details with the bearer token', () async {
      late http.Request seen;
      final auth = _auth(
        MockClient((req) async {
          seen = req;
          return _json(200, _detail());
        }),
        MemoryStorage(),
      );

      final d = await auth.api.getDomain(7);

      expect(seen.method, 'GET');
      expect(seen.url.path, '/api/v1/domains/7/details');
      expect(seen.headers['Authorization'], 'Bearer a1');
      expect(d.domain.domainName, 'alpha.com');
    });

    test('a 401 refreshes once, stores the new pair and retries', () async {
      final storage = MemoryStorage();
      final tokens = <String?>[];
      final auth = _auth(
        MockClient((req) async {
          if (req.url.path.endsWith('auth/refresh')) {
            return _json(200, {'access_token': 'a2', 'refresh_token': 'r2'});
          }
          tokens.add(req.headers['Authorization']);
          return tokens.length == 1
              ? _json(401, {'error': 'unauthorized'})
              : _json(200, _detail());
        }),
        storage,
      );

      final d = await auth.api.getDomain(1);

      expect(tokens, ['Bearer a1', 'Bearer a2']);
      expect(storage.tokens?.refreshToken, 'r2');
      expect(d.domain.id, 1);
    });

    test('a 404 is passed through as an ApiException', () async {
      final auth = _auth(
        MockClient((_) async => _json(404, {'error': 'not_found'})),
        MemoryStorage(),
      );
      await expectLater(
        auth.api.getDomain(1),
        throwsA(
          isA<ApiException>()
              .having((e) => e.statusCode, 'status', 404)
              .having((e) => e.code, 'code', 'not_found'),
        ),
      );
    });

    test('a malformed body is a bad_response', () async {
      for (final body in [
        <String, Object?>{},
        {'id': 1},
        {'data': []},
      ]) {
        final auth = _auth(
          MockClient((_) async => _json(200, body)),
          MemoryStorage(),
        );
        await expectLater(
          auth.api.getDomain(1),
          throwsA(
            isA<ApiException>().having((e) => e.code, 'code', 'bad_response'),
          ),
        );
      }
    });

    test('without a session nothing is sent', () async {
      var calls = 0;
      final api = _api(
        MockClient((_) async {
          calls++;
          return _json(200, _detail());
        }),
      );
      await expectLater(
        api.getDomain(1),
        throwsA(isA<ApiException>().having((e) => e.statusCode, 'status', 401)),
      );
      expect(calls, 0);
    });
  });

  group('Domain details screen', () {
    /// Serves the list, and [onDetail] for `GET domains/{id}`.
    MockClient server(Future<http.Response> Function(http.Request) onDetail) {
      return MockClient((req) async {
        final path = req.url.path;
        if (path.endsWith('auth/refresh')) {
          return _json(401, {'error': 'unauthorized'});
        }
        // HomeScreen also loads the expiry summary and balance; they are not
        // part of what these tests exercise, so keep them out of [onDetail].
        if (path.endsWith('expiry/summary') || path.endsWith('/balance')) {
          return _json(500, {'error': 'server_error'});
        }
        if (path == '/api/v1/domains') {
          return _json(200, _page([_row(1, 'alpha.com')]));
        }
        return onDetail(req);
      });
    }

    Future<(AuthController, MemoryStorage)> open(
      WidgetTester tester,
      MockClient client, {
      Size size = const Size(800, 2400),
    }) async {
      // Tall enough that the lazy ListView builds every field.
      tester.view.physicalSize = size;
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final storage = MemoryStorage();
      final auth = _auth(client, storage);
      await tester.pumpWidget(App(auth: auth));
      await tester.pumpAndSettle();
      // The app opens on the Dashboard; the list lives on the Domains screen.
      await tester.tap(find.byTooltip('Open navigation menu'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(ListTile, 'Domains'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('alpha.com'));
      await tester.pump(); // route pushed
      return (auth, storage);
    }

    testWidgets('tapping a row shows a spinner, then every labelled field', (
      tester,
    ) async {
      final seen = <String>[];
      final gate = Completer<void>();
      await open(
        tester,
        server((req) async {
          seen.add(req.url.path);
          await gate.future;
          return _json(200, _detail());
        }),
      );
      await tester.pump(const Duration(milliseconds: 400)); // route is open
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
      expect(find.text('Order ID'), findsNothing);
      gate.complete();
      await tester.pumpAndSettle();

      expect(seen, ['/api/v1/domains/1/details']);
      // Summary header: name, status and expiry.
      expect(find.byKey(const ValueKey('summary-domain')), findsOneWidget);
      expect(find.text('Active'), findsOneWidget);
      expect(find.textContaining('Expires 2030-01-15'), findsOneWidget);
      // Details tab.
      for (final label in [
        'Overview',
        'Registered',
        'Protection',
        'Auto-renew',
        'Privacy protection',
        'Registrar lock',
        'Nameservers',
        'Provider IDs',
        'Order ID',
        'Customer ID',
      ]) {
        expect(find.text(label), findsOneWidget, reason: label);
      }
      expect(find.textContaining('ns1.example.net'), findsOneWidget);
      expect(find.text('1001'), findsOneWidget);
      expect(find.textContaining('Not listed by the provider'), findsNothing);
      // Diagnostics are on the Sync tab, not the Details tab.
      expect(find.text('Last synced'), findsNothing);
      await tester.tap(find.byKey(const ValueKey('tab-sync')));
      await tester.pumpAndSettle();
      for (final label in [
        'Last synced',
        'Contact details synced',
        'First seen',
        'Missing from provider',
      ]) {
        expect(find.text(label), findsOneWidget, reason: label);
      }
      expect(find.text('Order ID'), findsNothing);
      expect(seen, ['/api/v1/domains/1/details']);
    });

    testWidgets('empty values are omitted; null nameservers say Not synced', (
      tester,
    ) async {
      await open(tester, server((_) async => _json(200, _bareDetail())));
      await tester.pumpAndSettle();

      expect(find.text('Not synced'), findsOneWidget);
      expect(find.text('Expiry unknown'), findsOneWidget);
      // No placeholder noise, no empty cards.
      expect(find.text('Unknown'), findsNothing);
      expect(find.text('Not provided'), findsNothing);
      for (final omitted in [
        'Overview',
        'Registered',
        'Protection',
        'Auto-renew',
        'Privacy protection',
        'Registrar lock',
        'Customer ID',
      ]) {
        expect(find.text(omitted), findsNothing, reason: omitted);
      }
      expect(find.text('Order ID'), findsOneWidget); // the one ID present
      // No status from the provider: no status badge, only the expiry badge.
      expect(find.byType(StatusBadge), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('tab-sync')));
      await tester.pumpAndSettle();
      expect(find.text('Last synced'), findsNothing);
      expect(find.text('First seen'), findsNothing);
      expect(find.text('Not yet synchronised'), findsOneWidget);
      expect(find.text('No'), findsOneWidget); // not missing from provider
    });

    testWidgets('an empty nameserver list says None listed', (tester) async {
      await open(
        tester,
        server(
          (_) async => _json(200, _detail(extra: {'nameservers': <String>[]})),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('None listed'), findsOneWidget);
      expect(find.text('Not synced'), findsNothing);
    });

    testWidgets('a missing domain shows a warning', (tester) async {
      await open(
        tester,
        server((_) async => _json(200, _detail(extra: {'is_missing': true}))),
      );
      await tester.pumpAndSettle();
      expect(find.textContaining('Not listed by the provider'), findsOneWidget);
      expect(find.byIcon(Icons.warning_amber), findsWidgets);
      // The summary stays visible with a danger badge.
      expect(find.text('Missing from provider'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('tab-sync')));
      await tester.pumpAndSettle();
      expect(find.text('Yes'), findsOneWidget);
    });

    testWidgets('a 404 says the domain is no longer available', (tester) async {
      await open(
        tester,
        server((_) async => _json(404, {'error': 'not_found'})),
      );
      await tester.pumpAndSettle();
      expect(find.text('This domain is no longer available.'), findsOneWidget);
      expect(find.text('Retry'), findsNothing);
    });

    testWidgets('an error offers Retry which loads the details', (
      tester,
    ) async {
      var fail = true;
      await open(
        tester,
        server(
          (_) async => fail
              ? _json(500, {'error': 'server_error'})
              : _json(200, _detail()),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.textContaining('server_error'), findsOneWidget);

      fail = false;
      await tester.tap(find.text('Retry'));
      await tester.pumpAndSettle();
      expect(find.text('Order ID'), findsOneWidget);
      expect(find.text('Retry'), findsNothing);
    });

    testWidgets('a malformed response is a retryable error, not a blank page', (
      tester,
    ) async {
      await open(tester, server((_) async => _json(200, {'id': 1})));
      await tester.pumpAndSettle();
      expect(find.textContaining('bad_response'), findsOneWidget);
      expect(find.text('Retry'), findsOneWidget);
    });

    testWidgets('going back keeps the list rows', (tester) async {
      var listCalls = 0;
      await open(
        tester,
        MockClient((req) async {
          final path = req.url.path;
          if (path == '/api/v1/domains') {
            listCalls++;
            return _json(200, _page([_row(1, 'alpha.com')]));
          }
          return _json(200, _detail());
        }),
      );
      await tester.pumpAndSettle();
      expect(find.text('Order ID'), findsOneWidget);

      await tester.pageBack();
      await tester.pumpAndSettle();
      expect(find.text('Order ID'), findsNothing);
      expect(find.text('alpha.com'), findsOneWidget);
      expect(listCalls, 1); // the list was not reloaded
    });

    testWidgets('an expired session closes the details and shows login', (
      tester,
    ) async {
      final (auth, storage) = await open(
        tester,
        server((_) async => _json(401, {'error': 'unauthorized'})),
      );
      await tester.pumpAndSettle();

      expect(auth.status, AuthStatus.signedOut);
      expect(storage.tokens, isNull);
      expect(find.text('Sign in'), findsOneWidget);
      expect(find.text('Order ID'), findsNothing);
      expect(find.text('Retry'), findsNothing);
    });

    testWidgets('logging out elsewhere while open also closes the details', (
      tester,
    ) async {
      final (auth, _) = await open(
        tester,
        server((_) async => _json(200, _detail())),
      );
      await tester.pumpAndSettle();
      expect(find.text('Order ID'), findsOneWidget);

      await tester.runAsync(auth.logout);
      await tester.pumpAndSettle();
      expect(find.text('Sign in'), findsOneWidget);
      expect(find.text('Order ID'), findsNothing);
    });

    group('tabs and contacts', () {
      Future<void> goTo(WidgetTester tester, String tab) async {
        await tester.ensureVisible(find.byKey(ValueKey('tab-$tab')));
        await tester.pumpAndSettle();
        await tester.tap(find.byKey(ValueKey('tab-$tab')));
        await tester.pumpAndSettle();
      }

      /// registrant: full, admin: absent, tech: only an email, billing: a
      /// synchronised contact with nothing in it.
      Map<String, Object?> withContacts() => _detail(
        extra: {
          'details_synced_at': '2026-05-02T09:00:00Z',
          'contacts': {
            'registrant': {
              'name': 'Jane Doe',
              'company': 'Acme Ltd',
              'email': 'jane.doe@example.com',
              'tel_country_code': '44',
              'tel_number': '2079460000',
              'address1': '1 High Street',
              'city': 'London',
              'postal_code': 'N1 1AA',
              'country': 'GB',
            },
            'tech': {'email': 'tech@example.com'},
            'billing': {'name': null, 'email': ''},
          },
        },
      );

      testWidgets('the summary stays visible on every tab', (tester) async {
        await open(tester, server((_) async => _json(200, withContacts())));
        await tester.pumpAndSettle();
        for (final tab in ['details', 'contacts', 'sync']) {
          await goTo(tester, tab);
          expect(find.byKey(const ValueKey('summary-domain')), findsOneWidget);
          expect(find.text('Active'), findsOneWidget, reason: tab);
        }
      });

      testWidgets('switching tabs sends no further requests', (tester) async {
        var detailCalls = 0;
        await open(
          tester,
          server((_) async {
            detailCalls++;
            return _json(200, withContacts());
          }),
        );
        await tester.pumpAndSettle();
        for (final tab in ['contacts', 'sync', 'details', 'sync', 'contacts']) {
          await goTo(tester, tab);
        }
        await tester.drag(find.byType(TabBarView), const Offset(600, 0));
        await tester.pumpAndSettle();
        expect(detailCalls, 1);
      });

      testWidgets('all four roles are listed, in order', (tester) async {
        await open(tester, server((_) async => _json(200, withContacts())));
        await tester.pumpAndSettle();
        await goTo(tester, 'contacts');
        final titles = [
          'Registrant Contact',
          'Administrative Contact',
          'Technical Contact',
          'Billing Contact',
        ];
        for (final t in titles) {
          expect(find.text(t), findsOneWidget, reason: t);
        }
        final ys = [for (final t in titles) tester.getTopLeft(find.text(t)).dy];
        expect(ys, [...ys]..sort());
      });

      testWidgets('an available contact summarises, expands and collapses', (
        tester,
      ) async {
        await open(tester, server((_) async => _json(200, withContacts())));
        await tester.pumpAndSettle();
        await goTo(tester, 'contacts');

        // Collapsed: one-line summary only.
        expect(find.text('Jane Doe · Acme Ltd'), findsOneWidget);
        expect(find.text('jane.doe@example.com'), findsNothing);

        await tester.tap(find.text('Registrant Contact'));
        await tester.pumpAndSettle();
        expect(find.text('jane.doe@example.com'), findsOneWidget);
        expect(find.text('+44 2079460000'), findsOneWidget);
        expect(find.text('1 High Street\nLondon, N1 1AA\nGB'), findsOneWidget);

        await tester.tap(find.text('Registrant Contact'));
        await tester.pumpAndSettle();
        expect(find.text('jane.doe@example.com'), findsNothing);
      });

      testWidgets('a missing contact is one non-expandable row', (
        tester,
      ) async {
        await open(tester, server((_) async => _json(200, withContacts())));
        await tester.pumpAndSettle();
        await goTo(tester, 'contacts');

        final admin = find.byKey(const ValueKey('contact-admin'));
        expect(
          find.descendant(of: admin, matching: find.text('Not synchronised')),
          findsOneWidget,
        );
        expect(
          find.byType(ExpansionTile),
          findsNWidgets(2),
        ); // registrant, tech
        expect(
          find.descendant(of: admin, matching: find.byType(ExpansionTile)),
          findsNothing,
        );
        expect(
          find.descendant(of: admin, matching: find.byIcon(Icons.expand_more)),
          findsNothing,
        );
      });

      testWidgets('a synchronised but empty contact is told apart', (
        tester,
      ) async {
        await open(tester, server((_) async => _json(200, withContacts())));
        await tester.pumpAndSettle();
        await goTo(tester, 'contacts');

        final billing = find.byKey(const ValueKey('contact-billing'));
        expect(
          find.descendant(
            of: billing,
            matching: find.text('Synchronised — no details on file'),
          ),
          findsOneWidget,
        );
        expect(
          find.descendant(of: billing, matching: find.text('Not synchronised')),
          findsNothing,
        );
      });

      testWidgets('no synced contacts at all: four Not synchronised rows', (
        tester,
      ) async {
        await open(tester, server((_) async => _json(200, _detail())));
        await tester.pumpAndSettle();
        await goTo(tester, 'contacts');
        expect(find.text('Not synchronised'), findsNWidgets(4));
        expect(find.byType(ExpansionTile), findsNothing);
      });

      testWidgets('empty fields of an available contact are omitted', (
        tester,
      ) async {
        await open(tester, server((_) async => _json(200, withContacts())));
        await tester.pumpAndSettle();
        await goTo(tester, 'contacts');
        await tester.tap(find.text('Technical Contact'));
        await tester.pumpAndSettle();

        expect(find.text('Email'), findsOneWidget);
        for (final omitted in ['Name', 'Company', 'Telephone', 'Address']) {
          expect(find.text(omitted), findsNothing, reason: omitted);
        }
        expect(find.text('Not provided'), findsNothing);
        expect(find.text('Unknown'), findsNothing);
      });

      testWidgets('nameservers, IDs and contact values can be selected', (
        tester,
      ) async {
        await open(tester, server((_) async => _json(200, withContacts())));
        await tester.pumpAndSettle();
        expect(
          find.ancestor(
            of: find.text('ns1.example.net'),
            matching: find.byType(SelectableText),
          ),
          findsOneWidget,
        );
        expect(
          find.ancestor(
            of: find.text('1001'),
            matching: find.byType(SelectableText),
          ),
          findsOneWidget,
        );
        await goTo(tester, 'contacts');
        await tester.tap(find.text('Registrant Contact'));
        await tester.pumpAndSettle();
        for (final v in ['jane.doe@example.com', '+44 2079460000']) {
          expect(
            find.ancestor(
              of: find.text(v),
              matching: find.byType(SelectableText),
            ),
            findsOneWidget,
            reason: v,
          );
        }
      });

      testWidgets('tabs are at least 48dp tall and labelled', (tester) async {
        final handle = tester.ensureSemantics();
        await open(tester, server((_) async => _json(200, withContacts())));
        await tester.pumpAndSettle();
        for (final tab in ['details', 'contacts', 'sync']) {
          expect(
            tester.getSize(find.byKey(ValueKey('tab-$tab'))).height,
            greaterThanOrEqualTo(48),
          );
        }
        for (final label in ['Details', 'Contacts', 'Sync']) {
          expect(find.bySemanticsLabel(RegExp(label)), findsWidgets);
        }
        handle.dispose();
      });

      testWidgets('Android back leaves the screen from any tab', (
        tester,
      ) async {
        var listCalls = 0;
        await open(
          tester,
          MockClient((req) async {
            if (req.url.path == '/api/v1/domains') {
              listCalls++;
              return _json(200, _page([_row(1, 'alpha.com')]));
            }
            return _json(200, withContacts());
          }),
        );
        await tester.pumpAndSettle();
        await goTo(tester, 'contacts');
        await tester.tap(find.text('Registrant Contact'));
        await tester.pumpAndSettle();

        await tester.binding.handlePopRoute();
        await tester.pumpAndSettle();
        expect(find.byKey(const ValueKey('summary-domain')), findsNothing);
        expect(find.text('alpha.com'), findsOneWidget);
        expect(listCalls, 1);
      });

      group('long values', () {
        const longName = 'Bartholomew-Maximilian Featherstonehaugh-Worthington';
        const longEmail =
            'a.very.long.local.part.that.cannot.break@'
            'subdomain.of-an-extremely-long-organisation-name.example.com';
        const longNs =
            'ns1.an-exceptionally-long-nameserver-host-name-for-testing.'
            'delegated-dns-provider.example.net';
        const longDomain =
            'the-quick-brown-fox-jumps-over-the-lazy-dog-again-and-again.com';
        const address =
            '1234 The Extraordinarily Long Street Name Boulevard, Apartment '
            '5678B';

        Map<String, Object?> longDetail() => {
          ..._detail(),
          'domain_name': longDomain,
          'nameservers': [longNs, 'ns2.$longNs'],
          'rc_order_id': '9' * 40,
          'details_synced_at': '2026-05-02T09:00:00Z',
          'contacts': {
            for (final role in contactRoles)
              role: {
                'name': longName,
                'company': '$longName Holdings International',
                'email': longEmail,
                'tel_country_code': '44',
                'tel_number': '2079460000',
                'address1': address,
                'address2': 'Floor 99, Tower of the Extraordinarily Long Name',
                'city':
                    'Llanfairpwllgwyngyllgogerychwyrndrobwllllantysiliogogogoch',
                'postal_code': 'LL61 5UJ',
                'country':
                    'United Kingdom of Great Britain and Northern Ireland',
              },
          },
        };

        for (final size in const [
          Size(320, 640),
          Size(360, 740),
          Size(412, 892),
          Size(1024, 768),
        ]) {
          for (final scale in const [1.0, 2.0]) {
            testWidgets(
              'no overflow at ${size.width.toInt()}dp, ${scale}x text',
              (tester) async {
                tester.platformDispatcher.textScaleFactorTestValue = scale;
                addTearDown(
                  tester.platformDispatcher.clearTextScaleFactorTestValue,
                );
                // The screen is mounted on its own so the check covers this
                // screen only, not the dashboard and list beneath it.
                tester.view.physicalSize = size;
                tester.view.devicePixelRatio = 1;
                addTearDown(tester.view.reset);
                final auth = _auth(
                  MockClient((_) async => _json(200, longDetail())),
                  MemoryStorage(),
                );
                await tester.pumpWidget(
                  MaterialApp(
                    theme: AppTheme.light,
                    home: DomainDetailScreen(
                      api: auth.api,
                      auth: auth,
                      domainId: 1,
                      title: 'alpha.com',
                    ),
                  ),
                );
                await tester.pumpAndSettle();
                expect(tester.takeException(), isNull);
                // The summary wraps the whole name.
                expect(
                  find.descendant(
                    of: find.byKey(const ValueKey('summary-domain')),
                    matching: find.text(longDomain),
                  ),
                  findsNothing,
                ); // (the key is on the Text itself)
                expect(
                  tester
                      .widget<Text>(
                        find.byKey(const ValueKey('summary-domain')),
                      )
                      .data,
                  longDomain,
                );

                await goTo(tester, 'contacts');
                for (final t in [
                  'Registrant Contact',
                  'Administrative Contact',
                  'Technical Contact',
                  'Billing Contact',
                ]) {
                  await tester.scrollUntilVisible(
                    find.text(t),
                    100,
                    scrollable: find
                        .descendant(
                          of: find.byType(ListView).first,
                          matching: find.byType(Scrollable),
                        )
                        .first,
                  );
                  await tester.ensureVisible(find.text(t));
                  await tester.pumpAndSettle();
                  await tester.tap(find.text(t));
                  await tester.pumpAndSettle();
                }
                expect(tester.takeException(), isNull);

                await goTo(tester, 'sync');
                expect(tester.takeException(), isNull);
                await goTo(tester, 'details');
                await tester.scrollUntilVisible(
                  find.text('Provider IDs'),
                  100,
                  scrollable: find
                      .descendant(
                        of: find.byType(ListView).first,
                        matching: find.byType(Scrollable),
                      )
                      .first,
                );
                expect(tester.takeException(), isNull);
              },
            );
          }
        }
      });
    });
  });
}

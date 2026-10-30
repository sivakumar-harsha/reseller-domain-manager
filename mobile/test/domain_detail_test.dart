import 'dart:async';
import 'dart:convert';

import 'package:domain_manager/app.dart';
import 'package:domain_manager/auth/auth_controller.dart';
import 'package:domain_manager/auth/token_storage.dart';
import 'package:domain_manager/core/api_client.dart';
import 'package:domain_manager/domains/domain.dart';
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
    jsonEncode(body), status,
    headers: {'content-type': 'application/json'});

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
      'meta': {
        'page': 1,
        'per_page': 20,
        'total': rows.length,
        'total_pages': 1,
      },
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
          DomainDetail.fromJson(_detail(extra: {
            'nameservers': ['ns1.example.net', 3, null]
          })).nameservers,
          ['ns1.example.net']);
      expect(
          DomainDetail.fromJson(_detail(extra: {'nameservers': 'x'}))
              .nameservers,
          isNull);
    });
  });

  group('getDomain request', () {
    test('sends GET domains/{id} with the bearer token', () async {
      late http.Request seen;
      final auth = _auth(MockClient((req) async {
        seen = req;
        return _json(200, _detail());
      }), MemoryStorage());

      final d = await auth.api.getDomain(7);

      expect(seen.method, 'GET');
      expect(seen.url.path, '/api/v1/domains/7');
      expect(seen.headers['Authorization'], 'Bearer a1');
      expect(d.domain.domainName, 'alpha.com');
    });

    test('a 401 refreshes once, stores the new pair and retries', () async {
      final storage = MemoryStorage();
      final tokens = <String?>[];
      final auth = _auth(MockClient((req) async {
        if (req.url.path.endsWith('auth/refresh')) {
          return _json(200, {'access_token': 'a2', 'refresh_token': 'r2'});
        }
        tokens.add(req.headers['Authorization']);
        return tokens.length == 1
            ? _json(401, {'error': 'unauthorized'})
            : _json(200, _detail());
      }), storage);

      final d = await auth.api.getDomain(1);

      expect(tokens, ['Bearer a1', 'Bearer a2']);
      expect(storage.tokens?.refreshToken, 'r2');
      expect(d.domain.id, 1);
    });

    test('a 404 is passed through as an ApiException', () async {
      final auth = _auth(
          MockClient((_) async => _json(404, {'error': 'not_found'})),
          MemoryStorage());
      await expectLater(
        auth.api.getDomain(1),
        throwsA(isA<ApiException>()
            .having((e) => e.statusCode, 'status', 404)
            .having((e) => e.code, 'code', 'not_found')),
      );
    });

    test('a malformed body is a bad_response', () async {
      for (final body in [<String, Object?>{}, {'id': 1}, {'data': []}]) {
        final auth = _auth(
            MockClient((_) async => _json(200, body)), MemoryStorage());
        await expectLater(
          auth.api.getDomain(1),
          throwsA(isA<ApiException>().having((e) => e.code, 'code', 'bad_response')),
        );
      }
    });

    test('without a session nothing is sent', () async {
      var calls = 0;
      final api = _api(MockClient((_) async {
        calls++;
        return _json(200, _detail());
      }));
      await expectLater(api.getDomain(1),
          throwsA(isA<ApiException>().having((e) => e.statusCode, 'status', 401)));
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
        if (path == '/api/v1/domains') {
          return _json(200, _page([_row(1, 'alpha.com')]));
        }
        return onDetail(req);
      });
    }

    Future<(AuthController, MemoryStorage)> open(
        WidgetTester tester, MockClient client) async {
      // Tall enough that the lazy ListView builds every field.
      tester.view.physicalSize = const Size(800, 2400);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final storage = MemoryStorage();
      final auth = _auth(client, storage);
      await tester.pumpWidget(App(auth: auth));
      await tester.pumpAndSettle();
      await tester.tap(find.text('alpha.com'));
      await tester.pump(); // route pushed
      return (auth, storage);
    }

    testWidgets('tapping a row shows a spinner, then every labelled field',
        (tester) async {
      final seen = <String>[];
      final gate = Completer<void>();
      await open(tester, server((req) async {
        seen.add(req.url.path);
        await gate.future;
        return _json(200, _detail());
      }));
      await tester.pump(const Duration(milliseconds: 400)); // route is open
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
      expect(find.text('Order ID'), findsNothing);
      gate.complete();
      await tester.pumpAndSettle();

      expect(seen, ['/api/v1/domains/1']);
      for (final label in [
        'Domain',
        'Status',
        'Expiry',
        'Auto-renew',
        'Privacy protection',
        'Registrar lock',
        'Nameservers',
        'Registered',
        'First seen',
        'Last synced',
        'Missing from provider',
        'Order ID',
        'Customer ID',
      ]) {
        expect(find.text(label), findsOneWidget, reason: label);
      }
      expect(find.textContaining('ns1.example.net'), findsOneWidget);
      expect(find.text('Active'), findsOneWidget);
      expect(find.text('1001'), findsOneWidget);
      expect(find.textContaining('Not listed by the provider'), findsNothing);
    });

    testWidgets('null flags show Unknown and null nameservers Not synced',
        (tester) async {
      await open(tester, server((_) async => _json(200, _bareDetail())));
      await tester.pumpAndSettle();

      expect(find.text('Not synced'), findsOneWidget);
      expect(find.text('Expiry unknown'), findsOneWidget);
      // status, auto-renew, privacy, registrar lock, registered, first seen,
      // last synced, customer id.
      expect(find.text('Unknown'), findsNWidgets(8));
    });

    testWidgets('a missing domain shows a warning', (tester) async {
      await open(
          tester,
          server((_) async =>
              _json(200, _detail(extra: {'is_missing': true}))));
      await tester.pumpAndSettle();
      expect(find.textContaining('Not listed by the provider'), findsOneWidget);
      expect(find.byIcon(Icons.warning_amber), findsWidgets);
    });

    testWidgets('a 404 says the domain is no longer available',
        (tester) async {
      await open(tester,
          server((_) async => _json(404, {'error': 'not_found'})));
      await tester.pumpAndSettle();
      expect(find.text('This domain is no longer available.'), findsOneWidget);
      expect(find.text('Retry'), findsNothing);
    });

    testWidgets('an error offers Retry which loads the details',
        (tester) async {
      var fail = true;
      await open(
          tester,
          server((_) async => fail
              ? _json(500, {'error': 'server_error'})
              : _json(200, _detail())));
      await tester.pumpAndSettle();
      expect(find.textContaining('server_error'), findsOneWidget);

      fail = false;
      await tester.tap(find.text('Retry'));
      await tester.pumpAndSettle();
      expect(find.text('Order ID'), findsOneWidget);
      expect(find.text('Retry'), findsNothing);
    });

    testWidgets('a malformed response is a retryable error, not a blank page',
        (tester) async {
      await open(tester, server((_) async => _json(200, {'id': 1})));
      await tester.pumpAndSettle();
      expect(find.textContaining('bad_response'), findsOneWidget);
      expect(find.text('Retry'), findsOneWidget);
    });

    testWidgets('going back keeps the list rows', (tester) async {
      var listCalls = 0;
      await open(tester, MockClient((req) async {
        final path = req.url.path;
        if (path == '/api/v1/domains') {
          listCalls++;
          return _json(200, _page([_row(1, 'alpha.com')]));
        }
        return _json(200, _detail());
      }));
      await tester.pumpAndSettle();
      expect(find.text('Order ID'), findsOneWidget);

      await tester.pageBack();
      await tester.pumpAndSettle();
      expect(find.text('Order ID'), findsNothing);
      expect(find.text('alpha.com'), findsOneWidget);
      expect(listCalls, 1); // the list was not reloaded
    });

    testWidgets('an expired session closes the details and shows login',
        (tester) async {
      final (auth, storage) = await open(
          tester, server((_) async => _json(401, {'error': 'unauthorized'})));
      await tester.pumpAndSettle();

      expect(auth.status, AuthStatus.signedOut);
      expect(storage.tokens, isNull);
      expect(find.text('Sign in'), findsOneWidget);
      expect(find.text('Order ID'), findsNothing);
      expect(find.text('Retry'), findsNothing);
    });

    testWidgets('logging out elsewhere while open also closes the details',
        (tester) async {
      final (auth, _) = await open(
          tester, server((_) async => _json(200, _detail())));
      await tester.pumpAndSettle();
      expect(find.text('Order ID'), findsOneWidget);

      await tester.runAsync(auth.logout);
      await tester.pumpAndSettle();
      expect(find.text('Sign in'), findsOneWidget);
      expect(find.text('Order ID'), findsNothing);
    });
  });
}

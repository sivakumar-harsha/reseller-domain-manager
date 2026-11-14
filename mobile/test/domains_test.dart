import 'dart:async';
import 'dart:convert';

import 'package:domain_manager/auth/auth_controller.dart';
import 'package:domain_manager/auth/token_storage.dart';
import 'package:domain_manager/core/api_client.dart';
import 'package:domain_manager/domains/domain.dart';
import 'package:domain_manager/domains/domain_controller.dart';
import 'package:domain_manager/screens/home_screen.dart';
import 'package:domain_manager/theme/app_theme.dart';
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

Map<String, Object?> _row(int id, String name, {Map<String, Object?>? extra}) =>
    {
      'id': id,
      'domain_name': name,
      'status': 'Active',
      'expires_at': '2030-01-15T00:00:00Z',
      'auto_renew': null,
      'privacy_enabled': true,
      'registrar_lock': false,
      'is_missing': false,
      'last_synced_at': '2026-05-01T10:30:00Z',
      ...?extra,
    };

Map<String, Object?> _page(
  List<Map<String, Object?>> rows, {
  int page = 1,
  int perPage = 20,
  int? total,
  int? totalPages,
  String? lastSynced = '2026-05-01T10:30:00Z',
}) => {
  'data': rows,
  'meta': {
    'page': page,
    'per_page': perPage,
    'total': total ?? rows.length,
    'total_pages': totalPages ?? 1,
  },
  'last_synced_at': lastSynced,
};

ApiClient _api(MockClient client) =>
    ApiClient(client: client, baseUrl: 'http://test/api/v1/');

/// Signed-in controller whose stored pair is access "a1" / refresh "r1".
AuthController _auth(MockClient client, MemoryStorage storage) {
  storage.tokens = const TokenPair(accessToken: 'a1', refreshToken: 'r1');
  final auth = AuthController(api: _api(client), storage: storage);
  auth.status = AuthStatus.signedIn;
  return auth;
}

void main() {
  group('parsing', () {
    test('a full row keeps every field and parses dates as UTC', () {
      final d = Domain.tryParse(_row(7, 'example.com'))!;
      expect(d.id, 7);
      expect(d.domainName, 'example.com');
      expect(d.status, 'Active');
      expect(d.expiresAt, DateTime.utc(2030, 1, 15));
      expect(d.expiresAt!.isUtc, isTrue);
      expect(d.autoRenew, isNull);
      expect(d.privacyEnabled, isTrue);
      expect(d.registrarLock, isFalse);
      expect(d.isMissing, isFalse);
      expect(d.lastSyncedAt, DateTime.utc(2026, 5, 1, 10, 30));
    });

    test('nullable fields stay null and are never invented', () {
      final d = Domain.tryParse({
        'id': 1,
        'domain_name': 'a.com',
        'status': null,
        'expires_at': null,
        'auto_renew': null,
        'privacy_enabled': null,
        'registrar_lock': null,
        'is_missing': false,
        'last_synced_at': null,
      })!;
      expect(d.status, isNull);
      expect(d.expiresAt, isNull);
      expect(d.privacyEnabled, isNull);
      expect(d.registrarLock, isNull);
      expect(d.lastSyncedAt, isNull);
    });

    test('is_missing is true only for a true flag', () {
      bool missing(Object? v) => Domain.tryParse(
        _row(1, 'a.com', extra: {'is_missing': v}),
      )!.isMissing;
      expect(missing(true), isTrue);
      expect(missing(1), isTrue);
      expect(missing(false), isFalse);
      expect(missing(0), isFalse);
      expect(missing(null), isFalse);
      expect(missing('false'), isFalse);
    });

    test('unparsable dates become null and bad rows are skipped', () {
      expect(
        Domain.tryParse(
          _row(1, 'a.com', extra: {'expires_at': 'soon'}),
        )!.expiresAt,
        isNull,
      );
      expect(Domain.tryParse({'id': 'x', 'domain_name': 'a.com'}), isNull);
      expect(Domain.tryParse({'id': 1, 'domain_name': ''}), isNull);
      expect(Domain.tryParse('nope'), isNull);

      final page = DomainPage.fromJson(
        _page([
          _row(1, 'a.com'),
          {'id': 2},
          _row(3, 'c.com'),
        ]),
      );
      expect(page.items.map((d) => d.id), [1, 3]);
      expect(page.skipped, 1);
    });

    test('the page envelope is validated', () {
      final p = DomainPage.fromJson(
        _page([_row(1, 'a.com')], page: 2, total: 41, totalPages: 3),
      );
      expect((p.page, p.total, p.totalPages, p.hasMore), (2, 41, 3, true));
      expect(
        DomainPage.fromJson(_page([], lastSynced: null)).lastSyncedAt,
        isNull,
      );

      expect(() => DomainPage.fromJson({'data': []}), throwsFormatException);
      expect(
        () => DomainPage.fromJson({'data': 'x', 'meta': {}}),
        throwsFormatException,
      );
      expect(
        () => DomainPage.fromJson({
          'data': [],
          'meta': {'page': 1, 'per_page': 20, 'total': -1, 'total_pages': 0},
        }),
        throwsFormatException,
      );
    });

    test('expiry text compares local calendar days', () {
      final now = DateTime.now();
      DateTime inDays(int n) =>
          DateTime(now.year, now.month, now.day, 12).add(Duration(days: n));
      expect(describeExpiry(null, now), 'Expiry unknown');
      expect(describeExpiry(inDays(0), now), contains('(today)'));
      expect(describeExpiry(inDays(1), now), contains('(tomorrow)'));
      expect(describeExpiry(inDays(30), now), contains('(in 30 days)'));
      expect(describeExpiry(inDays(-1), now), startsWith('Expired'));
      expect(describeExpiry(inDays(-1), now), contains('(yesterday)'));
      expect(describeExpiry(inDays(-9), now), contains('(9 days ago)'));
    });
  });

  group('listDomains request', () {
    test('sends the bearer token, paging and a trimmed search term', () async {
      late http.Request seen;
      final auth = _auth(
        MockClient((req) async {
          seen = req;
          return _json(200, _page([_row(1, 'a.com')]));
        }),
        MemoryStorage(),
      );

      final page = await auth.api.listDomains(
        q: ' ex_%ample ',
        page: 2,
        perPage: 50,
      );

      expect(seen.method, 'GET');
      expect(seen.url.path, '/api/v1/domains');
      expect(seen.url.queryParameters, {
        'q': 'ex_%ample',
        'page': '2',
        'per_page': '50',
      });
      expect(seen.headers['Authorization'], 'Bearer a1');
      expect(page.items.single.domainName, 'a.com');
    });

    test('a blank search term is not sent', () async {
      late http.Request seen;
      final auth = _auth(
        MockClient((req) async {
          seen = req;
          return _json(200, _page([]));
        }),
        MemoryStorage(),
      );
      await auth.api.listDomains(q: '   ');
      expect(seen.url.queryParameters, {'page': '1', 'per_page': '20'});
    });

    test('a malformed body is a bad_response, not a crash', () async {
      for (final body in ['not json', '[]', '{"data":[]}']) {
        final auth = _auth(
          MockClient((_) async => http.Response(body, 200)),
          MemoryStorage(),
        );
        await expectLater(
          auth.api.listDomains(),
          throwsA(
            isA<ApiException>().having((e) => e.code, 'code', 'bad_response'),
          ),
        );
      }
    });

    test(
      'without a session the call fails with 401 and sends nothing',
      () async {
        var calls = 0;
        final api = _api(
          MockClient((_) async {
            calls++;
            return _json(200, _page([]));
          }),
        );
        await expectLater(
          api.listDomains(),
          throwsA(
            isA<ApiException>().having((e) => e.statusCode, 'status', 401),
          ),
        );
        expect(calls, 0);
      },
    );
  });

  group('token refresh and retry', () {
    test(
      'a 401 refreshes once, persists the new pair and retries once',
      () async {
        final storage = MemoryStorage();
        final log = <String>[];
        final auth = _auth(
          MockClient((req) async {
            log.add('${req.url.path} ${req.headers['Authorization'] ?? ''}');
            if (req.url.path.endsWith('auth/refresh')) {
              return _json(200, {'access_token': 'a2', 'refresh_token': 'r2'});
            }
            return req.headers['Authorization'] == 'Bearer a1'
                ? _json(401, {'error': 'unauthorized'})
                : _json(200, _page([_row(1, 'a.com')]));
          }),
          storage,
        );

        final page = await auth.api.listDomains();

        expect(page.items, hasLength(1));
        expect(log, [
          '/api/v1/domains Bearer a1',
          '/api/v1/auth/refresh ',
          '/api/v1/domains Bearer a2',
        ]);
        expect(storage.tokens?.accessToken, 'a2');
        expect(storage.tokens?.refreshToken, 'r2');
        expect(auth.status, AuthStatus.signedIn);
      },
    );

    test('a second 401 is not retried again and ends the session', () async {
      final storage = MemoryStorage();
      var domainCalls = 0, refreshCalls = 0;
      final auth = _auth(
        MockClient((req) async {
          if (req.url.path.endsWith('auth/refresh')) {
            refreshCalls++;
            return _json(200, {'access_token': 'a2', 'refresh_token': 'r2'});
          }
          domainCalls++;
          return _json(401, {'error': 'unauthorized'});
        }),
        storage,
      );

      await expectLater(
        auth.api.listDomains(),
        throwsA(isA<ApiException>().having((e) => e.statusCode, 'status', 401)),
      );

      expect((domainCalls, refreshCalls), (2, 1));
      expect(auth.status, AuthStatus.signedOut);
      expect(storage.tokens, isNull);
    });

    test(
      'concurrent 401s spend the single-use refresh token only once',
      () async {
        final storage = MemoryStorage();
        var refreshCalls = 0;
        final gate = Completer<void>();
        final auth = _auth(
          MockClient((req) async {
            if (req.url.path.endsWith('auth/refresh')) {
              refreshCalls++;
              await gate.future; // keep the first refresh in flight
              return _json(200, {'access_token': 'a2', 'refresh_token': 'r2'});
            }
            return req.headers['Authorization'] == 'Bearer a1'
                ? _json(401, {'error': 'unauthorized'})
                : _json(200, _page([_row(1, 'a.com')]));
          }),
          storage,
        );

        final results = Future.wait([
          auth.api.listDomains(page: 1),
          auth.api.listDomains(page: 2),
          auth.api.listDomains(page: 3),
        ]);
        await Future<void>.delayed(const Duration(milliseconds: 50));
        gate.complete();
        final pages = await results;

        expect(pages, hasLength(3));
        expect(refreshCalls, 1);
        expect(storage.tokens?.refreshToken, 'r2');
        expect(auth.status, AuthStatus.signedIn);
      },
    );

    test(
      'a rejected refresh clears the session and returns to login',
      () async {
        final storage = MemoryStorage();
        final auth = _auth(
          MockClient((req) async {
            return req.url.path.endsWith('auth/refresh')
                ? _json(401, {'error': 'invalid_refresh_token'})
                : _json(401, {'error': 'unauthorized'});
          }),
          storage,
        );

        await expectLater(
          auth.api.listDomains(),
          throwsA(
            isA<ApiException>().having((e) => e.statusCode, 'status', 401),
          ),
        );

        expect(auth.status, AuthStatus.signedOut);
        expect(storage.tokens, isNull);
        expect(auth.errorMessage, contains('session has expired'));
      },
    );

    test('an unreachable server during refresh keeps the session', () async {
      final storage = MemoryStorage();
      final auth = _auth(
        MockClient((req) async {
          if (req.url.path.endsWith('auth/refresh')) throw Exception('offline');
          return _json(401, {'error': 'unauthorized'});
        }),
        storage,
      );

      await expectLater(
        auth.api.listDomains(),
        throwsA(
          isA<ApiException>().having((e) => e.code, 'code', 'network_error'),
        ),
      );

      expect(auth.status, AuthStatus.signedIn);
      expect(storage.tokens?.refreshToken, 'r1');
    });

    test('non-401 errors are passed through without refreshing', () async {
      var refreshCalls = 0;
      final auth = _auth(
        MockClient((req) async {
          if (req.url.path.endsWith('auth/refresh')) refreshCalls++;
          return _json(500, {'error': 'server_error'});
        }),
        MemoryStorage(),
      );
      await expectLater(
        auth.api.listDomains(),
        throwsA(
          isA<ApiException>().having((e) => e.code, 'code', 'server_error'),
        ),
      );
      expect(refreshCalls, 0);
    });

    test('after logout a pending request cannot revive the session', () async {
      final storage = MemoryStorage();
      final auth = _auth(MockClient((_) async => _json(401, {})), storage);
      await auth.logout();
      await expectLater(
        auth.api.listDomains(),
        throwsA(isA<ApiException>().having((e) => e.statusCode, 'status', 401)),
      );
      expect(auth.status, AuthStatus.signedOut);
    });
  });

  group('DomainController', () {
    DomainController controller(MockClient client, {int perPage = 2}) {
      final auth = _auth(client, MemoryStorage());
      return DomainController(
        api: auth.api,
        perPage: perPage,
        debounce: const Duration(milliseconds: 10),
      );
    }

    test('loads the first page and exposes totals and last sync', () async {
      final c = controller(
        MockClient(
          (_) async => _json(
            200,
            _page(
              [_row(1, 'a.com'), _row(2, 'b.com')],
              perPage: 2,
              total: 3,
              totalPages: 2,
            ),
          ),
        ),
      );
      await c.load();
      expect(c.items.map((d) => d.domainName), ['a.com', 'b.com']);
      expect((c.total, c.hasMore, c.loading, c.error), (3, true, false, null));
      expect(c.lastSyncedAt, DateTime.utc(2026, 5, 1, 10, 30));
    });

    test('an empty database is an empty state, not an error', () async {
      final c = controller(
        MockClient((_) async => _json(200, _page([], lastSynced: null))),
      );
      await c.load();
      expect(c.isEmpty, isTrue);
      expect(c.items, isEmpty);
      expect(c.error, isNull);
      expect(c.lastSyncedAt, isNull);
      expect(c.hasMore, isFalse);
    });

    test(
      'loadMore appends the next page, skips duplicates, and stops at the end',
      () async {
        final pages = <String>[];
        final c = controller(
          MockClient((req) async {
            final p = req.url.queryParameters['page']!;
            pages.add(p);
            return p == '1'
                ? _json(
                    200,
                    _page(
                      [_row(1, 'a.com'), _row(2, 'b.com')],
                      perPage: 2,
                      total: 3,
                      totalPages: 2,
                    ),
                  )
                : _json(
                    200,
                    _page(
                      [_row(2, 'b.com'), _row(3, 'c.com')],
                      page: 2,
                      perPage: 2,
                      total: 3,
                      totalPages: 2,
                    ),
                  );
          }),
        );
        await c.load();
        await c.loadMore();
        await c.loadMore(); // no more pages: must not call again

        expect(c.items.map((d) => d.id), [1, 2, 3]);
        expect(c.hasMore, isFalse);
        expect(pages, ['1', '2']);
      },
    );

    test('a failed load shows an error and retry recovers', () async {
      var fail = true;
      final c = controller(
        MockClient(
          (_) async => fail
              ? _json(500, {'error': 'server_error'})
              : _json(200, _page([_row(1, 'a.com')])),
        ),
      );
      await c.load();
      expect(c.error, contains('server_error'));
      expect(c.items, isEmpty);
      expect(c.isEmpty, isFalse); // an error is not an empty list

      fail = false;
      await c.retry();
      expect(c.error, isNull);
      expect(c.items, hasLength(1));
    });

    test('a network failure has a friendly message', () async {
      final c = controller(MockClient((_) async => throw Exception('down')));
      await c.load();
      expect(c.error, contains('Cannot reach the server'));
    });

    test(
      'a failed load-more keeps the rows and retry fetches the same page',
      () async {
        var failPage2 = true;
        final c = controller(
          MockClient((req) async {
            if (req.url.queryParameters['page'] == '2') {
              return failPage2
                  ? _json(503, {'error': 'unavailable'})
                  : _json(
                      200,
                      _page(
                        [_row(3, 'c.com')],
                        page: 2,
                        perPage: 2,
                        total: 3,
                        totalPages: 2,
                      ),
                    );
            }
            return _json(
              200,
              _page(
                [_row(1, 'a.com'), _row(2, 'b.com')],
                perPage: 2,
                total: 3,
                totalPages: 2,
              ),
            );
          }),
        );
        await c.load();
        await c.loadMore();
        expect(c.items, hasLength(2));
        expect(c.loadMoreError, isNotNull);
        expect(c.error, isNull);

        failPage2 = false;
        await c.retry();
        expect(c.items.map((d) => d.id), [1, 2, 3]);
        expect(c.loadMoreError, isNull);
      },
    );

    test(
      'refresh replaces rows, and a failed refresh keeps the old ones',
      () async {
        var step = 0;
        final c = controller(
          MockClient((_) async {
            step++;
            if (step == 1) return _json(200, _page([_row(1, 'a.com')]));
            if (step == 2) return _json(200, _page([_row(9, 'z.com')]));
            return _json(500, {'error': 'server_error'});
          }),
        );
        await c.load();
        await c.refresh();
        expect(c.items.map((d) => d.id), [9]);

        await c.refresh();
        expect(c.items.map((d) => d.id), [9]);
        expect(c.error, isNotNull);
        expect(c.refreshing, isFalse);
      },
    );

    test('search is debounced to one request and resets to page 1', () async {
      final queries = <String?>[];
      final c = controller(
        MockClient((req) async {
          queries.add(req.url.queryParameters['q']);
          return _json(200, _page([_row(1, 'a.com')]));
        }),
      );
      c.setQuery('e');
      c.setQuery('ex');
      c.setQuery('exa');
      expect(queries, isEmpty);
      await Future<void>.delayed(const Duration(milliseconds: 60));
      expect(queries, ['exa']);
      expect(c.query, 'exa');
      c.dispose();
    });

    test(
      'a slow response for an old search never overwrites a newer one',
      () async {
        final slow = Completer<http.Response>();
        final c = controller(
          MockClient((req) async {
            return req.url.queryParameters['q'] == 'old'
                ? slow.future
                : _json(200, _page([_row(2, 'new.com')]));
          }),
        );
        c.query = 'old';
        final first = c.load();
        c.query = 'new';
        await c.load();
        slow.complete(_json(200, _page([_row(1, 'old.com')])));
        await first;

        expect(c.items.map((d) => d.domainName), ['new.com']);
      },
    );

    test('a response arriving after dispose is ignored', () async {
      final slow = Completer<http.Response>();
      final c = controller(MockClient((_) => slow.future));
      final f = c.load();
      c.dispose();
      slow.complete(_json(200, _page([_row(1, 'a.com')])));
      await f; // must not throw "used after being disposed"
    });
  });

  group('HomeScreen', () {
    Future<(AuthController, MemoryStorage)> signedIn(
      WidgetTester tester,
      MockClient client,
    ) async {
      final storage = MemoryStorage();
      final auth = _auth(client, storage);
      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.light,
          home: HomeScreen(auth: auth),
        ),
      );
      await tester.pumpAndSettle();
      // The app opens on the Dashboard; the list lives on the Domains screen.
      await tester.tap(find.byTooltip('Open navigation menu'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(ListTile, 'Domains'));
      await tester.pumpAndSettle();
      return (auth, storage);
    }

    testWidgets('shows name, status, expiry, missing flag and last sync', (
      tester,
    ) async {
      final semantics = tester.ensureSemantics();
      await signedIn(
        tester,
        MockClient(
          (_) async => _json(
            200,
            _page([
              _row(1, 'alpha.com'),
              _row(
                2,
                'beta.com',
                extra: {'is_missing': true, 'status': null, 'expires_at': null},
              ),
            ], total: 2),
          ),
        ),
      );

      expect(find.text('alpha.com'), findsOneWidget);
      expect(find.text('beta.com'), findsOneWidget);
      expect(find.textContaining('Active'), findsOneWidget);
      expect(find.textContaining('Expires 2030-01-15'), findsOneWidget);
      expect(find.textContaining('Status unknown'), findsOneWidget);
      expect(find.textContaining('Expiry unknown'), findsOneWidget);
      expect(find.byIcon(Icons.warning_amber), findsOneWidget);
      // The header shows the total; its accessible label carries the count
      // and the last sync ("2 domains, last synced 2026-05-01 …").
      expect(find.text('2'), findsOneWidget);
      expect(find.bySemanticsLabel(RegExp('^2 domains')), findsOneWidget);
      expect(
        find.bySemanticsLabel(RegExp('last synced 2026-05-01')),
        findsOneWidget,
      );
      semantics.dispose();
    });

    testWidgets('an empty database shows a neutral empty state', (
      tester,
    ) async {
      await signedIn(
        tester,
        MockClient((_) async => _json(200, _page([], lastSynced: null))),
      );
      expect(find.textContaining('No domains yet'), findsOneWidget);
      expect(find.text('Retry'), findsNothing);
    });

    testWidgets('an error shows Retry which loads the list', (tester) async {
      var fail = true;
      await signedIn(
        tester,
        MockClient(
          (_) async => fail
              ? _json(500, {'error': 'server_error'})
              : _json(200, _page([_row(1, 'alpha.com')])),
        ),
      );
      expect(find.textContaining('server_error'), findsOneWidget);

      fail = false;
      await tester.tap(find.text('Retry'));
      await tester.pumpAndSettle();
      expect(find.text('alpha.com'), findsOneWidget);
    });

    testWidgets('Load more appends the next page', (tester) async {
      await signedIn(
        tester,
        MockClient((req) async {
          return req.url.queryParameters['page'] == '2'
              ? _json(
                  200,
                  _page(
                    [_row(3, 'gamma.com')],
                    page: 2,
                    total: 2,
                    totalPages: 2,
                  ),
                )
              : _json(
                  200,
                  _page([_row(1, 'alpha.com')], total: 2, totalPages: 2),
                );
        }),
      );
      await tester.ensureVisible(find.text('Load more'));
      await tester.tap(find.text('Load more'));
      await tester.pumpAndSettle();
      expect(find.text('gamma.com'), findsOneWidget);
      expect(find.text('Load more'), findsNothing);
    });

    testWidgets('typing in the search box queries the backend', (tester) async {
      final seen = <String?>[];
      await signedIn(
        tester,
        MockClient((req) async {
          if (req.url.path.endsWith('expiry/summary')) {
            return _json(200, {
              'expired': 0,
              'within_7_days': 0,
              'within_30_days': 0,
              'within_60_days': 0,
              'within_90_days': 0,
              'unknown': 0,
            });
          }
          if (req.url.path.endsWith('/balance')) {
            // Opening Domains refreshes the balance; not a list request.
            return _json(500, {'error': 'server_error'});
          }
          seen.add(req.url.queryParameters['q']);
          return _json(200, _page([_row(1, 'alpha.com')]));
        }),
      );
      // The search field is behind the "Search domains" toggle.
      expect(find.byType(TextField), findsNothing);
      await tester.tap(find.byTooltip('Search domains'));
      await tester.pumpAndSettle();
      expect(find.byType(TextField), findsOneWidget);
      await tester.enterText(find.byType(TextField), 'alp');
      await tester.pump(const Duration(milliseconds: 500));
      await tester.pumpAndSettle();
      expect(seen, [null, 'alp']);
    });

    testWidgets('logout still signs the user out', (tester) async {
      final (auth, storage) = await signedIn(
        tester,
        MockClient((_) async => _json(200, _page([_row(1, 'a.com')]))),
      );
      await tester.tap(find.byTooltip('Log out'));
      await tester.pumpAndSettle();
      expect(auth.status, AuthStatus.signedOut);
      expect(storage.tokens, isNull);
    });
  });
}

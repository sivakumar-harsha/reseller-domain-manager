import 'dart:convert';

import 'package:domain_manager/auth/auth_controller.dart';
import 'package:domain_manager/auth/token_storage.dart';
import 'package:domain_manager/core/api_client.dart';
import 'package:domain_manager/domains/domain_controller.dart';
import 'package:domain_manager/domains/expiry.dart';
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

Map<String, Object?> _row(int id, String name) => {
  'id': id,
  'domain_name': name,
  'status': 'Active',
  'expires_at': '2030-01-15T00:00:00Z',
  'auto_renew': null,
  'privacy_enabled': true,
  'registrar_lock': false,
  'is_missing': false,
  'last_synced_at': '2026-05-01T10:30:00Z',
};

Map<String, Object?> _page(
  List<Map<String, Object?>> rows, {
  int page = 1,
  int perPage = 20,
  int total = 0,
  int totalPages = 1,
}) => {
  'data': rows,
  'meta': {
    'page': page,
    'per_page': perPage,
    'total': total,
    'total_pages': totalPages,
  },
  'last_synced_at': '2026-05-01T10:30:00Z',
};

const _summaryJson = {
  'expired': 1,
  'within_7_days': 2,
  'within_30_days': 3,
  'within_60_days': 4,
  'within_90_days': 5,
  'unknown': 6,
  'as_of_date': '2026-10-30',
  'timezone': 'Asia/Kolkata',
};

ApiClient _api(MockClient client) {
  final auth = AuthController(
    api: ApiClient(client: client, baseUrl: 'http://test/api/v1/'),
    storage: _Storage(),
  );
  auth.status = AuthStatus.signedIn;
  return auth.api;
}

void main() {
  group('ExpirySummary', () {
    test('parses every field', () {
      final s = ExpirySummary.fromJson({..._summaryJson});
      expect(
        (
          s.expired,
          s.within7Days,
          s.within30Days,
          s.within60Days,
          s.within90Days,
          s.unknown,
        ),
        (1, 2, 3, 4, 5, 6),
      );
      expect((s.asOfDate, s.timezone), ('2026-10-30', 'Asia/Kolkata'));
    });

    test('countFor maps each filter and has none for all', () {
      final s = ExpirySummary.fromJson({..._summaryJson});
      expect(s.countFor(ExpiryFilter.all), isNull);
      expect(
        [for (final f in ExpiryFilter.values.skip(1)) s.countFor(f)],
        [1, 2, 3, 4, 5, 6],
      );
    });

    test('optional text fields may be missing', () {
      final s = ExpirySummary.fromJson(
        {..._summaryJson}
          ..remove('as_of_date')
          ..['timezone'] = 7,
      );
      expect((s.asOfDate, s.timezone), (null, null));
    });

    test(
      'a missing, negative, fractional or non-numeric count is rejected',
      () {
        for (final key in _summaryJson.keys.where(
          (k) => k != 'as_of_date' && k != 'timezone',
        )) {
          for (final bad in [null, -1, 1.5, '3']) {
            final json = {..._summaryJson, key: bad};
            expect(
              () => ExpirySummary.fromJson(json),
              throwsFormatException,
              reason: '$key=$bad',
            );
          }
          expect(
            () => ExpirySummary.fromJson({..._summaryJson}..remove(key)),
            throwsFormatException,
            reason: 'missing $key',
          );
        }
      },
    );
  });

  group('API values', () {
    test('filters and sorts use the backend whitelist values', () {
      expect(ExpiryFilter.values.map((f) => f.apiValue), [
        'all',
        'expired',
        'within_7_days',
        'within_30_days',
        'within_60_days',
        'within_90_days',
        'unknown',
      ]);
      expect(DomainSort.values.map((s) => s.apiValue), [
        null,
        'expiry_asc',
        'expiry_desc',
        'name_asc',
      ]);
    });
  });

  group('ApiClient', () {
    test('default list request sends no expiry or sort', () async {
      late http.Request seen;
      final api = _api(
        MockClient((req) async {
          seen = req;
          return _json(200, _page([]));
        }),
      );
      await api.listDomains();
      expect(seen.url.queryParameters, {'page': '1', 'per_page': '20'});
    });

    test('every filter and sort is sent with search and paging', () async {
      final seen = <Map<String, String>>[];
      final api = _api(
        MockClient((req) async {
          seen.add(req.url.queryParameters);
          return _json(200, _page([]));
        }),
      );
      for (final f in ExpiryFilter.values.skip(1)) {
        await api.listDomains(q: 'x', page: 3, perPage: 5, expiry: f);
      }
      for (final s in DomainSort.values.skip(1)) {
        await api.listDomains(sort: s);
      }
      await api.listDomains(
        expiry: ExpiryFilter.within30,
        sort: DomainSort.expiryDesc,
      );

      expect(seen.take(6).map((q) => q['expiry']), [
        'expired',
        'within_7_days',
        'within_30_days',
        'within_60_days',
        'within_90_days',
        'unknown',
      ]);
      expect(seen.first, {
        'q': 'x',
        'expiry': 'expired',
        'page': '3',
        'per_page': '5',
      });
      expect(seen.skip(6).take(3).map((q) => q['sort']), [
        'expiry_asc',
        'expiry_desc',
        'name_asc',
      ]);
      expect(seen.last['expiry'], 'within_30_days');
      expect(seen.last['sort'], 'expiry_desc');
    });

    test('expirySummary calls the endpoint with the bearer token', () async {
      late http.Request seen;
      final api = _api(
        MockClient((req) async {
          seen = req;
          return _json(200, _summaryJson);
        }),
      );
      final s = await api.expirySummary();
      expect(
        (seen.method, seen.url.path, seen.headers['Authorization']),
        ('GET', '/api/v1/expiry/summary', 'Bearer a1'),
      );
      expect(s.within90Days, 5);
    });

    test('a malformed summary is a bad_response', () async {
      final api = _api(MockClient((_) async => _json(200, {'expired': 'x'})));
      await expectLater(
        api.expirySummary(),
        throwsA(
          isA<ApiException>().having((e) => e.code, 'code', 'bad_response'),
        ),
      );
    });

    test('without a session the summary fails with 401', () async {
      final api = ApiClient(
        client: MockClient((_) async => _json(200, _summaryJson)),
        baseUrl: 'http://test/api/v1/',
      );
      await expectLater(
        api.expirySummary(),
        throwsA(isA<ApiException>().having((e) => e.statusCode, 'status', 401)),
      );
    });
  });

  group('DomainController filter and sort', () {
    late List<Map<String, String>> requests;

    DomainController build({int perPage = 1}) {
      requests = [];
      return DomainController(
        api: _api(
          MockClient((req) async {
            if (req.url.path.endsWith('expiry/summary')) {
              return _json(200, _summaryJson);
            }
            requests.add(req.url.queryParameters);
            final p = int.parse(req.url.queryParameters['page']!);
            return _json(
              200,
              _page(
                [_row(p, 'd$p.com')],
                page: p,
                perPage: 1,
                total: 3,
                totalPages: 3,
              ),
            );
          }),
        ),
        perPage: perPage,
        debounce: const Duration(milliseconds: 10),
      );
    }

    test('changing the filter reloads page 1 and keeps the search', () async {
      final c = build();
      c.query = 'ab';
      await c.load();
      await c.loadMore();
      expect(requests.last['page'], '2');

      await c.setExpiry(ExpiryFilter.within7);

      expect(requests.last, {
        'q': 'ab',
        'expiry': 'within_7_days',
        'page': '1',
        'per_page': '1',
      });
      expect(c.items.map((d) => d.id), [1]); // replaced, not appended
      expect(c.expiry, ExpiryFilter.within7);
    });

    test(
      'changing the sort reloads page 1 and keeps filter and search',
      () async {
        final c = build();
        c.query = 'ab';
        await c.setExpiry(ExpiryFilter.expired);
        await c.loadMore();
        await c.setSort(DomainSort.nameAsc);

        expect(requests.last, {
          'q': 'ab',
          'expiry': 'expired',
          'sort': 'name_asc',
          'page': '1',
          'per_page': '1',
        });
        expect(c.items.map((d) => d.id), [1]);
      },
    );

    test('later pages keep the filter and sort', () async {
      final c = build();
      await c.setExpiry(ExpiryFilter.within30);
      await c.setSort(DomainSort.expiryDesc);
      await c.loadMore();

      expect(requests.last, {
        'expiry': 'within_30_days',
        'sort': 'expiry_desc',
        'page': '2',
        'per_page': '1',
      });
    });

    test('a debounced search keeps the filter and sort', () async {
      final c = build();
      await c.setExpiry(ExpiryFilter.unknown);
      await c.setSort(DomainSort.expiryAsc);
      c.setQuery('zz');
      await Future<void>.delayed(const Duration(milliseconds: 50));

      expect(requests.last, {
        'q': 'zz',
        'expiry': 'unknown',
        'sort': 'expiry_asc',
        'page': '1',
        'per_page': '1',
      });
    });

    test('selecting the current value does not reload', () async {
      final c = build();
      await c.load();
      final before = requests.length;
      await c.setExpiry(ExpiryFilter.all);
      await c.setSort(DomainSort.defaultOrder);
      expect(requests.length, before);
    });

    test(
      'a failed filtered load reports the error through the existing path',
      () async {
        final c = DomainController(
          api: _api(
            MockClient((_) async => _json(400, {'error': 'invalid_request'})),
          ),
          debounce: const Duration(milliseconds: 10),
        );
        await c.setExpiry(ExpiryFilter.expired);
        expect(c.items, isEmpty);
        expect(
          c.error,
          DomainController.messageFor(ApiException(400, 'invalid_request')),
        );
        expect(c.loading, isFalse);
      },
    );
  });

  group('DomainController summary', () {
    test('loadSummary stores the counts and does not touch the list', () async {
      final c = DomainController(
        api: _api(
          MockClient(
            (req) async => req.url.path.endsWith('expiry/summary')
                ? _json(200, _summaryJson)
                : _json(200, _page([_row(1, 'a.com')], total: 1)),
          ),
        ),
      );
      await c.load();
      await c.loadSummary();

      expect(c.summary!.expired, 1);
      expect(c.summaryError, isNull);
      expect(c.summaryLoading, isFalse);
      expect(c.items.map((d) => d.id), [1]);
      expect(c.error, isNull);
    });

    test(
      'a failed reload keeps the previous counts and sets an error',
      () async {
        var fail = false;
        final c = DomainController(
          api: _api(
            MockClient(
              (_) async => fail
                  ? _json(500, {'error': 'server_error'})
                  : _json(200, _summaryJson),
            ),
          ),
        );
        await c.loadSummary();
        fail = true;
        await c.loadSummary();

        expect(c.summary!.within90Days, 5);
        expect(c.summaryError, isNotNull);
        expect(c.summaryLoading, isFalse);
      },
    );
  });
}

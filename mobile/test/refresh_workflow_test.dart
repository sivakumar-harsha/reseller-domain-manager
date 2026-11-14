import 'dart:async';
import 'dart:convert';

import 'package:domain_manager/auth/auth_controller.dart';
import 'package:domain_manager/auth/token_storage.dart';
import 'package:domain_manager/core/api_client.dart';
import 'package:domain_manager/domains/auto_refresh.dart';
import 'package:domain_manager/domains/balance.dart';
import 'package:domain_manager/domains/bulk_sync.dart';
import 'package:domain_manager/domains/refresh.dart';
import 'package:domain_manager/screens/domain_detail_screen.dart';
import 'package:domain_manager/screens/home_screen.dart';
import 'package:domain_manager/theme/app_theme.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

// No test here reaches a real server: every request goes to a fake transport.

class _Storage implements TokenStorage {
  @override
  Future<TokenPair?> read() async =>
      const TokenPair(accessToken: 'a1', refreshToken: 'r1');
  @override
  Future<void> write(TokenPair tokens) async {}
  @override
  Future<void> clear() async {}
}

class _Stamp implements RefreshStampStore {
  DateTime? value;
  @override
  Future<DateTime?> read() async => value;
  @override
  Future<void> write(DateTime v) async => value = v;
  @override
  Future<void> clear() async => value = null;
}

http.Response _json(int status, Object body) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

const _balance = {
  'selling_balance': '6244.40',
  'selling_currency': 'USD',
  'accounting_balance': '6000.00',
  'accounting_currency': 'USD',
  'selling_locked_balance': null,
  'accounting_locked_balance': null,
};

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

Map<String, Object?> _page() => {
  'data': [_row(1, 'alpha.com')],
  'meta': {'page': 1, 'per_page': 20, 'total': 1, 'total_pages': 1},
  'last_synced_at': '2026-05-01T10:30:00Z',
};

const _summary = {
  'expired': 0,
  'within_7_days': 0,
  'within_30_days': 0,
  'within_60_days': 0,
  'within_90_days': 0,
  'unknown': 0,
  'as_of_date': '2026-11-14',
  'timezone': 'UTC',
};

Map<String, Object?> _batch({
  String status = 'running',
  int total = 1,
  int processed = 0,
}) => {
  'batch_id': 'abcdef0123456789',
  'status': status,
  'phase': 'enrich',
  'total': total,
  'processed': processed,
  'succeeded': processed,
  'failed': 0,
  'skipped': 0,
  'discovery': {'state': 'skipped'},
};

/// A scripted fake backend that records every request as "METHOD path".
class _Server {
  final calls = <String>[];
  final bodies = <String, String>{};

  /// Inventory sync response; a completer lets a test hold it open.
  Future<http.Response> Function() inventory = () async => _json(200, {
    'status': 'success',
    'seen': 1,
    'new': 0,
    'updated': 0,
    'unchanged': 1,
  });
  Future<http.Response> Function() balance = () async => _json(200, _balance);

  /// `nothing_to_do` unless a test supplies a batch.
  Future<http.Response> Function() startBatch = () async =>
      _json(409, {'error': 'nothing_to_do'});

  int count(String call) => calls.where((c) => c == call).length;
  int countWhere(bool Function(String) test) => calls.where(test).length;

  Future<http.Response> handle(http.Request req) async {
    final path = req.url.path.replaceFirst('/api/v1/', '');
    final key = '${req.method} $path';
    calls.add(key);
    bodies[key] = req.body;
    switch (key) {
      case 'POST inventory/sync':
        return inventory();
      case 'GET balance':
        return balance();
      case 'GET domains':
        return _json(200, _page());
      case 'GET expiry/summary':
        return _json(200, _summary);
      case 'POST enrichment/batches':
        return startBatch();
      case 'GET enrichment/eligible':
        return _json(200, {'mode': 'refresh', 'eligible': 42});
      case 'POST enrichment/batches/abcdef0123456789/step':
        return _json(200, _batch(status: 'completed', processed: 1));
    }
    return _json(404, {'error': 'not_found'});
  }
}

class _Env {
  _Env(this.server) {
    auth = AuthController(
      api: ApiClient(
        client: MockClient(server.handle),
        baseUrl: 'http://test/api/v1/',
      ),
      storage: _Storage(),
    )..status = AuthStatus.signedIn;
    balance = BalanceController(api: auth.api);
    bulk = BulkSyncController(api: auth.api);
    inventory = AutoRefreshController(api: auth.api, store: stamp);
    refresh = RefreshController(
      inventory: inventory,
      balance: balance,
      bulk: bulk,
      reload: () async {
        reloads++;
        // The real reload hits the local list and summary endpoints.
        await auth.api.listDomainsRaw();
      },
    );
  }

  final _Server server;
  final stamp = _Stamp();
  late final AuthController auth;
  late final BalanceController balance;
  late final BulkSyncController bulk;
  late final AutoRefreshController inventory;
  late final RefreshController refresh;
  int reloads = 0;

  void dispose() {
    refresh.dispose();
    bulk.dispose();
    balance.dispose();
    inventory.dispose();
  }
}

extension on ApiClient {
  /// Stand-in for the local list reload used by the controller-level tests.
  Future<void> listDomainsRaw() => expirySummary().then((_) {});
}

Future<void> _settle() async {
  for (var i = 0; i < 20; i++) {
    await Future<void>.delayed(Duration.zero);
  }
}

void main() {
  group('Refresh', () {
    test(
      'fetches inventory and balance, then reloads the local data',
      () async {
        final s = _Server();
        final e = _Env(s);
        addTearDown(e.dispose);

        await e.refresh.refresh();

        expect(s.count('POST inventory/sync'), 1);
        expect(s.count('GET balance'), 1);
        expect(e.reloads, 1);
        // The reload happens after the sync, never before it.
        expect(
          s.calls.indexOf('GET expiry/summary'),
          greaterThan(s.calls.indexOf('POST inventory/sync')),
        );
        expect(e.refresh.lastInventoryAt, isNotNull);
        expect(e.refresh.lastBalanceAt, isNotNull);
        expect(e.refresh.messageIsError, isFalse);
        expect(e.refresh.message, contains('refreshed'));
        expect(e.balance.balance?.selling, 'USD 6,244.40');
      },
    );

    test('inventory and balance run independently, at the same time', () async {
      final s = _Server();
      final inv = Completer<http.Response>();
      final bal = Completer<http.Response>();
      s.inventory = () => inv.future;
      s.balance = () => bal.future;
      final e = _Env(s);
      addTearDown(e.dispose);

      final run = e.refresh.refresh();
      await _settle();
      // Both are in flight before either has answered.
      expect(s.count('POST inventory/sync'), 1);
      expect(s.count('GET balance'), 1);
      expect(e.refresh.refreshing, isTrue);

      // The balance finishing first does not release the refresh.
      bal.complete(_json(200, _balance));
      await _settle();
      expect(e.refresh.refreshing, isTrue);
      expect(e.reloads, 0, reason: 'reload waits for the inventory sync');

      inv.complete(_json(200, {'status': 'success'}));
      await run;
      expect(e.refresh.refreshing, isFalse);
      expect(e.reloads, 1);
    });

    test('a balance failure does not invalidate a successful inventory '
        'refresh', () async {
      final s = _Server();
      s.balance = () async => _json(502, {'error': 'provider_error'});
      final e = _Env(s);
      addTearDown(e.dispose);

      await e.refresh.refresh();

      expect(e.balance.unavailable, isTrue);
      expect(e.balance.balance, isNull);
      expect(e.refresh.lastInventoryAt, isNotNull);
      expect(e.refresh.lastBalanceAt, isNull);
      expect(e.reloads, 1);
      expect(e.refresh.message, contains('Balance is unavailable'));
      expect(e.stamp.value, isNotNull);
    });

    test('an inventory failure keeps existing data and is not shown as '
        'synchronised', () async {
      final s = _Server();
      final e = _Env(s);
      addTearDown(e.dispose);
      await e.refresh.refresh();
      await _settle(); // the background details check ends first
      final before = e.refresh.lastInventoryAt;
      final stampBefore = e.stamp.value;
      s.calls.clear();

      s.inventory = () async => _json(502, {'error': 'provider_error'});
      await e.refresh.refresh();

      expect(e.refresh.messageIsError, isTrue);
      expect(e.refresh.message, contains('Showing saved data'));
      expect(e.reloads, 1, reason: 'no reload: the cached rows stay as is');
      expect(e.refresh.lastInventoryAt, before, reason: 'time not advanced');
      expect(e.stamp.value, stampBefore);
      expect(s.countWhere((c) => c.startsWith('POST enrichment')), 0);
      // The balance is still requested and still works.
      expect(s.count('GET balance'), 1);
    });

    test('a failure before any success records no synchronised time', () async {
      final s = _Server();
      s.inventory = () async => throw Exception('offline');
      final e = _Env(s);
      addTearDown(e.dispose);

      await e.refresh.refresh();

      expect(e.refresh.lastInventoryAt, isNull);
      expect(e.stamp.value, isNull);
      expect(e.refresh.messageIsError, isTrue);
    });

    test('a sync already running on the server is not reported as a '
        'refresh', () async {
      final s = _Server();
      s.inventory = () async => _json(409, {'error': 'sync_in_progress'});
      final e = _Env(s);
      addTearDown(e.dispose);

      await e.refresh.refresh();

      expect(e.refresh.lastInventoryAt, isNull);
      expect(e.refresh.messageIsError, isTrue);
      expect(e.refresh.message, contains('already running'));
      expect(s.countWhere((c) => c.startsWith('POST enrichment')), 0);
    });

    test('while a details batch runs, Refresh makes no inventory request '
        'and no new batch', () async {
      final s = _Server();
      final e = _Env(s);
      addTearDown(e.dispose);
      e.bulk.phase = BulkPhase.running;

      await e.refresh.refresh();

      expect(s.count('POST inventory/sync'), 0);
      expect(s.countWhere((c) => c.startsWith('POST enrichment')), 0);
      expect(e.reloads, 1);
      expect(e.refresh.message, contains('still syncing'));
    });
  });

  group('details of new domains', () {
    test('missing details are enriched in the background in missing mode '
        'only', () async {
      final s = _Server();
      s.startBatch = () async => _json(200, _batch());
      final e = _Env(s);
      addTearDown(e.dispose);

      await e.refresh.refresh();
      await _settle();

      expect(s.count('POST enrichment/batches'), 1);
      expect(
        jsonDecode(s.bodies['POST enrichment/batches']!),
        {'mode': 'missing'},
        reason: 'never the refresh-everything mode',
      );
      expect(s.count('POST enrichment/batches/abcdef0123456789/step'), 1);
      expect(e.bulk.progress?.status, 'completed');
      // The refresh itself did not wait for the batch.
      expect(e.refresh.refreshing, isFalse);
    });

    test('already-enriched domains are not fetched again', () async {
      final s = _Server(); // start answers nothing_to_do
      final e = _Env(s);
      addTearDown(e.dispose);

      await e.refresh.refresh();
      await _settle();

      expect(s.count('POST enrichment/batches'), 1);
      expect(s.countWhere((c) => c.contains('/step')), 0);
      expect(s.countWhere((c) => c.contains('/enrich')), 0);
      expect(e.bulk.phase, BulkPhase.idle);
      expect(e.bulk.message, isNull, reason: 'nothing to do is not announced');
    });

    test('no batch is started when the inventory refresh failed', () async {
      final s = _Server();
      s.inventory = () async => _json(500, {'error': 'server_error'});
      final e = _Env(s);
      addTearDown(e.dispose);

      await e.refresh.refresh();
      await _settle();

      expect(s.countWhere((c) => c.startsWith('POST enrichment')), 0);
    });

    test('a paused batch is not replaced by a new one', () async {
      final s = _Server();
      final e = _Env(s);
      addTearDown(e.dispose);
      e.bulk.phase = BulkPhase.paused;

      await e.refresh.refresh();
      await _settle();

      expect(s.countWhere((c) => c.startsWith('POST enrichment')), 0);
    });
  });

  group('no duplicate requests', () {
    test('simultaneous refreshes share one inventory sync and one balance '
        'request', () async {
      final s = _Server();
      final inv = Completer<http.Response>();
      s.inventory = () => inv.future;
      final e = _Env(s);
      addTearDown(e.dispose);

      // Resume on several screens, plus a pull-to-refresh, all at once.
      final runs = [
        e.refresh.refresh(manual: false),
        e.refresh.refresh(manual: false),
        e.refresh.refresh(),
        e.refresh.refresh(manual: false),
      ];
      await _settle();
      inv.complete(_json(200, {'status': 'success'}));
      await Future.wait(runs);

      expect(s.count('POST inventory/sync'), 1);
      expect(s.count('GET balance'), 1);
      expect(e.reloads, 1);
    });

    test('resume right after a refresh requests nothing', () async {
      final s = _Server();
      final e = _Env(s);
      addTearDown(e.dispose);
      await e.refresh.refresh();
      await _settle();
      s.calls.clear();

      await e.refresh.refresh(manual: false); // resume

      expect(s.count('POST inventory/sync'), 0, reason: '15-minute interval');
      expect(s.count('GET balance'), 0, reason: 'balance is fresh');
      expect(e.refresh.message, isNull);
    });

    test(
      'resume after the balance window refreshes only the balance',
      () async {
        final s = _Server();
        var now = DateTime.utc(2026, 11, 14, 9);
        final e = _Env(s);
        addTearDown(e.dispose);
        final clocked = RefreshController(
          inventory: AutoRefreshController(
            api: e.auth.api,
            store: e.stamp,
            now: () => now,
          ),
          balance: BalanceController(api: e.auth.api, now: () => now),
          bulk: e.bulk,
          reload: () async {},
        );
        addTearDown(clocked.dispose);

        await clocked.refresh();
        s.calls.clear();
        now = now.add(const Duration(minutes: 2));
        await clocked.refresh(manual: false);

        expect(s.count('POST inventory/sync'), 0, reason: 'inventory not due');
        expect(s.count('GET balance'), 1);

        now = now.add(const Duration(minutes: 16));
        s.calls.clear();
        await clocked.refresh(manual: false);
        expect(s.count('POST inventory/sync'), 1, reason: 'due again');
      },
    );

    test(
      'after a failed automatic attempt the cooldown stops repeats',
      () async {
        final s = _Server();
        s.inventory = () async => _json(500, {'error': 'server_error'});
        final e = _Env(s);
        addTearDown(e.dispose);

        await e.refresh.refresh(manual: false);
        await e.refresh.refresh(manual: false);
        await e.refresh.refresh(manual: false);

        expect(s.count('POST inventory/sync'), 1);
      },
    );

    test('balance loadOnce joins an in-flight request and skips a fresh '
        'value', () async {
      final s = _Server();
      final bal = Completer<http.Response>();
      s.balance = () => bal.future;
      final e = _Env(s);
      addTearDown(e.dispose);

      final a = e.balance.loadOnce();
      final b = e.balance.loadOnce();
      bal.complete(_json(200, _balance));
      await Future.wait([a, b]);
      await e.balance.loadOnce();

      expect(s.count('GET balance'), 1);
    });
  });

  group('Domain Details refresh', () {
    Future<_Server> openDetail(
      WidgetTester t, {
      Future<http.Response> Function()? enrich,
    }) async {
      final s = _Server();
      var enrichCalls = 0;
      final client = MockClient((req) async {
        final path = req.url.path.replaceFirst('/api/v1/', '');
        s.calls.add('${req.method} $path');
        if (path == 'domains/1/details') {
          return _json(200, {
            ..._row(1, 'alpha.com'),
            'nameservers': ['ns1.example.net'],
            'rc_order_id': '1001',
            'rc_customer_id': '2002',
            'registered_at': null,
            'first_seen_at': null,
            'details_synced_at': enrichCalls > 0
                ? '2026-11-14T09:00:00Z'
                : null,
            'contacts': {
              'registrant': null,
              'admin': null,
              'tech': null,
              'billing': null,
            },
          });
        }
        if (path == 'domains/1/enrich') {
          enrichCalls++;
          return enrich != null
              ? enrich()
              : _json(200, {'domain_id': 1, 'status': 'enriched'});
        }
        return _json(404, {'error': 'not_found'});
      });
      final auth = AuthController(
        api: ApiClient(client: client, baseUrl: 'http://test/api/v1/'),
        storage: _Storage(),
      )..status = AuthStatus.signedIn;
      await t.pumpWidget(
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
      await t.pumpAndSettle();
      return s;
    }

    final refreshButton = find.byKey(const ValueKey('refresh-details'));

    testWidgets('enriches only this domain, then reloads the local details', (
      t,
    ) async {
      final s = await openDetail(t);
      expect(refreshButton, findsOneWidget);
      s.calls.clear();

      await t.tap(refreshButton);
      await t.pumpAndSettle();

      expect(s.calls, ['POST domains/1/enrich', 'GET domains/1/details']);
      expect(find.byKey(const ValueKey('details-refresh-error')), findsNothing);
    });

    testWidgets('shows loading and refuses a second simultaneous request', (
      t,
    ) async {
      final hold = Completer<http.Response>();
      final s = await openDetail(t, enrich: () => hold.future);
      s.calls.clear();

      await t.tap(refreshButton);
      await t.pump();
      expect(find.byKey(const ValueKey('details-refreshing')), findsOneWidget);
      // The button is disabled while the request runs.
      await t.tap(refreshButton, warnIfMissed: false);
      await t.pump();
      expect(s.count('POST domains/1/enrich'), 1);

      hold.complete(_json(200, {'status': 'enriched'}));
      await t.pumpAndSettle();
      expect(find.byKey(const ValueKey('details-refreshing')), findsNothing);
      expect(s.count('POST domains/1/enrich'), 1);
      expect(s.count('GET domains/1/details'), 1);
    });

    testWidgets('shows an error and keeps the data when the provider fails', (
      t,
    ) async {
      final s = await openDetail(
        t,
        enrich: () async => _json(502, {'error': 'provider_error'}),
      );
      s.calls.clear();

      await t.tap(refreshButton);
      await t.pumpAndSettle();

      expect(
        find.byKey(const ValueKey('details-refresh-error')),
        findsOneWidget,
      );
      expect(
        find.textContaining('could not return the details'),
        findsOneWidget,
      );
      expect(find.text('alpha.com'), findsWidgets);
      // No reload after a failed enrichment, and never a full inventory sync.
      expect(s.calls, ['POST domains/1/enrich']);
    });

    testWidgets('never starts an inventory sync or a batch', (t) async {
      final s = await openDetail(t);
      await t.tap(refreshButton);
      await t.pumpAndSettle();

      expect(s.countWhere((c) => c.contains('inventory')), 0);
      expect(s.countWhere((c) => c.contains('enrichment')), 0);
    });
  });

  group('Home screen', () {
    Future<_Server> openHome(
      WidgetTester t, {
      AutoRefreshController? auto,
    }) async {
      final s = _Server();
      final auth = AuthController(
        api: ApiClient(
          client: MockClient(s.handle),
          baseUrl: 'http://test/api/v1/',
        ),
        storage: _Storage(),
      )..status = AuthStatus.signedIn;
      auto ??= AutoRefreshController(api: auth.api, store: _Stamp());
      await t.pumpWidget(
        MaterialApp(
          theme: AppTheme.light,
          home: HomeScreen(auth: auth, autoRefresh: auto),
        ),
      );
      await t.pumpAndSettle();
      return s;
    }

    Future<void> openDomains(WidgetTester t) async {
      await t.tap(find.byTooltip('Open navigation menu'));
      await t.pumpAndSettle();
      await t.tap(find.text('Domains').last);
      await t.pumpAndSettle();
    }

    testWidgets('launch runs one inventory sync and one balance request', (
      t,
    ) async {
      final s = await openHome(t);

      expect(s.count('POST inventory/sync'), 1);
      expect(s.count('GET balance'), 1);
      // Opening Domains right away does not request the balance again.
      await openDomains(t);
      expect(s.count('GET balance'), 1);
      expect(s.count('POST inventory/sync'), 1);
    });

    testWidgets('pull-to-refresh on the Dashboard syncs inventory and balance '
        'and shows the status', (t) async {
      final s = await openHome(t);
      s.calls.clear();

      await t.fling(find.byType(ListView).first, const Offset(0, 400), 1000);
      await t.pumpAndSettle();

      expect(s.count('POST inventory/sync'), 1);
      expect(s.count('GET balance'), 1);
      expect(s.count('GET domains'), 1);
      expect(s.count('GET expiry/summary'), 1);
      expect(find.byKey(const ValueKey('refresh-status')), findsWidgets);
      expect(find.textContaining('Domains synced 20'), findsWidgets);
      expect(find.textContaining('Balance 20'), findsWidgets);
      // Normal refresh never re-syncs the details of existing domains.
      expect(s.countWhere((c) => c.contains('refresh')), 0);
    });

    testWidgets('the Sync button is gone; Re-sync all is in the menu and '
        'needs confirmation', (t) async {
      final s = await openHome(t);
      await openDomains(t);
      expect(find.text('Sync'), findsNothing);
      s.calls.clear();

      await t.tap(find.byKey(const ValueKey('more-actions')));
      await t.pumpAndSettle();
      expect(find.text('Re-sync all domain details'), findsOneWidget);
      await t.tap(find.text('Re-sync all domain details'));
      await t.pumpAndSettle();

      // A dialog, with the number of provider requests it would make.
      expect(find.byKey(const ValueKey('resync-all-dialog')), findsOneWidget);
      expect(find.textContaining('42 domains'), findsOneWidget);
      expect(s.count('POST enrichment/batches'), 0);

      // Cancelling sends nothing.
      await t.tap(find.text('Cancel'));
      await t.pumpAndSettle();
      expect(s.count('POST enrichment/batches'), 0);

      // Confirming starts a batch in refresh mode.
      s.startBatch = () async => _json(200, _batch(total: 42));
      await t.tap(find.byKey(const ValueKey('more-actions')));
      await t.pumpAndSettle();
      await t.tap(find.text('Re-sync all domain details'));
      await t.pumpAndSettle();
      await t.tap(find.byKey(const ValueKey('resync-all-confirm')));
      await t.pumpAndSettle();
      expect(s.count('POST enrichment/batches'), 1);
      expect(jsonDecode(s.bodies['POST enrichment/batches']!), {
        'mode': 'refresh',
      });
    });
  });
}

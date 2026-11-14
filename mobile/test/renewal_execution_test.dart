import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:domain_manager/auth/auth_controller.dart';
import 'package:domain_manager/auth/token_storage.dart';
import 'package:domain_manager/core/api_client.dart';
import 'package:domain_manager/domains/renewal_batch.dart';
import 'package:domain_manager/domains/renewal_execution.dart';
import 'package:domain_manager/screens/renewal_batch_screen.dart';
import 'package:domain_manager/screens/renewal_confirm_screen.dart';
import 'package:domain_manager/theme/app_theme.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

// Every test here uses a mock HTTP client. There is no network, no backend and
// no ResellerClub traffic; a renewal POST only ever reaches the scripted fake.

class _Storage implements TokenStorage {
  TokenPair? tokens = const TokenPair(accessToken: 'a1', refreshToken: 'r1');
  @override
  Future<TokenPair?> read() async => tokens;
  @override
  Future<void> write(TokenPair tokens) async => this.tokens = tokens;
  @override
  Future<void> clear() async => tokens = null;
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

Map<String, Object?> _item({String state = 'pending', int id = 11}) => {
  'id': id,
  'domain_id': 5,
  'domain_name': 'example.com',
  'state': state,
  'expiry_at_request': '2030-01-15T00:00:00Z',
  'renewal_amount': '1219.00',
  'gst_amount': '219.42',
  'vat_amount': '0.00',
  'total_amount': '1438.42',
  'currency': 'INR',
  'provider_action_id': null,
  'provider_invoice_id': null,
  'error_code': null,
};

Map<String, Object?> _batch({
  String state = 'pending',
  String option = 'KeepInvoice',
  int years = 1,
  List<Map<String, Object?>>? items,
}) {
  final rows = items ?? [_item(state: state)];
  return {
    'id': 7,
    'status': 'pending',
    'invoice_option': option,
    'years': years,
    'item_counts': {
      'pending': 0,
      'submitting': 0,
      'succeeded': 0,
      'failed': 0,
      'uncertain': 0,
    },
    'created_at': '2026-11-10T09:30:00Z',
    'updated_at': '2026-11-10T09:30:00Z',
    'finished_at': null,
    'items': rows,
  };
}

Map<String, Object?> _executed(String state, {String? error}) => {
  'replayed': false,
  'item': {
    'id': 11,
    'state': state,
    'error_code': error,
    'provider_action_id': state == 'succeeded' ? '98765' : null,
    'provider_invoice_id': null,
  },
  'batch': _batch(state: state),
  'amount': {'estimated_total': '1438.42', 'currency': 'INR'},
  'next': state == 'uncertain' ? 'reconcile' : 'none',
};

typedef _Reply = Future<http.Response> Function();

/// Scripted fake backend. Renewal POSTs are consumed from [renewReplies] in
/// order; running out of replies fails the test (an unexpected extra POST).
class _Server {
  final requests = <http.Request>[];
  final renewReplies = <_Reply>[];
  final unexpectedRenews = <String>[];

  /// What the stored batch looks like for GET and reconcile.
  String storedState = 'pending';
  String storedOption = 'KeepInvoice';
  http.Response? balance;
  http.Response? detailOverride;
  http.Response? reconcileOverride;
  int refreshes = 0;
  bool refreshOk = true;

  /// Set by the test: called with the request just before a renewal reply.
  void Function(http.Request)? onRenew;

  List<http.Request> get renews => [
    for (final r in requests)
      if (r.method == 'POST' && r.url.path.endsWith('/renew')) r,
  ];
  List<http.Request> get reconciles => [
    for (final r in requests)
      if (r.method == 'POST' && r.url.path.endsWith('/reconcile')) r,
  ];

  Future<http.Response> handle(http.Request r) async {
    requests.add(r);
    final path = r.url.path;
    if (path.endsWith('/auth/refresh')) {
      refreshes++;
      return refreshOk
          ? _json({'access_token': 'a2', 'refresh_token': 'r2'})
          : _json({'error': 'unauthorized'}, 401);
    }
    if (path.endsWith('/balance')) {
      return balance ??
          _json({
            'selling_balance': '6244.40',
            'selling_currency': 'USD',
            'accounting_balance': '523100.50',
            'accounting_currency': 'INR',
          });
    }
    if (r.method == 'POST' && path.endsWith('/renew')) {
      onRenew?.call(r);
      if (renewReplies.isEmpty) {
        unexpectedRenews.add(r.url.path);
        return _json({'error': 'unexpected_test_post'}, 599);
      }
      return renewReplies.removeAt(0)();
    }
    if (r.method == 'POST' && path.endsWith('/reconcile')) {
      return reconcileOverride ??
          _json({
            'batch': _batch(state: storedState, option: storedOption),
            'reconciliation': <Object>[],
          });
    }
    if (RegExp(r'/renewal-batches/\d+$').hasMatch(path)) {
      return detailOverride ??
          _json({'batch': _batch(state: storedState, option: storedOption)});
    }
    return _json({'error': 'not_found'}, 404);
  }
}

_Reply _ok(Map<String, Object?> body, [int status = 200]) =>
    () async => _json(body, status);
_Reply _err(int status, String code) =>
    () async => _json({'error': code}, status);
_Reply _drop() =>
    () async => throw const SocketException('connection lost');
_Reply _timeout() =>
    () async => throw TimeoutException('too slow');

void main() {
  late _Server server;
  late AuthController auth;
  late MemoryRenewalAttemptStore store;

  setUp(() {
    server = _Server();
    store = MemoryRenewalAttemptStore();
    auth = AuthController(
      api: ApiClient(
        client: MockClient((r) => server.handle(r)),
        baseUrl: 'http://test/api/v1/',
      ),
      storage: _Storage(),
    )..status = AuthStatus.signedIn;
  });

  tearDown(() {
    // No test may ever send a renewal POST it did not script.
    expect(server.unexpectedRenews, isEmpty);
  });

  RenewalExecutionController controller({
    String state = 'pending',
    String option = 'KeepInvoice',
    int years = 1,
  }) {
    final c = RenewalExecutionController(
      api: auth.api,
      store: store,
      batch: RenewalBatch.fromJson(
        _batch(state: state, option: option, years: years),
      ),
      itemId: 11,
      clock: () => DateTime.utc(2026, 11, 11),
    );
    addTearDown(c.dispose);
    return c;
  }

  Future<RenewalExecutionController> ready({
    String state = 'pending',
    String option = 'KeepInvoice',
  }) async {
    final c = controller(state: state, option: option);
    await c.start();
    c.setAcknowledged(true);
    return c;
  }

  bool marked() => store.markers.containsKey('7/11');

  Map<String, dynamic> body(http.Request r) =>
      jsonDecode(r.body) as Map<String, dynamic>;

  // -------------------------------------------------------------------------
  group('request contract', () {
    for (final option in ['KeepInvoice', 'NoInvoice']) {
      test(
        '$option sends exactly the six stored confirmation values',
        () async {
          server.renewReplies.add(_ok(_executed('succeeded')));
          final c = await ready(option: option);

          await c.renew();

          final r = server.renews.single;
          expect(r.url.path, '/api/v1/renewal-batches/7/items/11/renew');
          expect(r.headers['Authorization'], 'Bearer a1');
          expect(body(r), {
            'acknowledge_charge': true,
            'confirm_domain_name': 'example.com',
            'confirm_total_amount': '1438.42',
            'confirm_currency': 'INR',
            'confirm_years': 1,
            'confirm_invoice_option': option,
          });
          expect(body(r)['acknowledge_charge'], isA<bool>());
          expect(body(r)['confirm_years'], isA<int>());
          expect(c.phase, RenewPhase.succeeded);
        },
      );
    }

    test(
      'values come from the stored batch, not from anything local',
      () async {
        server.renewReplies.add(_ok(_executed('succeeded')));
        final c = controller(option: 'NoInvoice', years: 3);
        await c.start();
        c.setAcknowledged(true);

        await c.renew();

        final b = body(server.renews.single);
        expect(b['confirm_years'], 3);
        expect(b['confirm_invoice_option'], 'NoInvoice');
        expect(b['confirm_total_amount'], '1438.42');
      },
    );

    test('Renew does nothing without the acknowledgement', () async {
      final c = controller();
      await c.start();

      expect(c.canRenew, isFalse);
      await c.renew();

      expect(server.renews, isEmpty);
      expect(marked(), isFalse);
    });

    test('un-ticking the acknowledgement disables Renew again', () async {
      final c = await ready();
      expect(c.canRenew, isTrue);
      c.setAcknowledged(false);
      expect(c.canRenew, isFalse);
      await c.renew();
      expect(server.renews, isEmpty);
    });

    test('a second tap while the request is running sends nothing', () async {
      final gate = Completer<http.Response>();
      server.renewReplies.add(() => gate.future);
      final c = await ready();

      final first = c.renew();
      await Future<void>.delayed(Duration.zero);
      expect(c.phase, RenewPhase.sending);
      await c.renew();
      await c.renew();
      gate.complete(_json(_executed('succeeded')));
      await first;

      expect(server.renews.length, 1);
    });

    test('an item that is not pending is never sent', () async {
      for (final state in ['succeeded', 'failed', 'submitting', 'uncertain']) {
        final c = controller(state: state);
        await c.start();
        c.setAcknowledged(true);
        expect(c.canRenew, isFalse, reason: state);
        await c.renew();
      }
      expect(server.renews, isEmpty);
    });
  });

  // -------------------------------------------------------------------------
  group('attempt marker', () {
    test('is written BEFORE the request is sent', () async {
      var markerAtSend = false;
      server.onRenew = (_) => markerAtSend = marked();
      server.renewReplies.add(_ok(_executed('succeeded')));
      final c = await ready();

      await c.renew();

      expect(markerAtSend, isTrue);
    });

    test('a marker write failure prevents the request', () async {
      store.failWrites = true;
      final c = await ready();

      await c.renew();

      expect(server.renews, isEmpty);
      expect(c.message, contains('Nothing was charged'));
      expect(c.phase, RenewPhase.ready);
    });

    test('an unreadable marker blocks Renew (fail closed)', () async {
      store.failReads = true;
      final c = controller();
      await c.start();
      c.setAcknowledged(true);

      expect(c.canRenew, isFalse);
      expect(c.phase, RenewPhase.unconfirmed);
      await c.renew();
      expect(server.renews, isEmpty);
    });

    test('survives an app restart: the new screen is blocked', () async {
      server.renewReplies.add(_timeout());
      final first = await ready();
      await first.renew();
      expect(marked(), isTrue);

      // "Restart": a brand new controller over the same persistent store, and
      // the server still shows the item as pending.
      final second = controller();
      await second.start();
      second.setAcknowledged(true);

      expect(second.phase, RenewPhase.unconfirmed);
      expect(second.canRenew, isFalse);
      expect(second.showRenew, isFalse);
      await second.renew();
      expect(server.renews.length, 1);
      expect(marked(), isTrue);
    });

    test(
      'a marker left by a closed app is inspected before Renew shows',
      () async {
        await store.mark(7, 11, DateTime.utc(2026, 11, 11));
        final c = controller();

        expect(c.phase, RenewPhase.checking);
        expect(c.showRenew, isFalse);
        await c.start();

        expect(c.showRenew, isFalse);
        expect(c.showRecovery, isTrue);
      },
    );

    test('a refetched pending item with a marker stays blocked', () async {
      server.renewReplies.add(_timeout());
      final c = await ready();
      await c.renew();

      server.storedState = 'pending';
      await c.refresh();

      expect(c.phase, RenewPhase.unconfirmed);
      expect(c.canRenew, isFalse);
      expect(marked(), isTrue);
      expect(c.message, contains('still shows this item as pending'));
    });

    test('Check status on a pending marked item keeps it blocked', () async {
      await store.mark(7, 11, DateTime.utc(2026, 11, 11));
      final c = controller();
      await c.start();

      await c.checkStatus();

      expect(server.reconciles.length, 1);
      expect(c.canRenew, isFalse);
      expect(marked(), isTrue);
      expect(server.renews, isEmpty);
    });

    test('a stored final state removes the marker', () async {
      await store.mark(7, 11, DateTime.utc(2026, 11, 11));
      server.storedState = 'succeeded';
      final c = controller();
      await c.start();
      await c.checkStatus();

      expect(c.phase, RenewPhase.succeeded);
      expect(marked(), isFalse);
    });

    for (final state in ['pending', 'submitting', 'uncertain']) {
      test(
        'a $state item retains its marker; Renew is never offered',
        () async {
          await store.mark(7, 11, DateTime.utc(2026, 11, 11));
          final batch = RenewalBatch.fromJson(_batch(state: state));

          final blocked = await blockedItemIds(store, batch);
          expect(marked(), isTrue, reason: 'blockedItemIds');
          expect(blocked.contains(11), state == 'pending');

          final c = controller(state: state);
          await c.start();
          expect(marked(), isTrue, reason: 'start/_inspect');
          expect(c.canRenew, isFalse);
          expect(c.showRenew, isFalse);
          expect(c.showRecovery, isTrue);

          server.storedState = state;
          await c.checkStatus();
          await c.refresh();
          expect(marked(), isTrue, reason: 'after Check status and Refresh');
          expect(c.canRenew, isFalse);
          expect(c.showRenew, isFalse);
          expect(server.renews, isEmpty);
        },
      );
    }

    for (final state in ['succeeded', 'failed']) {
      test('a $state item clears its marker', () async {
        await store.mark(7, 11, DateTime.utc(2026, 11, 11));
        final batch = RenewalBatch.fromJson(_batch(state: state));
        await blockedItemIds(store, batch);
        expect(marked(), isFalse, reason: 'blockedItemIds');

        await store.mark(7, 11, DateTime.utc(2026, 11, 11));
        final c = controller(state: state);
        await c.start();
        expect(marked(), isFalse, reason: 'start/_inspect');
        expect(c.canRenew, isFalse);
        expect(c.showRenew, isFalse);
      });
    }

    test('nothing clears a marker by itself over time', () async {
      await store.mark(7, 11, DateTime.utc(2026, 11, 11));
      final c = controller();
      await c.start();
      await Future<void>.delayed(const Duration(milliseconds: 50));

      expect(marked(), isTrue);
      expect(server.requests.where((r) => r.method == 'POST'), isEmpty);
    });
  });

  // -------------------------------------------------------------------------
  group('responses and the marker table', () {
    test('200 succeeded: marker cleared, result shown, IDs kept', () async {
      server.storedState = 'succeeded';
      server.renewReplies.add(_ok(_executed('succeeded')));
      final c = await ready();

      await c.renew();

      expect(c.phase, RenewPhase.succeeded);
      expect(marked(), isFalse);
      expect(c.item!.actionId, '98765');
      expect(c.showRenew, isFalse);
    });

    test('200 failed: marker cleared, never retried', () async {
      server.storedState = 'failed';
      server.renewReplies.add(_ok(_executed('failed', error: 'order_changed')));
      final c = await ready();

      await c.renew();

      expect(c.phase, RenewPhase.failed);
      expect(marked(), isFalse);
      expect(c.showRenew, isFalse);
      expect(c.canRenew, isFalse);
      expect(server.renews.length, 1);
    });

    for (final (state, code) in [
      ('uncertain', 202),
      ('submitting', 202),
      ('uncertain', 200),
    ]) {
      test('$code with state $state: marker kept, no Renew', () async {
        server.storedState = state;
        server.renewReplies.add(_ok(_executed(state), code));
        final c = await ready();

        await c.renew();

        expect(c.phase, RenewPhase.unconfirmed);
        expect(marked(), isTrue);
        expect(c.showRenew, isFalse);
        expect(c.canRenew, isFalse);
        expect(server.renews.length, 1);
      });
    }

    test('a malformed 200 is unconfirmed', () async {
      server.renewReplies.add(_ok({'unexpected': true}));
      final c = await ready();

      await c.renew();

      expect(c.phase, RenewPhase.unconfirmed);
      expect(marked(), isTrue);
    });

    for (final (name, reply) in [
      ('a timeout after sending', _timeout()),
      ('a network disconnection', _drop()),
      ('HTTP 500 server_error', _err(500, 'server_error')),
      ('HTTP 502', _err(502, 'bad_gateway')),
      ('an unknown error code', _err(418, 'teapot')),
      (
        'a 429 throttle (not in the approved clear list)',
        _err(429, 'too_many_requests'),
      ),
    ]) {
      test('$name: exactly one POST, marker kept, no Renew again', () async {
        server.renewReplies.add(reply);
        final c = await ready();

        await c.renew();

        expect(server.renews.length, 1);
        expect(marked(), isTrue);
        expect(c.phase, RenewPhase.unconfirmed);
        expect(c.showRenew, isFalse);
        expect(c.canRenew, isFalse);
        expect(c.showRecovery, isTrue);
        expect(c.message, contains('Do not renew again'));

        // Nothing sends again by itself, and a manual tap cannot either.
        c.setAcknowledged(true);
        await c.renew();
        await Future<void>.delayed(const Duration(milliseconds: 50));
        expect(server.renews.length, 1);
      });
    }

    test('HTTP 409 execution_in_progress: marker kept, no Renew', () async {
      server.renewReplies.add(_err(409, 'execution_in_progress'));
      final c = await ready();

      await c.renew();

      expect(server.renews.length, 1);
      expect(marked(), isTrue);
      expect(c.phase, RenewPhase.inProgress);
      expect(c.showRenew, isFalse);
      expect(c.canRenew, isFalse);
      expect(c.showRecovery, isTrue);
      expect(c.message, contains('Another renewal operation is in progress'));

      c.setAcknowledged(true);
      await c.renew();
      expect(server.renews.length, 1);
    });

    test(
      '409 execution_in_progress survives a refetch of a pending item',
      () async {
        server.renewReplies.add(_err(409, 'execution_in_progress'));
        final c = await ready();
        await c.renew();

        await c.refresh();

        expect(c.phase, RenewPhase.inProgress);
        expect(c.showRenew, isFalse);
        expect(marked(), isTrue);
      },
    );

    test(
      '409 item_not_pending: marker cleared, stored state shown, no resend',
      () async {
        server.storedState = 'succeeded';
        server.renewReplies.add(_err(409, 'item_not_pending'));
        final c = await ready();

        await c.renew();

        expect(marked(), isFalse);
        expect(c.phase, RenewPhase.succeeded);
        expect(server.renews.length, 1);
      },
    );

    for (final (status, code) in [
      (503, 'balance_currency_unverified'),
      (503, 'balance_currency_mismatch'),
      (503, 'balance_unavailable'),
      (503, 'price_unavailable'),
      (503, 'provider_not_configured'),
      (409, 'insufficient_balance'),
      (409, 'price_changed'),
      (409, 'batch_expired'),
      (422, 'confirmation_mismatch'),
      (400, 'invalid_request'),
      (400, 'execution_limit_exceeded'),
      (404, 'not_found'),
    ]) {
      test(
        '$status $code: marker cleared, Renew closed for this screen',
        () async {
          server.renewReplies.add(_err(status, code));
          final c = await ready();

          await c.renew();

          expect(server.renews.length, 1);
          expect(marked(), isFalse);
          expect(c.phase, RenewPhase.refused);
          expect(c.showRenew, isFalse);
          expect(c.canRenew, isFalse);
          expect(c.message, isNot(contains('succeeded')));

          // Closed until the screen is reopened: another tap sends nothing.
          c.setAcknowledged(true);
          await c.renew();
          expect(server.renews.length, 1);
        },
      );
    }

    test('refusal messages say nothing was charged', () {
      for (final code in [
        'balance_currency_unverified',
        'insufficient_balance',
        'price_changed',
        'batch_expired',
      ]) {
        expect(
          RenewalExecutionController.messageFor(code),
          contains('Nothing was charged'),
        );
      }
    });
  });

  // -------------------------------------------------------------------------
  group('HTTP 401', () {
    test(
      'sends exactly ONE renewal POST, refreshes, clears the marker',
      () async {
        server.renewReplies.add(_err(401, 'unauthorized'));
        final c = await ready();

        await c.renew();
        await Future<void>.delayed(const Duration(milliseconds: 50));

        expect(server.renews.length, 1); // never resent automatically
        expect(server.refreshes, 1); // the session was refreshed
        expect(marked(), isFalse);
        expect(c.phase, RenewPhase.ready);
        expect(c.acknowledged, isFalse);
        expect(c.canRenew, isFalse); // must acknowledge and tap again
        expect(c.message, contains('The renewal was not sent'));
      },
    );

    test('a manual second tap sends exactly one more POST', () async {
      server.renewReplies.addAll([
        _err(401, 'unauthorized'),
        _ok(_executed('succeeded')),
      ]);
      final c = await ready();
      await c.renew();
      expect(server.renews.length, 1);

      c.setAcknowledged(true);
      expect(c.canRenew, isTrue);
      await c.renew();

      expect(server.renews.length, 2);
      expect(server.renews.last.headers['Authorization'], 'Bearer a2');
      expect(c.phase, RenewPhase.succeeded);
    });

    test('a failed session refresh still sends only one POST', () async {
      server.refreshOk = false;
      server.renewReplies.add(_err(401, 'unauthorized'));
      final c = await ready();

      await c.renew();

      expect(server.renews.length, 1);
      expect(marked(), isFalse);
    });
  });

  // -------------------------------------------------------------------------
  group('execution disabled', () {
    test(
      '503 execution_disabled: clear message, Renew stays disabled',
      () async {
        server.renewReplies.add(_err(503, 'execution_disabled'));
        final c = await ready();

        await c.renew();

        expect(c.phase, RenewPhase.disabled);
        expect(marked(), isFalse);
        expect(
          c.message,
          contains('Renewals are currently disabled on the server'),
        );
        expect(c.message, contains('No renewal was attempted'));
        expect(c.message, isNot(contains('succeeded')));
        expect(c.message, isNot(contains('payment')));
        expect(c.canRenew, isFalse);

        // Repeated attempts from this screen are blocked.
        c.setAcknowledged(true);
        await c.renew();
        await c.renew();
        expect(server.renews.length, 1);

        // Stored details stay available.
        expect(c.item!.domainName, 'example.com');
        await c.refresh();
        expect(c.item!.total, '1438.42');
        expect(c.canRenew, isFalse);
      },
    );
  });

  // -------------------------------------------------------------------------
  group('Check status', () {
    test('sends one reconcile POST and never a renewal POST', () async {
      server.renewReplies.add(_timeout());
      final c = await ready();
      await c.renew();
      server.storedState = 'uncertain';

      await c.checkStatus();

      expect(server.reconciles.length, 1);
      expect(
        server.reconciles.single.url.path,
        '/api/v1/renewal-batches/7/reconcile',
      );
      expect(server.renews.length, 1);
      expect(c.phase, RenewPhase.unconfirmed);
      expect(c.canRenew, isFalse);
    });

    test('a resolved success clears the marker', () async {
      server.renewReplies.add(_timeout());
      final c = await ready();
      await c.renew();
      server.storedState = 'succeeded';

      await c.checkStatus();

      expect(c.phase, RenewPhase.succeeded);
      expect(marked(), isFalse);
      expect(server.renews.length, 1);
    });

    test('a failing status check keeps everything blocked', () async {
      server.renewReplies.add(_timeout());
      final c = await ready();
      await c.renew();
      server.reconcileOverride = _json({'error': 'server_error'}, 500);

      await c.checkStatus();

      expect(c.message, contains('Could not check the status'));
      expect(marked(), isTrue);
      expect(c.canRenew, isFalse);
    });
  });

  // -------------------------------------------------------------------------
  group('confirmation screen', () {
    Future<RenewalExecutionController> open(
      WidgetTester t, {
      String option = 'KeepInvoice',
      String state = 'pending',
    }) async {
      t.view.physicalSize = const Size(800, 2400);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.reset);
      final c = controller(option: option, state: state);
      await t.pumpWidget(
        MaterialApp(
          theme: AppTheme.light,
          home: RenewalConfirmScreen(controller: c),
        ),
      );
      await t.pumpAndSettle();
      return c;
    }

    testWidgets('shows the stored item, estimates and the stored option', (
      t,
    ) async {
      await open(t);

      expect(find.text('example.com'), findsOneWidget);
      expect(find.text('1 year'), findsOneWidget);
      expect(find.text('Estimates, not a final invoice'), findsOneWidget);
      expect(find.text('Renewal cost (base)'), findsOneWidget);
      expect(find.text('GST (estimate)'), findsOneWidget);
      expect(find.text('VAT (estimate)'), findsOneWidget);
      expect(find.text('Estimated total'), findsOneWidget);
      expect(find.text('₹1,438.42'), findsOneWidget);
      expect(find.text('Final invoice'), findsNothing);
      expect(find.textContaining('may differ'), findsOneWidget);
      expect(find.text("Keep Customers' Invoice(s) pending"), findsOneWidget);
      expect(find.text('API value: KeepInvoice'), findsOneWidget);
      // The option is shown, not chosen, here.
      expect(find.byType(RadioListTile<InvoiceOption>), findsNothing);
    });

    testWidgets('Back to Domains only navigates and sends nothing', (t) async {
      t.view.physicalSize = const Size(800, 2400);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.reset);
      final c = controller(state: 'succeeded');
      await t.pumpWidget(
        MaterialApp(
          theme: AppTheme.light,
          home: Builder(
            builder: (context) => Scaffold(
              body: const Text('domain list'),
              floatingActionButton: FloatingActionButton(
                onPressed: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) => RenewalConfirmScreen(controller: c),
                  ),
                ),
              ),
            ),
          ),
        ),
      );
      await t.tap(find.byType(FloatingActionButton));
      await t.pumpAndSettle();

      expect(find.text('Renewal succeeded.'), findsOneWidget);
      expect(find.text('Refresh'), findsOneWidget);
      expect(find.text('Renew'), findsNothing);
      // Details stay on screen until the button is used.
      expect(find.text('example.com'), findsOneWidget);
      expect(find.text('Estimated total'), findsOneWidget);
      final posts = server.requests.where((r) => r.method == 'POST').length;

      await t.tap(find.byKey(const ValueKey('confirm-back-to-domains')));
      await t.pumpAndSettle();

      expect(find.byType(RenewalConfirmScreen), findsNothing);
      expect(find.text('domain list'), findsOneWidget);
      expect(server.requests.where((r) => r.method == 'POST').length, posts);
      expect(server.renews, isEmpty);
    });

    testWidgets('NoInvoice is shown with its API value', (t) async {
      await open(t, option: 'NoInvoice');

      expect(find.text("Don't create Customers' Invoice(s)"), findsOneWidget);
      expect(find.text('API value: NoInvoice'), findsOneWidget);
    });

    testWidgets('no accounting or available balance is shown', (t) async {
      await open(t);
      expect(find.text('Accounting Balance (API)'), findsNothing);
      expect(
        find.textContaining('Available', findRichText: true),
        findsNothing,
      );
      expect(
        find.textContaining('Accounting', findRichText: true),
        findsNothing,
      );
      expect(find.byKey(const ValueKey('confirm-balance')), findsNothing);
      expect(find.text('INR 523,100.50'), findsNothing);
      // The estimates and the estimate note stay.
      expect(find.text('Estimated total'), findsOneWidget);
      expect(
        find.byKey(const ValueKey('confirm-estimate-note')),
        findsOneWidget,
      );
    });

    testWidgets('an unavailable balance leaves the screen unchanged', (
      t,
    ) async {
      server.balance = _json({'error': 'provider_error'}, 502);
      await open(t);
      expect(find.text('Unavailable'), findsNothing);
      expect(find.text('Accounting Balance (API)'), findsNothing);
      expect(find.byKey(const ValueKey('confirm-ack')), findsOneWidget);
    });

    testWidgets('opening the screen makes no balance request', (t) async {
      await open(t);
      expect(
        server.requests.where((r) => r.url.path.endsWith('/balance')),
        isEmpty,
      );
      expect(server.requests.where((r) => r.method == 'POST'), isEmpty);
    });

    testWidgets('Renew is disabled until the charge is acknowledged', (
      t,
    ) async {
      await open(t);
      final button = find.byKey(const ValueKey('confirm-renew'));

      expect(t.widget<FilledButton>(button).onPressed, isNull);
      await t.tap(find.byKey(const ValueKey('confirm-ack')));
      await t.pumpAndSettle();
      expect(t.widget<FilledButton>(button).onPressed, isNotNull);
      expect(server.renews, isEmpty);
    });

    testWidgets('opening the screen sends no renewal or reconcile request', (
      t,
    ) async {
      await open(t);
      expect(server.renews, isEmpty);
      expect(server.reconciles, isEmpty);
      expect(server.requests.where((r) => r.method == 'POST'), isEmpty);
    });

    testWidgets(
      'execution_disabled shows the message and keeps Renew disabled',
      (t) async {
        server.renewReplies.add(_err(503, 'execution_disabled'));
        await open(t);
        await t.tap(find.byKey(const ValueKey('confirm-ack')));
        await t.pumpAndSettle();
        await t.tap(find.byKey(const ValueKey('confirm-renew')));
        await t.pumpAndSettle();

        expect(
          find.textContaining('Renewals are currently disabled on the server'),
          findsOneWidget,
        );
        expect(find.textContaining('succeeded'), findsNothing);
        expect(
          t
              .widget<FilledButton>(find.byKey(const ValueKey('confirm-renew')))
              .onPressed,
          isNull,
        );
        // Quote and stored details stay visible.
        expect(find.text('₹1,438.42'), findsOneWidget);
        expect(server.renews.length, 1);
      },
    );

    testWidgets('an uncertain result offers Check status, never Renew', (
      t,
    ) async {
      server.renewReplies.add(_ok(_executed('uncertain'), 202));
      server.storedState = 'uncertain';
      await open(t);
      await t.tap(find.byKey(const ValueKey('confirm-ack')));
      await t.pumpAndSettle();
      await t.tap(find.byKey(const ValueKey('confirm-renew')));
      await t.pumpAndSettle();

      expect(find.text('Renew'), findsNothing);
      expect(find.text('Renew again'), findsNothing);
      expect(find.text('Renew Again'), findsNothing);
      expect(find.byKey(const ValueKey('confirm-renew')), findsNothing);
      expect(find.text('Check status'), findsOneWidget);
      expect(find.text('Refresh'), findsOneWidget);
      expect(
        find.text('Outcome not confirmed. Do not renew again.'),
        findsOneWidget,
      );
      expect(server.renews.length, 1);

      await t.tap(find.byKey(const ValueKey('confirm-check-status')));
      await t.pumpAndSettle();
      expect(server.reconciles.length, 1);
      expect(server.renews.length, 1);
    });

    testWidgets(
      'a timeout shows the unconfirmed state with Check status only',
      (t) async {
        server.renewReplies.add(_timeout());
        await open(t);
        await t.tap(find.byKey(const ValueKey('confirm-ack')));
        await t.pumpAndSettle();
        await t.tap(find.byKey(const ValueKey('confirm-renew')));
        await t.pumpAndSettle();

        expect(find.byKey(const ValueKey('confirm-renew')), findsNothing);
        expect(find.text('Check status'), findsOneWidget);
        expect(server.renews.length, 1);
      },
    );

    testWidgets('execution_in_progress offers Check status and Refresh only', (
      t,
    ) async {
      server.renewReplies.add(_err(409, 'execution_in_progress'));
      await open(t);
      await t.tap(find.byKey(const ValueKey('confirm-ack')));
      await t.pumpAndSettle();
      await t.tap(find.byKey(const ValueKey('confirm-renew')));
      await t.pumpAndSettle();

      expect(find.byKey(const ValueKey('confirm-renew')), findsNothing);
      expect(
        find.textContaining('Another renewal operation is in progress'),
        findsOneWidget,
      );
      expect(find.text('Check status'), findsOneWidget);
      expect(find.text('Refresh'), findsOneWidget);
    });

    testWidgets('a definite failure shows Failed and no Renew', (t) async {
      server.storedState = 'failed';
      server.renewReplies.add(_ok(_executed('failed', error: 'order_changed')));
      await open(t);
      await t.tap(find.byKey(const ValueKey('confirm-ack')));
      await t.pumpAndSettle();
      await t.tap(find.byKey(const ValueKey('confirm-renew')));
      await t.pumpAndSettle();

      expect(find.textContaining('Renewal failed'), findsOneWidget);
      expect(find.byKey(const ValueKey('confirm-renew')), findsNothing);
      expect(find.text('Renew again'), findsNothing);
    });
  });

  // -------------------------------------------------------------------------
  group('batch screen', () {
    Future<void> open(
      WidgetTester t,
      String state, {
      bool withApi = true,
    }) async {
      t.view.physicalSize = const Size(800, 2400);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.reset);
      server.storedState = state;
      await t.pumpWidget(
        MaterialApp(
          theme: AppTheme.light,
          home: RenewalBatchScreen(
            controller: RenewalBatchDetailController(api: auth.api, batchId: 7),
            api: withApi ? auth.api : null,
            attempts: store,
          ),
        ),
      );
      await t.pumpAndSettle();
    }

    testWidgets('shows the stored option with its API value', (t) async {
      await open(t, 'pending');
      expect(
        find.text("Keep Customers' Invoice(s) pending (KeepInvoice)"),
        findsOneWidget,
      );
    });

    testWidgets('a pending item with no marker offers Review & renew', (
      t,
    ) async {
      await open(t, 'pending');
      expect(find.text('Review & renew'), findsOneWidget);
      expect(find.text('Check status'), findsNothing);
    });

    testWidgets('a pending item with a marker offers Check status only', (
      t,
    ) async {
      await store.mark(7, 11, DateTime.utc(2026, 11, 11));
      await open(t, 'pending');

      expect(find.text('Review & renew'), findsNothing);
      expect(find.text('Check status'), findsOneWidget);
      expect(find.text('Refresh'), findsOneWidget);
      expect(find.byKey(const ValueKey('unresolved-11')), findsOneWidget);
    });

    testWidgets('an unreadable marker store blocks Review & renew', (t) async {
      store.failReads = true;
      await open(t, 'pending');
      expect(find.text('Review & renew'), findsNothing);
      expect(find.text('Check status'), findsOneWidget);
    });

    for (final state in ['uncertain', 'submitting']) {
      testWidgets('a $state item offers Check status, never Renew', (t) async {
        await open(t, state);
        expect(find.text('Review & renew'), findsNothing);
        expect(find.text('Renew'), findsNothing);
        expect(find.text('Check status'), findsOneWidget);
      });
    }

    for (final state in ['succeeded', 'failed']) {
      testWidgets('a $state item has no action', (t) async {
        await open(t, state);
        expect(find.text('Review & renew'), findsNothing);
        expect(find.text('Check status'), findsNothing);
      });
    }

    testWidgets('without an API client the screen is read-only', (t) async {
      await open(t, 'pending', withApi: false);
      expect(find.text('Review & renew'), findsNothing);
    });

    testWidgets('Check status sends one reconcile POST and no renewal', (
      t,
    ) async {
      await open(t, 'uncertain');
      await t.tap(find.byKey(const ValueKey('check-status-11')));
      await t.pumpAndSettle();

      expect(server.reconciles.length, 1);
      expect(server.renews, isEmpty);
    });

    testWidgets(
      'Review & renew opens the confirmation without sending anything',
      (t) async {
        await open(t, 'pending');
        await t.tap(find.byKey(const ValueKey('review-renew-11')));
        await t.pumpAndSettle();

        expect(find.byType(RenewalConfirmScreen), findsOneWidget);
        expect(find.text('Review renewal'), findsOneWidget);
        expect(server.requests.where((r) => r.method == 'POST'), isEmpty);
      },
    );
  });

  // -------------------------------------------------------------------------
  group('no automatic renewal', () {
    test('only the API client can reach the renew and reconcile endpoints', () {
      final sep = String.fromCharCode(92);
      for (final f in Directory(
        'lib',
      ).listSync(recursive: true).whereType<File>()) {
        if (!f.path.endsWith('.dart')) continue;
        final text = f.readAsStringSync();
        final path = f.path.replaceAll(sep, '/');
        if (path.endsWith('core/api_client.dart')) continue;
        expect(text.contains("/renew'"), isFalse, reason: path);
        expect(text.contains("/reconcile'"), isFalse, reason: path);
      }
    });

    test('renewBatchItem is called only from the manual renew() method', () {
      final sep = String.fromCharCode(92);
      for (final f in Directory(
        'lib',
      ).listSync(recursive: true).whereType<File>()) {
        final path = f.path.replaceAll(sep, '/');
        if (!path.endsWith('.dart') || path.endsWith('core/api_client.dart')) {
          continue;
        }
        final text = f.readAsStringSync();
        if (text.contains('renewBatchItem(')) {
          expect(
            path.endsWith('domains/renewal_execution.dart'),
            isTrue,
            reason: path,
          );
          expect('renewBatchItem('.allMatches(text).length, 1);
        }
      }
    });

    test('starting the controller never sends anything', () async {
      final c = controller();
      await c.start();
      await Future<void>.delayed(const Duration(milliseconds: 50));
      expect(server.requests.where((r) => r.method == 'POST'), isEmpty);
    });
  });
}

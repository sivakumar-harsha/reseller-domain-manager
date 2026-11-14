import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:domain_manager/auth/auth_controller.dart';
import 'package:domain_manager/auth/token_storage.dart';
import 'package:domain_manager/core/api_client.dart';
import 'package:domain_manager/domains/renewal_batch.dart';
import 'package:domain_manager/domains/renewal_quote.dart';
import 'package:domain_manager/domains/renewal_submit.dart';
import 'package:domain_manager/domains/selected_renewal.dart';
import 'package:domain_manager/screens/renewal_batch_screen.dart';
import 'package:domain_manager/screens/renewal_history_screen.dart';
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

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

Map<String, Object?> _item(
  int id,
  int domainId,
  String name, {
  String state = 'pending',
}) => {
  'id': id,
  'domain_id': domainId,
  'domain_name': name,
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

Map<String, Object?> _batch(
  int id, {
  String status = 'pending',
  String option = 'KeepInvoice',
  List<Map<String, Object?>>? items,
  Map<String, int>? counts,
}) {
  final rows = items ?? [_item(1, 1, 'site1.com'), _item(2, 2, 'site2.com')];
  return {
    'id': id,
    'status': status,
    'invoice_option': option,
    'years': 1,
    'item_counts':
        counts ??
        {
          'pending': rows.length,
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

/// Fake backend: quotes, batch create / list / detail. No ResellerClub.
class _Server {
  final requests = <http.Request>[];
  final unsupported = <int>{4};

  /// Scripted answers for POST renewal-batches, consumed in order. A null
  /// entry simulates a dropped connection.
  final posts = <http.Response?>[];
  Completer<void>? postGate;

  http.Response? detail;
  http.Response? list;

  /// Answer for GET renewal-batches/active (null: the endpoint fails).
  http.Response? active;

  /// Answers consumed first, in order, before [active].
  final activeQueue = <http.Response>[];

  List<http.Request> get created => [
    for (final r in requests)
      if (r.method == 'POST' && r.url.path.endsWith('/renewal-batches')) r,
  ];

  Future<http.Response> handle(http.Request r) async {
    requests.add(r);
    final path = r.url.path;
    final quote = RegExp(r'domains/(\d+)/renewal-quote').firstMatch(path);
    if (quote != null) {
      final id = int.parse(quote.group(1)!);
      if (unsupported.contains(id)) {
        return _json({
          'domain': 'site$id.in',
          'years': 1,
          'status': 'unsupported_tld',
          'tax_status': 'not_established',
        });
      }
      return _json({
        'domain': 'site$id.com',
        'years': 1,
        'status': 'available',
        'currency': 'INR',
        'renewal_amount': '1219.00',
        'tax': '219.42',
        'vat': '0.00',
        'total': '1438.42',
        'gst_rate': '18',
        'vat_rate': '0',
        'tax_status': 'estimated',
        'cached': false,
      });
    }
    if (r.method == 'POST' && path.endsWith('/renewal-batches')) {
      await postGate?.future;
      if (posts.isEmpty) throw const SocketException('no scripted answer');
      final next = posts.removeAt(0);
      if (next == null) throw const SocketException('dropped');
      return next;
    }
    if (path.endsWith('/auth/refresh')) {
      return _json({'error': 'unauthorized'}, 401);
    }
    if (path.endsWith('/renewal-batches/active')) {
      if (activeQueue.isNotEmpty) return activeQueue.removeAt(0);
      return active ?? _json({'error': 'server_error'}, 500);
    }
    if (path.endsWith('/renewal-batches')) {
      return list ??
          _json({
            'data': <Object>[],
            'meta': {'page': 1, 'per_page': 20, 'total': 0, 'total_pages': 0},
          });
    }
    if (RegExp(r'/renewal-batches/\d+$').hasMatch(path)) {
      return detail ?? _json({'batch': _batch(7)});
    }
    return _json({'error': 'not_found'}, 404);
  }
}

void main() {
  late _Server server;
  late AuthController auth;

  setUp(() {
    server = _Server();
    auth = AuthController(
      api: ApiClient(
        client: MockClient((r) => server.handle(r)),
        baseUrl: 'http://test/api/v1/',
      ),
      storage: _Storage(),
    )..status = AuthStatus.signedIn;
  });

  Future<RenewalSubmitController> ready(
    List<int> ids, {
    String Function()? keys,
  }) async {
    final selected = SelectedRenewalController(
      api: auth.api,
      selected: [for (final id in ids) (id: id, name: 'site$id.com')],
    );
    addTearDown(selected.dispose);
    await selected.load();
    // The real API quote carries no usable tax now (RenewalQuote.fromJson drops
    // it), so nothing is submittable from a loaded quote. These controller-level
    // tests exercise the dormant submit logic, so they hand it fully taxed
    // quotes directly. The screen tests below use the real path.
    for (final e in selected.entries) {
      final q = e.quote.quote;
      if (q != null && q.available) {
        e.quote.quote = RenewalQuote(
          domain: q.domain,
          years: q.years,
          status: q.status,
          currency: q.currency,
          amount: q.amount,
          gst: '219.42',
          vat: '0.00',
          total: '1438.42',
          gstRate: '18',
          vatRate: '0',
          taxStatus: 'estimated',
        );
      }
    }
    return RenewalSubmitController(
      api: auth.api,
      selected: selected,
      keyGenerator: keys,
    );
  }

  Map<String, dynamic> body(http.Request r) =>
      jsonDecode(r.body) as Map<String, dynamic>;

  http.Response created201(int id) =>
      _json({'replayed': false, 'batch': _batch(id)}, 201);

  group('request contract', () {
    test(
      'KeepInvoice is serialised with only the four supported fields',
      () async {
        final s = await ready([1, 2]);
        server.posts.add(created201(7));
        s.choose(InvoiceOption.keepInvoice);

        expect(await s.submit(), isTrue);

        final r = server.created.single;
        final b = body(r);
        expect(b.keys.toSet(), {
          'domain_ids',
          'years',
          'invoice_option',
          'idempotency_key',
        });
        expect(b['invoice_option'], 'KeepInvoice');
        expect(b['domain_ids'], [1, 2]);
        expect(b['years'], 1);
        expect(r.headers['Authorization'], 'Bearer a1');
        expect(r.url.path, '/api/v1/renewal-batches');
      },
    );

    test('NoInvoice is serialised', () async {
      final s = await ready([1]);
      server.posts.add(
        _json({
          'replayed': false,
          'batch': _batch(
            8,
            option: 'NoInvoice',
            items: [_item(1, 1, 'site1.com')],
          ),
        }, 201),
      );
      s.choose(InvoiceOption.noInvoice);

      expect(await s.submit(), isTrue);
      expect(body(server.created.single)['invoice_option'], 'NoInvoice');
    });

    test('the two options map to the exact labels and values', () {
      expect(InvoiceOption.values.length, 2);
      expect(
        InvoiceOption.keepInvoice.label,
        "Keep Customers' Invoice(s) pending",
      );
      expect(InvoiceOption.keepInvoice.apiValue, 'KeepInvoice');
      expect(
        InvoiceOption.noInvoice.label,
        "Don't create Customers' Invoice(s)",
      );
      expect(InvoiceOption.noInvoice.apiValue, 'NoInvoice');
    });

    test('an option is required: nothing is sent without one', () async {
      final s = await ready([1]);

      expect(await s.submit(), isFalse);

      expect(s.error, contains('Choose'));
      expect(server.created, isEmpty);
    });

    test(
      'unsupported domains never enter the batch and amounts are never sent',
      () async {
        final s = await ready([1, 4, 2]);
        server.posts.add(created201(7));
        s.choose(InvoiceOption.keepInvoice);

        expect(s.excluded.map((e) => e.id), [4]);
        await s.submit();

        final b = body(server.created.single);
        expect(b['domain_ids'], [1, 2]);
        expect(
          keysOf(b).any(
            (k) =>
                k.contains('amount') ||
                k.contains('order') ||
                k.contains('customer') ||
                k.contains('expiry'),
          ),
          isFalse,
        );
      },
    );

    test('nothing is submitted when no selected domain has a price', () async {
      final s = await ready([4]);
      s.choose(InvoiceOption.keepInvoice);

      expect(await s.submit(), isFalse);
      expect(server.created, isEmpty);
      expect(s.error, isNotNull);
    });

    test('nothing is submitted for an empty selection', () async {
      final s = await ready([]);
      s.choose(InvoiceOption.keepInvoice);

      expect(await s.submit(), isFalse);
      expect(s.error, 'No domains are selected.');
      expect(server.created, isEmpty);
    });
  });

  group('idempotency', () {
    test(
      'keys are generated in the backend format, one per new submission',
      () async {
        final a = await ready([1]);
        final b = await ready([1]);
        server.posts.addAll([created201(1), created201(2)]);
        a.choose(InvoiceOption.keepInvoice);
        b.choose(InvoiceOption.keepInvoice);
        await a.submit();
        await b.submit();

        final keys = [
          for (final r in server.created) body(r)['idempotency_key'] as String,
        ];
        expect(keys[0], matches(RegExp(r'^[A-Za-z0-9_-]{16,64}$')));
        expect(keys[0], isNot(keys[1]));
      },
    );

    test(
      'a retry after a network failure reuses the same key and payload',
      () async {
        final s = await ready([1, 2]);
        server.posts.addAll([null, created201(7)]);
        s.choose(InvoiceOption.keepInvoice);

        expect(await s.submit(), isFalse);
        expect(s.error, contains('Nothing was confirmed'));
        expect(s.result, isNull);
        expect(s.selected.entries.length, 2); // selection survives the failure
        expect(await s.submit(), isTrue);

        final first = body(server.created[0]);
        final second = body(server.created[1]);
        expect(second, first);
        expect(second['idempotency_key'], first['idempotency_key']);
      },
    );

    test(
      'changing the content creates a new key; changing back reuses the old one',
      () async {
        final s = await ready([1]);
        server.posts.addAll([null, null, created201(7)]);

        s.choose(InvoiceOption.keepInvoice);
        await s.submit();
        s.choose(InvoiceOption.noInvoice);
        await s.submit();
        s.choose(InvoiceOption.keepInvoice);
        await s.submit();

        final keys = [
          for (final r in server.created) body(r)['idempotency_key'],
        ];
        expect(keys[0], isNot(keys[1]));
        expect(keys[2], keys[0]);
      },
    );

    test(
      'a confirmed batch forgets its key: the next submission is new',
      () async {
        var n = 0;
        final s = await ready([1], keys: () => 'test-key-${n++}-aaaaaaaaaaaa');
        server.posts.addAll([created201(1), created201(2)]);
        s.choose(InvoiceOption.keepInvoice);

        await s.submit();
        await s.submit();

        expect(
          [for (final r in server.created) body(r)['idempotency_key']],
          ['test-key-0-aaaaaaaaaaaa', 'test-key-1-aaaaaaaaaaaa'],
        );
      },
    );

    test('a second tap while submitting sends nothing', () async {
      final s = await ready([1]);
      server.postGate = Completer<void>();
      server.posts.add(created201(7));
      s.choose(InvoiceOption.keepInvoice);

      final first = s.submit();
      await Future<void>.delayed(Duration.zero);
      expect(s.submitting, isTrue);
      expect(await s.submit(), isFalse);
      server.postGate!.complete();

      expect(await first, isTrue);
      expect(server.created.length, 1);
      expect(s.submitting, isFalse);
    });

    test(
      'an idempotency conflict is reported and the key is dropped',
      () async {
        var n = 0;
        final s = await ready([1], keys: () => 'conflict-key-${n++}-aaaaaaaa');
        server.posts.addAll([
          _json({'error': 'idempotency_conflict'}, 409),
          created201(7),
        ]);
        s.choose(InvoiceOption.keepInvoice);

        expect(await s.submit(), isFalse);
        expect(s.error, contains('different details'));
        expect(await s.submit(), isTrue);
        expect(
          body(server.created[1])['idempotency_key'],
          isNot(body(server.created[0])['idempotency_key']),
        );
      },
    );
  });

  group('responses', () {
    test('201 is a new batch', () async {
      final s = await ready([1, 2]);
      server.posts.add(created201(7));
      s.choose(InvoiceOption.keepInvoice);

      await s.submit();

      expect(s.result!.replayed, isFalse);
      expect(s.result!.batch.id, 7);
      expect(s.result!.batch.items.length, 2);
    });

    test('200 is a replay of the existing batch', () async {
      final s = await ready([1, 2]);
      server.posts.add(_json({'replayed': true, 'batch': _batch(7)}));
      s.choose(InvoiceOption.keepInvoice);

      expect(await s.submit(), isTrue);
      expect(s.result!.replayed, isTrue);
    });

    for (final (status, code, expected) in [
      (401, 'unauthorized', 'session is no longer valid'),
      (422, 'domain_not_found', 'no longer in your inventory'),
      (422, 'quote_unavailable', 'price for one'),
      (409, 'duplicate_cycle', 'already open'),
      (502, 'provider_error', 'price source is unavailable'),
      (503, 'provider_not_configured', 'price source is unavailable'),
    ]) {
      test(
        'HTTP $status $code gives a clear message and keeps the selection',
        () async {
          final s = await ready([1]);
          server.posts.add(_json({'error': code}, status));
          s.choose(InvoiceOption.keepInvoice);

          expect(await s.submit(), isFalse);

          expect(s.error, contains(expected));
          expect(s.result, isNull);
          expect(s.selected.entries.length, 1);
          expect(s.option, InvoiceOption.keepInvoice);
          expect(s.error, isNot(contains('Exception')));
        },
      );
    }

    test('an unknown 5xx shows a generic message', () async {
      final s = await ready([1]);
      server.posts.add(_json({'error': 'server_error'}, 500));
      s.choose(InvoiceOption.keepInvoice);

      await s.submit();

      expect(s.error, contains('server_error'));
    });

    test(
      'an empty or malformed batch response is not treated as success',
      () async {
        final s = await ready([1]);
        server.posts.addAll([
          _json({
            'replayed': false,
            'batch': _batch(7, items: <Map<String, Object?>>[]),
          }, 201),
          _json({'unexpected': true}, 201),
        ]);
        s.choose(InvoiceOption.keepInvoice);

        expect(await s.submit(), isFalse);
        expect(s.result, isNull);
        expect(s.error, contains('unexpected response'));
        expect(await s.submit(), isFalse);
        expect(s.result, isNull);
      },
    );
  });

  group('existing batch recognition', () {
    http.Response activeFor(int domainId, Map<String, Object?> batch) => _json({
      'data': [
        {'domain_id': domainId, 'batch': batch},
      ],
    });

    List<http.Request> gets(String suffix) => [
      for (final r in server.requests)
        if (r.method == 'GET' && r.url.path.endsWith(suffix)) r,
    ];

    test(
      'an active batch is shown, not recreated, and nothing is sent',
      () async {
        final s = await ready([1]);
        server.active = activeFor(
          1,
          _batch(7, items: [_item(1, 1, 'site1.com')]),
        );
        s.choose(InvoiceOption.keepInvoice);

        expect(await s.submit(), isFalse);

        expect(server.created, isEmpty, reason: 'no POST renewal-batches');
        expect(s.existing!.id, 7);
        expect(s.existing!.status, 'pending');
        expect(s.error, contains('already exists and is pending'));
        expect(s.error, contains('No renewal has been carried out'));
        expect(s.result, isNull);
        expect(server.requests.where((r) => r.method != 'GET'), isEmpty);
        expect(
          gets('/renewal-batches/active').single.url.query,
          'domain_ids=1',
        );
      },
    );

    test('the lookup carries every submittable domain id', () async {
      final s = await ready([1, 2]);
      server.active = _json({'data': <Object>[]});
      server.posts.add(created201(7));
      s.choose(InvoiceOption.keepInvoice);

      expect(await s.submit(), isTrue);

      expect(
        Uri.decodeQueryComponent(
          gets('/renewal-batches/active').single.url.query,
        ),
        'domain_ids=1,2',
      );
    });

    test(
      'no active batch (for example one the backend expired) proceeds to create',
      () async {
        final s = await ready([1]);
        server.active = _json({'data': <Object>[]});
        server.posts.add(created201(8));
        s.choose(InvoiceOption.keepInvoice);

        expect(await s.submit(), isTrue);

        expect(s.existing, isNull);
        expect(s.result!.batch.id, 8);
        expect(server.created.length, 1);
      },
    );

    test(
      'a failed lookup does not block creating (the backend guards)',
      () async {
        final s = await ready([1]);
        server.posts.add(created201(8)); // server.active unset: lookup fails
        s.choose(InvoiceOption.keepInvoice);

        expect(await s.submit(), isTrue);

        expect(s.existing, isNull);
      },
    );

    test(
      'duplicate_cycle with an existing batch id loads and offers it',
      () async {
        final s = await ready([1]);
        server.active = _json({'data': <Object>[]});
        server.posts.add(
          _json({
            'error': 'duplicate_cycle',
            'existing_batch_id': 7,
            'domain_id': 1,
          }, 409),
        );
        server.detail = _json({
          'batch': _batch(7, items: [_item(1, 1, 'site1.com')]),
        });
        s.choose(InvoiceOption.keepInvoice);

        expect(await s.submit(), isFalse);

        expect(s.existing!.id, 7);
        expect(s.error, contains('already exists and is pending'));
        expect(server.created.length, 1, reason: 'one POST, never repeated');
        expect(gets('/renewal-batches/7'), hasLength(1));
      },
    );

    test('duplicate_cycle without an id falls back to the lookup', () async {
      final s = await ready([1]);
      server.posts.add(_json({'error': 'duplicate_cycle'}, 409));
      // The pre-check finds nothing; the lookup after the 409 finds batch 9.
      server.activeQueue.addAll([
        _json({'data': <Object>[]}),
        activeFor(1, _batch(9, items: [_item(1, 1, 'site1.com')])),
      ]);
      s.choose(InvoiceOption.keepInvoice);

      expect(await s.submit(), isFalse);

      expect(s.existing!.id, 9);
      expect(server.created.length, 1);
    });

    test(
      'duplicate_cycle that cannot be resolved keeps the plain message',
      () async {
        final s = await ready([1]);
        server.posts.add(_json({'error': 'duplicate_cycle'}, 409));
        s.choose(InvoiceOption.keepInvoice);

        expect(await s.submit(), isFalse);

        expect(s.existing, isNull);
        expect(s.error, contains('already open'));
      },
    );

    for (final (status, expected) in [
      ('pending', 'is pending'),
      ('processing', 'in progress'),
      ('needs_review', 'uncertain'),
      ('completed', 'already carried out'),
      ('partial', 'partly completed'),
      ('failed', 'failed'),
    ]) {
      test('the existing batch message is honest for $status', () {
        final b = RenewalBatch.fromJson(
          _batch(7, status: status, items: [_item(1, 1, 'site1.com')]),
        );

        final text = RenewalSubmitController.existingBatchMessage(b);

        expect(text, contains('site1.com'));
        expect(text, contains(expected));
      });
    }

    test('an uncertain batch tells the user not to renew again', () {
      final b = RenewalBatch.fromJson(_batch(7, status: 'needs_review'));

      expect(
        RenewalSubmitController.existingBatchMessage(b),
        contains('Do not renew again'),
      );
    });

    test('ApiException carries existing_batch_id from a 409 body', () async {
      server.posts.add(
        _json({'error': 'duplicate_cycle', 'existing_batch_id': 5}, 409),
      );

      try {
        await auth.api.createRenewalBatch(
          domainIds: [1],
          years: 1,
          invoiceOption: 'KeepInvoice',
          idempotencyKey: 'rn-0123456789abcdef',
        );
        fail('expected a 409');
      } on ApiException catch (e) {
        expect(e.statusCode, 409);
        expect(e.code, 'duplicate_cycle');
        expect(e.existingBatchId, 5);
      }
    });
  });

  group('existing batch screens', () {
    Future<void> openScreen(WidgetTester t) async {
      t.view.physicalSize = const Size(800, 2400);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.reset);
      final selected = SelectedRenewalController(
        api: auth.api,
        selected: [(id: 1, name: 'site1.com')],
      );
      await t.pumpWidget(
        MaterialApp(
          theme: AppTheme.light,
          home: SelectedRenewalScreen(
            controller: selected,
            submit: RenewalSubmitController(api: auth.api, selected: selected),
            api: auth.api,
          ),
        ),
      );
      await t.pumpAndSettle();
    }

    Future<void> tapCreate(WidgetTester t) async {
      await t.tap(find.byKey(const ValueKey('invoice-option-KeepInvoice')));
      await t.pumpAndSettle();
      final button = find.byKey(const ValueKey('submit-renewal'));
      await t.ensureVisible(button);
      await t.tap(button);
      await t.pumpAndSettle();
    }

    testWidgets('a duplicate_cycle response opens the existing batch', (
      t,
    ) async {
      server.posts.add(
        _json({'error': 'duplicate_cycle', 'existing_batch_id': 7}, 409),
      );
      server.detail = _json({
        'batch': _batch(7, items: [_item(1, 1, 'site1.com')]),
      });
      await openScreen(t);

      await tapCreate(t);

      expect(find.text('Renewal batch #7'), findsOneWidget);
      expect(find.byKey(const ValueKey('batch-banner')), findsOneWidget);
      expect(server.created.length, 1);
      expect(
        server.requests.where(
          (r) => r.method == 'POST' && r.url.path.endsWith('/renew'),
        ),
        isEmpty,
        reason: 'nothing is renewed automatically',
      );
    });

    testWidgets('a pre-check hit opens the batch without creating another', (
      t,
    ) async {
      final existing = _batch(7, items: [_item(1, 1, 'site1.com')]);
      server.active = _json({
        'data': [
          {'domain_id': 1, 'batch': existing},
        ],
      });
      server.detail = _json({'batch': existing});
      await openScreen(t);

      await tapCreate(t);

      expect(find.text('Renewal batch #7'), findsOneWidget);
      expect(server.created, isEmpty);
    });

    testWidgets('going back shows the message and an Open button', (t) async {
      final existing = _batch(7, items: [_item(1, 1, 'site1.com')]);
      server.active = _json({
        'data': [
          {'domain_id': 1, 'batch': existing},
        ],
      });
      server.detail = _json({'batch': existing});
      await openScreen(t);
      await tapCreate(t);

      await t.pageBack();
      await t.pumpAndSettle();

      expect(find.byKey(const ValueKey('submit-error')), findsOneWidget);
      final open = find.byKey(const ValueKey('open-existing-batch'));
      await t.ensureVisible(open);
      expect(find.text('Open existing batch #7'), findsOneWidget);
      await t.tap(open);
      await t.pumpAndSettle();
      expect(find.text('Renewal batch #7'), findsOneWidget);
      expect(server.created, isEmpty);
    });
  });

  group('screens', () {
    Future<void> open(WidgetTester t, List<int> ids) async {
      t.view.physicalSize = const Size(800, 2400);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.reset);
      final selected = SelectedRenewalController(
        api: auth.api,
        selected: [
          for (final id in ids)
            (id: id, name: id == 4 ? 'site4.in' : 'site$id.com'),
        ],
      );
      await t.pumpWidget(
        MaterialApp(
          theme: AppTheme.light,
          home: SelectedRenewalScreen(
            controller: selected,
            submit: RenewalSubmitController(api: auth.api, selected: selected),
            api: auth.api,
          ),
        ),
      );
      await t.pumpAndSettle();
    }

    const taxNote =
        'Tax and final payable amount must be confirmed in ResellerClub.';

    testWidgets(
      'both invoice options are offered, exclusive and unselected at first',
      (t) async {
        await open(t, [1, 2]);

        final keep = find.byKey(const ValueKey('invoice-option-KeepInvoice'));
        final none = find.byKey(const ValueKey('invoice-option-NoInvoice'));
        await t.ensureVisible(keep);
        expect(find.text("Keep Customers' Invoice(s) pending"), findsOneWidget);
        expect(find.text("Don't create Customers' Invoice(s)"), findsOneWidget);
        expect(keep, findsOneWidget);
        expect(none, findsOneWidget);
        expect(
          find.textContaining('Both options renew the domain'),
          findsNothing,
        );
        expect(find.text('Create renewal batch'), findsOneWidget);
        expect(
          t
              .widget<FilledButton>(
                find.byKey(const ValueKey('submit-renewal')),
              )
              .onPressed,
          isNull,
        );

        InvoiceOption? chosen() => t
            .widget<RadioGroup<InvoiceOption>>(
              find.byType(RadioGroup<InvoiceOption>),
            )
            .groupValue;
        expect(chosen(), isNull);
        await t.tap(keep);
        await t.pumpAndSettle();
        expect(find.byType(RadioListTile<InvoiceOption>), findsNWidgets(2));
        expect(chosen(), InvoiceOption.keepInvoice);

        await t.tap(none);
        await t.pumpAndSettle();
        expect(chosen(), InvoiceOption.noInvoice);
        expect(find.text(taxNote), findsOneWidget);
        expect(find.text('Combined renewal cost'), findsOneWidget);
        expect(find.text('₹2,438.00'), findsOneWidget);
      },
    );

    testWidgets(
      'unsupported domains stay listed and are excluded from the batch',
      (t) async {
        await open(t, [1, 4]);

        expect(find.text('No price available (1)'), findsOneWidget);
        expect(find.text('site4.in'), findsWidgets);
        expect(find.textContaining('Price unsupported'), findsOneWidget);
        expect(find.byKey(const ValueKey('review-excluded')), findsOneWidget);
        expect(
          find.textContaining('will not be included in the batch'),
          findsOneWidget,
        );
      },
    );

    for (final (option, label) in [
      ('KeepInvoice', "Keep Customers' Invoice(s) pending"),
      ('NoInvoice', "Don't create Customers' Invoice(s)"),
    ]) {
      testWidgets('creating a batch stores $option and opens it read-only', (
        t,
      ) async {
        server.posts.add(
          _json({'replayed': false, 'batch': _batch(7, option: option)}, 201),
        );
        server.detail = _json({'batch': _batch(7, option: option)});
        await open(t, [1, 2]);

        await t.tap(find.byKey(ValueKey('invoice-option-$option')));
        await t.pumpAndSettle();
        final button = find.byKey(const ValueKey('submit-renewal'));
        await t.ensureVisible(button);
        await t.tap(button);
        await t.pumpAndSettle();

        expect(body(server.created.single)['invoice_option'], option);
        expect(find.text('Renewal batch #7'), findsOneWidget);
        // The stored option is shown with its API value and cannot be edited.
        expect(find.text('$label ($option)'), findsOneWidget);
        expect(find.byType(Radio<InvoiceOption>), findsNothing);
        expect(find.byType(RadioListTile<InvoiceOption>), findsNothing);
      });
    }

    testWidgets('creating a batch sends no renewal request', (t) async {
      server.posts.add(created201(7));
      await open(t, [1, 2]);
      await t.tap(find.byKey(const ValueKey('invoice-option-KeepInvoice')));
      await t.pumpAndSettle();
      final button = find.byKey(const ValueKey('submit-renewal'));
      await t.ensureVisible(button);
      await t.tap(button);
      await t.pumpAndSettle();

      final writes = server.requests.where((r) => r.method != 'GET');
      expect(writes.length, 1);
      expect(writes.single.url.path, '/api/v1/renewal-batches');
    });

    testWidgets('batch detail shows backend status, counts and item figures', (
      t,
    ) async {
      t.view.physicalSize = const Size(800, 3000);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.reset);
      server.detail = _json({
        'batch': _batch(
          7,
          status: 'needs_review',
          items: [
            _item(1, 1, 'site1.com', state: 'pending'),
            _item(2, 2, 'site2.com', state: 'uncertain'),
            _item(3, 3, 'site3.com', state: 'failed'),
            _item(4, 4, 'site4.com', state: 'succeeded'),
          ],
          counts: {
            'pending': 1,
            'submitting': 0,
            'succeeded': 1,
            'failed': 1,
            'uncertain': 1,
          },
        ),
      });
      await t.pumpWidget(
        MaterialApp(
          theme: AppTheme.light,
          home: RenewalBatchScreen(
            controller: RenewalBatchDetailController(api: auth.api, batchId: 7),
          ),
        ),
      );
      await t.pumpAndSettle();

      final summary = find.byKey(const ValueKey('batch-summary'));
      expect(find.text('#7'), findsOneWidget);
      expect(
        find.descendant(of: summary, matching: find.text('Needs review')),
        findsOneWidget,
      );
      expect(
        find.descendant(of: summary, matching: find.text('4')),
        findsOneWidget,
      );
      expect(find.text('site2.com'), findsOneWidget);
      expect(find.text('₹1,219.00'), findsWidgets);
      expect(find.text('Uncertain'), findsWidgets);
      // Stored tax figures are labelled as unverified estimates.
      expect(find.byKey(const ValueKey('batch-tax-note')), findsOneWidget);
      expect(
        find.text(
          'Tax and final payable amount must be confirmed in ResellerClub.',
        ),
        findsOneWidget,
      );
      expect(
        find.textContaining('backend-calculated estimates'),
        findsOneWidget,
      );
      expect(find.text('GST (estimate)'), findsNWidgets(4));
      expect(find.text('VAT (estimate)'), findsNWidgets(4));
      expect(find.text('Total (estimate)'), findsNWidgets(4));
      expect(find.text('GST'), findsNothing);
      expect(find.text('VAT'), findsNothing);
      expect(find.text('Total'), findsNothing);
      expect(find.text('Estimated total'), findsNothing);
      expect(server.requests.last.url.path, '/api/v1/renewal-batches/7');
    });

    testWidgets('a pending batch is shown as Pending, never as renewed', (
      t,
    ) async {
      await t.pumpWidget(
        MaterialApp(
          theme: AppTheme.light,
          home: RenewalBatchScreen(
            controller: RenewalBatchDetailController(api: auth.api, batchId: 7),
          ),
        ),
      );
      await t.pumpAndSettle();

      expect(find.text('Pending'), findsWidgets);
      expect(find.text('Succeeded'), findsOneWidget); // only the count label
      expect(
        find.textContaining('Renewal request saved'),
        findsNothing,
      ); // not just created
      expect(find.textContaining('Batch status: Pending'), findsNothing);
      expect(
        find.textContaining('no renewal has been carried out'),
        findsOneWidget,
      );
    });

    testWidgets('batch detail shows an error with Retry when it cannot load', (
      t,
    ) async {
      server.detail = _json({'error': 'not_found'}, 404);
      await t.pumpWidget(
        MaterialApp(
          theme: AppTheme.light,
          home: RenewalBatchScreen(
            controller: RenewalBatchDetailController(api: auth.api, batchId: 9),
          ),
        ),
      );
      await t.pumpAndSettle();

      expect(find.text('That renewal batch was not found.'), findsOneWidget);
      expect(find.text('Retry'), findsOneWidget);
    });
  });

  group('history', () {
    Future<void> openHistory(WidgetTester t) async {
      await t.pumpWidget(
        MaterialApp(
          theme: AppTheme.light,
          home: RenewalHistoryScreen(
            controller: RenewalBatchListController(api: auth.api, perPage: 2),
            api: auth.api,
          ),
        ),
      );
      await t.pumpAndSettle();
    }

    testWidgets('empty state', (t) async {
      await openHistory(t);
      expect(find.text('No renewal batches yet.'), findsOneWidget);
    });

    testWidgets('error state with Retry', (t) async {
      server.list = _json({'error': 'server_error'}, 500);
      await openHistory(t);
      expect(find.textContaining('server_error'), findsOneWidget);
      expect(find.text('Retry'), findsOneWidget);
    });

    testWidgets('lists batches, paginates and opens a batch', (t) async {
      server.list = _json({
        'data': [
          _batch(9, items: <Map<String, Object?>>[])..remove('items'),
          _batch(8, status: 'failed', option: 'NoInvoice')..remove('items'),
        ],
        'meta': {'page': 1, 'per_page': 2, 'total': 3, 'total_pages': 2},
      });
      await openHistory(t);

      expect(find.text('Batch #9'), findsOneWidget);
      expect(find.text('Batch #8'), findsOneWidget);
      expect(find.text("Don't create Customers' Invoice(s)"), findsOneWidget);
      expect(find.text('Failed'), findsOneWidget);
      expect(find.byKey(const ValueKey('history-load-more')), findsOneWidget);

      server.list = _json({
        'data': [_batch(7)..remove('items')],
        'meta': {'page': 2, 'per_page': 2, 'total': 3, 'total_pages': 2},
      });
      await t.tap(find.byKey(const ValueKey('history-load-more')));
      await t.pumpAndSettle();
      expect(find.text('Batch #7'), findsOneWidget);
      expect(find.byKey(const ValueKey('history-load-more')), findsNothing);
      expect(
        server.requests
            .where((r) => r.url.path.endsWith('/renewal-batches'))
            .last
            .url
            .queryParameters['page'],
        '2',
      );

      await t.tap(find.text('Batch #7'));
      await t.pumpAndSettle();
      expect(find.text('Renewal batch #7'), findsOneWidget);
    });
  });

  group('execution safety', () {
    test('the app never references the provider or server-side execution', () {
      final forbidden = [
        'renew.json',
        'processItem',
        'processBatch',
        'renewDomain',
        'RENEWAL_EXECUTION',
      ];
      // The renewal and reconcile URLs exist only in the API client.
      final onlyInClient = ['/reconcile', '/renew'];
      for (final f in Directory(
        'lib',
      ).listSync(recursive: true).whereType<File>()) {
        if (!f.path.endsWith('.dart')) continue;
        final text = f.readAsStringSync();
        for (final word in forbidden) {
          expect(
            text.contains(word),
            isFalse,
            reason: '${f.path} contains $word',
          );
        }
        final path = f.path.replaceAll(String.fromCharCode(92), '/');
        if (!path.endsWith('core/api_client.dart')) {
          for (final word in onlyInClient) {
            expect(
              text.contains("$word'") || text.contains('$word"'),
              isFalse,
              reason: '${f.path} contains $word',
            );
          }
        }
      }
    });

    test(
      'nothing in the renewal code can run in the background or by timer',
      () {
        for (final name in [
          'lib/domains/renewal_execution.dart',
          'lib/screens/renewal_confirm_screen.dart',
          'lib/screens/renewal_batch_screen.dart',
        ]) {
          final text = File(name).readAsStringSync();
          for (final word in [
            'Timer',
            'Future.delayed',
            'didChangeAppLifecycleState',
            'WidgetsBindingObserver',
            'workmanager',
          ]) {
            expect(
              text.contains(word),
              isFalse,
              reason: '$name contains $word',
            );
          }
        }
      },
    );

    test(
      'the only renewal requests the app can make are the three batch endpoints',
      () async {
        final s = await ready([1]);
        server.posts.add(created201(7));
        s.choose(InvoiceOption.keepInvoice);
        await s.submit();
        await auth.api.listRenewalBatches();
        await auth.api.getRenewalBatch(7);

        final writes = server.requests.where((r) => r.method != 'GET').toList();
        expect(writes.length, 1);
        expect(writes.single.url.path, '/api/v1/renewal-batches');
      },
    );
  });
}

Iterable<String> keysOf(Map<String, dynamic> b) => b.keys;

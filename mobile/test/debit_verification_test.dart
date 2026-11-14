import 'dart:convert';

import 'package:domain_manager/auth/auth_controller.dart';
import 'package:domain_manager/auth/token_storage.dart';
import 'package:domain_manager/core/api_client.dart';
import 'package:domain_manager/domains/renewal_batch.dart';
import 'package:domain_manager/domains/renewal_execution.dart';
import 'package:domain_manager/screens/renewal_batch_screen.dart';
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

Map<String, Object?> _item({
  int id = 1,
  String state = 'succeeded',
  Map<String, Object?> debit = const {},
}) => {
  'id': id,
  'domain_id': id,
  'domain_name': 'site$id.com',
  'state': state,
  'expiry_at_request': '2030-01-15T00:00:00Z',
  'renewal_amount': '1219.00',
  'gst_amount': '219.42',
  'vat_amount': '0.00',
  'total_amount': '1438.42',
  'currency': 'INR',
  'provider_action_id': '111222',
  'provider_invoice_id': null,
  'error_code': null,
  'debit_verification_status': 'unverified',
  'debit_verified_at': null,
  'debit_transaction_id': null,
  'debit_verified_amount': null,
  'debit_verified_currency': null,
  'debit_verification_note': null,
  ...debit,
};

Map<String, Object?> _batch(List<Map<String, Object?>> items) => {
  'id': 7,
  'status': 'completed',
  'invoice_option': 'KeepInvoice',
  'years': 1,
  'item_counts': {
    'pending': 0,
    'submitting': 0,
    'succeeded': items.length,
    'failed': 0,
    'uncertain': 0,
  },
  'created_at': '2026-11-10T09:30:00Z',
  'items': items,
};

void main() {
  late List<http.Request> requests;
  late http.Response Function(http.Request) respond;
  late AuthController auth;

  setUp(() {
    requests = [];
    respond = (_) => _json({
      'batch': _batch([_item()]),
    });
    auth = AuthController(
      api: ApiClient(
        client: MockClient((r) async {
          requests.add(r);
          return respond(r);
        }),
        baseUrl: 'http://test/api/v1/',
      ),
      storage: _Storage(),
    )..status = AuthStatus.signedIn;
  });

  Future<void> open(WidgetTester t) async {
    t.view.physicalSize = const Size(800, 3000);
    t.view.devicePixelRatio = 1;
    addTearDown(t.view.reset);
    await t.pumpWidget(
      MaterialApp(
        theme: AppTheme.light,
        home: RenewalBatchScreen(
          controller: RenewalBatchDetailController(api: auth.api, batchId: 7),
          api: auth.api,
          attempts: MemoryRenewalAttemptStore(),
        ),
      ),
    );
    await t.pumpAndSettle();
  }

  group('model', () {
    test('missing or unknown debit status reads as unverified', () {
      final base = _item()..remove('debit_verification_status');
      expect(RenewalBatchItem.fromJson(base).debitStatus, 'unverified');
      final odd = _item(debit: {'debit_verification_status': 'paid'});
      expect(RenewalBatchItem.fromJson(odd).debitStatus, 'unverified');
    });

    test('the form is offered only for succeeded + unverified items', () {
      bool can(String state, String debit) => RenewalBatchItem.fromJson(
        _item(state: state, debit: {'debit_verification_status': debit}),
      ).canVerifyDebit;
      expect(can('succeeded', 'unverified'), isTrue);
      expect(can('succeeded', 'confirmed'), isFalse);
      expect(can('succeeded', 'not_found'), isFalse);
      expect(can('succeeded', 'disputed'), isFalse);
      for (final s in ['pending', 'submitting', 'failed', 'uncertain']) {
        expect(can(s, 'unverified'), isFalse, reason: s);
      }
    });

    test('labels', () {
      expect(renewalStateLabel('succeeded'), 'Successful');
      expect(renewalStateLabel('pending'), 'Pending');
      expect(renewalStateLabel('failed'), 'Failed');
      expect(renewalStateLabel('uncertain'), 'Uncertain');
      expect(debitStatusLabel('unverified'), 'Not verified');
      expect(debitStatusLabel('confirmed'), 'Confirmed');
      expect(debitStatusLabel('not_found'), 'Transaction not found');
      expect(debitStatusLabel('disputed'), 'Disputed');
    });
  });

  group('screen', () {
    testWidgets('shows both statuses separately; success is not a debit', (
      t,
    ) async {
      await open(t);

      expect(find.text('Domain renewal'), findsOneWidget);
      expect(find.text('Debit verification'), findsOneWidget);
      expect(find.text('Successful'), findsOneWidget);
      expect(find.text('Not verified'), findsOneWidget);
      expect(find.text('Confirmed'), findsNothing);
      expect(find.byKey(const ValueKey('verify-debit-1')), findsOneWidget);
      expect(find.byKey(const ValueKey('debit-details-1')), findsNothing);
    });

    testWidgets('failed, pending and uncertain items get no verify action', (
      t,
    ) async {
      respond = (_) => _json({
        'batch': _batch([
          _item(id: 1, state: 'failed'),
          _item(id: 2, state: 'uncertain'),
          _item(id: 3, state: 'pending'),
        ]),
      });
      await open(t);

      expect(find.byKey(const ValueKey('verify-debit-1')), findsNothing);
      expect(find.byKey(const ValueKey('verify-debit-2')), findsNothing);
      expect(find.byKey(const ValueKey('verify-debit-3')), findsNothing);
      expect(find.text('Failed'), findsWidgets);
      expect(find.text('Uncertain'), findsWidgets);
      expect(find.text('Not verified'), findsNWidgets(3));
    });

    testWidgets('each stored debit status is displayed with its details', (
      t,
    ) async {
      respond = (_) => _json({
        'batch': _batch([
          _item(
            id: 1,
            debit: {
              'debit_verification_status': 'confirmed',
              'debit_verified_at': '2026-11-11T10:00:00Z',
              'debit_transaction_id': 'TXN-1',
              'debit_verified_amount': '1438.42',
              'debit_verified_currency': 'INR',
              'debit_verification_note': 'Matched.',
            },
          ),
          _item(id: 2, debit: {'debit_verification_status': 'not_found'}),
          _item(id: 3, debit: {'debit_verification_status': 'disputed'}),
        ]),
      });
      await open(t);

      expect(find.text('Confirmed'), findsOneWidget);
      expect(find.text('Transaction not found'), findsOneWidget);
      expect(find.text('Disputed'), findsOneWidget);
      expect(find.text('Not verified'), findsNothing);
      expect(find.text('TXN-1'), findsOneWidget);
      expect(find.text('1438.42 INR'), findsOneWidget);
      expect(find.text('Matched.'), findsOneWidget);
      // Already verified: no manual action offered.
      expect(find.byKey(const ValueKey('verify-debit-1')), findsNothing);
      expect(find.byKey(const ValueKey('verify-debit-2')), findsNothing);
    });

    testWidgets('confirming needs every field and sends nothing until valid', (
      t,
    ) async {
      await open(t);
      final before = requests.length;

      await t.tap(find.byKey(const ValueKey('verify-debit-1')));
      await t.pumpAndSettle();
      await t.enterText(find.byKey(const ValueKey('debit-note')), 'Checked');
      await t.tap(find.byKey(const ValueKey('debit-save')));
      await t.pumpAndSettle();

      expect(find.byKey(const ValueKey('debit-error')), findsOneWidget);
      expect(requests.length, before);
      expect(find.text('Confirmed'), findsNothing);
    });

    testWidgets('submitting saves, then shows Confirmed and the details', (
      t,
    ) async {
      await open(t);

      respond = (r) {
        if (r.method == 'POST') {
          return _json({
            'replayed': false,
            'batch': _batch([
              _item(
                debit: {
                  'debit_verification_status': 'confirmed',
                  'debit_verified_at': '2026-11-11T10:00:00Z',
                  'debit_transaction_id': 'TXN-778899',
                  'debit_verified_amount': '1438.42',
                  'debit_verified_currency': 'INR',
                  'debit_verification_note': 'Matched in history.',
                },
              ),
            ]),
          });
        }
        return _json({
          'batch': _batch([_item()]),
        });
      };

      await t.tap(find.byKey(const ValueKey('verify-debit-1')));
      await t.pumpAndSettle();
      await t.enterText(find.byKey(const ValueKey('debit-tx')), 'TXN-778899');
      await t.enterText(find.byKey(const ValueKey('debit-amount')), '1438.42');
      await t.enterText(find.byKey(const ValueKey('debit-currency')), 'inr');
      await t.enterText(
        find.byKey(const ValueKey('debit-note')),
        'Matched in history.',
      );
      await t.tap(find.byKey(const ValueKey('debit-save')));
      await t.pumpAndSettle();

      final post = requests.lastWhere((r) => r.method == 'POST');
      expect(
        post.url.path,
        '/api/v1/renewal-batches/7/items/1/debit-verification',
      );
      expect(jsonDecode(post.body), {
        'status': 'confirmed',
        'transaction_id': 'TXN-778899',
        'amount': '1438.42',
        'currency': 'INR',
        'note': 'Matched in history.',
      });
      // Only verification was posted: never a renewal or a reconcile.
      expect(requests.where((r) => r.url.path.endsWith('/renew')), isEmpty);
      expect(requests.where((r) => r.url.path.endsWith('/reconcile')), isEmpty);

      expect(find.text('Confirmed'), findsOneWidget);
      expect(find.text('Successful'), findsOneWidget);
      expect(find.text('TXN-778899'), findsOneWidget);
      expect(find.text('1438.42 INR'), findsOneWidget);
      expect(find.text('Matched in history.'), findsOneWidget);
      expect(find.byKey(const ValueKey('verify-debit-1')), findsNothing);
    });

    testWidgets('a rejected submission never shows the debit as confirmed', (
      t,
    ) async {
      await open(t);
      respond = (r) => r.method == 'POST'
          ? _json({'error': 'item_not_succeeded'}, 409)
          : _json({
              'batch': _batch([_item()]),
            });

      await t.tap(find.byKey(const ValueKey('verify-debit-1')));
      await t.pumpAndSettle();
      await t.enterText(find.byKey(const ValueKey('debit-tx')), 'TXN-1');
      await t.enterText(find.byKey(const ValueKey('debit-amount')), '1438.42');
      await t.enterText(find.byKey(const ValueKey('debit-currency')), 'INR');
      await t.enterText(find.byKey(const ValueKey('debit-note')), 'n');
      await t.tap(find.byKey(const ValueKey('debit-save')));
      await t.pumpAndSettle();

      expect(
        find.text('Only a successfully renewed item can be verified.'),
        findsOneWidget,
      );
      expect(find.text('Confirmed'), findsNothing);
      expect(find.text('Not verified'), findsOneWidget);
    });
  });
}

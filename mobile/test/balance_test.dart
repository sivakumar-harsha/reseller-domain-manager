import 'dart:convert';

import 'package:domain_manager/auth/auth_controller.dart';
import 'package:domain_manager/auth/token_storage.dart';
import 'package:domain_manager/core/api_client.dart';
import 'package:domain_manager/domains/balance.dart';
import 'package:domain_manager/screens/bulk_sync_panel.dart';
import 'package:domain_manager/theme/app_theme.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

// Fake HTTP only: no network, no backend, no ResellerClub traffic.

class _Storage implements TokenStorage {
  TokenPair? tokens = const TokenPair(accessToken: 'a1', refreshToken: 'r1');
  @override
  Future<TokenPair?> read() async => tokens;
  @override
  Future<void> write(TokenPair tokens) async => this.tokens = tokens;
  @override
  Future<void> clear() async => tokens = null;
}

// What the backend returns for the verified provider response.
Map<String, Object?> _real({String sellingBalance = '2920.68'}) => {
  'selling_balance': sellingBalance,
  'selling_currency': 'USD',
  'selling_locked_balance': '0',
  'accounting_balance': '273867.87',
  'accounting_currency': 'INR',
  'accounting_locked_balance': '0',
};

void main() {
  group('Balance model', () {
    test('keeps selling and accounting separate, with locked balances', () {
      final b = Balance.fromJson(_real());

      expect(b.sellingCurrency, 'USD');
      expect(b.sellingBalance, '2920.68');
      expect(b.sellingLockedBalance, '0');
      expect(b.accountingCurrency, 'INR');
      expect(b.accountingBalance, '273867.87');
      expect(b.accountingLockedBalance, '0');
      expect(b.selling, 'USD 2,920.68');
      expect(b.accounting, 'INR 273,867.87');
      expect(b.sellingLocked, 'USD 0');
      expect(b.accountingLocked, 'INR 0');
    });

    test('currencies come from the response, never hardcoded or converted', () {
      final b = Balance.fromJson({
        ..._real(),
        'selling_currency': 'EUR',
        'accounting_currency': 'GBP',
      });

      expect(b.selling, startsWith('EUR '));
      expect(b.accounting, startsWith('GBP '));
      expect(b.selling, isNot(contains('USD')));
      expect(b.accounting, isNot(contains('INR')));
      expect(b.sellingBalance, '2920.68'); // not scaled by any rate
      expect(b.accountingBalance, '273867.87');
    });

    test('absent locked balances stay null, not zero', () {
      final json = _real()
        ..remove('selling_locked_balance')
        ..remove('accounting_locked_balance');
      final b = Balance.fromJson(json);

      expect(b.sellingLockedBalance, isNull);
      expect(b.sellingLocked, isNull);
      expect(b.accountingLocked, isNull);
      expect(b.selling, 'USD 2,920.68');
    });

    for (final key in [
      'selling_balance',
      'selling_currency',
      'accounting_balance',
      'accounting_currency',
    ]) {
      test('missing $key is rejected', () {
        final json = _real()..remove(key);
        expect(() => Balance.fromJson(json), throwsFormatException);
      });

      test('empty or non-string $key is rejected', () {
        expect(
          () => Balance.fromJson({..._real(), key: ''}),
          throwsFormatException,
        );
        expect(
          () => Balance.fromJson({..._real(), key: 12.5}),
          throwsFormatException,
        );
      });
    }

    test('an invalid locked balance is rejected', () {
      expect(
        () => Balance.fromJson({..._real(), 'selling_locked_balance': 5}),
        throwsFormatException,
      );
    });
  });

  group('BalanceController refresh', () {
    late List<http.Response Function()> script;
    late int calls;
    late BalanceController controller;

    setUp(() {
      script = [];
      calls = 0;
      final auth = AuthController(
        api: ApiClient(
          client: MockClient((r) async {
            expect(r.method, 'GET');
            expect(r.url.path, endsWith('/balance'));
            calls++;
            return script.removeAt(0)();
          }),
          baseUrl: 'http://test/api/v1/',
        ),
        storage: _Storage(),
      )..status = AuthStatus.signedIn;
      controller = BalanceController(api: auth.api);
      addTearDown(controller.dispose);
    });

    http.Response ok(Map<String, Object?> body) => http.Response(
      jsonEncode(body),
      200,
      headers: {'content-type': 'application/json'},
    );

    test('a reload shows the latest successful response', () async {
      script.add(() => ok(_real()));
      await controller.load();
      expect(controller.balance!.selling, 'USD 2,920.68');

      script.add(() => ok(_real(sellingBalance: '2800.10')));
      await controller.load();

      expect(calls, 2);
      expect(controller.balance!.selling, 'USD 2,800.10');
      expect(controller.balance!.accounting, 'INR 273,867.87');
    });

    test('a failed reload never keeps showing the old amount', () async {
      script.add(() => ok(_real()));
      await controller.load();
      expect(controller.balance, isNotNull);

      script.add(
        () => http.Response(
          jsonEncode({'error': 'provider_error'}),
          502,
          headers: {'content-type': 'application/json'},
        ),
      );
      await controller.load();

      expect(controller.balance, isNull);
      expect(controller.unavailable, isTrue);
    });

    test(
      'a malformed balance shows nothing instead of a wrong amount',
      () async {
        script.add(() => ok({..._real()..remove('selling_balance')}));
        await controller.load();

        expect(controller.balance, isNull);
        expect(controller.unavailable, isTrue);
      },
    );

    testWidgets('the inline balance shows selling currency and amount', (
      tester,
    ) async {
      script.add(() => ok(_real()));
      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.light,
          home: Scaffold(body: BalanceInline(controller: controller)),
        ),
      );
      await controller.load();
      await tester.pump();

      expect(find.textContaining('USD', findRichText: true), findsOneWidget);
      expect(
        find.textContaining('2,920.68', findRichText: true),
        findsOneWidget,
      );
      expect(find.textContaining('INR', findRichText: true), findsNothing);
      expect(find.textContaining('273,867', findRichText: true), findsNothing);
    });

    testWidgets('the inline balance is labelled Selling Balance (API)', (
      tester,
    ) async {
      final semantics = tester.ensureSemantics();
      script.add(() => ok(_real()));
      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.light,
          home: Scaffold(body: BalanceInline(controller: controller)),
        ),
      );
      await controller.load();
      await tester.pump();

      expect(
        find.bySemanticsLabel(RegExp('^Selling Balance \\(API\\)')),
        findsOneWidget,
      );
      expect(find.bySemanticsLabel(RegExp('Available balance')), findsNothing);
      semantics.dispose();
    });
  });
}

import 'dart:convert';

import 'package:domain_manager/auth/auth_controller.dart';
import 'package:domain_manager/auth/token_storage.dart';
import 'package:domain_manager/core/api_client.dart';
import 'package:domain_manager/domains/renewal_batch.dart';
import 'package:domain_manager/domains/renewal_execution.dart';
import 'package:domain_manager/domains/renewal_quote.dart';
import 'package:domain_manager/domains/renewal_submit.dart';
import 'package:domain_manager/domains/selected_renewal.dart';
import 'package:domain_manager/screens/renewal_quote_sheet.dart';
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

/// The backend's `eligibility` object for each state, as RenewalEligibility
/// emits it. The app only displays this verdict; it never computes one.
Map<String, Object?> _eligibility(String state) {
  switch (state) {
    case 'expired':
      return {
        'eligible': true,
        'state': 'expired',
        'reason': null,
        'expired': true,
        'expires_at': '2026-11-12T12:00:00Z',
        'days_since_expiry': 1,
      };
    case 'domain_not_active':
      return {
        'eligible': false,
        'state': 'domain_not_active',
        'reason': 'domain_not_active',
        'expired': false,
        'expires_at': '2027-01-01T12:00:00Z',
        'days_since_expiry': null,
      };
    default:
      return {
        'eligible': true,
        'state': 'pre_expiry',
        'reason': null,
        'expired': false,
        'expires_at': '2027-01-01T12:00:00Z',
        'days_since_expiry': null,
      };
  }
}

Map<String, Object?> _quote(int id, {Map<String, Object?>? eligibility}) => {
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
  'eligibility': ?eligibility,
};

void main() {
  group('RenewalEligibility parsing', () {
    test(
      'absent or malformed eligibility is null, and renewable stays true',
      () {
        expect(RenewalEligibility.tryParse(null), isNull);
        expect(RenewalEligibility.tryParse('x'), isNull);
        expect(RenewalEligibility.tryParse({'eligible': 'yes'}), isNull);
        expect(RenewalEligibility.tryParse({'eligible': true}), isNull);

        final q = RenewalQuote.fromJson(_quote(1));
        expect(q.eligibility, isNull);
        expect(q.renewable, isTrue);
      },
    );

    test('pre-expiry: shows the expiry date and no warning', () {
      final e = RenewalEligibility.tryParse(_eligibility('pre_expiry'))!;
      expect(e.eligible, isTrue);
      expect(e.expiryLine, 'Expires 2027-01-01');
      expect(e.warning, isNull);
    });

    test('expired: eligible, the request goes to ResellerClub to decide', () {
      final e = RenewalEligibility.tryParse(_eligibility('expired'))!;
      expect(e.eligible, isTrue);
      expect(e.expired, isTrue);
      expect(e.expiryLine, 'Expired 2026-11-12');
      expect(e.warning, contains('has expired'));
      expect(e.warning, contains('ResellerClub'));
      expect(e.warning, contains('decides'));
      expect(e.warning, isNot(contains('cannot be renewed')));
    });

    test('a non-Active state is blocked with its own text', () {
      final blocked = RenewalEligibility.tryParse(
        _eligibility('domain_not_active'),
      )!;
      expect(blocked.eligible, isFalse);
      expect(blocked.warning, contains('suspended, locked'));
      expect(blocked.warning, contains('redemption'));
    });

    test('an expired quote is renewable; a non-Active one is not', () {
      final expired = RenewalQuote.fromJson(
        _quote(1, eligibility: _eligibility('expired')),
      );
      expect(expired.available, isTrue);
      expect(expired.renewable, isTrue);

      final blocked = RenewalQuote.fromJson(
        _quote(1, eligibility: _eligibility('domain_not_active')),
      );
      expect(blocked.renewable, isFalse);
    });

    test('batch-creation eligibility errors have specific messages', () {
      for (final code in ['domain_not_active', 'expiry_unknown']) {
        final m = RenewalSubmitController.messageFor(ApiException(422, code));
        expect(m, isNot(contains('Could not create')), reason: code);
      }
    });
  });

  group('provider response', () {
    test('the ResellerClub message is parsed from the item and the result', () {
      final item = RenewalBatchItem.fromJson({
        'id': 1,
        'domain_id': 2,
        'domain_name': 'site2.com',
        'state': 'failed',
        'currency': 'INR',
        'renewal_amount': '1219.00',
        'gst_amount': '219.42',
        'vat_amount': '0.00',
        'total_amount': '1438.42',
        'error_code': 'provider_rejected',
        'provider_message': 'The domain has expired and cannot be renewed.',
      });
      expect(
        item.providerMessage,
        'The domain has expired and cannot be renewed.',
      );
      expect(item.errorCode, 'provider_rejected');

      final result = RenewalExecutionResponse.fromJson({
        'item': {
          'id': 1,
          'state': 'failed',
          'error_code': 'provider_rejected',
          'provider_message': 'Not allowed',
        },
      });
      expect(result.providerMessage, 'Not allowed');
      expect(result.definitive, isTrue);
    });

    test('a missing message is null, not an empty string', () {
      final result = RenewalExecutionResponse.fromJson({
        'item': {'id': 1, 'state': 'failed'},
      });
      expect(result.providerMessage, isNull);
    });
  });

  group('screens', () {
    late List<http.Request> requests;
    late Map<int, Map<String, Object?>?> eligibilityById;
    late AuthController auth;

    setUp(() {
      requests = [];
      eligibilityById = {};
      auth = AuthController(
        api: ApiClient(
          client: MockClient((r) async {
            requests.add(r);
            final m = RegExp(
              r'domains/(\d+)/renewal-quote',
            ).firstMatch(r.url.path);
            if (m != null) {
              final id = int.parse(m.group(1)!);
              return http.Response(
                jsonEncode(_quote(id, eligibility: eligibilityById[id])),
                200,
                headers: {'content-type': 'application/json'},
              );
            }
            if (r.url.path.endsWith('/renewal-batches/active')) {
              return http.Response(
                jsonEncode({'data': []}),
                200,
                headers: {'content-type': 'application/json'},
              );
            }
            return http.Response('{}', 404);
          }),
          baseUrl: 'http://test/api/v1/',
        ),
        storage: _Storage(),
      )..status = AuthStatus.signedIn;
    });

    Future<RenewalSubmitController> openSelected(
      WidgetTester t,
      List<int> ids,
    ) async {
      t.view.physicalSize = const Size(800, 2400);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.reset);
      final selected = SelectedRenewalController(
        api: auth.api,
        selected: [for (final id in ids) (id: id, name: 'site$id.com')],
      );
      final submit = RenewalSubmitController(api: auth.api, selected: selected);
      await t.pumpWidget(
        MaterialApp(
          theme: AppTheme.light,
          home: SelectedRenewalScreen(
            controller: selected,
            submit: submit,
            api: auth.api,
          ),
        ),
      );
      await t.pumpAndSettle();
      return submit;
    }

    testWidgets('an expired domain shows its expiry and the note, '
        'and stays in the batch', (t) async {
      eligibilityById[1] = _eligibility('expired');
      final submit = await openSelected(t, [1]);

      expect(find.text('Expired 2026-11-12'), findsOneWidget);
      expect(
        find.byKey(const ValueKey('expiry-warning-site1.com')),
        findsOneWidget,
      );
      expect(find.textContaining('ResellerClub, which decides'), findsWidgets);
      expect(submit.domainIds, [1]);
      expect(submit.blocked, isEmpty);
    });

    testWidgets('a non-Active domain is listed as blocked '
        'and excluded from the batch and totals', (t) async {
      eligibilityById[1] = _eligibility('pre_expiry');
      eligibilityById[2] = _eligibility('domain_not_active');
      final submit = await openSelected(t, [1, 2]);

      expect(find.byKey(const ValueKey('blocked-2')), findsOneWidget);
      expect(find.text('Cannot be renewed here (1)'), findsOneWidget);
      expect(find.textContaining('suspended, locked'), findsOneWidget);
      expect(submit.domainIds, [
        1,
      ], reason: 'only the renewable domain is sent');
      expect(submit.blocked.map((e) => e.id), [2]);
      // The blocked domain's price is not part of the combined cost.
      expect(submit.totals!.amount, '1219.00');
      expect(find.byKey(const ValueKey('review-blocked')), findsOneWidget);
      await t.pumpAndSettle();
      expect(
        requests.where((r) => r.method == 'POST'),
        isEmpty,
        reason: 'viewing never creates a batch',
      );
    });

    testWidgets('suspended, locked or redemption (not Active) is blocked', (
      t,
    ) async {
      eligibilityById[1] = _eligibility('domain_not_active');
      final submit = await openSelected(t, [1]);

      expect(find.byKey(const ValueKey('blocked-1')), findsOneWidget);
      expect(find.textContaining('suspended, locked'), findsOneWidget);
      expect(submit.domainIds, isEmpty);
    });

    testWidgets('submitting with only blocked domains sends nothing', (
      t,
    ) async {
      eligibilityById[1] = _eligibility('domain_not_active');
      final submit = await openSelected(t, [1]);
      submit.choose(InvoiceOption.keepInvoice);

      final ok = await submit.submit();
      expect(ok, isFalse);
      expect(submit.error, contains('can be renewed from this app'));
      expect(requests.where((r) => r.method == 'POST'), isEmpty);
    });

    testWidgets('the quote sheet shows the expiry date and the warning', (
      t,
    ) async {
      eligibilityById[1] = _eligibility('expired');
      await t.pumpWidget(
        MaterialApp(
          theme: AppTheme.light,
          home: Scaffold(
            body: RenewalQuoteSheet(
              controller: RenewalQuoteController(api: auth.api, domainId: 1),
              domainName: 'site1.com',
            ),
          ),
        ),
      );
      await t.pumpAndSettle();

      expect(find.text('Expired 2026-11-12'), findsOneWidget);
      expect(find.byKey(const ValueKey('expiry-warning')), findsOneWidget);
      expect(find.textContaining('decides'), findsOneWidget);
    });
  });
}

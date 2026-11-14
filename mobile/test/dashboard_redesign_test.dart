import 'dart:async';
import 'dart:convert';

import 'package:domain_manager/auth/auth_controller.dart';
import 'package:domain_manager/auth/token_storage.dart';
import 'package:domain_manager/core/api_client.dart';
import 'package:domain_manager/domains/domain_controller.dart';
import 'package:domain_manager/domains/expiry.dart';
import 'package:domain_manager/screens/home_screen.dart';
import 'package:domain_manager/theme/app_theme.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
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

Map<String, Object?> _page() => {
  'data': [
    {
      'id': 1,
      'domain_name': 'alpha.com',
      'status': 'Active',
      'expires_at': '2030-01-15T00:00:00Z',
      'auto_renew': null,
      'privacy_enabled': true,
      'registrar_lock': false,
      'is_missing': false,
      'last_synced_at': '2026-05-01T10:30:00Z',
    },
  ],
  'meta': {'page': 1, 'per_page': 20, 'total': 1, 'total_pages': 1},
  'last_synced_at': '2026-05-01T10:30:00Z',
};

const _counts = {
  'expired': 11,
  'within_7_days': 22,
  'within_30_days': 33,
  'within_60_days': 44,
  'within_90_days': 55,
  'unknown': 66,
  'as_of_date': '2026-10-30',
  'timezone': 'Asia/Kolkata',
};

const _zeros = {
  'expired': 0,
  'within_7_days': 0,
  'within_30_days': 0,
  'within_60_days': 0,
  'within_90_days': 0,
  'unknown': 0,
};

const _tiles = <(ExpiryFilter, String, String)>[
  (ExpiryFilter.expired, 'Expired', '11'),
  (ExpiryFilter.within7, 'Within 7 days', '22'),
  (ExpiryFilter.within30, 'Within 30 days', '33'),
  (ExpiryFilter.within60, 'Within 60 days', '44'),
  (ExpiryFilter.within90, 'Within 90 days', '55'),
  (ExpiryFilter.unknown, 'Unknown expiry', '66'),
];

const _initialError = 'Could not load the expiry counts.';
const _staleError =
    'Could not refresh the counts. Showing the last known values.';

enum _Summary { loaded, loading, error, stale, zeros }

/// A signed-in HomeScreen on the Dashboard with a scriptable summary endpoint.
class _Rig {
  _Rig(this.mode) {
    final client = MockClient((req) async {
      if (req.url.path.endsWith('expiry/summary')) {
        summaryCalls++;
        if (mode == _Summary.loading) {
          await gate.future;
          return _json(200, _counts);
        }
        if (failSummary) return _json(500, {'error': 'server_error'});
        return _json(200, mode == _Summary.zeros ? _zeros : _counts);
      }
      if (req.url.path.endsWith('/balance')) {
        return _json(500, {'error': 'server_error'});
      }
      listCalls.add(req.url);
      return _json(200, _page());
    });
    auth = AuthController(
      api: ApiClient(client: client, baseUrl: 'http://test/api/v1/'),
      storage: _Storage(),
    )..status = AuthStatus.signedIn;
    domains = DomainController(api: auth.api);
    failSummary = mode == _Summary.error;
  }

  final _Summary mode;
  final gate = Completer<void>();
  final listCalls = <Uri>[];
  int summaryCalls = 0;
  bool failSummary = false;
  late final AuthController auth;
  late final DomainController domains;

  Widget get widget => MaterialApp(
    theme: AppTheme.light,
    home: HomeScreen(key: ValueKey(mode), auth: auth, domains: domains),
  );

  /// Pumps the app to the state [mode] describes.
  Future<void> show(WidgetTester tester) async {
    await tester.pumpWidget(widget);
    if (mode == _Summary.loading) {
      await tester.pump(); // the summary request stays pending
      return;
    }
    await tester.pumpAndSettle();
    if (mode == _Summary.stale) {
      failSummary = true;
      await domains.loadSummary();
      await tester.pumpAndSettle();
    }
  }
}

Finder _tile(ExpiryFilter f) =>
    find.byKey(ValueKey('expiry-tile-${f.apiValue}'));

Material _tileMaterial(WidgetTester tester, ExpiryFilter f) =>
    tester.widget<Material>(
      find.descendant(of: _tile(f), matching: find.byType(Material)).first,
    );

void _setView(WidgetTester tester, Size size, double scale) {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1;
  tester.platformDispatcher.textScaleFactorTestValue = scale;
  addTearDown(tester.view.reset);
  addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
}

Future<void> _openDrawer(WidgetTester tester) async {
  await tester.tap(find.byTooltip('Open navigation menu'));
  await tester.pumpAndSettle();
}

void main() {
  group('summary tiles', () {
    testWidgets('six tiles keep their keys, labels and API counts', (
      tester,
    ) async {
      final rig = _Rig(_Summary.loaded);
      await rig.show(tester);
      for (final (f, label, n) in _tiles) {
        expect(_tile(f), findsOneWidget);
        expect(
          find.descendant(of: _tile(f), matching: find.text(label)),
          findsOneWidget,
        );
        expect(
          find.descendant(of: _tile(f), matching: find.text(n)),
          findsOneWidget,
        );
      }
      expect(find.text('Expiry overview'), findsOneWidget);
      expect(rig.summaryCalls, 1);
    });

    testWidgets('Expired is danger and Within 7 days is warning', (
      tester,
    ) async {
      await _Rig(_Summary.loaded).show(tester);
      final scheme = AppTheme.light.colorScheme;
      expect(
        _tileMaterial(tester, ExpiryFilter.expired).color,
        scheme.errorContainer,
      );
      expect(
        _tileMaterial(tester, ExpiryFilter.within7).color,
        isNot(scheme.surface),
      );
      expect(
        _tileMaterial(tester, ExpiryFilter.within7).color,
        isNot(scheme.errorContainer),
      );
      // Their counts take the tone colour too.
      final expiredCount = tester.widget<Text>(
        find.descendant(
          of: _tile(ExpiryFilter.expired),
          matching: find.text('11'),
        ),
      );
      expect(expiredCount.style!.color, scheme.onErrorContainer);
    });

    testWidgets('the other four tiles are neutral', (tester) async {
      await _Rig(_Summary.loaded).show(tester);
      final scheme = AppTheme.light.colorScheme;
      for (final f in [
        ExpiryFilter.within30,
        ExpiryFilter.within60,
        ExpiryFilter.within90,
        ExpiryFilter.unknown,
      ]) {
        expect(_tileMaterial(tester, f).color, scheme.surface, reason: '$f');
        final count = tester.widget<Text>(
          find.descendant(
            of: _tile(f),
            matching: find.byWidgetPredicate(
              (w) => w is Text && w.style?.fontWeight == FontWeight.w700,
            ),
          ),
        );
        expect(count.style!.color, scheme.onSurface);
      }
    });

    testWidgets('every tile has a label and an icon, not just colour', (
      tester,
    ) async {
      await _Rig(_Summary.loaded).show(tester);
      final icons = <ExpiryFilter, IconData>{
        ExpiryFilter.expired: Icons.cancel_outlined,
        ExpiryFilter.within7: Icons.warning_amber_rounded,
        ExpiryFilter.within30: Icons.schedule,
        ExpiryFilter.within60: Icons.schedule,
        ExpiryFilter.within90: Icons.schedule,
        ExpiryFilter.unknown: Icons.help_outline,
      };
      for (final (f, label, _) in _tiles) {
        expect(
          find.descendant(of: _tile(f), matching: find.byIcon(icons[f]!)),
          findsOneWidget,
          reason: '$label icon',
        );
        expect(
          find.descendant(of: _tile(f), matching: find.text(label)),
          findsOneWidget,
        );
      }
      // The two urgent tiles are told apart from the rest by shape too.
      expect(icons[ExpiryFilter.expired], isNot(icons[ExpiryFilter.within30]));
      expect(icons[ExpiryFilter.within7], isNot(icons[ExpiryFilter.within30]));
    });

    testWidgets('counts use tabular figures', (tester) async {
      await _Rig(_Summary.loaded).show(tester);
      final t = tester.widget<Text>(
        find.descendant(
          of: _tile(ExpiryFilter.within30),
          matching: find.text('33'),
        ),
      );
      expect(t.style!.fontFeatures!.map((f) => f.feature), contains('tnum'));
    });

    testWidgets('the semantics label keeps the "label: value" format', (
      tester,
    ) async {
      final semantics = tester.ensureSemantics();
      await _Rig(_Summary.loaded).show(tester);
      for (final (_, label, n) in _tiles) {
        expect(find.bySemanticsLabel('$label: $n'), findsOneWidget);
      }
      semantics.dispose();
    });

    testWidgets('tiles are at least 48dp', (tester) async {
      await _Rig(_Summary.zeros).show(tester);
      for (final (f, _, _) in _tiles) {
        final size = tester.getSize(_tile(f));
        expect(size.height, greaterThanOrEqualTo(48));
        expect(size.width, greaterThanOrEqualTo(48));
      }
    });

    testWidgets('all counts of zero are shown as 0', (tester) async {
      await _Rig(_Summary.zeros).show(tester);
      for (final (f, _, _) in _tiles) {
        expect(
          find.descendant(of: _tile(f), matching: find.text('0')),
          findsOneWidget,
        );
      }
      expect(find.byKey(const ValueKey('expiry-error')), findsNothing);
    });

    testWidgets('the as-of date is shown as a caption', (tester) async {
      await _Rig(_Summary.loaded).show(tester);
      expect(find.text('As of 2026-10-30 (Asia/Kolkata)'), findsOneWidget);
    });
  });

  group('loading', () {
    testWidgets('shows neutral placeholders with semantics, not an ellipsis', (
      tester,
    ) async {
      final semantics = tester.ensureSemantics();
      final rig = _Rig(_Summary.loading);
      await rig.show(tester);
      expect(find.text('…'), findsNothing);
      expect(find.byKey(const ValueKey('tile-loading')), findsNWidgets(6));
      expect(find.bySemanticsLabel('Loading expiry counts'), findsOneWidget);
      expect(find.bySemanticsLabel('Expired: loading'), findsOneWidget);
      // Placeholders are static: nothing keeps animating.
      expect(tester.hasRunningAnimations, isFalse);

      rig.gate.complete();
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('tile-loading')), findsNothing);
      expect(
        find.descendant(
          of: _tile(ExpiryFilter.expired),
          matching: find.text('11'),
        ),
        findsOneWidget,
      );
      semantics.dispose();
    });
  });

  group('errors', () {
    testWidgets('an initial failure is a danger banner with Retry summary', (
      tester,
    ) async {
      final rig = _Rig(_Summary.error);
      await rig.show(tester);
      expect(find.text(_initialError), findsOneWidget);
      expect(find.text(_staleError), findsNothing);
      expect(find.byIcon(Icons.cancel_outlined), findsWidgets);
      expect(find.text('Retry summary'), findsOneWidget);
      // The list is not affected.
      expect(rig.listCalls.length, 1);
      for (final (f, _, _) in _tiles) {
        expect(
          find.descendant(of: _tile(f), matching: find.text('–')),
          findsOneWidget,
        );
      }
    });

    testWidgets('a failed refresh keeps the counts and shows a warning', (
      tester,
    ) async {
      final rig = _Rig(_Summary.stale);
      await rig.show(tester);
      expect(find.text(_staleError), findsOneWidget);
      expect(find.text(_initialError), findsNothing);
      expect(find.byIcon(Icons.warning_amber_rounded), findsWidgets);
      expect(
        find.descendant(
          of: _tile(ExpiryFilter.expired),
          matching: find.text('11'),
        ),
        findsOneWidget,
      );
      expect(rig.listCalls.length, 1);
    });

    testWidgets('Retry summary retries once and recovers', (tester) async {
      final rig = _Rig(_Summary.error);
      await rig.show(tester);
      expect(rig.summaryCalls, 1);
      rig.failSummary = false;
      await tester.tap(find.text('Retry summary'));
      await tester.pumpAndSettle();
      expect(rig.summaryCalls, 2);
      expect(find.text('Retry summary'), findsNothing);
      expect(find.text(_initialError), findsNothing);
      expect(
        find.descendant(
          of: _tile(ExpiryFilter.expired),
          matching: find.text('11'),
        ),
        findsOneWidget,
      );
      expect(rig.listCalls.length, 1);
    });
  });

  group('behaviour', () {
    testWidgets('tapping a tile filters the list, sends no summary request', (
      tester,
    ) async {
      final rig = _Rig(_Summary.loaded);
      await rig.show(tester);
      await tester.tap(_tile(ExpiryFilter.within7));
      await tester.pumpAndSettle();
      expect(_tile(ExpiryFilter.within7), findsNothing); // now on Domains
      expect(rig.listCalls.last.queryParameters['expiry'], 'within_7_days');
      expect(
        tester
            .widget<ChoiceChip>(
              find.byKey(const ValueKey('expiry-chip-within_7_days')),
            )
            .selected,
        isTrue,
      );
      expect(rig.summaryCalls, 1);
    });

    testWidgets('switching screens sends no further summary requests', (
      tester,
    ) async {
      final rig = _Rig(_Summary.loaded);
      await rig.show(tester);
      for (var i = 0; i < 2; i++) {
        await _openDrawer(tester);
        await tester.tap(find.widgetWithText(ListTile, 'Domains'));
        await tester.pumpAndSettle();
        await _openDrawer(tester);
        await tester.tap(find.widgetWithText(ListTile, 'Dashboard'));
        await tester.pumpAndSettle();
      }
      expect(rig.summaryCalls, 1);
      expect(rig.listCalls.length, 1);
    });

    testWidgets('pull to refresh still reloads the summary and the list', (
      tester,
    ) async {
      final rig = _Rig(_Summary.loaded);
      await rig.show(tester);
      await tester.fling(
        find.byType(ListView).first,
        const Offset(0, 400),
        1000,
      );
      await tester.pumpAndSettle();
      expect(rig.summaryCalls, 2);
      expect(rig.listCalls.length, 2);
    });
  });

  group('app bar and drawer', () {
    testWidgets('keeps the tooltip, the title and a working Log out', (
      tester,
    ) async {
      await _Rig(_Summary.loaded).show(tester);
      expect(find.byTooltip('Open navigation menu'), findsOneWidget);
      expect(find.text('ResellerClub Domain Manager'), findsOneWidget);
      expect(find.byTooltip('Log out'), findsOneWidget);
    });

    testWidgets('the selected destination has a non-colour cue', (
      tester,
    ) async {
      await _Rig(_Summary.loaded).show(tester);
      await _openDrawer(tester);
      final dash = find.widgetWithText(ListTile, 'Dashboard');
      final dom = find.widgetWithText(ListTile, 'Domains');
      expect(tester.widget<ListTile>(dash).selected, isTrue);
      expect(tester.widget<ListTile>(dom).selected, isFalse);
      // Filled icon and a check mark on the selected one only.
      expect(
        find.descendant(of: dash, matching: find.byIcon(Icons.dashboard)),
        findsOneWidget,
      );
      expect(
        find.descendant(of: dash, matching: find.byIcon(Icons.check)),
        findsOneWidget,
      );
      expect(
        find.descendant(of: dom, matching: find.byIcon(Icons.dns_outlined)),
        findsOneWidget,
      );
      expect(
        find.descendant(of: dom, matching: find.byIcon(Icons.check)),
        findsNothing,
      );
      // And an outline around it.
      final shape =
          tester.widget<ListTile>(dash).shape! as RoundedRectangleBorder;
      expect(shape.side.width, greaterThan(0));
      expect(
        (tester.widget<ListTile>(dom).shape! as RoundedRectangleBorder)
            .side
            .style,
        BorderStyle.none,
      );
      // Bold label.
      expect(
        tester
            .widget<Text>(
              find.descendant(of: dash, matching: find.text('Dashboard')),
            )
            .style!
            .fontWeight,
        FontWeight.w700,
      );
    });

    testWidgets('the cue follows the destination', (tester) async {
      await _Rig(_Summary.loaded).show(tester);
      await _openDrawer(tester);
      await tester.tap(find.widgetWithText(ListTile, 'Domains'));
      await tester.pumpAndSettle();
      await _openDrawer(tester);
      final dom = find.widgetWithText(ListTile, 'Domains');
      expect(tester.widget<ListTile>(dom).selected, isTrue);
      expect(
        find.descendant(of: dom, matching: find.byIcon(Icons.check)),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: find.widgetWithText(ListTile, 'Dashboard'),
          matching: find.byIcon(Icons.check),
        ),
        findsNothing,
      );
    });

    testWidgets('Renewal history keeps its key and stays a drawer action', (
      tester,
    ) async {
      await _Rig(_Summary.loaded).show(tester);
      await _openDrawer(tester);
      expect(
        find.byKey(const ValueKey('drawer-renewal-history')),
        findsOneWidget,
      );
      expect(find.text('Renewal history'), findsOneWidget);
    });
  });

  group('layout', () {
    for (final size in const [
      Size(320, 568),
      Size(360, 740),
      Size(412, 892),
      Size(1024, 768),
    ]) {
      for (final scale in const [1.0, 2.0]) {
        final name = '${size.width.toInt()}x${size.height.toInt()}, ${scale}x';
        testWidgets('dashboard: no overflow, readable tiles at $name', (
          tester,
        ) async {
          _setView(tester, size, scale);
          for (final mode in _Summary.values) {
            final rig = _Rig(mode);
            await rig.show(tester);
            expect(tester.takeException(), isNull, reason: '$mode');

            // The AppBar's two lines fit inside it.
            final bar = tester.getRect(find.byType(AppBar));
            final app = tester.getRect(
              find.text('ResellerClub Domain Manager'),
            );
            final title = tester.getRect(find.text('Dashboard').first);
            expect(app.top, greaterThanOrEqualTo(bar.top), reason: '$mode');
            expect(
              title.bottom,
              lessThanOrEqualTo(bar.bottom),
              reason: '$mode',
            );

            // Every tile is on screen to reach, wide and tall enough to tap,
            // and its label is shown whole inside it.
            for (final (f, label, _) in _tiles) {
              await tester.ensureVisible(_tile(f));
              final box = tester.getRect(_tile(f));
              expect(box.width, greaterThanOrEqualTo(48), reason: '$mode $f');
              expect(box.height, greaterThanOrEqualTo(48), reason: '$mode $f');
              final text = tester.getRect(
                find.descendant(of: _tile(f), matching: find.text(label)),
              );
              expect(box.contains(text.topLeft), isTrue, reason: label);
              expect(
                box.contains(text.bottomRight - const Offset(.1, .1)),
                isTrue,
                reason: '$label not cut off',
              );
              final para = tester.renderObject<RenderParagraph>(
                find.descendant(of: _tile(f), matching: find.text(label)),
              );
              expect(para.didExceedMaxLines, isFalse, reason: label);
            }
            if (mode == _Summary.error || mode == _Summary.stale) {
              await tester.ensureVisible(find.text('Retry summary'));
            }
            expect(tester.takeException(), isNull, reason: '$mode');
            if (mode == _Summary.loading) {
              rig.gate.complete(); // release the pending request
              await tester.pumpAndSettle();
            }
          }
        });

        testWidgets('drawer: no overflow, all entries reachable at $name', (
          tester,
        ) async {
          _setView(tester, size, scale);
          await _Rig(_Summary.loaded).show(tester);
          await _openDrawer(tester);
          expect(tester.takeException(), isNull);
          await tester.ensureVisible(
            find.byKey(const ValueKey('drawer-renewal-history')),
          );
          await tester.ensureVisible(find.widgetWithText(ListTile, 'Domains'));
          expect(tester.takeException(), isNull);
        });
      }
    }

    testWidgets(
      'two columns when there is room, one at large text on a phone',
      (tester) async {
        _setView(tester, const Size(360, 740), 1.0);
        await _Rig(_Summary.loaded).show(tester);
        expect(
          tester.getTopLeft(_tile(ExpiryFilter.within7)).dy,
          tester.getTopLeft(_tile(ExpiryFilter.expired)).dy,
        );

        _setView(tester, const Size(360, 740), 2.0);
        await _Rig(_Summary.zeros).show(tester);
        expect(
          tester.getTopLeft(_tile(ExpiryFilter.within7)).dy,
          greaterThan(tester.getTopLeft(_tile(ExpiryFilter.expired)).dy),
        );
      },
    );
  });
}

import 'dart:io';

import 'package:domain_manager/app.dart';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:domain_manager/auth/auth_controller.dart';
import 'package:domain_manager/auth/token_storage.dart';
import 'package:domain_manager/core/api_client.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:domain_manager/theme/app_colors.dart';
import 'package:domain_manager/theme/app_theme.dart';
import 'package:domain_manager/widgets/info_row.dart';
import 'package:domain_manager/widgets/money_text.dart';
import 'package:domain_manager/widgets/outcome_banner.dart';
import 'package:domain_manager/widgets/pressable.dart';
import 'package:domain_manager/widgets/section_card.dart';
import 'package:domain_manager/widgets/state_view.dart';
import 'package:domain_manager/widgets/status_badge.dart';

double _ratio(Color a, Color b) {
  final la = a.computeLuminance();
  final lb = b.computeLuminance();
  final hi = la > lb ? la : lb;
  final lo = la > lb ? lb : la;
  return (hi + 0.05) / (lo + 0.05);
}

Widget _host(Widget child, {double width = 360, double textScale = 1}) =>
    MaterialApp(
      theme: AppTheme.light,
      home: MediaQuery(
        data: MediaQueryData(
          size: Size(width, 800),
          textScaler: TextScaler.linear(textScale),
        ),
        child: Scaffold(body: SingleChildScrollView(child: child)),
      ),
    );

void main() {
  group('light-only', () {
    test('theme is light and there is no dark theme API', () {
      expect(AppTheme.light.brightness, Brightness.light);
      expect(AppTheme.light.colorScheme.brightness, Brightness.light);
    });

    testWidgets('App forces light and passes no darkTheme', (tester) async {
      final auth = _auth();
      await tester.pumpWidget(App(auth: auth));
      final app = tester.widget<MaterialApp>(find.byType(MaterialApp));
      expect(app.themeMode, ThemeMode.light);
      expect(app.darkTheme, isNull);
    });

    testWidgets('stays light when the platform is in dark mode', (
      tester,
    ) async {
      tester.platformDispatcher.platformBrightnessTestValue = Brightness.dark;
      addTearDown(tester.platformDispatcher.clearPlatformBrightnessTestValue);
      await tester.pumpWidget(App(auth: _auth()));
      final ctx = tester.element(find.byType(Scaffold).first);
      expect(Theme.of(ctx).brightness, Brightness.light);
      expect(Theme.of(ctx).scaffoldBackgroundColor, AppColors.background);
    });

    test('native night resources do not use a dark parent theme', () {
      final f = File('android/app/src/main/res/values-night/styles.xml');
      final xml = f.readAsStringSync();
      expect(xml, isNot(contains('Theme.Black')));
      expect(xml, isNot(contains('Theme.DeviceDefault')));
      expect(xml, contains('Theme.Light.NoTitleBar'));
    });
  });

  group('contrast (WCAG AA, 4.5:1 for text)', () {
    final s = StatusColors.light;
    final scheme = AppTheme.light.colorScheme;
    final pairs = <String, (Color, Color)>{
      'text on surface': (AppColors.text, AppColors.surface),
      'text on background': (AppColors.text, AppColors.background),
      'muted on surface': (AppColors.textMuted, AppColors.surface),
      'muted on background': (AppColors.textMuted, AppColors.background),
      'onPrimary on primary': (scheme.onPrimary, scheme.primary),
      'accent badge': (scheme.onPrimaryContainer, scheme.primaryContainer),
      'neutral badge': (s.neutral, s.neutralContainer),
      'success badge': (s.success, s.successContainer),
      'caution badge': (s.caution, s.warningContainer),
      'warning badge': (s.warning, s.warningContainer),
      'danger badge': (scheme.onErrorContainer, scheme.errorContainer),
      'error on surface': (scheme.error, AppColors.surface),
      'success on surface': (s.success, AppColors.surface),
      'warning on surface': (s.warning, AppColors.surface),
      'caution on surface': (s.caution, AppColors.surface),
    };
    pairs.forEach((name, p) {
      test(name, () => expect(_ratio(p.$1, p.$2), greaterThanOrEqualTo(4.5)));
    });
  });

  group('shared widgets', () {
    testWidgets('StatusBadge shows icon and label for every tone', (
      tester,
    ) async {
      await tester.pumpWidget(
        _host(
          Column(
            children: [
              for (final t in BadgeTone.values)
                StatusBadge(label: 'Label ${t.name}', tone: t),
            ],
          ),
        ),
      );
      for (final t in BadgeTone.values) {
        expect(find.text('Label ${t.name}'), findsOneWidget);
      }
      expect(find.byType(Icon), findsNWidgets(BadgeTone.values.length));
    });

    testWidgets('StatusBadge wraps a long label without overflow', (
      tester,
    ) async {
      await tester.pumpWidget(
        _host(
          const SizedBox(
            width: 120,
            child: StatusBadge(
              label: 'Pending Delete Restorable',
              tone: BadgeTone.warning,
            ),
          ),
          width: 320,
          textScale: 2,
        ),
      );
      expect(tester.takeException(), isNull);
    });

    testWidgets('InfoRow wraps long values at 320dp and 2.0x font', (
      tester,
    ) async {
      await tester.pumpWidget(
        _host(
          const InfoRow(
            label: 'Nameservers',
            value:
                'ns1.a-very-long-nameserver-hostname-for-testing.example-registry.co.uk',
            selectable: true,
          ),
          width: 320,
          textScale: 2,
        ),
      );
      expect(tester.takeException(), isNull);
      expect(find.byType(SelectableText), findsOneWidget);
    });

    testWidgets('stacked InfoRow puts the label above a full, untruncated '
        'value at 320dp and 2.0x font', (tester) async {
      const address =
          '1234 The Extraordinarily Long Street Name Boulevard\n'
          'Llanfairpwllgwyngyllgogerychwyrndrobwllllantysiliogogogoch\n'
          'United Kingdom of Great Britain and Northern Ireland';
      await tester.pumpWidget(
        _host(
          const InfoRow(
            label: 'Address',
            value: address,
            stacked: true,
            selectable: true,
          ),
          width: 320,
          textScale: 2,
        ),
      );
      expect(tester.takeException(), isNull);
      final label = tester.getTopLeft(find.text('Address'));
      final value = tester.getTopLeft(find.byType(SelectableText));
      expect(value.dy, greaterThan(label.dy)); // below, not beside
      expect(value.dx, label.dx); // both start-aligned
      // Never capped: the SelectableText has no line limit.
      expect(
        tester.widget<SelectableText>(find.byType(SelectableText)).maxLines,
        isNull,
      );
      // The default (non-stacked) layout is unchanged.
      await tester.pumpWidget(
        _host(
          const InfoRow(label: 'Address', value: 'x'),
          width: 320,
          textScale: 2,
        ),
      );
      final row = tester.getTopLeft(find.text('Address'));
      final side = tester.getTopLeft(find.text('x'));
      expect(side.dx, greaterThan(row.dx));
    });

    testWidgets('MoneyText uses tabular figures and never alters the text', (
      tester,
    ) async {
      await tester.pumpWidget(_host(const MoneyText('₹ 1,234.50')));
      final t = tester.widget<Text>(find.text('₹ 1,234.50'));
      expect(t.style!.fontFeatures!.map((f) => f.feature), contains('tnum'));
      expect(t.style!.fontWeight, FontWeight.w700);
    });

    testWidgets('SectionCard renders title, trailing and child', (
      tester,
    ) async {
      await tester.pumpWidget(
        _host(
          const SectionCard(
            title: 'Overview',
            trailing: Text('T'),
            emphasis: true,
            child: Text('Body'),
          ),
        ),
      );
      expect(find.text('Overview'), findsOneWidget);
      expect(find.text('T'), findsOneWidget);
      expect(find.text('Body'), findsOneWidget);
    });

    testWidgets('StateView loading / empty / error and retry', (tester) async {
      var retried = 0;
      await tester.pumpWidget(
        _host(
          SizedBox(
            height: 600,
            child: StateView.error(
              message: 'Could not load.',
              onRetry: () => retried++,
            ),
          ),
        ),
      );
      expect(find.text('Could not load.'), findsOneWidget);
      await tester.tap(find.text('Retry'));
      expect(retried, 1);

      await tester.pumpWidget(
        _host(
          const SizedBox(height: 600, child: StateView.empty(message: 'None')),
        ),
      );
      expect(find.text('None'), findsOneWidget);
      expect(find.text('Retry'), findsNothing);

      await tester.pumpWidget(
        _host(const SizedBox(height: 600, child: StateView.loading())),
      );
      expect(find.byType(CircularProgressIndicator), findsOneWidget);

      await tester.pumpWidget(_host(const SkeletonRows(count: 3)));
      expect(tester.takeException(), isNull);
    });

    testWidgets('Pressable scales on press and never blocks the tap', (
      tester,
    ) async {
      var taps = 0;
      await tester.pumpWidget(
        _host(
          Pressable(
            child: FilledButton(
              onPressed: () => taps++,
              child: const Text('Go'),
            ),
          ),
        ),
      );
      double scale() =>
          tester.widget<AnimatedScale>(find.byType(AnimatedScale)).scale;
      expect(scale(), 1);
      final g = await tester.startGesture(tester.getCenter(find.text('Go')));
      await tester.pump();
      expect(scale(), closeTo(0.97, 1e-9));
      await g.up();
      await tester.pump();
      expect(scale(), 1);
      expect(taps, 1);
    });

    testWidgets('Pressable motion is instant under reduced motion', (
      tester,
    ) async {
      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.light,
          home: const MediaQuery(
            data: MediaQueryData(disableAnimations: true),
            child: Pressable(child: SizedBox(width: 10, height: 10)),
          ),
        ),
      );
      expect(
        tester.widget<AnimatedScale>(find.byType(AnimatedScale)).duration,
        Duration.zero,
      );
    });

    testWidgets('OutcomeBanner shows content, switches, no overflow', (
      tester,
    ) async {
      await tester.pumpWidget(
        _host(
          const OutcomeBanner(
            tone: BadgeTone.success,
            title: 'Renewal succeeded.',
          ),
          width: 320,
          textScale: 2,
        ),
      );
      expect(find.text('Renewal succeeded.'), findsOneWidget);
      await tester.pumpWidget(
        _host(
          const OutcomeBanner(
            tone: BadgeTone.warning,
            title: 'Outcome not confirmed. Do not renew again.',
            message: 'Check status first.',
          ),
          width: 320,
          textScale: 2,
        ),
      );
      await tester.pumpAndSettle();
      expect(
        find.text('Outcome not confirmed. Do not renew again.'),
        findsOneWidget,
      );
      expect(find.text('Renewal succeeded.'), findsNothing);
      expect(tester.takeException(), isNull);
    });
  });
}

class _NoStorage implements TokenStorage {
  @override
  Future<TokenPair?> read() async => null;
  @override
  Future<void> write(TokenPair tokens) async {}
  @override
  Future<void> clear() async {}
}

AuthController _auth() => AuthController(
  api: ApiClient(
    client: MockClient((_) async => http.Response('{}', 200)),
    baseUrl: 'http://test/api/v1/',
  ),
  storage: _NoStorage(),
);

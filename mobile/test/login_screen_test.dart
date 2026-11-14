import 'dart:async';
import 'dart:convert';

import 'package:domain_manager/app.dart';
import 'package:domain_manager/auth/auth_controller.dart';
import 'package:domain_manager/auth/token_storage.dart';
import 'package:domain_manager/core/api_client.dart';
import 'package:domain_manager/screens/login_screen.dart';
import 'package:domain_manager/theme/app_theme.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

class _MemoryStorage implements TokenStorage {
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

AuthController _auth(MockClient client, _MemoryStorage storage) {
  final api = ApiClient(client: client, baseUrl: 'http://test/api/v1/');
  return AuthController(api: api, storage: storage)
    ..status = AuthStatus.signedOut;
}

Widget _host(AuthController auth) => MaterialApp(
  theme: AppTheme.light,
  home: LoginScreen(auth: auth),
);

Finder get _emailField => find.widgetWithText(TextFormField, 'Email');
Finder get _passwordField => find.widgetWithText(TextFormField, 'Password');

bool _obscured(WidgetTester tester) => tester
    .widget<EditableText>(
      find.descendant(of: _passwordField, matching: find.byType(EditableText)),
    )
    .obscureText;

void main() {
  group('login form', () {
    testWidgets('shows the brand, both fields and one Sign in button', (
      tester,
    ) async {
      await tester.pumpWidget(
        _host(_auth(MockClient((_) async => _json(204, {})), _MemoryStorage())),
      );
      expect(find.text('Domain Manager'), findsOneWidget);
      expect(_emailField, findsOneWidget);
      expect(_passwordField, findsOneWidget);
      expect(find.text('Sign in'), findsOneWidget);
      expect(find.byKey(const ValueKey('login-error')), findsNothing);
    });

    testWidgets('the password toggle shows and hides it, keeping the text', (
      tester,
    ) async {
      await tester.pumpWidget(
        _host(_auth(MockClient((_) async => _json(204, {})), _MemoryStorage())),
      );
      await tester.enterText(_passwordField, 'hunter2');
      expect(_obscured(tester), isTrue);
      expect(find.byTooltip('Show password'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('toggle-password')));
      await tester.pump();
      expect(_obscured(tester), isFalse);
      expect(find.byTooltip('Hide password'), findsOneWidget);
      expect(find.text('hunter2'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('toggle-password')));
      await tester.pump();
      expect(_obscured(tester), isTrue);
    });

    testWidgets('the toggle is at least 48dp', (tester) async {
      await tester.pumpWidget(
        _host(_auth(MockClient((_) async => _json(204, {})), _MemoryStorage())),
      );
      final size = tester.getSize(
        find.byKey(const ValueKey('toggle-password')),
      );
      expect(size.width, greaterThanOrEqualTo(48));
      expect(size.height, greaterThanOrEqualTo(48));
    });

    testWidgets('empty fields show validation and send nothing', (
      tester,
    ) async {
      var calls = 0;
      await tester.pumpWidget(
        _host(
          _auth(
            MockClient((_) async {
              calls++;
              return _json(200, {});
            }),
            _MemoryStorage(),
          ),
        ),
      );
      await tester.tap(find.text('Sign in'));
      await tester.pump();
      expect(find.text('Enter your email'), findsOneWidget);
      expect(find.text('Enter your password'), findsOneWidget);
      expect(calls, 0);
    });

    testWidgets('Next moves to the password; Done on it submits', (
      tester,
    ) async {
      final paths = <String>[];
      await tester.pumpWidget(
        _host(
          _auth(
            MockClient((req) async {
              paths.add(req.url.path);
              return _json(401, {'error': 'invalid_credentials'});
            }),
            _MemoryStorage(),
          ),
        ),
      );
      await tester.tap(_emailField);
      await tester.enterText(_emailField, 'o@example.com');
      await tester.testTextInput.receiveAction(TextInputAction.next);
      await tester.pump();
      final focused = FocusManager.instance.primaryFocus!;
      expect(
        find.descendant(
          of: _passwordField,
          matching: find.byWidgetPredicate(
            (w) => w is EditableText && w.focusNode == focused,
          ),
        ),
        findsOneWidget,
      );
      expect(paths, isEmpty);

      await tester.enterText(_passwordField, 'pw');
      await tester.testTextInput.receiveAction(TextInputAction.done);
      await tester.pumpAndSettle();
      expect(paths, ['/api/v1/auth/login']);
    });
  });

  group('authentication result', () {
    testWidgets('a rejected login shows the error inline and stores nothing', (
      tester,
    ) async {
      final storage = _MemoryStorage();
      final auth = _auth(
        MockClient((_) async => _json(401, {'error': 'invalid_credentials'})),
        storage,
      );
      await tester.pumpWidget(_host(auth));
      await tester.enterText(_emailField, 'o@example.com');
      await tester.enterText(_passwordField, 'bad');
      await tester.tap(find.text('Sign in'));
      await tester.pumpAndSettle();

      expect(find.byKey(const ValueKey('login-error')), findsOneWidget);
      expect(find.text('Incorrect email or password.'), findsOneWidget);
      expect(auth.status, AuthStatus.signedOut);
      expect(storage.tokens, isNull);
      // The form is still there with what was typed, ready for another try.
      expect(find.text('Sign in'), findsOneWidget);
      expect(find.text('o@example.com'), findsOneWidget);
    });

    testWidgets('the error clears when the next attempt starts', (
      tester,
    ) async {
      final gate = Completer<void>();
      var first = true;
      final auth = _auth(
        MockClient((_) async {
          if (first) {
            first = false;
            return _json(401, {'error': 'invalid_credentials'});
          }
          await gate.future;
          return _json(401, {'error': 'invalid_credentials'});
        }),
        _MemoryStorage(),
      );
      await tester.pumpWidget(_host(auth));
      await tester.enterText(_emailField, 'o@example.com');
      await tester.enterText(_passwordField, 'bad');
      await tester.tap(find.text('Sign in'));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('login-error')), findsOneWidget);

      await tester.tap(find.text('Sign in'));
      await tester.pump();
      expect(find.byKey(const ValueKey('login-error')), findsNothing);
      gate.complete();
      await tester.pumpAndSettle();
    });

    testWidgets('while signing in the button is disabled and shows a spinner', (
      tester,
    ) async {
      final gate = Completer<void>();
      final auth = _auth(
        MockClient((_) async {
          await gate.future;
          return _json(401, {'error': 'invalid_credentials'});
        }),
        _MemoryStorage(),
      );
      await tester.pumpWidget(_host(auth));
      await tester.enterText(_emailField, 'o@example.com');
      await tester.enterText(_passwordField, 'pw');
      await tester.tap(find.text('Sign in'));
      await tester.pump();

      expect(find.text('Sign in'), findsNothing);
      final button = find.byType(FilledButton);
      expect(tester.widget<FilledButton>(button).onPressed, isNull);
      final spinner = tester.widget<CircularProgressIndicator>(
        find.descendant(
          of: button,
          matching: find.byType(CircularProgressIndicator),
        ),
      );
      // Visible on the primary-coloured button.
      expect(spinner.color, AppTheme.light.colorScheme.onPrimary);

      gate.complete();
      await tester.pumpAndSettle();
      expect(find.text('Sign in'), findsOneWidget);
    });

    test('a successful login signs in and stores the tokens', () async {
      final storage = _MemoryStorage();
      final auth = _auth(
        MockClient((req) async {
          if (req.url.path.endsWith('auth/login')) {
            return _json(200, {'access_token': 'a', 'refresh_token': 'r'});
          }
          return _json(200, {'id': 1, 'email': 'o@example.com'});
        }),
        storage,
      );
      await auth.login('o@example.com', 'pw');
      expect(auth.status, AuthStatus.signedIn);
      expect(storage.tokens?.refreshToken, 'r');
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
        testWidgets(
          'no overflow at ${size.width.toInt()}x${size.height.toInt()}, '
          '${scale}x text, with an error and the keyboard open',
          (tester) async {
            tester.view.physicalSize = size;
            tester.view.devicePixelRatio = 1;
            tester.platformDispatcher.textScaleFactorTestValue = scale;
            addTearDown(tester.view.reset);
            addTearDown(
              tester.platformDispatcher.clearTextScaleFactorTestValue,
            );
            final auth =
                _auth(
                    MockClient(
                      (_) async => _json(500, {'error': 'server_error'}),
                    ),
                    _MemoryStorage(),
                  )
                  ..errorMessage =
                      'The server is having trouble right now, so we could not '
                      'check your details. Please try again in a few minutes.';
            await tester.pumpWidget(_host(auth));
            expect(tester.takeException(), isNull);
            expect(find.byKey(const ValueKey('login-error')), findsOneWidget);

            // Keyboard up: the form scrolls instead of overflowing.
            tester.view.viewInsets = FakeViewPadding(bottom: size.height * 0.4);
            addTearDown(tester.view.resetViewInsets);
            await tester.pumpAndSettle();
            expect(tester.takeException(), isNull);

            // Everything is still reachable by scrolling.
            await tester.ensureVisible(find.byType(FilledButton));
            await tester.ensureVisible(_emailField);
            expect(tester.takeException(), isNull);
          },
        );
      }
    }
  });

  group('startup', () {
    testWidgets('shows the brand and one spinner, not the login form', (
      tester,
    ) async {
      final auth = AuthController(
        api: ApiClient(
          client: MockClient((_) async => _json(204, {})),
          baseUrl: 'http://test/api/v1/',
        ),
        storage: _MemoryStorage(),
      );
      expect(auth.status, AuthStatus.unknown);
      await tester.pumpWidget(App(auth: auth));
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
      expect(find.text('Domain Manager'), findsWidgets);
      expect(find.text('Sign in'), findsNothing);
      expect(_emailField, findsNothing);
    });

    testWidgets('moves to the login form once the session is known', (
      tester,
    ) async {
      final auth = AuthController(
        api: ApiClient(
          client: MockClient((_) async => _json(204, {})),
          baseUrl: 'http://test/api/v1/',
        ),
        storage: _MemoryStorage(),
      );
      await tester.pumpWidget(App(auth: auth));
      await tester.runAsync(auth.restore); // no tokens: signed out
      await tester.pump();
      expect(find.byType(CircularProgressIndicator), findsNothing);
      expect(find.text('Sign in'), findsOneWidget);
    });

    testWidgets('fits 320dp at 2.0x text', (tester) async {
      tester.view.physicalSize = const Size(320, 480);
      tester.view.devicePixelRatio = 1;
      tester.platformDispatcher.textScaleFactorTestValue = 2;
      addTearDown(tester.view.reset);
      addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
      final auth = AuthController(
        api: ApiClient(
          client: MockClient((_) async => _json(204, {})),
          baseUrl: 'http://test/api/v1/',
        ),
        storage: _MemoryStorage(),
      );
      await tester.pumpWidget(App(auth: auth));
      expect(tester.takeException(), isNull);
    });
  });
}

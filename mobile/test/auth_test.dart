import 'dart:convert';

import 'package:domain_manager/app.dart';
import 'package:domain_manager/auth/auth_controller.dart';
import 'package:domain_manager/auth/token_storage.dart';
import 'package:domain_manager/core/api_client.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

class MemoryStorage implements TokenStorage {
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

AuthController _controller(MockClient client, MemoryStorage storage) =>
    AuthController(
      api: ApiClient(client: client, baseUrl: 'http://test/api/v1/'),
      storage: storage,
    );

void main() {
  test('login stores tokens and loads the user', () async {
    final storage = MemoryStorage();
    final auth = _controller(
      MockClient((req) async {
        if (req.url.path.endsWith('auth/login')) {
          return _json(200, {'access_token': 'a', 'refresh_token': 'r'});
        }
        expect(req.headers['Authorization'], 'Bearer a');
        return _json(200, {'id': 1, 'email': 'o@example.com'});
      }),
      storage,
    );
    await auth.login('o@example.com', 'pw');
    expect(auth.status, AuthStatus.signedIn);
    expect(storage.tokens?.refreshToken, 'r');
  });

  test('bad credentials show an error and store nothing', () async {
    final storage = MemoryStorage();
    final auth = _controller(
      MockClient((_) async => _json(401, {'error': 'invalid_credentials'})),
      storage,
    );
    await auth.login('o@example.com', 'bad');
    expect(auth.status, AuthStatus.unknown);
    expect(auth.errorMessage, 'Incorrect email or password.');
    expect(storage.tokens, isNull);
  });

  test('restore refreshes an expired access token', () async {
    final storage = MemoryStorage()
      ..tokens = const TokenPair(accessToken: 'old', refreshToken: 'r1');
    final auth = _controller(
      MockClient((req) async {
        if (req.url.path.endsWith('auth/refresh')) {
          return _json(200, {'access_token': 'new', 'refresh_token': 'r2'});
        }
        if (req.headers['Authorization'] == 'Bearer old') {
          return _json(401, {'error': 'unauthorized'});
        }
        return _json(200, {'id': 1, 'email': 'o@example.com'});
      }),
      storage,
    );
    await auth.restore();
    expect(auth.status, AuthStatus.signedIn);
    expect(storage.tokens?.refreshToken, 'r2');
  });

  test(
    'restore with a rejected refresh token signs out and clears tokens',
    () async {
      final storage = MemoryStorage()
        ..tokens = const TokenPair(accessToken: 'old', refreshToken: 'r1');
      final log = <String>[];
      final auth = _controller(
        MockClient((req) async {
          log.add(req.url.path);
          return _json(401, {'error': 'unauthorized'});
        }),
        storage,
      );
      await auth.restore();
      expect(auth.status, AuthStatus.signedOut);
      expect(auth.user, isNull);
      expect(storage.tokens, isNull);
      expect(auth.errorMessage, isNull);
      // One me, one refresh: no retry loop after the rejection.
      expect(log, ['/api/v1/auth/me', '/api/v1/auth/refresh']);
    },
  );

  test(
    'restore keeps tokens when the refresh fails with a server error',
    () async {
      final storage = MemoryStorage()
        ..tokens = const TokenPair(accessToken: 'old', refreshToken: 'r1');
      final auth = _controller(
        MockClient(
          (req) async => req.url.path.endsWith('auth/refresh')
              ? _json(500, {'error': 'server_error'})
              : _json(401, {'error': 'unauthorized'}),
        ),
        storage,
      );
      await auth.restore();
      expect(auth.status, AuthStatus.signedOut);
      expect(storage.tokens?.refreshToken, 'r1');
      expect(auth.errorMessage, contains('server_error'));
    },
  );

  testWidgets('a rejected refresh at startup shows the login form', (
    tester,
  ) async {
    final storage = MemoryStorage()
      ..tokens = const TokenPair(accessToken: 'old', refreshToken: 'r1');
    final auth = _controller(
      MockClient((_) async => _json(401, {'error': 'unauthorized'})),
      storage,
    );
    await tester.runAsync(auth.restore);
    await tester.pumpWidget(App(auth: auth));
    expect(find.text('Sign in'), findsOneWidget);
    expect(storage.tokens, isNull);
  });

  test(
    'logout clears local tokens even if the server is unreachable',
    () async {
      final storage = MemoryStorage()
        ..tokens = const TokenPair(accessToken: 'a', refreshToken: 'r');
      final auth = _controller(
        MockClient((_) async => throw Exception('offline')),
        storage,
      );
      await auth.logout();
      expect(auth.status, AuthStatus.signedOut);
      expect(storage.tokens, isNull);
    },
  );

  testWidgets('signed-out state shows the login form', (tester) async {
    final auth = _controller(
      MockClient((_) async => _json(204, {})),
      MemoryStorage(),
    );
    await auth.restore();
    await tester.pumpWidget(App(auth: auth));
    expect(find.text('Sign in'), findsOneWidget);
  });
}

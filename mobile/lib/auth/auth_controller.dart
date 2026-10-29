import 'package:flutter/foundation.dart';

import '../core/api_client.dart';
import 'token_storage.dart';

enum AuthStatus { unknown, signedOut, signedIn }

class AuthController extends ChangeNotifier implements SessionAuthorizer {
  AuthController({required ApiClient api, required TokenStorage storage})
      : _api = api,
        _storage = storage {
    api.authorizer = this;
  }

  final ApiClient _api;
  final TokenStorage _storage;

  /// The client that data calls go through; its requests use [run].
  ApiClient get api => _api;

  AuthStatus status = AuthStatus.unknown;
  User? user;
  String? errorMessage;
  bool busy = false;

  /// Restores a stored session at startup, refreshing the access token if needed.
  Future<void> restore() async {
    final tokens = await _storage.read();
    if (tokens == null) return _setSignedOut();

    try {
      user = await _api.me(tokens.accessToken);
      status = AuthStatus.signedIn;
      notifyListeners();
    } on ApiException catch (e) {
      if (e.statusCode == 401) {
        await _refreshAndLoad(tokens);
      } else {
        // Offline or server trouble: keep tokens so a later start can resume.
        _setSignedOut(message: _message(e));
      }
    }
  }

  Future<void> _refreshAndLoad(TokenPair old) async {
    try {
      // Refresh tokens are single-use: persist the new pair immediately.
      final fresh = await _api.refresh(old.refreshToken);
      await _storage.write(fresh);
      user = await _api.me(fresh.accessToken);
      status = AuthStatus.signedIn;
      notifyListeners();
    } on ApiException catch (e) {
      if (e.statusCode == 401) await _storage.clear();
      _setSignedOut(message: e.statusCode == 401 ? null : _message(e));
    }
  }

  Future<void> login(String email, String password) async {
    busy = true;
    errorMessage = null;
    notifyListeners();
    try {
      final tokens = await _api.login(email.trim(), password);
      await _storage.write(tokens);
      user = await _api.me(tokens.accessToken);
      status = AuthStatus.signedIn;
    } on ApiException catch (e) {
      errorMessage = _message(e);
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  Future<void> logout() async {
    final tokens = await _storage.read();
    // Always clear locally, even if the server call fails.
    await _storage.clear();
    user = null;
    status = AuthStatus.signedOut;
    errorMessage = null;
    notifyListeners();
    if (tokens != null) {
      try {
        await _api.logout(
          refreshToken: tokens.refreshToken,
          accessToken: tokens.accessToken,
        );
      } on ApiException {
        // Best effort.
      }
    }
  }

  // Tail of the queue of token refreshes. Refresh tokens are single-use, so
  // only one refresh may be in flight at a time.
  Future<void> _refreshQueue = Future<void>.value();

  @override
  Future<T> run<T>(Future<T> Function(String accessToken) call) async {
    final tokens = await _storage.read();
    if (tokens == null) throw ApiException(401, 'unauthorized');

    try {
      return await call(tokens.accessToken);
    } on ApiException catch (e) {
      if (e.statusCode != 401) rethrow;
    }

    // Expired access token: refresh once, then retry once.
    final fresh = await _refreshAfter(tokens.accessToken);
    try {
      return await call(fresh);
    } on ApiException catch (e) {
      if (e.statusCode == 401) await _expireSession();
      rethrow;
    }
  }

  /// Returns a usable access token after [staleAccess] was rejected. Callers
  /// are serialised; a caller arriving after another already refreshed reuses
  /// the stored pair instead of spending the refresh token a second time.
  Future<String> _refreshAfter(String staleAccess) {
    final result = _refreshQueue.then((_) => _refreshOnce(staleAccess));
    _refreshQueue = result.then<void>((_) {}, onError: (_) {});
    return result;
  }

  Future<String> _refreshOnce(String staleAccess) async {
    final current = await _storage.read();
    if (current == null) throw ApiException(401, 'unauthorized'); // logged out
    if (current.accessToken != staleAccess) return current.accessToken;

    final TokenPair fresh;
    try {
      fresh = await _api.refresh(current.refreshToken);
    } on ApiException catch (e) {
      // A definitive rejection ends the session. Offline, rate limiting and
      // server trouble keep the tokens so a later attempt can still work.
      final rejected = e.statusCode >= 400 &&
          e.statusCode < 500 &&
          e.statusCode != 408 &&
          e.statusCode != 429;
      if (rejected) await _expireSession();
      rethrow;
    }

    try {
      await _storage.write(fresh);
    } on Object {
      // The old refresh token is spent and the new pair could not be saved.
      await _expireSession();
      throw ApiException(0, 'storage_error');
    }
    return fresh.accessToken;
  }

  Future<void> _expireSession() async {
    if (status != AuthStatus.signedIn) return;
    await _storage.clear();
    _setSignedOut(message: 'Your session has expired. Please sign in again.');
  }

  void _setSignedOut({String? message}) {
    user = null;
    status = AuthStatus.signedOut;
    errorMessage = message;
    notifyListeners();
  }

  static String _message(ApiException e) {
    switch (e.code) {
      case 'invalid_credentials':
        return 'Incorrect email or password.';
      case 'account_locked':
        final s = e.retryAfter;
        return s == null
            ? 'Account temporarily locked. Try again later.'
            : 'Account temporarily locked. Try again in $s seconds.';
      case 'too_many_requests':
        return 'Too many attempts. Please wait a moment.';
      case 'network_error':
        return 'Cannot reach the server. Check the API address and that the backend is running.';
      case 'invalid_request':
        return 'Please enter a valid email and password.';
      default:
        return 'Something went wrong (${e.code}).';
    }
  }
}

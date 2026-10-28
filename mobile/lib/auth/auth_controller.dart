import 'package:flutter/foundation.dart';

import '../core/api_client.dart';
import 'token_storage.dart';

enum AuthStatus { unknown, signedOut, signedIn }

class AuthController extends ChangeNotifier {
  AuthController({required ApiClient api, required TokenStorage storage})
      : _api = api,
        _storage = storage;

  final ApiClient _api;
  final TokenStorage _storage;

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

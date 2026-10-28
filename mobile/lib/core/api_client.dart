import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import 'config.dart';

class ApiException implements Exception {
  ApiException(this.statusCode, this.code, {this.retryAfter});

  /// 0 when the server could not be reached.
  final int statusCode;
  final String code;
  final int? retryAfter;

  @override
  String toString() => 'ApiException($statusCode, $code)';
}

class TokenPair {
  const TokenPair({required this.accessToken, required this.refreshToken});

  final String accessToken;
  final String refreshToken;
}

class User {
  const User({required this.id, required this.email});

  final int id;
  final String email;
}

/// Thin JSON client for the backend auth endpoints. Never logs bodies or tokens.
class ApiClient {
  ApiClient({http.Client? client, String baseUrl = apiBaseUrl})
      : _client = client ?? http.Client(),
        _base = baseUrl.endsWith('/') ? baseUrl : '$baseUrl/';

  final http.Client _client;
  final String _base;

  Future<TokenPair> login(String email, String password) async {
    final json = await _send('POST', 'auth/login', body: {
      'email': email,
      'password': password,
      'device_label': 'mobile',
    });
    return _tokens(json);
  }

  Future<TokenPair> refresh(String refreshToken) async {
    final json = await _send('POST', 'auth/refresh', body: {
      'refresh_token': refreshToken,
    });
    return _tokens(json);
  }

  Future<void> logout({String? refreshToken, String? accessToken}) async {
    await _send(
      'POST',
      'auth/logout',
      body: {'refresh_token': ?refreshToken},
      accessToken: accessToken,
    );
  }

  Future<User> me(String accessToken) async {
    final json = await _send('GET', 'auth/me', accessToken: accessToken);
    return User(id: (json['id'] as num).toInt(), email: json['email'] as String);
  }

  TokenPair _tokens(Map<String, dynamic> json) {
    final access = json['access_token'];
    final refresh = json['refresh_token'];
    if (access is! String || refresh is! String) {
      throw ApiException(500, 'bad_response');
    }
    return TokenPair(accessToken: access, refreshToken: refresh);
  }

  Future<Map<String, dynamic>> _send(
    String method,
    String path, {
    Map<String, dynamic>? body,
    String? accessToken,
  }) async {
    final request = http.Request(method, Uri.parse('$_base$path'))
      ..headers['Accept'] = 'application/json';
    if (body != null) {
      request.headers['Content-Type'] = 'application/json';
      request.body = jsonEncode(body);
    }
    if (accessToken != null) {
      request.headers['Authorization'] = 'Bearer $accessToken';
    }

    final http.Response response;
    try {
      response = await http.Response.fromStream(
        await _client.send(request).timeout(const Duration(seconds: 15)),
      );
    } on Object {
      throw ApiException(0, 'network_error');
    }

    if (response.statusCode >= 200 && response.statusCode < 300) {
      if (response.body.isEmpty) return const {};
      final decoded = jsonDecode(response.body);
      return decoded is Map<String, dynamic> ? decoded : const {};
    }

    var code = 'http_${response.statusCode}';
    try {
      final decoded = jsonDecode(response.body);
      if (decoded is Map && decoded['error'] is String) {
        code = decoded['error'] as String;
      }
    } on FormatException {
      // keep the generic code
    }
    throw ApiException(
      response.statusCode,
      code,
      retryAfter: int.tryParse(response.headers['retry-after'] ?? ''),
    );
  }
}

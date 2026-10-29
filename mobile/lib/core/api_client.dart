import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import '../domains/domain.dart';
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

/// Supplies a valid access token to authenticated calls and handles an expired
/// one: [run] calls [call] with the current token and, on HTTP 401, retries it
/// at most once with a refreshed token.
abstract class SessionAuthorizer {
  Future<T> run<T>(Future<T> Function(String accessToken) call);
}

/// Thin JSON client for the backend auth endpoints. Never logs bodies or tokens.
class ApiClient {
  ApiClient({http.Client? client, String baseUrl = apiBaseUrl})
      : _client = client ?? http.Client(),
        _base = baseUrl.endsWith('/') ? baseUrl : '$baseUrl/';

  final http.Client _client;
  final String _base;

  /// Set by the auth controller; required for authenticated data calls.
  SessionAuthorizer? authorizer;

  /// `GET domains`. An empty or blank [q] is not sent.
  Future<DomainPage> listDomains(
      {String? q, int page = 1, int perPage = 20}) {
    final auth = authorizer;
    if (auth == null) return Future.error(ApiException(401, 'unauthorized'));
    final term = q?.trim() ?? '';
    return auth.run((token) async {
      final json = await _send('GET', 'domains', accessToken: token, query: {
        if (term.isNotEmpty) 'q': term,
        'page': '$page',
        'per_page': '$perPage',
      });
      try {
        return DomainPage.fromJson(json);
      } on FormatException {
        throw ApiException(500, 'bad_response');
      }
    });
  }

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
    Map<String, String>? query,
  }) async {
    final uri = Uri.parse('$_base$path');
    final request = http.Request(
        method, query == null ? uri : uri.replace(queryParameters: query))
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
      final Object? decoded;
      try {
        decoded = jsonDecode(response.body);
      } on FormatException {
        throw ApiException(500, 'bad_response');
      }
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

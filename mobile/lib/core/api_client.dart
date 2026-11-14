import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import '../domains/balance.dart';
import '../domains/bulk_sync.dart';
import '../domains/domain.dart';
import '../domains/expiry.dart';
import '../domains/renewal_batch.dart';
import '../domains/renewal_execution.dart';
import '../domains/renewal_quote.dart';
import 'config.dart';

class ApiException implements Exception {
  ApiException(
    this.statusCode,
    this.code, {
    this.retryAfter,
    this.existingBatchId,
  });

  /// 0 when the server could not be reached.
  final int statusCode;
  final String code;
  final int? retryAfter;

  /// `existing_batch_id` of a 409 `duplicate_cycle`: the user's own active batch.
  final int? existingBatchId;

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

  /// Calls [call] EXACTLY ONCE. On HTTP 401 the session may be refreshed for
  /// later calls, but [call] is never repeated. Used for the renewal POST.
  Future<T> runOnce<T>(Future<T> Function(String accessToken) call);
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

  /// `GET domains`. An empty or blank [q], [ExpiryFilter.all] and
  /// [DomainSort.defaultOrder] are not sent.
  Future<DomainPage> listDomains({
    String? q,
    int page = 1,
    int perPage = 20,
    ExpiryFilter expiry = ExpiryFilter.all,
    DomainSort sort = DomainSort.defaultOrder,
  }) {
    final auth = authorizer;
    if (auth == null) return Future.error(ApiException(401, 'unauthorized'));
    final term = q?.trim() ?? '';
    return auth.run((token) async {
      final json = await _send(
        'GET',
        'domains',
        accessToken: token,
        query: {
          if (term.isNotEmpty) 'q': term,
          if (expiry != ExpiryFilter.all) 'expiry': expiry.apiValue,
          'sort': ?sort.apiValue,
          'page': '$page',
          'per_page': '$perPage',
        },
      );
      try {
        return DomainPage.fromJson(json);
      } on FormatException {
        throw ApiException(500, 'bad_response');
      }
    });
  }

  /// `GET expiry/summary`: cumulative expiry counts for the dashboard.
  Future<ExpirySummary> expirySummary() {
    final auth = authorizer;
    if (auth == null) return Future.error(ApiException(401, 'unauthorized'));
    return auth.run((token) async {
      final json = await _send('GET', 'expiry/summary', accessToken: token);
      try {
        return ExpirySummary.fromJson(json);
      } on FormatException {
        throw ApiException(500, 'bad_response');
      }
    });
  }

  /// `GET domains/{id}/details`: domain, nameservers and stored contacts. A 404
  /// means the domain no longer exists.
  Future<DomainDetail> getDomain(int id) {
    final auth = authorizer;
    if (auth == null) return Future.error(ApiException(401, 'unauthorized'));
    return auth.run((token) async {
      final json = await _send(
        'GET',
        'domains/$id/details',
        accessToken: token,
      );
      try {
        return DomainDetail.fromJson(json);
      } on FormatException {
        throw ApiException(500, 'bad_response');
      }
    });
  }

  /// `GET domains/{id}/renewal-quote?years=`: read-only price estimate for the
  /// stored domain row [id]. Never renews, invoices or charges anything.
  Future<RenewalQuote> getRenewalQuote(int id, {int years = 1}) {
    final auth = authorizer;
    if (auth == null) return Future.error(ApiException(401, 'unauthorized'));
    return auth.run((token) async {
      final json = await _send(
        'GET',
        'domains/$id/renewal-quote',
        accessToken: token,
        query: {'years': '$years'},
        timeout: const Duration(seconds: 45),
      );
      try {
        return RenewalQuote.fromJson(json);
      } on FormatException {
        throw ApiException(500, 'bad_response');
      }
    });
  }

  /// `POST renewal-batches`: stores a LOCAL renewal batch (nothing is renewed,
  /// invoiced or charged). Only the four contract fields are sent; the backend
  /// supplies order ids, customer ids, expiries and amounts itself. The same
  /// [idempotencyKey] with the same content returns the existing batch.
  /// Never retried automatically.
  Future<RenewalBatchResult> createRenewalBatch({
    required List<int> domainIds,
    required int years,
    required String invoiceOption,
    required String idempotencyKey,
  }) {
    final auth = authorizer;
    if (auth == null) return Future.error(ApiException(401, 'unauthorized'));
    return auth.run((token) async {
      final json = await _send(
        'POST',
        'renewal-batches',
        accessToken: token,
        body: {
          'domain_ids': domainIds,
          'years': years,
          'invoice_option': invoiceOption,
          'idempotency_key': idempotencyKey,
        },
        // The backend quotes every domain before storing the batch.
        timeout: const Duration(seconds: 120),
      );
      try {
        return RenewalBatchResult.fromJson(json);
      } on FormatException {
        throw ApiException(500, 'bad_response');
      }
    });
  }

  /// `POST renewal-batches/{batchId}/items/{itemId}/renew`: the ONE manual
  /// renewal request for one stored item. Sent exactly once: there is no retry
  /// of any kind, including after a 401 (the session may be refreshed, the
  /// request is not repeated). [confirmation] holds the six stored values.
  /// The long timeout covers the server's fresh price and balance checks plus
  /// the provider call; a timeout means the outcome is UNKNOWN.
  Future<RenewalExecutionResponse> renewBatchItem({
    required int batchId,
    required int itemId,
    required Map<String, Object> confirmation,
  }) {
    final auth = authorizer;
    if (auth == null) return Future.error(ApiException(401, 'unauthorized'));
    return auth.runOnce((token) async {
      final json = await _send(
        'POST',
        'renewal-batches/$batchId/items/$itemId/renew',
        accessToken: token,
        body: confirmation,
        timeout: const Duration(seconds: 90),
      );
      try {
        return RenewalExecutionResponse.fromJson(json);
      } on FormatException {
        // The server answered but the answer is unreadable: unconfirmed.
        throw ApiException(200, 'bad_response');
      }
    });
  }

  /// `POST renewal-batches/{id}/reconcile`: read-only towards ResellerClub; it
  /// can only look up recorded actions and never resends a renewal. Manual.
  Future<RenewalBatch> reconcileRenewalBatch(int batchId) {
    final auth = authorizer;
    if (auth == null) return Future.error(ApiException(401, 'unauthorized'));
    return auth.run((token) async {
      final json = await _send(
        'POST',
        'renewal-batches/$batchId/reconcile',
        accessToken: token,
        body: const {},
        timeout: const Duration(seconds: 120),
      );
      try {
        return RenewalBatch.fromJson(json['batch']);
      } on FormatException {
        throw ApiException(500, 'bad_response');
      }
    });
  }

  /// `POST renewal-batches/{b}/items/{i}/debit-verification`: records a MANUAL
  /// debit verification. It only stores verification data; it never renews.
  Future<RenewalBatch> verifyRenewalDebit({
    required int batchId,
    required int itemId,
    required Map<String, Object> body,
  }) {
    final auth = authorizer;
    if (auth == null) return Future.error(ApiException(401, 'unauthorized'));
    return auth.run((token) async {
      final json = await _send(
        'POST',
        'renewal-batches/$batchId/items/$itemId/debit-verification',
        accessToken: token,
        body: body,
      );
      try {
        return RenewalBatch.fromJson(json['batch']);
      } on FormatException {
        throw ApiException(500, 'bad_response');
      }
    });
  }

  /// `GET renewal-batches`: the signed-in user's batches, newest first.
  Future<RenewalBatchPage> listRenewalBatches({
    int page = 1,
    int perPage = 20,
  }) {
    final auth = authorizer;
    if (auth == null) return Future.error(ApiException(401, 'unauthorized'));
    return auth.run((token) async {
      final json = await _send(
        'GET',
        'renewal-batches',
        accessToken: token,
        query: {'page': '$page', 'per_page': '$perPage'},
      );
      try {
        return RenewalBatchPage.fromJson(json);
      } on FormatException {
        throw ApiException(500, 'bad_response');
      }
    });
  }

  /// `GET renewal-batches/active?domain_ids=`: the user's ACTIVE batch per
  /// domain id (read-only). Domains without one are absent. A batch that is
  /// stale and was never submitted is not active: the backend releases it when
  /// a new batch is created.
  Future<Map<int, RenewalBatch>> findActiveRenewalBatches(List<int> domainIds) {
    final auth = authorizer;
    if (auth == null) return Future.error(ApiException(401, 'unauthorized'));
    return auth.run((token) async {
      final json = await _send(
        'GET',
        'renewal-batches/active',
        accessToken: token,
        query: {'domain_ids': domainIds.join(',')},
      );
      try {
        final data = json['data'];
        if (data is! List) throw const FormatException('bad active');
        final out = <int, RenewalBatch>{};
        for (final row in data) {
          if (row is! Map || row['domain_id'] is! num) {
            throw const FormatException('bad active row');
          }
          out[(row['domain_id'] as num).toInt()] = RenewalBatch.fromJson(
            row['batch'],
          );
        }
        return out;
      } on FormatException {
        throw ApiException(500, 'bad_response');
      }
    });
  }

  /// `GET renewal-batches/{id}`: one batch with its items.
  Future<RenewalBatch> getRenewalBatch(int id) {
    final auth = authorizer;
    if (auth == null) return Future.error(ApiException(401, 'unauthorized'));
    return auth.run((token) async {
      final json = await _send(
        'GET',
        'renewal-batches/$id',
        accessToken: token,
      );
      try {
        return RenewalBatch.fromJson(json['batch']);
      } on FormatException {
        throw ApiException(500, 'bad_response');
      }
    });
  }

  /// `POST domains/{id}/enrich`: asks the backend to fetch this domain's
  /// contacts from the provider. No body is sent (the backend uses its stored
  /// order and customer ids). The provider call can be slow, hence the long
  /// timeout. Never retried automatically.
  Future<void> enrichDomain(int id) {
    final auth = authorizer;
    if (auth == null) return Future.error(ApiException(401, 'unauthorized'));
    return auth.run((token) async {
      await _send(
        'POST',
        'domains/$id/enrich',
        accessToken: token,
        timeout: const Duration(seconds: 110),
      );
    });
  }

  /// `GET balance`: the reseller's available balance (read-only).
  Future<Balance> getBalance() {
    final auth = authorizer;
    if (auth == null) return Future.error(ApiException(401, 'unauthorized'));
    return auth.run((token) async {
      final json = await _send(
        'GET',
        'balance',
        accessToken: token,
        timeout: const Duration(seconds: 45),
      );
      try {
        return Balance.fromJson(json);
      } on FormatException {
        throw ApiException(500, 'bad_response');
      }
    });
  }

  /// `POST inventory/sync`: one inventory-only run on the backend (new domains
  /// and changed expiry dates; no enrichment). The first run reads the whole
  /// inventory from the provider, hence the long timeout. Never retried.
  Future<void> syncInventory() {
    final auth = authorizer;
    if (auth == null) return Future.error(ApiException(401, 'unauthorized'));
    return auth.run((token) async {
      await _send(
        'POST',
        'inventory/sync',
        accessToken: token,
        timeout: const Duration(seconds: 300),
      );
    });
  }

  /// `GET enrichment/eligible`: how many domains a batch in [mode]
  /// (`missing` or `refresh`) would process.
  Future<int> eligibleCount(String mode) {
    final auth = authorizer;
    if (auth == null) return Future.error(ApiException(401, 'unauthorized'));
    return auth.run((token) async {
      final json = await _send(
        'GET',
        'enrichment/eligible',
        accessToken: token,
        query: {'mode': mode},
      );
      final n = json['eligible'];
      if (n is! num) throw ApiException(500, 'bad_response');
      return n.toInt();
    });
  }

  /// `POST enrichment/batches`. A 409 `batch_in_progress` means one is running.
  Future<BatchProgress> startBatch(String mode) => _batch(
    (token) => _send(
      'POST',
      'enrichment/batches',
      accessToken: token,
      body: {'mode': mode},
    ),
  );

  /// `POST enrichment/batches/{id}/step`: the server processes a few domains
  /// one after another; the long timeout covers a slow provider.
  Future<BatchProgress> stepBatch(String batchId) => _batch(
    (token) => _send(
      'POST',
      'enrichment/batches/$batchId/step',
      accessToken: token,
      // The first step reads the whole inventory from the provider.
      timeout: const Duration(seconds: 300),
    ),
  );

  /// `GET enrichment/batches/current`.
  Future<BatchProgress> currentBatch() => _batch(
    (token) => _send('GET', 'enrichment/batches/current', accessToken: token),
  );

  Future<BatchProgress> _batch(
    Future<Map<String, dynamic>> Function(String token) call,
  ) {
    final auth = authorizer;
    if (auth == null) return Future.error(ApiException(401, 'unauthorized'));
    return auth.run((token) async {
      final json = await call(token);
      try {
        return BatchProgress.fromJson(json);
      } on FormatException {
        throw ApiException(500, 'bad_response');
      }
    });
  }

  Future<TokenPair> login(String email, String password) async {
    final json = await _send(
      'POST',
      'auth/login',
      body: {'email': email, 'password': password, 'device_label': 'mobile'},
    );
    return _tokens(json);
  }

  Future<TokenPair> refresh(String refreshToken) async {
    final json = await _send(
      'POST',
      'auth/refresh',
      body: {'refresh_token': refreshToken},
    );
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
    return User(
      id: (json['id'] as num).toInt(),
      email: json['email'] as String,
    );
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
    Duration timeout = const Duration(seconds: 15),
  }) async {
    final uri = Uri.parse('$_base$path');
    final request = http.Request(
      method,
      query == null ? uri : uri.replace(queryParameters: query),
    )..headers['Accept'] = 'application/json';
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
        await _client.send(request).timeout(timeout),
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
    int? existingBatchId;
    try {
      final decoded = jsonDecode(response.body);
      if (decoded is Map && decoded['error'] is String) {
        code = decoded['error'] as String;
      }
      if (decoded is Map && decoded['existing_batch_id'] is num) {
        existingBatchId = (decoded['existing_batch_id'] as num).toInt();
      }
    } on FormatException {
      // keep the generic code
    }
    throw ApiException(
      response.statusCode,
      code,
      retryAfter: int.tryParse(response.headers['retry-after'] ?? ''),
      existingBatchId: existingBatchId,
    );
  }
}

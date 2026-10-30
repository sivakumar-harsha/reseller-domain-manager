import 'dart:async';

import 'package:flutter/foundation.dart';

import '../core/api_client.dart';
import 'domain.dart';

/// State for the read-only domain list: first load, debounced search,
/// load-more pagination and refresh. Stale responses (an older search, or one
/// that arrives after dispose) are discarded.
class DomainController extends ChangeNotifier {
  DomainController({
    required ApiClient api,
    this.perPage = 20,
    this.debounce = const Duration(milliseconds: 400),
  }) : _api = api;

  final ApiClient _api;
  final int perPage;
  final Duration debounce;

  final List<Domain> items = [];
  String query = '';
  int total = 0;
  int _page = 0;
  int _totalPages = 0;
  DateTime? lastSyncedAt;

  /// First page (or a search) is loading and there is nothing to show yet.
  bool loading = false;

  /// A pull-to-refresh is running; existing rows stay visible.
  bool refreshing = false;
  bool loadingMore = false;

  /// Set when loading the first page or refreshing failed.
  String? error;

  /// Set when loading a further page failed; the loaded rows stay.
  String? loadMoreError;

  /// True once the first request has finished, successfully or not.
  bool loaded = false;

  Timer? _timer;
  int _generation = 0;
  bool _disposed = false;

  bool get hasMore => _page > 0 && _page < _totalPages;
  bool get isEmpty => loaded && error == null && items.isEmpty;

  /// Loads the first page for the current query, replacing the list.
  Future<void> load() async {
    _timer?.cancel();
    final gen = ++_generation;
    items.clear();
    total = 0;
    _page = 0;
    _totalPages = 0;
    loading = true;
    refreshing = false;
    loadingMore = false;
    error = null;
    loadMoreError = null;
    loaded = false;
    _notify();

    try {
      final page = await _api.listDomains(q: query, page: 1, perPage: perPage);
      if (_stale(gen)) return;
      _apply(page);
    } on ApiException catch (e) {
      if (_stale(gen)) return;
      error = messageFor(e);
    } finally {
      if (!_stale(gen)) {
        loading = false;
        loaded = true;
        _notify();
      }
    }
  }

  /// Reloads page 1 but keeps the current rows if it fails.
  Future<void> refresh() async {
    if (loading) return;
    _timer?.cancel();
    final gen = ++_generation;
    refreshing = true;
    loadingMore = false;
    error = null;
    loadMoreError = null;
    _notify();

    try {
      final page = await _api.listDomains(q: query, page: 1, perPage: perPage);
      if (_stale(gen)) return;
      items.clear();
      _apply(page);
    } on ApiException catch (e) {
      if (_stale(gen)) return;
      error = messageFor(e);
    } finally {
      if (!_stale(gen)) {
        refreshing = false;
        loaded = true;
        _notify();
      }
    }
  }

  /// Retries whatever last failed.
  Future<void> retry() {
    if (loaded && error == null && loadMoreError != null) return loadMore();
    return items.isEmpty ? load() : refresh();
  }

  Future<void> loadMore() async {
    if (loading || refreshing || loadingMore || !hasMore) return;
    final gen = _generation;
    loadingMore = true;
    loadMoreError = null;
    _notify();

    try {
      final page =
          await _api.listDomains(q: query, page: _page + 1, perPage: perPage);
      if (_stale(gen)) return;
      _apply(page);
    } on ApiException catch (e) {
      if (_stale(gen)) return;
      loadMoreError = messageFor(e);
    } finally {
      if (!_stale(gen)) {
        loadingMore = false;
        _notify();
      }
    }
  }

  /// Debounced: the request is made [debounce] after the last change.
  void setQuery(String value) {
    final next = value.trim();
    if (next == query) return;
    query = next;
    _timer?.cancel();
    _timer = Timer(debounce, load);
  }

  void _apply(DomainPage page) {
    final known = items.map((d) => d.id).toSet();
    items.addAll(page.items.where((d) => known.add(d.id)));
    total = page.total;
    _page = page.page;
    _totalPages = page.totalPages;
    lastSyncedAt = page.lastSyncedAt;
  }

  bool _stale(int gen) => _disposed || gen != _generation;

  void _notify() {
    if (!_disposed) notifyListeners();
  }

  @override
  void dispose() {
    _disposed = true;
    _timer?.cancel();
    super.dispose();
  }

  /// User-facing text for a failed domain request, shared with the detail screen.
  static String messageFor(ApiException e) {
    switch (e.code) {
      case 'network_error':
        return 'Cannot reach the server. Check the connection and try again.';
      case 'unauthorized':
      case 'storage_error':
        return 'Your session is no longer valid. Please sign in again.';
      case 'too_many_requests':
        return 'Too many requests. Please wait a moment and try again.';
      case 'invalid_request':
        return 'That search could not be processed.';
      default:
        return 'Could not load domains (${e.code}). Try again.';
    }
  }
}

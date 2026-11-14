import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../core/api_client.dart';

/// Remembers when the last successful inventory refresh finished.
abstract class RefreshStampStore {
  Future<DateTime?> read();
  Future<void> write(DateTime value);
  Future<void> clear();
}

/// Stored next to the session tokens; no new dependency.
class SecureRefreshStampStore implements RefreshStampStore {
  SecureRefreshStampStore([FlutterSecureStorage? storage])
    : _storage = storage ?? const FlutterSecureStorage();

  static const _key = 'inventory_refreshed_at';
  final FlutterSecureStorage _storage;

  @override
  Future<DateTime?> read() async {
    try {
      final raw = await _storage.read(key: _key);
      return raw == null ? null : DateTime.tryParse(raw);
    } catch (_) {
      return null;
    }
  }

  @override
  Future<void> write(DateTime value) async {
    try {
      await _storage.write(key: _key, value: value.toUtc().toIso8601String());
    } catch (_) {
      // Losing the stamp only means the next launch refreshes again.
    }
  }

  /// Deletes only this store's key; tokens and other values are untouched.
  @override
  Future<void> clear() async {
    try {
      await _storage.delete(key: _key);
    } catch (_) {
      // Best effort.
    }
  }
}

/// Minimum time between successful automatic inventory refreshes.
const autoRefreshInterval = Duration(minutes: 15);

/// What one inventory refresh attempt did.
enum InventoryOutcome {
  /// The sync ran and succeeded.
  success,

  /// The sync was attempted and failed; [AutoRefreshController.error] says why.
  failed,

  /// The server is already running a sync (409); nothing was refreshed.
  busy,

  /// Automatic attempt skipped: not due yet, or cooling down after a failure.
  notDue,

  /// Skipped on purpose (disposed, or the caller said it was blocked).
  blocked,
}

/// Inventory-only refresh. Automatic attempts run at most once per [interval];
/// a manual one ([refresh] with `force`) ignores the interval and the failure
/// cooldown. Either way there is never more than one request in flight: a call
/// that arrives while one is running simply joins it. Triggered by the caller
/// (launch, resume, pull-to-refresh); there are no timers. It does not enrich
/// domains.
class AutoRefreshController extends ChangeNotifier {
  AutoRefreshController({
    required ApiClient api,
    required RefreshStampStore store,
    this.interval = autoRefreshInterval,
    this.retryAfterFailure = const Duration(minutes: 5),
    DateTime Function()? now,
  }) : _api = api,
       _store = store,
       _now = now ?? DateTime.now;

  final ApiClient _api;
  final RefreshStampStore _store;
  final Duration interval;

  /// After a failed automatic attempt, no new automatic attempt before this
  /// has passed (so returning to the app repeatedly does not hammer a failing
  /// server).
  final Duration retryAfterFailure;
  final DateTime Function() _now;

  /// A refresh request is in flight.
  bool refreshing = false;

  /// User-facing text for the last failed attempt; cleared by the next attempt.
  String? error;

  /// Error code of the last failed attempt (for example `sync_in_progress`).
  String? errorCode;

  /// When the last successful inventory refresh finished. Restored from the
  /// stamp store on the first attempt; never set by a failure.
  DateTime? lastRefreshedAt;

  DateTime? _lastFailure;
  Future<InventoryOutcome>? _inflight;
  bool _disposed = false;

  /// Runs an inventory refresh. Without [force] it only runs when due.
  /// [blocked] skips the attempt, e.g. while a details batch is running.
  Future<InventoryOutcome> refresh({
    bool force = false,
    bool Function()? blocked,
  }) {
    final running = _inflight;
    if (running != null) return running;
    if (_disposed) return Future.value(InventoryOutcome.blocked);
    final f = _attempt(force, blocked).whenComplete(() => _inflight = null);
    _inflight = f;
    return f;
  }

  Future<InventoryOutcome> _attempt(
    bool force,
    bool Function()? blocked,
  ) async {
    if (blocked?.call() ?? false) return InventoryOutcome.blocked;
    final now = _now();
    final failure = _lastFailure;
    if (!force &&
        failure != null &&
        now.difference(failure) < retryAfterFailure) {
      return InventoryOutcome.notDue;
    }
    final last = await _store.read();
    lastRefreshedAt ??= last;
    // A stamp in the future (clock change) counts as due.
    if (!force && last != null) {
      final age = now.difference(last);
      if (!age.isNegative && age < interval) return InventoryOutcome.notDue;
    }
    if (_disposed || (blocked?.call() ?? false)) {
      return InventoryOutcome.blocked;
    }

    refreshing = true;
    error = null;
    errorCode = null;
    _notify();
    try {
      await _api.syncInventory();
      _lastFailure = null;
      final done = _now();
      lastRefreshedAt = done;
      await _store.write(done);
      return InventoryOutcome.success;
    } on ApiException catch (e) {
      _lastFailure = _now();
      errorCode = e.code;
      error = messageFor(e);
      return e.code == 'sync_in_progress'
          ? InventoryOutcome.busy
          : InventoryOutcome.failed;
    } finally {
      refreshing = false;
      _notify();
    }
  }

  /// Forgets the last refresh time and the failure cooldown (used on logout).
  Future<void> reset() async {
    _lastFailure = null;
    error = null;
    errorCode = null;
    lastRefreshedAt = null;
    await _store.clear();
  }

  static String messageFor(ApiException e) {
    switch (e.code) {
      case 'network_error':
        return 'Could not refresh domains: server unreachable. Showing saved data.';
      case 'unauthorized':
        return 'Your session is no longer valid. Please sign in again.';
      case 'sync_in_progress':
        return 'A sync is already running. Showing saved data.';
      case 'provider_not_configured':
        return 'The provider connection is not configured on the server.';
      case 'too_many_requests':
        return 'Too many requests. Showing saved data.';
      default:
        return 'Could not refresh domains (${e.code}). Showing saved data.';
    }
  }

  void _notify() {
    if (!_disposed) notifyListeners();
  }

  @override
  void dispose() {
    _disposed = true;
    super.dispose();
  }
}

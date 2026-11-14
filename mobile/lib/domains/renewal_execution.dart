import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../core/api_client.dart';
import 'renewal_batch.dart';

// ---------------------------------------------------------------------------
// Attempt marker
// ---------------------------------------------------------------------------

/// Device-local record that a renewal request for one item was STARTED.
///
/// It is written BEFORE the request is sent and cleared only by a response the
/// backend is known to produce before it executes anything (see
/// [RenewalExecutionController.clearsMarker]) or when the stored item state is
/// no longer `pending`. A marked item can never be renewed from this app, even
/// when a refetched batch still shows it as `pending`: a lost answer proves
/// nothing about whether the request reached the provider.
abstract class RenewalAttemptStore {
  Future<bool> contains(int batchId, int itemId);

  /// Throws when the marker cannot be saved.
  Future<void> mark(int batchId, int itemId, DateTime at);

  Future<void> clear(int batchId, int itemId);
}

/// Keychain / Android Keystore backed markers.
class SecureRenewalAttemptStore implements RenewalAttemptStore {
  SecureRenewalAttemptStore([FlutterSecureStorage? storage])
    : _storage = storage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _storage;

  static String _key(int batchId, int itemId) =>
      'renewal_attempt_${batchId}_$itemId';

  @override
  Future<bool> contains(int batchId, int itemId) async =>
      await _storage.read(key: _key(batchId, itemId)) != null;

  @override
  Future<void> mark(int batchId, int itemId, DateTime at) => _storage.write(
    key: _key(batchId, itemId),
    value: at.toUtc().toIso8601String(),
  );

  @override
  Future<void> clear(int batchId, int itemId) =>
      _storage.delete(key: _key(batchId, itemId));
}

/// Process-local markers, for tests only. Nothing in the app uses this.
class MemoryRenewalAttemptStore implements RenewalAttemptStore {
  final Map<String, DateTime> markers = {};

  /// Simulates an unwritable store.
  bool failWrites = false;

  /// Simulates an unreadable store.
  bool failReads = false;

  static String _key(int batchId, int itemId) => '$batchId/$itemId';

  @override
  Future<bool> contains(int batchId, int itemId) async {
    if (failReads) throw StateError('unreadable');
    return markers.containsKey(_key(batchId, itemId));
  }

  @override
  Future<void> mark(int batchId, int itemId, DateTime at) async {
    if (failWrites) throw StateError('unwritable');
    markers[_key(batchId, itemId)] = at;
  }

  @override
  Future<void> clear(int batchId, int itemId) async {
    markers.remove(_key(batchId, itemId));
  }
}

/// Only a definitive terminal server state may release a marker. `pending`,
/// `submitting`, `uncertain` and anything unknown keep it.
bool isTerminalState(String state) => state == 'succeeded' || state == 'failed';

/// The ids of [batch] items that must NOT be offered Renew: `pending` items with
/// a marker (or whose marker cannot be read: fail closed). A marker is removed
/// only when the stored state is `succeeded` or `failed`.
Future<Set<int>> blockedItemIds(
  RenewalAttemptStore store,
  RenewalBatch batch,
) async {
  final blocked = <int>{};
  for (final item in batch.items) {
    try {
      final marked = await store.contains(batch.id, item.id);
      if (item.state == 'pending' && marked) blocked.add(item.id);
      if (marked && isTerminalState(item.state)) {
        await store.clear(batch.id, item.id);
      }
    } catch (_) {
      if (item.state == 'pending') blocked.add(item.id);
    }
  }
  return blocked;
}

// ---------------------------------------------------------------------------
// Execution response
// ---------------------------------------------------------------------------

/// A parsed 200/202 answer of `POST renewal-batches/{b}/items/{i}/renew`.
class RenewalExecutionResponse {
  const RenewalExecutionResponse({
    required this.itemId,
    required this.state,
    this.errorCode,
    this.providerMessage,
    this.actionId,
    this.invoiceId,
  });

  final int itemId;

  /// The backend's item state.
  final String state;
  final String? errorCode, actionId, invoiceId, providerMessage;

  /// Only these two states are a final answer from the provider path.
  bool get definitive => state == 'succeeded' || state == 'failed';

  factory RenewalExecutionResponse.fromJson(Map<String, dynamic> json) {
    final item = json['item'];
    if (item is! Map) throw const FormatException('bad response');
    final id = item['id'];
    final state = item['state'];
    if (id is! num || state is! String) {
      throw const FormatException('bad response');
    }
    String? text(String key) {
      final v = item[key];
      return v == null ? null : '$v';
    }

    return RenewalExecutionResponse(
      itemId: id.toInt(),
      state: state,
      errorCode: text('error_code'),
      providerMessage: text('provider_message'),
      actionId: text('provider_action_id'),
      invoiceId: text('provider_invoice_id'),
    );
  }
}

// ---------------------------------------------------------------------------
// Controller
// ---------------------------------------------------------------------------

enum RenewPhase {
  /// Reading the attempt marker / refetching.
  checking,

  /// Renew may be offered (still needs the acknowledgement).
  ready,

  /// The one request is in flight.
  sending,

  succeeded,
  failed,

  /// The outcome is not known. Only Check status and Refresh are offered.
  unconfirmed,

  /// Another renewal operation holds the server lock. Marker kept.
  inProgress,

  /// 503 execution_disabled: nothing was attempted.
  disabled,

  /// A definite refusal before anything was executed.
  refused,
}

/// Drives the manual confirmation of ONE stored batch item.
///
/// It sends at most one renewal request per [renew] call, never from a timer,
/// listener or lifecycle hook, and never again by itself. Every value in the
/// request comes from the stored batch and item, unmodified.
class RenewalExecutionController extends ChangeNotifier {
  RenewalExecutionController({
    required this.api,
    required this.store,
    required RenewalBatch batch,
    required this.itemId,
    DateTime Function()? clock,
  }) : _batch = batch,
       _clock = clock ?? DateTime.now;

  final ApiClient api;
  final RenewalAttemptStore store;
  final int itemId;
  final DateTime Function() _clock;

  RenewalBatch _batch;
  RenewalBatch get batch => _batch;

  RenewPhase phase = RenewPhase.checking;
  bool acknowledged = false;

  /// An unresolved attempt marker exists (or could not be read).
  bool marked = false;

  /// Result details of a definitive answer, or the stored item's.
  String? message;
  bool checkingStatus = false;
  bool refreshing = false;

  /// True after a refusal / disabled answer: Renew is not offered again from
  /// this screen instance.
  bool _closed = false;
  bool _sending = false;
  bool _disposed = false;

  RenewalBatchItem? get item {
    for (final i in _batch.items) {
      if (i.id == itemId) return i;
    }
    return null;
  }

  bool get busy => _sending || checkingStatus || refreshing;

  /// Renew may be tapped.
  bool get canRenew {
    final i = item;
    return phase == RenewPhase.ready &&
        acknowledged &&
        !_sending &&
        !_closed &&
        !marked &&
        i != null &&
        i.state == 'pending';
  }

  /// The Renew button is part of the screen (possibly disabled).
  bool get showRenew =>
      phase == RenewPhase.ready ||
      phase == RenewPhase.sending ||
      phase == RenewPhase.disabled;

  /// Check status / Refresh are offered.
  bool get showRecovery =>
      phase == RenewPhase.unconfirmed || phase == RenewPhase.inProgress;

  void setAcknowledged(bool value) {
    if (_sending) return;
    acknowledged = value;
    _notify();
  }

  /// Inspects the marker and the stored state. Safe on every open: it reads
  /// only, and only ever REMOVES a marker when the stored state is final.
  Future<void> start() async {
    await _inspect();
  }

  Future<void> _inspect() async {
    final i = item;
    if (i == null) {
      phase = RenewPhase.refused;
      message = 'This renewal item was not found.';
      _notify();
      return;
    }
    try {
      marked = await store.contains(_batch.id, i.id);
    } catch (_) {
      marked = true; // fail closed: an unreadable marker blocks Renew
    }
    if (marked && isTerminalState(i.state)) {
      try {
        await store.clear(_batch.id, i.id);
        marked = false;
      } catch (_) {
        // keep the marker; the stored state still decides what is shown
      }
    }
    _derivePhase();
    _notify();
  }

  void _derivePhase() {
    final i = item!;
    switch (i.state) {
      case 'succeeded':
        phase = RenewPhase.succeeded;
        message = null;
      case 'failed':
        phase = RenewPhase.failed;
        message = null;
      case 'pending':
        if (marked) {
          phase = phase == RenewPhase.inProgress
              ? RenewPhase.inProgress
              : RenewPhase.unconfirmed;
          message = phase == RenewPhase.inProgress
              ? message
              : 'The server still shows this item as pending, but an earlier '
                    'renewal attempt from this device could not be confirmed. '
                    'Renewal is blocked.';
        } else if (_closed) {
          // keep the refusal / disabled message and phase
        } else {
          phase = RenewPhase.ready;
          message = null;
        }
      default: // submitting, uncertain, anything unknown
        phase = RenewPhase.unconfirmed;
        message =
            'The outcome of this renewal is not confirmed. Do not renew '
            'again. Use Check status.';
    }
  }

  /// The ONE renewal request. A second call while one is running, or without
  /// the acknowledgement, does nothing.
  Future<void> renew() async {
    if (!canRenew) return;
    final i = item!;
    _sending = true;
    phase = RenewPhase.sending;
    message = null;
    _notify();

    try {
      // 1. The marker goes first. No marker, no request.
      try {
        await store.mark(_batch.id, i.id, _clock());
        if (!await store.contains(_batch.id, i.id)) {
          throw StateError('marker not readable');
        }
      } catch (_) {
        _closed = false;
        phase = RenewPhase.ready;
        acknowledged = false;
        message =
            'A safety record could not be saved on this device, so nothing '
            'was sent. Nothing was charged.';
        return;
      }
      marked = true;

      // 2. The request, built only from the stored values.
      RenewalExecutionResponse? response;
      ApiException? error;
      try {
        response = await api.renewBatchItem(
          batchId: _batch.id,
          itemId: i.id,
          confirmation: {
            'acknowledge_charge': true,
            'confirm_domain_name': i.domainName,
            'confirm_total_amount': i.total,
            'confirm_currency': i.currency,
            'confirm_years': _batch.years,
            'confirm_invoice_option': _batch.invoiceOption,
          },
        );
      } on ApiException catch (e) {
        error = e;
      } catch (_) {
        error = ApiException(0, 'network_error');
      }

      if (response != null) {
        await _applyResponse(i, response);
      } else {
        await _applyError(i, error!);
      }
    } finally {
      _sending = false;
      _notify();
    }
  }

  Future<void> _applyResponse(
    RenewalBatchItem i,
    RenewalExecutionResponse r,
  ) async {
    if (r.itemId == i.id && r.definitive) {
      await _clearMarker(i.id);
      phase = r.state == 'succeeded' ? RenewPhase.succeeded : RenewPhase.failed;
      message = null;
      _patchItem(i.id, r);
      _notify();
      return;
    }
    // 202, submitting, uncertain, a mismatched item, anything else: unresolved.
    phase = RenewPhase.unconfirmed;
    message =
        'The outcome of this renewal is not confirmed. Do not renew again. '
        'Use Check status.';
    if (r.itemId == i.id) _patchItem(i.id, r);
  }

  Future<void> _applyError(RenewalBatchItem i, ApiException e) async {
    final code = e.code;
    if (code == 'execution_in_progress') {
      phase = RenewPhase.inProgress; // marker kept
      message =
          'Another renewal operation is in progress. This item was not '
          'confirmed from this device, so Renew is not offered. Use Check '
          'status or Refresh.';
      return;
    }
    if (!clearsMarker(e)) {
      // timeout, network failure, HTTP 500, unknown answer: ambiguous
      phase = RenewPhase.unconfirmed;
      message =
          'The outcome of this renewal is not confirmed. Do not renew again. '
          'Use Check status.';
      return;
    }

    await _clearMarker(i.id);

    if (e.statusCode == 401) {
      // The request was rejected before the renewal controller ran. The
      // session was refreshed by the authorizer; nothing was resent. The user
      // must tap Renew again by hand and acknowledge again.
      phase = RenewPhase.ready;
      acknowledged = false;
      message =
          'Your session expired and was refreshed. The renewal was not sent. '
          'Tick the confirmation and tap Renew to try again.';
      return;
    }
    _closed = true;
    if (code == 'item_not_pending') {
      phase = RenewPhase.refused;
      message =
          'This item is no longer pending, so nothing was sent. '
          'The stored state is shown.';
      await _refetch(quiet: true);
      return;
    }
    if (code == 'execution_disabled') {
      phase = RenewPhase.disabled;
      message =
          'Renewals are currently disabled on the server. No renewal was '
          'attempted. You can still review the stored details.';
      return;
    }
    phase = RenewPhase.refused;
    message = messageFor(code);
  }

  /// Codes the backend returns BEFORE it claims or sends anything, plus a
  /// confirmed 401 from the auth filter. Everything else leaves the marker.
  static bool clearsMarker(ApiException e) {
    if (e.statusCode == 401) return e.code == 'unauthorized';
    return _beforeExecution.contains(e.code);
  }

  static const _beforeExecution = {
    'execution_disabled',
    'balance_currency_unverified',
    'balance_currency_mismatch',
    'balance_unavailable',
    'price_unavailable',
    'provider_not_configured',
    'insufficient_balance',
    'price_changed',
    'batch_expired',
    'item_not_pending',
    'confirmation_mismatch',
    'invalid_request',
    'execution_limit_exceeded',
    'not_found',
  };

  static String messageFor(String code) {
    switch (code) {
      case 'balance_currency_unverified':
        return 'The server has not verified the balance currency for '
            'renewals, so this renewal was refused. Nothing was charged.';
      case 'balance_currency_mismatch':
        return 'The account balance currency does not match the renewal '
            'currency, so this renewal was refused. Nothing was charged.';
      case 'balance_unavailable':
        return 'The balance could not be read, so this renewal was refused. '
            'Nothing was charged.';
      case 'price_unavailable':
        return 'The current price could not be read, so this renewal was '
            'refused. Nothing was charged.';
      case 'provider_not_configured':
        return 'The provider is not configured on the server. Nothing was '
            'charged.';
      case 'insufficient_balance':
        return 'The API accounting balance is not enough for this renewal. '
            'Nothing was charged.';
      case 'price_changed':
        return 'The price changed since this batch was created. Create a new '
            'quote and batch. Nothing was charged.';
      case 'batch_expired':
        return 'This renewal batch has expired. Create a new quote and batch. '
            'Nothing was charged.';
      case 'confirmation_mismatch':
        return 'The confirmation did not match the stored renewal, so the '
            'request was refused. Nothing was charged.';
      default:
        return 'The request was refused by the server ($code). Nothing was '
            'charged.';
    }
  }

  void _patchItem(int id, RenewalExecutionResponse r) {
    _batch = RenewalBatch(
      id: _batch.id,
      status: _batch.status,
      invoiceOption: _batch.invoiceOption,
      years: _batch.years,
      counts: _batch.counts,
      createdAt: _batch.createdAt,
      items: [
        for (final i in _batch.items)
          if (i.id == id)
            RenewalBatchItem(
              id: i.id,
              domainId: i.domainId,
              domainName: i.domainName,
              state: r.state,
              currency: i.currency,
              amount: i.amount,
              gst: i.gst,
              vat: i.vat,
              total: i.total,
              actionId: r.actionId ?? i.actionId,
              invoiceId: r.invoiceId ?? i.invoiceId,
              errorCode: r.errorCode ?? i.errorCode,
              providerMessage: r.providerMessage ?? i.providerMessage,
            )
          else
            i,
      ],
    );
  }

  Future<void> _clearMarker(int id) async {
    try {
      await store.clear(_batch.id, id);
      marked = false;
    } catch (_) {
      // Could not clear: the marker stays and keeps Renew blocked.
    }
  }

  // ---- recovery actions (manual) -----------------------------------------

  /// `POST renewal-batches/{id}/reconcile`: read-only towards ResellerClub,
  /// never resends a renewal. Manual only.
  Future<void> checkStatus() async {
    if (busy) return;
    checkingStatus = true;
    message = null;
    _notify();
    try {
      final fresh = await api.reconcileRenewalBatch(_batch.id);
      _batch = fresh;
      await _inspect();
    } on ApiException catch (e) {
      message = 'Could not check the status (${e.code}). Try again.';
    } catch (_) {
      message = 'Could not check the status. Try again.';
    } finally {
      checkingStatus = false;
      _notify();
    }
  }

  /// Re-reads the stored batch (GET). A refetched `pending` item with a marker
  /// stays blocked.
  Future<void> refresh() async {
    if (busy) return;
    await _refetch(quiet: false);
  }

  Future<void> _refetch({required bool quiet}) async {
    refreshing = !quiet;
    if (!quiet) {
      message = null;
      _notify();
    }
    try {
      _batch = await api.getRenewalBatch(_batch.id);
      await _inspect();
    } on ApiException catch (e) {
      if (!quiet) message = 'Could not refresh (${e.code}). Try again.';
    } catch (_) {
      if (!quiet) message = 'Could not refresh. Try again.';
    } finally {
      refreshing = false;
      _notify();
    }
  }

  void _notify() {
    if (!_disposed) notifyListeners();
  }

  @override
  void dispose() {
    if (_disposed) return;
    _disposed = true;
    super.dispose();
  }
}

import 'dart:async';

import 'package:flutter/foundation.dart';

import '../core/api_client.dart';

/// Progress of a bulk details batch, as returned by the backend. Holds counters,
/// domain names and fixed error codes only.
class BatchProgress {
  const BatchProgress({
    required this.batchId,
    required this.status,
    required this.total,
    required this.processed,
    required this.succeeded,
    required this.failed,
    required this.skipped,
    this.currentName,
    this.abortReason,
    this.phase = 'enrich',
    this.discoveryState = 'skipped',
    this.discoveryRunStatus,
    this.newDomains = 0,
    this.domainsSeen = 0,
    this.failures = const [],
  });

  final String batchId;

  /// `running`, `completed` or `aborted`.
  final String status;
  final int total, processed, succeeded, failed, skipped;
  final String? currentName;
  final String? abortReason;

  /// `discover` (checking the provider for new domains) or `enrich`.
  final String phase;

  /// `pending`, `done`, `failed` or `skipped`; [discoveryRunStatus] is the
  /// inventory sync outcome (`success`, `partial`, `failed`).
  final String discoveryState;
  final String? discoveryRunStatus;
  final int newDomains, domainsSeen;
  final List<BatchFailure> failures;

  bool get discovering => phase == 'discover';

  int get remaining => total - processed;
  bool get isRunning => status == 'running';

  factory BatchProgress.fromJson(Map<String, dynamic> json) {
    int n(String key) {
      final v = json[key];
      if (v is! num) throw const FormatException('bad batch');
      return v.toInt();
    }

    final id = json['batch_id'];
    final status = json['status'];
    if (id is! String || status is! String) {
      throw const FormatException('bad batch');
    }
    final current = json['current'];
    final failures = <BatchFailure>[];
    final raw = json['failures'];
    if (raw is List) {
      for (final f in raw) {
        if (f is Map && f['domain_id'] is num) {
          failures.add(
            BatchFailure(
              domainId: (f['domain_id'] as num).toInt(),
              domainName: f['domain_name'] is String
                  ? f['domain_name'] as String
                  : 'Domain ${f['domain_id']}',
              error: f['error'] is String ? f['error'] as String : 'unknown',
            ),
          );
        }
      }
    }
    final disc = json['discovery'];
    int dn(String key) =>
        disc is Map && disc[key] is num ? (disc[key] as num).toInt() : 0;
    return BatchProgress(
      batchId: id,
      phase: json['phase'] is String ? json['phase'] as String : 'enrich',
      discoveryState: disc is Map && disc['state'] is String
          ? disc['state'] as String
          : 'skipped',
      discoveryRunStatus: disc is Map && disc['run_status'] is String
          ? disc['run_status'] as String
          : null,
      newDomains: dn('new'),
      domainsSeen: dn('seen'),
      status: status,
      total: n('total'),
      processed: n('processed'),
      succeeded: n('succeeded'),
      failed: n('failed'),
      skipped: n('skipped'),
      currentName: current is Map && current['domain_name'] is String
          ? current['domain_name'] as String
          : null,
      abortReason: json['abort_reason'] is String
          ? json['abort_reason'] as String
          : null,
      failures: failures,
    );
  }
}

class BatchFailure {
  const BatchFailure({
    required this.domainId,
    required this.domainName,
    required this.error,
  });

  final int domainId;
  final String domainName;
  final String error;
}

enum BulkPhase { idle, running, paused, finished }

/// Drives a resumable bulk sync: starts a batch, then calls the step endpoint
/// one request at a time until the backend reports it is done. Progress lives
/// on the server, so a lost connection only pauses; [resume] continues the same
/// batch without redoing completed domains.
class BulkSyncController extends ChangeNotifier {
  BulkSyncController({required ApiClient api, this.onCompleted}) : _api = api;

  final ApiClient _api;

  /// Called once when a batch finishes with status `completed` (not on pause,
  /// abort or intermediate steps). Its failure never affects the batch result.
  final FutureOr<void> Function()? onCompleted;

  BulkPhase phase = BulkPhase.idle;
  BatchProgress? progress;

  /// User-facing text for the last problem, if any.
  String? message;

  /// Failed domains currently being retried individually.
  final Set<int> retrying = {};

  /// Failed domains retried successfully since the batch finished.
  final Set<int> recovered = {};

  bool _disposed = false;

  bool get busy => phase == BulkPhase.running;

  /// Eligible domain counts for the confirmation dialog.
  Future<({int missing, int all})> eligibleCounts() async {
    final missing = await _api.eligibleCount('missing');
    final all = await _api.eligibleCount('refresh');
    return (missing: missing, all: all);
  }

  /// How many stored domains a "Re-sync all domain details" would process.
  Future<int> eligibleAll() => _api.eligibleCount('refresh');

  /// Starts a new batch (or continues one already in progress on the server).
  ///
  /// [quiet] is for the automatic follow-up of a Refresh: nothing to do, or a
  /// batch that is already running, is not announced.
  Future<void> start({required bool refresh, bool quiet = false}) async {
    if (busy) return;
    phase = BulkPhase.running;
    message = null;
    recovered.clear();
    progress = null;
    _notify();
    try {
      progress = await _api.startBatch(refresh ? 'refresh' : 'missing');
    } on ApiException catch (e) {
      if (e.code == 'batch_in_progress') {
        message = quiet ? null : 'A batch was already running; continuing it.';
        try {
          progress = await _api.currentBatch();
        } on ApiException catch (e2) {
          return _pause(_errorText(e2));
        }
      } else if (e.code == 'nothing_to_do') {
        phase = BulkPhase.idle;
        message = quiet ? null : 'There are no domains to synchronise.';
        return _notify();
      } else {
        return _pause(_errorText(e), keepProgress: false);
      }
    }
    await _loop();
  }

  /// Background follow-up of a Refresh: synchronises the details of domains
  /// that never had them (newly discovered ones), in `missing` mode only. The
  /// server skips its own discovery because the Refresh just ran it. Does
  /// nothing while a batch is running or paused (the user can resume that one).
  Future<void> startMissing() async {
    if (busy || phase == BulkPhase.paused || _disposed) return;
    await start(refresh: false, quiet: true);
  }

  /// Continues the current batch after a pause.
  Future<void> resume() async {
    if (busy) return;
    phase = BulkPhase.running;
    message = null;
    _notify();
    try {
      progress = await _api.currentBatch();
    } on ApiException catch (e) {
      return _pause(_errorText(e));
    }
    await _loop();
  }

  Future<void> _loop() async {
    var busyWaits = 0;
    while (!_disposed && (progress?.isRunning ?? false)) {
      try {
        progress = await _api.stepBatch(progress!.batchId);
        busyWaits = 0;
        _notify();
      } on ApiException catch (e) {
        if ((e.code == 'batch_busy' || e.code == 'sync_in_progress') &&
            busyWaits < 30) {
          // The server is still finishing the previous step.
          busyWaits++;
          await Future<void>.delayed(const Duration(seconds: 5));
          continue;
        }
        return _pause(_errorText(e));
      }
    }
    if (_disposed) return;
    final p = progress;
    if (p != null && p.status == 'aborted') {
      message = p.abortReason == 'provider_not_configured'
          ? 'Stopped: the provider connection is not configured on the server.'
          : 'The batch was stopped.';
    }
    phase = BulkPhase.finished;
    _notify();
    if (p != null && p.status == 'completed') _runOnCompleted();
  }

  void _runOnCompleted() {
    try {
      Future<void>.sync(onCompleted ?? () {}).catchError((Object _) {});
    } catch (_) {
      // A failed follow-up (e.g. the list reload) must not change the result.
    }
  }

  void _pause(String text, {bool keepProgress = true}) {
    message = text;
    phase = keepProgress && progress != null
        ? BulkPhase.paused
        : BulkPhase.idle;
    _notify();
  }

  /// Retries one failed domain through the single-domain endpoint.
  Future<void> retryFailed(int domainId) async {
    if (retrying.contains(domainId)) return;
    retrying.add(domainId);
    message = null;
    _notify();
    try {
      await _api.enrichDomain(domainId);
      recovered.add(domainId);
    } on ApiException catch (e) {
      message = _errorText(e);
    } finally {
      retrying.remove(domainId);
      _notify();
    }
  }

  void dismiss() {
    if (busy) return;
    phase = BulkPhase.idle;
    progress = null;
    message = null;
    recovered.clear();
    _notify();
  }

  static String _errorText(ApiException e) {
    switch (e.code) {
      case 'network_error':
        return 'Connection lost. Progress is saved on the server; resume when '
            'the connection is back.';
      case 'unauthorized':
        return 'Your session is no longer valid. Please sign in again.';
      case 'too_many_requests':
        return 'Too many requests. Wait a moment, then resume.';
      case 'sync_in_progress':
      case 'batch_busy':
        return 'The previous step is still finishing on the server. Try '
            'resuming in a moment.';
      case 'batch_not_found':
        return 'The batch no longer exists on the server.';
      case 'customer_unknown':
      case 'customer_mismatch':
        return 'Customer details do not match the provider; nothing changed.';
      case 'provider_error':
        return 'The provider could not return the details.';
      case 'provider_not_configured':
        return 'The provider connection is not configured on the server.';
      default:
        return 'Request failed (${e.code}).';
    }
  }

  /// User-facing text for a per-domain failure code.
  static String failureText(String code) => switch (code) {
    'provider_error' => 'Provider error',
    'customer_mismatch' => 'Customer ID mismatch',
    'customer_unknown' => 'No customer ID stored',
    'not_found' => 'Domain not found',
    'storage_error' => 'Storage error',
    _ => 'Failed ($code)',
  };

  void _notify() {
    if (!_disposed) notifyListeners();
  }

  @override
  void dispose() {
    _disposed = true;
    super.dispose();
  }
}

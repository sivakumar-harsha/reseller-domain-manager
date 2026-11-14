import 'dart:math';

import 'package:flutter/foundation.dart';

import '../core/api_client.dart';
import 'renewal_batch.dart';
import 'selected_renewal.dart';

/// Creates a renewal batch from the selected domains.
///
/// The user picks the invoice option BEFORE this runs; it is stored in the
/// batch and can never be changed afterwards. Domains with a verified price
/// are submitted; the backend recomputes every amount itself.
///
/// When used, this only stores a LOCAL batch through `POST renewal-batches`. It cannot
/// renew, invoice or charge anything, and it never talks to ResellerClub.
///
/// Idempotency: every distinct submission (domain ids + years + invoice
/// option) gets its own key. A failed or timed-out attempt KEEPS its key, so
/// tapping again resends the identical payload with the identical key and the
/// backend returns the first batch instead of creating a second. A key is
/// forgotten only when it succeeded or the backend reported a conflict.
class RenewalSubmitController extends ChangeNotifier {
  RenewalSubmitController({
    required this.api,
    required this.selected,
    String Function()? keyGenerator,
  }) : _newKey = keyGenerator ?? _randomKey;

  final ApiClient api;
  final SelectedRenewalController selected;
  final String Function() _newKey;

  InvoiceOption? option;
  bool submitting = false;
  String? error;
  RenewalBatchResult? result;

  /// The user's own ACTIVE batch for a selected domain, found before creating
  /// (or reported by a 409 `duplicate_cycle`). While set, the screen shows and
  /// opens it instead of creating another. Nothing is renewed automatically.
  RenewalBatch? existing;

  /// Payload signature -> key of an attempt that has not succeeded yet.
  final Map<String, String> _keys = {};
  bool _disposed = false;

  /// Domains that go into the batch: those with a verified backend price.
  /// Unsupported, unavailable, failed and loading ones are never submitted.
  List<SelectedRenewalEntry> get submittable => [
    for (final e in selected.entries)
      if (e.renewable) e,
  ];

  /// Selected domains that stay out of the batch.
  List<SelectedRenewalEntry> get excluded => [
    for (final e in selected.entries)
      if (!e.priced) e,
  ];

  /// Priced domains the backend refuses to renew: kept out of the batch.
  List<SelectedRenewalEntry> get blocked => selected.blocked;

  bool get quoting => selected.entries.any((e) => e.loading);

  /// Combined backend figures for the submittable domains (null when they
  /// cannot be summed, for example mixed currencies).
  RenewalTotals? get totals =>
      RenewalTotals.of([for (final e in submittable) e.quote.quote!]);

  List<int> get domainIds => [for (final e in submittable) e.id];

  void choose(InvoiceOption value) {
    if (submitting) return;
    option = value;
    error = null;
    _notify();
  }

  /// Returns true only when the backend confirmed a batch (HTTP 200 or 201).
  Future<bool> submit() async {
    if (submitting) return false; // duplicate tap
    final chosen = option;
    if (chosen == null) {
      error = 'Choose how customers\' invoices are handled before submitting.';
      _notify();
      return false;
    }
    if (quoting) {
      error = 'Wait for the prices to finish loading.';
      _notify();
      return false;
    }
    final ids = domainIds;
    if (selected.entries.isEmpty) {
      error = 'No domains are selected.';
      _notify();
      return false;
    }
    if (ids.isEmpty && blocked.isNotEmpty && excluded.isEmpty) {
      error =
          'None of the selected domains can be renewed from this app, so '
          'there is nothing to submit.';
      _notify();
      return false;
    }
    if (ids.isEmpty || totals == null) {
      error =
          'None of the selected domains has a complete price, so there is '
          'nothing to submit.';
      _notify();
      return false;
    }

    final signature = '${ids.join(',')}|${selected.years}|${chosen.apiValue}';

    submitting = true;
    error = null;
    existing = null;
    _notify();
    try {
      // An active batch for a selected domain is shown instead of creating
      // another. A failed lookup is not fatal: the backend still refuses a
      // duplicate. A batch the backend has safely expired is not reported.
      final active = await _activeBatch(ids);
      if (_disposed) return false;
      if (active != null) {
        existing = active;
        error = existingBatchMessage(active);
        return false;
      }
      final key = _keys.putIfAbsent(signature, _newKey);
      final created = await api.createRenewalBatch(
        domainIds: ids,
        years: selected.years,
        invoiceOption: chosen.apiValue,
        idempotencyKey: key,
      );
      _keys.remove(signature);
      if (_disposed) return false;
      result = created;
      return true;
    } on ApiException catch (e) {
      if (e.code == 'idempotency_conflict') _keys.remove(signature);
      if (e.code == 'duplicate_cycle') {
        final found = await _fromDuplicate(e, ids);
        if (_disposed) return false;
        if (found != null) {
          existing = found;
          error = existingBatchMessage(found);
          return false;
        }
      }
      if (!_disposed) error = messageFor(e);
      return false;
    } catch (_) {
      if (!_disposed) error = 'Could not create the renewal batch. Try again.';
      return false;
    } finally {
      if (!_disposed) {
        submitting = false;
        _notify();
      }
    }
  }

  /// The first active batch among [ids], or null (none found or lookup failed).
  Future<RenewalBatch?> _activeBatch(List<int> ids) async {
    try {
      final found = await api.findActiveRenewalBatches(ids);
      return found.isEmpty ? null : found.values.first;
    } on ApiException {
      return null;
    }
  }

  /// After a 409 `duplicate_cycle`: loads the batch the backend named, or looks
  /// the active batch up when it named none.
  Future<RenewalBatch?> _fromDuplicate(ApiException e, List<int> ids) async {
    final id = e.existingBatchId;
    if (id != null) {
      try {
        return await api.getRenewalBatch(id);
      } on ApiException {
        // fall through to the lookup
      }
    }
    return _activeBatch(ids);
  }

  /// What an existing batch means for the user, by its real backend status.
  static String existingBatchMessage(RenewalBatch b) {
    final name = b.items.isEmpty ? 'this domain' : b.items.first.domainName;
    switch (b.status) {
      case 'pending':
        return 'A renewal batch for $name already exists and is pending. No '
            'renewal has been carried out yet. Open it instead of creating '
            'another.';
      case 'processing':
        return 'A renewal for $name is in progress. Open the existing batch.';
      case 'needs_review':
        return 'The outcome of the renewal for $name is uncertain. Do not renew '
            'again. Open the batch and check its status.';
      case 'completed':
        return 'The renewal for $name was already carried out. Open the batch '
            'to see the result.';
      case 'partial':
        return 'The batch for $name was partly completed. Open it to see what '
            'happened.';
      case 'failed':
        return 'The earlier renewal for $name failed. Open the batch to see why.';
      default:
        return 'A renewal batch for $name already exists. Open it instead of '
            'creating another.';
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

  static String messageFor(ApiException e) {
    switch (e.code) {
      case 'network_error':
        return 'Cannot reach the server. Nothing was confirmed, so it is safe '
            'to try again.';
      case 'unauthorized':
      case 'storage_error':
        return 'Your session is no longer valid. Please sign in again.';
      case 'too_many_requests':
        return 'Too many requests. Please wait a moment and try again.';
      case 'idempotency_conflict':
        return 'This request clashed with an earlier one that had different '
            'details. Review the selection and submit again.';
      case 'duplicate_cycle':
        return 'A renewal request for one of these domains is already open. '
            'Check the renewal history.';
      case 'domain_not_found':
      case 'domain_missing':
        return 'One of the selected domains is no longer in your inventory.';
      case 'quote_unavailable':
        return 'A price for one of the selected domains is not available.';
      case 'domain_not_active':
        return 'One of the selected domains is not Active (suspended, locked, '
            'deleted or in redemption) and cannot be renewed from this app.';
      case 'expiry_unknown':
        return 'The expiry of one of the selected domains is unknown, so it '
            'cannot be renewed from this app.';
      case 'provider_not_configured':
      case 'provider_error':
        return 'The price source is unavailable right now. Try again later.';
      case 'bad_response':
        return 'The server sent an unexpected response. Check the renewal '
            'history before trying again.';
      case 'invalid_request':
      case 'invalid_domain_ids':
      case 'invalid_years':
      case 'invalid_invoice_option':
      case 'invalid_idempotency_key':
      case 'duplicate_domain':
        return 'The server rejected that request. Review the selection.';
      default:
        if (e.statusCode == 502 || e.statusCode == 503) {
          return 'The server is unavailable right now. Try again later.';
        }
        return 'Could not create the renewal batch (${e.code}). Try again.';
    }
  }

  /// 32 random hex characters: matches the backend's `[A-Za-z0-9_-]{16,64}`.
  static String _randomKey() {
    final rng = Random.secure();
    final bytes = [for (var i = 0; i < 16; i++) rng.nextInt(256)];
    return 'rn-${bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join()}';
  }
}

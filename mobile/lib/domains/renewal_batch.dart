import 'package:flutter/foundation.dart';

import '../core/api_client.dart';
import 'domain_controller.dart';
import 'renewal_quote.dart';

/// Invoice handling for a renewal batch. Only these two values exist.
enum InvoiceOption {
  keepInvoice(
    'KeepInvoice',
    "Keep Customers' Invoice(s) pending",
    "Customers' invoices are created and left pending (not paid).",
  ),
  noInvoice(
    'NoInvoice',
    "Don't create Customers' Invoice(s)",
    "No customer invoices are created for this renewal.",
  );

  const InvoiceOption(this.apiValue, this.label, this.detail);

  /// The exact value the backend accepts.
  final String apiValue;
  final String label;
  final String detail;

  static InvoiceOption? fromApi(String? value) {
    for (final o in values) {
      if (o.apiValue == value) return o;
    }
    return null;
  }
}

const _knownDebit = ['unverified', 'confirmed', 'not_found', 'disputed'];

/// Label for the DOMAIN RENEWAL status (the provider result).
String renewalStateLabel(String state) {
  switch (state) {
    case 'succeeded':
      return 'Successful';
    case 'pending':
      return 'Pending';
    case 'submitting':
      return 'Submitting';
    case 'failed':
      return 'Failed';
    case 'uncertain':
      return 'Uncertain';
    default:
      return state;
  }
}

/// Label for the DEBIT VERIFICATION status (a separate, manual check).
String debitStatusLabel(String status) {
  switch (status) {
    case 'confirmed':
      return 'Confirmed';
    case 'not_found':
      return 'Transaction not found';
    case 'disputed':
      return 'Disputed';
    default:
      return 'Not verified';
  }
}

/// One domain inside a batch, exactly as `GET renewal-batches/{id}` returns it.
/// Amounts stay decimal strings; `state` is the backend's value, never derived.
class RenewalBatchItem {
  const RenewalBatchItem({
    required this.id,
    required this.domainId,
    required this.domainName,
    required this.state,
    required this.currency,
    required this.amount,
    required this.gst,
    required this.vat,
    required this.total,
    this.actionId,
    this.invoiceId,
    this.errorCode,
    this.providerMessage,
    this.debitStatus = 'unverified',
    this.debitVerifiedAt,
    this.debitTransactionId,
    this.debitAmount,
    this.debitCurrency,
    this.debitNote,
  });

  final int id;
  final int domainId;
  final String domainName;

  /// `pending`, `submitting`, `succeeded`, `failed` or `uncertain`.
  final String state;
  final String currency;
  final String amount, gst, vat, total;
  final String? actionId, invoiceId, errorCode;

  /// ResellerClub's own explanation of a failed or unusual result, when given.
  final String? providerMessage;

  /// Manual financial verification, independent of [state]: `unverified`,
  /// `confirmed`, `not_found` or `disputed`. Anything missing or unknown reads
  /// as `unverified`, never as confirmed.
  final String debitStatus;
  final DateTime? debitVerifiedAt;
  final String? debitTransactionId, debitAmount, debitCurrency, debitNote;

  /// The manual verification form is offered only for a provider-succeeded
  /// item whose debit is still unverified.
  bool get canVerifyDebit =>
      state == 'succeeded' && debitStatus == 'unverified';

  factory RenewalBatchItem.fromJson(Object? json) {
    if (json is! Map) throw const FormatException('bad item');
    final id = json['id'];
    final domainId = json['domain_id'];
    final name = json['domain_name'];
    final state = json['state'];
    final currency = json['currency'];
    String money(String key) {
      final v = json[key];
      if (v is String && RenewalQuote.isMoney(v)) return v;
      throw const FormatException('bad amount');
    }

    if (id is! num ||
        domainId is! num ||
        name is! String ||
        state is! String ||
        currency is! String) {
      throw const FormatException('bad item');
    }
    String? text(String key) {
      final v = json[key];
      return v == null ? null : '$v';
    }

    return RenewalBatchItem(
      id: id.toInt(),
      domainId: domainId.toInt(),
      domainName: name,
      state: state,
      currency: currency,
      amount: money('renewal_amount'),
      gst: money('gst_amount'),
      vat: money('vat_amount'),
      total: money('total_amount'),
      actionId: text('provider_action_id'),
      invoiceId: text('provider_invoice_id'),
      errorCode: text('error_code'),
      providerMessage: text('provider_message'),
      debitStatus: _knownDebit.contains(json['debit_verification_status'])
          ? json['debit_verification_status'] as String
          : 'unverified',
      debitVerifiedAt: json['debit_verified_at'] is String
          ? DateTime.tryParse(json['debit_verified_at'] as String)
          : null,
      debitTransactionId: text('debit_transaction_id'),
      debitAmount: text('debit_verified_amount'),
      debitCurrency: text('debit_verified_currency'),
      debitNote: text('debit_verification_note'),
    );
  }

  String money(String value) => RenewalQuote(
    domain: '',
    years: 1,
    status: 'available',
    currency: currency,
    amount: value,
  ).money(value);
}

/// A renewal batch from the list or detail endpoint (items only on detail).
class RenewalBatch {
  const RenewalBatch({
    required this.id,
    required this.status,
    required this.invoiceOption,
    required this.years,
    required this.counts,
    this.createdAt,
    this.items = const [],
  });

  final int id;

  /// `pending`, `processing`, `completed`, `partial`, `failed` or `needs_review`.
  final String status;

  /// The backend value (`KeepInvoice` / `NoInvoice`).
  final String invoiceOption;
  final int years;
  final Map<String, int> counts;
  final DateTime? createdAt;
  final List<RenewalBatchItem> items;

  int count(String state) => counts[state] ?? 0;
  int get total => counts.values.fold(0, (a, b) => a + b);

  InvoiceOption? get option => InvoiceOption.fromApi(invoiceOption);

  factory RenewalBatch.fromJson(Object? json) {
    if (json is! Map) throw const FormatException('bad batch');
    final id = json['id'];
    final status = json['status'];
    final option = json['invoice_option'];
    final years = json['years'];
    final counts = json['item_counts'];
    if (id is! num ||
        status is! String ||
        option is! String ||
        years is! num ||
        counts is! Map) {
      throw const FormatException('bad batch');
    }
    final parsed = <String, int>{};
    counts.forEach((k, v) {
      if (k is String && v is num) parsed[k] = v.toInt();
    });
    final items = json['items'];
    final created = json['created_at'];
    return RenewalBatch(
      id: id.toInt(),
      status: status,
      invoiceOption: option,
      years: years.toInt(),
      counts: parsed,
      createdAt: created is String ? DateTime.tryParse(created) : null,
      items: items is List
          ? [for (final i in items) RenewalBatchItem.fromJson(i)]
          : const [],
    );
  }
}

/// `POST renewal-batches` result: 201 for a new batch, 200 (`replayed`) when
/// the idempotency key already existed.
class RenewalBatchResult {
  const RenewalBatchResult({required this.replayed, required this.batch});

  final bool replayed;
  final RenewalBatch batch;

  factory RenewalBatchResult.fromJson(Map<String, dynamic> json) {
    final replayed = json['replayed'];
    if (replayed is! bool) throw const FormatException('bad result');
    final batch = RenewalBatch.fromJson(json['batch']);
    if (batch.items.isEmpty) throw const FormatException('empty batch');
    return RenewalBatchResult(replayed: replayed, batch: batch);
  }
}

class RenewalBatchPage {
  const RenewalBatchPage({
    required this.items,
    required this.page,
    required this.totalPages,
    required this.total,
  });

  final List<RenewalBatch> items;
  final int page, totalPages, total;

  bool get hasMore => page < totalPages;

  factory RenewalBatchPage.fromJson(Map<String, dynamic> json) {
    final data = json['data'];
    final meta = json['meta'];
    if (data is! List || meta is! Map) throw const FormatException('bad page');
    int number(String key) {
      final v = meta[key];
      if (v is! num) throw const FormatException('bad meta');
      return v.toInt();
    }

    return RenewalBatchPage(
      items: [for (final b in data) RenewalBatch.fromJson(b)],
      page: number('page'),
      totalPages: number('total_pages'),
      total: number('total'),
    );
  }
}

/// User-facing text for a batch status or item state; the value itself is the
/// backend's. `pending` never reads as a success.
String statusLabel(String status) {
  switch (status) {
    case 'pending':
      return 'Pending';
    case 'submitting':
      return 'Submitting';
    case 'processing':
      return 'Processing';
    case 'succeeded':
      return 'Succeeded';
    case 'completed':
      return 'Completed';
    case 'partial':
      return 'Partly completed';
    case 'failed':
      return 'Failed';
    case 'uncertain':
      return 'Uncertain';
    case 'needs_review':
      return 'Needs review';
    default:
      return status;
  }
}

String _batchMessage(ApiException e, {String what = 'renewal batch'}) {
  switch (e.code) {
    case 'not_found':
      return 'That renewal batch was not found.';
    case 'bad_response':
      return 'The server sent an unexpected response. Try again.';
    case 'network_error':
    case 'unauthorized':
    case 'storage_error':
    case 'too_many_requests':
      return DomainController.messageFor(e);
    default:
      return 'Could not load the $what (${e.code}). Try again.';
  }
}

/// Loads one batch with `GET renewal-batches/{id}`. [initial] (the create
/// response) is shown immediately; the loaded copy replaces it. A failed
/// refresh keeps what is already shown.
class RenewalBatchDetailController extends ChangeNotifier {
  RenewalBatchDetailController({
    required ApiClient api,
    required this.batchId,
    this.initial,
  }) : _api = api,
       batch = initial;

  final ApiClient _api;
  final int batchId;
  final RenewalBatch? initial;

  RenewalBatch? batch;
  String? error;
  bool loading = false;
  bool _disposed = false;
  int _generation = 0;

  Future<void> load() async {
    final gen = ++_generation;
    loading = true;
    error = null;
    _notify();
    try {
      final result = await _api.getRenewalBatch(batchId);
      if (_disposed || gen != _generation) return;
      batch = result;
    } on ApiException catch (e) {
      if (_disposed || gen != _generation) return;
      error = _batchMessage(e);
    } catch (_) {
      if (_disposed || gen != _generation) return;
      error = 'Could not load the renewal batch. Try again.';
    } finally {
      if (!_disposed && gen == _generation) {
        loading = false;
        _notify();
      }
    }
  }

  /// True while a manual Check status is running.
  bool checking = false;

  /// Manual "Check status": `POST renewal-batches/{id}/reconcile`, which only
  /// looks up recorded provider actions (read-only) and never resends a
  /// renewal. Never called automatically.
  Future<void> checkStatus() async {
    if (checking) return;
    final gen = ++_generation;
    checking = true;
    error = null;
    _notify();
    try {
      final result = await _api.reconcileRenewalBatch(batchId);
      if (_disposed || gen != _generation) return;
      batch = result;
    } on ApiException catch (e) {
      if (_disposed || gen != _generation) return;
      error = 'Could not check the status (${e.code}). Try again.';
    } catch (_) {
      if (_disposed || gen != _generation) return;
      error = 'Could not check the status. Try again.';
    } finally {
      checking = false;
      _notify();
    }
  }

  /// True while a manual debit verification is being saved.
  bool verifying = false;

  /// Saves the operator's manual debit verification (`POST .../debit-verification`).
  /// It never renews anything. Returns null on success, otherwise a message;
  /// the item only reads as confirmed once the backend has returned it so.
  Future<String?> verifyDebit({
    required int itemId,
    required String status,
    String? transactionId,
    String? amount,
    String? currency,
    required String note,
  }) async {
    if (verifying) return 'A verification is already being saved.';
    verifying = true;
    _notify();
    try {
      final result = await _api.verifyRenewalDebit(
        batchId: batchId,
        itemId: itemId,
        body: {
          'status': status,
          'transaction_id': ?transactionId,
          'amount': ?amount,
          'currency': ?currency,
          'note': note,
        },
      );
      batch = result;
      return null;
    } on ApiException catch (e) {
      return debitVerificationMessage(e);
    } catch (_) {
      return 'Could not save the verification. Try again.';
    } finally {
      verifying = false;
      _notify();
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

/// User-facing text for a refused or failed debit verification.
String debitVerificationMessage(ApiException e) {
  switch (e.code) {
    case 'item_not_succeeded':
      return 'Only a successfully renewed item can be verified.';
    case 'already_confirmed':
      return 'This debit is already confirmed and cannot be changed here.';
    case 'verification_evidence_required':
      return 'Confirming needs a transaction ID, amount, currency and note.';
    case 'verification_conflict':
      return 'This item was just updated. Refresh and check its status.';
    case 'invalid_request':
      return 'Check the transaction ID, amount, currency and note.';
    case 'not_found':
      return 'That renewal item was not found.';
    default:
      return 'Could not save the verification (${e.code}). Try again.';
  }
}

/// Renewal history with `GET renewal-batches?page=&per_page=` pagination.
class RenewalBatchListController extends ChangeNotifier {
  RenewalBatchListController({required ApiClient api, this.perPage = 20})
    : _api = api;

  final ApiClient _api;
  final int perPage;

  final List<RenewalBatch> items = [];
  int _page = 0;
  int _totalPages = 1;
  bool loading = false;
  bool loaded = false;
  String? error;
  bool _disposed = false;

  bool get hasMore => _page < _totalPages;
  bool get empty => loaded && items.isEmpty && error == null;

  Future<void> load() => _fetch(1);

  Future<void> loadMore() =>
      hasMore && !loading ? _fetch(_page + 1) : Future.value();

  Future<void> _fetch(int page) async {
    if (loading) return;
    loading = true;
    error = null;
    _notify();
    try {
      final result = await _api.listRenewalBatches(
        page: page,
        perPage: perPage,
      );
      if (_disposed) return;
      if (page == 1) items.clear();
      items.addAll(result.items);
      _page = result.page;
      _totalPages = result.totalPages;
      loaded = true;
    } on ApiException catch (e) {
      if (_disposed) return;
      error = _batchMessage(e, what: 'renewal history');
    } catch (_) {
      if (_disposed) return;
      error = 'Could not load the renewal history. Try again.';
    } finally {
      if (!_disposed) {
        loading = false;
        _notify();
      }
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

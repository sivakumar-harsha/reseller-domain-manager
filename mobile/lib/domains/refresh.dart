import 'dart:async';

import 'package:flutter/foundation.dart';

import 'auto_refresh.dart';
import 'balance.dart';
import 'bulk_sync.dart';

/// The one Refresh: pull-to-refresh on the Dashboard and the Domain List, and
/// the automatic refresh on launch and resume, all run through here.
///
/// 1. The inventory sync and the balance request start together and run
///    independently: a balance failure never undoes a successful inventory
///    refresh, and an inventory failure never blocks the balance.
/// 2. After a successful inventory sync the local list and expiry counts are
///    reloaded (the cached rows stay on screen the whole time).
/// 3. Domains whose details were never synchronised (new ones) are then
///    enriched in the background through the existing batch. Domains that
///    already have details are never re-fetched here.
///
/// Only one Refresh runs at a time; a call that arrives meanwhile (a second
/// screen, a resume, a rebuild) joins the running one. If the inventory sync
/// fails, the data on screen is not presented as freshly synchronised: no
/// "synced" time is recorded and no success message is shown.
class RefreshController extends ChangeNotifier {
  RefreshController({
    required this.inventory,
    required this.balance,
    required this.bulk,
    required this.reload,
    this.balanceWindow = const Duration(seconds: 60),
  });

  /// Null disables the provider sync (only the local reload and balance run).
  final AutoRefreshController? inventory;
  final BalanceController balance;
  final BulkSyncController bulk;

  /// Reloads the local list and expiry summary. Must keep the previous data on
  /// failure and report errors through its own state.
  final Future<void> Function() reload;

  /// An automatic refresh does not re-request a balance younger than this.
  final Duration balanceWindow;

  /// A refresh is running (inventory, balance or the local reload).
  bool refreshing = false;

  /// Result of the last refresh, for a snackbar and the status line. Cleared
  /// when the next refresh starts.
  String? message;

  /// [message] describes a failure or an incomplete refresh.
  bool messageIsError = false;

  /// Last successful inventory sync / balance request, or null.
  DateTime? get lastInventoryAt => inventory?.lastRefreshedAt;
  DateTime? get lastBalanceAt => balance.lastLoadedAt;

  Future<void>? _inflight;
  bool _disposed = false;

  /// Runs a refresh. [manual] is a user pull-to-refresh (ignores the 15-minute
  /// interval and the failure cooldown, always re-requests the balance);
  /// otherwise it is the automatic launch/resume refresh.
  Future<void> refresh({bool manual = true}) {
    final running = _inflight;
    if (running != null) return running;
    final f = _run(manual).whenComplete(() => _inflight = null);
    _inflight = f;
    return f;
  }

  Future<void> _run(bool manual) async {
    refreshing = true;
    message = null;
    messageIsError = false;
    _notify();

    var enrich = false;
    try {
      final inv = inventory;
      final detailsRunning = bulk.busy;

      // Started together, awaited separately: neither can fail the other.
      final inventoryF = (inv == null || detailsRunning)
          ? Future.value(InventoryOutcome.blocked)
          : inv.refresh(force: manual).catchError((Object _) {
              return InventoryOutcome.failed;
            });
      final balanceF = balance
          .loadOnce(within: manual ? Duration.zero : balanceWindow)
          .catchError((Object _) {});

      final outcome = await inventoryF;
      await balanceF;

      switch (outcome) {
        case InventoryOutcome.success:
          await _reload();
          message = balance.unavailable
              ? 'Domains refreshed. Balance is unavailable.'
              : 'Domains and balance refreshed.';
          enrich = true;
        case InventoryOutcome.failed:
          message =
              inv?.error ?? 'Could not refresh domains. Showing saved data.';
          messageIsError = true;
        case InventoryOutcome.busy:
          // Another sync is running on the server: show what is stored, but
          // do not call it a refresh.
          await _reload();
          message =
              inv?.error ?? 'A sync is already running. Showing saved data.';
          messageIsError = true;
        case InventoryOutcome.notDue:
          // Automatic attempt with nothing due; nothing to announce.
          break;
        case InventoryOutcome.blocked:
          if (manual) {
            await _reload();
            if (inv != null) {
              message =
                  'Domain details are still syncing. Reloaded saved data.';
            }
          }
      }
    } finally {
      refreshing = false;
      _notify();
    }

    if (enrich && !_disposed) {
      // Background: progress shows in the Domain List's panel.
      unawaited(bulk.startMissing());
    }
  }

  Future<void> _reload() async {
    try {
      await reload();
    } catch (_) {
      // The reload keeps its own error state; the sync itself succeeded.
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

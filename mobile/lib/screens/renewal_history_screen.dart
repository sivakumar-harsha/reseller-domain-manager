import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../domains/renewal_batch.dart';
import '../domains/renewal_execution.dart';
import '../theme/app_spacing.dart';
import 'renewal_batch_screen.dart';

/// Opens the renewal history (read-only list of the signed-in user's batches).
Future<void> showRenewalHistory(
  BuildContext context, {
  required ApiClient api,
}) {
  return Navigator.of(context).push(
    MaterialPageRoute<void>(
      builder: (_) => RenewalHistoryScreen(
        controller: RenewalBatchListController(api: api),
        api: api,
      ),
    ),
  );
}

/// Past renewal batches from `GET renewal-batches`, newest first, with
/// "Load more" pagination. Opening one shows its detail screen.
class RenewalHistoryScreen extends StatefulWidget {
  const RenewalHistoryScreen({
    super.key,
    required this.controller,
    required this.api,
    this.attempts,
  });

  final RenewalBatchListController controller;
  final ApiClient api;

  /// Attempt markers. Defaults to secure storage.
  final RenewalAttemptStore? attempts;

  @override
  State<RenewalHistoryScreen> createState() => _RenewalHistoryScreenState();
}

class _RenewalHistoryScreenState extends State<RenewalHistoryScreen> {
  RenewalBatchListController get _c => widget.controller;

  @override
  void initState() {
    super.initState();
    _c.load();
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  void _open(RenewalBatch b) {
    Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => RenewalBatchScreen(
          controller: RenewalBatchDetailController(
            api: widget.api,
            batchId: b.id,
          ),
          api: widget.api,
          attempts: widget.attempts,
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Renewal history')),
      body: SafeArea(
        child: ListenableBuilder(
          listenable: _c,
          builder: (context, _) {
            if (_c.items.isEmpty) {
              if (_c.loading || !_c.loaded && _c.error == null) {
                return const Center(child: CircularProgressIndicator());
              }
              if (_c.error != null) {
                return Center(
                  child: Padding(
                    padding: const EdgeInsets.all(AppSpacing.md),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(_c.error!, textAlign: TextAlign.center),
                        const SizedBox(height: AppSpacing.sm),
                        FilledButton(
                          onPressed: _c.load,
                          child: const Text('Retry'),
                        ),
                      ],
                    ),
                  ),
                );
              }
              return const Center(child: Text('No renewal batches yet.'));
            }
            return RefreshIndicator(
              onRefresh: _c.load,
              child: ListView(
                padding: const EdgeInsets.all(AppSpacing.md),
                children: [
                  for (final b in _c.items) _tile(context, b),
                  if (_c.error != null)
                    Padding(
                      padding: const EdgeInsets.symmetric(
                        vertical: AppSpacing.sm,
                      ),
                      child: Text(
                        _c.error!,
                        style: TextStyle(
                          color: Theme.of(context).colorScheme.error,
                        ),
                      ),
                    ),
                  if (_c.hasMore || _c.error != null && _c.loaded)
                    Center(
                      child: _c.loading
                          ? const Padding(
                              padding: EdgeInsets.all(AppSpacing.sm),
                              child: CircularProgressIndicator(),
                            )
                          : TextButton(
                              key: const ValueKey('history-load-more'),
                              onPressed: _c.loadMore,
                              child: const Text('Load more'),
                            ),
                    ),
                ],
              ),
            );
          },
        ),
      ),
    );
  }

  Widget _tile(BuildContext context, RenewalBatch b) {
    final theme = Theme.of(context);
    return Card(
      child: InkWell(
        borderRadius: AppRadius.card,
        onTap: () => _open(b),
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.component),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      'Batch #${b.id}',
                      style: theme.textTheme.bodyLarge?.copyWith(
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ),
                  StatusChip(b.status),
                ],
              ),
              const SizedBox(height: AppSpacing.xs),
              Text(formatBatchDate(b.createdAt)),
              Text(b.option?.label ?? b.invoiceOption),
              Text(
                '${b.total} ${b.total == 1 ? 'item' : 'items'} · '
                '${b.count('pending')} pending · '
                '${b.count('succeeded')} succeeded · '
                '${b.count('failed')} failed · '
                '${b.count('uncertain')} uncertain',
                style: theme.textTheme.bodySmall,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

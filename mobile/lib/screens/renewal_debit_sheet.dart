import 'package:flutter/material.dart';

import '../domains/renewal_batch.dart';
import '../theme/app_spacing.dart';

/// Manual debit verification form. The operator records what ResellerClub's
/// transaction history shows; nothing here renews or charges anything, and the
/// result is only shown as saved once the backend has accepted it.
class RenewalDebitSheet extends StatefulWidget {
  const RenewalDebitSheet({
    super.key,
    required this.item,
    required this.controller,
  });

  final RenewalBatchItem item;
  final RenewalBatchDetailController controller;

  @override
  State<RenewalDebitSheet> createState() => _RenewalDebitSheetState();
}

class _RenewalDebitSheetState extends State<RenewalDebitSheet> {
  final _tx = TextEditingController();
  final _amount = TextEditingController();
  final _currency = TextEditingController();
  final _note = TextEditingController();
  String _status = 'confirmed';
  bool _saving = false;
  String? _error;

  @override
  void dispose() {
    _tx.dispose();
    _amount.dispose();
    _currency.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final tx = _tx.text.trim();
    final amount = _amount.text.trim();
    final currency = _currency.text.trim().toUpperCase();
    final note = _note.text.trim();
    final confirming = _status == 'confirmed';
    if (note.isEmpty) {
      setState(() => _error = 'Enter a note describing what you checked.');
      return;
    }
    if (confirming && (tx.isEmpty || amount.isEmpty || currency.isEmpty)) {
      setState(
        () => _error =
            'Confirming needs a transaction ID, amount, currency and note.',
      );
      return;
    }
    if (amount.isNotEmpty &&
        !RegExp(r'^\d{1,10}(\.\d{1,2})?$').hasMatch(amount)) {
      setState(() => _error = 'Enter the amount as a number, e.g. 1674.42.');
      return;
    }
    if (currency.isNotEmpty && !RegExp(r'^[A-Z]{3}$').hasMatch(currency)) {
      setState(() => _error = 'Enter a 3-letter currency code, e.g. INR.');
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
    });
    final error = await widget.controller.verifyDebit(
      itemId: widget.item.id,
      status: _status,
      transactionId: tx.isEmpty ? null : tx,
      amount: amount.isEmpty ? null : amount,
      currency: currency.isEmpty ? null : currency,
      note: note,
    );
    if (!mounted) return;
    if (error == null) {
      Navigator.of(context).pop(true);
    } else {
      setState(() {
        _saving = false;
        _error = error;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: EdgeInsets.fromLTRB(
        AppSpacing.md,
        AppSpacing.md,
        AppSpacing.md,
        MediaQuery.of(context).viewInsets.bottom + AppSpacing.md,
      ),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Verify debit: ${widget.item.domainName}',
              style: theme.textTheme.titleMedium,
            ),
            const SizedBox(height: AppSpacing.xs),
            Text(
              'Record what you found in the ResellerClub transaction history. '
              'This does not renew the domain or charge anything.',
              style: theme.textTheme.bodySmall,
            ),
            const SizedBox(height: AppSpacing.sm),
            DropdownButtonFormField<String>(
              key: const ValueKey('debit-status'),
              initialValue: _status,
              decoration: const InputDecoration(labelText: 'Result'),
              items: const [
                DropdownMenuItem(
                  value: 'confirmed',
                  child: Text('Debit found and confirmed'),
                ),
                DropdownMenuItem(
                  value: 'not_found',
                  child: Text('Transaction not found'),
                ),
                DropdownMenuItem(value: 'disputed', child: Text('Disputed')),
              ],
              onChanged: _saving
                  ? null
                  : (v) => setState(() => _status = v ?? 'confirmed'),
            ),
            TextField(
              key: const ValueKey('debit-tx'),
              controller: _tx,
              enabled: !_saving,
              decoration: const InputDecoration(labelText: 'Transaction ID'),
            ),
            TextField(
              key: const ValueKey('debit-amount'),
              controller: _amount,
              enabled: !_saving,
              keyboardType: const TextInputType.numberWithOptions(
                decimal: true,
              ),
              decoration: const InputDecoration(labelText: 'Actual amount'),
            ),
            TextField(
              key: const ValueKey('debit-currency'),
              controller: _currency,
              enabled: !_saving,
              textCapitalization: TextCapitalization.characters,
              decoration: const InputDecoration(
                labelText: 'Currency (e.g. INR)',
              ),
            ),
            TextField(
              key: const ValueKey('debit-note'),
              controller: _note,
              enabled: !_saving,
              maxLines: 3,
              decoration: const InputDecoration(labelText: 'Operator note'),
            ),
            if (_error != null)
              Padding(
                padding: const EdgeInsets.only(top: AppSpacing.sm),
                child: Text(
                  _error!,
                  key: const ValueKey('debit-error'),
                  style: TextStyle(color: theme.colorScheme.error),
                ),
              ),
            const SizedBox(height: AppSpacing.sm),
            FilledButton(
              key: const ValueKey('debit-save'),
              onPressed: _saving ? null : _save,
              child: Text(_saving ? 'Saving...' : 'Save verification'),
            ),
          ],
        ),
      ),
    );
  }
}

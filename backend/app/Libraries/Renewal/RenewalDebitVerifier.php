<?php

declare(strict_types=1);

namespace App\Libraries\Renewal;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Records the operator's MANUAL verification of the financial debit of one
 * renewal item, against ResellerClub's transaction history.
 *
 * This class has no provider client and no execution service: it cannot call
 * `domains/renew.json` or any other provider endpoint, and it never writes the
 * renewal `state`, `provider_*`, `error_code` or `completed_at` columns. It only
 * writes the `debit_*` columns and appends to `renewal_debit_verifications`.
 *
 * Rules (all fixed error codes, nothing is written on a refusal):
 * - the item must belong to the user's batch and be provider-`succeeded`;
 * - `confirmed` needs transaction id, amount, currency and a note;
 *   `not_found` / `disputed` need a note and can never mark the debit confirmed;
 * - a `confirmed` result is never overwritten: an identical repeat is a no-op
 *   replay, anything else is refused with `already_confirmed`;
 * - a `not_found` / `disputed` result may later be updated (including to
 *   `confirmed`); every accepted change is appended to the audit table;
 * - the update is one conditional UPDATE on the previous status, so two
 *   concurrent submissions cannot both win.
 */
final class RenewalDebitVerifier
{
    public const STATUSES = ['confirmed', 'not_found', 'disputed'];

    private BaseConnection $db;

    /** @var callable(): DateTimeImmutable */
    private $clock;

    public function __construct(?BaseConnection $db = null, ?callable $clock = null)
    {
        $this->db    = $db ?? Database::connect();
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * @param array<string, mixed> $input status, transaction_id, amount, currency, note
     *
     * @return array{replayed: bool, item_id: int}
     *
     * @throws RenewalException
     */
    public function record(int $userId, int $batchId, int $itemId, array $input): array
    {
        $clean = $this->validate($input);

        $row = $this->load($userId, $batchId, $itemId);

        if ($row === null) {
            throw new RenewalException('not_found');
        }

        if ((string) $row['state'] !== 'succeeded') {
            throw new RenewalException('item_not_succeeded');
        }

        $previous = (string) $row['debit_verification_status'];

        if ($previous === 'confirmed') {
            if ($this->sameEvidence($row, $clean)) {
                return ['replayed' => true, 'item_id' => $itemId];
            }

            throw new RenewalException('already_confirmed');
        }

        if ($previous === $clean['status'] && $this->sameEvidence($row, $clean)) {
            return ['replayed' => true, 'item_id' => $itemId];
        }

        $now = ($this->clock)()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        try {
            $this->db->transBegin();

            $this->db->table('renewal_items')
                ->where('id', $itemId)
                ->where('state', 'succeeded')
                ->where('debit_verification_status', $previous)
                ->update([
                    'debit_verification_status' => $clean['status'],
                    'debit_verified_at'         => $now,
                    'debit_transaction_id'      => $clean['transaction_id'],
                    'debit_verified_amount'     => $clean['amount'],
                    'debit_verified_currency'   => $clean['currency'],
                    'debit_verification_note'   => $clean['note'],
                    'updated_at'                => $now,
                ]);

            if ($this->db->affectedRows() !== 1) {
                // Someone else changed it first: change nothing and report the conflict.
                $this->db->transRollback();

                throw new RenewalException('verification_conflict');
            }

            $this->db->table('renewal_debit_verifications')->insert([
                'renewal_item_id' => $itemId,
                'user_id'         => $userId,
                'previous_status' => $previous,
                'new_status'      => $clean['status'],
                'transaction_id'  => $clean['transaction_id'],
                'amount'          => $clean['amount'],
                'currency'        => $clean['currency'],
                'note'            => $clean['note'],
                'created_at'      => $now,
            ]);

            if ($this->db->transStatus() === false) {
                $this->db->transRollback();

                throw new RenewalException('storage_error');
            }

            $this->db->transCommit();
        } catch (RenewalException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'Debit verification failed: {class}', ['class' => $e::class]);

            throw new RenewalException('storage_error');
        }

        return ['replayed' => false, 'item_id' => $itemId];
    }

    /**
     * @param array<string, mixed> $in
     *
     * @return array{status: string, transaction_id: ?string, amount: ?string, currency: ?string, note: string}
     */
    private function validate(array $in): array
    {
        $status = $in['status'] ?? null;

        if (! is_string($status) || ! in_array($status, self::STATUSES, true)) {
            throw new RenewalException('invalid_request');
        }

        $tx   = $this->optional($in['transaction_id'] ?? null);
        $amt  = $this->optional($in['amount'] ?? null);
        $cur  = $this->optional($in['currency'] ?? null);
        $note = is_string($in['note'] ?? null) ? trim($in['note']) : '';

        if ($note === '' || mb_strlen($note) > 1000) {
            throw new RenewalException('invalid_request');
        }

        if ($tx !== null && preg_match('/^[A-Za-z0-9._:\-]{1,64}$/', $tx) !== 1) {
            throw new RenewalException('invalid_request');
        }

        if ($amt !== null) {
            if (preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/', $amt) !== 1) {
                throw new RenewalException('invalid_request');
            }

            $amt = number_format((float) $amt, 2, '.', '');

            if ((float) $amt <= 0) {
                throw new RenewalException('invalid_request');
            }
        }

        if ($cur !== null) {
            $cur = strtoupper($cur);

            if (preg_match('/^[A-Z]{3}$/', $cur) !== 1) {
                throw new RenewalException('invalid_request');
            }
        }

        // Confirmation is evidence-based: every field is mandatory.
        if ($status === 'confirmed' && ($tx === null || $amt === null || $cur === null)) {
            throw new RenewalException('verification_evidence_required');
        }

        return ['status' => $status, 'transaction_id' => $tx, 'amount' => $amt, 'currency' => $cur, 'note' => $note];
    }

    private function optional(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new RenewalException('invalid_request');
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $clean
     */
    private function sameEvidence(array $row, array $clean): bool
    {
        return (string) $row['debit_verification_status'] === $clean['status']
            && ($row['debit_transaction_id'] === null ? null : (string) $row['debit_transaction_id']) === $clean['transaction_id']
            && ($row['debit_verified_amount'] === null ? null : number_format((float) $row['debit_verified_amount'], 2, '.', '')) === $clean['amount']
            && ($row['debit_verified_currency'] === null ? null : (string) $row['debit_verified_currency']) === $clean['currency']
            && trim((string) ($row['debit_verification_note'] ?? '')) === $clean['note'];
    }

    /** @return array<string, mixed>|null */
    private function load(int $userId, int $batchId, int $itemId): ?array
    {
        return $this->db->query(
            'SELECT i.id, i.state, i.debit_verification_status, i.debit_transaction_id, i.debit_verified_amount, '
            . 'i.debit_verified_currency, i.debit_verification_note '
            . 'FROM renewal_items i JOIN renewal_batches b ON b.id = i.batch_id '
            . 'WHERE i.id = ? AND i.batch_id = ? AND b.user_id = ?',
            [$itemId, $batchId, $userId],
        )->getRowArray();
    }
}

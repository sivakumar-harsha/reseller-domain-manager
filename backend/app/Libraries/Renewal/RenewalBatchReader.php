<?php

declare(strict_types=1);

namespace App\Libraries\Renewal;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Read-only access to renewal batches for the API. EVERY query is scoped by the
 * authenticated user's id, so a batch of another user is indistinguishable from
 * a batch that does not exist. Nothing here writes, and nothing here talks to
 * the provider.
 *
 * Provider order ids, customer ids and contact data are never selected: only
 * the item state, amounts and the provider action / invoice ids are returned.
 */
final class RenewalBatchReader
{
    private const STATES = ['pending', 'submitting', 'succeeded', 'failed', 'uncertain'];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Newest first; the id breaks ties so paging is stable.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function listForUser(int $userId, int $page, int $perPage): array
    {
        $total = (int) $this->db->table('renewal_batches')->where('user_id', $userId)->countAllResults();

        $rows = $this->db->table('renewal_batches')
            ->select('id, invoice_option, years, status, created_at, updated_at, finished_at')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        $out = [];

        foreach ($rows as $row) {
            $out[] = $this->presentBatch($row, $this->counts((int) $row['id']), null);
        }

        return ['rows' => $out, 'total' => $total];
    }

    /**
     * The batch with its items, or null when it does not exist or is not the user's.
     *
     * @return array<string, mixed>|null
     */
    public function findForUser(int $userId, int $batchId): ?array
    {
        $batch = $this->db->table('renewal_batches')
            ->select('id, invoice_option, years, status, created_at, updated_at, finished_at')
            ->where('id', $batchId)->where('user_id', $userId)
            ->get()->getRowArray();

        if ($batch === null) {
            return null;
        }

        $items = $this->db->query(
            'SELECT i.id, i.domain_id, d.domain_name, i.expiry_at_request, i.renewal_amount, i.gst_amount, i.vat_amount, '
            . 'i.total_amount, i.currency, i.state, i.provider_action_id, i.provider_invoice_id, i.error_code, i.provider_message, '
            . 'i.created_at, i.updated_at, i.completed_at, i.debit_verification_status, i.debit_verified_at, '
            . 'i.debit_transaction_id, i.debit_verified_amount, i.debit_verified_currency, i.debit_verification_note '
            . 'FROM renewal_items i JOIN domains d ON d.id = i.domain_id WHERE i.batch_id = ? ORDER BY i.id ASC',
            [(int) $batch['id']],
        )->getResultArray();

        return $this->presentBatch($batch, $this->counts((int) $batch['id']), array_map(fn (array $r): array => $this->presentItem($r), $items));
    }

    /** True only for a batch that exists AND belongs to the user. */
    public function ownedBy(int $userId, int $batchId): bool
    {
        return $this->db->table('renewal_batches')->select('id')->where('id', $batchId)->where('user_id', $userId)->get()->getRowArray() !== null;
    }

    /**
     * @return array<string, int>
     */
    private function counts(int $batchId): array
    {
        $counts = array_fill_keys(self::STATES, 0);

        $rows = $this->db->query('SELECT state, COUNT(*) AS n FROM renewal_items WHERE batch_id = ? GROUP BY state', [$batchId])->getResultArray();

        foreach ($rows as $row) {
            if (array_key_exists((string) $row['state'], $counts)) {
                $counts[(string) $row['state']] = (int) $row['n'];
            }
        }

        return $counts;
    }

    /**
     * @param array<string, mixed>             $row
     * @param array<string, int>               $counts
     * @param list<array<string, mixed>>|null  $items
     *
     * @return array<string, mixed>
     */
    private function presentBatch(array $row, array $counts, ?array $items): array
    {
        $out = [
            'id'             => (int) $row['id'],
            'status'         => (string) $row['status'],
            'invoice_option' => (string) $row['invoice_option'],
            'years'          => (int) $row['years'],
            'item_counts'    => $counts,
            'created_at'     => $this->iso($row['created_at']),
            'updated_at'     => $this->iso($row['updated_at']),
            'finished_at'    => $this->iso($row['finished_at']),
        ];

        if ($items !== null) {
            $out['items'] = $items;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function presentItem(array $r): array
    {
        return [
            'id'                  => (int) $r['id'],
            'domain_id'           => (int) $r['domain_id'],
            'domain_name'         => (string) $r['domain_name'],
            'state'               => (string) $r['state'],
            'expiry_at_request'   => $this->iso($r['expiry_at_request']),
            'renewal_amount'      => (string) $r['renewal_amount'],
            'gst_amount'          => (string) $r['gst_amount'],
            'vat_amount'          => (string) $r['vat_amount'],
            'total_amount'        => (string) $r['total_amount'],
            'currency'            => (string) $r['currency'],
            'provider_action_id'  => $r['provider_action_id'] === null ? null : (string) $r['provider_action_id'],
            'provider_invoice_id' => $r['provider_invoice_id'] === null ? null : (string) $r['provider_invoice_id'],
            'error_code'          => $r['error_code'] === null ? null : (string) $r['error_code'],
            // The provider's own explanation, when it gave one.
            'provider_message'    => ($r['provider_message'] ?? null) === null ? null : (string) $r['provider_message'],
            'created_at'          => $this->iso($r['created_at']),
            'updated_at'          => $this->iso($r['updated_at']),
            'completed_at'        => $this->iso($r['completed_at']),
            // Financial verification, independent of `state`: only the manual verification action sets it.
            'debit_verification_status' => (string) $r['debit_verification_status'],
            'debit_verified_at'         => $this->iso($r['debit_verified_at']),
            'debit_transaction_id'      => $r['debit_transaction_id'] === null ? null : (string) $r['debit_transaction_id'],
            'debit_verified_amount'     => $r['debit_verified_amount'] === null ? null : (string) $r['debit_verified_amount'],
            'debit_verified_currency'   => $r['debit_verified_currency'] === null ? null : (string) $r['debit_verified_currency'],
            'debit_verification_note'   => $r['debit_verification_note'] === null ? null : (string) $r['debit_verification_note'],
        ];
    }

    /** UTC "Y-m-d H:i:s" to ISO 8601 with a Z suffix. */
    private function iso(mixed $utc): ?string
    {
        if ($utc === null || $utc === '') {
            return null;
        }

        return (new DateTimeImmutable((string) $utc, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}

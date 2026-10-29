<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Read access to the cached domain inventory. ResellerClub stays the
 * authoritative source; rows are written by the (future) sync service.
 * All instants are UTC; the "Y-m-d H:i:s" strings are converted at the API edge.
 */
class DomainModel extends Model
{
    protected $table            = 'domains';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = true;
    protected $dateFormat       = 'datetime';
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';
    protected $allowedFields    = [
        'rc_order_id', 'domain_name', 'rc_customer_id', 'status', 'registered_at', 'expires_at',
        'auto_renew', 'privacy_enabled', 'registrar_lock', 'nameservers_json', 'content_hash',
        'first_seen_at', 'last_synced_at', 'last_seen_sync_id', 'is_missing',
    ];

    /** Columns the mobile list view needs. content_hash and last_seen_sync_id are internal. */
    private const LIST_COLUMNS = 'id, domain_name, status, expires_at, auto_renew, privacy_enabled, registrar_lock, is_missing, last_synced_at';

    private const DETAIL_COLUMNS = 'id, domain_name, rc_order_id, rc_customer_id, status, registered_at, expires_at, auto_renew, '
        . 'privacy_enabled, registrar_lock, nameservers_json, is_missing, first_seen_at, last_synced_at';

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function search(?string $q, int $page, int $perPage): array
    {
        $this->select(self::LIST_COLUMNS);

        if ($q !== null && $q !== '') {
            // like() does not escape the % and _ wildcards of its input in this
            // CodeIgniter version (its quoting step ignores them), so they are
            // escaped here with "!". With $escape = true, like() also appends
            // ESCAPE '!' (standard SQL, same on MySQL and MariaDB) and still
            // quotes the value itself.
            $this->like('domain_name', str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q), 'both', true);
        }

        $total = $this->countAllResults(false);

        // Rows without an expiry date sort last; the expression is plain SQL
        // understood by both MySQL and MariaDB. id makes the order total.
        $rows = $this->orderBy('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END', 'ASC', false)
            ->orderBy('expires_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll($perPage, ($page - 1) * $perPage);

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDetail(int $id): ?array
    {
        return $this->select(self::DETAIL_COLUMNS)->where('id', $id)->first();
    }

    /** Newest per-row sync time across the whole inventory (not search-filtered); null when empty. */
    public function latestSyncedAt(): ?string
    {
        $row = $this->builder()->selectMax('last_synced_at', 'latest')->get()->getRowArray();

        return $row['latest'] ?? null;
    }
}

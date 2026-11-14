<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

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

    /** Accepted `expiry` list filters; `all` applies no expiry condition. */
    public const EXPIRY_FILTERS = ['all', 'expired', 'within_7_days', 'within_30_days', 'within_60_days', 'within_90_days', 'unknown'];

    /** Accepted `sort` values; null/absent keeps the default (nearest expiry first). */
    public const SORTS = ['expiry_asc', 'expiry_desc', 'name_asc'];

    /**
     * Any expiry filter other than `all` excludes domains the provider no longer
     * lists (`is_missing = 1`), exactly like expirySummary(); `all` keeps them.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function search(?string $q, int $page, int $perPage, string $expiry = 'all', ?string $sort = null, ?DateTimeImmutable $now = null): array
    {
        $this->select(self::LIST_COLUMNS);

        if ($expiry !== 'all') {
            $this->where('is_missing', 0);

            foreach ($this->expiryClauses($expiry, $this->expiryBoundaries($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))) as [$condition, $value]) {
                $value === null ? $this->where($condition, null, false) : $this->where($condition, $value);
            }
        }

        if ($q !== null && $q !== '') {
            // like() does not escape the % and _ wildcards of its input in this
            // CodeIgniter version (its quoting step ignores them), so they are
            // escaped here with "!". With $escape = true, like() also appends
            // ESCAPE '!' (standard SQL, same on MySQL and MariaDB) and still
            // quotes the value itself.
            $this->like('domain_name', str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q), 'both', true);
        }

        $total = $this->countAllResults(false);

        if ($sort === 'name_asc') {
            $this->orderBy('domain_name', 'ASC');
        } else {
            // Rows without an expiry date sort last in both directions; the
            // expression is plain SQL understood by both MySQL and MariaDB.
            $this->orderBy('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END', 'ASC', false)
                ->orderBy('expires_at', $sort === 'expiry_desc' ? 'DESC' : 'ASC');
        }

        // id makes every order total (stable pagination).
        $rows = $this->orderBy('id', 'ASC')->findAll($perPage, ($page - 1) * $perPage);

        return ['rows' => $rows, 'total' => $total];
    }

    /** Business timezone of the expiry dashboard; stored instants stay UTC. */
    public const EXPIRY_TIMEZONE = 'Asia/Kolkata';

    /** Cumulative upcoming windows, in calendar days from today (inclusive). */
    public const EXPIRY_WINDOWS = [7, 30, 60, 90];

    /**
     * Calendar-day boundaries in UTC "Y-m-d H:i:s" for the stored DATETIME values.
     * `today` is the first instant of the current EXPIRY_TIMEZONE day; `windows[N]`
     * is the first instant AFTER the last day of the N-day window (exclusive end),
     * so a domain expiring any time on day +N is inside window N.
     *
     * @return array{date: string, today: string, windows: array<int, string>}
     */
    public function expiryBoundaries(DateTimeImmutable $now): array
    {
        $utc   = new DateTimeZone('UTC');
        $today = $now->setTimezone(new DateTimeZone(self::EXPIRY_TIMEZONE))->setTime(0, 0);
        $utcAt = static fn (DateTimeImmutable $t): string => $t->setTimezone($utc)->format('Y-m-d H:i:s');

        $windows = [];

        foreach (self::EXPIRY_WINDOWS as $days) {
            $windows[$days] = $utcAt($today->modify('+' . ($days + 1) . ' days'));
        }

        return ['date' => $today->format('Y-m-d'), 'today' => $utcAt($today), 'windows' => $windows];
    }

    /**
     * Cumulative expiry counts in one aggregate query over non-missing domains.
     * The windows overlap on purpose: a domain expiring in 5 days is in all of them.
     *
     * @return array{expired: int, within_7_days: int, within_30_days: int, within_60_days: int, within_90_days: int, unknown: int, as_of_date: string, timezone: string}
     */
    public function expirySummary(DateTimeImmutable $now): array
    {
        $b = $this->expiryBoundaries($now);

        $columns = [];
        $binds   = [];

        // The same clauses back the list filters, so counts and filtered lists agree.
        foreach (array_diff(self::EXPIRY_FILTERS, ['all']) as $filter) {
            $parts = [];

            foreach ($this->expiryClauses($filter, $b) as [$condition, $value]) {
                $parts[] = $value === null ? $condition : $condition . ' ?';

                if ($value !== null) {
                    $binds[] = $value;
                }
            }

            $columns[] = 'COALESCE(SUM(' . implode(' AND ', $parts) . "), 0) AS {$filter}_count";
        }

        $row = $this->db->query('SELECT ' . implode(', ', $columns) . ' FROM domains WHERE is_missing = 0', $binds)->getRowArray() ?? [];

        $out = ['expired' => (int) ($row['expired_count'] ?? 0)];

        foreach (self::EXPIRY_WINDOWS as $days) {
            $out["within_{$days}_days"] = (int) ($row["within_{$days}_days_count"] ?? 0);
        }

        $out['unknown']    = (int) ($row['unknown_count'] ?? 0);
        $out['as_of_date'] = $b['date'];
        $out['timezone']   = self::EXPIRY_TIMEZONE;

        return $out;
    }

    /**
     * The single definition of each expiry filter as AND-ed clauses over
     * `expires_at`: [condition, value] with a null value for "IS NULL". Values
     * come from expiryBoundaries() only, never from the request.
     *
     * @param array{date: string, today: string, windows: array<int, string>} $b
     *
     * @return list<array{0: string, 1: string|null}>
     */
    private function expiryClauses(string $filter, array $b): array
    {
        if ($filter === 'expired') {
            return [['expires_at <', $b['today']]];
        }

        if ($filter === 'unknown') {
            return [['expires_at IS NULL', null]];
        }

        if (preg_match('/^within_(\d+)_days$/', $filter, $m) === 1 && isset($b['windows'][(int) $m[1]])) {
            return [['expires_at >=', $b['today']], ['expires_at <', $b['windows'][(int) $m[1]]]];
        }

        throw new InvalidArgumentException('unknown expiry filter');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDetail(int $id): ?array
    {
        return $this->select(self::DETAIL_COLUMNS)->where('id', $id)->first();
    }

    /** Contact columns returned to the client; no database ids or provider ids. */
    public const CONTACT_FIELDS = [
        'name', 'company', 'email', 'tel_country_code', 'tel_number',
        'address1', 'address2', 'address3', 'city', 'state', 'country', 'postal_code',
    ];

    /**
     * Stored contacts of one domain, keyed by role. Roles without a link are
     * absent; a contact shared by several roles appears under each of them.
     *
     * @return array<string, array<string, mixed>>
     */
    public function findContactsByRole(int $domainId): array
    {
        $columns = implode(', ', array_map(static fn (string $f): string => 'c.' . $f, self::CONTACT_FIELDS));

        $rows = $this->db->table('domain_contacts dc')
            ->select('dc.role AS role, ' . $columns)
            ->join('rc_contacts c', 'c.id = dc.rc_contact_row_id', 'inner')
            ->where('dc.domain_id', $domainId)
            ->get()->getResultArray();

        $byRole = [];

        foreach ($rows as $row) {
            $role = $row['role'];
            unset($row['role']);
            $byRole[$role] = $row;
        }

        return $byRole;
    }

    /** UTC "Y-m-d H:i:s" of the last successful enrichment, or null. */
    public function findDetailsSyncedAt(int $domainId): ?string
    {
        $row = $this->builder()->select('details_synced_at')->where('id', $domainId)->get()->getRowArray();

        return $row['details_synced_at'] ?? null;
    }

    /** Newest per-row sync time across the whole inventory (not search-filtered); null when empty. */
    public function latestSyncedAt(): ?string
    {
        $row = $this->builder()->selectMax('last_synced_at', 'latest')->get()->getRowArray();

        return $row['latest'] ?? null;
    }
}

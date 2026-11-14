<?php

declare(strict_types=1);

namespace App\Libraries\Enrichment;

use App\Libraries\ResellerClub\ResellerClubClient;
use App\Libraries\ResellerClub\ResellerClubException;
use Closure;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * Read-only enrichment of ONE stored domain from ResellerClub's
 * domains/details.json: contacts (`rc_contacts`), the four role links
 * (`domain_contacts`), nameservers and `domains.details_synced_at`. Nothing is
 * ever written to ResellerClub.
 *
 * Decisions:
 * - The order id and customer id come only from the stored domain row, never
 *   from a caller. The provider is called BEFORE the database transaction.
 * - The provider's customerid (and orderid) must equal the stored values,
 *   otherwise nothing is written. A stored NULL customer id also stops the run
 *   (nothing to validate against). rc_customer_id is never changed.
 * - Only the approved contact fields are read from the response (by name); the
 *   response array is never stored, logged or returned, so domsecret, fax
 *   numbers, parentkey and the other provider metadata cannot leak.
 * - Contact field: key absent or null keeps the stored value; an empty (or
 *   whitespace-only) string becomes NULL; a value that is not a scalar, is not
 *   valid UTF-8 or is longer than 255 characters is unusable and keeps the
 *   stored value (a warning is recorded).
 * - Role: an empty or missing role id preserves the existing link (warning). A
 *   valid id with a usable contact object upserts the contact and links it. A
 *   valid id without a usable object links an already stored contact of this
 *   customer, otherwise the role is skipped and any existing link preserved.
 *   A contact object whose own contactid or customerid disagrees with the role
 *   id or the stored customer is treated as missing. Contacts are never deleted.
 * - Nameservers are replaced only when ns1 or ns2 is present and every supplied
 *   ns1..ns13 value is a valid host name; otherwise they are kept (warning).
 * - Everything is one transaction; details_synced_at is its last statement, so
 *   it only changes when every earlier write succeeded and the commit worked.
 * - Sync-owned columns of `domains` are never written.
 */
class DomainDetailsEnrichmentService
{
    private const FORMAT = 'Y-m-d H:i:s';

    private const MAX_NAMESERVERS = 13;

    private const FIELD_MAX_LENGTH = 255;

    /** Stored column => provider key. */
    private const CONTACT_FIELDS = [
        'name'             => 'name',
        'company'          => 'company',
        'email'            => 'emailaddr',
        'tel_country_code' => 'telnocc',
        'tel_number'       => 'telno',
        'address1'         => 'address1',
        'address2'         => 'address2',
        'address3'         => 'address3',
        'city'             => 'city',
        'state'            => 'state',
        'country'          => 'country',
        'postal_code'      => 'zip',
    ];

    /** Role => [provider id key, provider object key]. */
    private const ROLES = [
        'registrant' => ['registrantcontactid', 'registrantcontact'],
        'admin'      => ['admincontactid', 'admincontact'],
        'tech'       => ['techcontactid', 'techcontact'],
        'billing'    => ['billingcontactid', 'billingcontact'],
    ];

    /** @var Closure(): DateTimeImmutable */
    private Closure $clock;

    /**
     * @param (callable(): DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly ResellerClubClient $client,
        ?callable $clock = null,
    ) {
        $this->clock = Closure::fromCallable($clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    public function enrich(int $domainId): EnrichmentResult
    {
        if (! $this->client->isConfigured()) {
            return EnrichmentResult::failed(EnrichmentResult::NOT_CONFIGURED);
        }

        try {
            $domain = $this->db->table('domains')->select('id, rc_order_id, rc_customer_id')->where('id', $domainId)->get()->getRowArray();
        } catch (Throwable $e) {
            return $this->storageFailure('load', $e);
        }

        if ($domain === null) {
            return EnrichmentResult::failed(EnrichmentResult::NOT_FOUND);
        }

        $orderId    = (string) $domain['rc_order_id'];
        $customerId = $domain['rc_customer_id'] === null ? '' : (string) $domain['rc_customer_id'];

        if ($customerId === '') {
            log_message('warning', 'Enrichment skipped for domain {id}: no stored customer id', ['id' => $domainId]);

            return EnrichmentResult::failed(EnrichmentResult::CUSTOMER_UNKNOWN);
        }

        try {
            $details = $this->client->domainDetails($orderId);
        } catch (ResellerClubException $e) {
            log_message('warning', 'Enrichment provider call failed for domain {id}: {code}', ['id' => $domainId, 'code' => $e->getMessage()]);

            return EnrichmentResult::failed(EnrichmentResult::PROVIDER_ERROR, $e->getMessage());
        }

        if (! is_scalar($details['customerid'] ?? null) || (string) $details['customerid'] !== $customerId) {
            log_message('warning', 'Enrichment skipped for domain {id}: provider customer id does not match', ['id' => $domainId]);

            return EnrichmentResult::failed(EnrichmentResult::CUSTOMER_MISMATCH);
        }

        if ((string) $details['orderid'] !== $orderId) {
            log_message('warning', 'Enrichment skipped for domain {id}: provider order id does not match', ['id' => $domainId]);

            return EnrichmentResult::failed(EnrichmentResult::PROVIDER_ERROR, 'order_mismatch');
        }

        $warnings = [];
        $roles    = $this->planRoles($details, $customerId, $warnings);
        $ns       = $this->planNameservers($details, $warnings);

        foreach ($warnings as [$scope, $code]) {
            log_message('warning', 'Enrichment warning for domain {id}: {scope} {code}', ['id' => $domainId, 'scope' => $scope, 'code' => $code]);
        }

        return $this->store($domainId, $orderId, $customerId, $roles, $ns, count($warnings));
    }

    /**
     * @param array<string, mixed>              $details
     * @param list<array{0: string, 1: string}> $warnings
     *
     * @return array<string, array{id: string|null, fields: array<string, string|null>|null}>
     */
    private function planRoles(array $details, string $customerId, array &$warnings): array
    {
        $plan = [];

        foreach (self::ROLES as $role => [$idKey, $objKey]) {
            $id = $this->contactId($details[$idKey] ?? null);

            if ($id === null) {
                $warnings[]  = [$role, 'role_id_missing'];
                $plan[$role] = ['id' => null, 'fields' => null];

                continue;
            }

            $object = $details[$objKey] ?? null;
            $fields = null;

            if (! is_array($object)) {
                $warnings[] = [$role, 'contact_object_missing'];
            } elseif (isset($object['contactid']) && $this->contactId($object['contactid']) !== $id) {
                $warnings[] = [$role, 'contact_id_mismatch'];
            } elseif (isset($object['customerid']) && (! is_scalar($object['customerid']) || (string) $object['customerid'] !== $customerId)) {
                $warnings[] = [$role, 'contact_customer_mismatch'];
            } else {
                $fields = [];

                foreach (self::CONTACT_FIELDS as $column => $key) {
                    $value = $this->fieldValue($object, $key, $role, $warnings);

                    if ($value !== false) {
                        $fields[$column] = $value;
                    }
                }
            }

            $plan[$role] = ['id' => $id, 'fields' => $fields];
        }

        return $plan;
    }

    private function contactId(mixed $raw): ?string
    {
        if (is_int($raw)) {
            $raw = (string) $raw;
        }

        return is_string($raw) && preg_match('/^[0-9]{1,32}$/', $raw) === 1 ? $raw : null;
    }

    /**
     * false: keep the stored value. null: store NULL. string: store it.
     *
     * @param array<string, mixed>              $object
     * @param list<array{0: string, 1: string}> $warnings
     */
    private function fieldValue(array $object, string $key, string $role, array &$warnings): string|false|null
    {
        if (! array_key_exists($key, $object) || $object[$key] === null) {
            return false;
        }

        $value = $object[$key];

        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > self::FIELD_MAX_LENGTH) {
            $warnings[] = [$role, 'field_unusable'];

            return false;
        }

        return trim($value) === '' ? null : $value;
    }

    /**
     * @param array<string, mixed>              $details
     * @param list<array{0: string, 1: string}> $warnings
     *
     * @return list<string>|null null: leave the stored nameservers alone
     */
    private function planNameservers(array $details, array &$warnings): ?array
    {
        $present = static fn (string $k): bool => isset($details[$k]) && is_scalar($details[$k]);

        if (! $present('ns1') && ! $present('ns2')) {
            return null;
        }

        $list = [];

        for ($i = 1; $i <= self::MAX_NAMESERVERS; $i++) {
            if (! $present('ns' . $i)) {
                continue;
            }

            $host = strtolower(rtrim(trim((string) $details['ns' . $i]), '.'));

            if ($host === '') {
                continue;
            }

            if (strlen($host) > 253 || preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $host) !== 1) {
                $warnings[] = ['nameservers', 'nameserver_invalid'];

                return null;
            }

            $list[] = $host;
        }

        if ($list === []) {
            $warnings[] = ['nameservers', 'nameservers_empty'];

            return null;
        }

        return $list;
    }

    /**
     * @param array<string, array{id: string|null, fields: array<string, string|null>|null}> $roles
     * @param list<string>|null                                                                $nameservers
     */
    private function store(int $domainId, string $orderId, string $customerId, array $roles, ?array $nameservers, int $warnings): EnrichmentResult
    {
        $now = $this->now();
        $c   = ['created' => 0, 'updated' => 0, 'linked' => 0, 'changed' => 0, 'preserved' => 0];

        $this->db->transBegin();

        try {
            // Lock the row and make sure it is still the one that was validated.
            $locked = $this->db->query('SELECT rc_order_id, rc_customer_id FROM domains WHERE id = ? FOR UPDATE', [$domainId])->getRowArray();

            if ($locked === null || (string) $locked['rc_order_id'] !== $orderId || (string) ($locked['rc_customer_id'] ?? '') !== $customerId) {
                $this->db->transRollback();
                log_message('warning', 'Enrichment skipped for domain {id}: the stored domain changed', ['id' => $domainId]);

                return EnrichmentResult::failed(EnrichmentResult::CUSTOMER_MISMATCH);
            }

            $rowIds = []; // contact id => stored row id, one upsert per contact per run

            foreach ($roles as $role => $item) {
                if ($item['id'] === null) {
                    $c['preserved']++;

                    continue;
                }

                if (array_key_exists($item['id'], $rowIds)) {
                    $contactRowId = $rowIds[$item['id']];
                } elseif ($item['fields'] !== null) {
                    $contactRowId = $this->upsertContact($customerId, $item['id'], $item['fields'], $now, $c);
                } else {
                    $contactRowId = $this->findContact($customerId, $item['id']);
                }

                if ($contactRowId === null) {
                    $c['preserved']++;

                    continue;
                }

                $rowIds[$item['id']] = $contactRowId;
                $this->linkRole($domainId, $role, $contactRowId, $now, $c);
            }

            $update = ['details_synced_at' => $now, 'updated_at' => $now];

            if ($nameservers !== null) {
                $update['nameservers_json'] = json_encode($nameservers, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }

            $this->db->table('domains')->where('id', $domainId)->update($update);

            if ($this->db->transStatus() === false || $this->db->transCommit() === false) {
                throw new RuntimeException('transaction_failed');
            }
        } catch (Throwable $e) {
            $this->db->transRollback();

            return $this->storageFailure('store', $e);
        }

        return new EnrichmentResult(
            EnrichmentResult::SUCCESS,
            $c['created'],
            $c['updated'],
            $c['linked'],
            $c['changed'],
            $c['preserved'],
            $warnings,
            $nameservers !== null,
            $now,
        );
    }

    private function findContact(string $customerId, string $contactId): ?int
    {
        $row = $this->db->table('rc_contacts')->select('id')->where('rc_customer_id', $customerId)->where('rc_contact_id', $contactId)->get()->getRowArray();

        return $row === null ? null : (int) $row['id'];
    }

    /**
     * @param array<string, string|null> $fields
     * @param array<string, int>         $c
     */
    private function upsertContact(string $customerId, string $contactId, array $fields, string $now, array &$c): int
    {
        $existing = $this->findContact($customerId, $contactId);

        if ($existing !== null) {
            $this->db->table('rc_contacts')->where('id', $existing)->update($fields + ['last_synced_at' => $now, 'updated_at' => $now]);
            $c['updated']++;

            return $existing;
        }

        $row = array_fill_keys(array_keys(self::CONTACT_FIELDS), null);

        $this->db->table('rc_contacts')->insert($fields + $row + [
            'rc_customer_id' => $customerId,
            'rc_contact_id'  => $contactId,
            'first_seen_at'  => $now,
            'last_synced_at' => $now,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        $id = (int) $this->db->insertID();

        if ($id <= 0) {
            throw new RuntimeException('no_contact_id');
        }

        $c['created']++;

        return $id;
    }

    /**
     * @param array<string, int> $c
     */
    private function linkRole(int $domainId, string $role, int $contactRowId, string $now, array &$c): void
    {
        $link = $this->db->table('domain_contacts')->select('id, rc_contact_row_id')->where('domain_id', $domainId)->where('role', $role)->get()->getRowArray();

        if ($link === null) {
            $this->db->table('domain_contacts')->insert([
                'domain_id'         => $domainId,
                'rc_contact_row_id' => $contactRowId,
                'role'              => $role,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
            $c['linked']++;

            return;
        }

        if ((int) $link['rc_contact_row_id'] !== $contactRowId) {
            $this->db->table('domain_contacts')->where('id', (int) $link['id'])->update(['rc_contact_row_id' => $contactRowId, 'updated_at' => $now]);
            $c['changed']++;
        }
    }

    private function storageFailure(string $step, Throwable $e): EnrichmentResult
    {
        // Class name only: database messages can contain SQL and data.
        log_message('error', 'Enrichment {step} failed: {class}', ['step' => $step, 'class' => $e::class]);

        return EnrichmentResult::failed(EnrichmentResult::STORAGE_ERROR);
    }

    private function now(): string
    {
        return ($this->clock)()->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
    }
}

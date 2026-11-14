<?php

declare(strict_types=1);

namespace Tests\Support\Database;

use App\Database\Migrations\AddDetailsSyncedAtToDomains;
use App\Database\Migrations\CreateDomainContacts;
use App\Database\Migrations\CreateDomains;
use App\Database\Migrations\CreateRcContacts;
use App\Database\Migrations\CreateSyncRuns;
use Config\Database;
use Throwable;

/**
 * Gives a test class the `sync_runs`, `domains`, `rc_contacts` and
 * `domain_contacts` tables in the isolated test database, created once per class
 * and emptied before each test. Use after setUpIsolatedAuthDatabase(), which
 * verifies the connection is the isolated *_test database.
 *
 * Same rules as the other database tests: if any of the tables already exists the
 * test is SKIPPED (never touched), and only tables this class created are dropped.
 */
trait UsesEnrichmentSchema
{
    private static bool $enrichmentSchemaCreated = false;

    protected function setUpEnrichmentSchema(): void
    {
        if (! self::$enrichmentSchemaCreated) {
            foreach (['sync_runs', 'domains', 'rc_contacts', 'domain_contacts'] as $table) {
                if ($this->testDb->tableExists($table)) {
                    $this->markTestSkipped('Enrichment tests NOT executed: the test database already contains ' . $table . '; refusing to touch it.');
                }
            }

            require_once APPPATH . 'Database/Migrations/2026-10-27-120000_CreateSyncRuns.php';
            require_once APPPATH . 'Database/Migrations/2026-10-27-120100_CreateDomains.php';
            require_once APPPATH . 'Database/Migrations/2026-10-30-100000_CreateRcContacts.php';
            require_once APPPATH . 'Database/Migrations/2026-10-30-100100_CreateDomainContacts.php';
            require_once APPPATH . 'Database/Migrations/2026-10-30-100200_AddDetailsSyncedAtToDomains.php';

            $forge = Database::forge('tests');

            try {
                (new CreateSyncRuns($forge))->up();
                (new CreateDomains($forge))->up();
                (new AddDetailsSyncedAtToDomains($forge))->up();
                (new CreateRcContacts($forge))->up();
                (new CreateDomainContacts($forge))->up();
            } catch (Throwable $e) {
                self::dropEnrichmentSchema($forge);

                throw $e;
            }

            self::$enrichmentSchemaCreated = true;
        }

        // Only reached for tables this class created (guard above).
        $this->testDb->query('DELETE FROM domain_contacts');
        $this->testDb->query('DELETE FROM rc_contacts');
        $this->testDb->query('DELETE FROM domains');
        $this->testDb->query('DELETE FROM sync_runs');
    }

    protected static function tearDownEnrichmentSchema(): void
    {
        if (self::$enrichmentSchemaCreated) {
            self::dropEnrichmentSchema(Database::forge('tests'));
            self::$enrichmentSchemaCreated = false;
        }
    }

    private static function dropEnrichmentSchema(\CodeIgniter\Database\Forge $forge): void
    {
        $forge->dropTable('domain_contacts', true);
        $forge->dropTable('rc_contacts', true);
        $forge->dropTable('domains', true);
        $forge->dropTable('sync_runs', true);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function insertEnrichableDomain(array $overrides = []): int
    {
        $this->testDb->table('domains')->insert($overrides + [
            'rc_order_id'    => '900001',
            'domain_name'    => 'enrich-me.test',
            'rc_customer_id' => '555001',
            'status'         => 'Active',
            'first_seen_at'  => '2026-10-01 00:00:00',
            'last_synced_at' => '2026-10-20 08:00:00',
            'created_at'     => '2026-10-01 00:00:00',
            'updated_at'     => '2026-10-01 00:00:00',
        ]);

        return (int) $this->testDb->insertID();
    }

    /**
     * Synthetic provider response. Every personal value is fake.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected function providerDetails(array $overrides = []): array
    {
        $contact = static fn (string $id, string $label): array => [
            'contactid'  => $id,
            'customerid' => '555001',
            'name'       => 'Test ' . $label,
            'company'    => 'Example ' . $label . ' Ltd',
            'emailaddr'  => $label . '@example.test',
            'telnocc'    => '99',
            'telno'      => '5550100' . $id,
            'faxnocc'    => '99',
            'faxno'      => '5559999',
            'address1'   => '1 Test Street',
            'address2'   => 'Unit ' . $label,
            'address3'   => '',
            'city'       => 'Testville',
            'state'      => 'Test State',
            'country'    => 'ZZ',
            'zip'        => '00000',
            'type'       => 'Contact',
            'contactstatus' => 'Active',
            'parentkey'  => 'SENTINEL-PARENTKEY',
        ];

        $base = [
            'orderid'             => '900001',
            'domainname'          => 'enrich-me.test',
            'customerid'          => '555001',
            'currentstatus'       => 'Active',
            'domsecret'           => 'SENTINEL-DOMSECRET-9f3a',
            'ns1'                 => 'ns1.example.test',
            'ns2'                 => 'NS2.Example.Test.',
            'registrantcontactid' => '7001',
            'admincontactid'      => '7002',
            'techcontactid'       => '7003',
            'billingcontactid'    => '7004',
            'registrantcontact'   => $contact('7001', 'registrant'),
            'admincontact'        => $contact('7002', 'admin'),
            'techcontact'         => $contact('7003', 'tech'),
            'billingcontact'      => $contact('7004', 'billing'),
        ];

        return array_replace($base, $overrides);
    }
}

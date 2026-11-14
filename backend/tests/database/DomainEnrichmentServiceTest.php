<?php

declare(strict_types=1);

use App\Libraries\Enrichment\DomainDetailsEnrichmentService;
use App\Libraries\Enrichment\EnrichmentResult;
use App\Libraries\ResellerClub\ResellerClubClient;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Database\MySQLi\Connection as MySQLiConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\ResellerClub as ResellerClubConfig;
use Tests\Support\Libraries\IsolatedResellerClubConfig;
use Tests\Support\Database\UsesEnrichmentSchema;
use Tests\Support\Database\UsesIsolatedAuthDatabase;

/**
 * DomainDetailsEnrichmentService against a fake provider transport and the
 * isolated test database. No network access; every personal value is synthetic.
 *
 * @internal
 */
final class DomainEnrichmentServiceTest extends CIUnitTestCase
{
    use UsesIsolatedAuthDatabase {
        tearDownAfterClass as private tearDownAuthSchema;
    }
    use UsesEnrichmentSchema;

    private const NOW = '2026-10-31 09:00:00';

    /** @var list<array{status: int, body: string}|Throwable> */
    private array $script = [];

    /** @var list<array{0: string, 1: array<string, scalar>}> */
    private array $calls = [];

    private int $domainId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIsolatedAuthDatabase();
        $this->setUpEnrichmentSchema();

        $this->script   = [];
        $this->calls    = [];
        $this->domainId = $this->insertEnrichableDomain();
    }

    public static function tearDownAfterClass(): void
    {
        self::tearDownEnrichmentSchema();
        self::tearDownAuthSchema();
    }

    // ---- helpers -----------------------------------------------------------

    private function service(?BaseConnection $db = null): DomainDetailsEnrichmentService
    {
        $config              = IsolatedResellerClubConfig::make();
        $config->baseUrl     = 'https://test.httpapi.example/api';
        $config->authUserId  = '123456';
        $config->apiKey      = 'sentinel-api-key-never-store';
        $config->maxAttempts = 1;

        $client = new ResellerClubClient($config, function (string $url, array $query): array {
            $this->calls[] = [$url, $query];
            $next          = array_shift($this->script);

            if ($next === null) {
                throw new RuntimeException('unexpected provider call');
            }

            if ($next instanceof Throwable) {
                throw $next;
            }

            return $next;
        }, static function (int $ms): void {
        });

        return new DomainDetailsEnrichmentService(
            $db ?? $this->testDb,
            $client,
            static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')),
        );
    }

    /**
     * @param array<string, mixed> $details
     */
    private function enrich(array $details, ?BaseConnection $db = null): EnrichmentResult
    {
        $this->script[] = ['status' => 200, 'body' => (string) json_encode($details)];

        return $this->service($db)->enrich($this->domainId);
    }

    /**
     * @return array<string, mixed>
     */
    private function domainRow(): array
    {
        return $this->testDb->table('domains')->where('id', $this->domainId)->get()->getRowArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function contact(string $contactId): array
    {
        return $this->testDb->table('rc_contacts')->where('rc_customer_id', '555001')->where('rc_contact_id', $contactId)->get()->getRowArray() ?? [];
    }

    /**
     * Role => provider contact id of the current links.
     *
     * @return array<string, string>
     */
    private function roleMap(): array
    {
        $rows = $this->testDb->query(
            'SELECT dc.role, c.rc_contact_id FROM domain_contacts dc JOIN rc_contacts c ON c.id = dc.rc_contact_row_id WHERE dc.domain_id = ? ORDER BY dc.role',
            [$this->domainId],
        )->getResultArray();

        return array_column($rows, 'rc_contact_id', 'role');
    }

    private function rows(string $table): int
    {
        return $this->testDb->table($table)->countAllResults();
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return ['rc' => $this->rows('rc_contacts'), 'dc' => $this->rows('domain_contacts')];
    }

    private function allData(): string
    {
        return (string) json_encode([
            $this->testDb->table('domains')->get()->getResultArray(),
            $this->testDb->table('rc_contacts')->get()->getResultArray(),
            $this->testDb->table('domain_contacts')->get()->getResultArray(),
            $this->testDb->table('sync_runs')->get()->getResultArray(),
        ]);
    }

    private function logs(): string
    {
        $logs = '';

        foreach (glob(WRITEPATH . 'logs/*.log') ?: [] as $file) {
            $logs .= (string) file_get_contents($file);
        }

        return $logs;
    }

    // ---- request -----------------------------------------------------------

    public function testRequestUsesStoredIdsAndTheApprovedParameters(): void
    {
        $this->enrich($this->providerDetails());

        $this->assertCount(1, $this->calls);
        [$url, $query] = $this->calls[0];

        $this->assertSame('https://test.httpapi.example/api/domains/details.json', $url);
        $this->assertSame(
            ['auth-userid' => '123456', 'api-key' => 'sentinel-api-key-never-store', 'order-id' => '900001', 'options' => 'All'],
            $query,
        );
    }

    public function testUnknownDomainDoesNotCallTheProvider(): void
    {
        $result = $this->service()->enrich($this->domainId + 1000);

        $this->assertSame(EnrichmentResult::NOT_FOUND, $result->status);
        $this->assertSame([], $this->calls);
    }

    public function testStoredNullCustomerStopsBeforeTheProviderCall(): void
    {
        $this->testDb->table('domains')->where('id', $this->domainId)->update(['rc_customer_id' => null]);

        $result = $this->service()->enrich($this->domainId);

        $this->assertSame(EnrichmentResult::CUSTOMER_UNKNOWN, $result->status);
        $this->assertSame([], $this->calls);
        $this->assertSame(['rc' => 0, 'dc' => 0], $this->counts());
    }

    // ---- contacts and roles ------------------------------------------------

    public function testSuccessfulEnrichmentStoresMappedContactsRolesNameserversAndTimestamp(): void
    {
        $result = $this->enrich($this->providerDetails());

        $this->assertTrue($result->succeeded());
        $this->assertSame(4, $result->contactsCreated);
        $this->assertSame(0, $result->contactsUpdated);
        $this->assertSame(4, $result->rolesLinked);
        $this->assertSame(0, $result->warnings);
        $this->assertTrue($result->nameserversUpdated);

        $registrant = $this->contact('7001');
        $this->assertSame('Test registrant', $registrant['name']);
        $this->assertSame('Example registrant Ltd', $registrant['company']);
        $this->assertSame('registrant@example.test', $registrant['email']);
        $this->assertSame('99', $registrant['tel_country_code']);
        $this->assertSame('55501007001', $registrant['tel_number']);
        $this->assertSame('1 Test Street', $registrant['address1']);
        $this->assertSame('Unit registrant', $registrant['address2']);
        $this->assertNull($registrant['address3'], 'An explicit empty string becomes NULL.');
        $this->assertSame('Testville', $registrant['city']);
        $this->assertSame('Test State', $registrant['state']);
        $this->assertSame('ZZ', $registrant['country']);
        $this->assertSame('00000', $registrant['postal_code']);
        $this->assertSame(self::NOW, $registrant['first_seen_at']);
        $this->assertSame(self::NOW, $registrant['last_synced_at']);

        $domain = $this->domainRow();
        $this->assertSame(self::NOW, $domain['details_synced_at']);
        $this->assertSame(['ns1.example.test', 'ns2.example.test'], json_decode((string) $domain['nameservers_json'], true));
    }

    public function testAllFourRolesAreMapped(): void
    {
        $this->enrich($this->providerDetails());

        $this->assertSame(['admin' => '7002', 'billing' => '7004', 'registrant' => '7001', 'tech' => '7003'], $this->roleMap());
    }

    public function testContactsAreDeduplicatedByCustomerAndContactId(): void
    {
        $this->enrich($this->providerDetails());
        $firstSeen = $this->contact('7001')['first_seen_at'];

        $this->testDb->table('rc_contacts')->update(['first_seen_at' => '2026-01-01 00:00:00']);

        $second = $this->enrich($this->providerDetails());

        $this->assertSame(['rc' => 4, 'dc' => 4], $this->counts());
        $this->assertSame(0, $second->contactsCreated);
        $this->assertSame(4, $second->contactsUpdated);
        $this->assertSame(0, $second->rolesLinked);
        $this->assertNotSame($firstSeen, $this->contact('7001')['first_seen_at'], 'first_seen_at is left alone on update.');
        $this->assertSame('2026-01-01 00:00:00', $this->contact('7001')['first_seen_at']);
    }

    public function testSameContactIdIsStoredOnceAndSharedAcrossRoles(): void
    {
        $details = $this->providerDetails([
            'admincontactid'   => '7001',
            'techcontactid'    => '7001',
            'billingcontactid' => '7004',
        ]);
        $details['admincontact']['contactid'] = '7001';
        $details['techcontact']['contactid']  = '7001';

        $result = $this->enrich($details);

        $this->assertTrue($result->succeeded());
        $this->assertSame(2, $result->contactsCreated);
        $this->assertSame(4, $result->rolesLinked);
        $this->assertSame(['rc' => 2, 'dc' => 4], $this->counts());
        $this->assertSame(['admin' => '7001', 'billing' => '7004', 'registrant' => '7001', 'tech' => '7001'], $this->roleMap());
    }

    public function testChangedRoleIdUpdatesTheLinkAndKeepsTheOldContact(): void
    {
        $this->enrich($this->providerDetails());

        $details                          = $this->providerDetails(['techcontactid' => '7099']);
        $details['techcontact']['contactid'] = '7099';
        $details['techcontact']['name']      = 'Replacement Tech';

        $result = $this->enrich($details);

        $this->assertSame(1, $result->rolesChanged);
        $this->assertSame(1, $result->contactsCreated);
        $this->assertSame('7099', $this->roleMap()['tech']);
        $this->assertSame(5, $this->rows('rc_contacts'), 'The previous contact is never deleted.');
        $this->assertNotEmpty($this->contact('7003'));
        $this->assertSame(4, $this->rows('domain_contacts'));
    }

    public function testMissingContactFieldKeepsTheStoredValueAndEmptyStringBecomesNull(): void
    {
        $this->enrich($this->providerDetails());

        $details = $this->providerDetails();
        unset($details['registrantcontact']['city'], $details['registrantcontact']['state']);
        $details['registrantcontact']['company']   = '';
        $details['registrantcontact']['telnocc']   = null;
        $details['registrantcontact']['address1']  = '2 New Street';

        $this->enrich($details);

        $registrant = $this->contact('7001');
        $this->assertSame('Testville', $registrant['city']);
        $this->assertSame('Test State', $registrant['state']);
        $this->assertSame('99', $registrant['tel_country_code'], 'A JSON null counts as missing.');
        $this->assertNull($registrant['company']);
        $this->assertSame('2 New Street', $registrant['address1']);
    }

    public function testUnusableFieldValuesKeepTheStoredValueAndWarn(): void
    {
        $this->enrich($this->providerDetails());

        $details                              = $this->providerDetails();
        $details['registrantcontact']['name'] = str_repeat('x', 256);
        $details['registrantcontact']['city'] = ['not', 'scalar'];

        $result = $this->enrich($details);

        $this->assertTrue($result->succeeded());
        $this->assertSame(2, $result->warnings);
        $this->assertSame('Test registrant', $this->contact('7001')['name']);
        $this->assertSame('Testville', $this->contact('7001')['city']);
    }

    public function testMissingRoleObjectAndIdPreserveTheExistingLinkAndWarn(): void
    {
        $this->enrich($this->providerDetails());
        $before = $this->roleMap();

        $details = $this->providerDetails();
        unset($details['admincontact'], $details['admincontactid']);

        $result = $this->enrich($details);

        $this->assertTrue($result->succeeded());
        $this->assertSame($before, $this->roleMap());
        $this->assertSame(1, $result->rolesPreserved);
        $this->assertGreaterThanOrEqual(1, $result->warnings);
    }

    public function testEmptyRoleIdPreservesTheExistingLinkEvenWhenTheObjectIsPresent(): void
    {
        $this->enrich($this->providerDetails());
        $before = $this->roleMap();

        foreach ([['billingcontactid' => ''], ['billingcontactid' => null]] as $override) {
            $result = $this->enrich($this->providerDetails($override));

            $this->assertTrue($result->succeeded());
            $this->assertSame($before, $this->roleMap());
            $this->assertGreaterThanOrEqual(1, $result->warnings);
        }

        $missing = $this->providerDetails();
        unset($missing['billingcontactid']);
        $this->enrich($missing);
        $this->assertSame($before, $this->roleMap());
    }

    public function testExistingContactIsLinkedWhenTheRoleObjectIsAbsent(): void
    {
        $this->enrich($this->providerDetails());
        $this->testDb->table('domain_contacts')->where('role', 'tech')->delete();
        $this->assertArrayNotHasKey('tech', $this->roleMap());

        $details = $this->providerDetails();
        unset($details['techcontact']);

        $result = $this->enrich($details);

        $this->assertSame(1, $result->rolesLinked);
        $this->assertSame(0, $result->contactsCreated);
        $this->assertSame('7003', $this->roleMap()['tech']);
        $this->assertSame(4, $this->rows('rc_contacts'));
    }

    public function testUnknownContactWithoutAnObjectIsSkipped(): void
    {
        $details = $this->providerDetails(['techcontactid' => '8999']);
        unset($details['techcontact']);

        $result = $this->enrich($details);

        $this->assertTrue($result->succeeded());
        $this->assertSame(1, $result->rolesPreserved);
        $this->assertSame(3, $result->rolesLinked);
        $this->assertArrayNotHasKey('tech', $this->roleMap());
        $this->assertSame([], $this->contact('8999'));
        $this->assertSame(3, $this->rows('rc_contacts'));
    }

    public function testContactObjectThatDisagreesWithTheRoleIdOrCustomerIsNotStored(): void
    {
        $details                                = $this->providerDetails();
        $details['admincontact']['contactid']   = '9999';
        $details['techcontact']['customerid']   = '777777';

        $result = $this->enrich($details);

        $this->assertTrue($result->succeeded());
        $this->assertSame(2, $result->warnings);
        $this->assertSame(['billing' => '7004', 'registrant' => '7001'], $this->roleMap());
        $this->assertSame([], $this->contact('9999'));
        $this->assertSame([], $this->contact('7002'));
        $this->assertSame([], $this->contact('7003'));
    }

    // ---- customer validation -----------------------------------------------

    public function testCustomerMismatchChangesNothing(): void
    {
        $this->enrich($this->providerDetails());
        $snapshot = $this->allData();

        $details                = $this->providerDetails(['customerid' => '555999', 'ns1' => 'other.example.test']);
        $details['admincontact']['name'] = 'Changed';
        $details['techcontactid'] = '7099';

        $result = $this->enrich($details);

        $this->assertSame(EnrichmentResult::CUSTOMER_MISMATCH, $result->status);
        $this->assertSame($snapshot, $this->allData());
        $this->assertSame('555001', $this->domainRow()['rc_customer_id']);
    }

    public function testMissingProviderCustomerIdChangesNothing(): void
    {
        $details = $this->providerDetails();
        unset($details['customerid']);

        $result = $this->enrich($details);

        $this->assertSame(EnrichmentResult::CUSTOMER_MISMATCH, $result->status);
        $this->assertSame(['rc' => 0, 'dc' => 0], $this->counts());
        $this->assertNull($this->domainRow()['details_synced_at']);
    }

    public function testProviderOrderMismatchChangesNothing(): void
    {
        $result = $this->enrich($this->providerDetails(['orderid' => '123']));

        $this->assertSame(EnrichmentResult::PROVIDER_ERROR, $result->status);
        $this->assertSame(['rc' => 0, 'dc' => 0], $this->counts());
        $this->assertNull($this->domainRow()['details_synced_at']);
    }

    // ---- nameservers -------------------------------------------------------

    public function testNameserversAreReplacedWhenSupplied(): void
    {
        $this->testDb->table('domains')->where('id', $this->domainId)->update(['nameservers_json' => '["old.example.test"]']);

        $result = $this->enrich($this->providerDetails(['ns1' => 'a.example.test', 'ns2' => 'b.example.test', 'ns3' => 'c.example.test']));

        $this->assertTrue($result->nameserversUpdated);
        $this->assertSame(['a.example.test', 'b.example.test', 'c.example.test'], json_decode((string) $this->domainRow()['nameservers_json'], true));
    }

    public function testNameserversAreRetainedWhenBothAreAbsent(): void
    {
        $this->testDb->table('domains')->where('id', $this->domainId)->update(['nameservers_json' => '["old.example.test"]']);

        $details = $this->providerDetails();
        unset($details['ns1'], $details['ns2']);

        $result = $this->enrich($details);

        $this->assertTrue($result->succeeded());
        $this->assertFalse($result->nameserversUpdated);
        $this->assertSame('["old.example.test"]', $this->domainRow()['nameservers_json']);
        $this->assertSame(self::NOW, $this->domainRow()['details_synced_at']);
    }

    public function testOneSuppliedNameserverIsEnoughToReplace(): void
    {
        $this->testDb->table('domains')->where('id', $this->domainId)->update(['nameservers_json' => '["old.example.test"]']);

        $details = $this->providerDetails();
        unset($details['ns2']);

        $this->enrich($details);

        $this->assertSame(['ns1.example.test'], json_decode((string) $this->domainRow()['nameservers_json'], true));
    }

    public function testInvalidNameserverKeepsTheStoredList(): void
    {
        $this->testDb->table('domains')->where('id', $this->domainId)->update(['nameservers_json' => '["old.example.test"]']);

        $result = $this->enrich($this->providerDetails(['ns2' => 'not a host name']));

        $this->assertTrue($result->succeeded());
        $this->assertFalse($result->nameserversUpdated);
        $this->assertSame('["old.example.test"]', $this->domainRow()['nameservers_json']);
        $this->assertGreaterThanOrEqual(1, $result->warnings);
    }

    // ---- transaction and timestamp ----------------------------------------

    public function testDatabaseFailureRollsBackEverything(): void
    {
        $this->testDb->table('domains')->where('id', $this->domainId)->update(['nameservers_json' => '["old.example.test"]']);
        $before = $this->allData();

        $faulty         = new EnrichFaultyConnection((array) config('Database')->tests);
        $faulty->faults = ['/^INSERT INTO `domain_contacts`/' => null];

        try {
            $result = $this->enrich($this->providerDetails(), $faulty);
        } finally {
            $faulty->close();
        }

        $this->assertSame(EnrichmentResult::STORAGE_ERROR, $result->status);
        $this->assertSame(['rc' => 0, 'dc' => 0], $this->counts(), 'Contacts inserted before the failure must be rolled back.');
        $this->assertSame($before, $this->allData());
        $this->assertNull($this->domainRow()['details_synced_at']);
        $this->assertSame('["old.example.test"]', $this->domainRow()['nameservers_json']);
        $this->assertStringNotContainsString('injected fault', $this->logs());
    }

    public function testFailureOnTheFinalDomainUpdateRollsBackContactsAndLinks(): void
    {
        $faulty         = new EnrichFaultyConnection((array) config('Database')->tests);
        $faulty->faults = ['/^UPDATE `domains`/' => null];

        try {
            $result = $this->enrich($this->providerDetails(), $faulty);
        } finally {
            $faulty->close();
        }

        $this->assertSame(EnrichmentResult::STORAGE_ERROR, $result->status);
        $this->assertSame(['rc' => 0, 'dc' => 0], $this->counts());
        $this->assertNull($this->domainRow()['details_synced_at']);
    }

    public function testProviderFailuresLeaveTheDatabaseAndTimestampUntouched(): void
    {
        $snapshot = $this->allData();

        foreach ([
            ['status' => 503, 'body' => 'down'],
            ['status' => 200, 'body' => '{"status":"ERROR","message":"SENTINEL-PROVIDER-TEXT"}'],
            ['status' => 200, 'body' => 'not json'],
            ['status' => 200, 'body' => '{"customerid":"555001"}'],
        ] as $response) {
            $this->script[] = $response;
            $result         = $this->service()->enrich($this->domainId);

            $this->assertSame(EnrichmentResult::PROVIDER_ERROR, $result->status);
            $this->assertSame($snapshot, $this->allData());
        }

        $this->assertStringNotContainsString('SENTINEL-PROVIDER-TEXT', $this->logs());
    }

    public function testDetailsSyncedAtIsSetOnlyBySuccessfulRuns(): void
    {
        $this->assertNull($this->domainRow()['details_synced_at']);

        $this->enrich($this->providerDetails(['customerid' => '1']));
        $this->assertNull($this->domainRow()['details_synced_at']);

        $this->enrich($this->providerDetails());
        $this->assertSame(self::NOW, $this->domainRow()['details_synced_at']);
    }

    // ---- ownership and secrets ---------------------------------------------

    public function testSyncOwnedColumnsAreNeverChanged(): void
    {
        $this->testDb->query("INSERT INTO sync_runs (trigger_type, status, started_at, created_at, updated_at) VALUES ('manual', 'success', '2026-10-20 08:00:00', '2026-10-20 08:00:00', '2026-10-20 08:00:00')");
        $runId = (int) $this->testDb->insertID();

        $this->testDb->table('domains')->where('id', $this->domainId)->update([
            'content_hash'      => str_repeat('a', 64),
            'last_seen_sync_id' => $runId,
            'is_missing'        => 1,
            'registered_at'     => '2020-01-01 00:00:00',
            'expires_at'        => '2030-01-01 00:00:00',
            'auto_renew'        => 1,
            'privacy_enabled'   => 0,
        ]);

        $columns = ['rc_order_id', 'domain_name', 'rc_customer_id', 'status', 'registered_at', 'expires_at', 'auto_renew', 'privacy_enabled', 'registrar_lock', 'content_hash', 'first_seen_at', 'last_synced_at', 'last_seen_sync_id', 'is_missing', 'created_at'];
        $pick    = fn (array $row): array => array_intersect_key($row, array_flip($columns));
        $before  = $pick($this->domainRow());

        $details = $this->providerDetails([
            'domainname'    => 'different.example.test',
            'currentstatus' => 'Suspended',
            'creationtime'  => '1',
            'endtime'       => '2',
        ]);

        $this->assertTrue($this->enrich($details)->succeeded());
        $this->assertSame($before, $pick($this->domainRow()));
    }

    public function testDomsecretAndOtherUnapprovedDataAreNeverStoredLoggedOrReturned(): void
    {
        $details = $this->providerDetails(['ns1' => 'ns1.example.test']);
        unset($details['admincontact']);

        $result = $this->enrich($details);
        $this->enrich($this->providerDetails(['customerid' => '1']));

        $stored  = $this->allData();
        $logs    = $this->logs();
        $returned = (string) json_encode($result);

        foreach (['SENTINEL-DOMSECRET-9f3a', 'SENTINEL-PARENTKEY', '5559999', 'sentinel-api-key-never-store'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
            $this->assertStringNotContainsString($secret, $logs);
            $this->assertStringNotContainsString($secret, $returned);
        }

        foreach (['example.test', 'Testville', '1 Test Street'] as $personal) {
            $this->assertStringNotContainsString($personal, $returned . $this->capturedLogLines($logs));
        }
    }

    private function capturedLogLines(string $logs): string
    {
        // Personal data may appear in the database, never in a log line about enrichment.
        return implode("\n", array_filter(explode("\n", $logs), static fn (string $l): bool => str_contains($l, 'Enrichment')));
    }
}

/**
 * Test-only MySQLi connection that throws for statements matching a regex, to
 * exercise database failure handling.
 */
final class EnrichFaultyConnection extends MySQLiConnection
{
    /** @var array<string, string|null> regex => null (throw) or replacement SQL */
    public array $faults = [];

    protected function execute(string $sql)
    {
        foreach ($this->faults as $pattern => $replacement) {
            if (preg_match($pattern, $sql) === 1) {
                if ($replacement === null) {
                    throw new DatabaseException('injected fault: ' . $sql);
                }

                $sql = $replacement;
            }
        }

        return parent::execute($sql);
    }
}

// CodeIgniter derives the Result/Builder class names by replacing "Connection" in the class name.
class_alias(\CodeIgniter\Database\MySQLi\Result::class, 'EnrichFaultyResult');
class_alias(\CodeIgniter\Database\MySQLi\Builder::class, 'EnrichFaultyBuilder');

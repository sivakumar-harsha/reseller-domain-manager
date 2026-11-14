<?php

declare(strict_types=1);

use App\Libraries\Renewal\RenewalEligibility;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class RenewalEligibilityTest extends CIUnitTestCase
{
    private const NOW    = 1794000000;
    private const EXPIRY = 1794000000 - 5 * 86400;

    public function testNotExpiredIsEligible(): void
    {
        $r = RenewalEligibility::evaluate('Active', self::NOW + 86400, self::NOW);

        $this->assertTrue($r['eligible']);
        $this->assertSame('pre_expiry', $r['state']);
        $this->assertFalse($r['expired']);
        $this->assertNull($r['reason']);
    }

    public function testTheExactExpiryInstantIsNotYetExpired(): void
    {
        $r = RenewalEligibility::evaluate('Active', self::NOW, self::NOW);

        $this->assertSame([true, 'pre_expiry', false], [$r['eligible'], $r['state'], $r['expired']]);
    }

    public function testAnExpiredActiveDomainIsEligibleAndTheProviderDecides(): void
    {
        $r = RenewalEligibility::evaluate('Active', self::EXPIRY, self::NOW);

        $this->assertTrue($r['eligible']);
        $this->assertSame('expired', $r['state']);
        $this->assertTrue($r['expired']);
        $this->assertNull($r['reason']);
        $this->assertSame(5, $r['days_since_expiry']);
    }

    public function testHowLongAgoItExpiredDoesNotMatter(): void
    {
        foreach ([1, 36, 40, 400, 5000] as $days) {
            $r = RenewalEligibility::evaluate('Active', self::NOW - $days * 86400, self::NOW);

            $this->assertTrue($r['eligible'], "{$days} days");
            $this->assertSame('expired', $r['state'], "{$days} days");
        }
    }

    public function testThereIsNoLocalGracePeriodTableOrGraceState(): void
    {
        $this->assertFalse(defined(RenewalEligibility::class . '::GRACE_POLICY'));
        $this->assertFalse(defined(RenewalEligibility::class . '::GRACE_UNVERIFIED'));
        $this->assertFalse(defined(RenewalEligibility::class . '::BEYOND_GRACE'));
        $this->assertCount(7, RenewalEligibility::evaluate('Active', self::EXPIRY, self::NOW), 'No grace_* keys in the verdict.');
    }

    /** @return array<string, array{0: string|null, 1: string}> */
    public static function nonActiveStatuses(): array
    {
        return [
            'suspended'                 => ['Suspended', 'suspended'],
            'locked'                    => ['Locked', 'locked'],
            'deleted'                   => ['Deleted', 'deleted'],
            'redemption'                => ['RedemptionPeriod', 'redemption'],
            'pending delete restorable' => ['Pending Delete Restorable', 'deleted'],
            'restoration'               => ['Restoration', 'restoration'],
            'pending'                   => ['Pending', 'pending'],
            'inactive'                  => ['InActive', 'not_active'],
            'lowercase active'          => ['active', 'not_active'],
            'padded active'             => [' Active ', 'not_active'],
            'empty'                     => ['', 'unknown'],
            'null'                      => [null, 'unknown'],
        ];
    }

    /**
     * @dataProvider nonActiveStatuses
     */
    public function testAnyStatusOtherThanExactlyActiveIsRefusedExpiredOrNot(?string $status, string $class): void
    {
        foreach ([self::NOW + 86400, self::EXPIRY] as $expiry) {
            $r = RenewalEligibility::evaluate($status, $expiry, self::NOW);

            $this->assertFalse($r['eligible']);
            $this->assertSame('domain_not_active', $r['reason']);
            $this->assertSame($class, $r['status_class']);
        }
    }

    public function testUnknownExpiryIsRefused(): void
    {
        $r = RenewalEligibility::evaluate('Active', null, self::NOW);

        $this->assertSame([false, 'expiry_unknown', 'expiry_unknown', null], [$r['eligible'], $r['state'], $r['reason'], $r['expires_at']]);
    }
}

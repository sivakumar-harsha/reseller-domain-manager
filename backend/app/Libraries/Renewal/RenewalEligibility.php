<?php

declare(strict_types=1);

namespace App\Libraries\Renewal;

/**
 * The single renewal-eligibility rule used by the quote, batch creation and
 * execution, so the three can never disagree. Pure: no database, no provider
 * call, no clock of its own (the caller passes "now").
 *
 * A domain is eligible when BOTH hold:
 *
 * 1. Its status is exactly "Active". Suspended, locked, deleted, redemption,
 *    pending-delete and restorable states, an empty status and an unknown
 *    status are never eligible here (a fail-closed local safety check).
 * 2. Its expiry is known.
 *
 * An expiry date in the past is NOT a reason to refuse. Like the ResellerClub
 * website, the application lets an expired domain proceed and lets
 * ResellerClub decide whether the renewal is accepted. There is no local
 * grace-period table: the application does not know, and does not claim to
 * know, how long any TLD can still be renewed after it expires. The `expired`
 * flag and the `expired` state are informational, so the app can tell the user
 * that the provider will make the final decision.
 */
final class RenewalEligibility
{
    public const PRE_EXPIRY     = 'pre_expiry';
    public const EXPIRED        = 'expired';
    public const STATUS_BLOCKED = 'domain_not_active';
    public const EXPIRY_UNKNOWN = 'expiry_unknown';

    /** The only provider/local status that can be renewed (exact match). */
    public const ACTIVE_STATUS = 'Active';

    private const DAY = 86400;

    /** Statuses that are reported with a more specific class (diagnostic only; all are ineligible). */
    private const STATUS_CLASSES = [
        'suspend'    => 'suspended',
        'lock'       => 'locked',
        'delet'      => 'deleted',
        'redemption' => 'redemption',
        'restor'     => 'restoration',
        'pending'    => 'pending',
    ];

    /**
     * @return array{
     *   eligible: bool, state: string, reason: string|null, expired: bool,
     *   status_class: string|null, expires_at: string|null, days_since_expiry: int|null
     * }
     */
    public static function evaluate(?string $status, ?int $expiryEpoch, int $nowEpoch): array
    {
        $out = [
            'eligible'          => false,
            'state'             => self::STATUS_BLOCKED,
            'reason'            => self::STATUS_BLOCKED,
            'expired'           => false,
            'status_class'      => null,
            'expires_at'        => $expiryEpoch === null ? null : gmdate('Y-m-d\TH:i:s\Z', $expiryEpoch),
            'days_since_expiry' => null,
        ];

        if ($status !== self::ACTIVE_STATUS) {
            $out['status_class'] = self::statusClass($status);

            return $out;
        }

        if ($expiryEpoch === null) {
            return ['state' => self::EXPIRY_UNKNOWN, 'reason' => self::EXPIRY_UNKNOWN] + $out;
        }

        if ($expiryEpoch >= $nowEpoch) {
            return ['eligible' => true, 'state' => self::PRE_EXPIRY, 'reason' => null] + $out;
        }

        return [
            'eligible'          => true,
            'state'             => self::EXPIRED,
            'reason'            => null,
            'expired'           => true,
            'days_since_expiry' => intdiv($nowEpoch - $expiryEpoch, self::DAY),
        ] + $out;
    }

    private static function statusClass(?string $status): string
    {
        $s = strtolower((string) $status);

        foreach (self::STATUS_CLASSES as $needle => $class) {
            if ($s !== '' && str_contains($s, $needle)) {
                return $class;
            }
        }

        return $s === '' ? 'unknown' : 'not_active';
    }
}

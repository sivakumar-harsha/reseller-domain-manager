<?php

declare(strict_types=1);

namespace App\Libraries\Domain;

use LogicException;

/**
 * Canonical form of a domain name for storage and comparison: lowercase ASCII,
 * with internationalised labels in Punycode (UTS #46, non-transitional).
 *
 * The result is null for anything that is not a usable domain name; callers
 * must treat null as "unusable", never as a name. A value is not accepted just
 * because idn_to_ascii() returned a string: the result is re-validated as an
 * ASCII hostname and every Punycode label must survive a decode/encode round
 * trip, which rejects malformed Punycode.
 *
 * Rules, in order:
 * - the input must be a non-empty string, valid UTF-8, with no whitespace
 *   (including surrounding whitespace) and no control characters;
 * - it is converted with UTS #46 (case folding, Unicode normalisation,
 *   full-width and other mapped characters, STD3 rules, bidi and joiner checks);
 * - at most one trailing dot is removed;
 * - the ASCII result is at most 253 characters, has at least two labels, and
 *   every label is 1-63 characters of [a-z0-9-] that neither starts nor ends
 *   with a hyphen, and has "--" in positions 3-4 only as the "xn--" prefix;
 * - every "xn--" label must decode and re-encode to itself.
 */
final class DomainNameCanonicalizer
{
    private const MAX_NAME  = 253;
    private const MAX_LABEL = 63;

    /** Longer raw input is rejected before any conversion work is done. */
    private const MAX_INPUT_BYTES = 1024;

    private const FLAGS = IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ | IDNA_USE_STD3_RULES;

    /**
     * @throws LogicException when the PHP intl extension is missing (a missing extension must not make every name look invalid)
     */
    public static function canonicalize(mixed $name): ?string
    {
        if (! function_exists('idn_to_ascii') || ! function_exists('idn_to_utf8')) {
            throw new LogicException('The PHP intl extension is required.');
        }

        if (! is_string($name) || $name === '' || strlen($name) > self::MAX_INPUT_BYTES) {
            return null;
        }

        if (! mb_check_encoding($name, 'UTF-8') || preg_match('/[\p{Z}\p{Cc}]/u', $name) === 1) {
            return null;
        }

        $ascii = idn_to_ascii($name, self::FLAGS, INTL_IDNA_VARIANT_UTS46, $info);

        if (! is_string($ascii) || ($info['errors'] ?? 1) !== 0) {
            return null;
        }

        $ascii = strtolower($ascii);

        if (str_ends_with($ascii, '.')) {
            $ascii = substr($ascii, 0, -1);
        }

        return self::isValidAscii($ascii) ? $ascii : null;
    }

    private static function isValidAscii(string $name): bool
    {
        if ($name === '' || strlen($name) > self::MAX_NAME || ! str_contains($name, '.')) {
            return false;
        }

        foreach (explode('.', $name) as $label) {
            $length = strlen($label);

            if ($length < 1 || $length > self::MAX_LABEL || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
                return false;
            }

            if (substr($label, 2, 2) === '--' && ! (str_starts_with($label, 'xn--') && self::roundTrips($label))) {
                return false;
            }
        }

        return true;
    }

    /** A Punycode label is genuine only if it decodes to Unicode and encodes back to the same label. */
    private static function roundTrips(string $label): bool
    {
        if (strlen($label) <= 4) {
            return false;
        }

        $unicode = idn_to_utf8($label, self::FLAGS, INTL_IDNA_VARIANT_UTS46, $info);

        if (! is_string($unicode) || ($info['errors'] ?? 1) !== 0 || $unicode === $label) {
            return false;
        }

        return idn_to_ascii($unicode, self::FLAGS, INTL_IDNA_VARIANT_UTS46, $back) === $label && ($back['errors'] ?? 1) === 0;
    }
}

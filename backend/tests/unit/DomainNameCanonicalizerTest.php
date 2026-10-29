<?php

declare(strict_types=1);

use App\Libraries\Domain\DomainNameCanonicalizer;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Pure conversion rules; no database and no network.
 *
 * @internal
 */
final class DomainNameCanonicalizerTest extends CIUnitTestCase
{
    /**
     * @param array<string, string|null> $cases input => expected canonical form (null = unusable)
     */
    private function assertCanonical(array $cases): void
    {
        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, DomainNameCanonicalizer::canonicalize((string) $input), json_encode((string) $input));
        }
    }

    private function nameOfLength(int $length): string
    {
        // 63 + 1 + 63 + 1 + 63 + 1 + n  => total = 192 + n, with n >= 2 for a valid last label.
        $labels = [str_repeat('a', 63), str_repeat('b', 63), str_repeat('c', 63)];
        $rest   = $length - 192;

        return implode('.', $labels) . '.' . str_repeat('d', $rest);
    }

    public function testAsciiNamesAreLowercased(): void
    {
        $this->assertCanonical([
            'example.com'       => 'example.com',
            'EXAMPLE.COM'       => 'example.com',
            'Alpha.Example.Com' => 'alpha.example.com',
            'a-b.c-d.org'       => 'a-b.c-d.org',
            '1.2.example'       => '1.2.example',
        ]);
    }

    public function testUnicodeIsConvertedToPunycode(): void
    {
        $this->assertCanonical([
            'bücher.example' => 'xn--bcher-kva.example',
            'münchen.de'     => 'xn--mnchen-3ya.de',
            '日本語.jp'         => 'xn--wgv71a119e.jp',
            'faß.de'         => 'xn--fa-hia.de',
            // Full-width letters and the ideographic full stop are mapped by UTS #46.
            'ｅｘａｍｐｌｅ.com'    => 'example.com',
            'münchen。de'     => 'xn--mnchen-3ya.de',
        ]);
    }

    public function testPunycodeIsKeptAndMixedCaseGivesTheSameIdentity(): void
    {
        $this->assertCanonical([
            'xn--bcher-kva.example' => 'xn--bcher-kva.example',
            'XN--BCHER-KVA.Example' => 'xn--bcher-kva.example',
            'Bücher.EXAMPLE'        => 'xn--bcher-kva.example',
            'BÜCHER.example'        => 'xn--bcher-kva.example',
            'xn--ls8h.la'           => 'xn--ls8h.la',
        ]);

        // Unicode and Punycode spellings of one name are one identity.
        $this->assertSame(
            DomainNameCanonicalizer::canonicalize('bücher.example'),
            DomainNameCanonicalizer::canonicalize('xn--bcher-kva.example'),
        );
    }

    public function testConversionIsIdempotent(): void
    {
        foreach (['bücher.example', 'Example.COM', '日本語.jp.', 'xn--ls8h.la'] as $input) {
            $once = DomainNameCanonicalizer::canonicalize($input);

            $this->assertNotNull($once, $input);
            $this->assertSame($once, DomainNameCanonicalizer::canonicalize($once), $input);
        }
    }

    public function testAtMostOneTrailingDotIsRemoved(): void
    {
        $this->assertCanonical([
            'example.com.'   => 'example.com',
            'bücher.example.' => 'xn--bcher-kva.example',
            'example.com..'  => null,
            'example.com...' => null,
            '.'              => null,
            'example.'       => null,
        ]);
    }

    public function testMalformedPunycodeIsRejected(): void
    {
        $this->assertCanonical([
            'xn--bcher-kv.example'   => null, // truncated encoding
            'xn--bcher-kva-.example' => null, // stray delimiter
            'xn--99999999.example'   => null, // overflowing code point
            'xn--a-.example'         => null,
            'xn--abc.example'        => null,
            'xn--.example'           => null,
            'xn--example-.com'       => null, // would decode to plain ASCII
            'xn--bcher-kva_.example' => null,
            'xn--ü.example'          => null,
        ]);
    }

    public function testWhitespaceAndControlCharactersAreRejected(): void
    {
        $this->assertCanonical([
            'exa mple.com'      => null,
            ' example.com'      => null,
            'example.com '      => null,
            "example.com\n"     => null,
            "exa\tmple.com"     => null,
            "exa\0mple.com"     => null,
            "a\u{3000}b.com"    => null, // ideographic space
            "a\u{00A0}b.com"    => null, // no-break space
            ''                  => null,
        ]);
    }

    public function testInvalidLabelsAndHyphensAreRejected(): void
    {
        $this->assertCanonical([
            'example'     => null, // single label
            'a..b.com'    => null,
            '.example.com' => null,
            '-a.com'      => null,
            'a-.com'      => null,
            'a.-b.com'    => null,
            'a.b-.com'    => null,
            'ab--cd.com'  => null, // reserved "--" in positions 3-4 outside Punycode
            'a_b.com'     => null,
            'a!b.com'     => null,
            'a/b.com'     => null,
            'a@b.com'     => null,
        ]);
    }

    public function testNonStringAndInvalidUtf8AreRejected(): void
    {
        foreach ([null, 123, 1.5, true, ['example.com'], new stdClass()] as $value) {
            $this->assertNull(DomainNameCanonicalizer::canonicalize($value), get_debug_type($value));
        }

        $this->assertNull(DomainNameCanonicalizer::canonicalize("\xFF.com"));
        $this->assertNull(DomainNameCanonicalizer::canonicalize("a\xC3.com"));
    }

    public function testLabelLengthBoundaries(): void
    {
        $this->assertSame(str_repeat('a', 63) . '.com', DomainNameCanonicalizer::canonicalize(str_repeat('a', 63) . '.com'));
        $this->assertNull(DomainNameCanonicalizer::canonicalize(str_repeat('a', 64) . '.com'));
        $this->assertNull(DomainNameCanonicalizer::canonicalize('a.' . str_repeat('b', 64)));
        $this->assertNull(DomainNameCanonicalizer::canonicalize('.com'));

        // The 63-character limit applies to the Punycode form, not the Unicode form.
        $this->assertNull(DomainNameCanonicalizer::canonicalize(str_repeat('ü', 60) . '.com'));
        $this->assertNotNull(DomainNameCanonicalizer::canonicalize(str_repeat('ü', 10) . '.com'));
    }

    public function testTotalLengthBoundaries(): void
    {
        $max = $this->nameOfLength(253);
        $this->assertSame(253, strlen($max));
        $this->assertSame($max, DomainNameCanonicalizer::canonicalize($max));
        $this->assertSame($max, DomainNameCanonicalizer::canonicalize($max . '.'), 'The trailing dot does not count.');

        $over = $this->nameOfLength(254);
        $this->assertSame(254, strlen($over));
        $this->assertNull(DomainNameCanonicalizer::canonicalize($over));

        $this->assertNull(DomainNameCanonicalizer::canonicalize(str_repeat('a.', 600) . 'com'), 'Oversized raw input is rejected early.');
    }

    public function testResultIsAlwaysPrintableLowercaseAscii(): void
    {
        foreach (['bücher.example', 'EXAMPLE.com', '日本語.jp', 'xn--ls8h.la', 'münchen。de'] as $input) {
            $result = DomainNameCanonicalizer::canonicalize($input);

            $this->assertNotNull($result, $input);
            $this->assertMatchesRegularExpression('/^[a-z0-9.\-]+$/', $result, $input);
        }
    }
}

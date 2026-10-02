<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use GuzzleHttp\Utils;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\Utf8Scrub;

/**
 * The scrub authority every prompt-source loader shares (audit 15d-08).
 *
 * The loader-level behaviour — that each loader actually CALLS it — is pinned
 * in {@see PromptSourceUtf8Test}; this file pins the contract those loaders
 * lean on: valid input untouched, output always encodable, never longer than
 * the input, an exact count, and a notice only when something was replaced.
 */
final class Utf8ScrubTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function invalidShapes(): iterable
    {
        yield 'a lone Latin-1 byte' => ["Caf\xe9", 1];
        yield 'a truncated 3-byte sequence' => ["a\xe2\x82b", 1];
        yield 'a truncated 4-byte sequence' => ["\xf0\x9f\x98", 1];
        yield 'three bare continuation bytes' => ["\x80\x81\x82", 3];
        yield 'binary between ASCII' => ["ab\xff\xfe\xfdcd", 3];
        yield 'an existing ? is not swallowed' => ["\xc3(?", 1];
    }

    #[DataProvider('invalidShapes')]
    public function testInvalidInputBecomesEncodableNeverLongerAndIsCountedExactly(string $raw, int $expected): void
    {
        [$clean, $replaced] = Utf8Scrub::scrub($raw);

        self::assertTrue(mb_check_encoding($clean, 'UTF-8'));
        self::assertLessThanOrEqual(strlen($raw), strlen($clean), 'a one-byte substitute can never grow the text');
        self::assertSame($expected, $replaced);
        // Utils::jsonEncode() is what both providers' `'json' => $params`
        // reaches, and it throws on malformed UTF-8 — the failure this exists for.
        self::assertIsString(Utils::jsonEncode(['content' => $clean]));
    }

    public function testValidInputIsReturnedUntouchedWithNoNotice(): void
    {
        $text = "Café — naïve ✓ ?";

        self::assertSame([$text, 0], Utf8Scrub::scrub($text));
        self::assertSame($text, Utf8Scrub::announced($text, 'CLAUDE.md'));
        self::assertSame('', Utf8Scrub::notice(0, 'CLAUDE.md'));
    }

    public function testTheSurroundingTextSurvivesAndTheSubstituteIsAQuestionMark(): void
    {
        self::assertSame('Caf? au lait', Utf8Scrub::clean("Caf\xe9 au lait"));
    }

    public function testAnnouncedAppendsANoticeNamingTheSourceAndTheCount(): void
    {
        $out = Utf8Scrub::announced("Caf\xe9 na\xefve", 'docs/legacy.md');

        self::assertStringStartsWith('Caf? na?ve', $out);
        self::assertStringContainsString(
            '[encoding: 2 byte sequence(s) of docs/legacy.md were not valid UTF-8 and were replaced with "?".]',
            $out,
        );
        self::assertTrue(mb_check_encoding($out, 'UTF-8'));
    }

    public function testTheNoticeScrubsTheSourceLabelToo(): void
    {
        $notice = Utf8Scrub::notice(1, "caf\xe9.md");

        self::assertTrue(mb_check_encoding($notice, 'UTF-8'), 'a path is bytes; the notice naming it must still encode');
        self::assertStringContainsString('of caf?.md were', $notice);
    }

    public function testTheGlobalSubstituteCharacterIsRestored(): void
    {
        $before = mb_substitute_character();

        Utf8Scrub::scrub("\xff");

        self::assertSame($before, mb_substitute_character());
    }
}

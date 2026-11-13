<?php

/**
 * candy-tetris/tests/Support/CliVisibleTest.php
 *
 * Pins the one stderr-notice clamp (candy-tetris-014/-075): control bytes
 * never reach the terminal, the payload is clamped by display width so a
 * UTF-8 sequence cannot be sliced, and an over-long payload loses its
 * MIDDLE — the trailing path component, the first thing the old 40-byte
 * byte-slice ate, must survive.
 */

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests\Support;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Tetris\Support\CliVisible;

use function mb_strwidth;
use function preg_match;

final class CliVisibleTest extends TestCase
{
    public function testControlBytesBecomeQuestionMarks(): void
    {
        self::assertSame(
            'Failed??[31m??style?',
            CliVisible::clamp("Failed?\x1b[31m\x00\rstyle\n"),
            'ESC, NUL, CR and LF must all land as visible "?" — never as terminal control',
        );
        self::assertSame(
            '?del?',
            CliVisible::clamp("\x7fdel\x7f"),
        );
    }

    public function testAShortPayloadPassesThroughUntouched(): void
    {
        $plain = 'invalid persistence format in /home/player/.config/candy-tetris/scores.json';
        self::assertSame($plain, CliVisible::clamp($plain));
    }

    public function testTheTailOfALongPathSurvivesTheClamp(): void
    {
        $payload = 'token for "move" is not a single key in /home/player/'
            . str_repeat('deep-', 20) . 'keys.json';

        $clamped = CliVisible::clamp($payload);

        self::assertStringStartsWith('token for "move"', $clamped, 'the leading diagnosis survives');
        self::assertStringEndsWith('deep-keys.json', $clamped, 'the tail of the path survives — that is the bytes the old head-slice ate first');
        self::assertStringContainsString('…', $clamped, 'the elided middle is marked, not silently gone');
        self::assertLessThanOrEqual(CliVisible::MAX_PAYLOAD_WIDTH, mb_strwidth($clamped, 'UTF-8'));
    }

    public function testTheClampNeverSplitsAUtf8Sequence(): void
    {
        $payload = str_repeat('Ünïcödé — 漢字test', 40) . 'keys.json';

        $clamped = CliVisible::clamp($payload, 30);

        self::assertSame(1, preg_match('/^[\x20-\x7e\x{80}-\x{10FFFF}…]+$/u', $clamped), 'no orphan continuation bytes may appear');
        self::assertStringNotContainsString("\xEF\xBF\xBD", $clamped, 'no replacement glyph — characters are whole');
        self::assertStringEndsWith('keys.json', $clamped);
    }

    public function testWideCharactersAreCountedByWidthNotByCharacter(): void
    {
        $clamped = CliVisible::clamp(str_repeat('漢', 50), 10);

        self::assertLessThanOrEqual(10, mb_strwidth($clamped, 'UTF-8'), '50 fullwidth glyphs must not overflow into 50 columns');
        self::assertStringContainsString('…', $clamped);
    }

    public function testEveryLineOfAMultilinePayloadCollapsesToOneLine(): void
    {
        $clamped = CliVisible::clamp("one\ntwo\r\nthree\x0bfour");

        self::assertStringNotContainsString("\n", $clamped);
        self::assertStringNotContainsString("\r", $clamped);
        self::assertStringNotContainsString("\x0b", $clamped);
    }

    public function testAWidthThatCannotFitHeadMarkerAndTailRefuses(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot fit head, marker and tail');

        CliVisible::clamp('anything', 3);
    }
}

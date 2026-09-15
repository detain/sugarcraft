<?php

declare(strict_types=1);

namespace SugarCraft\Gallery\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Gallery\AnsiGuard;
use SugarCraft\Gallery\PosterCard;
use SugarCraft\Sprinkles\Style;

/**
 * The SGR-only rule behind the {@see PosterCard::withStyledTitle()} trust boundary.
 *
 * Everything here is about what the guard lets through and what it stops. The
 * dangerous cases are the ones that must be exhaustive: a styled title that
 * reaches the terminal with a cursor move, an erase, or an OSC payload is a
 * terminal-injection bug in whatever app embeds this widget, so each escape form
 * ECMA-48 defines gets its own fixture rather than being assumed covered.
 */
final class AnsiGuardTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function safeProvider(): array
    {
        return [
            'empty' => [''],
            'plain ascii' => ['Blade Runner'],
            'plain utf-8' => ['因果の物語 — Ångström'],
            'bold + reset' => ["\e[1mBlade\e[0m Runner"],
            'many params' => ["\e[1;4;31;48;5;208mX\e[0m"],
            'sgr sub-params' => ["\e[4:3mundercurl\e[59m"],
            'sgr only' => ["\e[0m"],
            'styled then plain' => ["\e[32mok\e[39m done"],
            'percent and brackets' => ['100% [not-an-escape] (x)'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}> payload + why it is unsafe
     */
    public static function unsafeProvider(): array
    {
        return [
            'cursor up (CSI A)' => ["\e[2Ahi", 'a CSI sequence with a non-SGR final byte'],
            'cursor home' => ["\e[Hhi", 'same'],
            'erase display' => ["hi\e[2J", 'clears the screen from a title cell'],
            'erase line' => ["hi\e[K", 'clears the rest of the row'],
            'set mode (DECSTBM)' => ["\e[?1;2r", 'private-parameter CSI'],
            'hyperlink OSC BEL' => ["\e]8;;https://evil\x07click", 'an OSC 8 hyperlink'],
            'hyperlink OSC ST' => ["\e]8;;https://evil\e\\click", 'the same payload using ST'],
            'window title OSC' => ["\e]0;pwned\e\\", 'OSC rewrites the terminal tab'],
            'sixel DCS' => ["\eP0;1;2q#0;2;0;0;0\e\\", 'a DCS pixel payload'],
            'iterm2 OSC' => ["\e]1337;File=inline=1:AAAA\e\\", 'an iTerm2 inline image'],
            'apc' => ["\e+_never_seen_\e\\", 'APC'],
            'charset designator' => ["\e(Bplain", 'ESC ( selects a character set'],
            'Fe reverse index' => ["\eMscroll-up", 'a two-byte Fe escape'],
            'bell' => ["ring\x07", 'a bare C0 control'],
            'carriage return' => ["line1\rline2", 'would overwrite the row'],
            'newline' => ["row1\nrow2", 'would break the fixed-height cell'],
            'tab' => ["a\tb", 'would move the cursor mid-title'],
            'nul' => ["nu\x00l", 'a bare C0 control'],
            'shift-in' => ["si\x0Ehere", 'C0'],
            'del' => ["de\x7Fl", 'DEL'],
            'lone trailing escape' => ["tail\e", 'an unterminated sequence'],
            'truncated csi' => ["\e[3", 'a CSI with no final byte'],
            'escape before sgr' => ["\e[\e[32mok", 'a malformed prefix smuggling a valid SGR'],
        ];
    }

    /**
     * @dataProvider safeProvider
     */
    public function testAcceptsStylingAndText(string $ansi): void
    {
        self::assertTrue(AnsiGuard::isSafe($ansi), 'a safe title must be accepted');
        self::assertSame($ansi, AnsiGuard::assertSafe($ansi), 'assertSafe returns its input unchanged');
        self::assertSame($ansi, PosterCard::assertSafeAnsi($ansi), 'the card delegate behaves identically');
        self::assertSame($ansi, AnsiGuard::sanitize($ansi), 'sanitising a safe string is a byte-preserving no-op');
    }

    /**
     * @dataProvider unsafeProvider
     */
    public function testRejectsEveryOtherEscapeForm(string $ansi, string $why): void
    {
        self::assertFalse(AnsiGuard::isSafe($ansi), $why);

        $thrown = null;
        try {
            AnsiGuard::assertSafe($ansi);
        } catch (InvalidArgumentException $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'assertSafe must fail loud, not silently accept ' . json_encode($ansi));
        self::assertStringContainsString('Unsafe escape sequence at offset', $thrown->getMessage());
        self::assertMatchesRegularExpression('/offset \d+: \[[0-9A-F ]+/', $thrown->getMessage(), 'the message pinpoints the offending bytes');
    }

    /**
     * @dataProvider unsafeProvider
     */
    public function testSanitizeStripsThePayloadAndKeepsTheRest(string $ansi, string $why): void
    {
        $clean = AnsiGuard::sanitize($ansi);

        self::assertTrue(AnsiGuard::isSafe($clean), 'sanitize always yields something acceptable');
        self::assertSame($clean, AnsiGuard::sanitize($clean), 'sanitize is idempotent');
        self::assertLessThanOrEqual(strlen($ansi), strlen($clean), 'sanitize never invents bytes');
    }

    public function testSanitizeKeepsAdjacentStylingAndText(): void
    {
        // The escape is dropped in place; the styling around it survives, so a
        // highlight that merely *contains* one bad sequence is not thrown away.
        $dirty = "\e[1mBlade" . "\e[2J" . "\e[0m Runner";

        self::assertSame("\e[1mBlade\e[0m Runner", AnsiGuard::sanitize($dirty));
    }

    public function testSanitizeRemovesAnUnterminatedOscEntirely(): void
    {
        // Nothing after a truncated string sequence may be reinterpreted as text
        // the terminal will act on: the whole payload goes, and so does the tail
        // the terminal would have consumed as part of it.
        self::assertSame('', AnsiGuard::sanitize("\e]8;;https://evil"));
    }

    public function testOffsetReportedPointsAtTheOffendingSequence(): void
    {
        $ansi = "\e[1msafe\e[2J";

        try {
            AnsiGuard::assertSafe($ansi);
            self::fail('expected the erase sequence to be rejected');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('at offset 8:', $e->getMessage(), 'ESC[1m + "safe" = 8 bytes in');
            self::assertStringContainsString('1B 5B 32 4A', $e->getMessage(), 'the offending bytes are hex-dumped');
        }
    }

    public function testStylingProducedByCandySprinklesPassesTheGuard(): void
    {
        // The guard exists to stop untrusted bytes, not to punish this ecosystem's
        // own renderer: whatever candy-sprinkles emits must always be acceptable,
        // across the colour spaces it supports.
        $titles = [
            Style::new()->bold()->render('Blade Runner'),
            Style::new()->fg('#123456')->bg('#fedcba')->render('Dune'),
            Style::new()->underline()->italic()->faint()->reverse()->render('Alien'),
            Style::new()->fg(Color::ansi256(208))->bg(Color::ansi(4))->strikethrough()->blink()->render('Heat'),
            Style::new()->fg(Color::rgb(3, 4, 5))->invisible()->render('Secret'),
        ];

        foreach ($titles as $i => $styled) {
            self::assertTrue(AnsiGuard::isSafe($styled), 'sprinkles output #' . $i . ' must pass: ' . json_encode($styled));
            self::assertSame($styled, AnsiGuard::sanitize($styled));
        }
    }
}

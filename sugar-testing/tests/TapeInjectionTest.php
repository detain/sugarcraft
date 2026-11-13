<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Testing\Tape\TapeRecorder;

/**
 * The rendered tape is executed by CI (vhs.yml renders committed tapes),
 * so anything a builder argument can smuggle — a raw newline forging a
 * standalone directive, a quote escaping a Set Theme string, a literal
 * Shell "..." — is arbitrary command execution. These pins lock the
 * escape guarantee: every physical line is a directive or a comment that
 * the builder itself emitted, never text interpolated from a payload.
 *
 * line() is the deliberate verbatim escape hatch and is pinned as such:
 * whatever it receives lands unescaped, on purpose.
 */
final class TapeInjectionTest extends TestCase
{
    private string $tapePath;

    protected function setUp(): void
    {
        $this->tapePath = \sys_get_temp_dir() . '/sugar-testing-tape-' . \getmypid() . '-' . \bin2hex(\random_bytes(4)) . '.tape';
    }

    protected function tearDown(): void
    {
        if (\is_file($this->tapePath)) {
            @\unlink($this->tapePath);
        }
    }

    public function testTypedNewlineCannotForgeADirectiveLine(): void
    {
        TapeRecorder::to($this->tapePath)
            ->type("a\nSet FontSize 4242")
            ->save();

        $content = (string) \file_get_contents($this->tapePath);

        self::assertStringContainsString('Type "a\nSet FontSize 4242"', $content, 'the newline must ride inside the quotes as a backslash escape');
        self::assertNotContains('Set FontSize 4242', \explode("\n", $content), 'a forged standalone Set directive escaped the quotes');
    }

    public function testTypedSubstitutionTokenIsRefusedNotSanitized(): void
    {
        // The dialect rewrites double-brace key tokens inside quoted Type
        // content; silently stripping them would corrupt legitimate text,
        // so the builder refuses the whole value loudly.
        try {
            TapeRecorder::to($this->tapePath)->type('safe {{ctrl+c}} text');
            self::fail('typed content carrying the substitution markers must throw');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('must never carry', $e->getMessage());
        }

        // A single brace is ordinary text and still records fine.
        TapeRecorder::to($this->tapePath)->type('x {y} z')->save();
        self::assertStringContainsString('Type "x {y} z"', (string) \file_get_contents($this->tapePath));
    }

    public function testHeaderThemeCannotCarrySubstitutionMarkers(): void
    {
        try {
            TapeRecorder::to($this->tapePath)->header(theme: 'Tokyo{{enter}}Night');
            self::fail('theme carrying the substitution markers must throw');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('must never carry', $e->getMessage());
        }
    }

    public function testControlBytesNeverSurviveRawInsideTypedText(): void
    {
        TapeRecorder::to($this->tapePath)
            ->header()
            ->type("b\rc\x07d\x00e")
            ->save();

        foreach (\explode("\n", (string) \file_get_contents($this->tapePath)) as $line) {
            self::assertFalse(
                (bool) \preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $line),
                'raw control byte in: ' . \addcslashes($line, "\x00-\x1f\x7f"),
            );
        }
    }

    public function testCommentCannotForgeADirectiveLine(): void
    {
        TapeRecorder::to($this->tapePath)
            ->comment("note\nShell \"rm -rf /\"")
            ->save();

        foreach (\explode("\n", (string) \file_get_contents($this->tapePath)) as $line) {
            if ($line === '') {
                continue;
            }
            self::assertStringStartsWith('# ', $line, 'a comment payload forged a non-comment line');
        }
    }

    public function testHeaderThemeCannotEscapeItsQuotes(): void
    {
        TapeRecorder::to($this->tapePath)
            ->header(theme: 'Evil" Enter')
            ->save();

        $lines = \explode("\n", (string) \file_get_contents($this->tapePath));
        $themeIndex = \array_search(true, \array_map(static fn (string $l): bool => \str_starts_with($l, 'Set Theme'), $lines), true);

        self::assertIsInt($themeIndex);
        self::assertSame('Set Theme "Evil\" Enter"', $lines[$themeIndex], 'the theme quote must be escaped in place, on one line');
        self::assertStringStartsWith('Set FontSize', $lines[$themeIndex + 1], 'the theme smuggled a standalone directive line');
    }

    public function testLineIsTheDeliberateVerbatimHatch(): void
    {
        TapeRecorder::to($this->tapePath)
            ->line('Set FontSize 42')
            ->save();

        self::assertMatchesRegularExpression(
            '/^Set FontSize 42$/m',
            (string) \file_get_contents($this->tapePath),
            'line() is documented verbatim — it must pass the directive through as its own line',
        );
    }

    public function testResizeIsACommentLineNotADirective(): void
    {
        TapeRecorder::to($this->tapePath)
            ->resize(80, 24)
            ->save();

        $content = (string) \file_get_contents($this->tapePath);

        self::assertMatchesRegularExpression('/^# .*80.*24.*$/m', $content);
        self::assertStringNotContainsString('Set Width 80', $content, 'resize() must not rewrite header dimensions');
    }
}

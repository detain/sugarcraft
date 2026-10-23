<?php

/**
 * Behavioural pins for the candy-image front end — usage/help exits,
 * the flag grammar (values, formats, scale, size box), the refusal surface
 * (chafa by design, screen-passthrough by unwired app-surface dial), the
 * Dial flags (--symbols / --canvas / --work),
 * and the terminal-detection dump. The blocking render path is pinned in
 * RenderSmokeTest; the blocking animation loop never runs here — the $play
 * seam returns the wired Player.
 */

declare(strict_types=1);

namespace SugarCraft\CandyImage\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CandyImage\Cli;
use SugarCraft\Image\Detect;
use SugarCraft\Image\Renderer\SymbolMapRenderer;

final class CliTest extends TestCase
{
    /** @var list<string> */
    private array $artifacts = [];

    protected function tearDown(): void
    {
        // --passthrough mutates a process-global in sugar-image; leave the
        // base's documented default (enabled) for every later test.
        Detect::setAutoPassthrough(true);

        foreach ($this->artifacts as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->artifacts = [];
    }

    /** @return array{resource, resource} fresh in-memory out/err pair */
    private static function pipes(): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        return [$stdout, $stderr];
    }

    /** @param resource $stream */
    private static function drain($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    /** @param list<string> $args */
    private static function drive(array $args): array
    {
        [$stdout, $stderr] = self::pipes();
        $exit = Cli::main(array_merge(['candy-image'], $args), $stdout, $stderr);

        return [$exit, self::drain($stdout), self::drain($stderr)];
    }

    public function testHelpExitsZeroWithUsageOnStdout(): void
    {
        [$exit, $out, $err] = self::drive(['--help']);

        $this->assertSame(Cli::EXIT_OK, $exit);
        $this->assertStringContainsString('usage:', $out);
        $this->assertStringContainsString('candy-image', $out);
        $this->assertSame('', $err);
    }

    public function testShortHelpFlagExitsZero(): void
    {
        [$exit, $out] = self::drive(['-h']);

        $this->assertSame(Cli::EXIT_OK, $exit);
        $this->assertStringContainsString('usage:', $out);
    }

    public function testVersionExitsZeroWithNamedVersion(): void
    {
        [$exit, $out, $err] = self::drive(['--version']);

        $this->assertSame(Cli::EXIT_OK, $exit);
        $this->assertSame('candy-image ' . Cli::VERSION . "\n", $out);
        $this->assertSame('', $err);
    }

    public function testMissingFileExitsUsageWithHelpOnStderr(): void
    {
        [$exit, $out, $err] = self::drive([]);

        $this->assertSame(Cli::EXIT_USAGE, $exit);
        $this->assertStringContainsString('usage:', $err);
        $this->assertSame('', $out);
    }

    public function testUnknownFlagExitsUsageNamingTheFlag(): void
    {
        [$exit, , $err] = self::drive(['--colors=1']);

        $this->assertSame(Cli::EXIT_USAGE, $exit);
        $this->assertStringContainsString('unknown option --colors', $err);
    }

    public function testProbeFlagIsRefusedAsUnknownOnThisBase(): void
    {
        // Kept --probe off the surface: Detect exposes no env-only
        // probe seam, so honouring the spelling here would be a lie.
        [, , $err] = self::drive(['--probe=off', 'x.png']);

        $this->assertStringContainsString('unknown option --probe', $err);
    }

    public function testValuelessFlagExitsUsage(): void
    {
        [$exit, , $err] = self::drive(['--format']);

        $this->assertSame(Cli::EXIT_USAGE, $exit);
        $this->assertStringContainsString("invalid value '' for --format", $err);
    }

    public function testUnknownFormatExitsUsage(): void
    {
        [$exit, , $err] = self::drive(['--format=svg', 'x.png']);

        $this->assertSame(Cli::EXIT_USAGE, $exit);
        $this->assertStringContainsString("unknown render format 'svg'", $err);
    }

    public function testChafaFormatIsRefusedByDesign(): void
    {
        [$exit, , $err] = self::drive(['--format=chafa', 'x.png']);

        $this->assertSame(Cli::EXIT_USAGE, $exit);
        $this->assertStringContainsString('chafa', $err);
        $this->assertStringContainsString('F-18', $err);
    }

    public function testSymbolsSelectorIsAcceptedAndValidated(): void
    {
        // Chafa's tag-selector grammar now reaches the library.
        // A missing file still exits INPUT — proof the flag itself parsed.
        [$exit, , $err] = self::drive(['--symbols=braille', '/nonexistent.png']);
        $this->assertSame(Cli::EXIT_INPUT, $exit, $err);

        [$exit, , $err] = self::drive(['--symbols=block+braille-legacy', '/nonexistent.png']);
        $this->assertSame(Cli::EXIT_INPUT, $exit, $err);

        [$exit, , $err] = self::drive(['--symbols=notaclass', 'x.png']);
        $this->assertSame(Cli::EXIT_USAGE, $exit);
        $this->assertStringContainsString('unknown symbol class', $err);

        [$exit, , $err] = self::drive(['--symbols', 'x.png']);
        $this->assertSame(Cli::EXIT_USAGE, $exit);
        $this->assertStringContainsString("invalid value '' for --symbols", $err);
    }

    public function testSymbolsFormatIsAWiredRenderer(): void
    {
        [$exit, , $err] = self::drive(['--format=symbols', '/nonexistent.png']);

        $this->assertSame(Cli::EXIT_INPUT, $exit, 'the format is known, the file is not: ' . $err);
        $this->assertStringNotContainsString('unknown render format', $err);
    }

    public function testCanvasFlagAcceptsTheFourRungsAndRefusesOthers(): void
    {
        foreach (['truecolor', 'ansi256', 'ansi16', 'fgbg'] as $mode) {
            [$exit] = self::drive(['--canvas=' . $mode, '/nonexistent.png']);
            $this->assertSame(Cli::EXIT_INPUT, $exit, $mode);
        }

        [$exit, , $err] = self::drive(['--canvas=mono', 'x.png']);
        $this->assertSame(Cli::EXIT_USAGE, $exit);
        $this->assertStringContainsString("invalid value 'mono' for --canvas", $err);
    }

    public function testWorkFlagAcceptsChafaIntegersOnly(): void
    {
        // Rev-w10-2 M3: chafa's --work is G_OPTION_ARG_INT 1..9
        // (chicle-options.c:2420); the bare-float sugar-image dial spelling
        // is gone — it was what inverted the polarity at the cheap end.
        foreach (['1', '5', '9'] as $value) {
            [$exit] = self::drive(['--work=' . $value, '/nonexistent.png']);
            $this->assertSame(Cli::EXIT_INPUT, $exit, $value);
        }

        foreach (['0', '10', 'abc', '-1', '0.5', '0.75', '1.5'] as $value) {
            [$exit] = self::drive(['--work=' . $value, 'x.png']);
            $this->assertSame(Cli::EXIT_USAGE, $exit, $value);
        }
    }

    public function testTheWorkDialCarriesChafasExactNormalization(): void
    {
        // Rev-w10-2 M3: exact parity with chafa.c:570,
        // (n - 1) / 8.0f — every step is a dyadic rational, so float
        // equality is exact (no epsilon). Polarity: 1 is the CHEAPEST
        // (0.0), 9 the most exhaustive (1.0).
        $table = [
            '1' => 0.0,
            '2' => 0.125,
            '3' => 0.25,
            '4' => 0.375,
            '5' => 0.5,
            '6' => 0.625,
            '7' => 0.75,
            '8' => 0.875,
            '9' => 1.0,
        ];
        foreach ($table as $raw => $factor) {
            // PHP casts numeric-string array keys to int — spell the dial back.
            $this->assertSame($factor, Cli::workDialToFactor((string) $raw), '--work=' . $raw);
        }

        // Monotonicity: successive steps strictly increase, so the
        // pre-fix 1 -> 1.0 / 1.5 -> 0.15 collapse is structurally
        // impossible — decimals never parse at all.
        $previous = null;
        foreach ($table as $factor) {
            if ($previous !== null) {
                $this->assertGreaterThan($previous, $factor);
            }
            $previous = $factor;
        }
        $this->assertNull(Cli::workDialToFactor('1.5'), 'the non-integer collapse spelling is refused');

        // The endpoints land on the renderer's documented rungs.
        $this->assertSame(1, SymbolMapRenderer::candidateLimit(Cli::workDialToFactor('1')));
        $this->assertTrue(SymbolMapRenderer::isExhaustiveWorkFactor(Cli::workDialToFactor('9')));
        // chafa's step 7 lands on 0.75 → raw int 8 → EXHAUSTIVE (the old
        // /10 mapping answered 0.7 → 7 → non-exhaustive: this pin reds it).
        $this->assertTrue(SymbolMapRenderer::isExhaustiveWorkFactor(Cli::workDialToFactor('7')));
        $this->assertFalse(SymbolMapRenderer::isExhaustiveWorkFactor(Cli::workDialToFactor('6')));
    }

    public function testScreenPassthroughStaysRefusedThoughS71Landed(): void
    {
        [$exit, , $err] = self::drive(['--passthrough=screen', 'x.png']);

        $this->assertSame(Cli::EXIT_USAGE, $exit);
        $this->assertStringContainsString('--passthrough=screen is not available on this base', $err);
        $this->assertStringContainsString('applied automatically by detection', $err);
    }

    public function testTmuxPassthroughIsRefusedWithoutAForceSeam(): void
    {
        [$exit, , $err] = self::drive(['--passthrough=tmux', 'x.png']);

        $this->assertSame(Cli::EXIT_USAGE, $exit);
        $this->assertStringContainsString('not available on this base', $err);
    }

    public function testPassthroughNoneDisablesTheTmuxTransaction(): void
    {
        // The refusal surface exits before apply; a valid value applies even
        // though the missing-file usage exit follows right after.
        [$exit] = self::drive(['--passthrough=none']);

        $this->assertSame(Cli::EXIT_USAGE, $exit);
        $this->assertFalse(Detect::autoPassthrough());
    }

    public function testPassthroughAutoKeepsTheTmuxTransaction(): void
    {
        Detect::setAutoPassthrough(false);

        self::drive(['--passthrough=auto']);

        $this->assertTrue(Detect::autoPassthrough());
    }

    public function testSecondPositionalExitsUsage(): void
    {
        [$exit, , $err] = self::drive(['a.png', 'b.png']);

        $this->assertSame(Cli::EXIT_USAGE, $exit);
        $this->assertStringContainsString("unexpected argument 'b.png'", $err);
    }

    public function testPoliteIsAcceptedAsANoOp(): void
    {
        // chicle accepts --polite (on|off); SC renders inline synchronously,
        // so the flag must parse cleanly and change nothing.
        foreach (['--polite', '--polite=on', '--polite=off'] as $spelling) {
            [$exit, , $err] = self::drive([$spelling]);

            $this->assertSame(Cli::EXIT_USAGE, $exit, $spelling);
            $this->assertStringContainsString('usage:', $err, $spelling);
            $this->assertStringNotContainsString('unknown option', $err, $spelling);
        }
    }

    public function testWidthRejectsNonPositiveAndFractionalValues(): void
    {
        foreach (['0', 'abc', '-4', '8.5'] as $raw) {
            [$exit, , $err] = self::drive(['--width=' . $raw, 'x.png']);

            $this->assertSame(Cli::EXIT_USAGE, $exit, $raw);
            $this->assertStringContainsString('invalid value', $err, $raw);
        }
    }

    public function testSizeBoxMustBeAPositivePairAndConflictsWithExplicitLegs(): void
    {
        [$exit, , $err] = self::drive(['--size=40x12', 'x.png']);
        $this->assertNotSame(Cli::EXIT_USAGE, $exit, 'a valid box parses through to the render path');
        $this->assertSame(Cli::EXIT_INPUT, $exit);
        $this->assertNotSame('', $err, 'the missing file is reported by the decode stage');

        foreach (['40', '0x12', '12x0', 'axb'] as $raw) {
            [$exit, , $err] = self::drive(['--size=' . $raw, 'x.png']);

            $this->assertSame(Cli::EXIT_USAGE, $exit, $raw);
            $this->assertStringContainsString('invalid value', $err, $raw);
        }

        [$exit, , $err] = self::drive(['--width=40', '--size=40x12', 'x.png']);
        $this->assertSame(Cli::EXIT_USAGE, $exit);
        $this->assertStringContainsString('invalid value', $err);

        [$exit, , $err] = self::drive(['--size=40x12', '--height=8', 'x.png']);
        $this->assertSame(Cli::EXIT_USAGE, $exit);
        $this->assertStringContainsString('invalid value', $err);
    }

    public function testScaleAcceptsEverySpellingCaseInsensitively(): void
    {
        foreach (['fit', 'shrink', 'FILL', 'stretch', 'none', 'Crop'] as $raw) {
            [$exit, , $err] = self::drive(['--scale=' . $raw]);

            $this->assertSame(Cli::EXIT_USAGE, $exit, $raw);
            $this->assertStringNotContainsString('invalid value', $err, $raw);
        }
    }

    public function testScaleRejectsUnknownSpellings(): void
    {
        [$exit, , $err] = self::drive(['--scale=contain', 'x.png']);

        $this->assertSame(Cli::EXIT_USAGE, $exit);
        $this->assertStringContainsString("invalid value 'contain' for --scale", $err);
    }

    public function testDumpDetectExitsZeroWithTheStructuredReport(): void
    {
        [$exit, $out, $err] = self::drive(['--dump-detect']);

        $this->assertSame(Cli::EXIT_OK, $exit, $err);
        $this->assertSame('', $err);
        foreach (['terminal detection dump', 'env: TERM=', 'term-db: chain=', 'detect: sixel=', 'detect: summary=', 'cell-px:', 'default-fg:', 'da1-reply:', 'auto: protocol=', 'tmux-transaction:', 'palette:'] as $line) {
            $this->assertStringContainsString($line, $out);
        }
    }

    public function testDumpDetectHonoursThePassthroughDecisionItPrints(): void
    {
        [$exit, $out] = self::drive(['--dump-detect', '--passthrough=none']);

        $this->assertSame(Cli::EXIT_OK, $exit);
        $this->assertStringContainsString('tmux-transaction: disabled', $out);
    }

    public function testMissingFileExitsInputWithTheDecoderError(): void
    {
        $ghost = sys_get_temp_dir() . '/candy-image-definitely-absent.png';
        $this->assertFileDoesNotExist($ghost);

        [$exit, $out, $err] = self::drive([$ghost]);

        $this->assertSame(Cli::EXIT_INPUT, $exit);
        $this->assertNotSame('', $err, 'the decode error must be named on stderr');
        $this->assertSame('', $out);
    }

    public function testUndecodableFileExitsInput(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd is required to reach the decode refusal path.');
        }

        $path = tempnam(sys_get_temp_dir(), 'sc_candyimage_') . '.png';
        $this->artifacts[] = $path;
        file_put_contents($path, 'this is not an image');

        [$exit, , $err] = self::drive([$path]);

        $this->assertSame(Cli::EXIT_INPUT, $exit);
        $this->assertNotSame('', $err);
    }

    public function testAnimateMissingFileExitsInput(): void
    {
        [$exit, , $err] = self::drive(['--animate', '/nonexistent/candy-image-absent.gif']);

        $this->assertSame(Cli::EXIT_INPUT, $exit);
        $this->assertStringContainsString('no such file', strtolower($err));
    }
}

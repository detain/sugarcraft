<?php

/**
 * F-18: behavioural pins for the candy-image front end — usage/help exits,
 * the flag grammar (values, formats, scale, size box), the refusal surface
 * (chafa by design, symbols/screen-passthrough by unwired app-surface dial),
 * and the terminal-detection dump. The blocking render path is pinned in
 * RenderSmokeTest; the blocking animation loop never runs here — the $play
 * seam returns the wired Player.
 */

declare(strict_types=1);

namespace SugarCraft\CandyImage\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CandyImage\Cli;
use SugarCraft\Image\Detect;

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
        // F-18 kept --probe off the surface: Detect exposes no env-only
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

    public function testSymbolsFlagStaysRefusedThoughTheEngineLanded(): void
    {
        [$exit, , $err] = self::drive(['--symbols=braille', 'x.png']);

        $this->assertSame(Cli::EXIT_USAGE, $exit);
        $this->assertStringContainsString('--symbols is not available on this base', $err);
        $this->assertStringContainsString('deliberately does not wire the app-surface dial', $err);
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

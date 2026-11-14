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
use SugarCraft\Core\Util\Tty\Kernel32Interface;
use SugarCraft\Core\Util\Tty\PosixBackend;
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

    /**
     * One-component temp fixture path, created exclusively (candy-image-012:
     * the old tempnam()+suffix shape created a second file per run and
     * orphaned the zero-byte base — the canonical single-name form from
     * examples/candy-image.php, registered for tearDown before anything else).
     */
    private function uniqueTempPath(string $suffix): string
    {
        $path = sys_get_temp_dir() . '/candy-image-test-' . bin2hex(random_bytes(8)) . $suffix;
        $handle = fopen($path, 'x');
        self::assertIsResource($handle, "fixture path {$path} must be creatable exclusively");
        fclose($handle);
        $this->artifacts[] = $path;

        return $path;
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
    private static function drive(array $args, ?\Closure $play = null): array
    {
        [$stdout, $stderr] = self::pipes();
        $exit = Cli::main(array_merge(['candy-image'], $args), $stdout, $stderr, $play);

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
        $this->assertStringContainsString('chafa-independent', $err);
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

    public function testPassthroughNoneDisablesTheTransactionForItsRunOnly(): void
    {
        // m19 — the decision applies during the run (observed via the dump
        // surface) and the process-global is restored when main() returns.
        Detect::setAutoPassthrough(true);
        [$exit, $out] = self::drive(['--dump-detect', '--passthrough=none']);

        $this->assertSame(Cli::EXIT_OK, $exit);
        $this->assertStringContainsString('tmux-transaction: disabled', $out);
        $this->assertTrue(Detect::autoPassthrough(), 'a completed run must restore the ambient default');
    }

    public function testPassthroughAutoEnablesTheTransactionForItsRunOnly(): void
    {
        Detect::setAutoPassthrough(false);
        [$exit, $out] = self::drive(['--dump-detect', '--passthrough=auto']);

        $this->assertSame(Cli::EXIT_OK, $exit);
        $this->assertStringContainsString('tmux-transaction: enabled', $out);
        $this->assertFalse(Detect::autoPassthrough(), 'restoration goes both ways — a pre-existing disabled state must come back');
    }

    public function testBackToBackRunsNeverBleedThePassthroughDecision(): void
    {
        Detect::setAutoPassthrough(true);
        self::drive(['--dump-detect', '--passthrough=none']);
        $this->assertTrue(Detect::autoPassthrough());

        Detect::setAutoPassthrough(false);
        self::drive(['--dump-detect', '--passthrough=auto']);
        $this->assertFalse(Detect::autoPassthrough(), 'each run starts from the ambient setting, never the previous override');
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
        foreach (['--polite', '--polite=on', '--polite=off', '--polite='] as $spelling) {
            [$exit, , $err] = self::drive([$spelling]);

            $this->assertSame(Cli::EXIT_USAGE, $exit, $spelling);
            $this->assertStringContainsString('usage:', $err, $spelling);
            $this->assertStringNotContainsString('unknown option', $err, $spelling);
        }
    }

    public function testPoliteWithJunkValueIsRefused(): void
    {
        // n1 — the accept-all no-op must not swallow a typo'd value silently.
        [$exit, , $err] = self::drive(['--polite=jam']);

        $this->assertSame(Cli::EXIT_USAGE, $exit);
        $this->assertStringContainsString("invalid value 'jam' for --polite", $err);
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

        $path = $this->uniqueTempPath('.png');
        file_put_contents($path, 'this is not an image');

        [$exit, , $err] = self::drive([$path]);

        $this->assertSame(Cli::EXIT_INPUT, $exit);
        $this->assertNotSame('', $err);
    }

    public function testAnimateMissingFileExitsInput(): void
    {
        [$exit, , $err] = self::drive(['--animate', '/nonexistent/candy-image-absent.gif'], static fn (mixed $player): int => 0);

        $this->assertSame(Cli::EXIT_INPUT, $exit, 'the $play seam replaces the interactive loop, so no-terminal refusal must not shadow decode errors');
        $this->assertStringContainsString('no such file', strtolower($err));
    }

    public function testAnimateWithRenderDialsIsRefusedNamingEach(): void
    {
        [$exit, , $err] = self::drive(['--animate', '--work=9', '--format=half', '/nonexistent/candy-image-absent.gif']);

        $this->assertSame(Cli::EXIT_USAGE, $exit);
        $this->assertStringContainsString('--animate', $err);
        $this->assertStringContainsString('--work', $err);
        $this->assertStringContainsString('--format', $err);
    }

    public function testAnimateWithViewportBoxIsHonoredNotRefused(): void
    {
        [$exit, , $err] = self::drive(['--animate', '--size=40x12', '/nonexistent/candy-image-absent.gif'], static fn (mixed $player): int => 0);

        $this->assertSame(Cli::EXIT_INPUT, $exit, 'the box dials steer the animation path, so they must not join the refusal');
        $this->assertStringNotContainsString('ignores', $err);
    }

    /**
     * candy-image-004: with both standard streams redirected there is nobody
     * to steer the alternate-screen player — the pump spins CPU forever
     * (timeout 4 previously produced rc=124). The CLI route must refuse with
     * exit 1 instead. Runs as a child because the refusal reads the process's
     * own STDIN/STDOUT, which a pipe-open harness can shape but an in-process
     * test cannot.
     */
    public function testAnimateWithoutATerminalIsRefusedInsteadOfSpinning(): void
    {
        $cmd = [\PHP_BINARY, __DIR__ . '/../bin/candy-image', '--animate', '/nonexistent/candy-image-absent.gif'];
        $proc = \proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, \dirname(__DIR__));
        $this->assertIsResource($proc);
        \fclose($pipes[0]); // stdin reads EOF — a redirected pipe, not a terminal

        foreach ([1, 2] as $i) {
            \stream_set_timeout($pipes[$i], 10);
        }
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        $status = \proc_get_status($proc);
        if ($status['running']) {
            \proc_terminate($proc); // spinning-player regression guard: never hang the suite
        }
        $rc = \proc_close($proc);

        $this->assertSame(Cli::EXIT_USAGE, $rc, 'a pipe-bound --animate must refuse promptly, never spin (stdout was: ' . $out . ')');
        $this->assertStringContainsString('--animate', $err);
        $this->assertStringContainsString('terminal', $err);
    }

    public function testDumpDetectCannotEmitRawControlBytesFromHostileEnv(): void
    {
        $term = getenv('TERM');
        $colorterm = getenv('COLORTERM');
        putenv("TERM=\x1b]8;;http://evil\x07xterm-weird");
        putenv("COLORTERM=truecolor\x0a\x1b[31mfake");

        try {
            [$exit, $out] = self::drive(['--dump-detect']);
        } finally {
            putenv($term === false ? 'TERM' : "TERM=$term");
            putenv($colorterm === false ? 'COLORTERM' : "COLORTERM=$colorterm");
        }

        $this->assertSame(Cli::EXIT_OK, $exit);
        $this->assertStringContainsString('env: TERM=', $out, 'the field label survives the sweep');
        $this->assertStringNotContainsString("\x1b", $out, 'a hostile TERM must never emit a raw escape');
        $this->assertStringNotContainsString("\x07", $out, 'a forged OSC bell must die at the write choke');
    }

    public function testRefusalsSanitizeHostileArgvBytes(): void
    {
        [$exit, , $err] = self::drive(["--format=svg\x1b]8;;http://evil\x07", 'x.png']);

        $this->assertSame(Cli::EXIT_USAGE, $exit);
        $this->assertStringContainsString('unknown render format', $err);
        $this->assertStringNotContainsString("\x1b", $err, 'the echoed value must not carry raw escapes');
        $this->assertStringNotContainsString("\x07", $err);
    }

    public function testCellDimensionsAreCappedAtTheDocumentedCeiling(): void
    {
        // m18 — arbitrary digits used to sail past the parser into the
        // unclamped renderers, where the memory fatal exited 255 and broke
        // the documented exit-code contract.
        [$exit] = self::drive(['--width=' . Cli::MAX_CELL_DIM, '/nonexistent/candy-image-absent.png']);
        $this->assertSame(Cli::EXIT_INPUT, $exit, 'the ceiling itself stays a valid box leg');

        [$exit, , $err] = self::drive(['--width=' . (Cli::MAX_CELL_DIM + 1), '/nonexistent/candy-image-absent.png']);
        $this->assertSame(Cli::EXIT_USAGE, $exit);
        $this->assertStringContainsString('invalid value', $err);

        [$exit, , $err] = self::drive(['--width=99999999999999999999', 'x.png']);
        $this->assertSame(Cli::EXIT_USAGE, $exit, 'saturating digit runs must refuse as usage, not fatal at 255');
        $this->assertStringContainsString('invalid value', $err);

        [$exit, , $err] = self::drive(['--size=200000x12', 'x.png']);
        $this->assertSame(Cli::EXIT_USAGE, $exit);
        $this->assertStringContainsString('invalid value', $err);
    }

    /**
     * IMG-01 (candy-image-001) — the viewport ladder below the guarded size
     * ioctl. The phpunit run always pipes STDOUT, so the ioctl arm cannot be
     * entered in-process; what IS reachable is every rung the throw falls
     * through to, which is the exact rescue path Windows/FFI-less/BSD hosts
     * take when the query throws before this fix turned them into exit 255.
     *
     * @return array{string|false, string|false}
     */
    private function stashColumnsLines(): array
    {
        $stashed = [getenv('COLUMNS'), getenv('LINES')];
        putenv('COLUMNS');
        putenv('LINES');

        return $stashed;
    }

    /** @param array{string|false, string|false} $stashed */
    private function restoreColumnsLines(array $stashed): void
    {
        [$columns, $lines] = $stashed;
        putenv($columns === false ? 'COLUMNS' : 'COLUMNS=' . $columns);
        putenv($lines === false ? 'LINES' : 'LINES=' . $lines);
    }

    public function testViewportFallsBackToFixedCellsWhenNoSignalExists(): void
    {
        $stashed = $this->stashColumnsLines();
        try {
            // Pipe STDOUT (phpunit), no env: rung 3 of the ladder.
            $this->assertSame(
                [Cli::FALLBACK_CELLS_W, Cli::FALLBACK_CELLS_H],
                Cli::viewportCells(),
                'the documented 60x24 fallback answers when neither ioctl nor env speaks',
            );
        } finally {
            $this->restoreColumnsLines($stashed);
        }
    }

    public function testViewportPrefersColumnsLinesOverTheFixedFallback(): void
    {
        $stashed = $this->stashColumnsLines();
        putenv('COLUMNS=123');
        putenv('LINES=45');
        try {
            // The rows-1 status allowance is the animate path's contract on
            // every live-geometry rung, env-derived included.
            $this->assertSame([123, 44], Cli::viewportCells());
        } finally {
            $this->restoreColumnsLines($stashed);
        }
    }

    public function testViewportIgnoresHalfAColumnsLinesPair(): void
    {
        $stashed = $this->stashColumnsLines();
        putenv('COLUMNS=123');
        putenv('LINES=0');
        try {
            $this->assertSame(
                [Cli::FALLBACK_CELLS_W, Cli::FALLBACK_CELLS_H],
                Cli::viewportCells(),
                'a zero or missing leg means the pair speaks nothing — never a half-trusted box',
            );
        } finally {
            $this->restoreColumnsLines($stashed);
        }
    }

    public function testTheGuardedQueryIsARealThrowSourceAndNeverEscapesTheLadder(): void
    {
        $stashed = $this->stashColumnsLines();
        try {
            // The hazard is not hypothetical: the callee documents its throw
            // (fd-not-tty here; Windows/FFI-less hosts throw deeper inside it)
            // and it fires raw...
            $threw = false;
            try {
                PosixBackend::queryWinsize(1);
            } catch (\Throwable) {
                $threw = true;
            }
            $this->assertTrue($threw, 'PosixBackend::queryWinsize(1) must throw on the piped phpunit STDOUT — the guard covers a live hazard');

            // ...yet the app surface never lets it escape: cells land finite.
            $cells = Cli::viewportCells();
            $this->assertGreaterThan(0, $cells[0]);
            $this->assertGreaterThan(0, $cells[1]);

            // A full main() run exits in {0,1,2} — never 255 — with the
            // ladder answering for the box.
            [$exit] = self::drive(['/nonexistent/candy-image-absent.png']);
            $this->assertContains($exit, [Cli::EXIT_OK, Cli::EXIT_USAGE, Cli::EXIT_INPUT]);
        } finally {
            $this->restoreColumnsLines($stashed);
        }
    }

    /**
     * A userland stream whose writes are always accepted, but only half at
     * a time: the loop drives every payload to a deterministic 1-byte
     * shortfall with zero warnings — the delivery-accounting contract
     * without a pipe-buffer race.
     */
    private static function halfDeliveringStream()
    {
        $definition = <<<'PHP'
            final class CandyImageHalfWriter
            {
                public $context;

                public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
                {
                    return true;
                }

                public function stream_write(string $data): int
                {
                    return (int) floor(strlen($data) / 2);
                }

                public function stream_close(): void
                {
                }
            }
            PHP;
        if (!class_exists('CandyImageHalfWriter', false)) {
            eval($definition);
        }

        $unique = 'cimg-half-' . bin2hex(random_bytes(4));
        $registered = stream_wrapper_register($unique, 'CandyImageHalfWriter');
        self::assertTrue($registered);
        $stream = fopen($unique . '://render', 'w');
        self::assertIsResource($stream);

        return $stream;
    }

    public function testStillRenderWithShortDeliveryExitsDeliveryFailure(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd is required to mint the fixture.');
        }

        $path = $this->uniqueTempPath('.png');
        $im = imagecreatetruecolor(16, 8);
        imagefilledrectangle($im, 0, 0, 15, 7, imagecolorallocate($im, 10, 120, 200));
        imagepng($im, $path);

        [, $stderr] = self::pipes();
        $exit = Cli::main(['candy-image', '--format=ascii', $path], self::halfDeliveringStream(), $stderr);

        $this->assertSame(Cli::EXIT_INPUT, $exit, 'a render that does not fully land must not exit 0');
        $this->assertStringContainsString('write failed', self::drain($stderr));
    }

    public function testDumpDetectWithShortDeliveryExitsDeliveryFailure(): void
    {
        [, $stderr] = self::pipes();
        $exit = Cli::main(['candy-image', '--dump-detect'], self::halfDeliveringStream(), $stderr);

        $this->assertSame(Cli::EXIT_INPUT, $exit);
        $err = self::drain($stderr);
        $this->assertStringContainsString('write failed', $err);
        $this->assertLessThanOrEqual(1, substr_count($err, 'write failed'), 'once the reader is gone the dump stops stacking shortfalls');
    }

    /**
     * The candy-image-011 repro: a huge render piped to a reader that stops
     * early. PHP ignores SIGPIPE, so the child learns about the closed
     * reader only through the delivery accounting — exit 2 with
     * 'write failed' on stderr. If the machine finishes the whole payload
     * into the pipe before the reader closes, there is nothing to fail and
     * the test skips.
     */
    public function testRenderIntoEarlyClosedPipeExitsNonzero(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd is required to mint the fixture.');
        }

        $path = $this->uniqueTempPath('.png');
        $im = imagecreatetruecolor(64, 48);
        imagefilledrectangle($im, 0, 0, 63, 47, imagecolorallocate($im, 200, 30, 90));
        imagepng($im, $path);

        $proc = proc_open(
            [\PHP_BINARY, __DIR__ . '/../bin/candy-image', '--format=ascii', '--size=2000x1500', $path],
            ['1' => ['pipe', 'w'], '2' => ['pipe', 'w']],
            $pipes,
            \dirname(__DIR__),
        );
        self::assertIsResource($proc);

        // Close the read end while the payload is still in flight.
        $seen = (string) stream_get_contents($pipes[1], 4096);
        fclose($pipes[1]);

        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $status = proc_get_status($proc);
        if ($status['running']) {
            proc_terminate($proc);
        }
        $rc = proc_close($proc);

        if ($rc === 0) {
            $this->markTestSkipped('the whole payload was delivered before the reader closed; nothing to fail on this run');
        }

        $this->assertSame(Cli::EXIT_INPUT, $rc, 'truncated delivery must exit 2 — stderr was: ' . $stderr . ' — stdout prefix was: ' . $seen);
        $this->assertStringContainsString('write failed', $stderr);
    }

    /**
     * IMG-14 (candy-image-023): the arm-selection predicate is the whole
     * Linux/macOS safety story, so its truth table is pinned in-process.
     */
    public function testConsoleCodepageIsArmedOnlyOnWindowsConsoles(): void
    {
        $this->assertTrue(Cli::shouldArmConsoleCodepage(true, true));
        $this->assertFalse(Cli::shouldArmConsoleCodepage(true, false), 'redirected output has no console codepage to arm');
        $this->assertFalse(Cli::shouldArmConsoleCodepage(false, true), 'POSIX consoles do not speak CP65001');
        $this->assertFalse(Cli::shouldArmConsoleCodepage(false, false));
    }

    /**
     * IMG-14: the capture/arm/restore sequence, driven through a fake
     * seam so it is exercised on every host (same injection style as
     * sugar-core's own WindowsBackend tests).
     */
    public function testCodepageArmCapturesPreviousPageAndRestoresIt(): void
    {
        $seen = [];
        $kernel32 = $this->createMock(Kernel32Interface::class);
        $kernel32->method('getConsoleOutputCP')->willReturnCallback(static function () use (&$seen): int {
            $seen[] = 'get';

            return 850;
        });
        $kernel32->method('setConsoleOutputCP')->willReturnCallback(static function (int $cp) use (&$seen): bool {
            $seen[] = $cp;

            return true;
        });

        $restore = $this->armConsoleCodepage($kernel32);
        $this->assertNotNull($restore, 'a successful arm must hand back a restore closure');
        $this->assertSame(['get', 65001], $seen, 'the arm must read the previous page before switching to UTF-8');

        $restore();
        $this->assertSame(['get', 65001, 850], $seen, 'restore must put back exactly the captured page');
    }

    public function testFailedCodepageArmReportsUnarmedAndNeverClaimsARestore(): void
    {
        $kernel32 = $this->createMock(Kernel32Interface::class);
        $kernel32->method('getConsoleOutputCP')->willReturn(437);
        $kernel32->expects($this->once())->method('setConsoleOutputCP')->willReturn(false);

        $this->assertNull($this->armConsoleCodepage($kernel32), 'a refused arm has nothing to undo — reporting a restore closure would promise a page switch that never happened');
    }

    /**
     * IMG-14: on this (non-Windows) host the gated helper must short-circuit
     * to null without resolving the Kernel32 seam at all.
     */
    public function testWindowsGateShortCircuitsOffWindows(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('this arm pins the non-Windows short circuit');
        }

        $method = new \ReflectionMethod(Cli::class, 'armWindowsOutputCodepage');
        $method->setAccessible(true);

        $this->assertNull($method->invoke(null));
    }

    /**
     * IMG-14 structural: the still-write block must actually consult the
     * gate and restore in a finally — the behavioural arms above prove
     * the pieces, this pins their wiring into main().
     */
    public function testStillRenderPathWiresTheCodepageArmAroundTheWrites(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../src/Cli.php');

        $this->assertMatchesRegularExpression(
            '/armWindowsOutputCodepage\(\);\s*try\s*\{.*?writePayload\(\$stdout, \$bytes\).*?finally\s*\{\s*if \(\$restoreCodepage !== null\)/s',
            $source,
            'the payload writes must sit between the arm and its finally-restore'
        );
        $this->assertMatchesRegularExpression(
            '/if \(!self::shouldArmConsoleCodepage\(\\\\PHP_OS_FAMILY === \'Windows\'[^\n]*\n\s*return null;/s',
            $source,
            'the gated helper must return null the moment the predicate refuses'
        );
    }

    /**
     * IMG-15 (candy-image-025): the env line must carry the whole roster
     * the chain consults, with absent/empty distinguished.
     */
    public function testDumpDetectPrintsTheFullEvidenceTable(): void
    {
        putenv('WEZTERM_EXECUTABLE=/probe/wezterm-bin');
        putenv('LC_TERMINAL=');

        try {
            [$exit, $out] = self::drive(['--dump-detect']);
        } finally {
            putenv('WEZTERM_EXECUTABLE');
            putenv('LC_TERMINAL');
        }

        $this->assertSame(Cli::EXIT_OK, $exit);
        $this->assertStringContainsString('env: TERM=', $out);
        $this->assertStringContainsString('TERM_PROGRAM=', $out, 'emulator-family evidence must travel with the diagnostic');
        $this->assertStringContainsString('TMUX=', $out);
        $this->assertStringContainsString('ComSpec=', $out);
        $this->assertStringContainsString('WEZTERM_EXECUTABLE=/probe/wezterm-bin', $out, 'a present variable must print its value verbatim');
        $this->assertStringContainsString('LC_TERMINAL=(empty)', $out, 'set-but-empty must not blur into unset');
        $this->assertMatchesRegularExpression('/env: [^\n]*(\(?unset\)?|\(?empty\)?)/', $out, 'absent roster entries must be visible as (unset)');
    }

    /**
     * IMG-15 (candy-image-032): a silently-echoing terminal leaves the
     * DA1 reply as '' — that must not read as an answer.
     */
    public function testDumpDetectReadsASilentDa1EchoAsNone(): void
    {
        $reply = new \ReflectionProperty(Detect::class, 'lastDa1Reply');
        $reply->setAccessible(true);
        $reply->setValue(null, '');

        try {
            [$exit, $out] = self::drive(['--dump-detect']);
        } finally {
            Detect::reset();
        }

        $this->assertSame(Cli::EXIT_OK, $exit);
        $this->assertStringContainsString('da1-reply: (none)', $out, 'an empty echo is not an answer');
    }

    private function armConsoleCodepage(Kernel32Interface $kernel32): ?callable
    {
        $method = new \ReflectionMethod(Cli::class, 'armConsoleCodepage');
        $method->setAccessible(true);

        /** @var null|callable():void $restore */
        $restore = $method->invoke(null, $kernel32);

        return $restore;
    }
}

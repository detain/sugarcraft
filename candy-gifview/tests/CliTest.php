<?php

declare(strict_types=1);

namespace SugarCraft\GifView\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Animation\Decoder;
use SugarCraft\Animation\Player;
use SugarCraft\Animation\Renderer;
use SugarCraft\GifView\Cli;

/**
 * Behavioural pins for the viewer front end: usage/help exits, decode-failure
 * exits, and the argv → Player wiring (preset selection). The blocking Program
 * loop is never entered — the $play seam returns the constructed Player.
 */
final class CliTest extends TestCase
{
    /** @var list<string> */
    private array $artifacts = [];

    protected function tearDown(): void
    {
        foreach ($this->artifacts as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->artifacts = [];
    }

    /**
     * @return array{resource, resource} fresh in-memory out/err pair
     */
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

    #[DataProvider('helpFlags')]
    public function testHelpExitsZeroWithUsageOnStdout(string $flag): void
    {
        [$stdout, $stderr] = self::pipes();

        $exit = Cli::main(['candy-gifview', $flag], $stdout, $stderr);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('usage:', self::drain($stdout));
        $this->assertStringContainsString('candy-gifview', self::drain($stdout));
        $this->assertSame('', self::drain($stderr));
    }

    /** @return array<string, array{string}> */
    public static function helpFlags(): array
    {
        return ['--help' => ['--help'], '-h' => ['-h']];
    }

    public function testMissingArgumentExitsOneWithUsageOnStderr(): void
    {
        [$stdout, $stderr] = self::pipes();

        $exit = Cli::main(['candy-gifview'], $stdout, $stderr);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('usage:', self::drain($stderr));
        $this->assertSame('', self::drain($stdout));
    }

    public function testMissingFileExitsOneWithDecoderError(): void
    {
        [$stdout, $stderr] = self::pipes();

        $exit = Cli::main(['candy-gifview', '/nonexistent/candy-gifview-test-absent.gif'], $stdout, $stderr);

        $this->assertSame(1, $exit);
        // The decoder's own wording (a sugar-animation library string) may change;
        // "no such file" is the fragment the CLI contract keeps stable.
        $this->assertStringContainsString('no such file', self::drain($stderr));
        $this->assertSame('', self::drain($stdout));
    }

    public function testTheVersionResolverFallsBackOffAnyNonPharPath(): void
    {
        // No archive, a missing path, and an existing-but-not-a-phar file all
        // answer with the compiled constant — never a throw (corrupt archives
        // must not crash --version).
        $this->assertSame(\SugarCraft\GifView\Support\Version::CURRENT, \SugarCraft\GifView\Support\Version::resolve(''));
        $this->assertSame(\SugarCraft\GifView\Support\Version::CURRENT, \SugarCraft\GifView\Support\Version::resolve('/definitely/not/a/phar/' . \uniqid('sc_gifview_', true)));

        $plain = (string) tempnam(sys_get_temp_dir(), 'sc_gifview_notphar_');
        $this->artifacts[] = $plain;
        \file_put_contents($plain, 'not a phar at all');

        $this->assertSame(\SugarCraft\GifView\Support\Version::CURRENT, \SugarCraft\GifView\Support\Version::resolve($plain));
    }

    #[DataProvider('presetTokens')]
    public function testWiredPlayerCarriesRequestedPreset(array $extraArgs, string $expectedPreset): void
    {
        $gif = $this->gifFixture();
        [$stdout, $stderr] = self::pipes();

        $seen = null;
        $exit = Cli::main(
            array_merge(['candy-gifview', $gif], $extraArgs),
            $stdout,
            $stderr,
            static function (Player $player) use (&$seen): int {
                $seen = $player;

                return 0;
            },
        );

        $this->assertSame(0, $exit, self::drain($stderr));
        $this->assertInstanceOf(Player::class, $seen);
        $this->assertSame($expectedPreset, $seen->preset);
        $this->assertNotEmpty($seen->frames, 'the fixture GIF must decode to at least one frame');
        $this->assertInstanceOf(Renderer::class, $seen->renderer);
    }

    /** @return array<string, array{list<string>, string}> */
    public static function presetTokens(): array
    {
        return [
            'default is solid'  => [[], Renderer::PRESET_SOLID],
            'density'           => [['density'], Renderer::PRESET_DENSITY],
        ];
    }

    /**
     * The preset slot accepts exactly the two documented presets; a third
     * spelling is a refusal, never a silent fall back to solid.
     */
    public function testUnknownPresetIsRefusedLoudly(): void
    {
        $gif = $this->gifFixture();
        [$stdout, $stderr] = self::pipes();

        $exit = Cli::main(['candy-gifview', $gif, 'wat'], $stdout, $stderr,
            static fn (Player $p): int => 0);

        $this->assertSame(1, $exit);
        $err = self::drain($stderr);
        $this->assertStringContainsString("unknown preset 'wat'", $err);
        $this->assertStringContainsString('usage:', $err);
        $this->assertSame('', self::drain($stdout));
    }

    /**
     * Only the double-dash spellings are options; -fps=10 must refuse as an
     * unknown option instead of being swallowed as a file path.
     */
    public function testSingleDashLongOptionsAreRefused(): void
    {
        $gif = $this->gifFixture();
        [$stdout, $stderr] = self::pipes();

        $exit = Cli::main(['candy-gifview', $gif, '-fps=10'], $stdout, $stderr,
            static fn (Player $p): int => 0);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('unknown option --fps', self::drain($stderr));
    }

    /**
     * The lone `-` (stdin convention) is not special to this front end —
     * it keeps its exemption from the dash-refusal hatch, rides the
     * positional arm, and must be reported as the unopenable path it is
     * (gifview-019).
     */
    public function testTheLoneDashIsRefusedAsAPathNotSwallowed(): void
    {
        [$stdout, $stderr] = self::pipes();

        $exit = Cli::main(['candy-gifview', '-'], $stdout, $stderr);

        $this->assertSame(1, $exit);
        $err = self::drain($stderr);
        $this->assertStringContainsString("could not play '-'", $err,
            'the dash rides the positional arm and fails honestly as a path');
        $this->assertStringNotContainsString('unknown option', $err,
            'the dash-only token never enters the flag-refusal hatch');
        $this->assertSame('', self::drain($stdout));
    }

    /**
     * The standalone `--` ends option parsing (gifview-021): what follows is
     * positional, so files literally named -foo.gif are reachable and flag
     * spellings after the hatch are ordinary paths.
     */
    public function testTheEndOfOptionsHatchMakesLaterTokensPositional(): void
    {
        [$stdout, $stderr] = self::pipes();

        $exit = Cli::main(['candy-gifview', '--', '-foo.gif'], $stdout, $stderr);

        $this->assertSame(1, $exit);
        $err = self::drain($stderr);
        $this->assertStringContainsString("could not play '-foo.gif'", $err,
            'the escaped name reaches the intake as an ordinary path');
        $this->assertStringNotContainsString('unknown option', $err);

        [$stdout2, $stderr2] = self::pipes();
        $exit2 = Cli::main(['candy-gifview', '--', '--watch'], $stdout2, $stderr2);

        $this->assertSame(1, $exit2);
        $err2 = self::drain($stderr2);
        $this->assertStringContainsString("could not play '--watch'", $err2,
            'even flag-shaped tokens after the hatch are paths');
        $this->assertStringNotContainsString('takes no value', $err2);
        $this->assertSame('', self::drain($stdout));
        $this->assertSame('', self::drain($stdout2));
    }

    /**
     * `--=x` (empty option name) must get its own message instead of the
     * degenerate `unknown option --` echo (gifview-021).
     */
    public function testTheEmptyOptionNameGetsItsOwnRefusal(): void
    {
        [$stdout, $stderr] = self::pipes();

        $exit = Cli::main(['candy-gifview', '--=x'], $stdout, $stderr);

        $this->assertSame(1, $exit);
        $err = self::drain($stderr);
        $this->assertStringContainsString('missing option name', $err);
        $this->assertStringNotContainsString('unknown option --', $err,
            'the degenerate empty-flag echo is dead');
        $this->assertStringContainsString('usage:', $err, 'the usage banner still follows');
        $this->assertSame('', self::drain($stdout));
    }

    public function testMissingFileIsWrappedInTheAppCannotPlayLine(): void
    {
        [$stdout, $stderr] = self::pipes();
        $path = '/nonexistent/candy-gifview-wrap-absent.gif';

        $exit = Cli::main(['candy-gifview', $path], $stdout, $stderr);

        $this->assertSame(1, $exit);
        $err = self::drain($stderr);
        $this->assertStringContainsString('could not play', $err, 'the CLI speaks in its own brand');
        $this->assertStringContainsString($path, $err, 'the refused path is echoed back');
        $this->assertStringContainsString('no such file', $err, 'the engine reason survives the wrap');
        $this->assertSame('', self::drain($stdout));
    }

    /**
     * gifview-16: a character device is not a file — the is_file() gate
     * ahead of the open refuses it with the engine's own wording, never a
     * blocking read and never a slurp.
     */
    public function testADevicePathIsRefusedByTheIsFileGate(): void
    {
        if (!\file_exists('/dev/null')) {
            $this->markTestSkipped('/dev/null is absent on this host; nothing to refuse.');
        }

        [$stdout, $stderr] = self::pipes();

        $exit = Cli::main(['candy-gifview', '/dev/null'], $stdout, $stderr);

        $this->assertSame(1, $exit);
        $err = self::drain($stderr);
        $this->assertStringContainsString('no such file', $err, 'the engine refusal wording is kept');
        $this->assertStringContainsString('/dev/null', $err);
    }

    /**
     * gifview-16: a FIFO is neither a regular file nor openable for a
     * bounded read — it must die at the same is_file() gate, not block on
     * a writer-less open.
     */
    public function testAFifoIsRefusedByTheIsFileGate(): void
    {
        if (!\function_exists('posix_mkfifo')) {
            $this->markTestSkipped('no posix_mkfifo on this host; pin would be vacuous.');
        }

        $path = \tempnam(sys_get_temp_dir(), 'sc_gifview_fifo_');
        $this->assertIsString($path);
        $this->assertTrue(@unlink($path), 'tempnam file must be removable before mkfifo');
        if (!@posix_mkfifo($path, 0600)) {
            $this->markTestSkipped('posix_mkfifo refused on this host/filesystem.');
        }
        $this->artifacts[] = $path;

        [$stdout, $stderr] = self::pipes();

        $exit = Cli::main(['candy-gifview', $path], $stdout, $stderr);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no such file', self::drain($stderr));
    }

    /**
     * The size gate is the BOUNDED READ, not a filesize() promise (gifview-16):
     * the intake must derive at most MAX_INPUT_BYTES + 1 bytes and decide on
     * the length seen — a check-then-read ladder lets a growing file slip
     * past, and a filesize() the host cannot express (32-bit over 2 GiB)
     * used to skip the gate open. Token-pin on the real source: reverting
     * to the old filesize()/readFile() shapes reddens here even when a
     * lucky host makes the behavioural arms agree.
     */
    public function testTheIntakeGatesOnTheBoundedReadNotOnFilesize(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../src/Cli.php');
        $start = strpos($src, 'private static function decodeFrames(');
        $this->assertIsInt($start, 'decodeFrames() moved or renamed — update the pin');
        $body = substr($src, $start);
        $braceEnd = strpos($body, "\n    }");
        $this->assertIsInt($braceEnd);
        $body = substr($body, 0, $braceEnd);

        $this->assertStringContainsString(
            'stream_get_contents($handle, self::MAX_INPUT_BYTES + 1)',
            $body,
            'the intake lost its bounded read — the cap must ride the read itself',
        );
        $this->assertStringContainsString('strlen($bytes) > self::MAX_INPUT_BYTES', $body,
            'the refusal must decide on the length actually read');
        $this->assertStringNotContainsString('filesize', $body,
            'a filesize() pre-check is the fail-open ladder gifview-16 retired');
        $this->assertStringNotContainsString('Decoder::readFile', $body,
            'readFile() check-then-reads internally — the bounded read belongs here');
        $this->assertStringContainsString("Lang::t('decoder.no_file'", $body,
            'non-regular paths keep the engine is_file() refusal wording');
    }

    public function testAnEmptyFrameListIsRefusedLoudly(): void
    {
        [$stdout, $stderr] = self::pipes();

        $exit = Cli::main(
            ['candy-gifview', 'empty.gif'],
            $stdout,
            $stderr,
            null,
            null,
            static fn (string $p, int $w, int $h): array => [[], null],
        );

        $this->assertSame(1, $exit, 'a zero-frame intake never opens the TUI');
        $err = self::drain($stderr);
        $this->assertStringContainsString('no frames to play', $err);
        $this->assertStringContainsString('empty.gif', $err);
        $this->assertSame('', self::drain($stdout));
    }

    public function testDurationOnASingleFrameAnimationIsRefused(): void
    {
        $gif = $this->gifFixture();
        [$stdout, $stderr] = self::pipes();

        $exit = Cli::main(['candy-gifview', $gif, '--duration=2'], $stdout, $stderr,
            static fn (Player $p): int => 0);

        $this->assertSame(1, $exit, 'the engine deadline can never elapse on one frame — refuse instead');
        $this->assertStringContainsString('needs at least two frames', self::drain($stderr));
    }

    public function testOversizedFilesAreRefusedBeforeTheyAreBuffered(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sc_gifview_big_') . '.gif';
        $this->artifacts[] = $path;
        $handle = fopen($path, 'a');
        $this->assertIsResource($handle);
        $this->assertTrue(ftruncate($handle, Cli::MAX_INPUT_BYTES + 1), 'sparse 64 MiB+1 file');
        fclose($handle);

        [$stdout, $stderr] = self::pipes();
        $exit = Cli::main(['candy-gifview', $path], $stdout, $stderr,
            static fn (Player $p): int => 0);

        $this->assertSame(1, $exit, self::drain($stderr));
        $err = self::drain($stderr);
        $this->assertStringContainsString('intake cap', $err);
        $this->assertStringContainsString('could not play', $err);
        $this->assertSame('', self::drain($stdout));
    }

    public function testViewportCellsUsesTheDocumentedFallbackOffATty(): void
    {
        // The escape hatch would legitimately override the probe, so this
        // pin needs the environment silent — capture, clear, restore.
        $savedColumns = \getenv('COLUMNS');
        $savedLines = \getenv('LINES');
        \putenv('COLUMNS');
        \putenv('LINES');

        try {
            [$cellsW, $cellsH] = Cli::viewportCells();

            if (!\is_resource(\STDOUT) || !stream_isatty(STDOUT)) {
                // phpunit/CI runs are piped — the deterministic branch.
                $this->assertSame(Cli::FALLBACK_CELLS_W, $cellsW);
                $this->assertSame(Cli::FALLBACK_CELLS_H, $cellsH);

                return;
            }

            // Interactive run: sized from the real terminal, status line reserved.
            $this->assertGreaterThan(0, $cellsW);
            $this->assertGreaterThan(0, $cellsH);
        } finally {
            \putenv($savedColumns === false ? 'COLUMNS' : "COLUMNS=$savedColumns");
            \putenv($savedLines === false ? 'LINES' : "LINES=$savedLines");
        }
    }

    public function testVersionFlagsPrintCurrentAheadOfTheGdPreflight(): void
    {
        // Outside an archive the answer is CURRENT verbatim — and it must be
        // printed before the capability refusal can possibly fire: even an
        // image-hostile host has to be able to ask for its version.
        foreach (['--version', '-v'] as $flag) {
            [$stdout, $stderr] = self::pipes();
            $exit = Cli::main(['candy-gifview', $flag], $stdout, $stderr);

            $this->assertSame(0, $exit, self::drain($stderr));
            $this->assertSame("candy-gifview 0.1.0\n", self::drain($stdout), $flag);
            $this->assertSame('', self::drain($stderr), $flag);
        }

        // The ordering itself is the contract; on a gd-equipped host the
        // in-process arms above cannot prove precedence, so pin the layout.
        $src = (string) file_get_contents(__DIR__ . '/../src/Cli.php');
        $this->assertLessThan(
            strpos($src, "if (!\\extension_loaded('gd')) {"),
            strpos($src, "token === '--version'"),
            "the --version arm drifted behind the gd preflight — archives must answer it everywhere",
        );
    }

    private function gifFixture(): string
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd is required to decode the fixture GIF.');
        }

        $path = tempnam(sys_get_temp_dir(), 'sc_gifview_cli_') . '.gif';
        $this->artifacts[] = $path;

        $im = imagecreatetruecolor(4, 4);
        imagefill($im, 0, 0, imagecolorallocate($im, 10, 20, 30));
        imagegif($im, $path);
        imagedestroy($im);
        $this->assertGreaterThan(0, filesize($path), 'GD wrote an empty GIF');

        return $path;
    }

    /**
     * Repeat a single-frame GD GIF's frame block n times, prepending a fresh
     * Graphics Control Extension to each copy (GD's imagegif output carries
     * no GCE, so the repeated blocks would otherwise share one delay). The
     * header is 13 bytes plus the global colour table when present; the
     * frame block spans header-end to the final 0x3B trailer.
     */
    private static function assembleGif(string $single, int $n): string
    {
        $flags = ord($single[10]);
        $headerEnd = 13 + (($flags & 0x80) !== 0 ? 3 * (2 << ($flags & 7)) : 0);
        $trailerAt = strrpos($single, ';');
        self::assertIsInt($trailerAt, 'GD output must end its block chain with the 0x3B trailer');

        $block = substr($single, $headerEnd, $trailerAt - $headerEnd);
        $gce = "\x21\xF9\x04\x04" . pack('v', 4) . "\x00\x00";

        return substr($single, 0, $headerEnd) . str_repeat($gce . $block, $n) . "\x3B";
    }

    /** A fixture GIF decoded to exactly $n frames (n=1 is the bare GD file). */
    private function gifFixtureFrames(int $n): string
    {
        $single = $this->gifFixture();
        if ($n === 1) {
            return $single;
        }

        $path = tempnam(sys_get_temp_dir(), 'sc_gifview_multi_') . '.gif';
        $this->artifacts[] = $path;
        file_put_contents($path, self::assembleGif((string) file_get_contents($single), $n));

        return $path;
    }

    /**
     * E-09 seam probe: run main() with the given extra argv through the
     * $play seam and return the wired Player (failing on non-zero exit).
     *
     * @param list<string> $args flags/positions after the script name, the
     *        GIF path placeholder {gif} substituted with the fixture.
     */
    private function wiredPlayer(array $args, int $frames = 1): Player
    {
        $gif = $this->gifFixtureFrames($frames);
        [$stdout, $stderr] = self::pipes();

        $seen = null;
        $exit = Cli::main(
            array_merge(
                ['candy-gifview'],
                array_map(static fn (string $a): string => str_replace('{gif}', $gif, $a), $args),
            ),
            $stdout,
            $stderr,
            static function (Player $player) use (&$seen): int {
                $seen = $player;

                return 0;
            },
        );

        $this->assertSame(0, $exit, self::drain($stderr));

        return $seen;
    }

    public function testE09FpsFlagWiresTheFixedCadence(): void
    {
        $player = $this->wiredPlayer(['{gif}', '--fps=24']);
        $this->assertSame(24.0, $player->fixedFps);
        $this->assertNull($player->maxSeconds);
    }

    public function testE09SpeedMultiplierAnchorsOnTheDecodedNominalRate(): void
    {
        // sugar-animation exposes no per-frame multiplier (only the
        // fixed-cadence seam), so --speed scales the GIF's own nominal fps
        // derived from the decoded frames. Literal-pinned (gifview-009 — the
        // old assert re-implemented nominalFps() inline and could never
        // disagree with it): the bare GD single-frame fixture carries no GCE
        // and decodes at the engine default delay of 10 cs, so
        // nominal = 1 / (10/100) = 10.0 fps, doubled by --speed=2 → 20.0.
        $player = $this->wiredPlayer(['{gif}', '--speed=2']);
        $this->assertSame(20.0, $player->fixedFps);

        // assembleGif stamps every added GCE with delay 4 cs, so the
        // two-frame fixture answers nominal = 2 / ((4+4)/100) = 25.0 fps,
        // doubled → 50.0. A /100 → /1000 mutation in nominalFps() reddens
        // both literals 10x over; the readable constant-comparison failure
        // is the point.
        $player = $this->wiredPlayer(['{gif}', '--speed=2'], 2);
        $this->assertSame(50.0, $player->fixedFps);
    }

    public function testE09SpeedMaxSaturatesAtThePlayerCeiling(): void
    {
        $player = $this->wiredPlayer(['{gif}', '--speed=max']);
        $this->assertSame(Player::MAX_FPS, $player->fixedFps);
    }

    public function testE09SpeedFpsSuffixIsAnAbsoluteRate(): void
    {
        $player = $this->wiredPlayer(['{gif}', '--speed=5fps']);
        $this->assertSame(5.0, $player->fixedFps);
    }

    public function testE09DurationWiresTheModelDeadline(): void
    {
        // Two frames: --duration on a single-frame file is a loud refusal
        // (the engine deadline can never elapse), not a wiring question.
        $player = $this->wiredPlayer(['{gif}', '--duration=3.5'], 2);
        $this->assertSame(3.5, $player->maxSeconds);
        $this->assertNull($player->fixedFps, 'duration alone keeps the GIF cadence');
    }

    public function testE09FlagsAndPositionalsMixInAnyOrder(): void
    {
        $player = $this->wiredPlayer(['--fps=10', '--duration=2', '{gif}', 'density'], 2);
        $this->assertSame(10.0, $player->fixedFps);
        $this->assertSame(2.0, $player->maxSeconds);
        $this->assertSame(Renderer::PRESET_DENSITY, $player->preset);
    }

    /** @return array<string, array{string, string}> */
    public static function refusalCases(): array
    {
        return [
            'unknown flag'  => ['--bogus=now', 'unknown option'],
            'zero fps'      => ['--fps=0', 'invalid value'],
            'word speed'    => ['--speed=lots', 'invalid value'],
            'empty value'   => ['--duration=', 'invalid value'],
            'negative time' => ['--duration=-2', 'invalid value'],
        ];
    }

    #[DataProvider('refusalCases')]
    public function testE09RefusalsExitOneWithTheNamedComplaintOnStderr(string $flag, string $needle): void
    {
        $gif = $this->gifFixture();
        [$stdout, $stderr] = self::pipes();

        $exit = Cli::main(['candy-gifview', $gif, $flag], $stdout, $stderr,
            static fn (Player $p): int => 0);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString($needle, self::drain($stderr));
        $this->assertSame('', self::drain($stdout), 'refusals never write to stdout');
    }

    public function testE09ThirdPositionalIsRefused(): void
    {
        $gif = $this->gifFixture();
        [$stdout, $stderr] = self::pipes();

        $exit = Cli::main(['candy-gifview', $gif, 'density', 'wat'], $stdout, $stderr,
            static fn (Player $p): int => 0);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('unexpected argument', self::drain($stderr));
    }

    public function testE09NoFlagsLeaveThePlayerUntouched(): void
    {
        $player = $this->wiredPlayer(['{gif}']);
        $this->assertNull($player->fixedFps);
        $this->assertNull($player->maxSeconds);
    }

    /**
     * The outer belt constant is a documented promise: the README "Limits"
     * section names 4096 and the whole animation is materialised below it.
     * The engine window (Decoder::MAX_FRAMES) truncates long files before
     * this belt can fire — the truncation pin below proves the honest
     * end-to-end shape.
     */
    public function testTheFrameCeilingIsTheDocumentedConstant(): void
    {
        $this->assertSame(4_096, Cli::MAX_FRAMES);
    }

    /**
     * collectFrames() drains lazily and refuses one frame past the cap, so
     * an over-cap file never buffers its full stream. Driven directly with
     * generators: the cap logic is value-generic, only the walk matters.
     */
    public function testCollectFramesRefusesOneFramePastTheCap(): void
    {
        $collect = new \ReflectionMethod(Cli::class, 'collectFrames');
        $collect->setAccessible(true);

        $five = static function (): \Generator {
            for ($i = 0; $i < 5; $i++) {
                yield new \stdClass();
            }
        };

        $this->assertCount(5, $collect->invoke(null, $five(), 5, 16), 'at the cap the walk succeeds');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceeds the 4-frame intake cap');
        $collect->invoke(null, $five(), 4, 16);
    }

    /**
     * The resident-memory gate is frames × viewport cells, checked during
     * the drain (fail-loud, not buffered-past): five 3M-cell frames pass the
     * 6M budget's line and refuse before the fifth lands.
     */
    public function testCollectFramesEnforcesTheResidentCellBudget(): void
    {
        $collect = new \ReflectionMethod(Cli::class, 'collectFrames');
        $collect->setAccessible(true);

        $five = static function (): \Generator {
            for ($i = 0; $i < 5; $i++) {
                yield new \stdClass();
            }
        };

        $this->assertCount(5, $collect->invoke(null, $five(), 10, 1_000), 'a small grid never trips the budget');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('resident budget');
        $collect->invoke(null, $five(), 10, 3_000_000);
    }

    /**
     * End-to-end shape of the engine window: a 257-frame GIF plays its
     * first Decoder::MAX_FRAMES frames and the viewer says so on stderr —
     * the truncation is disclosed, never silent.
     */
    public function testTheEngineWindowTruncatesWithAnHonestNotice(): void
    {
        $gif = $this->gifFixtureFrames(257);
        [$stdout, $stderr] = self::pipes();

        $seen = null;
        $exit = Cli::main(['candy-gifview', $gif], $stdout, $stderr,
            static function (Player $player) use (&$seen): int {
                $seen = $player;

                return 0;
            });

        $this->assertSame(0, $exit, self::drain($stderr));
        $this->assertCount(Decoder::MAX_FRAMES, $seen->frames);
        $this->assertStringContainsString('showing the first 256 frames', self::drain($stderr));
        $this->assertSame('', self::drain($stdout));
    }

    /**
     * --paused starts the Player frozen (Space steps it by hand); the
     * default stays running, and a value with the bare flag refuses.
     */
    public function testPausedWiresThePlayerAndRefusesAValue(): void
    {
        $this->assertTrue($this->wiredPlayer(['{gif}', '--paused'])->paused);
        $this->assertFalse($this->wiredPlayer(['{gif}'])->paused, 'default is playing');

        $gif = $this->gifFixture();
        [$stdout, $stderr] = self::pipes();
        $exit = Cli::main(['candy-gifview', $gif, '--paused=5'], $stdout, $stderr,
            static fn (Player $p): int => 0);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--paused takes no value', self::drain($stderr));
    }

    /**
     * Structural pins for the two launch-path affordances that cannot be
     * driven in-process (the real Program loop): the loading line is keyed
     * and emitted, and a signal stop re-encodes as the shell idiom 128+N.
     */
    public function testTheLaunchPathCarriesTheLoadingLineAndSignalExit(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../src/Cli.php');

        $this->assertStringContainsString("\$program->stopSignal()", $src);
        $this->assertStringContainsString('128 + $signal', $src);
        $this->assertStringContainsString("Lang::t('cli.decoding'", $src);
        $this->assertStringContainsString("Lang::t('cli.truncated'", $src);
    }

    /**
     * Security m11: a raw \Error escaping the hand-rolled GIF walk (the
     * Decoder's documented contract is RuntimeException, but the walk is
     * byte-driven) must exit 1 with the message on stderr, not fatal
     * mid-launch. The $decode seam is the throwing fixture.
     */
    public function testRawErrorFromTheIntakeExitsOneLoudly(): void
    {
        [$stdout, $stderr] = self::pipes();

        $exit = Cli::main(
            ['candy-gifview', 'synthetic.gif'],
            $stdout,
            $stderr,
            null,
            null,
            static fn (string $p, int $w, int $h): array => throw new \Error('simulated walk failure'),
        );

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('simulated walk failure', self::drain($stderr));
        $this->assertSame('', self::drain($stdout));
    }

    /**
     * gifview-006/007: the loop-count audit rides the intake, so the exact
     * RuntimeException('not a GIF') shape that Decoder::loopCount throws for
     * bytes shorter than 13 or with a non-GIF signature is rescued through
     * the same exit-1 channel as decode failures — one localized line, never
     * a raw stack trace. (Before the fix this throw ran in main(), OUTSIDE
     * any catch: measured exit 255 with the library string as final word.)
     */
    public function testNotAGifThrowFromTheAuditedIntakeExitsOneLoudly(): void
    {
        [$stdout, $stderr] = self::pipes();

        $exit = Cli::main(
            ['candy-gifview', 'junk.bin'],
            $stdout,
            $stderr,
            static fn (Player $p): int => self::fail('the play seam must never run after an intake throw'),
            null,
            static fn (string $p, int $w, int $h): array => throw new \RuntimeException('sugar-animation: not a GIF'),
        );

        $this->assertSame(1, $exit);
        $err = self::drain($stderr);
        $this->assertStringContainsString('could not play', $err, 'the CLI speaks in its own brand');
        $this->assertStringContainsString('not a GIF', $err, 'the audit reason survives the wrap');
        $this->assertSame(1, substr_count($err, "\n"), 'exactly one line — no stack trace relay');
        $this->assertStringNotContainsString('stack trace', strtolower($err));
        $this->assertSame('', self::drain($stdout));
    }

    /**
     * Structural law behind gifview-007: the raw intake bytes are a secret of
     * decodeFrames() alone. main() must bind withLoops() from the audited
     * tuple and must never hold a $bytes reference — otherwise a forever-
     * looping animation parks up to MAX_INPUT_BYTES of dead RAM again, and a
     * re-appearing second Decoder::loopCount() call site would re-open the
     * un-rescued-audit hole (it would have to be the bytes).
     */
    public function testTheAuditedTupleReleasesTheRawBytes(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../src/Cli.php');

        $this->assertSame(
            1,
            substr_count($src, 'Decoder::loopCount('),
            'loopCount is audited at exactly one place — inside the intake',
        );

        $mainStart = strpos($src, 'public static function main(');
        $mainEnd = strpos($src, "\n    /**", $mainStart);
        self::assertIsInt($mainStart);
        self::assertIsInt($mainEnd);
        $main = substr($src, $mainStart, $mainEnd - $mainStart);

        $this->assertStringNotContainsString('$bytes', $main, 'raw intake bytes must die at the tuple destructure');
        $this->assertStringContainsString('[$frames, $loops] = $decode(', $main, 'main consumes the audited tuple');
        $this->assertStringContainsString('$player->withLoops($loops)', $main, 'the loops bind from the tuple, not from bytes');
    }

    /**
     * Structural pin for the rescue-catch widening: both decode sites catch
     * \Throwable, and a re-narrowed `catch (\RuntimeException` anywhere in
     * the front end reddens this fail-loud.
     */
    public function testBothDecodeRescueCatchesStayWidenedToThrowable(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../src/Cli.php');

        $this->assertStringNotContainsString(
            'catch (\RuntimeException',
            $src,
            'a narrow catch re-lets a raw \Error escape the launch/watch path',
        );
        $this->assertSame(
            3,
            substr_count($src, 'catch (\Throwable'),
            'the launch rescue, the watch-reload rescue, and the viewport probe belt'
                . ' are the three sites that must catch \Throwable',
        );
    }
}

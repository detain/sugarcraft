<?php

declare(strict_types=1);

namespace SugarCraft\GifView\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
            'unknown token falls back to solid' => [['wat'], Renderer::PRESET_SOLID],
        ];
    }

    public function testViewportCellsUsesTheDocumentedFallbackOffATty(): void
    {
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
     * E-09 seam probe: run main() with the given extra argv through the
     * $play seam and return the wired Player (failing on non-zero exit).
     *
     * @param list<string> $args flags/positions after the script name, the
     *        GIF path placeholder {gif} substituted with the fixture.
     */
    private function wiredPlayer(array $args): Player
    {
        $gif = $this->gifFixture();
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
        // derived from the decoded frames — pin the arithmetic here.
        $player = $this->wiredPlayer(['{gif}', '--speed=2']);
        $n = count($player->frames);
        $nominal = $n / (array_sum(array_map(
            static fn ($f): int => max(1, $f->delay),
            $player->frames,
        )) / 100.0);
        $this->assertSame(2.0 * $nominal, $player->fixedFps);
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
        $player = $this->wiredPlayer(['{gif}', '--duration=3.5']);
        $this->assertSame(3.5, $player->maxSeconds);
        $this->assertNull($player->fixedFps, 'duration alone keeps the GIF cadence');
    }

    public function testE09FlagsAndPositionalsMixInAnyOrder(): void
    {
        $player = $this->wiredPlayer(['--fps=10', '--duration=2', '{gif}', 'density']);
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
     * The intake cap is a documented promise: the README "Limits" section
     * names 4096, and the whole animation is materialised below it.
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

        $this->assertCount(5, $collect->invoke(null, $five(), 5), 'at the cap the walk succeeds');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceeds the 4-frame limit');
        $collect->invoke(null, $five(), 4);
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
            2,
            substr_count($src, 'catch (\Throwable'),
            'exactly the launch and watch-reload rescue sites must catch \Throwable',
        );
    }
}

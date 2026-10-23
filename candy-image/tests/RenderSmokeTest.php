<?php

/**
 * Golden-free render smokes for candy-image — a generated fixture goes
 * through the real CLI and the bytes land on the caller's stdout stream.
 * No terminal is faked: with STDOUT piped (every phpunit run), detection
 * answers from the env table and Mosaic's always-available floor renders,
 * so these pins assert the honest shape (exit 0, non-empty ANSI bytes) and
 * the animate pass-through wiring via the $play seam.
 *
 * Hermeticity note: {@see setUp()} pins the env keys the detection ladder
 * consults (see {@see HERMETIC_ENV_KEYS}) because a tmux-hosted runner's
 * exported TERM_PROGRAM/TERM_PROGRAM_VERSION alone would steer auto-format
 * onto the sixel passthrough regardless of TMUX or the piped STDOUT.
 */

declare(strict_types=1);

namespace SugarCraft\CandyImage\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Animation\Player;
use SugarCraft\Animation\Renderer;
use SugarCraft\CandyImage\Cli;

final class RenderSmokeTest extends TestCase
{
    /**
     * Env keys whose ambient values steer the auto-format ladder: TMUX and
     * TERM gate Detect's direct getenv() reads, and TERM_PROGRAM /
     * TERM_PROGRAM_VERSION are consulted by the F-01 TermDb table
     * ({@see \SugarCraft\Image\TermDb::snapshotEnv()}).
     *
     * WHY THESE FOUR: a tmux-hosted runner exports TERM_PROGRAM=tmux and
     * TERM_PROGRAM_VERSION into every pane, and the mux table rows answer
     * sixel from that pair even when TMUX is unset — so the naive
     * `env -u TMUX` shape still lands auto-format on the sixel passthrough
     * (the l11 audit's "not hermetic on tmux hosts" finding). Pinning the
     * whole consulted set to the documented no-graphics shape (TERM=dumb,
     * everything else absent) makes the ANSI floor deterministic without
     * touching the detector.
     */
    private const HERMETIC_ENV_KEYS = ['TERM', 'TERM_PROGRAM', 'TERM_PROGRAM_VERSION', 'TMUX'];

    /** @var array<string, string|false> pre-test getenv() snapshots, false = unset */
    private array $ambientEnv = [];

    /** @var list<string> */
    private array $artifacts = [];

    protected function setUp(): void
    {
        foreach (self::HERMETIC_ENV_KEYS as $key) {
            $this->ambientEnv[$key] = getenv($key);
        }
        putenv('TERM_PROGRAM');
        putenv('TERM_PROGRAM_VERSION');
        putenv('TMUX');
        putenv('TERM=dumb');
    }

    protected function tearDown(): void
    {
        foreach ($this->ambientEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
        $this->ambientEnv = [];

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

    private function pngFixture(): string
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd is required to decode image fixtures.');
        }

        $path = tempnam(sys_get_temp_dir(), 'sc_candyimage_') . '.png';
        $this->artifacts[] = $path;

        $im = imagecreatetruecolor(16, 8);
        for ($x = 0; $x < 16; ++$x) {
            for ($y = 0; $y < 8; ++$y) {
                imagesetpixel($im, $x, $y, imagecolorallocate($im, $x * 16, $y * 32, 128));
            }
        }
        imagepng($im, $path);
        imagedestroy($im);

        return $path;
    }

    private function gifFixture(): string
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd is required to decode the fixture GIF.');
        }

        $path = tempnam(sys_get_temp_dir(), 'sc_candyimage_') . '.gif';
        $this->artifacts[] = $path;

        $im = imagecreatetruecolor(4, 4);
        imagefill($im, 0, 0, imagecolorallocate($im, 10, 20, 30));
        imagegif($im, $path);
        imagedestroy($im);

        return $path;
    }

    public function testAutoFormatRendersAnsiBytesToStdout(): void
    {
        $png = $this->pngFixture();
        [$stdout, $stderr] = self::pipes();

        $exit = Cli::main(['candy-image', $png], $stdout, $stderr);

        $this->assertSame(Cli::EXIT_OK, $exit, self::drain($stderr));
        $bytes = self::drain($stdout);
        $this->assertNotSame('', $bytes);
        $this->assertStringContainsString("\x1b[", $bytes, 'the render must carry SGR styling');
        $this->assertStringEndsWith("\n", $bytes, 'stream output always ends on a fresh line');
        $this->assertSame('', self::drain($stderr));
    }

    public function testExplicitHalfAndQuarterFormatsRenderAtRequestedWidth(): void
    {
        $png = $this->pngFixture();

        foreach (['half', 'quarter'] as $format) {
            [$stdout, $stderr] = self::pipes();

            $exit = Cli::main(['candy-image', '--format=' . $format, '--width=8', $png], $stdout, $stderr);

            $this->assertSame(Cli::EXIT_OK, $exit, $format . ': ' . self::drain($stderr));
            $bytes = self::drain($stdout);
            $this->assertNotSame('', $bytes, $format);
            $this->assertStringContainsString("\x1b[", $bytes, $format);
        }
    }

    public function testAsciiFormatRendersCellTextWithoutTruncatingTheImageBox(): void
    {
        $png = $this->pngFixture();
        [$stdout, $stderr] = self::pipes();

        $exit = Cli::main(['candy-image', '--format=ascii', '--width=8', '--height=4', $png], $stdout, $stderr);

        $this->assertSame(Cli::EXIT_OK, $exit, self::drain($stderr));
        $bytes = self::drain($stdout);
        $rows = explode("\n", rtrim($bytes, "\n"));
        $this->assertGreaterThanOrEqual(2, count($rows));
    }

    public function testSymbolsTierRendersAndTheReachA04DialsMoveTheBytes(): void
    {
        $png = $this->pngFixture();

        $render = static function (array $args) use ($png): string {
            [$stdout, $stderr] = self::pipes();
            $exit = Cli::main(array_merge(['candy-image'], $args, [$png]), $stdout, $stderr);
            self::assertSame(Cli::EXIT_OK, $exit, self::drain($stderr));
            $bytes = self::drain($stdout);
            self::assertStringContainsString("\x1b[", $bytes, 'symbols output carries SGR styling');

            return $bytes;
        };

        $default = $render(['--format=symbols', '--width=6']);
        $selected = $render(['--format=symbols', '--width=6', '--symbols=braille']);
        $quantized = $render(['--format=symbols', '--width=6', '--canvas=ansi16']);
        $exhaustive = $render(['--format=symbols', '--width=6', '--work=9']);

        $this->assertNotSame($default, $selected, '--symbols=braille must change the corpus');
        $this->assertNotSame($default, $quantized, '--canvas=ansi16 must change the pens');
        $this->assertNotSame($default, $exhaustive, '--work=9 must reach the exhaustive search');
    }

    public function testScaleFlagIsAcceptedOnTheRenderPath(): void
    {
        $png = $this->pngFixture();
        [$stdout, $stderr] = self::pipes();

        $exit = Cli::main(['candy-image', '--format=half', '--scale=fit', '--size=12x6', $png], $stdout, $stderr);

        $this->assertSame(Cli::EXIT_OK, $exit, self::drain($stderr));
        $this->assertNotSame('', self::drain($stdout));
    }

    public function testAnimatePassThroughWiresThePlayerThroughTheSeam(): void
    {
        $gif = $this->gifFixture();
        [$stdout, $stderr] = self::pipes();

        $seen = null;
        $exit = Cli::main(
            ['candy-image', '--animate', $gif],
            $stdout,
            $stderr,
            static function (Player $player) use (&$seen): int {
                $seen = $player;

                return 0;
            },
        );

        $this->assertSame(Cli::EXIT_OK, $exit, self::drain($stderr));
        $this->assertInstanceOf(Player::class, $seen);
        $this->assertNotEmpty($seen->frames, 'the fixture GIF must decode to at least one frame');
        $this->assertInstanceOf(Renderer::class, $seen->renderer);
        $this->assertSame('', self::drain($stdout), 'the play seam never writes to stdout');
    }
}

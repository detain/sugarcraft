<?php

/**
 * F-18: golden-free render smokes for candy-image — a generated fixture goes
 * through the real CLI and the bytes land on the caller's stdout stream.
 * No terminal is faked: with STDOUT piped (every phpunit run), detection
 * answers from the env table and Mosaic's always-available floor renders,
 * so these pins assert the honest shape (exit 0, non-empty ANSI bytes) and
 * the animate pass-through wiring via the $play seam.
 */

declare(strict_types=1);

namespace SugarCraft\CandyImage\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Animation\Player;
use SugarCraft\Animation\Renderer;
use SugarCraft\CandyImage\Cli;

final class RenderSmokeTest extends TestCase
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

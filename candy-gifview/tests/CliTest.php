<?php

declare(strict_types=1);

namespace SugarCraft\GifView\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Flip\Player;
use SugarCraft\Flip\Renderer;
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
        // The decoder's own wording (a candy-flip library string) may change;
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
}

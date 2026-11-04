<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Encode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Encode\PhpGifEncoder;
use SugarCraft\Vcr\Encode\TapeToGif;
use SugarCraft\Vcr\Raster\FontLoader;
use SugarCraft\Vcr\Raster\Rasterizer;
use SugarCraft\Vcr\Render\Renderer;
use SugarCraft\Vcr\Tape\Compiler;
use SugarCraft\Vcr\Tape\Lexer;
use SugarCraft\Vcr\Tape\Parser;
use SugarCraft\Vterm\Snapshot;

/**
 * `Hide` / `Show` flip the frame stream's cursor-capture flag as the generator
 * advances. The render loop used to read that flag once, before the loop, so
 * every frame was rasterized with the pre-iteration value and the directives
 * were inert.
 *
 * End-to-end GIF bytes cannot see the difference on an echo-mode tape (the
 * shell-cursor cell is not part of those frames), so the pin injects a
 * recording rasterizer and asserts on the per-frame flag the pipeline actually
 * asked for.
 */
final class TapeToGifCursorCaptureTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!\extension_loaded('gd')) {
            $this->markTestSkipped('ext/gd not available');
        }
        $this->dir = sys_get_temp_dir() . '/candy-vcr-t2g-cursor-' . bin2hex(random_bytes(4));
        if (!mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            self::fail("Failed to create temp dir: {$this->dir}");
        }
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->dir . '/' . $entry);
            }
        }
        @rmdir($this->dir);
    }

    public function testFramesAfterHideAreRasterizedWithoutTheCursor(): void
    {
        $flags = $this->captureFlags("Type \"ab\"\nEnter\nHide\nType \"cd\"\nSleep 100ms\n");

        $this->assertGreaterThanOrEqual(2, count($flags), 'the tape must produce more than one frame');
        $this->assertTrue($flags[0], 'the opening frame is captured before any Hide');
        $this->assertContains(false, $flags, 'Hide must stop the cursor being rasterized on later frames');
        $this->assertContains(true, $flags, 'the frames before the Hide still carry the cursor');
    }

    public function testFramesWithoutHideKeepTheCursor(): void
    {
        $flags = $this->captureFlags("Type \"ab\"\nEnter\nType \"cd\"\nSleep 100ms\n");

        $this->assertGreaterThanOrEqual(2, count($flags));
        $this->assertSame([], array_keys($flags, false, true), 'no Hide means every frame keeps the cursor');
    }

    /**
     * @return list<bool> the renderCursor argument per rasterized frame, in order
     */
    private function captureFlags(string $body): array
    {
        $tapePath = $this->dir . '/cursor.tape';
        file_put_contents($tapePath, "Set Theme \"TokyoNight\"\nSet FontSize 14\nSet Width 640\nSet Height 224\n" . $body);

        $recorder = new class implements Rasterizer {
            /** @var list<bool> */
            public array $flags = [];

            public function rasterize(
                Snapshot $snapshot,
                int $cellW,
                int $cellH,
                ?FontLoader $fonts = null,
                bool $renderCursor = true,
            ): \GdImage {
                $this->flags[] = $renderCursor;
                $image = imagecreatetruecolor($cellW, $cellH);
                if (!$image instanceof \GdImage) {
                    throw new \RuntimeException('imagecreatetruecolor failed');
                }
                return $image;
            }
        };

        $pipeline = new TapeToGif(new Lexer(), new Parser(), new Compiler(), new Renderer(), $recorder, new PhpGifEncoder());
        $pipeline->render($tapePath, $this->dir . '/cursor.gif', ['encoder' => 'php']);

        return $recorder->flags;
    }
}

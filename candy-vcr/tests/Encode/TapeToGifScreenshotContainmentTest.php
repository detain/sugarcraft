<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Encode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Encode\TapeToGif;

/**
 * A `Screenshot` path is confined to the output directory, but confinement by
 * string alone still follows a symlink planted at that name — the render used
 * to `imagepng()` straight onto the target, so `out/shot.png -> /elsewhere/x.png`
 * wrote outside the output directory (CWE-59).
 *
 * Screenshots are now reserved under a private temp name and moved into place
 * with `rename()`, which replaces the link itself instead of its referent.
 */
final class TapeToGifScreenshotContainmentTest extends TestCase
{
    private const SENTINEL = 'do-not-overwrite-this-file';

    private string $root;

    private string $outDir;

    private string $victim;

    protected function setUp(): void
    {
        if (!\extension_loaded('gd')) {
            $this->markTestSkipped('ext/gd not available');
        }
        if (!\function_exists('symlink')) {
            $this->markTestSkipped('symlink() not available');
        }

        $this->root = sys_get_temp_dir() . '/candy-vcr-t2g-link-' . bin2hex(random_bytes(4));
        $this->outDir = $this->root . '/out';
        $this->victim = $this->root . '/victim.png';
        if (!mkdir($this->outDir, 0700, true) && !is_dir($this->outDir)) {
            self::fail("Failed to create temp dir: {$this->outDir}");
        }
        file_put_contents($this->victim, self::SENTINEL);
    }

    protected function tearDown(): void
    {
        foreach ([$this->outDir . '/shot.png', $this->outDir . '/result.gif', $this->root . '/tape.tape', $this->victim] as $path) {
            if (is_link($path) || is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($this->outDir);
        @rmdir($this->root);
    }

    public function testScreenshotReplacesASymlinkInsteadOfWritingThroughIt(): void
    {
        if (!@symlink($this->victim, $this->outDir . '/shot.png')) {
            $this->markTestSkipped('symlink creation refused on this filesystem');
        }

        $this->render("Type \"X\"\nScreenshot shot.png\n");

        // The referent must be untouched...
        $this->assertStringContainsString(self::SENTINEL, (string) file_get_contents($this->victim));
        // ...and the link must be gone: the screenshot is a real file now.
        $this->assertFalse(is_link($this->outDir . '/shot.png'), 'screenshot must not stay a symlink');
        $this->assertNotFalse(@imagecreatefrompng($this->outDir . '/shot.png'), 'screenshot must be a PNG at the requested name');
    }

    public function testScreenshotRefusesAnAbsolutePathOutsideTheOutputDirectory(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Screenshot path escapes output directory');

        $this->render("Type \"X\"\nScreenshot {$this->victim}\n");
    }

    private function render(string $body): void
    {
        $tapePath = $this->root . '/tape.tape';
        file_put_contents($tapePath, "Set Theme TokyoNight\nSet FontSize 14\nSet Width 700\nSet Height 300\n" . $body);

        TapeToGif::new(['encoder' => 'php', 'backend' => 'gd'])
            ->render($tapePath, $this->outDir . '/result.gif', ['encoder' => 'php']);
    }
}

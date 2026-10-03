<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\ClipboardImage;

/**
 * Audit 15b-15, Ctrl+V: the clipboard's image is saved through the first
 * clipboard tool that hands one over, and anything that is not an image is
 * "nothing to paste", never a file left behind.
 */
final class ClipboardImageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sc_clipimg_' . bin2hex(random_bytes(4));
        ClipboardImage::useDirectoryForTesting($this->dir);
    }

    protected function tearDown(): void
    {
        ClipboardImage::useRunnerForTesting(null);
        ClipboardImage::useCandidatesForTesting(null);
        ClipboardImage::useDirectoryForTesting(null);
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testTheFirstToolThatYieldsAnImageWins(): void
    {
        $tried = [];
        ClipboardImage::useCandidatesForTesting([['wl-paste'], ['xclip']]);
        ClipboardImage::useRunnerForTesting(static function (array $argv, string $dest) use (&$tried): bool {
            $tried[] = $argv[0];
            if ($argv[0] === 'wl-paste') {
                file_put_contents($dest, 'text on the clipboard');

                return true;
            }
            file_put_contents($dest, "\x89PNG\r\n\x1a\n" . 'pixels');

            return true;
        });

        $path = ClipboardImage::save();

        $this->assertSame(['wl-paste', 'xclip'], $tried);
        $this->assertNotNull($path);
        $this->assertStringStartsWith($this->dir . '/paste-', $path);
        $this->assertStringEndsWith('.png', $path);
        $this->assertCount(1, glob($this->dir . '/*') ?: [], 'the non-image attempt left nothing behind');
        $this->assertSame(0700, fileperms($this->dir) & 0777, 'the paste directory is private');
    }

    public function testAJpegIsNamedForWhatItIs(): void
    {
        ClipboardImage::useCandidatesForTesting([['pngpaste', '{dest}']]);
        $seen = null;
        ClipboardImage::useRunnerForTesting(static function (array $argv, string $dest) use (&$seen): bool {
            $seen = $argv;
            file_put_contents($dest, "\xFF\xD8\xFF\xE0jpeg");

            return true;
        });

        $path = ClipboardImage::save();

        $this->assertNotNull($path);
        $this->assertStringEndsWith('.jpg', $path);
        $this->assertSame('pngpaste', $seen[0]);
        $this->assertStringStartsWith($this->dir . '/paste-', $seen[1], 'pngpaste is told the destination as its argument');
    }

    public function testNoToolOrAFailedToolMeansNothingToPaste(): void
    {
        ClipboardImage::useCandidatesForTesting([]);
        $this->assertNull(ClipboardImage::save());

        ClipboardImage::useCandidatesForTesting([['xclip']]);
        ClipboardImage::useRunnerForTesting(static function (array $argv, string $dest): bool {
            file_put_contents($dest, "\x89PNG\r\n\x1a\n");

            return false;
        });
        $this->assertNull(ClipboardImage::save(), 'a non-zero exit is not a paste, whatever it wrote');
        $this->assertSame([], glob($this->dir . '/*') ?: []);
    }
}

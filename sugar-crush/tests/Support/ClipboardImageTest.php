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
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_link($file) || is_file($file)) {
                unlink($file);
            } elseif (is_dir($file) && !in_array(basename($file), ['.', '..'], true)) {
                rmdir($file);
            }
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

    /**
     * Audit 15b-15 residual: saved pastes used to pile up in the temp dir
     * forever. The sweep reclaims only this class's own stale files - its
     * name shape, a regular file, past the minimum age - and a fresh paste,
     * a stranger's file, a symlink and a directory are all left alone.
     */
    public function testSweepRemovesOnlyStalePastesOfItsOwnShape(): void
    {
        mkdir($this->dir, 0700, true);
        // Ten days ahead, so the symlink and the directory - whose own
        // mtimes are "now" and cannot be back-dated through PHP - are past
        // the minimum age too, and only their file type protects them.
        $now = time() + 10 * ClipboardImage::STALE_PASTE_MIN_AGE_SECONDS;
        $old = $now - ClipboardImage::STALE_PASTE_MIN_AGE_SECONDS;
        $files = [
            'stale-png' => 'paste-20260101-120000-0123abcd.png',
            'stale-jpg' => 'paste-20260101-120001-89abcdef.jpg',
            'fresh' => 'paste-20260102-120000-00112233.png',
            'stranger' => 'holiday.png',
            'near-miss' => 'paste-20260101-120000-0123abcd.png.bak',
        ];
        foreach ($files as $key => $name) {
            file_put_contents($this->dir . '/' . $name, 'x');
            touch($this->dir . '/' . $name, $key === 'fresh' ? $now - 60 : $old);
        }
        $target = $this->dir . '/target.png';
        file_put_contents($target, 'x');
        symlink($target, $this->dir . '/paste-20260101-120002-aaaaaaaa.png');
        mkdir($this->dir . '/paste-20260101-120003-bbbbbbbb.png');

        $this->assertSame(2, ClipboardImage::sweepStale($now));

        $this->assertFileDoesNotExist($this->dir . '/' . $files['stale-png']);
        $this->assertFileDoesNotExist($this->dir . '/' . $files['stale-jpg']);
        $this->assertFileExists($this->dir . '/' . $files['fresh'], 'a draft may still mention a fresh paste');
        $this->assertFileExists($this->dir . '/' . $files['stranger']);
        $this->assertFileExists($this->dir . '/' . $files['near-miss']);
        $this->assertTrue(is_link($this->dir . '/paste-20260101-120002-aaaaaaaa.png'), 'a symlink is never swept');
        $this->assertDirectoryExists($this->dir . '/paste-20260101-120003-bbbbbbbb.png');
    }

    public function testEachSaveSweepsEarlierStalePastes(): void
    {
        mkdir($this->dir, 0700, true);
        $stale = $this->dir . '/paste-20200101-000000-deadbeef.png';
        file_put_contents($stale, "\x89PNG\r\n\x1a\n");
        touch($stale, time() - ClipboardImage::STALE_PASTE_MIN_AGE_SECONDS - 5);

        ClipboardImage::useCandidatesForTesting([['xclip']]);
        ClipboardImage::useRunnerForTesting(static function (array $argv, string $dest): bool {
            file_put_contents($dest, "\x89PNG\r\n\x1a\n" . 'pixels');

            return true;
        });

        $path = ClipboardImage::save();

        $this->assertNotNull($path);
        $this->assertFileExists($path);
        $this->assertFileDoesNotExist($stale, 'the paste that just landed swept the day-old one');
    }

    public function testSweepOfAMissingDirectoryIsANoOp(): void
    {
        $this->assertSame(0, ClipboardImage::sweepStale());
    }
}

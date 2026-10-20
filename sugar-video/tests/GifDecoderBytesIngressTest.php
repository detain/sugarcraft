<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Video\Decode\GifDecoder;
use SugarCraft\Video\Render\Mode;

/**
 * E-11: the TOCTOU cure — a GifDecoder reads its source EXACTLY ONCE at
 * open and every later decode (including reopen of the same path) runs over
 * the captured bytes, so mutating or replacing the file underneath a live
 * player can no longer change what plays mid-session.
 *
 * The parity companion on the decode side is sugar-animation's
 * DecodeBytesParityTest; the structural sibling in sugar-image is
 * ImageSourceGifBytesIngressTest. This file owns the player-side promise.
 *
 * @covers \SugarCraft\Video\Decode\GifDecoder
 */
final class GifDecoderBytesIngressTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_dir($path)) {
                @rmdir($path);
            } elseif (file_exists($path)) {
                unlink($path);
            }
        }
        $this->paths = [];
        parent::tearDown();
    }

    private function track(string $path): string
    {
        $this->paths[] = $path;
        return $path;
    }

    /** A solid black GIF at the requested size (every decoded cell is black). */
    private function createBlackGif(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefill($img, 0, 0, $black);

        $path = $this->track(sys_get_temp_dir() . '/sugar-video-e11-' . uniqid('', true) . '.gif');
        imagegif($img, $path);
        imagedestroy($img);

        return $path;
    }

    /** A gradient GIF whose decoded cells are provably NOT all black. */
    private function createGradientGif(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $color = imagecolorallocate($img, ($x * 31) % 256, ($y * 47) % 256, (($x + $y) * 17) % 256);
                imagesetpixel($img, $x, $y, $color);
            }
        }

        $path = $this->track(sys_get_temp_dir() . '/sugar-video-e11-swap-' . uniqid('', true) . '.gif');
        imagegif($img, $path);
        imagedestroy($img);

        return $path;
    }

    /**
     * Keystone: open a black GIF, REPLACE the file at the same path with a
     * gradient GIF, then reopen the same path. The playback must still be the
     * black capture — a decoder that re-reads the path here would decode the
     * gradient instead. A direct fresh decoder over the swapped file proves
     * the two content sets really differ, so the pin is non-vacuous.
     *
     * @testdox reopen() of a mutated path replays the ORIGINAL capture, not the file (E-11)
     */
    public function testReopenOfSamePathReusesCapturedBytesAfterMutation(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build a test GIF');
        }

        $path = $this->createBlackGif(8, 6);
        $swap = $this->createGradientGif(8, 6);
        $swapBytes = (string) file_get_contents($swap);

        $decoder = new GifDecoder();
        $decoder->open($path, 8, 6, 10.0, Mode::Ascii);
        $beforeFrame = $decoder->next();
        $this->assertNotNull($beforeFrame);

        // Swap the file content under the live decoder, same path.
        $this->assertTrue(file_put_contents($path, $swapBytes) === strlen($swapBytes));

        $decoder->reopen($path, 8, 6, 10.0, Mode::Ascii);
        $afterFrame = $decoder->next();
        $decoder->close();

        $this->assertNotNull($afterFrame);
        $this->assertSame(
            $beforeFrame->bytes,
            $afterFrame->bytes,
            'reopen() of the same path must replay the bytes captured at open — the file changed underneath',
        );

        // Negative control: a FRESH decoder over the same swapped path sees the
        // gradient, so the equality above can only hold because of the capture.
        $fresh = new GifDecoder();
        $fresh->open($path, 8, 6, 10.0, Mode::Ascii);
        $freshFrame = $fresh->next();
        $fresh->close();
        $this->assertNotNull($freshFrame);
        $this->assertNotSame(
            $beforeFrame->bytes,
            $freshFrame->bytes,
            'the swapped file must decode differently — otherwise this pin proves nothing',
        );
    }

    /**
     * @testdox close() then reopen() of the same path needs no re-read and replays the capture (E-11)
     */
    public function testReopenAfterCloseReplaysCapture(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build a test GIF');
        }

        $path = $this->createBlackGif(4, 3);

        $decoder = new GifDecoder();
        $decoder->open($path, 4, 3, 10.0, Mode::Ascii);
        $first = $decoder->next();
        $decoder->close();

        // Delete the file entirely: any path re-read on reopen is now fatal.
        unlink($path);

        $decoder->reopen($path, 4, 3, 10.0, Mode::Ascii);
        $again = $decoder->next();
        $decoder->close();

        $this->assertNotNull($first);
        $this->assertNotNull($again);
        $this->assertSame($first->bytes, $again->bytes, 'reopen must survive a vanished path — it decodes from bytes');
    }

    /**
     * @testdox reopening a DIFFERENT source takes the fresh-capture path (E-11)
     */
    public function testReopenOfDifferentSourceRecaptures(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build a test GIF');
        }

        $black = $this->createBlackGif(4, 3);
        $gradient = $this->createGradientGif(4, 3);

        $decoder = new GifDecoder();
        $decoder->open($black, 4, 3, 10.0, Mode::Ascii);
        $blackFrame = $decoder->next();

        $decoder->reopen($gradient, 4, 3, 10.0, Mode::Ascii);
        $gradientFrame = $decoder->next();
        $decoder->close();

        $this->assertNotNull($blackFrame);
        $this->assertNotNull($gradientFrame);
        $this->assertNotSame($blackFrame->bytes, $gradientFrame->bytes, 'a path hop must capture and decode the new file');
    }

    /**
     * @testdox a missing source fails loud at open with path and reason (E-11)
     */
    public function testMissingSourceRefusesAtOpen(): void
    {
        $ghost = $this->track(sys_get_temp_dir() . '/sugar-video-e11-absent-' . uniqid('', true) . '.gif');

        $decoder = new GifDecoder();
        try {
            $decoder->open($ghost, 1, 1, 10.0);
            $this->fail('expected RuntimeException for a missing source');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($ghost, $e->getMessage());
            $this->assertStringContainsString('no such file or directory', $e->getMessage());
        }
    }

    /**
     * @testdox a non-regular source (directory) is refused (E-11)
     */
    public function testDirectorySourceRefused(): void
    {
        $dir = $this->track(sys_get_temp_dir() . '/sugar-video-e11-dir-' . uniqid('', true));
        mkdir($dir);

        $decoder = new GifDecoder();
        try {
            $decoder->open($dir, 1, 1, 10.0);
            $this->fail('expected RuntimeException for a directory source');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not a regular file', $e->getMessage());
        }
    }

    /**
     * @testdox a source above the byte ceiling is refused before any decode (E-11)
     */
    public function testOversizedSourceRefused(): void
    {
        $path = $this->track(sys_get_temp_dir() . '/sugar-video-e11-huge-' . uniqid('', true) . '.gif');
        $written = (int) @file_put_contents($path, str_repeat("\x00", GifDecoder::MAX_SOURCE_BYTES + 1));
        if ($written <= 0) {
            $this->markTestSkipped('filesystem cannot hold a ' . (GifDecoder::MAX_SOURCE_BYTES + 1) . '-byte test file');
        }

        $decoder = new GifDecoder();
        try {
            $decoder->open($path, 1, 1, 10.0);
            $this->fail('expected RuntimeException for an oversized source');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('above the', $e->getMessage());
            $this->assertStringContainsString('byte ceiling', $e->getMessage());
        }
    }
}

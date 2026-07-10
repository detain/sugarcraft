<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Encode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Encode\TapeToGif;

/**
 * End-to-end proof that a tape's `Output <path>` directive routes the rendered
 * GIF to the requested path (when no explicit caller path is supplied) and that
 * a traversal path in the directive cannot escape the tape's directory.
 *
 * Uses the pure-PHP GIF encoder so the pipeline needs only ext/gd (no ffmpeg).
 */
final class TapeToGifOutputDirectiveTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!\extension_loaded('gd')) {
            $this->markTestSkipped('ext/gd not available');
        }
        $this->dir = sys_get_temp_dir() . '/candy-vcr-t2g-out-' . bin2hex(random_bytes(4));
        if (!mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            self::fail("Failed to create temp dir: {$this->dir}");
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function writeTape(string $body): string
    {
        $path = $this->dir . '/demo.tape';
        file_put_contents($path, $body);
        return $path;
    }

    public function testTapeOutputDirectiveIsHonoredWhenNoExplicitOutput(): void
    {
        $tape = $this->writeTape("Output custom.gif\nSet Width 20\nSet Height 3\nType \"hi\"\nSleep 200ms\n");

        $written = TapeToGif::create(['encoder' => 'php'])->render($tape, null, ['encoder' => 'php']);

        $expected = $this->dir . '/custom.gif';
        // Pre-fix the directive was discarded and the GIF landed at demo.gif.
        $this->assertSame($expected, $written);
        $this->assertFileExists($expected);
        $this->assertSame('GIF89a', (string) file_get_contents($expected, false, null, 0, 6));
        $this->assertFileDoesNotExist($this->dir . '/demo.gif');
    }

    public function testExplicitCallerOutputWinsOverTapeDirective(): void
    {
        $tape = $this->writeTape("Output custom.gif\nSet Width 20\nSet Height 3\nType \"hi\"\nSleep 200ms\n");
        $explicit = $this->dir . '/explicit.gif';

        $written = TapeToGif::create(['encoder' => 'php'])->render($tape, $explicit, ['encoder' => 'php']);

        $this->assertSame($explicit, $written);
        $this->assertFileExists($explicit);
        $this->assertFileDoesNotExist($this->dir . '/custom.gif');
    }

    public function testTraversalOutputDirectiveDoesNotEscapeTapeDir(): void
    {
        // A parent-directory escape must be ignored; the render falls back to
        // the tape name with a `.gif` extension and never writes outside dir.
        $tape = $this->writeTape("Output ../evil.gif\nSet Width 20\nSet Height 3\nType \"hi\"\nSleep 200ms\n");
        $escapeTarget = dirname($this->dir) . '/evil.gif';
        $this->assertFileDoesNotExist($escapeTarget);

        $written = TapeToGif::create(['encoder' => 'php'])->render($tape, null, ['encoder' => 'php']);

        $this->assertSame($this->dir . '/demo.gif', $written);
        $this->assertFileExists($this->dir . '/demo.gif');
        $this->assertFileDoesNotExist($escapeTarget);
    }

    public function testOutputThroughPreplantedSymlinkDoesNotEscapeTapeDir(): void
    {
        // Pre-plant a symlink `out.gif` inside the tape dir pointing OUTSIDE it.
        // The parent-dir (tape dir) resolves under base, so the pre-fix
        // parent-only guard writes the rendered GIF *through* the link to the
        // outside target. The symlink-target guard must reject it: the render
        // falls back to the safe default inside the tape dir and NOTHING is
        // written at the outside target.
        $escapeTarget = dirname($this->dir) . '/candy-vcr-pwned-' . bin2hex(random_bytes(4)) . '.gif';
        $this->assertFileDoesNotExist($escapeTarget);

        $link = $this->dir . '/out.gif';
        if (!@symlink($escapeTarget, $link)) {
            $this->markTestSkipped('symlink() not available on this platform');
        }

        $tape = $this->writeTape("Output out.gif\nSet Width 20\nSet Height 3\nType \"hi\"\nSleep 200ms\n");

        try {
            $written = TapeToGif::create(['encoder' => 'php'])->render($tape, null, ['encoder' => 'php']);

            $this->assertSame($this->dir . '/demo.gif', $written, 'must fall back to the safe default');
            $this->assertFileExists($this->dir . '/demo.gif');
            $this->assertFileDoesNotExist($escapeTarget, 'render must NOT write through the symlink');
        } finally {
            @unlink($escapeTarget);
        }
    }
}

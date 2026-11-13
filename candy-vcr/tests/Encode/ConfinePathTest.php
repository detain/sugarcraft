<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Encode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Encode\GifEncoder;
use SugarCraft\Vcr\Encode\PhpGifEncoder;
use SugarCraft\Vcr\Encode\TapeToGif;
use SugarCraft\Vcr\Cli\Application;
use SugarCraft\Vcr\Raster\GdRasterizer;
use SugarCraft\Vcr\Tape\Compiler;
use SugarCraft\Vcr\Tape\Lexer;
use SugarCraft\Vcr\Tape\Parser;
use SugarCraft\Vcr\Render\Renderer;
use SugarCraft\Vcr\Tests\Support\Stream;

/**
 * Finding 010/026 — the write door's path and return guards.
 *
 * Two independent lies lived at the seam:
 *
 * 1. `confineScreenshotPath()` real-pathed only one side of its prefix
 *    compare in the relative arm. With a relative `-o rel.gif` the output
 *    directory is '.' and an absolute realpath never starts with './' — so a
 *    perfectly confined Screenshot died with a bogus "escapes output
 *    directory" (the PHP-02/QA-04 repro). Both sides are real-pathed now.
 *
 * 2. `encodeAtomically()` ignored the encoder's bool return, and the bool
 *    itself was dishonest anyway: the encoders' `filesize($outputPath) > 0`
 *    self-check read PHP's global stat cache AFTER `new SplFileObject($path,
 *    'w')` (or any reservation-time stat) had seeded the size at the
 *    0-byte fopen('x') creation, so a fully written GIF returned false.
 *    The encoders now re-stat (clearstatcache), and the caller fails loud
 *    on a real false BEFORE the rename — never "GIF written" on 0 bytes.
 */
final class ConfinePathTest extends TestCase
{
    private string $dir;

    private string $savedCwd;

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }
        $this->savedCwd = (string) getcwd();
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/vcrconfine-' . bin2hex(random_bytes(4));
        if (!mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            $this->fail("Failed to create temp dir: {$this->dir}");
        }
    }

    protected function tearDown(): void
    {
        chdir($this->savedCwd);
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            if (is_dir((string) $f)) {
                @rmdir((string) $f);
            } else {
                @unlink((string) $f);
            }
        }
        @rmdir($this->dir);
    }

    private function pipeline(?GifEncoder $encoder = null): TapeToGif
    {
        return new TapeToGif(
            new Lexer(),
            new Parser(),
            new Compiler(),
            new Renderer(),
            new GdRasterizer(),
            $encoder ?? new PhpGifEncoder(),
        );
    }

    /**
     * @param non-empty-string $name
     */
    private function tape(string $name, string $body): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, "Set Theme TokyoNight\nSet FontSize 14\nSet Width 300\nSet Height 120\n" . $body);
        return $path;
    }

    private function shotTape(string $name = 'shot.tape'): string
    {
        return $this->tape($name, "Type \"X\"\nScreenshot shot.png\n");
    }

    public function testRelativeOutputPathKeepsScreenshotsConfinedAndAllowed(): void
    {
        $tape = $this->shotTape();
        chdir($this->dir);

        $gif = $this->pipeline()->render('shot.tape', 'rel.gif', ['encoder' => 'php']);

        $this->assertSame('rel.gif', $gif);
        clearstatcache();
        $this->assertFileExists($this->dir . '/rel.gif');
        $this->assertStringStartsWith('GIF8', (string) file_get_contents($this->dir . '/rel.gif'));
        $this->assertFileExists($this->dir . '/shot.png');
        // The PHP-02/QA-04 repro: the render used to die with
        // "Screenshot path escapes output directory: shot.png".
        $this->assertSame([], glob($this->dir . '/.candyvcr-*'), 'no temp residue after the atomic write');
    }

    public function testRelativeOutputDirectoryIsRealPathedOnBothSides(): void
    {
        $tape = $this->shotTape();
        mkdir($this->dir . '/out', 0700, true);
        chdir($this->dir);

        $gif = $this->pipeline()->render('shot.tape', 'out/rel.gif', ['encoder' => 'php']);

        $this->assertSame('out/rel.gif', $gif);
        $this->assertFileExists($this->dir . '/out/rel.gif');
        $this->assertFileExists($this->dir . '/out/shot.png');
    }

    public function testTraversalIsStillRefusedUnderARelativeOutputDir(): void
    {
        $this->tape('bad.tape', "Type \"X\"\nScreenshot ../escape.png\n");
        chdir($this->dir);

        try {
            $this->pipeline()->render('bad.tape', 'rel.gif', ['encoder' => 'php']);
            $this->fail('traversal screenshot must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Screenshot path escapes output directory', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->dir . '/escape.png');
    }

    public function testAbsoluteScreenshotOutsideARelativeOutputDirIsRefused(): void
    {
        $outside = $this->dir . '/outside.png';
        $this->tape('absout.tape', "Type \"X\"\nScreenshot {$outside}\n");
        mkdir($this->dir . '/out', 0700, true);
        chdir($this->dir);

        try {
            $this->pipeline()->render('absout.tape', 'out/rel.gif', ['encoder' => 'php']);
            $this->fail('absolute screenshot outside the output directory must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Screenshot path escapes output directory', $e->getMessage());
        }
        $this->assertFileDoesNotExist($outside);
    }

    public function testRenderTapeCliWithRelativeOutputSucceeds(): void
    {
        $this->shotTape();
        chdir($this->dir);

        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');
        $this->assertNotFalse($stdout);
        $this->assertNotFalse($stderr);

        $exit = (new Application())->run(
            ['candy-vcr', 'render-tape', 'shot.tape', '-o', 'rel.gif', '--encoder', 'php', '--backend', 'gd'],
            $stdout,
            $stderr,
        );

        rewind($stdout);
        rewind($stderr);
        $this->assertSame(
            0,
            $exit,
            'render-tape -o rel.gif must succeed; stderr: ' . (string) stream_get_contents($stderr),
        );
        $this->assertFileExists($this->dir . '/rel.gif');
        $this->assertFileExists($this->dir . '/shot.png');
    }

    public function testEncoderFalseFailsLoudBeforeTheRename(): void
    {
        // A tape with no screenshot exercises only the encode door.
        $this->tape('plain-fail.tape', "Type \"X\"\n");
        chdir($this->dir);

        $silentFailureEncoder = new class implements GifEncoder {
            public function encode(array $pngPaths, string $outputPath, int $fps = 30, ?array $durations = null): bool
            {
                return false;
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function name(): string
            {
                return 'fake-false';
            }
        };

        try {
            $this->pipeline($silentFailureEncoder)->render('plain-fail.tape', 'never.gif', ['encoder' => 'php']);
            $this->fail('encoder returning false must abort the render');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('GIF encoder failed to produce: never.gif', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->dir . '/never.gif', 'a lying "GIF written" on 0 bytes is the failure class');
        $this->assertSame([], glob($this->dir . '/.candyvcr-*'), 'the reserved temp is removed on the failure path');
    }

    public function testEncoderBoolIsHonestOnAReservedTempFile(): void
    {
        // Regression for the stat-cache lie: encodeAtomically reserves the
        // temp with fopen('x') (size 0 at creation); PhpGifEncoder writes
        // through SplFileObject, whose constructor re-seeds the global stat
        // cache with that 0 — a bare filesize() check then reports the fully
        // written GIF as empty. The encode bool must reflect DISK truth.
        $png = $this->dir . '/frame.png';
        $image = imagecreatetruecolor(8, 8);
        imagepng($image, $png);
        imagedestroy($image);

        $reserved = $this->dir . '/.candyvcr-reserved.gif';
        $handle = fopen($reserved, 'x');
        $this->assertNotFalse($handle);
        fclose($handle);

        $this->assertTrue(
            (new PhpGifEncoder())->encode([$png], $reserved, 30, [100]),
            'a fully written GIF over an x-reserved file must return true',
        );
        clearstatcache();
        $this->assertGreaterThan(0, (int) filesize($reserved));
        $this->assertStringStartsWith('GIF8', (string) file_get_contents($reserved));
    }
}

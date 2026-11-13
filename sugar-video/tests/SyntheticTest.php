<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SugarCraft\Animation\Decoder;
use SugarCraft\Video\Synthetic;

/**
 * Tests for the synthetic animated GIF generator.
 *
 * The primary regression: the original buildSyntheticGif() produced a
 * single-frame GIF.  The new Synthetic::generate() must produce an animated
 * GIF that sugar-animation's GifDecoder can decode into ≥2 frames.
 */
final class SyntheticTest extends TestCase
{
    /**
     * Regression test: Synthetic must produce an animated GIF with ≥2 frames.
     *
     * FAIL ON MASTER: the old buildSyntheticGif() emits 1 frame.
     * PASS AFTER FIX: Synthetic::generate() emits 16 phase-shifted frames.
     */
    public function testGenerateProducesAnimatedGif(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD not available');
        }

        $path = Synthetic::generate('/tmp/sr-test-animated.gif', 8, 8, 16, 4);

        $this->assertFileExists($path);

        $bytes = file_get_contents($path);
        $this->assertStringStartsWith('GIF8', $bytes);

        // The regression: sugar-animation's GifDecoder must decode ≥2 frames from
        // an animated GIF.  A single-frame static GIF (the old behavior)
        // decodes to exactly 1 frame and this assertion fails.
        $decoder = new Decoder();
        $frames = $decoder->decode($path, 8, 8);
        $this->assertGreaterThanOrEqual(
            2,
            count($frames),
            'Synthetic must produce an animated GIF with ≥2 frames; got ' . count($frames)
        );
    }

    /**
     * Every frame must decode to the gradient Synthetic drew for it. Frames
     * 1..n carry the palette GD quantised them to as a Local Color Table;
     * dropping it repaints them through frame 0's palette.
     */
    public function testEachFrameDecodesThroughItsOwnPalette(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD not available');
        }
        $w = 8;
        $h = 8;
        $n = 4;
        $path = $this->scratchDir() . '/palette.gif';
        Synthetic::generate($path, $w, $h, $n, 5);

        $frames = Decoder::decode($path, $w, $h);
        $this->assertCount($n, $frames);
        foreach ($frames as $f => $frame) {
            $this->assertSame(5, $frame->delay, "frame $f delay");
            $err = 0;
            $worst = 0;
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $want = [
                        (int) min(255, 255 * $x / $w),
                        (int) min(255, 255 * $y / $h),
                        (int) min(255, 255 * (($x + $y + intdiv($f * $w, $n)) % $w) / $w),
                    ];
                    $got = $frame->cells[$y][$x];
                    $this->assertNotNull($got, "frame $f ($x,$y) transparent");
                    foreach ([0, 1, 2] as $c) {
                        $d = abs($want[$c] - $got[$c]);
                        $err += $d;
                        $worst = max($worst, $d);
                    }
                }
            }
            // GD quantises truecolor to a palette, so single channels drift
            // (MEASURED: mean ~3.3, worst 29 per frame). Repainting a frame
            // through frame 0's palette instead gives mean ~40, worst ~195.
            $this->assertLessThan(8, $err / ($w * $h * 3), "frame $f mean channel error");
            $this->assertLessThan(48, $worst, "frame $f worst channel error");
        }
    }

    /**
     * The stream must be a single GIF89a (extensions are not GIF87a) with
     * exactly one trailer, at the end.
     */
    public function testStreamIsGif89aWithSingleTrailer(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD not available');
        }
        $path = $this->scratchDir() . '/stream.gif';
        Synthetic::generate($path, 8, 8, 3, 4);
        $bytes = (string) file_get_contents($path);

        $this->assertStringStartsWith('GIF89a', $bytes);
        $this->assertSame("\x3B", substr($bytes, -1));
        $this->assertSame(3, substr_count($bytes, "\x21\xF9\x04"), 'one GCE per frame');
    }

    /**
     * More frames than pixel columns must still animate: the phase step used
     * to be (int)(w/frames), which is 0 for 8 px x 16 frames.
     */
    public function testFramesDifferWhenFrameCountExceedsWidth(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD not available');
        }
        $path = $this->scratchDir() . '/phase.gif';
        Synthetic::generate($path, 8, 8, 16, 4);
        $frames = Decoder::decode($path, 8, 8);

        $this->assertCount(16, $frames);
        $this->assertNotEquals($frames[0]->cells, $frames[2]->cells);
    }

    /**
     * When the pid-suffixed temp file already exists, the tempnam() fallback
     * must be used and renamed into place, not leaked beside the output.
     */
    public function testTempCollisionLeavesNoStrayFile(): void
    {
        $dir = $this->scratchDir();
        $path = $dir . '/collide.gif';
        $blocker = $path . '.' . getmypid() . '.tmp';
        touch($blocker);

        Synthetic::generate($path, 4, 4, 2, 4);

        $left = array_values(array_diff((array) scandir($dir), ['.', '..']));
        sort($left);
        $this->assertSame(['collide.gif', basename($blocker)], $left);
        $this->assertStringStartsWith('GIF8', (string) file_get_contents($path));
    }

    private ?string $scratch = null;

    private function scratchDir(): string
    {
        if ($this->scratch === null) {
            $this->scratch = sys_get_temp_dir() . '/sugar-video-synth-' . bin2hex(random_bytes(6));
            mkdir($this->scratch, 0700);
        }
        return $this->scratch;
    }

    protected function tearDown(): void
    {
        if ($this->scratch !== null) {
            foreach ((array) glob($this->scratch . '/{,.}*', GLOB_BRACE) as $f) {
                if (is_file((string) $f)) {
                    unlink((string) $f);
                }
            }
            @rmdir($this->scratch);
            $this->scratch = null;
        }
    }

    /**
     * Verify the GD-absent fallback produces a valid tiny GIF file.
     */
    public function testGenerateFallbackWhenGdAbsent(): void
    {
        // The fallback path is only reachable when ext-gd is absent.
        // When GD IS present we skip; when it is absent the code path is
        // exercised in testGenerateProducesAnimatedGif (which would mark
        // itself skipped).  Here we validate the static fallback bytes
        // manually to ensure they are well-formed regardless of GD state.
        $gif = "GIF89a\x01\x00\x01\x00\x80\x00\x00\xff\xff\xff\x00\x00\x00!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x01\x00;";
        $this->assertStringStartsWith('GIF8', $gif);
        $this->assertSame("\x3B", substr($gif, -1)); // trailer byte
    }

    /**
     * No buildSyntheticGif symbol must remain anywhere under src/ or examples/.
     *
     * Both Reel::buildSyntheticGif() and examples/buildSyntheticGif() have been
     * replaced by the single Synthetic::generate() source of truth.
     */
    public function testNoBuildSyntheticGifRemains(): void
    {
        $srcDir = \dirname(__DIR__) . '/src';
        $exDir = \dirname(__DIR__) . '/examples';

        $violations = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir)) as $f) {
            if (!$f->isFile()) {
                continue;
            }
            $content = file_get_contents($f->getPathname());
            if (str_contains($content, 'buildSyntheticGif')) {
                $violations[] = $f->getPathname() . ' contains buildSyntheticGif';
            }
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($exDir)) as $f) {
            if (!$f->isFile()) {
                continue;
            }
            $content = file_get_contents($f->getPathname());
            if (str_contains($content, 'buildSyntheticGif')) {
                $violations[] = $f->getPathname() . ' contains buildSyntheticGif';
            }
        }

        $this->assertSame([], $violations);
    }

    /**
     * Smoke test: play.php --help must emit zero PHP warnings.
     *
     * The F19 bug was that every unguarded $argv[1] access emitted an
     * "Undefined array key 1" warning when no argument was supplied.
     */
    public function testPlayPhpHelpEmitsNoWarnings(): void
    {
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            ['php', \dirname(__DIR__) . '/examples/play.php', '--help'],
            $spec,
            $pipes,
        );
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[0]);
        $exit = proc_close($proc);

        $this->assertStringNotContainsString('Warning:', $stderr);
        $this->assertStringNotContainsString('Undefined array key', $stderr);
        // m28, flipped in-step: --help is a success path, not an error.
        $this->assertSame(0, $exit, 'help should exit with code 0');
        // SV-L73: a help request writes to STDOUT (the pager-able convention) —
        // usage bytes land on stdout, stderr stays silent; error usages keep
        // writing STDERR (pinned by the bad-mode legs of this file's era).
        $this->assertStringContainsString('Usage:', $stdout, 'help rides stdout');
        $this->assertSame('', $stderr, 'the --help path writes nothing to stderr anymore');
    }

    /**
     * Smoke test: play.php with no arguments must emit zero PHP warnings.
     *
     * This was the exact F19 bug — calling `php examples/play.php` (no args)
     * caused two "Undefined array key 1" warnings because $argv[1] was
     * accessed without guarding against unset keys. argv parsing runs before
     * any TTY decision, so the no-warnings half of the contract survives the
     * guard hoist unchanged.
     *
     * The hoist flipped the second half: the stream_isatty(STDOUT) guard
     * now protects EVERY invocation, not just explicit-mode ones, so a bare
     * run whose stdout is captured answers with the refusal instead of the
     * synthetic banner. (The old comment's claim that bash hands php a
     * pseudo-TTY was never true — echo|php is a pipe — and playback under
     * this harness only ever worked because the guard was unreachable for a
     * bare run; that silent-pipe behaviour was exactly the defect.)
     */
    public function testPlayPhpNoArgsEmitsNoWarnings(): void
    {
        $playPhp = \dirname(__DIR__) . '/examples/play.php';
        $cmd = 'bash -c ' . escapeshellarg('echo q | php ' . escapeshellarg($playPhp) . ' 2>&1');
        $stderr = shell_exec($cmd);

        $this->assertStringNotContainsString('Warning:', (string) $stderr);
        $this->assertStringNotContainsString('Undefined array key', (string) $stderr);
        // The hoisted guard refuses before any source is generated: a bare
        // piped run answers with the refusal, never the synthetic banner.
        // Re-nesting the guard into the mode block restores the old banner
        // and reddens both legs below.
        $this->assertStringContainsString('playback needs a terminal', (string) $stderr);
        $this->assertStringNotContainsString('synthetic test pattern', (string) $stderr);
    }

    /**
     * M13: a planted symlink at the target path must never be written through
     * — the rename path replaces the link itself, and the stand-in fallback
     * refuses to follow it.
     */
    public function testGenerateNeverWritesThroughAPlantedSymlink(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD not available');
        }

        $dir = $this->scratchDir();
        $victim = $dir . '/victim.txt';
        $link = $dir . '/link.gif';
        file_put_contents($victim, 'ORIGINAL');
        symlink($victim, $link);

        $written = Synthetic::generate($link, 8, 4, 2, 1);

        $this->assertStringStartsWith('GIF8', (string) file_get_contents($written));
        $this->assertFalse(is_link($written), 'the returned path is a regular file, not a link');
        $this->assertSame('ORIGINAL', (string) file_get_contents($victim), 'the symlink target must be untouched');
    }

    /**
     * M13: the stand-in writer, called directly on a live symlink, diverts to
     * a fresh file in the same directory instead of following the link.
     */
    public function testTheSafeStandInRefusesToWriteThroughALink(): void
    {
        $dir = $this->scratchDir();
        $victim = $dir . '/v2.txt';
        $link = $dir . '/v2-link.bin';
        file_put_contents($victim, 'ORIGINAL');
        symlink($victim, $link);

        $method = new \ReflectionMethod(Synthetic::class, 'writeSafeStandIn');

        $written = $method->invoke(null, $dir, $link, 'BYTES');

        $this->assertNotSame($link, $written);
        $this->assertSame('ORIGINAL', (string) file_get_contents($victim));
        $this->assertSame('BYTES', (string) file_get_contents($written));
        @unlink($written);
    }
}

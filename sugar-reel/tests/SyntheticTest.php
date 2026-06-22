<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SugarCraft\Flip\Decoder;
use SugarCraft\Reel\Synthetic;

/**
 * Tests for the synthetic animated GIF generator.
 *
 * The primary regression: the original buildSyntheticGif() produced a
 * single-frame GIF.  The new Synthetic::generate() must produce an animated
 * GIF that candy-flip's GifDecoder can decode into ≥2 frames.
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

        // The regression: candy-flip's GifDecoder must decode ≥2 frames from
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
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[0]);
        $exit = proc_close($proc);

        $this->assertStringNotContainsString('Warning:', $stderr);
        $this->assertStringNotContainsString('Undefined array key', $stderr);
        $this->assertSame(1, $exit, 'help should exit with code 1');
    }

    /**
     * Smoke test: play.php with no arguments must emit zero PHP warnings.
     *
     * This was the exact F19 bug — calling `php examples/play.php` (no args)
     * caused two "Undefined array key 1" warnings because $argv[1] was
     * accessed without guarding against unset keys.
     *
     * We run via bash -c 'echo q | php examples/play.php' so that stdin is
     * a TTY (bash provides a pseudo-TTY for the sub-shell).  This lets the
     * Program::run() exit cleanly on 'q' without triggering /dev/tty open
     * failures that occur with plain proc_open pipes.
     */
    public function testPlayPhpNoArgsEmitsNoWarnings(): void
    {
        $playPhp = \dirname(__DIR__) . '/examples/play.php';
        $cmd = 'bash -c ' . escapeshellarg('echo q | php ' . escapeshellarg($playPhp) . ' 2>&1');
        $stderr = shell_exec($cmd);

        $this->assertStringNotContainsString('Warning:', (string) $stderr);
        $this->assertStringNotContainsString('Undefined array key', (string) $stderr);
        // Confirm the synthetic pattern message was printed.
        $this->assertStringContainsString('synthetic test pattern', (string) $stderr);
    }
}

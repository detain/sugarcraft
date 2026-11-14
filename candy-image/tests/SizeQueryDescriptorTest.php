<?php

/**
 * Structural pin for the descriptor the viewer hands to the size
 * ioctl, and for the tty gate in front of it.
 *
 * The defect family this guards (recorded in candy-gifview's CALIBER
 * LEARNINGS): casting a PHP stream constant to int yields its RESOURCE ID,
 * not its file descriptor, and the resource ids of the std streams are not
 * their descriptors — a size query built on the cast asks the kernel about
 * the wrong stream and dies on a perfectly good terminal. This viewer must
 * keep spelling descriptor 1 as the literal behind a core stream_isatty
 * gate, exactly like the fixed sibling.
 */

declare(strict_types=1);

namespace SugarCraft\CandyImage\Tests;

use PHPUnit\Framework\TestCase;

final class SizeQueryDescriptorTest extends TestCase
{
    private const BIN = __DIR__ . '/../bin/candy-image';
    private const CLI = __DIR__ . '/../src/Cli.php';

    /** @return array<string, string> label => source text */
    private static function scanTargets(): array
    {
        $bin = file_get_contents(self::BIN);
        self::assertIsString($bin, 'the bin must exist next to the app');
        $cli = file_get_contents(self::CLI);
        self::assertIsString($cli, 'Cli.php must exist');

        return ['bin/candy-image' => $bin, 'src/Cli.php' => $cli];
    }

    public function testTheSizeQueryIsLiteralDescriptorOne(): void
    {
        $cli = (self::scanTargets())['src/Cli.php'];

        $this->assertStringContainsString('PosixBackend::queryWinsize(1)', $cli);
        $this->assertStringNotContainsString('queryWinsize((int)', $cli, 'the cast shape names the wrong stream');
        $this->assertStringNotContainsString('posix_isatty', $cli, 'the gate stays core stream_isatty — no ext-posix symbol in the app');
    }

    public function testTheDeletedSizeIoctlQueryIsNotCalled(): void
    {
        $cli = (self::scanTargets())['src/Cli.php'];

        // sugar-pty PTY-40 deleted SizeIoctl::query(); a call left behind fails silently.
        $this->assertStringNotContainsString('SizeIoctl::query', $cli);
    }

    public function testTheTtyGateWrapsTheQuery(): void
    {
        $cli = (self::scanTargets())['src/Cli.php'];

        $this->assertMatchesRegularExpression(
            '/stream_isatty\(STDOUT\)[\s\S]{0,120}PosixBackend::queryWinsize\(1\)/',
            $cli,
            'the query must sit inside the stream_isatty(STDOUT) branch',
        );
    }

    public function testTheQueryIsGuardedByAThrowableCatch(): void
    {
        // IMG-01 (candy-image-001): queryWinsize throws by contract
        // (fd-not-tty, rc!=0) and Libc::lib() throws before it on Windows
        // and FFI-less builds. Raw, each one exits 255 through this frame
        // and breaches the documented 0/1/2 table — the catch rung is the
        // difference between a degraded box size and a dead viewer.
        $cli = (self::scanTargets())['src/Cli.php'];

        $this->assertMatchesRegularExpression(
            '/try \{[\s\S]{0,80}PosixBackend::queryWinsize\(1\)[\s\S]{0,220}\} catch \((\\\\)Throwable\)/',
            $cli,
            'the size query must sit inside a try whose catch swallows \\Throwable and falls through the ladder',
        );
    }

    public function testTheBinDelegatesEverythingToCli(): void
    {
        $bin = (self::scanTargets())['bin/candy-image'];

        $this->assertStringContainsString('SugarCraft\\CandyImage\\Cli::main($argv ?? [], STDOUT, STDERR)', $bin);
        $this->assertStringNotContainsString('fromFile', $bin, 'the bin stays a shim: no logic outside Cli');
        $this->assertStringContainsString('cannot find composer autoload.php', $bin);
        // IMG-13 (candy-image-018): the environment locale is wired at startup
        // — one line above main, the gifview twin's shape.
        $this->assertStringContainsString('T::setLocale(', $bin);
        $this->assertStringContainsString('T::detect()', $bin);
    }

    /**
     * A German environment must not crash or half-translate: with no de.php
     * shipped yet, the lookup falls back to 'en' and the bytes are the
     * silent-env bytes (the wiring's tolerance, pinned end-to-end).
     */
    public function testAGermanEnvironmentFallsBackToEnglishByteIdentically(): void
    {
        $help = static function (array $env): string {
            $process = proc_open(
                [PHP_BINARY, self::BIN, '--help'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                dirname(__DIR__),
                $env,
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $out = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process));

            return $out;
        };

        $base = $help(['PATH' => '/usr/bin:/bin']);
        $german = $help(['PATH' => '/usr/bin:/bin', 'LANG' => 'de_DE.UTF-8', 'LC_ALL' => 'de_DE.UTF-8']);

        self::assertStringContainsString('usage: candy-image', $base);
        self::assertSame($base, $german, 'without lang/de.php the detected locale must resolve to the English catalog, byte for byte');
    }

    /**
     * Execute the IMG-01 env rung as a real child: stdout is a pipe, so the
     * ioctl throws, the catch falls through to COLUMNS/LINES, and the render
     * box must come from the environment — measured shape: an 8x8 source in
     * ascii fills width and derives rows as width/2, so COLUMNS=40 yields
     * exactly 40-column rows (the fixed [60,24] fallback would be 60x30).
     */
    public function testTheEnvRungSizesTheChildRender(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd is required to mint the fixture.');
        }

        $png = sys_get_temp_dir() . '/candy-image-test-' . bin2hex(random_bytes(8)) . '.png';
        $im = imagecreatetruecolor(8, 8);
        self::assertIsObject($im);
        imagepng($im, $png);
        imagedestroy($im);

        try {
            $process = proc_open(
                [PHP_BINARY, self::BIN, '--format=ascii', $png],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                dirname(__DIR__),
                ['PATH' => '/usr/bin:/bin', 'COLUMNS' => '40', 'LINES' => '10'],
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $rc = proc_close($process);

            self::assertSame(0, $rc, "child stderr: {$err}");
            $rows = preg_split('/\R/', rtrim($out, "\n"));
            self::assertIsArray($rows);
            self::assertNotSame([], $rows);
            $widths = array_map('strlen', $rows);
            self::assertSame(40, max($widths), 'COLUMNS=40 must serve the render width (fallback would be 60)');
            self::assertCount(20, $rows, 'the env-sized box measures 40x20 for this aspect (fallback 60x30)');
        } finally {
            @unlink($png);
        }
    }
}

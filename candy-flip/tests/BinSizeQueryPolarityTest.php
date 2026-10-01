<?php

/**
 * Pins for the descriptor `bin/candy-flip` hands to the size ioctl, and for
 * the tty gate in front of it.
 *
 * The bug these guard: the bin ran `SizeIoctl::query((int) STDOUT)` behind a
 * `posix_isatty(STDOUT)` check. An `(int)` cast of a PHP stream yields its
 * RESOURCE ID, not its file descriptor — measured, PHP 8.3.6, `(int) STDOUT`
 * is 2, and descriptor 2 is STDERR. The gate correctly noticed that STDOUT
 * was a terminal, then asked the kernel about STDERR: `php candy-flip demo.gif
 * 2>err.log` — a routine invocation — died with `RuntimeException: Cannot
 * query size of non-tty fd` on a live terminal. Renderer::withAdaptiveSize()
 * carried exactly this defect and is pinned by RendererAdaptiveSizeDescriptorTest;
 * the bin was the sibling that never got the fix.
 *
 * The bin now spells descriptor 1 as the literal and gates with core
 * `stream_isatty(STDOUT)`, which drops the process's only ext-posix symbol.
 */

declare(strict_types=1);

namespace SugarCraft\Flip\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\Posix\PosixPtyPair;

final class BinSizeQueryPolarityTest extends TestCase
{
    private const BIN = __DIR__ . '/../bin/candy-flip';

    /** The SizeIoctl failure wording the pre-fix shape emitted on stderr. */
    private const NON_TTY_MESSAGE = 'Cannot query size of non-tty fd';

    private const PTY_COLS = 137;
    private const PTY_ROWS = 43;

    /** @var list<PosixPtyPair> */
    private array $ptys = [];

    /** @var list<string> */
    private array $artifacts = [];

    protected function tearDown(): void
    {
        foreach ($this->ptys as $pair) {
            $pair->master()->close();
        }
        $this->ptys = [];

        foreach ($this->artifacts as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->artifacts = [];
    }

    private static function binSource(): string
    {
        $contents = file_get_contents(self::BIN);
        self::assertIsString($contents, 'the bin must exist next to the lib');

        return $contents;
    }

    /**
     * Every `(int)` cast applied to a standard stream — the bug's exact shape.
     *
     * Read from the TOKEN stream rather than the raw text on purpose: this
     * class's comments quote `(int) STDOUT` to explain why it is wrong, and a
     * plain text scan would accuse the documentation of the defect it
     * documents. Comments carry no tokens here, so they cannot offend.
     *
     * @return list<string> "line (int) NAME" for each offender
     */
    private static function streamCasts(string $source): array
    {
        $found  = [];
        $tokens = \token_get_all($source);

        for ($i = 0, $count = \count($tokens); $i < $count; $i++) {
            $cast = $tokens[$i];
            if (!\is_array($cast) || $cast[0] !== T_INT_CAST) {
                continue;
            }
            // Step over whitespace and an optional leading namespace separator.
            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];
                if (\is_array($next) && ($next[0] === T_WHITESPACE || $next[0] === T_NS_SEPARATOR)) {
                    continue;
                }
                if (\is_array($next)
                    && $next[0] === T_STRING
                    && \in_array(\strtoupper($next[1]), ['STDIN', 'STDOUT', 'STDERR'], true)
                ) {
                    $found[] = "line {$cast[2]}: (int) {$next[1]}";
                }
                break;
            }
        }

        return $found;
    }

    public function testTheBinCarriesNoStreamToIntCasts(): void
    {
        $this->assertSame(
            [],
            self::streamCasts(self::binSource()),
            'an (int) cast of a stream yields the resource id, never the fd',
        );
    }

    public function testTheBinQueriesDescriptorOneBehindACoreIsattyGate(): void
    {
        $source = self::binSource();

        $this->assertStringContainsString(
            'SizeIoctl::query(1)',
            $source,
            'STDOUT\'s descriptor is the literal 1; anything else is guessing',
        );
        $this->assertStringContainsString(
            'stream_isatty(STDOUT)',
            $source,
            'the tty gate must be core stream_isatty, not the ext-posix twin',
        );
        $this->assertStringNotContainsString(
            'posix_isatty',
            $source,
            'the bin is the lib\'s only production surface; ext-posix stays out of it',
        );
    }

    public function testCastingTheStdoutResourceIsNotItsDescriptor(): void
    {
        // The trap itself, pinned as a fact about PHP rather than left to a
        // comment: the resource id is 2, the descriptor is 1. If a future edit
        // "simplifies" query(1) back to query((int) STDOUT), the scans above
        // go red, and this test explains why they did.
        if (!\is_resource(\STDOUT)) {
            $this->markTestSkipped('STDOUT is not a resource in this SAPI.');
        }

        $this->assertNotSame(
            1,
            (int) \STDOUT,
            'the cast resolves to STDERR\'s descriptor — that coincidence WAS the bug',
        );
    }

    /**
     * THE GUARD, behavioural: the real bin in a child whose descriptor 1 is a
     * 137x43 pseudo-terminal and whose descriptor 2 is a plain file — the
     * `2>err.log` shape from the report. The pre-fix body threw here (it
     * queried fd 2); the fixed one sizes from fd 1 and never emits the
     * non-tty diagnostic. The exit code is deliberately unread: the program
     * either runs its loop until `timeout` kills it or exits early on the
     * fixture GIF — neither outcome is what is under test.
     */
    public function testTheBinSizesFromStdoutWhenStderrIsRedirectedToAFile(): void
    {
        if (\PHP_OS_FAMILY === 'Windows' || \DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('POSIX pseudo-terminals are required.');
        }
        if (!\extension_loaded('ffi') || !\function_exists('posix_isatty')) {
            $this->markTestSkipped('ext-ffi and ext-posix are required to build a pty.');
        }
        if (!\function_exists('proc_open') || !is_executable('/usr/bin/timeout')) {
            $this->markTestSkipped('proc_open() or the timeout(1) wrapper is unavailable.');
        }

        $pair = (new PosixPtySystem())->open();
        $this->ptys[] = $pair;
        $pair->master()->resize(self::PTY_COLS, self::PTY_ROWS);

        // `open()` reports 0x0 until resized (measured), and an unresized pty
        // would let a silently-failed fixture pass vacuously.
        $this->assertSame(
            ['rows' => self::PTY_ROWS, 'cols' => self::PTY_COLS, 'xpix' => 0, 'ypix' => 0],
            $pair->master()->size(),
            'the pty did not take the size the fixture asked for',
        );

        $gif = $this->gifFixture();

        $errLog = tempnam(sys_get_temp_dir(), 'sc_flip_bin_errlog_');
        $this->assertIsString($errLog);
        $this->artifacts[] = $errLog;

        $process = proc_open(
            ['timeout', '3', \PHP_BINARY, realpath(self::BIN), $gif],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $this->slavePath($pair), 'w'],
                2 => ['file', $errLog, 'w'],
            ],
            $pipes,
        );
        $this->assertIsResource($process, 'could not start the bin child');

        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);

        $stderr = file_get_contents($errLog);
        $this->assertIsString($stderr);
        $this->assertStringNotContainsString(
            self::NON_TTY_MESSAGE,
            $stderr,
            'the bin asked the kernel for a size on a non-terminal fd — the (int) STDOUT '
                . 'cast is back, or the gate moved off descriptor 1',
        );
    }

    private function slavePath(PosixPtyPair $pair): string
    {
        $path = $pair->slave()->path();
        $this->assertIsString($path);

        return $path;
    }

    /**
     * A decodable-if-GD-exists GIF on disk. Whether the child can actually
     * decode it is beside the point: the size query happens BEFORE any decode,
     * so even the ext-gd refusal proves the guard's half that matters — the
     * bin got past descriptor 1 without a non-tty throw.
     */
    private function gifFixture(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sc_flip_bin_') . '.gif';
        $this->artifacts[] = $path;

        if (\extension_loaded('gd')) {
            $im = imagecreatetruecolor(4, 4);
            imagefill($im, 0, 0, imagecolorallocate($im, 10, 20, 30));
            imagegif($im, $path);
            imagedestroy($im);
            $this->assertGreaterThan(0, filesize($path), 'GD wrote an empty GIF');

            return $path;
        }

        file_put_contents($path, 'GIF89a' . str_repeat("\x00", 10));

        return $path;
    }
}

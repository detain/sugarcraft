<?php

/**
 * Pins for the descriptor the viewer hands to the size ioctl, and for the tty
 * gate in front of it.
 *
 * The bug these guard (inherited with the bin when the viewer CLI split out of
 * sugar-animation, rename campaign step 1.3): the old `bin/sugar-animation` ran
 * `SizeIoctl::query((int) STDOUT)` behind a `posix_isatty(STDOUT)` check. An
 * `(int)` cast of a PHP stream yields its RESOURCE ID, not its file descriptor
 * — measured, PHP 8.3.6, `(int) STDOUT` is 2, and descriptor 2 is STDERR. The
 * gate correctly noticed that STDOUT was a terminal, then asked the kernel
 * about STDERR: `php candy-gifview demo.gif 2>err.log` — a routine
 * invocation — died with `RuntimeException: Cannot query size of non-tty fd`
 * on a live terminal. Renderer::withAdaptiveSize() carried exactly this defect
 * and is pinned by RendererAdaptiveSizeDescriptorTest in sugar-animation; the bin
 * was the sibling that never got the fix.
 *
 * The size query now lives in `src/Cli.php::viewportCells()`, which delegates
 * the probe to sugar-core's never-throwing `Util\Tty` façade (an explicit
 * COLUMNS/LINES environment pair wins first; off a tty the app answers its
 * own 60x24 fallback). The app no longer touches the ioctl or the tty gate
 * directly — the descriptor discipline moved into the façade's seats, and
 * this file pins the delegation. The bin is a
 * shim; both files are scanned.
 */

declare(strict_types=1);

namespace SugarCraft\GifView\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\GifView\Cli;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\Posix\PosixPtyPair;

final class BinSizeQueryPolarityTest extends TestCase
{
    private const BIN = __DIR__ . '/../bin/candy-gifview';
    private const CLI = __DIR__ . '/../src/Cli.php';

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

    /** @return array<string, string> label => source text */
    private static function scanTargets(): array
    {
        $bin = file_get_contents(self::BIN);
        self::assertIsString($bin, 'the bin must exist next to the app');
        $cli = file_get_contents(self::CLI);
        self::assertIsString($cli, 'Cli.php must exist');

        return ['bin/candy-gifview' => $bin, 'src/Cli.php' => $cli];
    }

    /**
     * Every `(int)` cast applied to a standard stream — the bug's exact shape.
     *
     * Read from the TOKEN stream rather than the raw text on purpose: this
     * class's comments — and Cli.php's docblocks — quote `(int) STDOUT` to
     * explain why it is wrong, and a plain text scan would accuse the
     * documentation of the defect it documents. Comments carry no tokens here,
     * so they cannot offend.
     *
     * @return list<string> "file line N: (int) NAME" for each offender
     */
    private static function streamCasts(string $label, string $source): array
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
                    $found[] = "$label line {$cast[2]}: (int) {$next[1]}";
                }
                break;
            }
        }

        return $found;
    }

    public function testBinAndCliCarryNoStreamToIntCasts(): void
    {
        $offenders = [];
        foreach (self::scanTargets() as $label => $source) {
            $offenders = array_merge($offenders, self::streamCasts($label, $source));
        }

        $this->assertSame(
            [],
            $offenders,
            'an (int) cast of a stream yields the resource id, never the fd',
        );
    }

    public function testCliDelegatesTheSizeProbeToTheNeverThrowingTtyFacade(): void
    {
        $cli = self::scanTargets()['src/Cli.php'];

        $this->assertStringNotContainsString(
            'SizeIoctl',
            $cli,
            'the app must not hand a descriptor to the raw ioctl: the never-throwing'
                . ' Tty facade owns the probe, so no FFI failure can reach exit 255',
        );
        $this->assertStringContainsString(
            'new Tty(',
            $cli,
            'viewportCells() must probe through SugarCraft\\Core\\Util\\Tty',
        );

        // The rescue belt is structural: the probe arms must be wrapped so a
        // hypothetical facade failure degrades to the fallback grid.
        $probe = self::methodText($cli, 'viewportCells');
        $this->assertStringContainsString(
            'catch (\\Throwable)',
            $probe,
            'the size probe sits behind a catch-all belt',
        );
        $this->assertStringContainsString(
            'FALLBACK_CELLS_W',
            $probe,
            'the non-tty and probe-failure arms land on the documented 60x24 fallback',
        );
    }

    /** The text of one brace-flat method in $src, name through closing brace. */
    private static function methodText(string $src, string $name): string
    {
        $start = strpos($src, 'function ' . $name);
        self::assertNotFalse($start, "src/Cli.php must still define {$name}()");
        $end = strpos($src, "\n    }", $start);
        self::assertNotFalse($end, "unterminated {$name}() in src/Cli.php");

        return substr($src, $start, $end - $start);
    }

    public function testViewportCellsHonoursTheEnvironmentEscapeHatch(): void
    {
        $saved = [];
        foreach (['COLUMNS', 'LINES'] as $name) {
            $saved[$name] = \getenv($name);
        }

        try {
            \putenv('COLUMNS=100');
            \putenv('LINES=50');
            $this->assertSame(
                [100, 48],
                Cli::viewportCells(),
                'a valid COLUMNS/LINES pair wins over any probe, rows keep the 2-row reserve',
            );

            \putenv('LINES=2');
            $this->assertSame(
                [100, 1],
                Cli::viewportCells(),
                'max(1, rows - 2) floors even a two-row terminal',
            );

            \putenv('COLUMNS=12.5');
            \putenv('LINES=-5');
            [$cellsW, $cellsH] = Cli::viewportCells();
            if (!\is_resource(\STDOUT) || !\stream_isatty(\STDOUT)) {
                $this->assertSame(
                    [Cli::FALLBACK_CELLS_W, Cli::FALLBACK_CELLS_H],
                    [$cellsW, $cellsH],
                    'junk env values are ignored, not honoured',
                );
            } else {
                $this->assertGreaterThan(0, $cellsW);
                $this->assertGreaterThan(0, $cellsH);
            }

            \putenv('COLUMNS=100');
            \putenv('LINES');
            [$cellsW] = Cli::viewportCells();
            $this->assertSame(
                100,
                $cellsW,
                'COLUMNS alone still answers the width axis (per-axis hatch)',
            );
        } finally {
            foreach ($saved as $name => $value) {
                \putenv($value === false ? $name : "$name=$value");
            }
        }
    }

    public function testNeitherSurfaceReachesForExtPosix(): void
    {
        foreach (self::scanTargets() as $label => $source) {
            $this->assertStringNotContainsString(
                'posix_isatty',
                $source,
                "the viewer's production surfaces stay ext-posix free ($label)",
            );
        }
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

        $errLog = tempnam(sys_get_temp_dir(), 'sc_gifview_bin_errlog_');
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
            'the viewer asked the kernel for a size on a non-terminal fd — the (int) STDOUT '
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
     * gifview-008: the rows-2 viewport reserve, observable through a LIVE
     * kernel size query — not just "greater than 0". The child answers
     * json_encode(viewportCells()) with fd 1 wired to the resized slave and
     * COLUMNS/LINES unset in its environment, so the probe leg (Tty facade)
     * must run; the exit code is read this time, because a belt-swallowed
     * probe failure would answer the 60x24 fallback and we must not pass
     * vacuously. A 137x43 terminal yields [137, 41]: the two status rows the
     * renderer reserves are the whole difference. Second arm: rows 2 and 1 —
     * the max(1, …) floor must survive a stooopid-small terminal (mutation
     * check: `- 2` → `- 1` reddens arm one; deleting the floor reddens arm two).
     *
     * @return array{0: list<int>, 1: int} [cells, exit code]
     */
    private function probeThroughPty(PosixPtyPair $pair): array
    {
        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        $this->assertIsString($autoload, 'the lib vendor autoload must exist to probe in a child');

        $process = proc_open(
            ['timeout', '6', \PHP_BINARY, '-r',
                'require ' . var_export($autoload, true) . ';'
                . ' echo json_encode(\SugarCraft\GifView\Cli::viewportCells()), "\n";'],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $this->slavePath($pair), 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            // The escape hatch must stay shut: an inherited COLUMNS/LINES
            // would satisfy viewportCells() before the probe leg and the
            // pty would prove nothing. null unsets for the child.
            ['COLUMNS' => null, 'LINES' => null],
        );
        $this->assertIsResource($process, 'could not start the probe child');
        if (\is_resource($pipes[0] ?? null)) {
            fclose($pipes[0]);
        }

        $deadline = \microtime(true) + 5.0;
        $out = '';
        while (\microtime(true) < $deadline) {
            $chunk = $pair->master()->read(4096, 0.1);
            if ($chunk !== null && $chunk !== '') {
                $out .= $chunk;
                if (\str_contains($out, "\n")) {
                    break;
                }
            }
        }
        $stderr = (string) stream_get_contents($pipes[2]);
        if (\is_resource($pipes[2])) {
            fclose($pipes[2]);
        }

        // timeout(1) bounds a wedged child; proc_close blocks until the
        // child is dead and — the measured fact here, PHP 8.3.6 — carries
        // its exit code (proc_get_status loses the key once the internal
        // SIGCHLD reaper has waited the child).
        $exit = proc_close($process);

        $this->assertNotSame(
            '',
            $out,
            'the probe child answered nothing on the pty; child stderr: ' . $stderr,
        );
        $this->assertSame(0, $exit, 'the probe child must exit cleanly, saw stderr: ' . $stderr);

        $line = \str_replace("\r", '', \explode("\n", $out)[0]);
        $cells = json_decode($line, true);
        $this->assertIsArray($cells, 'the probe child answered non-JSON: ' . $line);

        /** @var list<int> $cells */
        return [$cells, $exit];
    }

    public function testViewportCellsReservesTwoStatusRowsOnALivePty(): void
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
        // Vacuity guard, same measured fact as the bin test: an unresized pty
        // reports 0x0, so the resize pin below failing must stop the test.
        $this->assertSame(
            ['rows' => self::PTY_ROWS, 'cols' => self::PTY_COLS, 'xpix' => 0, 'ypix' => 0],
            $pair->master()->size(),
            'the pty did not take the size the fixture asked for',
        );

        [$cells] = $this->probeThroughPty($pair);
        $this->assertSame(
            [self::PTY_COLS, self::PTY_ROWS - 2],
            $cells,
            'a 137x43 terminal must answer [137, 41] — the reserve is two rows, observed live',
        );

        foreach ([2, 1] as $rows) {
            $pair->master()->resize(self::PTY_COLS, $rows);
            [$cells] = $this->probeThroughPty($pair);
            $this->assertSame(
                [self::PTY_COLS, 1],
                $cells,
                "max(1, rows - 2) floors an {$rows}-row terminal to a one-row viewport",
            );
        }
    }

    /**
     * A decodable-if-GD-exists GIF on disk. Whether the child can actually
     * decode it is beside the point: the size query happens BEFORE any decode,
     * so even the ext-gd refusal proves the guard's half that matters — the
     * bin got past descriptor 1 without a non-tty throw.
     */
    private function gifFixture(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sc_gifview_bin_') . '.gif';
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

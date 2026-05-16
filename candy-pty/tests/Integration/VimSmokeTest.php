<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\PtySystemFactory;
use SugarCraft\Vt\Terminal\Terminal;

/**
 * End-to-end smoke test for `vim` under a real PTY.
 *
 * Drives the canonical "open file, type Hello, save, quit" dialog and
 * asserts both the on-disk artifact and an in-memory candy-vt screen
 * snapshot showing "Hello" appeared on the editor grid before the
 * `:wq` write. Vim is the most demanding child we exercise — it uses
 * the alternate screen buffer, drives SIGWINCH on resize, and reacts
 * to escape sequences with sub-second cadence — so a passing run
 * means the PTY plus the SIGWINCH path plus the cooked-mode write
 * path are all healthy.
 *
 * @see plans/sugarcraft-is-a-mono-logical-twilight.md (P5.3)
 */
final class VimSmokeTest extends TestCase
{
    private const VIM_PATH = '/usr/bin/vim';
    private const WALLCLOCK_BUDGET_SEC = 8.0;

    /**
     * POSIX + FFI prerequisites — must run before any PTY syscall.
     * Mirrors {@see InteractiveShellTestCase::requirePtySyscalls()}.
     */
    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only; Windows ConPTY is a separate port.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required to exercise the libc PTY syscalls.');
        }
        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped('ext-pcntl is required to fork the vim child.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
    }

    public function testVimEditSaveQuitRoundTrip(): void
    {
        $this->requirePtySyscalls();
        if (!\is_executable(self::VIM_PATH)) {
            $this->markTestSkipped(\sprintf('vim not installed at %s', self::VIM_PATH));
        }

        // Per-PID + random suffix so concurrent test runs don't collide.
        $scratch = \sprintf(
            '%s/sugarcraft-pty-vim-%d-%s.txt',
            \sys_get_temp_dir(),
            \getmypid(),
            \bin2hex(\random_bytes(4)),
        );
        // Pre-truncate so vim opens an empty buffer rather than landing
        // on a "press ENTER" prompt or the swap-file recovery menu.
        @\unlink($scratch);

        $system = PtySystemFactory::default();
        $pair = $system->open(80, 24);
        $master = $pair->master();
        $term = Terminal::create(80, 24);
        $child = null;

        try {
            $child = $pair->slave()->spawn(
                [self::VIM_PATH, '-u', 'NONE', '-i', 'NONE', '-N', $scratch],
                [
                    'TERM' => 'xterm-256color',
                    'PATH' => \getenv('PATH') ?: '/usr/bin:/bin',
                    'LANG' => 'C',
                    'LC_ALL' => 'C',
                    'HOME' => \sys_get_temp_dir(),
                ],
                80,
                24,
                controllingTerminal: true,
            );

            \stream_set_blocking($master->stream(), false);

            // Let vim paint its initial screen — alt-screen entry, status
            // line, tildes. 0.5 s is more than enough on a real box.
            $this->pumpInto($term, $master, $child, 0.5);

            // Enter insert mode + type the literal "Hello".
            $master->write('i');
            $this->pumpInto($term, $master, $child, 0.3);
            $master->write('Hello');
            $this->pumpInto($term, $master, $child, 0.5);

            // Screen-assert BEFORE saving: "Hello" must appear somewhere
            // on the grid. Vim's status line and gutter shift between
            // versions so we scan rather than asserting fixed coords.
            $this->assertScreenContains(
                $term,
                'Hello',
                'vim must render "Hello" on the cell grid before :wq',
            );

            // Escape back to normal mode, then :wq<CR>.
            $master->write("\x1b");
            $this->pumpInto($term, $master, $child, 0.3);
            $master->write(":wq\r");

            // Wait for vim to exit on its own (writes buffer + quits).
            $deadline = \microtime(true) + self::WALLCLOCK_BUDGET_SEC;
            while (\microtime(true) < $deadline && !$child->exited()) {
                $this->pumpInto($term, $master, $child, 0.1);
            }

            $this->assertTrue(
                $child->exited(),
                'vim did not exit within the wallclock budget after :wq',
            );

            $exit = $child->wait();
            $this->assertSame(0, $exit, 'vim exited non-zero after :wq');

            $this->assertFileExists($scratch);
            $this->assertSame(
                "Hello\n",
                \file_get_contents($scratch),
                'vim must have written exactly "Hello\\n" to the scratch file',
            );
        } finally {
            if ($child !== null && !$child->exited()) {
                try {
                    $child->kill(MasterPty::SIGKILL);
                } catch (\Throwable) {
                    // Ignore — process may have raced to exit.
                }
                try {
                    $child->wait();
                } catch (\Throwable) {
                    // Ignore — wait may fail if pcntl already reaped.
                }
            }
            if (!$master->isClosed()) {
                $master->close();
            }
            if (\is_file($scratch)) {
                @\unlink($scratch);
            }
            // Vim leaves a swap file on abnormal exit; sweep it.
            $swap = \dirname($scratch) . '/.' . \basename($scratch) . '.swp';
            if (\is_file($swap)) {
                @\unlink($swap);
            }
        }
    }

    /**
     * Drain master non-blocking for $window seconds, feeding every
     * byte into the candy-vt terminal so callers can screen-assert
     * mid-test. Exits early if the child has exited and EOF arrives.
     */
    private function pumpInto(Terminal $term, MasterPty $master, \SugarCraft\Pty\Contract\Child $child, float $window): void
    {
        $deadline = \microtime(true) + $window;
        while (\microtime(true) < $deadline) {
            $chunk = $master->read(8192, 0.05);
            if ($chunk === null) {
                if ($child->exited()) {
                    $tail = $master->read(8192, 0.05);
                    if ($tail !== null && $tail !== '') {
                        $term->feed($tail);
                    }
                    return;
                }
                continue;
            }
            if ($chunk === '') {
                return;
            }
            $term->feed($chunk);
        }
    }

    /**
     * Scan the candy-vt screen row-by-row for $needle. We don't pin
     * coordinates because vim's status line / cursor line position
     * varies with version, signcolumn, and tabline defaults.
     */
    private function assertScreenContains(Terminal $term, string $needle, string $message): void
    {
        $screen = $term->screen();
        for ($r = 0; $r < $screen->rows; $r++) {
            $line = '';
            for ($c = 0; $c < $screen->cols; $c++) {
                $cell = $screen->cell($r, $c);
                if ($cell->continuation) {
                    continue;
                }
                $line .= $cell->grapheme;
            }
            if (\str_contains($line, $needle)) {
                $this->addToAssertionCount(1);
                return;
            }
        }
        $this->fail($message);
    }
}

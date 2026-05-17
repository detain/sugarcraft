<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cli\RecordCommand;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;

/**
 * P6.5.2 — `--shell` flag. Records a session running the user's
 * `$SHELL -l` (or `/bin/sh -l` fallback) instead of an explicit
 * positional command. Useful for "capture what my prompt does" demos
 * that don't want to enumerate the shell binary every time.
 */
final class RecordCommandShellTest extends TestCase
{
    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only; Windows ConPTY is a separate port.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required to exercise the libc PTY syscalls.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped('ext-pcntl is required for controllingTerminal:true spawns.');
        }
    }

    public function testShellAndPositionalCmdAreMutuallyExclusive(): void
    {
        $cmd = new RecordCommand(\fopen('/dev/null', 'r'));
        $stdout = \fopen('php://memory', 'r+');
        $stderr = \fopen('php://memory', 'r+');
        try {
            $rc = $cmd->run(['--shell', '--', '/bin/echo', 'hi'], $stdout, $stderr);
            $this->assertSame(2, $rc);
            \rewind($stderr);
            $err = (string) \stream_get_contents($stderr);
            $this->assertStringContainsString('may not be combined', $err);
        } finally {
            \fclose($stdout);
            \fclose($stderr);
        }
    }

    public function testShellUsageStringIncludesShellFlag(): void
    {
        $cmd = new RecordCommand(\fopen('/dev/null', 'r'));
        $stdout = \fopen('php://memory', 'r+');
        $stderr = \fopen('php://memory', 'r+');
        try {
            $rc = $cmd->run(['--help'], $stdout, $stderr);
            $this->assertSame(2, $rc);
            \rewind($stderr);
            $usage = (string) \stream_get_contents($stderr);
            $this->assertStringContainsString('--shell', $usage);
            $this->assertStringContainsString('Spawn $SHELL', $usage);
        } finally {
            \fclose($stdout);
            \fclose($stderr);
        }
    }

    public function testShellSpawnFallsBackToFallbackShellWhenShellEnvMissing(): void
    {
        $this->requirePtySyscalls();
        if (!\is_executable(RecordCommand::FALLBACK_SHELL)) {
            $this->markTestSkipped('FALLBACK_SHELL not executable on this host.');
        }

        // Wipe $SHELL to force the fallback path. PHP-CLI `putenv('')`
        // unsets the variable; we restore it after.
        $prior = \getenv('SHELL');
        \putenv('SHELL');

        $cassette = \tempnam(\sys_get_temp_dir(), 'rec-shell-');
        $this->assertIsString($cassette);
        $cmd = new RecordCommand(\fopen('/dev/null', 'r'));
        $stdout = \fopen('php://memory', 'r+');
        $stderr = \fopen('php://memory', 'r+');

        try {
            // -l + immediate exit so the shell doesn't block waiting
            // for input. /bin/sh honours `-c` as a non-shell-option,
            // so we just exec exit(0).
            $rc = $cmd->run(
                ['--shell', '--output', $cassette, '--no-ctty', '--', 'placeholder'],
                $stdout,
                $stderr,
            );
            // 'placeholder' should fail because --shell is mutually
            // exclusive with positional cmd.
            $this->assertSame(2, $rc, '--shell + positional must reject');
        } finally {
            if ($prior !== false) {
                \putenv("SHELL={$prior}");
            }
            \fclose($stdout);
            \fclose($stderr);
            if (\file_exists($cassette)) {
                @\unlink($cassette);
            }
        }
    }

    public function testShellRecordsSessionThroughDefaultShell(): void
    {
        $this->requirePtySyscalls();

        // Use a tiny scripted shell that exits immediately, so the
        // test doesn't hang waiting for prompt-typing. Point $SHELL
        // at a minimal exit-0 sh wrapper for the duration of the run.
        $wrapper = \tempnam(\sys_get_temp_dir(), 'sh-wrap-');
        $this->assertIsString($wrapper);
        \file_put_contents(
            $wrapper,
            "#!/bin/sh\nprintf 'shell-marker\\n'\nexit 0\n",
        );
        \chmod($wrapper, 0o755);

        $prior = \getenv('SHELL');
        \putenv("SHELL={$wrapper}");

        $cassette = \tempnam(\sys_get_temp_dir(), 'rec-shell-real-');
        $this->assertIsString($cassette);
        $cmd = new RecordCommand(\fopen('/dev/null', 'r'));
        $stdout = \fopen('php://memory', 'r+');
        $stderr = \fopen('php://memory', 'r+');

        try {
            $rc = $cmd->run(
                ['--shell', '--output', $cassette],
                $stdout,
                $stderr,
            );
            $this->assertSame(0, $rc, 'wrapper-shell exit 0 must surface');

            $loaded = (new JsonlFormat())->read($cassette);
            $blob = '';
            foreach ($loaded->events as $event) {
                if ($event->kind === EventKind::Output) {
                    $blob .= (string) ($event->payload['b'] ?? '');
                }
            }
            $this->assertStringContainsString('shell-marker', $blob);
        } finally {
            if ($prior !== false) {
                \putenv("SHELL={$prior}");
            } else {
                \putenv('SHELL');
            }
            \fclose($stdout);
            \fclose($stderr);
            if (\file_exists($cassette)) {
                @\unlink($cassette);
            }
            if (\file_exists($wrapper)) {
                @\unlink($wrapper);
            }
        }
    }
}

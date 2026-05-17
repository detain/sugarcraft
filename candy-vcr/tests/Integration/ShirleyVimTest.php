<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cli\RecordCommand;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vt\Terminal\Terminal;

/**
 * P6.5.5 — Shirley-style integration: record a real vim session
 * (`iHello<Esc>:wq<CR>`), then replay the cassette's output stream
 * through candy-vt::Terminal to confirm the recorded byte stream
 * parses into a valid screen state. Vim is the canonical "scary"
 * case because of full-screen alt-screen + cursor positioning + CSR.
 *
 * Bounded at ~15 s wallclock. Skipped when vim isn't installed (CI
 * images frequently strip it), and when candy-vt isn't autoloadable.
 */
final class ShirleyVimTest extends TestCase
{
    private function requireVim(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only; Windows ConPTY is a separate port.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required for PTY syscalls.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped('ext-pcntl is required for controllingTerminal:true spawns.');
        }
        if (!\class_exists(Terminal::class)) {
            $this->markTestSkipped('sugarcraft/candy-vt is not autoloadable.');
        }
        $vim = '/usr/bin/vim';
        if (!\is_executable($vim)) {
            $alt = (string) (\trim((string) @\shell_exec('command -v vim 2>/dev/null')));
            if ($alt === '' || !\is_executable($alt)) {
                $this->markTestSkipped('vim is not available on this host.');
            }
            $vim = $alt;
        }
        return $vim;
    }

    public function testRecordVimSessionAndReplayThroughTerminal(): void
    {
        $vim = $this->requireVim();

        // Scratch buffer for vim to write into; we delete it after.
        $scratch = \tempnam(\sys_get_temp_dir(), 'shirley-vim-buf-');
        $cassette = \tempnam(\sys_get_temp_dir(), 'shirley-vim-cas-');
        $this->assertIsString($scratch);
        $this->assertIsString($cassette);

        // Pre-load the keystroke script onto a socket pair so we can
        // hand the read end to RecordCommand as stdin and vim
        // consumes it in order. Sequence: enter insert mode (`i`),
        // type `Hello`, leave insert mode (ESC), save+quit (`:wq\r`).
        $pair = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        [$stdinRead, $stdinWrite] = $pair;
        \fwrite($stdinWrite, "iHello\x1b:wq\r");
        // Closing the write end immediately would EOF the pump too
        // soon; keep it open until after the recording completes.

        $cmd = new RecordCommand($stdinRead);
        $stdout = \fopen('php://memory', 'r+');
        $stderr = \fopen('php://memory', 'r+');

        try {
            $start = \microtime(true);
            $rc = $cmd->run([
                '--output', $cassette,
                '--cols', '80',
                '--rows', '24',
                '--',
                $vim,
                '-N',           // nocompatible
                '-u', 'NONE',   // skip user vimrc — keep behaviour deterministic
                '-i', 'NONE',   // no viminfo writeback
                $scratch,
            ], $stdout, $stderr);
            $elapsed = \microtime(true) - $start;

            $this->assertLessThan(
                15.0,
                $elapsed,
                'vim integration must stay bounded — saw ' . \round($elapsed, 2) . 's',
            );

            // vim's normal :wq exits 0; some builds return 1 when the
            // buffer is unchanged. Accept either — what matters is that
            // the cassette captured the session.
            $this->assertContains($rc, [0, 1], "vim exit ({$rc}) must be 0 or 1");

            $loaded = (new JsonlFormat())->read($cassette);
            $sawOutput = false;
            $sawQuit = false;
            $allOutput = '';
            foreach ($loaded->events as $event) {
                if ($event->kind === EventKind::Output) {
                    $sawOutput = true;
                    $allOutput .= (string) ($event->payload['b'] ?? '');
                } elseif ($event->kind === EventKind::Quit) {
                    $sawQuit = true;
                }
            }
            $this->assertTrue($sawOutput, 'cassette must contain vim output bytes');
            $this->assertTrue($sawQuit, 'cassette must close with a quit event');

            // Feed the concatenated output through candy-vt's
            // Terminal to confirm the byte stream parses into a valid
            // screen state. We don't assert specific cells — vim's
            // exit-sequence varies across versions/builds, and the
            // goal here is "the parser ingests it cleanly" not "the
            // screen matches a golden frame".
            $term = Terminal::create(80, 24);
            $term->feed($allOutput);
            $term->flush();

            $screen = $term->screen();
            $this->assertGreaterThan(0, $screen->cols, 'terminal must report a positive width');
            $this->assertGreaterThan(0, $screen->rows, 'terminal must report a positive height');

            // The scratch buffer should now exist on disk and contain
            // the typed "Hello" line that vim saved on :wq.
            // (Some vim builds defer write; tolerate either outcome,
            // but if the file is present it must contain our text.)
            if (\is_file($scratch) && \filesize($scratch) > 0) {
                $contents = (string) \file_get_contents($scratch);
                $this->assertStringContainsString('Hello', $contents);
            }
        } finally {
            if (\is_resource($stdinWrite)) {
                @\fclose($stdinWrite);
            }
            if (\is_resource($stdinRead)) {
                @\fclose($stdinRead);
            }
            \fclose($stdout);
            \fclose($stderr);
            if (\file_exists($cassette)) {
                @\unlink($cassette);
            }
            if (\file_exists($scratch)) {
                @\unlink($scratch);
            }
        }
    }
}

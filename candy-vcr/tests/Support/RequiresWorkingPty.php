<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Support;

use SugarCraft\Vcr\Cli\RecordCommand;
use SugarCraft\Vcr\Format\JsonlFormat;

/**
 * Shared gate for tests that drive the real PTY layer.
 *
 * Two-phase check:
 *  1. Extension/device probes (ext-ffi, ext-pcntl, /dev/ptmx, /bin/echo).
 *  2. A one-shot smoke spawn through {@see RecordCommand} recording
 *     `/bin/echo ok` into a throwaway cassette. Some CI environments
 *     load every extension cleanly but still fail to bring the
 *     pty-shim subprocess up (sandboxed runners, missing
 *     CAP_SYS_PTRACE, broken vendor autoload for the shim). The
 *     symptom is a non-zero exit on a trivially-successful child,
 *     which then masquerades as a candy-vcr regression on every
 *     downstream PTY test. The smoke spawn flushes that out once per
 *     class and {@see markTestSkipped()}'s the suite when it trips.
 *
 * Cached per-process so the smoke spawn runs once across the whole
 * test run, not once per `requirePtySyscalls()` call.
 */
trait RequiresWorkingPty
{
    /** @var array{ok: bool, reason: string}|null */
    private static ?array $ptyProbeCache = null;

    private function requirePtySyscalls(): void
    {
        $probe = $this->probePty();
        if (!$probe['ok']) {
            $this->markTestSkipped($probe['reason']);
        }
    }

    /**
     * @return array{ok: bool, reason: string}
     */
    private function probePty(): array
    {
        if (self::$ptyProbeCache !== null) {
            return self::$ptyProbeCache;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return self::$ptyProbeCache = [
                'ok' => false,
                'reason' => 'candy-pty is POSIX-only; Windows ConPTY is a separate port.',
            ];
        }
        if (!\extension_loaded('ffi')) {
            return self::$ptyProbeCache = [
                'ok' => false,
                'reason' => 'ext-ffi is required to exercise the libc PTY syscalls.',
            ];
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            return self::$ptyProbeCache = [
                'ok' => false,
                'reason' => '/dev/ptmx is unreadable/unwritable on this host.',
            ];
        }
        if (!\extension_loaded('pcntl')) {
            return self::$ptyProbeCache = [
                'ok' => false,
                'reason' => 'ext-pcntl is required for controllingTerminal:true spawns.',
            ];
        }
        if (!\is_executable('/bin/echo')) {
            return self::$ptyProbeCache = [
                'ok' => false,
                'reason' => '/bin/echo is not executable on this host.',
            ];
        }

        // Smoke spawn: record `/bin/echo ok` and confirm exit-code 0.
        // The pty-shim subprocess loads its own autoloader and any
        // breakage there surfaces as a non-zero exit (typically 3),
        // which is what was failing every PTY test on Ubuntu CI.
        $cassette = \tempnam(\sys_get_temp_dir(), 'pty-probe-');
        if (!\is_string($cassette)) {
            return self::$ptyProbeCache = [
                'ok' => false,
                'reason' => 'tempnam() failed; cannot run pty probe.',
            ];
        }

        $devNullIn = @\fopen('/dev/null', 'r');
        $stdout = \fopen('php://memory', 'r+');
        $stderr = \fopen('php://memory', 'r+');
        if (!\is_resource($devNullIn) || !\is_resource($stdout) || !\is_resource($stderr)) {
            if (\is_resource($devNullIn)) {
                \fclose($devNullIn);
            }
            if (\is_resource($stdout)) {
                \fclose($stdout);
            }
            if (\is_resource($stderr)) {
                \fclose($stderr);
            }
            @\unlink($cassette);
            return self::$ptyProbeCache = [
                'ok' => false,
                'reason' => 'pty probe could not allocate scratch streams.',
            ];
        }

        $errText = '';
        try {
            $cmd = new RecordCommand($devNullIn);
            $rc = $cmd->run(
                ['--output', $cassette, '--', '/bin/echo', 'ok'],
                $stdout,
                $stderr,
            );
        } catch (\Throwable $e) {
            $rc = -1;
            \rewind($stderr);
            $errText = (string) \stream_get_contents($stderr);
            return self::$ptyProbeCache = [
                'ok' => false,
                'reason' => 'pty probe threw: ' . $e->getMessage() . ' (' . \trim($errText) . ')',
            ];
        } finally {
            if (\is_resource($devNullIn)) {
                \fclose($devNullIn);
            }
            if (\is_resource($stdout)) {
                \fclose($stdout);
            }
            \rewind($stderr);
            $errText = (string) \stream_get_contents($stderr);
            if (\is_resource($stderr)) {
                \fclose($stderr);
            }
            // Make sure cassette is cleaned up even if assertions later
            // skip the test.
            if (\file_exists($cassette)) {
                @\unlink($cassette);
            }
        }

        if ($rc !== 0) {
            return self::$ptyProbeCache = [
                'ok' => false,
                'reason' => "pty-shim smoke spawn exited {$rc} (expected 0); "
                    . 'PTY layer is not fully usable in this environment. '
                    . \trim($errText),
            ];
        }

        // Last check: the cassette must contain at least one event, otherwise
        // the recorder front-end was a no-op even though the subprocess exited
        // cleanly — also indicates a broken setup.
        try {
            $loaded = (new JsonlFormat())->read($cassette);
            if ($loaded->eventCount() === 0) {
                return self::$ptyProbeCache = [
                    'ok' => false,
                    'reason' => 'pty probe produced an empty cassette; recorder front-end is broken.',
                ];
            }
        } catch (\Throwable $e) {
            return self::$ptyProbeCache = [
                'ok' => false,
                'reason' => 'pty probe cassette could not be read: ' . $e->getMessage(),
            ];
        }

        return self::$ptyProbeCache = ['ok' => true, 'reason' => ''];
    }
}

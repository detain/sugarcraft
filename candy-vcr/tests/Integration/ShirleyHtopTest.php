<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cli\RecordCommand;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vcr\Support\Scalars;
use SugarCraft\Vcr\Tests\Support\RequiresWorkingPty;
use SugarCraft\Vcr\Tests\Support\Stream;

/**
 * P6.5.5 — Shirley-style integration: record a real htop session,
 * verify the cassette contains the canonical alt-screen enter/leave
 * sequence (\x1b[?1049h / \x1b[?1049l).
 *
 * htop is the reference terminal app that uses the alternate screen
 * buffer for its full UI. bash and vim use it too, but htop is the
 * cleanest "pure alternate screen" exerciser because it exits
 * immediately via -n 1 (one refresh, then quit) with no user
 * interaction required.
 *
 * Skipped when htop is not installed or when the host fails the shared
 * working-PTY gate (probes + smoke spawn).
 */
final class ShirleyHtopTest extends TestCase
{
    use RequiresWorkingPty;

    private const ALT_SCREEN_ENTER = "\x1b[?1049h";
    private const ALT_SCREEN_LEAVE  = "\x1b[?1049l";

    private function requireHtop(): void
    {
        // VCR-S45 (finding 049): the shared two-phase gate — device/
        // extension probes AND the one-shot smoke spawn — replaces the
        // hand-duplicated probe list, which let broken-PTY hosts (all
        // probes green, shim dead) through to a hard FAIL. The binary
        // arm stays local: htop just is not installed on many hosts,
        // and on Windows it never is (findHtop returns null there and
        // the skip names availability, not the platform).
        $this->requirePtySyscalls();
        $htop = $this->findHtop();
        if ($htop === null) {
            $this->markTestSkipped('htop is not available on this host.');
        }
    }

    /**
     * Locate the htop binary, checking common paths.
     */
    private function findHtop(): ?string
    {
        $paths = [
            '/usr/bin/htop',
            '/bin/htop',
            '/usr/local/bin/htop',
        ];
        foreach ($paths as $p) {
            if (\is_executable($p)) {
                return $p;
            }
        }
        $which = @\trim((string) \shell_exec('command -v htop 2>/dev/null'));
        if ($which !== '' && \is_executable($which)) {
            return $which;
        }
        return null;
    }

    /**
     * Acceptance pin for the gate adoption (finding 049): when the
     * shared probe reports not-ok, the test SKIPS with the probe's
     * reason — it never proceeds to a hard FAIL. The cache property is
     * per-using-class (trait static), so this stays inside this file.
     */
    public function testBrokenPtyProbeSkipsInsteadOfFailing(): void
    {
        $cache = new \ReflectionProperty(self::class, 'ptyProbeCache');
        $cache->setValue(null, ['ok' => false, 'reason' => 'simulated dead shim']);
        try {
            $this->requireHtop();
            $this->fail('requireHtop() must skip when the PTY gate reports not-ok');
        } catch (\PHPUnit\Framework\SkippedWithMessageException $e) {
            $this->assertStringContainsString('simulated dead shim', $e->getMessage());
        } finally {
            $cache->setValue(null, null);
        }
    }

    public function testRecordHtopAltScreenSequence(): void
    {
        $this->requireHtop();

        $cassette = \tempnam(\sys_get_temp_dir(), 'shirley-htop-');
        $this->assertIsString($cassette);

        // htop -n 1: collect one screen refresh then quit automatically.
        // No user interaction needed; deterministic exit.
        $pair = \SugarCraft\Vcr\Support\SocketPair::open();
        $this->assertIsArray($pair);
        [$stdinRead, $stdinWrite] = $pair;

        $cmd = new RecordCommand($stdinRead);
        $stdout = Stream::memory();
        $stderr = Stream::memory();

        try {
            $start = \microtime(true);
            // Use --no-ctty so Ctrl-C / SIGINT from the pump's EOF
            // handling does not send SIGINT to htop itself (which can
            // cause it to SIGABRT on some htop builds).
            $rc = $cmd->run([
                '--force', '--output', $cassette,
                '--no-ctty',
                '--cols', '80',
                '--rows', '24',
                '--',
                'htop', '-n', '1',
            ], $stdout, $stderr);
            $elapsed = \microtime(true) - $start;

            // htop -n 1 should exit quickly; give it generous headroom.
            $this->assertLessThan(
                15.0,
                $elapsed,
                'htop integration must stay bounded — saw ' . \round($elapsed, 2) . 's',
            );

            // htop -n 1 typically exits 0, but may exit 6 (SIGABRT)
            // in some PTY/containers where htop's internal resize handler
            // hits a fatal path. The cassette is still valid — what matters
            // is that we recorded a quit event and the alt-screen sequences.
            $this->assertContains($rc, [0, 6], 'htop -n 1 must exit 0 or 6');

            // Walk the cassette and collect all output bytes.
            $loaded = (new JsonlFormat())->read($cassette);
            $allOutput = '';
            $sawQuit = false;
            foreach ($loaded->events as $event) {
                if ($event->kind === EventKind::Output) {
                    $allOutput .= Scalars::string($event->payload['b'] ?? '', 'recorded output bytes');
                } elseif ($event->kind === EventKind::Quit) {
                    $sawQuit = true;
                }
            }

            $this->assertTrue($sawQuit, 'cassette must close with a quit event');
            $this->assertNotSame('', $allOutput, 'cassette must contain htop output bytes');

            // Assert the cassette opens and closes with the alternate-screen
            // escape sequences. \x1b[?1049h enters alternate screen;
            // \x1b[?1049l leaves it.  htop uses this exclusively — bash
            // and vim may or may not depending on $TERM settings.
            $firstScreenSeq = \strpos($allOutput, self::ALT_SCREEN_ENTER);
            $this->assertNotFalse(
                $firstScreenSeq,
                'cassette must contain the alternate-screen enter sequence \x1b[?1049h; got: '
                . $this->formatBytes($allOutput),
            );

            $lastLeaveSeq = \strrpos($allOutput, self::ALT_SCREEN_LEAVE);
            $this->assertNotFalse(
                $lastLeaveSeq,
                'cassette must contain the alternate-screen leave sequence \x1b[?1049l; got: '
                . $this->formatBytes($allOutput),
            );

            // The enter must appear before the leave (session lifecycle).
            $this->assertLessThan(
                $lastLeaveSeq,
                $firstScreenSeq,
                'alternate-screen enter must precede leave in the session lifecycle',
            );
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
        }
    }

    /**
     * Format a byte string for readable assertion messages — show hex
     * for non-printable ranges and escape sequences.
     *
     * @param string $bytes
     */
    private function formatBytes(string $bytes): string
    {
        $hex = \bin2hex($bytes);
        if (\strlen($hex) > 128) {
            $hex = \substr($hex, 0, 128) . '...(' . \strlen($bytes) . ' bytes total)';
        }
        return $hex;
    }
}

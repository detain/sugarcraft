<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Examples;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Tests\Support\PtyProbe;

/**
 * Finding 001: the flagship demo fatal-crashed on a real PTY while every
 * pipe-based harness stayed green. dashboard-live.php guards on
 * stream_isatty(STDOUT) and the boxer TypeError only fired at the live
 * 24×80 geometry, so no non-TTY run could ever reach it. This leg drives
 * the script on a genuine PTY at the finding's geometry and pins the
 * contract the old harnesses could not see: the program must boot, keep
 * the loop alive, answer `q`, and exit clean.
 */
final class DashboardLivePtySmokeTest extends TestCase
{
    public function testDashboardLiveBootsQuitsAndNeverFatalsUnderRealPty(): void
    {
        if (!PtyProbe::usable()) {
            $this->markTestSkipped(
                'No usable PTY on this host (needs POSIX + working ext-ffi + /dev/ptmx).'
            );
        }

        $result = PtyProbe::run(
            cmd: [PHP_BINARY, dirname(__DIR__, 2) . '/examples/dashboard-live.php'],
            cols: 80,
            rows: 24,
            input: "q\r",
            watch: fn (string $out): bool => str_contains($out, 'SugarDashboard Interactive Dashboard'),
            timeoutSeconds: 20.0,
        );

        $this->assertFalse($result['timedOut'], 'dashboard-live.php never exited within 20s; writes=' . $result['writes']);
        $this->assertStringNotContainsString('Fatal error', $result['output'], 'the flagship demo died with a PHP fatal under a real PTY');
        $this->assertStringNotContainsString('TypeError', $result['output'], 'Node render union must not leak a TypeError into the frame stream');
        $this->assertSame(0, $result['rc'], 'exit code: ' . var_export($result['rc'], true));
        $this->assertStringContainsString('Goodbye from SugarDashboard!', $result['output'], 'the quit key must be handled end-to-end over the PTY');
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\BuiltIn\ShellSend;

/**
 * ShellSend::relayReady() is the one readiness rule for an interactive shell's
 * relay. The pid record it waits on must name a pid, not merely exist: an
 * empty pid file is a write in progress, and a ShellStop that reads it finds no
 * process group to signal.
 */
final class ShellSendRelayReadyTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];
    }

    private function pidFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'relay-pid-');
        $this->assertNotFalse($path);
        $this->files[] = $path;
        file_put_contents($path, $contents);

        return $path;
    }

    public function testAPositivePidRecordIsReady(): void
    {
        $this->assertTrue(ShellSend::relayReady(['ptyPidPath' => $this->pidFile("4242\n")]));
    }

    public function testAnEmptyPidRecordIsNotReadyUntilTheBudgetPasses(): void
    {
        // A zero-byte file is the truncated window of a non-atomic write: the
        // relay has not recorded its pid yet, so the gate must not open on it.
        $this->assertFalse(ShellSend::relayReady(['ptyPidPath' => $this->pidFile('')]));
    }

    public function testARowWithNoPidPathIsReadyControl(): void
    {
        // Control: a pre-relay merged row names no pid path at all, and the
        // gate has always let it through, so the tightened check must not
        // reach that branch.
        $this->assertTrue(ShellSend::relayReady([]));
    }
}

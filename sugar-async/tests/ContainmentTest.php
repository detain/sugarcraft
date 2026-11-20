<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Async\Containment;
use SugarCraft\Async\WindowsPipes;
use SugarCraft\Core\Util\Proc\BoundedShutdown;

final class ContainmentTest extends TestCase
{
    private function requireGroupKill(): void
    {
        if (WindowsPipes::runtimeNeedsIt() || !function_exists('posix_kill') || !is_executable('/usr/bin/setsid')) {
            $this->markTestSkipped('needs posix_kill, /usr/bin/setsid and a POSIX runtime');
        }
    }

    public function testGroupIdIsNullForAResourceThatIsNotAChild(): void
    {
        $this->assertNull(Containment::groupId(null));
        $this->assertNull(Containment::groupId('not a process'));
    }

    public function testGroupIdIsNullForAChildThatLeadsNoGroup(): void
    {
        $proc = proc_open([PHP_BINARY, '-r', 'usleep(300000);'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        try {
            // An unwrapped child inherits this process's group, so no group id is reported.
            $this->assertNull(Containment::groupId($proc));
        } finally {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            BoundedShutdown::terminateBounded($proc);
        }
    }

    public function testTerminateBoundedKillsTheWholeGroupIncludingGrandchildren(): void
    {
        $this->requireGroupKill();
        $proc = proc_open(
            ['/usr/bin/setsid', 'sh', '-c', 'sleep 30 & echo $!; wait'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);
        // The grandchild's pid is written only after setsid(1) has run, so reading
        // it first makes the group measurement below deterministic.
        $grandchild = (int) trim((string) fgets($pipes[1]));
        $this->assertGreaterThan(0, $grandchild);
        $groupPid = Containment::groupId($proc);
        $this->assertSame(proc_get_status($proc)['pid'], $groupPid, 'the setsid child leads its own group');

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        Containment::terminateBounded($proc, $groupPid);

        $this->assertTrue(
            BoundedShutdown::waitBounded(static fn (): bool => BoundedShutdown::pidExited($grandchild), 3.0),
            'the group ladder must reach the grandchild',
        );
    }

    public function testTerminateSignalsTheGroupWhenTheChildLeadsOne(): void
    {
        $this->requireGroupKill();
        $proc = proc_open(
            ['/usr/bin/setsid', 'sh', '-c', 'sleep 30 & echo $!; wait'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);
        $grandchild = (int) trim((string) fgets($pipes[1]));

        $this->assertTrue(Containment::terminate($proc, 15));
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        BoundedShutdown::terminateBounded($proc);

        $this->assertTrue(
            BoundedShutdown::waitBounded(static fn (): bool => BoundedShutdown::pidExited($grandchild), 3.0),
            'terminate() must measure the group and signal it',
        );
    }

    public function testTerminateOfANonResourceIsFalse(): void
    {
        $this->assertFalse(Containment::terminate(null));
    }
}

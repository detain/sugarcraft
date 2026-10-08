<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\PosixProcessControl;
use SugarCraft\Top\Overlay\Signals;
use SugarCraft\Top\Source\Fake\FakeProcessControl;

/** The live and fake ProcessControl: only harmless calls (signal 0, same nice). */
final class PosixProcessControlTest extends TestCase
{
    public function testSignalZeroProbesAndReportsErrno(): void
    {
        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('ext-posix');
        }
        $control = PosixProcessControl::new();
        $this->assertSame(0, $control->signal((int) getmypid(), 0), 'signal 0 only checks the pid');
        $this->assertSame(Signals::ESRCH, $control->signal(2_000_000_000, 0));
    }

    public function testReniceToTheCurrentValueSucceeds(): void
    {
        if (!function_exists('pcntl_getpriority')) {
            $this->markTestSkipped('ext-pcntl');
        }
        $pid = (int) getmypid();
        $nice = pcntl_getpriority($pid);
        $this->assertIsInt($nice);
        $this->assertSame(0, PosixProcessControl::new()->renice($pid, $nice));
        $this->assertNotSame(0, PosixProcessControl::new()->renice(2_000_000_000, 0));
    }

    public function testOutOfRangePidsNeverReachASyscall(): void
    {
        $control = PosixProcessControl::new();
        // 0 / negatives address process groups (kill) or candy-top itself (setpriority).
        foreach ([0, -1, -4410, PosixProcessControl::PID_MAX + 1, PHP_INT_MAX] as $pid) {
            $this->assertSame(Signals::ESRCH, $control->signal($pid, 0), (string) $pid);
            $this->assertSame(Signals::ESRCH, $control->renice($pid, 0), (string) $pid);
        }
    }

    public function testFakeNeverTouchesAProcess(): void
    {
        $this->assertSame(0, FakeProcessControl::new()->signal(1, 9));
        $this->assertSame(Signals::EPERM, FakeProcessControl::new(Signals::EPERM)->renice(1, -5));
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Support\HookProcessGroups;

/**
 * #14: quitting reaps the hook processes still running. The registry tracks
 * the live pids; the quit path SIGTERMs them and the child is gone well inside
 * the 2-second grace, without the quit waiting on it.
 */
final class HookProcessGroupsTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (HookProcessGroups::live() as $pid) {
            HookProcessGroups::release($pid);
        }
        parent::tearDown();
    }

    public function testRegisterReleaseAndLiveTrackThePids(): void
    {
        HookProcessGroups::register(4242);
        HookProcessGroups::register(4243);
        HookProcessGroups::register(0);

        self::assertSame([4242, 4243], HookProcessGroups::live(), 'a non-positive pid is never tracked');

        HookProcessGroups::release(4242);
        self::assertSame([4243], HookProcessGroups::live());
    }

    public function testQuitTerminatesALiveHookChild(): void
    {
        if (!\function_exists('posix_kill')) {
            self::markTestSkipped('needs ext-posix to signal a child');
        }

        $process = \proc_open(['sleep', '30'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $pid = (int) (\proc_get_status($process)['pid'] ?? 0);
        HookProcessGroups::register($pid);

        $chat = new Chat(history: [], backend: new EchoBackend());
        self::assertSame(1, $chat->abandonActiveHookGroups());

        // Run the loop past the SIGTERM. A child that exits on TERM is gone long
        // before the 2-second SIGKILL rung, and the bounded stop keeps the test
        // from waiting on a child that never dies.
        $loop = Loop::get();
        $watch = null;
        $bound = null;
        $watch = $loop->addPeriodicTimer(0.05, static function () use ($process, $loop, &$watch, &$bound): void {
            if ((\proc_get_status($process)['running'] ?? false) !== true) {
                $loop->cancelTimer($watch);
                $loop->cancelTimer($bound);
                $loop->stop();
            }
        });
        $bound = $loop->addTimer(3.0, static function () use ($loop, &$watch): void {
            $loop->cancelTimer($watch);
            $loop->stop();
        });
        $loop->run();

        self::assertFalse((\proc_get_status($process)['running'] ?? true), 'the hook child was still running after quit');

        foreach ($pipes as $pipe) {
            \fclose($pipe);
        }
        \proc_close($process);
    }
}

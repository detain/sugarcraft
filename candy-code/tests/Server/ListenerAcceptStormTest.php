<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use SugarCraft\Code\Server\Listener;

/**
 * A failing accept (EMFILE) must pause the master and retry on a timer, so a
 * backlog that cannot be drained produces bounded error lines, not a spin.
 */
final class ListenerAcceptStormTest extends TestCase
{
    public function testFailedAcceptPausesMasterAndResumesViaTimer(): void
    {
        $real = Loop::get();
        $loop = $this->createMock(LoopInterface::class);
        $callback = null;
        $timerCb = null;
        $loop->method('addReadStream')->willReturnCallback(function ($s, $cb) use (&$callback): void {
            $callback = $cb;
        });
        $loop->expects($this->exactly(2))->method('removeReadStream');
        $loop->expects($this->once())->method('addTimer')->willReturnCallback(
            function ($i, $cb) use (&$timerCb, $real): TimerInterface {
                $timerCb = $cb;

                return $real->addTimer(3600, static function (): void {
                });
            },
        );

        $listener = Listener::bind('tcp://127.0.0.1:0', $loop);
        $errors = 0;
        $listener->on('error', static function () use (&$errors): void {
            $errors++;
        });

        // Nothing pending: the non-blocking accept fails, as under EMFILE.
        $master = (new \ReflectionProperty($listener, 'master'))->getValue($listener);
        $this->assertIsCallable($callback);
        $callback($master);
        $this->assertSame(1, $errors);
        $this->assertIsCallable($timerCb);

        // Timer fires: accepting resumes (second addReadStream registers).
        $callback = null;
        $timerCb();
        $this->assertIsCallable($callback);

        $listener->close();
    }
}

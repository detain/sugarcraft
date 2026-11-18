<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Model;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Tests\Support\BigFrameModel;
use SugarCraft\Core\Tests\Support\BumpMsg;
use SugarCraft\Core\Tests\Support\SaturatingFrameModel;
use SugarCraft\Core\Tests\Support\CountingModel;
use SugarCraft\Core\Writer;

/**
 * P1-1 render scheduling and queued output, driven through a real loop: an idle
 * program renders on dirty only (rank 16), and a reader that stalls cannot
 * block the loop.
 */
final class ProgramRenderSchedulingTest extends TestCase
{
    /** @var list<resource> */
    private array $held = [];

    protected function tearDown(): void
    {
        foreach ($this->held as $res) {
            if (is_resource($res)) {
                fclose($res);
            }
        }
        $this->held = [];
        CountingModel::$views = 0;
    }

    /** @return array{0: resource, 1: resource} */
    private function pair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        $this->held[] = $pair[0];
        $this->held[] = $pair[1];
        return $pair;
    }

    private function program(Model $model, $output, $input, StreamSelectLoop $loop, float $framerate = 240.0): Program
    {
        return new Program($model, new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            input: $input,
            output: $output,
            loop: $loop,
            framerate: $framerate,
        ));
    }

    public function testAnIdleProgramDoesNotRenderOnEveryTick(): void
    {
        // 240 fps for half a second would be ~120 polled frames. Rendering on
        // dirty only keeps it to the first paint plus the quit.
        [$input] = $this->pair();
        $output = fopen('php://memory', 'w+');
        $this->held[] = $output;
        $loop = new StreamSelectLoop();
        $program = $this->program(new CountingModel(), $output, $input, $loop);
        $loop->addTimer(0.5, static function () use ($program): void {
            $program->quit();
        });

        $program->run();

        self::assertLessThan(10, CountingModel::$views, 'an idle program paints on dirty, not at the framerate');
    }

    public function testOneMessageRendersOneFrame(): void
    {
        [$input] = $this->pair();
        $output = fopen('php://memory', 'w+');
        $this->held[] = $output;
        $loop = new StreamSelectLoop();
        $program = $this->program(new CountingModel(), $output, $input, $loop);
        $before = 0;
        $after = 0;
        $loop->addTimer(0.1, static function () use ($program, &$before): void {
            $before = CountingModel::$views;
            $program->send(new BumpMsg());
        });
        $loop->addTimer(0.3, static function () use (&$after): void {
            $after = CountingModel::$views;
        });
        $loop->addTimer(0.4, static function () use ($program): void {
            $program->quit();
        });

        $program->run();

        self::assertSame(1, $after - $before, 'one dirtying message is one frame');
    }

    public function testABurstOfMessagesInsideOneFrameIntervalIsOneFrame(): void
    {
        [$input] = $this->pair();
        $output = fopen('php://memory', 'w+');
        $this->held[] = $output;
        $loop = new StreamSelectLoop();
        // 10 fps: the frame interval is 100 ms, far longer than the burst.
        $program = $this->program(new CountingModel(), $output, $input, $loop, 10.0);
        $before = 0;
        $after = 0;
        $loop->addTimer(0.05, static function () use ($program, &$before): void {
            $before = CountingModel::$views;
            for ($i = 0; $i < 5; $i++) {
                $program->send(new BumpMsg());
            }
        });
        $loop->addTimer(0.5, static function () use (&$after): void {
            $after = CountingModel::$views;
        });
        $loop->addTimer(0.6, static function () use ($program): void {
            $program->quit();
        });

        $program->run();

        self::assertSame(1, $after - $before, 'a burst of dirtying messages coalesces into one frame');
    }

    public function testARunThatThrowsLeavesTheOutputBlocking(): void
    {
        // run() attaches the writer inside its try, so a throw anywhere after
        // that point must still detach it: the caller's stream goes back to
        // blocking mode instead of staying non-blocking after the program.
        [$input] = $this->pair();
        [$output] = $this->pair();
        $model = new class () implements Model {
            use \SugarCraft\Core\SubscriptionCapable;

            public function init(): ?\Closure
            {
                return null;
            }

            public function update(\SugarCraft\Core\Msg $msg): array
            {
                return [$this, null];
            }

            public function view(): string
            {
                throw new \RuntimeException('boom');
            }
        };
        $program = $this->program($model, $output, $input, new StreamSelectLoop());

        try {
            $program->run();
            self::fail('the view throw must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertTrue(stream_get_meta_data($output)['blocked'], 'the output is blocking again after the throw');
    }

    public function testReleasingTheTerminalHandsTheOutputBackInBlockingMode(): void
    {
        // An exec child or the shell inherits the output's open file description,
        // so O_NONBLOCK must not survive a release. restoreTerminal() re-enters
        // queued mode.
        [$input] = $this->pair();
        [$output] = $this->pair();
        $program = $this->program(new CountingModel(), $output, $input, new StreamSelectLoop());
        $live = new \ReflectionProperty(Program::class, 'renderLive');
        $attach = new \ReflectionMethod(Program::class, 'attachOutput');
        $detach = new \ReflectionMethod(Program::class, 'detachOutput');
        $live->setValue($program, true);
        $attach->invoke($program);
        self::assertFalse(stream_get_meta_data($output)['blocked'], 'queued mode is non-blocking');

        $program->releaseTerminal();
        self::assertTrue(stream_get_meta_data($output)['blocked'], 'the external program gets a blocking stream');

        $program->restoreTerminal();
        self::assertFalse(stream_get_meta_data($output)['blocked'], 'the restored program queues again');

        $live->setValue($program, false);
        $detach->invoke($program);
    }

    public function testAStalledOutputReaderDoesNotBlockTimersOrRun(): void
    {
        if (!function_exists('socket_import_stream')) {
            self::markTestSkipped('ext-sockets is needed to pin SO_SNDBUF on the output end');
        }
        [$input] = $this->pair();
        [$output, $stalledPeer] = $this->pair();
        $sock = socket_import_stream($output);
        self::assertNotFalse($sock);
        self::assertTrue(socket_set_option($sock, SOL_SOCKET, SO_SNDBUF, 8192));
        $loop = new StreamSelectLoop();
        $program = $this->program(new BigFrameModel(), $output, $input, $loop);
        $fired = false;
        $pending = -1;
        $loop->addTimer(0.3, function () use (&$fired, &$pending, $program, $stalledPeer): void {
            // Timers still fire while a frame sits queued behind a reader that
            // never reads; the queued bytes are what keep the loop responsive.
            $writer = (new \ReflectionProperty(Program::class, 'writer'))->getValue($program);
            self::assertInstanceOf(Writer::class, $writer);
            $pending = $writer->pendingBytes();
            $fired = true;
            fclose($stalledPeer);
        });
        $loop->addTimer(0.6, static function () use ($program): void {
            $program->quit();
        });

        $started = microtime(true);
        $program->run();
        $elapsed = microtime(true) - $started;

        self::assertTrue($fired, 'a timer fired while the output was stalled');
        self::assertGreaterThan(0, $pending, 'the frame was queued, not written into the full pipe');
        self::assertLessThan(5.0, $elapsed, 'teardown is bounded once the reader is gone');
    }

    public function testASaturatedWriterHoldsTheFrameAndPaintsOnceTheBacklogDrains(): void
    {
        if (!function_exists('socket_import_stream')) {
            self::markTestSkipped('ext-sockets is needed to pin SO_SNDBUF on the output end');
        }
        [$input] = $this->pair();
        [$output, $reader] = $this->pair();
        $sock = socket_import_stream($output);
        self::assertNotFalse($sock);
        self::assertTrue(socket_set_option($sock, SOL_SOCKET, SO_SNDBUF, 8192));
        $loop = new StreamSelectLoop();
        SaturatingFrameModel::$views = 0;
        $program = $this->program(new SaturatingFrameModel(), $output, $input, $loop);
        $writer = (new \ReflectionProperty(Program::class, 'writer'))->getValue($program);
        self::assertInstanceOf(Writer::class, $writer);

        // Twelve dirtying messages, each a ~1 MiB frame, while nothing reads.
        for ($i = 0; $i < 12; $i++) {
            $loop->addTimer(0.05 + $i * 0.02, static function () use ($program): void {
                $program->send(new BumpMsg());
            });
        }
        $stalledViews = -1;
        $saturatedWhileStalled = false;
        $heldViews = -1;
        $drained = 0;
        $loop->addTimer(0.6, static function () use (&$stalledViews, &$saturatedWhileStalled, $writer): void {
            $stalledViews = SaturatingFrameModel::$views;
            $saturatedWhileStalled = $writer->isSaturated();
        });
        // Still stalled: no new paint while the writer is saturated.
        $loop->addTimer(0.7, static function () use (&$heldViews, $reader, $loop, &$drained): void {
            $heldViews = SaturatingFrameModel::$views;
            stream_set_blocking($reader, false);
            // Reading frees the pipe; the writer's own write watcher drains the backlog.
            $loop->addPeriodicTimer(0.01, static function () use ($reader, &$drained): void {
                while (($chunk = fread($reader, 1 << 20)) !== false && $chunk !== '') {
                    $drained++;
                }
            });
        });
        // Nothing sends a message after the stall, so a later paint can only come
        // from the held frame being re-armed when the backlog clears.
        $quit = static function () use (&$quit, &$drained, $program, $loop, $writer, $reader): void {
            // Empty the pipe so teardown's last writes cannot block on a full socket.
            while (($chunk = fread($reader, 1 << 20)) !== false && $chunk !== '') {
                $drained++;
            }
            if ($writer->pendingBytes() > 0) {
                $loop->addTimer(0.01, $quit);
                return;
            }
            $program->quit();
        };
        $loop->addTimer(1.0, $quit);

        $program->run();

        self::assertTrue($saturatedWhileStalled, 'the stalled reader saturated the writer');
        self::assertSame($stalledViews, $heldViews, 'a saturated writer holds the frame: no paint while stalled');
        self::assertGreaterThan($heldViews, SaturatingFrameModel::$views, 'the held frame paints once the backlog drains');
        self::assertGreaterThan(0, $drained, 'the reader drained the queued output');
    }
}

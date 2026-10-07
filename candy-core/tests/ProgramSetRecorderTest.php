<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Recorder;
use SugarCraft\Core\Tests\Support\LoggingModel;

/**
 * candy-core audit C4: the recorder is a mutable, identity-wired sink
 * (Program AND Renderer both hold the same object), so the honest shape is
 * setRecorder(): void — not a with*() builder that returns a shared,
 * mutated $this. These pins keep the setter real and the deprecated
 * withRecorder() shim honest (same instance in, same wiring out).
 */
final class ProgramSetRecorderTest extends TestCase
{
    /**
     * Minimal counting Recorder double — records the lifecycle events the
     * loop emits during a short run.
     */
    private function fakeRecorder(array &$events): Recorder
    {
        return new class ($events) implements Recorder {
            /** @param list<string> $events shared by reference with the test */
            public function __construct(private array &$events) {}

            public function recordResize(int $cols, int $rows): void
            {
                $this->events[] = 'resize';
            }

            public function recordInputBytes(string $bytes): void
            {
                $this->events[] = 'input';
            }

            public function recordOutput(string $bytes): void
            {
                $this->events[] = 'output';
            }

            public function recordQuit(): void
            {
                $this->events[] = 'quit';
            }

            public function close(): void
            {
                $this->events[] = 'close';
            }
        };
    }

    /** @return array{0: resource, 1: resource, 2: resource} */
    private function pipes(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        [$in, $writer] = $pair;
        $out = fopen('php://memory', 'w+b');
        $this->assertNotFalse($out);
        stream_set_blocking($in, false);

        return [$in, $out, $writer];
    }

    private function options($in, $out, StreamSelectLoop $loop): ProgramOptions
    {
        return new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            input: $in,
            output: $out,
            loop: $loop,
        );
    }

    public function testSetRecorderWiresTheSinkIntoTheRunningLoop(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $events = [];

        $model = new LoggingModel(initCmd: Cmd::tick(0.01, static fn (): Msg => new QuitMsg()));
        $program = new Program($model, $this->options($in, $out, $loop));
        // setRecorder is a statement, not a chain link: assert the return
        // is void by calling it for effect only.
        $program->setRecorder($this->fakeRecorder($events));

        // Safety net so a broken quit path fails fast instead of hanging.
        $loop->addTimer(2.0, static fn () => $loop->stop());
        $program->run();

        fclose($writer);
        fclose($in);
        $bytes = stream_get_contents($out, offset: 0);
        fclose($out);

        // The identity-wired sink saw the loop's lifecycle: at least one
        // frame was published through the renderer's recorder hook, the
        // quit marker fired, and close ran exactly once after the loop.
        $this->assertContains('output', $events, 'renderer never teed output bytes to the recorder');
        $this->assertContains('quit', $events);
        $this->assertSame('close', $events[\count($events) - 1], 'close must be the final event');
        $this->assertSame(1, \count(array_keys($events, 'close', true)), 'close is idempotent-once');
        $this->assertNotSame('', $bytes, 'the frame must still reach the tty');
    }

    public function testDeprecatedWithRecorderStillChainsSameInstanceAndWires(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $events = [];

        $model = new LoggingModel(initCmd: Cmd::tick(0.01, static fn (): Msg => new QuitMsg()));
        $program = new Program($model, $this->options($in, $out, $loop));
        // BC shape: returns THE SAME program (not a clone) and forwards.
        $this->assertSame($program, $program->withRecorder($this->fakeRecorder($events)));

        $loop->addTimer(2.0, static fn () => $loop->stop());
        $program->run();

        fclose($writer);
        fclose($in);
        fclose($out);

        $this->assertContains('quit', $events, 'the deprecated shim must forward to setRecorder');
        $this->assertSame('close', $events[\count($events) - 1]);
    }
}

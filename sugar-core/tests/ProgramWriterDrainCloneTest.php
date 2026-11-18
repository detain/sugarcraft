<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\Writer;

/** Inert model: the test only needs a Program that starts and quits. */
final class WriterDrainCloneProbeModel implements Model
{
    use SubscriptionCapable;

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        return [$this, null];
    }

    public function view(): string
    {
        return 'probe';
    }
}

/**
 * withExceptionHandler()/withLogger() clone the Program but share its Writer.
 * The writer's drain hook must follow the instance that runs, or a backlog
 * drain services the original and leaves the clone's overflow state stuck.
 */
final class ProgramWriterDrainCloneTest extends TestCase
{
    public function testTheRunningCloneOwnsTheWriterDrainHook(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        [$reader, $writerEnd] = $sockets;
        $output = fopen('php://memory', 'w+');
        $this->assertNotFalse($output);
        $loop = new StreamSelectLoop();

        $original = new Program(new WriterDrainCloneProbeModel(), new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            input: $reader,
            output: $output,
            loop: $loop,
        ));
        $clone = $original->withExceptionHandler(static fn (\Throwable $t): null => null);

        $loop->addTimer(0.05, static fn () => $clone->quit());
        $loop->addTimer(2.0, static fn () => $loop->stop());
        $clone->run();

        $writerProp = new \ReflectionProperty(Program::class, 'writer');
        $writer = $writerProp->getValue($clone);
        $this->assertInstanceOf(Writer::class, $writer);
        $drain = (new \ReflectionProperty(Writer::class, 'onDrain'))->getValue($writer);
        $this->assertInstanceOf(\Closure::class, $drain);
        $this->assertSame($clone, (new \ReflectionFunction($drain))->getClosureThis(),
            'the drain hook must be bound to the instance that ran, not the constructing original');

        $overflow = new \ReflectionProperty(Program::class, 'outputOverflowReported');
        $overflow->setValue($clone, true);
        $drain();
        $this->assertFalse($overflow->getValue($clone),
            'a drained backlog must clear the running instance\'s overflow report');

        fclose($writerEnd);
        fclose($reader);
        fclose($output);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\TickHandle;
use SugarCraft\Core\TickRequest;

/** Shared state the probe model and the test body both see. */
final class TickProbeState
{
    public int $fired = 0;
    public ?TickHandle $handle = null;
}

/**
 * Arms a cancellable tick on 'a' and cancels it on 'c'. The produce closure
 * counts its runs, so a test can tell whether the tick ever fired.
 */
final class CancellableTickModel implements Model
{
    use SubscriptionCapable;

    public function __construct(
        public readonly TickProbeState $state,
        public readonly float $seconds,
    ) {
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }
        if ($msg->rune === 'a') {
            $state = $this->state;
            $handle = Cmd::cancellableTick($this->seconds, static function () use ($state): ?Msg {
                $state->fired++;
                return null;
            });
            $this->state->handle = $handle;
            return [$this, $handle->cmd()];
        }
        if ($msg->rune === 'b') {
            // Cancel in the same update that issues the tick: the Cmd is still
            // queued, so the TickRequest reaches the Program already cancelled.
            $state = $this->state;
            $handle = Cmd::cancellableTick($this->seconds, static function () use ($state): ?Msg {
                $state->fired++;
                return null;
            });
            $handle->cancel();
            return [$this, $handle->cmd()];
        }
        if ($msg->rune === 'c' && $this->state->handle !== null) {
            $this->state->handle->cancel();
        }
        return [$this, null];
    }

    public function view(): string
    {
        return '';
    }
}

final class CmdCancellableTickTest extends TestCase
{
    /** @return array{0:resource, 1:resource, 2:resource} */
    private function pipes(): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        [$reader, $writer] = $sockets;
        $output = fopen('php://memory', 'w+');
        $this->assertNotFalse($output);
        return [$reader, $output, $writer];
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

    public function testCancelBeforeTheTickIsArmedNeverFires(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $state = new TickProbeState();
        $program = new Program(new CancellableTickModel($state, 0.02), $this->options($in, $out, $loop));

        $program->send(new KeyMsg(KeyType::Char, 'b'));
        // Past the 0.02 s deadline, so an armed timer would have run by now.
        $loop->addTimer(0.2, static fn () => $program->quit());
        $loop->addTimer(1.0, static fn () => $loop->stop());
        $program->run();

        $this->assertSame(0, $state->fired, 'a tick cancelled before arming must not run its produce closure');
        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testCancelWhilePendingDisarmsTheLoopTimer(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $state = new TickProbeState();
        $program = new Program(new CancellableTickModel($state, 0.3), $this->options($in, $out, $loop));

        $program->send(new KeyMsg(KeyType::Char, 'a'));
        // The tick is armed for 0.3 s; cancel it at 0.05 s.
        $loop->addTimer(0.05, static fn () => $program->send(new KeyMsg(KeyType::Char, 'c')));
        // Quit well after the original deadline so a live timer would have fired.
        $loop->addTimer(0.6, static fn () => $program->quit());
        $loop->addTimer(2.0, static fn () => $loop->stop());
        $program->run();

        $this->assertSame(0, $state->fired, 'cancel() while pending must cancel the loop timer');
        $this->assertTrue($state->handle?->isCancelled());
        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testATickThatFiresIsUnaffectedByALaterCancel(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $state = new TickProbeState();
        $program = new Program(new CancellableTickModel($state, 0.05), $this->options($in, $out, $loop));

        $program->send(new KeyMsg(KeyType::Char, 'a'));
        $loop->addTimer(0.3, static fn () => $program->send(new KeyMsg(KeyType::Char, 'c')));
        $loop->addTimer(0.6, static fn () => $program->quit());
        $loop->addTimer(2.0, static fn () => $loop->stop());
        $program->run();

        $this->assertSame(1, $state->fired, 'a tick that already fired runs exactly once');
        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testCancellableTickReturnsAHandleWhoseCmdCarriesIt(): void
    {
        $handle = Cmd::cancellableTick(1.5, static fn (): ?Msg => null);
        $this->assertInstanceOf(TickHandle::class, $handle);
        $this->assertFalse($handle->isCancelled());
        $request = ($handle->cmd())();
        $this->assertInstanceOf(TickRequest::class, $request);
        $this->assertSame(1.5, $request->seconds);
        $this->assertSame($handle, $request->handle);
    }

    public function testCancelIsIdempotentAndSafeWithoutAnArmedTimer(): void
    {
        $handle = Cmd::cancellableTick(1.0, static fn (): ?Msg => null);
        $handle->cancel();
        $handle->cancel();
        $this->assertTrue($handle->isCancelled());
        // attach() after cancel() disarms at once instead of holding a live timer.
        $disarmed = 0;
        $handle->attach(static function () use (&$disarmed): void {
            $disarmed++;
        });
        $this->assertSame(1, $disarmed);
    }
}

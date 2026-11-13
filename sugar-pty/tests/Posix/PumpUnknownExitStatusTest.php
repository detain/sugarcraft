<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Posix\PosixPump;
use SugarCraft\Pty\Posix\ReactPump;
use SugarCraft\Pty\PumpOptions;

/**
 * Unknown child state resolves to -1, never a fabricated 0.
 *
 * Contract\Child::exitCode() documents its null leg: the status died in
 * someone else's waitpid (the trait's own sharp edge, and the reason
 * wait() exists). When a pump sees exited() + a null code, the only
 * truthful answer is the contract's "status not observed" value — -1.
 * A 0 there would report a clean exit the pump never saw, and the
 * Windows twin already refuses it (ConPtyChild: "never a fabricated 0").
 *
 * Signal death is NOT this path: it maps to 128+signo inside the child
 * itself (ChildPollTrait), so a signal-killed child arrives here with a
 * concrete int and keeps its code.
 *
 * The fake below models the post-external-reap state directly —
 * exited() true with the code held by the reaper — which is what the
 * real-world race leaves the child object looking like.
 */
final class PumpUnknownExitStatusTest extends TestCase
{
    use \SugarCraft\Pty\Tests\Support\RequiresPtyCapability;

    public function testPosixPumpReturnsMinusOneWhenExitStatusIsUnknown(): void
    {
        $this->requireBasePtyCapability();
        $master = new UnknownExitMasterPty();
        $stdin = \fopen('php://temp', 'r+');
        $this->assertNotFalse($stdin);

        $status = (new PosixPump())->run($master, $stdin, null, new UnknownExitChild());

        $this->assertSame(
            -1,
            $status,
            'exited-but-unreadable status must ride the -1 unknown leg, not 0',
        );
    }

    public function testReactPumpOnChildExitAndResolutionBothCarryMinusOne(): void
    {
        $this->requireBasePtyCapability();
        $loop = new StreamSelectLoop();
        $seen = null;
        $opts = (new PumpOptions())->withOnChildExit(static function (int $code) use (&$seen): void {
            $seen = $code;
        });

        $settled = null;
        $promise = (new ReactPump($loop))->start(
            new UnknownExitMasterPty(),
            null,
            null,
            new UnknownExitChild(),
            $opts,
        );
        $promise->then(static function (int $result) use (&$settled): void {
            $settled = $result;
        });
        $loop->addTimer(2.0, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();

        $this->assertSame(-1, $seen, 'onChildExit must not be handed a fabricated clean exit');
        $this->assertSame(-1, $settled, 'the promise must settle -1 when the status was never observed');
    }
}

/**
 * exited() true, exitCode() null: exactly the shape a child left behind
 * by an external reap (ChildPollTrait::exited()'s non-resource /
 * proc_get_status legs), with no waiting or killing to disturb it.
 */
final class UnknownExitChild implements Child
{
    public function pid(): int
    {
        return 424242;
    }

    public function exited(): bool
    {
        return true;
    }

    public function exitCode(): ?int
    {
        return null;
    }

    public function wait(): int
    {
        return 0;
    }

    public function kill(int $signal): void
    {
    }
}

/** Quiet master for the unknown-exit legs (socketpair-backed stream). */
final class UnknownExitMasterPty implements MasterPty
{
    /** @var resource */
    private $stream;

    public function __construct()
    {
        $pair = @\stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new \RuntimeException('stream_socket_pair unavailable on this host.');
        }
        [$this->stream, $peer] = $pair;
        \stream_set_blocking($this->stream, false);
        \fclose($peer);
    }

    public function read(int $len = 8192, ?float $timeout = null): ?string
    {
        return null;
    }

    public function write(string $bytes): int
    {
        return \strlen($bytes);
    }

    public function resize(int $cols, int $rows): void
    {
    }

    public function size(): array
    {
        return ['cols' => 80, 'rows' => 24, 'xpix' => 0, 'ypix' => 0];
    }

    public function stream(): mixed
    {
        return $this->stream;
    }
    /**
     * MasterPty contract member (PTY-13). Scripted fakes hold no real
     * blocking mode — nothing they return depends on it.
     */
    public function setBlocking(bool $blocking): void
    {
    }


    public function fd(): int
    {
        return -1;
    }

    public function close(): void
    {
    }

    public function isClosed(): bool
    {
        return false;
    }
}

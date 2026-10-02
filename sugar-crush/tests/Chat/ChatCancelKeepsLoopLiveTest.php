<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Support\ProcessContainment;
use SugarCraft\Crush\Support\ProcessTree;
use SugarCraft\Crush\Tests\Support\ProcessTreeKillTest;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResultsMsg;
use SugarCraft\Crush\Tools\BuiltIn\Bash;

/**
 * Audit R3, the Chat half: both of Chat's cancel kill sites — the tool fan-out
 * ({@see Chat::waitForToolChildrenAsync()}) and the forked hook / `!` command
 * child ({@see Chat::forkedPayloadCmd()}) — ran killTree() and the bounded reap
 * inside the loop timer that noticed the cancel, ~110 ms of frozen frame per
 * Escape (MEASURED on the engine path, the same walk here).
 *
 * The discriminating observation is the one
 * {@see \SugarCraft\Crush\Tests\Backend\EngineBackendCancelKeepsLoopLiveTest}
 * makes: a heartbeat must catch the killed fork STOPPED while the command is
 * still unsettled. Synchronously, stop-kill-reap-settle all happened in one
 * callback and no other callback could ever see the stop.
 */
final class ChatCancelKeepsLoopLiveTest extends TestCase
{
    /** @var list<int> */
    private array $strays = [];

    /** Our own fork, signalled in tearDown() only while it is still OUR unreaped child. */
    private ?int $fork = null;

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('Chat forks its tool and hook children only with ext-pcntl and ext-posix.');
        }
        if (!ProcessTree::available() || ProcessContainment::detachedSpawnBinary() === '') {
            self::markTestSkipped('the tree walk needs /proc and the command needs a setsid-detached spawn.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->strays as $pid) {
            @\posix_kill($pid, 9);
        }
        if ($this->fork !== null && \pcntl_waitpid($this->fork, $status, \WNOHANG) === 0) {
            @\posix_kill($this->fork, 9);
            \pcntl_waitpid($this->fork, $status);
        }

        parent::tearDown();
    }

    public function testCancellingAToolFanOutKeepsTheLoopTurningWhileTheTreeDies(): void
    {
        $marker = 'sleep 30.' . \random_int(100000, 999999);
        $chat = (new Chat())->registerTool(
            'slow',
            static fn(array $args): string => (new Bash())->execute(['command' => $marker])->content(),
        );

        [$running, $cmd] = $chat->update(new AssistantMsg(
            Message::assistant('running')->withToolCalls([new ToolCall('slow', [], 'call_1')]),
        ));
        self::assertInstanceOf(\Closure::class, $cmd);
        $asyncCmd = $cmd();
        self::assertInstanceOf(AsyncCmd::class, $asyncCmd);

        $fork = $this->forkRunning($marker);

        [$once] = $running->update(new KeyMsg(KeyType::Escape));
        [$cancelled] = $once->update(new KeyMsg(KeyType::Escape));
        self::assertFalse($cancelled->inFlight, 'fixture: the double Escape cancelled the turn');

        [$resolved, $held] = $this->settleWatching($asyncCmd->promise, $fork);

        self::assertInstanceOf(ToolResultsMsg::class, $resolved, 'the cancelled batch never settled');
        self::assertGreaterThan(0, $held, 'no loop tick ran between the tool child being stopped and the batch settling');
        self::assertSame([], $this->survivors($marker), 'a cancelled Chat tool call\'s command outlived it');
        self::assertSame(-1, \pcntl_waitpid($fork, $status, \WNOHANG), 'the killed tool child was never reaped');
    }

    public function testCancellingAForkedPayloadCommandKeepsTheLoopTurningWhileTheTreeDies(): void
    {
        $marker = 'sleep 30.' . \random_int(100000, 999999);
        $cancellation = new CancellationToken();
        $method = new \ReflectionMethod(Chat::class, 'forkedPayloadCmd');
        $cmd = $method->invoke(
            null,
            static fn(): string => (new Bash())->execute(['command' => $marker])->content(),
            static fn(string $file): never => throw new \LogicException('a cancelled child\'s payload must not be collected'),
            static fn(): never => throw new \LogicException('fixture: pcntl_fork() failed'),
            $cancellation,
        );
        $asyncCmd = $cmd();
        self::assertInstanceOf(AsyncCmd::class, $asyncCmd);

        $fork = $this->forkRunning($marker);
        $cancellation->cancel();

        [$resolved, $held] = $this->settleWatching($asyncCmd->promise, $fork);

        self::assertNull($resolved, 'a cancelled forked command resolves null so nothing is dispatched');
        self::assertGreaterThan(0, $held, 'no loop tick ran between the forked child being stopped and the command settling');
        self::assertSame([], $this->survivors($marker), 'the cancelled command\'s shell outlived it');
        self::assertSame(-1, \pcntl_waitpid($fork, $status, \WNOHANG), 'the killed child was never reaped');
    }

    /**
     * The fork of THIS process whose tree runs $marker, found by walking up
     * from the command once it is visible.
     */
    private function forkRunning(string $marker): int
    {
        $deadline = \microtime(true) + 5.0;
        while (($pids = ProcessTreeKillTest::pidsRunning($marker)) === [] && \microtime(true) < $deadline) {
            \usleep(20_000);
        }
        self::assertNotSame([], $pids, 'fixture: the command never started');
        \array_push($this->strays, ...$pids);

        $me = \getmypid();
        $pid = $pids[0];
        for ($hops = 0; $hops < 16; $hops++) {
            $parent = ProcessTree::stat($pid)['ppid'] ?? 0;
            if ($parent === $me) {
                $this->fork = $pid;

                return $pid;
            }
            if ($parent <= 1) {
                break;
            }
            $pid = $parent;
        }
        self::fail('fixture: the command is not under a fork of this process');
    }

    /**
     * Run the loop until $promise settles, counting the ticks on which $fork
     * was seen stopped while it was still pending.
     *
     * @return array{0: mixed, 1: int}
     */
    private function settleWatching(PromiseInterface $promise, int $fork): array
    {
        $loop = Loop::get();
        $settled = false;
        $resolved = null;
        $promise->then(static function ($value) use (&$settled, &$resolved, $loop): void {
            $settled = true;
            $resolved = $value;
            $loop->stop();
        });

        $held = 0;
        $heartbeat = $loop->addPeriodicTimer(0.002, static function () use (&$settled, &$held, $fork): void {
            if (!$settled && (ProcessTree::stat($fork)['state'] ?? null) === 'T') {
                $held++;
            }
        });
        $guard = $loop->addTimer(10.0, static fn() => $loop->stop());
        if (!$settled) {
            $loop->run();
        }
        $loop->cancelTimer($guard);
        $loop->cancelTimer($heartbeat);
        self::assertTrue($settled, 'the cancelled command never settled');

        return [$resolved, $held];
    }

    /**
     * @return list<int>
     */
    private function survivors(string $marker): array
    {
        $pids = ProcessTreeKillTest::survivingAfter($marker, 2.0);
        \array_push($this->strays, ...$pids);

        return $pids;
    }
}

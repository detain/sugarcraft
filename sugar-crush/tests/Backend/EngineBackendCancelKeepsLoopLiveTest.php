<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Support\ProcessContainment;
use SugarCraft\Crush\Support\ProcessTree;
use SugarCraft\Crush\Tests\Support\ProcessTreeKillTest;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\Bash;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Audit R3: the cancel teardown of {@see EngineBackend::completeAsync()} ran
 * killTree() and the bounded reap synchronously inside the loop callback that
 * noticed the cancel — MEASURED ~110 ms with the event loop frozen, on the
 * Escape whose whole job is getting the user out of a stuck turn.
 *
 * WHAT IS OBSERVED, AND WHY IT DISCRIMINATES. A heartbeat timer looks at the
 * turn child on every tick. Before the fix the child went from running to
 * SIGSTOPped to SIGKILLed to reaped within ONE callback, so no other callback
 * could ever see it stopped while the turn was still unsettled. After it, the
 * root is stopped at once and the walk, kill and reap each take loop ticks —
 * so the heartbeat must catch the child held ('T') with the turn pending. The
 * end state (turn rejected as cancelled, command dead) is asserted too, so the
 * loop staying live never costs the kill.
 */
final class EngineBackendCancelKeepsLoopLiveTest extends TestCase
{
    /** @var list<int> */
    private array $strays = [];

    private string $dir = '';

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('completeAsync() only forks (and only tears a tree down) with ext-pcntl + ext-posix.');
        }
        if (!ProcessTree::available() || ProcessContainment::detachedSpawnBinary() === '') {
            self::markTestSkipped('the tree walk needs /proc and the command needs a setsid-detached spawn.');
        }

        $this->dir = \sys_get_temp_dir() . '/cancel_live_' . \getmypid() . '_' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->strays as $pid) {
            @\posix_kill($pid, 9);
        }
        foreach (\glob($this->dir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->dir);

        parent::tearDown();
    }

    public function testTheLoopKeepsTurningWhileACancelledTurnsTreeIsKilled(): void
    {
        $marker = 'sleep 30.' . \random_int(100000, 999999);
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'Bash', ['command' => $marker])]),
            new CompleteResponse(content: 'done'),
        ]);
        $backend = EngineBackend::new($provider, 'm')->withTools([new Bash($this->dir)]);

        $tracked = new \ReflectionProperty(EngineBackend::class, 'unreapedChildren');
        $before = \array_keys($tracked->getValue());

        $cancellation = new CancellationToken();
        $promise = $backend->completeAsync([Message::user('go')], null, $cancellation);
        $turnChild = \array_values(\array_diff(\array_keys($tracked->getValue()), $before))[0] ?? null;
        self::assertIsInt($turnChild, 'fixture: completeAsync() did not fork a turn child');
        $this->strays[] = $turnChild;

        $settled = false;
        $error = null;
        $loop = Loop::get();
        $promise->then(
            static function () use (&$settled, $loop): void {
                $settled = true;
                $loop->stop();
            },
            static function (\Throwable $e) use (&$settled, &$error, $loop): void {
                $settled = true;
                $error = $e;
                $loop->stop();
            },
        );

        $seen = false;
        $heldWhilePending = 0;
        $heartbeat = $loop->addPeriodicTimer(0.002, function () use ($marker, $cancellation, $turnChild, &$seen, &$settled, &$heldWhilePending): void {
            if (!$seen) {
                $running = ProcessTreeKillTest::pidsRunning($marker);
                if ($running !== []) {
                    $seen = true;
                    \array_push($this->strays, ...$running);
                    $cancellation->cancel();
                }

                return;
            }
            if (!$settled && (ProcessTree::stat($turnChild)['state'] ?? null) === 'T') {
                $heldWhilePending++;
            }
        });
        $guard = $loop->addTimer(15.0, static fn() => $loop->stop());
        if (!$settled) {
            $loop->run();
        }
        $loop->cancelTimer($guard);
        $loop->cancelTimer($heartbeat);

        self::assertTrue($seen, 'fixture: the command never started, so the cancel proves nothing');
        self::assertTrue($settled, 'the cancelled turn never settled');
        self::assertInstanceOf(\RuntimeException::class, $error);
        self::assertStringContainsString('cancelled', $error->getMessage());
        self::assertGreaterThan(
            0,
            $heldWhilePending,
            'no loop tick ran between the turn child being stopped and the turn settling: the teardown froze the loop',
        );
        self::assertSame([], ProcessTreeKillTest::survivingAfter($marker, 2.0), 'the cancelled turn\'s command kept running');
        self::assertArrayNotHasKey($turnChild, $tracked->getValue(), 'the killed turn child was never reaped');
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Support\ProcessContainment;
use SugarCraft\Crush\Support\ProcessTree;
use SugarCraft\Crush\Tests\Support\ProcessTreeKillTest;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\Bash;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Audit B2/F-E2 through the REAL Escape-Escape path: {@see
 * EngineBackend::completeAsync()}'s cancel teardown must take the turn's
 * running command — and a parallel Task sub-agent's command — down with the
 * turn child. Before the fix the teardown SIGKILLed the turn child alone and
 * the setsid'd `bash -c …` it was waiting on ran to completion as an orphan.
 */
final class EngineBackendTeardownKillsToolTreeTest extends TestCase
{
    private string $dir = '';

    /** @var list<int> */
    private array $strays = [];

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('completeAsync() only forks (and only tears a tree down) with ext-pcntl + ext-posix.');
        }
        if (!ProcessTree::available() || ProcessContainment::detachedSpawnBinary() === '') {
            self::markTestSkipped('the tree walk needs /proc and the orphan needs a setsid-detached command.');
        }

        $this->dir = \sys_get_temp_dir() . '/teardown_tree_' . \getmypid() . '_' . \bin2hex(\random_bytes(4));
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

    public function testCancellingATurnKillsTheCommandItIsRunning(): void
    {
        $marker = 'sleep 30.' . \random_int(100000, 999999);
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'Bash', ['command' => $marker])]),
            new CompleteResponse(content: 'done'),
        ]);
        $backend = EngineBackend::new($provider, 'm')->withTools([new Bash($this->dir)]);

        $cancellation = new CancellationToken();
        $seen = [];
        $loop = Loop::get();
        $probe = $loop->addPeriodicTimer(0.05, function () use ($marker, $cancellation, &$seen): void {
            if ($seen === []) {
                $seen = ProcessTreeKillTest::pidsRunning($marker);
                if ($seen !== []) {
                    \array_push($this->strays, ...$seen);
                    $cancellation->cancel();
                }
            }
        });

        $error = $this->settle($backend->completeAsync([Message::user('go')], null, $cancellation));
        $loop->cancelTimer($probe);

        self::assertNotSame([], $seen, 'the command never started, so the cancel proves nothing');
        self::assertInstanceOf(\RuntimeException::class, $error);
        self::assertStringContainsString('cancelled', $error->getMessage());
        self::assertSame([], $this->survivors($marker, 2.0), 'the cancelled turn\'s command kept running');
    }

    public function testCancellingATurnStopsAParallelTaskSubAgentsCommand(): void
    {
        $file = $this->dir . '/survived';
        $provider = new ScriptedProvider([
            // The turn: two Tasks, so they fan out as forks BELOW the turn
            // child (and are exempt from the parallel deadline).
            new CompleteResponse(content: '', toolCalls: [
                new ToolCall('call_a', 'Task', self::task()),
                new ToolCall('call_b', 'Task', self::task()),
            ]),
            // Each sub-agent (its own fork, its own copy of this script).
            new CompleteResponse(content: '', toolCalls: [
                new ToolCall('call_s', 'Bash', ['command' => 'sleep 1.5; echo survived > ' . \escapeshellarg($file)]),
            ]),
            new CompleteResponse(content: 'report'),
        ]);
        $bash = new Bash($this->dir);
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry(), toolRegistry: [$bash], toolUniverse: [$bash]);
        $manager->register(RosterAgent::named('coder', ['Bash'], maxTurns: 5));
        $backend = EngineBackend::new($provider, 'm')->withTools([$bash, new TaskTool($manager)]);

        $cancellation = new CancellationToken();
        $loop = Loop::get();
        $loop->addTimer(0.8, static function () use ($cancellation): void {
            $cancellation->cancel();
        });

        $error = $this->settle($backend->completeAsync([Message::user('go')], null, $cancellation));

        self::assertInstanceOf(\RuntimeException::class, $error);
        \usleep(2_500_000);
        self::assertFileDoesNotExist($file, 'a parallel Task sub-agent\'s command finished after the turn was cancelled');
    }

    /**
     * @return array<string, string>
     */
    private static function task(): array
    {
        return ['description' => 'Run the slow command', 'prompt' => 'Run it', 'agent' => 'coder'];
    }

    /**
     * Run the loop until $promise settles (bounded), returning its rejection.
     */
    private function settle(\React\Promise\PromiseInterface $promise): ?\Throwable
    {
        $loop = Loop::get();
        $done = false;
        $error = null;
        $promise->then(
            static function () use (&$done, $loop): void {
                $done = true;
                $loop->stop();
            },
            static function (\Throwable $e) use (&$done, &$error, $loop): void {
                $done = true;
                $error = $e;
                $loop->stop();
            },
        );
        if (!$done) {
            $guard = $loop->addTimer(15.0, static fn() => $loop->stop());
            $loop->run();
            $loop->cancelTimer($guard);
        }
        self::assertTrue($done, 'the turn never settled');

        return $error;
    }

    /**
     * @return list<int>
     */
    private function survivors(string $marker, float $budget): array
    {
        $deadline = \microtime(true) + $budget;
        do {
            $pids = ProcessTreeKillTest::pidsRunning($marker);
            if ($pids === []) {
                return [];
            }
            \usleep(20_000);
        } while (\microtime(true) < $deadline);

        return $pids;
    }
}

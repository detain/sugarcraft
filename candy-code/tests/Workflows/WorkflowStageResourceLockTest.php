<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentResult;
use SugarCraft\Code\Agents\AgentStatus;
use SugarCraft\Code\Agents\AgentWorkerPool;
use SugarCraft\Code\Agents\ExecutorInterface;
use SugarCraft\Code\Agents\SubAgent;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Workflows\StageResourceLocks;
use SugarCraft\Code\Workflows\Tasks;
use SugarCraft\Code\Workflows\WorkflowBuilder;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowLoadException;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowStatus;
use SugarCraft\Code\Workflows\WorkflowTask;

/**
 * O3: a stage may declare `resource: <name>` and the engine takes an
 * exclusive host lock on it before dispatch — bounded wait, loud named
 * failure on contention, release on every exit path, kernel release on
 * death. Workflows that declare nothing touch no lock at all.
 */
final class WorkflowStageResourceLockTest extends TestCase
{
    private string $tempDir;

    /** @var list<string> every prompt the fake executor was handed */
    private array $prompts = [];

    /** @var array<string, \Closure(SubAgent): ?AgentResult> */
    private array $script = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/candy_code_wf_locks_' . uniqid('', true);
        mkdir($this->tempDir . '/workflows', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    // ------------------------------------------------------------------ builder

    public function testTheBuilderCarriesTheResourcePairAndRefusesBadBounds(): void
    {
        $task = Tasks::agent('coder')->prompt('p')->resource('  gpu  ')->lockWaitSeconds(0)->build();
        self::assertSame('gpu', $task->resource, 'the name is trimmed at the builder, trusted inside');
        self::assertSame(0, $task->lockWaitSeconds, '0 is the legitimate ask-once bound');

        $quiet = Tasks::agent('coder')->prompt('p')->build();
        self::assertNull($quiet->resource, 'absent means no lock, byte-identical to pre-O3');
        self::assertNull($quiet->lockWaitSeconds);

        foreach (['', '   '] as $blank) {
            $failed = false;
            try {
                Tasks::agent('coder')->prompt('p')->resource($blank);
            } catch (\InvalidArgumentException $refusal) {
                $failed = true;
                self::assertStringContainsString('names no resource', $refusal->getMessage());
            }
            self::assertTrue($failed, 'a blank resource name must be refused, not stored');
        }
    }

    public function testTheBuilderRefusesANegativeWait(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/lockWaitSeconds cannot be negative, got -5/');

        Tasks::agent('coder')->prompt('p')->lockWaitSeconds(-5);
    }

    // ------------------------------------------------------------------ YAML

    public function testTheYamlSpellingsReachTheTaskOnEveryArm(): void
    {
        $registry = new WorkflowRegistry($this->tempDir . '/workflows');

        $oneTask = $registry->fromYamlString(
            "name: yaml-one\n"
            . "description: one\n"
            . "stages:\n"
            . "  - name: work\n"
            . "    agent: coder\n"
            . "    prompt: check\n"
            . "    resource: gpu\n"
            . "    lockWaitSeconds: '12'\n",
            'yaml-one',
        );
        $task = $oneTask->stages[0]['tasks'][0];
        self::assertInstanceOf(WorkflowTask::class, $task);
        self::assertSame('gpu', $task->resource);
        self::assertSame(12, $task->lockWaitSeconds, 'a quoted integer is accepted exactly like retries');

        $listed = $registry->fromYamlString(
            "name: yaml-list\n"
            . "description: two\n"
            . "stages:\n"
            . "  - name: work\n"
            . "    tasks:\n"
            . "      - agent: coder\n"
            . "        prompt: one\n"
            . "        resource: display\n"
            . "      - agent: coder\n"
            . "        prompt: two\n",
            'yaml-list',
        );
        [$first, $second] = $listed->stages[0]['tasks'];
        self::assertSame('display', $first->resource);
        self::assertNull($second->resource, 'a task that declares nothing rides the shared stage lock, not its own');

        $parallel = $registry->fromYamlString(
            "name: yaml-par\n"
            . "description: three\n"
            . "stages:\n"
            . "  - name: fan\n"
            . "    parallel: true\n"
            . "    agents:\n"
            . "      - type: coder\n"
            . "        prompt: one\n"
            . "        resource: gpu\n"
            . "        lockWaitSeconds: 3\n",
            'yaml-par',
        );
        $agent = $parallel->stages[0]['tasks'][0];
        self::assertInstanceOf(WorkflowTask::class, $agent);
        self::assertSame('gpu', $agent->resource);
        self::assertSame(3, $agent->lockWaitSeconds);
    }

    public function testTheYamlRefusesBadResourceSpellings(): void
    {
        $cases = [
            'blank name' => ["    resource: '  '\n", '/names no resource/'],
            'negative wait' => ["    resource: gpu\n    lockWaitSeconds: -1\n", '/lockWaitSeconds\" must be at least 0, got -1|must be at least 0, got -1/'],
            'float wait' => ["    resource: gpu\n    lockWaitSeconds: 1.5\n", '/must be a whole number/'],
            'stage key beside tasks' => ["    resource: gpu\n", '/"resource" belongs on each task/'],
        ];

        foreach ($cases as $why => [$extra, $shape]) {
            $yaml = "name: closed\n"
                . "description: {$why}\n"
                . "stages:\n"
                . "  - name: work\n"
                . "    agent: coder\n"
                . "    prompt: check\n"
                . $extra;
            if ($why === 'stage key beside tasks') {
                $yaml = "name: closed\n"
                    . "description: {$why}\n"
                    . "stages:\n"
                    . "  - name: work\n"
                    . $extra
                    . "    tasks:\n"
                    . "      - agent: coder\n"
                    . "        prompt: check\n";
            }

            $failed = false;
            try {
                (new WorkflowRegistry($this->tempDir . '/workflows'))->fromYamlString($yaml, 'closed');
            } catch (WorkflowLoadException $refusal) {
                $failed = true;
                self::assertMatchesRegularExpression($shape, $refusal->getMessage(), $why);
            }
            self::assertTrue($failed, $why . ' — a bad lock ask must be refused, not ignored');
        }
    }

    // ------------------------------------------------------------------ engine

    public function testAContendedResourceFailsTheStageLoudlyAndWithdrawsTheRecord(): void
    {
        $registry = new WorkflowRegistry($this->tempDir . '/workflows');
        $registry->register(
            (new WorkflowBuilder())
                ->name('locked')
                ->description('one stage wanting gpu')
                ->stage('work', Tasks::agent('coder')->prompt('do the thing')->resource('gpu')->lockWaitSeconds(1))
                ->build(),
        );
        $engine = $this->engineFor($registry);

        // Hold the engine's own lock file from OUTSIDE the engine — exactly
        // the shape a competing run (or an operator's battery) presents.
        $lockFile = StageResourceLocks::pathFor($this->tempDir . '/locks', 'gpu');
        mkdir($this->tempDir . '/locks', 0700, true);
        $held = fopen($lockFile, 'c+');
        self::assertIsResource($held);
        self::assertTrue(flock($held, \LOCK_EX));

        try {
            $run = $engine->run('locked');
        } finally {
            flock($held, \LOCK_UN);
            fclose($held);
        }

        self::assertSame(WorkflowStatus::Failed, $run->status);
        $error = (string) $run->stageResults[0]->error;
        self::assertStringContainsString('gpu', $error, 'the loud failure names the resource');
        self::assertStringContainsString('1.0s', $error, 'and the bound it waited');
        self::assertSame([], $this->prompts, 'a stage that never got the lock never dispatches');

        // The failed run withdrew its O1 record and status stays Failed.
        self::assertFileDoesNotExist($this->tempDir . '/workflows/.running/locked.json');
        self::assertSame(WorkflowStatus::Failed, $engine->getStatus('locked'));

        // Once the hold is gone, the same workflow runs — and its output is
        // ordinary again.
        $this->prompts = [];
        $again = $this->engineFor($registry)->run('locked');
        self::assertSame(WorkflowStatus::Completed, $again->status);
        self::assertSame(['do the thing'], $this->prompts);
    }

    public function testReleasedLocksDoNotPoisonTheNextRunInAnyProcess(): void
    {
        $registry = new WorkflowRegistry($this->tempDir . '/workflows');
        $registry->register(
            (new WorkflowBuilder())
                ->name('first')
                ->description('wants gpu once')
                ->stage('work', Tasks::agent('coder')->prompt('one')->resource('gpu')->lockWaitSeconds(1))
                ->build(),
        );
        $registry->register(
            (new WorkflowBuilder())
                ->name('second')
                ->description('wants gpu after')
                ->stage('work', Tasks::agent('coder')->prompt('two')->resource('gpu')->lockWaitSeconds(1))
                ->build(),
        );

        // Same engine, same process: if the finally-release ever regressed,
        // the second run would be locked out by the first one's ghost.
        $engine = $this->engineFor($registry);

        self::assertSame(WorkflowStatus::Completed, $engine->run('first')->status);
        $this->prompts = [];
        self::assertSame(WorkflowStatus::Completed, $engine->run('second')->status);
        self::assertSame(['two'], $this->prompts);
    }

    public function testADeadProcessReleasesItsLockForTheNextRun(): void
    {
        if (!function_exists('proc_open') || !function_exists('posix_kill')) {
            $this->markTestSkipped('the dead-holder pin drives a real second process');
        }

        $lockDir = $this->tempDir . '/locks';
        $child = $this->tempDir . '/holder.php';
        file_put_contents($child, <<<'PHP'
            <?php

            declare(strict_types=1);

            require $argv[1];

            $handle = fopen($argv[2], 'c+');
            \SugarCraft\Code\Support\TimedFileLock::acquire($handle, \LOCK_EX, 'dead-holder test lock', 5.0);
            file_put_contents($argv[3], 'held');
            if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
                pcntl_async_signals(true);
                pcntl_signal(\SIGALRM, static function (): void {
                    exit(9);
                });
                pcntl_alarm(20);
            }
            while (true) {
                usleep(100_000);
            }
            PHP);

        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        mkdir($lockDir, 0700, true);
        $lockFile = StageResourceLocks::pathFor($lockDir, 'gpu');
        $marker = $this->tempDir . '/held';

        $process = proc_open(
            [PHP_BINARY, $child, $autoload, $lockFile, $marker],
            [0 => ['pipe', 'r'], 1 => ['file', $this->tempDir . '/holder.out', 'a'], 2 => ['file', $this->tempDir . '/holder.out', 'a']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);

        try {
            $deadline = microtime(true) + 15.0;
            while (!is_file($marker)) {
                if (!proc_get_status($process)['running']) {
                    self::fail('the lock holder exited before taking the lock: ' . (string) @file_get_contents($this->tempDir . '/holder.out'));
                }
                self::assertLessThan($deadline, microtime(true), 'the lock holder never announced itself');
                usleep(25_000);
            }

            // While it lives, the engine cannot get the resource: the 1s
            // wait fails loudly (the message is the same shape as the
            // in-process contention pin above).
            $registry = new WorkflowRegistry($this->tempDir . '/workflows');
            $registry->register(
                (new WorkflowBuilder())
                    ->name('locked')
                    ->description('one stage wanting gpu')
                    ->stage('work', Tasks::agent('coder')->prompt('do the thing')->resource('gpu')->lockWaitSeconds(1))
                    ->build(),
            );

            $blocked = $this->engineFor($registry)->run('locked');
            self::assertSame(WorkflowStatus::Failed, $blocked->status);
            self::assertStringContainsString('gpu', (string) $blocked->stageResults[0]->error);
        } finally {
            $status = proc_get_status($process);
            if ($status['running']) {
                posix_kill($status['pid'], \SIGKILL);
            }
            proc_close($process);
        }

        // The kernel released with the dead process's descriptors — the next
        // run gets the lock at once. No cleanup, no stale-file sweep.
        $this->prompts = [];
        $fresh = $this->engineFor($registry)->run('locked');
        self::assertSame(WorkflowStatus::Completed, $fresh->status);
        self::assertSame(['do the thing'], $this->prompts);
    }

    public function testTwoProcessesOnOneResourceSerializeTheirStages(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('the serialization pin drives two real processes');
        }

        $log = $this->tempDir . '/order.log';
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $child = $this->tempDir . '/worker.php';
        file_put_contents($child, <<<'PHP'
            <?php

            declare(strict_types=1);

            require $argv[1];

            // args: autoload, registryDir, id, holdSeconds, waitSeconds, logFile
            $registry = new SugarCraft\Code\Workflows\WorkflowRegistry($argv[2] . '/workflows');
            $registry->register(
                (new SugarCraft\Code\Workflows\WorkflowBuilder())
                    ->name('w-' . $argv[3])
                    ->description('one resource stage')
                    ->stage(
                        'work',
                        SugarCraft\Code\Workflows\Tasks::agent('coder')
                            ->prompt('do the thing')
                            ->resource('gpu')
                            ->lockWaitSeconds((int) $argv[5]),
                    )
                    ->build(),
            );

            $test = new class ($argv[6], $argv[3], (float) $argv[4]) {
                public function __construct(private readonly string $log, private readonly string $id, private readonly float $hold)
                {
                }

                public function run(): void
                {
                    file_put_contents($this->log, sprintf("START %s %.6F\n", $this->id, microtime(true)), \FILE_APPEND);
                    usleep((int) ($this->hold * 1_000_000));
                    file_put_contents($this->log, sprintf("END %s %.6F\n", $this->id, microtime(true)), \FILE_APPEND);
                }
            };

            $executor = new class ($test) implements \SugarCraft\Code\Agents\ExecutorInterface {
                public function __construct(private readonly object $test)
                {
                }

                public function execute(\SugarCraft\Code\Agents\SubAgent $subAgent, \SugarCraft\Code\Providers\CompleteRequest $request): \SugarCraft\Code\Agents\AgentResult
                {
                    $this->test->run();

                    return new \SugarCraft\Code\Agents\AgentResult(
                        agentId: $subAgent->id,
                        status: \SugarCraft\Code\Agents\AgentStatus::Completed,
                        output: 'done',
                        tokensUsed: 1,
                        costUsd: 0.0,
                        startedAt: new \DateTimeImmutable(),
                        completedAt: new \DateTimeImmutable(),
                    );
                }

                public function executeStream(\SugarCraft\Code\Agents\SubAgent $subAgent, \SugarCraft\Code\Providers\CompleteRequest $request): \Generator
                {
                    yield $this->execute($subAgent, $request);
                }

                public function cancel(string $agentId): void
                {
                }

                public function cancelAll(): void
                {
                }
            };

            $engine = new SugarCraft\Code\Workflows\WorkflowEngine($registry, new SugarCraft\Code\Agents\AgentWorkerPool(1, $executor));
            exit($engine->run('w-' . $argv[3])->status === SugarCraft\Code\Workflows\WorkflowStatus::Completed ? 0 : 1);
            PHP);

        $procA = proc_open(
            [PHP_BINARY, $child, $autoload, $this->tempDir, 'A', '0.8', '30', $log],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipesA,
        );
        $procB = proc_open(
            [PHP_BINARY, $child, $autoload, $this->tempDir, 'B', '0.3', '30', $log],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipesB,
        );
        self::assertIsResource($procA);
        self::assertIsResource($procB);
        foreach ($pipesA as $p) {
            fclose($p);
        }
        foreach ($pipesB as $p) {
            fclose($p);
        }

        $codeA = proc_close($procA);
        $codeB = proc_close($procB);
        self::assertSame(0, $codeA, 'holder A must complete');
        self::assertSame(0, $codeB, 'waiter B must complete within its 30s bound');

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($log))));
        self::assertCount(4, $lines, 'both processes logged a start and an end');

        $starts = [];
        $ends = [];
        foreach ($lines as $line) {
            if (preg_match('/^(START|END) (\w) ([0-9.]+)$/', $line, $m) === 1) {
                if ($m[1] === 'START') {
                    $starts[$m[2]] = (float) $m[3];
                } else {
                    $ends[$m[2]] = (float) $m[3];
                }
            }
        }
        // Which id STARTED first is legitimate either way — assert the SET,
        // in sorted order, and let the winner fall out of the timestamps.
        $ids = array_keys($starts);
        sort($ids);
        self::assertSame(['A', 'B'], $ids, 'both ids logged starts');
        $ids = array_keys($ends);
        sort($ids);
        self::assertSame(['A', 'B'], $ids, 'both ids logged ends');

        // Serialization: the critical sections never overlap — whoever won
        // (either order is legitimate) ran start-to-end before the other
        // started. Without the lock both processes sleep their hold side by
        // side and the intervals interleave.
        $first = $starts['A'] <= $starts['B'] ? 'A' : 'B';
        $second = $first === 'A' ? 'B' : 'A';
        self::assertLessThanOrEqual(
            $starts[$second] + 1.0e-6,
            $ends[$first],
            "stage {$second} entered its critical section while stage {$first} still held the resource",
        );
    }

    // ------------------------------------------------------------------ internals

    public function testCollectStageTasksReachesEveryStageShape(): void
    {
        $engine = new WorkflowEngine(new WorkflowRegistry($this->tempDir . '/workflows'), new AgentWorkerPool(1, $this->inlineExecutor()));

        $method = new \ReflectionMethod($engine, 'collectStageTasks');
        $method->setAccessible(true);

        $task = Tasks::agent('coder')->prompt('p')->build();
        $named = Tasks::agent('coder')->name('v')->prompt('q')->build();

        $flat = $method->invoke($engine, ['tasks' => [$task]], 'stage');
        self::assertSame([$task], $flat);

        $fanout = $method->invoke($engine, ['tasks' => [$task, 'junk', null]], 'parallel');
        self::assertSame([$task], $fanout, 'non-task entries are skipped, not fatal');

        $verified = $method->invoke($engine, ['task' => $task, 'verifier' => $named], 'verification');
        self::assertSame([$task, $named], $verified);

        $pipelined = $method->invoke($engine, ['stages' => [['tasks' => [$task]], ['tasks' => [$named]]]], 'pipeline');
        self::assertSame([$task, $named], $pipelined);
    }

    // ------------------------------------------------------------------ helpers

    private function inlineExecutor(): ExecutorInterface
    {
        return new class implements ExecutorInterface {
            public function execute(SubAgent $subAgent, CompleteRequest $request): AgentResult
            {
                return self::ok($subAgent);
            }

            public function executeStream(SubAgent $subAgent, CompleteRequest $request): \Generator
            {
                yield self::ok($subAgent);
            }

            public function cancel(string $agentId): void
            {
            }

            public function cancelAll(): void
            {
            }

            private static function ok(SubAgent $subAgent): AgentResult
            {
                return new AgentResult(
                    agentId: $subAgent->id,
                    status: AgentStatus::Completed,
                    output: 'OUT[' . $subAgent->task . ']',
                    tokensUsed: 1,
                    costUsd: 0.0,
                    startedAt: new \DateTimeImmutable(),
                    completedAt: new \DateTimeImmutable(),
                );
            }
        };
    }

    private function engineFor(WorkflowRegistry $registry): WorkflowEngine
    {
        $test = $this;
        $executor = new class ($test) implements ExecutorInterface {
            public function __construct(private readonly WorkflowStageResourceLockTest $test)
            {
            }

            public function execute(SubAgent $subAgent, CompleteRequest $request): AgentResult
            {
                return $this->test->dispatch($subAgent);
            }

            public function executeStream(SubAgent $subAgent, CompleteRequest $request): \Generator
            {
                yield $this->execute($subAgent, $request);
            }

            public function cancel(string $agentId): void
            {
            }

            public function cancelAll(): void
            {
            }
        };

        return new WorkflowEngine($registry, new AgentWorkerPool(1, $executor));
    }

    /** @internal the capturing fake's body; public only so the anonymous class can reach it */
    public function dispatch(SubAgent $subAgent): AgentResult
    {
        $this->prompts[] = $subAgent->task;

        foreach ($this->script as $prefix => $behaviour) {
            if (str_starts_with($subAgent->task, $prefix)) {
                $result = $behaviour($subAgent);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return new AgentResult(
            agentId: $subAgent->id,
            status: AgentStatus::Completed,
            output: 'OUT[' . $subAgent->task . ']',
            tokensUsed: 10,
            costUsd: 0.5,
            startedAt: new \DateTimeImmutable(),
            completedAt: new \DateTimeImmutable(),
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) && !is_link($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

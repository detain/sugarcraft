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
use SugarCraft\Code\Workflows\CpuCount;
use SugarCraft\Code\Workflows\Tasks;
use SugarCraft\Code\Workflows\Workflow;
use SugarCraft\Code\Workflows\WorkflowBuilder;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowStatus;

/**
 * P0-3 safety rails: the CPU-derived default width, the 4096-item fan-out
 * ceiling, `from.max` truncation that RECORDS its drop, and the run-wide
 * `config.maxAgents` dispatch backstop.
 */
final class WorkflowSafetyRailsTest extends TestCase
{
    private string $tempDir;
    private WorkflowRegistry $registry;

    /** @var list<string> every prompt the executor was handed, in order */
    private array $prompts = [];

    /** @var array<string, \Closure(SubAgent): ?AgentResult> prompt prefix => scripted answer */
    private array $script = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/candy_code_wf_rails_' . uniqid('', true);
        mkdir($this->tempDir . '/workflows', 0755, true);
        $this->registry = new WorkflowRegistry($this->tempDir . '/workflows');
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->tempDir);
    }

    // -------------------------------------------------------------------------
    // Default width
    // -------------------------------------------------------------------------

    public function testAnUnsetWidthResolvesToTheCpuDerivedDefault(): void
    {
        $engine = $this->engine();
        $unset = (new WorkflowBuilder())->name('w')->build();

        foreach ([64 => 16, 18 => 16, 17 => 15, 4 => 2, 3 => 1, 2 => 1, 1 => 1] as $cpus => $width) {
            $engine->setCpuCount($cpus);
            self::assertSame($width, $engine->concurrencyFor($unset), "min(16, max(1, {$cpus} - 2))");
            self::assertSame($width, Workflow::defaultConcurrency($cpus));
        }

        self::assertNull($engine->concurrencyFor((new WorkflowBuilder())->name('w')->maxConcurrent(0)->build()), 'an explicit 0 is still no cap');
        self::assertSame(7, $engine->concurrencyFor((new WorkflowBuilder())->name('w')->maxConcurrent(7)->build()), 'an explicit width wins over the default');

        $engine->setCpuCount(null);
        self::assertSame(Workflow::defaultConcurrency(CpuCount::detect()), $engine->concurrencyFor($unset), 'null restores host detection');
    }

    public function testTheCpuCountAndWidthRefuseNonsense(): void
    {
        try {
            $this->engine()->setCpuCount(0);
            self::fail('a zero CPU count must be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('at least 1 CPU', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxConcurrent() needs 0 (no cap) or a width of at least 1, got -1');
        (new WorkflowBuilder())->maxConcurrent(-1);
    }

    public function testTheCpuListParserCountsKernelRanges(): void
    {
        self::assertSame(7, CpuCount::countCpuList('0-3,6,8-9'));
        self::assertSame(1, CpuCount::countCpuList('0'));
        self::assertNull(CpuCount::countCpuList(''));
        self::assertNull(CpuCount::countCpuList('zero'));
        self::assertNull(CpuCount::countCpuList('3-1'));
        self::assertGreaterThanOrEqual(1, CpuCount::detect());
    }

    // -------------------------------------------------------------------------
    // Fan-out ceiling and from.max
    // -------------------------------------------------------------------------

    public function testAFanOutPastTheCeilingFailsLoudlyAndDispatchesNothing(): void
    {
        $envRoot = $this->tempDir . '/env';
        mkdir($envRoot, 0755, true);
        for ($i = 0; $i <= Workflow::FAN_OUT_CEILING; $i++) {
            touch(sprintf('%s/f%05d.txt', $envRoot, $i));
        }

        $this->registerSweep([]);
        $result = $this->engine($envRoot)->run('sweep');

        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertSame([], $this->prompts, 'nothing forks — the wave is refused, never truncated to fit');
        self::assertSame(
            "Stage 'sweep' expanded 4097 files from \"" . chr(42) . ".txt\"; the per-stage fan-out ceiling is 4096 — narrow the glob or set from.max",
            $result->firstFailure()?->error,
        );
    }

    public function testFromMaxKeepsTheSortedPrefixAndRecordsWhatItDropped(): void
    {
        $envRoot = $this->fiveFiles();
        $this->registerSweep(['max' => 2]);

        $result = $this->engine($envRoot)->run('sweep');

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame(['fix a.txt', 'fix b.txt'], $this->prompts, 'the first two of the SORTED expansion run');
        self::assertSame(
            "[from: kept 2 of 5 matches (max: 2); 3 dropped, listed at the end of this output]\n"
            . "OUT[fix a.txt]\nOUT[fix b.txt]\n"
            . "dropped: c.txt\ndropped: d.txt\ndropped: e.txt",
            $result->stageResults[0]->output,
        );
    }

    public function testAMaxThatCutsNothingLeavesTheOutputByteIdentical(): void
    {
        $envRoot = $this->fiveFiles();
        $this->registerSweep(['max' => 5]);
        $capped = $this->engine($envRoot)->run('sweep')->stageResults[0]->output;

        $this->prompts = [];
        $this->registerSweep([]);
        $plain = $this->engine($envRoot)->run('sweep')->stageResults[0]->output;

        self::assertSame($plain, $capped);
        self::assertStringNotContainsString('[from:', $capped);
    }

    public function testATruncationRidesThePauseRecordAndReplaysItsNotice(): void
    {
        $envRoot = $this->fiveFiles();
        $this->registerSweep(['max' => 2]);
        $engine = $this->engine($envRoot);

        $this->script['fix b'] = static function (SubAgent $a) use ($engine): AgentResult {
            $engine->pause('sweep');

            return new AgentResult(agentId: $a->id, status: AgentStatus::Failed, error: new \RuntimeException('b broke'));
        };

        $run = $engine->run('sweep');
        self::assertSame(WorkflowStatus::Failed, $run->status);

        $wave = $this->pauseRecord('sweep')['dynamicWaves']['sweep'];
        self::assertSame(['a.txt', 'b.txt'], $wave['paths']);
        self::assertSame(2, $wave['max']);
        self::assertSame(5, $wave['matched']);
        self::assertSame(['c.txt', 'd.txt', 'e.txt'], $wave['dropped']);

        // The tree grows under the record: the replay keeps the recorded cut.
        file_put_contents($envRoot . '/0.txt', 'early');
        unset($this->script['fix b']);
        $this->prompts = [];

        $resumed = $engine->resume($run->workflowId);

        self::assertSame(WorkflowStatus::Completed, $resumed->status, (string) $resumed->firstFailure()?->error);
        self::assertSame(['fix a.txt', 'fix b.txt'], $this->prompts);
        self::assertStringStartsWith('[from: kept 2 of 5 matches (max: 2); 3 dropped', (string) $resumed->stageResults[0]->output);
        self::assertStringEndsWith("dropped: e.txt", (string) $resumed->stageResults[0]->output);
    }

    public function testTheBuilderHoldsFromToTheLoaderShape(): void
    {
        $stage = (new WorkflowBuilder())->name('w')
            ->parallel('wave', [Tasks::agent('coder')->prompt('p')], from: ['glob' => ' src/x ', 'max' => 3])
            ->build()->stages[0];
        self::assertSame(['glob' => 'src/x', 'chunk' => null, 'max' => 3], $stage['from']);

        foreach ([
            [['glob' => 'x', 'max' => 0], 'from "max" must be a whole number from 1 to 4096'],
            [['glob' => 'x', 'max' => 4097], 'from "max" must be a whole number from 1 to 4096'],
            [['glob' => 'x', 'chunk' => 0], 'from "chunk" must be a whole number of at least 1'],
            [['glob' => ''], 'from needs a non-empty "glob"'],
            [['glob' => 'x', 'root' => '/'], 'from has unknown key "root"'],
        ] as [$from, $needle]) {
            try {
                (new WorkflowBuilder())->parallel('wave', [Tasks::agent('coder')], from: $from);
                self::fail("from " . json_encode($from) . ' must be refused');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($needle, $e->getMessage());
            }
        }
    }

    // -------------------------------------------------------------------------
    // config.maxAgents
    // -------------------------------------------------------------------------

    public function testTheDefaultCeilingIsAThousand(): void
    {
        self::assertSame(1000, (new WorkflowBuilder())->name('w')->build()->maxAgents);
        self::assertSame(1000, (new Workflow('w', 'd'))->maxAgents);
        self::assertSame(3, (new WorkflowBuilder())->name('w')->maxAgents(3)->build()->maxAgents);
        self::assertSame(3, (new Workflow('w', 'd', maxAgents: 3))->withStatus(WorkflowStatus::Running)->maxAgents, 'mutate() carries it');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxAgents() needs a whole number of at least 1, got 0');
        (new WorkflowBuilder())->maxAgents(0);
    }

    public function testADispatchPastTheCeilingFailsItsStage(): void
    {
        $this->registry->register((new WorkflowBuilder())->name('three')->maxAgents(2)
            ->stage('a', Tasks::agent('coder')->prompt('do a'))
            ->stage('b', Tasks::agent('coder')->prompt('do b'))
            ->stage('c', Tasks::agent('coder')->prompt('do c'))
            ->build());

        $result = $this->engine()->run('three');

        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertSame(['do a', 'do b'], $this->prompts);
        self::assertSame('c', $result->firstFailure()?->stageName);
        self::assertStringStartsWith('agent ceiling reached (2)', (string) $result->firstFailure()?->error);
    }

    public function testAWaveThatWouldCrossTheCeilingForksNoneOfIt(): void
    {
        $this->registry->register((new WorkflowBuilder())->name('wave')->maxAgents(2)
            ->parallel('fan', [
                Tasks::agent('coder')->prompt('one'),
                Tasks::agent('coder')->prompt('two'),
                Tasks::agent('coder')->prompt('three'),
            ])
            ->build());

        $result = $this->engine()->run('wave');

        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertSame([], $this->prompts);
        self::assertStringContainsString('agent ceiling reached (2): this run has dispatched 0 agents and this stage needs 3 more', (string) $result->firstFailure()?->error);
    }

    public function testRetriesCountAgainstTheCeiling(): void
    {
        $tries = 0;
        $this->script['flaky'] = static function (SubAgent $a) use (&$tries): ?AgentResult {
            return ++$tries === 1
                ? new AgentResult(agentId: $a->id, status: AgentStatus::Failed, error: new \RuntimeException('hiccup'))
                : null;
        };
        $this->registry->register((new WorkflowBuilder())->name('retry')->maxAgents(2)
            ->stage('a', Tasks::agent('coder')->prompt('flaky')->retries(1))
            ->stage('b', Tasks::agent('coder')->prompt('do b'))
            ->build());

        $result = $this->engine()->run('retry');

        self::assertSame(['flaky', 'flaky'], $this->prompts, 'the retry ran — it was the second dispatch');
        self::assertSame('b', $result->firstFailure()?->stageName);
        self::assertStringStartsWith('agent ceiling reached (2)', (string) $result->firstFailure()?->error);
    }

    public function testLoopRoundsCountAgainstTheCeiling(): void
    {
        $this->registry->register((new WorkflowBuilder())->name('loop')->maxAgents(2)
            ->stage('fix', Tasks::agent('coder')->prompt('round {{round}}'))
            ->loop(['max' => 5, 'until' => ['stage' => 'fix', 'contains' => 'NEVER']])
            ->build());

        $result = $this->engine()->run('loop');

        self::assertSame(['round 1', 'round 2'], $this->prompts);
        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertStringStartsWith('agent ceiling reached (2)', (string) $result->firstFailure()?->error);
    }

    public function testAnIncludedWorkflowSharesItsParentsCeiling(): void
    {
        $this->registry->register((new WorkflowBuilder())->name('inner')
            ->stage('i1', Tasks::agent('coder')->prompt('inner one'))
            ->stage('i2', Tasks::agent('coder')->prompt('inner two'))
            ->build());
        $this->registry->register((new WorkflowBuilder())->name('outer')->maxAgents(2)
            ->stage('o1', Tasks::agent('coder')->prompt('outer one'))
            ->uses('carry', 'inner')
            ->build());

        $result = $this->engine()->run('outer');

        self::assertSame(['outer one', 'inner one'], $this->prompts, "the inner run's own default ceiling does not reset the count");
        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertStringContainsString('agent ceiling reached (2)', (string) $result->firstFailure()?->error);
    }

    public function testAResumeKeepsCountingFromThePauseRecord(): void
    {
        $this->registry->register((new WorkflowBuilder())->name('long')->maxAgents(3)
            ->stage('a', Tasks::agent('coder')->prompt('do a'))
            ->stage('b', Tasks::agent('coder')->prompt('do b'))
            ->stage('c', Tasks::agent('coder')->prompt('do c'))
            ->stage('d', Tasks::agent('coder')->prompt('do d'))
            ->build());
        $engine = $this->engine();
        $this->script['do b'] = static function () use ($engine): ?AgentResult {
            $engine->pause('long');

            return null;
        };

        $run = $engine->run('long');
        self::assertSame(WorkflowStatus::Paused, $run->status);
        self::assertSame(2, $this->pauseRecord('long')['agentsDispatched']);

        $this->prompts = [];
        $resumed = $engine->resume($run->workflowId);

        self::assertSame(['do c'], $this->prompts, 'the resumed run had one dispatch left, not a fresh thousand');
        self::assertSame('d', $resumed->firstFailure()?->stageName);
        self::assertStringStartsWith('agent ceiling reached (3)', (string) $resumed->firstFailure()?->error);
    }

    // -------------------------------------------------------------------------

    /** @internal the fake executor's body; public only so the anonymous class can reach it */
    public function dispatch(SubAgent $subAgent): AgentResult
    {
        $this->prompts[] = $subAgent->task;

        foreach ($this->script as $prefix => $answer) {
            if (str_starts_with($subAgent->task, $prefix)) {
                $scripted = $answer($subAgent);
                if ($scripted !== null) {
                    return $scripted;
                }
            }
        }

        return new AgentResult(
            agentId: $subAgent->id,
            status: AgentStatus::Completed,
            output: 'OUT[' . $subAgent->task . ']',
            tokensUsed: 1,
            costUsd: 0.01,
            startedAt: new \DateTimeImmutable(),
            completedAt: new \DateTimeImmutable(),
        );
    }

    /** @param array<string, int> $extra */
    private function registerSweep(array $extra): void
    {
        $this->registry->register((new WorkflowBuilder())->name('sweep')
            ->parallel('sweep', [Tasks::agent('coder')->prompt('fix {{path}}')], from: ['glob' => chr(42) . '.txt', 'chunk' => null] + $extra)
            ->build());
    }

    private function fiveFiles(): string
    {
        $envRoot = $this->tempDir . '/env';
        mkdir($envRoot, 0755, true);
        foreach (['e', 'c', 'a', 'd', 'b'] as $name) {
            file_put_contents("{$envRoot}/{$name}.txt", $name);
        }

        return $envRoot;
    }

    /** @return array<string, mixed> */
    private function pauseRecord(string $name): array
    {
        $file = $this->tempDir . '/workflows/.running/' . $name . '.json';
        self::assertFileExists($file);

        return (array) json_decode((string) file_get_contents($file), true);
    }

    private function engine(?string $envRoot = null): WorkflowEngine
    {
        $executor = new class ($this) implements ExecutorInterface {
            public function __construct(private readonly WorkflowSafetyRailsTest $test)
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

        return new WorkflowEngine($this->registry, new AgentWorkerPool(1, $executor), environmentRoot: $envRoot);
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? self::removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}

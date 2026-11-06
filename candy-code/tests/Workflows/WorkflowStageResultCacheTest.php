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
use SugarCraft\Code\Workflows\Tasks;
use SugarCraft\Code\Workflows\WorkflowBuilder;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowStatus;

/**
 * C13: the opt-in per-stage result cache on resume.
 *
 * A stage that declares `cache: true` may reuse its recorded successful
 * result when a resumed run's re-computed input hash still matches the hash
 * recorded when that stage settled — same ask, same answer, zero dispatch.
 * Every other shape (flag absent — the default —, missing record row,
 * missing/forged hash, edited declaration) answers a plain re-dispatch: the
 * cache only ever narrows work, never invents it. The g5 substrate supplies
 * both halves: the per-stage identity hashes ride the pause record, and the
 * serialized stage rows are lossless enough to carry.
 */
final class WorkflowStageResultCacheTest extends TestCase
{
    private string $tempDir;
    private WorkflowRegistry $registry;
    private WorkflowEngine $engine;

    /** @var list<string> every prompt the executor was handed, in order */
    private array $prompts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/candy_code_wf_cache_' . uniqid('', true);
        mkdir($this->tempDir . '/workflows', 0755, true);
        $this->registry = new WorkflowRegistry($this->tempDir . '/workflows');

        $test = $this;
        $executor = new class ($test) implements ExecutorInterface {
            public function __construct(private readonly WorkflowStageResultCacheTest $test)
            {
            }

            public function execute(SubAgent $subAgent, CompleteRequest $request): AgentResult
            {
                return $this->test->dispatchCache($subAgent);
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

        $this->engine = new WorkflowEngine($this->registry, new AgentWorkerPool(1, $executor));
    }

    protected function tearDown(): void
    {
        $this->removeCacheDirectory($this->tempDir);

        parent::tearDown();
    }

    /** @internal the fake executor's body; public only so the anonymous class can reach it */
    public function dispatchCache(SubAgent $subAgent): AgentResult
    {
        $this->prompts[] = $subAgent->task;

        return new AgentResult(
            agentId: $subAgent->id,
            status: AgentStatus::Completed,
            output: 'FRESH[' . $subAgent->task . ']',
            tokensUsed: 10,
            costUsd: 0.5,
            startedAt: new \DateTimeImmutable(),
            completedAt: new \DateTimeImmutable(),
        );
    }

    public function testAStageThatOptsInWithAMatchingHashIsCarriedNotReDispatched(): void
    {
        $this->registerThree(cached: true);
        $this->writeRecord(stageInputHashes: ['c' => $this->recipeHash('do c using {{b.output}}')]);

        $resumed = $this->engine->resume('three');

        $this->assertSame(
            ['do b using OUT[do a]'],
            $this->prompts,
            'the cached stage c settled from its recorded row — only b was dispatched',
        );
        $this->assertSame(WorkflowStatus::Completed, $resumed->status);
        // b re-dispatched costs 10 tokens/0.5; the record carries a's and c's
        // recorded 10/0.5 each — the run totals all three honestly.
        $this->assertSame(30, $resumed->totalTokens);
        $this->assertSame(1.5, $resumed->totalCost);
        $this->assertCount(3, $resumed->stageResults);
        $this->assertSame('CACHED[c]', $resumed->stageResults[2]->output);
        $this->assertSame(WorkflowStatus::Completed, $resumed->stageResults[2]->status);
    }

    public function testAForgedHashNeverBuysAReuse(): void
    {
        $this->registerThree(cached: true);
        $this->writeRecord(stageInputHashes: ['c' => str_repeat('f', 64)]);

        $resumed = $this->engine->resume('three');

        $this->assertSame(
            ['do b using OUT[do a]', 'do c using FRESH[do b using OUT[do a]]'],
            $this->prompts,
            'a recorded hash that does not match the stage declaration is a cache miss, not a carry',
        );
        $this->assertSame(WorkflowStatus::Completed, $resumed->status);
    }

    public function testTheCacheIsOffUnlessTheStageOptsIn(): void
    {
        $this->registerThree(cached: false);
        $this->writeRecord(stageInputHashes: ['c' => $this->recipeHash('do c using {{b.output}}')]);

        $resumed = $this->engine->resume('three');

        $this->assertSame(
            ['do b using OUT[do a]', 'do c using FRESH[do b using OUT[do a]]'],
            $this->prompts,
            'matching bytes without the opt-in flag re-dispatch — the door defaults closed',
        );
        $this->assertSame(WorkflowStatus::Completed, $resumed->status);
    }

    public function testAnEditedStageDeclarationLosesTheMatch(): void
    {
        // The record was written while c asked for the plain prompt; the
        // registered declaration now differs, so the re-computed hash cannot
        // match and the stage must run again.
        $this->registry->register(
            (new WorkflowBuilder())
                ->name('three')
                ->description('a then b then c')
                ->stage('a', Tasks::agent('coder')->prompt('do a'))
                ->stage('b', Tasks::agent('coder')->prompt('do b using {{a.output}}'))
                ->stage('c', Tasks::agent('coder')->prompt('do c differently using {{b.output}}'), cache: true)
                ->build(),
        );
        $this->writeRecord(stageInputHashes: ['c' => $this->recipeHash('do c using {{b.output}}')]);

        $resumed = $this->engine->resume('three');

        $this->assertSame(
            ['do b using OUT[do a]', 'do c differently using FRESH[do b using OUT[do a]]'],
            $this->prompts,
            'an edit to the stage is a new ask: the recorded answer no longer fits',
        );
        $this->assertSame(WorkflowStatus::Completed, $resumed->status);
    }

    private function registerThree(bool $cached): void
    {
        $this->registry->register(
            (new WorkflowBuilder())
                ->name('three')
                ->description('a then b then c')
                ->stage('a', Tasks::agent('coder')->prompt('do a'))
                ->stage('b', Tasks::agent('coder')->prompt('do b using {{a.output}}'))
                ->stage('c', Tasks::agent('coder')->prompt('do c using {{b.output}}'), cache: $cached)
                ->build(),
        );
    }

    /**
     * The C13 recipe, byte-pinned by the engine's substrate tests: a stage's
     * identity is its type plus each task's (prompt, model, schema).
     */
    private function recipeHash(string $prompt): string
    {
        return hash(
            'sha256',
            json_encode(
                ['type' => 'stage', 'tasks' => [['prompt' => $prompt, 'model' => null, 'schema' => null]]],
                JSON_UNESCAPED_SLASHES,
            ),
        );
    }

    /**
     * @param array<string, string> $stageInputHashes
     */
    private function writeRecord(array $stageInputHashes): void
    {
        mkdir($this->tempDir . '/workflows/.running', 0755, true);
        $atom = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        file_put_contents(
            $this->tempDir . '/workflows/.running/three.json',
            (string) json_encode([
                'workflowId' => 'three-eeeeeeee',
                'workflowPath' => 'three',
                'status' => WorkflowStatus::Paused->value,
                'stagesCompleted' => 1,
                'context' => ['a.output' => 'OUT[do a]'],
                'stageResults' => [
                    [
                        'stageName' => 'a',
                        'status' => WorkflowStatus::Completed->value,
                        'output' => 'OUT[do a]',
                        'error' => null,
                        'agents' => [[
                            'agentId' => 'a-1',
                            'status' => AgentStatus::Completed->value,
                            'output' => 'OUT[do a]',
                            'error' => null,
                            'tokensUsed' => 10,
                            'costUsd' => 0.5,
                            'startedAt' => $atom,
                            'completedAt' => $atom,
                            'attempts' => 1,
                        ]],
                        'startedAt' => $atom,
                        'completedAt' => $atom,
                    ],
                    [
                        'stageName' => 'c',
                        'status' => WorkflowStatus::Completed->value,
                        'output' => 'CACHED[c]',
                        'error' => null,
                        'agents' => [[
                            'agentId' => 'c-1',
                            'status' => AgentStatus::Completed->value,
                            'output' => 'CACHED[c]',
                            'error' => null,
                            'tokensUsed' => 10,
                            'costUsd' => 0.5,
                            'startedAt' => $atom,
                            'completedAt' => $atom,
                            'attempts' => 1,
                        ]],
                        'startedAt' => $atom,
                        'completedAt' => $atom,
                    ],
                ],
                'totalTokens' => 20,
                'totalCost' => 1.0,
                'startedAt' => $atom,
                'pausedAt' => $atom,
                'stageInputHashes' => $stageInputHashes,
            ]),
        );
    }

    private function removeCacheDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeCacheDirectory($path);

                continue;
            }
            @unlink($path);
        }
        @rmdir($dir);
    }
}

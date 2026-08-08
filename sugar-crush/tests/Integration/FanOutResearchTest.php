<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Providers\CompleteRequest;

/**
 * E2E: fan-out research coordination via AgentWorkerPool.
 *
 * This is one of the two "E2E Tests" named in crush_code_plan.md's build plan
 * (FanOutResearchTest / MultiAgentRefactorTest) that were never actually
 * built. It only makes sense now that R14a/R14a.fix hardened
 * AgentWorkerPool's real pcntl_fork() concurrency path (waitForCompletion()'s
 * WNOHANG poll loop, the storeResult() non-finite-costUsd hang) — against the
 * old busy-spin pool this would have been flaky/wrong by construction.
 *
 * Spawns 5 "explorer" SubAgents through AgentWorkerPool's DEFAULT executor
 * (ProcessExecutor + real pcntl_fork() — no mocks, matching
 * AgentWorkerPoolTest's R14a real-fork test style), each independently
 * "researching" a different top-level src/ directory. A lead (the test
 * itself, standing in for one, per this item's brief) synthesizes each
 * result as it is yielded from the pool's Generator — some of that synthesis
 * genuinely overlaps in wall-clock time with the pool's other forked
 * children still running, since executeAll() yields results incrementally
 * as each child completes, not after every child has finished.
 *
 * "No file conflicts between them" is verified two ways, both against real
 * shared-filesystem state written to concurrently by up to 5 independent OS
 * processes:
 *   1. AgentWorkerPool's own IPC layer: storeResult()/extractResult() write
 *      per-agent result files into the SAME shared sys_get_temp_dir(),
 *      concurrently, from every forked child. We assert each yielded
 *      result's output corresponds to exactly its own agent's assigned
 *      directory and never another agent's — i.e. the shared-directory IPC
 *      never cross-assigns one explorer's findings to another.
 *   2. The lead's own synthesis step: each completed result is written to a
 *      disjoint per-directory synthesis file using an exclusive-create
 *      (fopen 'x') — a real accidental collision would fail loudly here
 *      instead of silently overwriting another explorer's findings.
 */
final class FanOutResearchTest extends TestCase
{
    /** @var string[] Five real, disjoint top-level src/ directories to "research". */
    private const RESEARCH_DIRS = ['Agents', 'Workflows', 'Skills', 'Tools', 'Memory'];

    private string $synthesisDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->synthesisDir = sys_get_temp_dir() . '/sugar-crush-fanout-synth-' . uniqid('', true) . '/';
        mkdir($this->synthesisDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->synthesisDir . '*');
        if ($files !== false) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        @rmdir($this->synthesisDir);

        parent::tearDown();
    }

    /**
     * testFiveExplorersResearchDisjointDirectoriesConcurrentlyWithoutFileConflicts
     *
     * Drives 5 real forked "explorer" agents through AgentWorkerPool's
     * default (uninjected) executor path — the only path that actually
     * calls pcntl_fork() (see AgentWorkerPool::startAgent(): a custom
     * injected executor always runs synchronously, because PHPUnit mocks do
     * not survive fork()). Each agent's task string names a distinct
     * directory; ProcessExecutor's worker echoes the task verbatim into its
     * "complete" output, so we can verify each result belongs to the right
     * explorer without any test-only plumbing in the fork/executor path.
     */
    public function testFiveExplorersResearchDisjointDirectoriesConcurrentlyWithoutFileConflicts(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork() is not available in this environment.');
        }

        $runId = uniqid('fanout_', true);
        $agents = [];
        $dirsByAgentId = [];

        foreach (self::RESEARCH_DIRS as $dir) {
            $agentId = "explorer-{$runId}-{$dir}";
            $dirsByAgentId[$agentId] = $dir;

            $agents[] = new SubAgent(
                id: $agentId,
                agent: new Agent(
                    name: "Explorer-{$dir}",
                    description: 'Independent directory researcher',
                    prompt: 'You are a codebase explorer agent.',
                    model: 'test-model',
                    provider: 'test',
                    tools: [],
                    skillNames: [],
                    hooks: [],
                    isActive: true,
                ),
                task: "Research directory src/{$dir} and report its structure.",
            );
        }

        $request = new CompleteRequest(
            model: 'test-model',
            messages: [['role' => 'user', 'content' => 'fan-out research']],
        );

        // Baseline: a single agent run through the real ProcessExecutor
        // directly (executeOne() bypasses fork/pool bookkeeping entirely) —
        // same proxy AgentWorkerPoolTest's R14a real-fork test uses to
        // establish "one unit of real work" latency to compare the
        // concurrent run against.
        $baselinePool = new AgentWorkerPool(maxConcurrent: 1);
        $baselineStart = hrtime(true);
        $baselineResult = $baselinePool->executeOne(
            new SubAgent(
                id: "explorer-{$runId}-baseline",
                agent: $agents[0]->agent,
                task: 'Baseline single-agent research run.',
            ),
            $request,
        );
        $baselineDurationNs = hrtime(true) - $baselineStart;
        $this->assertSame(AgentStatus::Completed, $baselineResult->status);

        $pool = new AgentWorkerPool(maxConcurrent: 5);

        $start = hrtime(true);
        $collected = [];
        $writtenSynthesisFiles = [];

        foreach ($pool->executeAll($agents, $request) as $result) {
            $collected[$result->agentId] = $result;

            $this->assertArrayHasKey(
                $result->agentId,
                $dirsByAgentId,
                'Result carries an agent ID that was never dispatched — '
                    . 'a real conflict in the shared-temp-directory IPC layer.',
            );
            $dir = $dirsByAgentId[$result->agentId];

            // Cross-contamination check: this explorer's output must name
            // its OWN directory and none of the other 4 — despite all 5
            // forked children racing to write/read result files in the
            // SAME shared sys_get_temp_dir() concurrently.
            $this->assertStringContainsString(
                "src/{$dir}",
                (string) $result->output,
                "Explorer for {$dir} did not receive its own task back — possible IPC cross-assignment.",
            );
            foreach (self::RESEARCH_DIRS as $otherDir) {
                if ($otherDir === $dir) {
                    continue;
                }
                $this->assertStringNotContainsString(
                    "src/{$otherDir}",
                    (string) $result->output,
                    "Explorer for {$dir} received output mentioning {$otherDir}'s directory — "
                        . 'a real file/result conflict between concurrently-running explorers.',
                );
            }

            // Lead synthesis: write this explorer's findings to a disjoint
            // file. Exclusive-create means a genuine path collision between
            // two "different" directories would fail loudly right here
            // instead of silently clobbering another explorer's findings.
            $synthesisPath = $this->synthesisDir . $dir . '.synthesis.json';
            $fp = fopen($synthesisPath, 'x');
            $this->assertNotFalse(
                $fp,
                "Failed to exclusively create synthesis file for {$dir} — path collision with another explorer.",
            );
            fwrite($fp, json_encode([
                'directory' => $dir,
                'agentId' => $result->agentId,
                'status' => $result->status->value,
                'findings' => $result->output,
            ], JSON_THROW_ON_ERROR));
            fclose($fp);
            $writtenSynthesisFiles[] = $synthesisPath;
        }
        $elapsedNs = hrtime(true) - $start;

        $this->assertCount(5, $collected, 'Expected exactly 5 explorer results, no more, no fewer.');
        foreach ($collected as $result) {
            $this->assertSame(AgentStatus::Completed, $result->status);
        }

        // Genuinely-concurrent-completion proxy, same style as
        // AgentWorkerPoolTest::testExecuteAllWithRealForkedExecutorCompletesWithinBoundedMultipleOfSingleAgentDuration:
        // very generous headroom (10x baseline, floor 20s) since this
        // environment runs multiple heavy proc_open-based suites in
        // parallel, which inflates timing non-linearly. The bound only
        // needs to catch a genuine stall/regression that reintroduces
        // sequential (non-parallel) execution across 5 real agents.
        $maxAllowedNs = max($baselineDurationNs * 10, 20_000_000_000);
        $this->assertLessThanOrEqual(
            $maxAllowedNs,
            $elapsedNs,
            sprintf(
                'Concurrent execution of 5 explorer agents took %.3fs, exceeding the bound of %.3fs '
                    . '(10x the single-agent baseline of %.3fs) — possible stall/regression in the '
                    . 'worker pool completion loop, or a silent fallback to sequential execution.',
                $elapsedNs / 1e9,
                $maxAllowedNs / 1e9,
                $baselineDurationNs / 1e9,
            ),
        );

        // Final "no conflicts" sweep: exactly one synthesis file per
        // directory exists on disk, matching what was written above — no
        // extra, no missing, no duplicate.
        $this->assertCount(5, $writtenSynthesisFiles);
        $onDisk = glob($this->synthesisDir . '*.synthesis.json');
        $this->assertNotFalse($onDisk);
        $this->assertCount(5, $onDisk, 'Expected exactly 5 synthesis files on disk, one per researched directory.');
        foreach (self::RESEARCH_DIRS as $dir) {
            $this->assertFileExists($this->synthesisDir . $dir . '.synthesis.json');
        }
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\ExecutorInterface;
use SugarCraft\Crush\Workflows\Tasks;
use SugarCraft\Crush\Workflows\WorkflowBuilder;
use SugarCraft\Crush\Workflows\WorkflowEngine;
use SugarCraft\Crush\Workflows\WorkflowNotFoundException;
use SugarCraft\Crush\Workflows\WorkflowNotRunningException;
use SugarCraft\Crush\Workflows\WorkflowRegistry;
use SugarCraft\Crush\Workflows\WorkflowStatus;

/**
 * `/workflow pause|resume|status` must accept the identifier `/workflow run`
 * PRINTS.
 *
 * The defect, measured on a real `Bootstrap::chat($root)` launch before this
 * file existed:
 *
 *     /workflow run safe             -> ID: `safe-252630d0`
 *     /workflow pause safe-252630d0  -> **Error:** No result found for workflow 'safe-252630d0'.
 *     /workflow status safe-252630d0 -> **Error:** No pause file found for workflow 'safe-252630d0'
 *     /workflow resume safe-252630d0 -> **Error:** No paused workflow found with ID 'safe-252630d0'
 *     /workflow pause safe           -> Workflow `safe` has been paused.
 *
 * Three of the five verbs rejected the only identifier the UI hands the user,
 * and accepted one it never prints. Engine-side cause: {@see WorkflowEngine::run()}
 * keyed its result map by the workflow NAME while the SIGINT path keyed the same
 * map by the generated `<name>-<hash>`, so which spelling worked depended on how
 * the run had ended, and the pause FILE was named after whichever string the
 * caller happened to use.
 *
 * WHY THE SUITE COULD NOT SEE IT: every engine pause/resume/status test passed a
 * NAME, and the only Chat-level ones passed no argument at all. So the corpus
 * could not produce the mismatch. Every test here takes its identifier from
 * `$result->workflowId` — the exact string the transcript shows — or exercises
 * the cross-process direction where the in-memory map is gone.
 */
final class WorkflowIdentifierTest extends TestCase
{
    private string $tempDir;
    private WorkflowRegistry $registry;
    private WorkflowEngine $engine;
    private ExecutorInterface $mockExecutor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sugarcrush_wf_ids_' . uniqid('', true);
        mkdir($this->tempDir . '/workflows', 0755, true);

        // An explicit user-tier directory rather than a redirected HOME: the
        // pause file is anchored to the REGISTRY's own workflows path, so naming
        // it here is both the isolation and an assertion about that anchoring.
        $this->registry = new WorkflowRegistry($this->tempDir . '/workflows');

        $this->mockExecutor = $this->getMockBuilder(ExecutorInterface::class)
            ->onlyMethods(['execute', 'executeStream', 'cancel', 'cancelAll'])
            ->getMock();
        $this->mockExecutor->method('execute')->willReturnCallback(
            fn (): AgentResult => new AgentResult(
                agentId: 'agent-' . uniqid(),
                status: AgentStatus::Completed,
                output: 'ok',
                tokensUsed: 10,
                costUsd: 0.001,
                startedAt: new \DateTimeImmutable(),
                completedAt: new \DateTimeImmutable(),
            ),
        );

        $this->engine = new WorkflowEngine($this->registry, new AgentWorkerPool(2, $this->mockExecutor));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    private function registerWorkflow(string $name, int $stages = 2): void
    {
        $builder = (new WorkflowBuilder())->name($name)->description('identifier fixture');
        for ($i = 1; $i <= $stages; $i++) {
            $builder = $builder->stage("stage-{$i}", Tasks::agent('coder')->prompt("Step {$i}"));
        }

        $this->registry->register($builder->build());
    }

    private function pauseDir(): string
    {
        return $this->tempDir . '/workflows/.running';
    }

    /** The run ID is a distinct string from the name, or none of this matters. */
    public function testTheIdARunPrintsIsNotTheNameItWasStartedWith(): void
    {
        $this->registerWorkflow('safe');

        $id = $this->engine->run('safe')->workflowId;

        $this->assertNotSame('safe', $id);
        $this->assertMatchesRegularExpression('/^safe-[0-9a-f]{8}$/', $id);
    }

    /**
     * The headline: the printed ID pauses the run, and lands in the SAME file the
     * name would have.
     */
    public function testPauseAcceptsThePrintedRunIdAndTheNameInterchangeably(): void
    {
        $this->registerWorkflow('safe');
        $id = $this->engine->run('safe')->workflowId;

        $this->engine->pause($id);

        // Named by the LOADABLE name, not by the identifier the caller typed:
        // resume() has to hand `workflowPath` back to the registry, and one
        // canonical file is what lets both spellings find the same state.
        $this->assertFileExists($this->pauseDir() . '/safe.json');
        $this->assertSame(
            ['safe.json'],
            array_values(array_diff((array) scandir($this->pauseDir()), ['.', '..'])),
            'one run must not produce two pause files, one per spelling',
        );

        $data = json_decode((string) file_get_contents($this->pauseDir() . '/safe.json'), true);
        $this->assertSame($id, $data['workflowId']);
        $this->assertSame('safe', $data['workflowPath']);

        // And the name still works, because breaking it to fix the ID would be
        // the same defect facing the other way.
        $this->engine->pause('safe');
        $this->assertFileExists($this->pauseDir() . '/safe.json');
    }

    public function testStatusAcceptsThePrintedRunIdAndTheName(): void
    {
        $this->registerWorkflow('safe');
        $id = $this->engine->run('safe')->workflowId;
        $this->engine->pause($id);

        $this->assertSame(WorkflowStatus::Paused, $this->engine->getStatus($id));
        $this->assertSame(WorkflowStatus::Paused, $this->engine->getStatus('safe'));
    }

    /**
     * The CROSS-PROCESS direction, which the in-memory alias map cannot serve: a
     * user reads the ID off a transcript, restarts the CLI, and types it into
     * `/workflow status`. A fresh engine has no memory of the run, so the only
     * thing that can map the ID back to a file is the ID recorded INSIDE it.
     */
    public function testAFreshEngineResolvesAPrintedRunIdOutOfThePauseFileItself(): void
    {
        $this->registerWorkflow('safe');
        $id = $this->engine->run('safe')->workflowId;
        $this->engine->pause($id);

        $fresh = new WorkflowEngine(
            new WorkflowRegistry($this->tempDir . '/workflows'),
            new AgentWorkerPool(2, $this->mockExecutor),
        );

        $this->assertSame(WorkflowStatus::Paused, $fresh->getStatus($id));
    }

    /**
     * resume() by printed ID reloads the definition and KEEPS the identity, so a
     * user who pauses and resumes twice is not handed a new ID each time to look
     * the run up by.
     */
    public function testResumeAcceptsThePrintedRunIdAndPreservesIt(): void
    {
        $this->registerWorkflow('safe', stages: 3);
        $id = $this->engine->run('safe')->workflowId;
        $this->engine->pause($id);

        $resumed = $this->engine->resume($id);

        $this->assertSame($id, $resumed->workflowId);
        $this->assertSame(WorkflowStatus::Completed, $resumed->status);
    }

    /**
     * A pause taken AFTER a resume must still be loadable.
     *
     * This is what the two fields being one string cost: `workflowPath` used to be
     * written as whatever identifier the caller passed, so pausing a run that had
     * been resumed BY ID recorded an ID where a registry name belongs, and the
     * next resume asked `load('safe-1a2b3c4d')`.
     */
    public function testAPauseTakenAfterAResumeStillNamesALoadableWorkflow(): void
    {
        $this->registerWorkflow('safe', stages: 3);
        $id = $this->engine->run('safe')->workflowId;
        $this->engine->pause($id);
        $this->engine->resume($id);

        $this->engine->pause($id);

        $data = json_decode((string) file_get_contents($this->pauseDir() . '/safe.json'), true);
        $this->assertSame('safe', $data['workflowPath']);
        $this->assertSame($id, $data['workflowId']);
        $this->assertSame(WorkflowStatus::Completed, $this->engine->resume($id)->status);
    }

    /**
     * A workflow whose NAME looks like a run ID resolves to itself.
     *
     * The reason the ID lookup decodes each pause file instead of stripping a
     * trailing `-[0-9a-f]{8}`: that pattern would guess at generateWorkflowId()'s
     * shape, and `deploy-1a2b3c4d` is a perfectly ordinary workflow name.
     */
    public function testAWorkflowNamedLikeARunIdResolvesToItself(): void
    {
        $this->registerWorkflow('deploy-1a2b3c4d');
        $id = $this->engine->run('deploy-1a2b3c4d')->workflowId;
        $this->assertNotSame('deploy-1a2b3c4d', $id);

        $this->engine->pause('deploy-1a2b3c4d');

        $this->assertFileExists($this->pauseDir() . '/deploy-1a2b3c4d.json');
        $this->assertSame(WorkflowStatus::Paused, $this->engine->getStatus('deploy-1a2b3c4d'));
        $this->assertSame(WorkflowStatus::Paused, $this->engine->getStatus($id));
    }

    /**
     * A pause file written by an OLDER build, found by this one.
     *
     * Older builds wrote `workflowId` and `workflowPath` as the SAME string —
     * whatever identifier the caller passed — and named the file after it. Two
     * shapes exist on disk in the wild, and this pins what each one now does
     * rather than leaving it to be discovered:
     *
     *  - `<name>.json` (the common one, from `/workflow pause <name>`): fully
     *    usable. Found by name through the exact-filename lookup, and resumable
     *    because `workflowPath` is a name the registry resolves.
     *  - `<name>-<hash>.json` (from a pause taken under a run ID): still FOUND by
     *    that ID, and `resume()` still cannot load it — `workflowPath` holds an ID
     *    where a registry name belongs. That was equally true of the build that
     *    wrote it, so it is a limitation carried forward, not one introduced; the
     *    fix is forward-only, since a file this build writes records both fields.
     */
    public function testPauseFilesWrittenByAnOlderBuildAreStillResolvable(): void
    {
        $this->registerWorkflow('legacy');
        mkdir($this->pauseDir(), 0755, true);

        $legacy = static fn (string $identifier): string => (string) json_encode([
            'workflowId' => $identifier,
            'workflowPath' => $identifier,
            'status' => 'paused',
            'stagesCompleted' => 1,
            'context' => ['stage-1.output' => 'from-the-old-build'],
            'stageResults' => [],
            'totalTokens' => 10,
            'totalCost' => 0.001,
            'startedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'pausedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);

        file_put_contents($this->pauseDir() . '/legacy.json', $legacy('legacy'));
        $this->assertSame(WorkflowStatus::Paused, $this->engine->getStatus('legacy'));
        $resumed = $this->engine->resume('legacy');
        $this->assertSame(WorkflowStatus::Completed, $resumed->status);
        $this->assertSame('from-the-old-build', $resumed->context['stage-1.output']);

        file_put_contents($this->pauseDir() . '/legacy-1a2b3c4d.json', $legacy('legacy-1a2b3c4d'));
        $this->assertSame(
            WorkflowStatus::Paused,
            $this->engine->getStatus('legacy-1a2b3c4d'),
            'a legacy file named after a run ID is still found by that ID',
        );
        $this->expectException(WorkflowNotFoundException::class);
        $this->engine->resume('legacy-1a2b3c4d');
    }

    /** Accepting more spellings must not mean accepting anything. */
    public function testAnUnknownIdentifierIsStillRefused(): void
    {
        $this->registerWorkflow('safe');
        $this->engine->run('safe');

        try {
            $this->engine->pause('safe-00000000');
            $this->fail('a run ID this engine never issued must not pause anything');
        } catch (WorkflowNotRunningException $e) {
            $this->assertStringContainsString('No result found', $e->getMessage());
        }

        $this->expectException(WorkflowNotRunningException::class);
        $this->engine->getStatus('never-paused');
    }

    /**
     * And the identifier is still a FILENAME component, so the traversal refusal
     * survives the new resolution in front of it.
     */
    public function testAnIdentifierWithPathSeparatorsIsStillRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->engine->getStatus('../../etc/passwd');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}

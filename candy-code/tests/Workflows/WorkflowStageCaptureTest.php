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
use SugarCraft\Code\Workflows\WorkflowLoadException;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowStatus;

/**
 * S3: `capture: true` on a stage publishes that stage's FULL output to a
 * run-scoped file and hands downstream prompts the path plus a bounded
 * preview — the self-contained-lane move: detail lives in a file an agent
 * reads when it needs it, the prompt envelope stays cache-stable, and
 * nobody inherits a silent truncation. Uncaptured runs are byte-identical.
 */
final class WorkflowStageCaptureTest extends TestCase
{
    private string $tempDir;
    private WorkflowRegistry $registry;
    private WorkflowEngine $engine;

    /** @var list<string> */
    private array $prompts = [];

    /** @var string the output `big` answers with */
    private string $big;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/candy_code_wf_capture_' . uniqid('', true);
        mkdir($this->tempDir . '/workflows', 0755, true);
        $this->registry = new WorkflowRegistry($this->tempDir . '/workflows');
        $this->big = str_repeat('x', 5000);

        $test = $this;
        $executor = new class ($test) implements ExecutorInterface {
            public function __construct(private readonly WorkflowStageCaptureTest $test)
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

        $this->engine = new WorkflowEngine($this->registry, new AgentWorkerPool(1, $executor));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    /** @internal the fake executor's body; public only so the anonymous class can reach it */
    public function dispatch(SubAgent $subAgent): AgentResult
    {
        $this->prompts[] = $subAgent->task;

        return new AgentResult(
            agentId: $subAgent->id,
            status: AgentStatus::Completed,
            output: str_starts_with($subAgent->task, 'big') ? $this->big : 'OUT[' . $subAgent->task . ']',
            tokensUsed: 10,
            costUsd: 0.5,
            startedAt: new \DateTimeImmutable(),
            completedAt: new \DateTimeImmutable(),
        );
    }

    public function testACapturedStagePublishesTheWholeOutputAndDownstreamGetsAPathPlusPreview(): void
    {
        $this->registry->register(
            (new WorkflowBuilder())
                ->name('cap')
                ->description('a captured then b')
                ->stage('a', Tasks::agent('coder')->prompt('big'), capture: true)
                ->stage('b', Tasks::agent('coder')->prompt('use {{a.output}}'))
                ->build(),
        );

        $run = $this->engine->run('cap');
        self::assertSame(WorkflowStatus::Completed, $run->status);

        $file = WorkflowEngine::stageCapturePath($this->tempDir . '/workflows/.running', 'cap', 0, 'a');
        self::assertFileExists($file, 'the capture file must exist where the layout says');
        self::assertSame($this->big, (string) file_get_contents($file), 'the file holds every byte, untruncated');

        $downstream = $this->prompts[1];
        self::assertStringContainsString('[output captured to ' . $file . ']', $downstream);
        self::assertStringContainsString(str_repeat('x', 2048), $downstream, 'the bounded preview rides along');
        self::assertStringContainsString('the full 5000 bytes are in the file', $downstream);
        self::assertLessThan(3000, strlen($downstream), 'the whole 5 KB dump must NOT reach the prompt');

        // The run's own records keep the FULL output — capture changes the
        // prompt envelope, never what the stage result or the pause record
        // store: serializeStageResult still carries the work product.
        self::assertSame($this->big, $run->stageResults[0]->output);
    }

    public function testAnUncapturedRunTouchesNothingAndDumpsTheSameBytesAsAlways(): void
    {
        $this->registry->register(
            (new WorkflowBuilder())
                ->name('plain')
                ->description('a then b, no capture')
                ->stage('a', Tasks::agent('coder')->prompt('big'))
                ->stage('b', Tasks::agent('coder')->prompt('use {{a.output}}'))
                ->build(),
        );

        $run = $this->engine->run('plain');
        self::assertSame(WorkflowStatus::Completed, $run->status);

        // No capture directory, ever, on an uncaptured run. (The .running
        // DIRECTORY may exist from the O1 record's atomic write — the
        // capture layout is the <id>.stages/ sibling, and that is what must
        // not appear.)
        self::assertDirectoryDoesNotExist($this->tempDir . '/workflows/.running/plain.stages');
        self::assertSame('use ' . $this->big, $this->prompts[1], 'the legacy full-dump interpolation, byte for byte');
        self::assertSame($this->big, $run->context['a.output']);
    }

    public function testAShortCapturedOutputIsWholeSoNoTruncationMarkIsLiedAbout(): void
    {
        $this->registry->register(
            (new WorkflowBuilder())
                ->name('small')
                ->description('tiny capture')
                ->stage('a', Tasks::agent('coder')->prompt('do a'), capture: true)
                ->stage('b', Tasks::agent('coder')->prompt('use {{a.output}}'))
                ->build(),
        );

        $this->engine->run('small');

        $file = WorkflowEngine::stageCapturePath($this->tempDir . '/workflows/.running', 'small', 0, 'a');
        self::assertSame(
            'use [output captured to ' . $file . "]\nOUT[do a]",
            $this->prompts[1],
            'a short capture is whole — no truncation mark is claimed about output that did not truncate',
        );
    }

    public function testCaptureFilesOutliveTheWithdrawnPauseRecord(): void
    {
        $this->registry->register(
            (new WorkflowBuilder())
                ->name('keep')
                ->description('capture survives the clean terminal')
                ->stage('a', Tasks::agent('coder')->prompt('do a'), capture: true)
                ->build(),
        );

        self::assertSame(WorkflowStatus::Completed, $this->engine->run('keep')->status);

        self::assertFileDoesNotExist($this->tempDir . '/workflows/.running/keep.json', 'the record withdraws as always');
        self::assertFileExists(
            WorkflowEngine::stageCapturePath($this->tempDir . '/workflows/.running', 'keep', 0, 'a'),
            'the captured work product stays readable for whoever the path was handed to',
        );
    }

    public function testTheYamlFlagIsCarriedAndRefusedNotCoerced(): void
    {
        $captured = (new WorkflowRegistry($this->tempDir . '/workflows'))->fromYamlString(
            "name: ycap\n"
            . "description: one\n"
            . "stages:\n"
            . "  - name: work\n"
            . "    agent: coder\n"
            . "    prompt: check\n"
            . "    capture: true\n",
            'ycap',
        );
        self::assertTrue($captured->stages[0]['capture']);

        $quiet = (new WorkflowRegistry($this->tempDir . '/workflows'))->fromYamlString(
            "name: yquiet\n"
            . "description: two\n"
            . "stages:\n"
            . "  - name: work\n"
            . "    agent: coder\n"
            . "    prompt: check\n"
            . "    capture: false\n",
            'yquiet',
        );
        self::assertArrayNotHasKey('capture', $quiet->stages[0], 'false means the legacy shape, exactly');

        $besideTasks = (new WorkflowRegistry($this->tempDir . '/workflows'))->fromYamlString(
            "name: ylist\n"
            . "description: three\n"
            . "stages:\n"
            . "  - name: work\n"
            . "    capture: true\n"
            . "    tasks:\n"
            . "      - agent: coder\n"
            . "        prompt: check\n",
            'ylist',
        );
        self::assertTrue($besideTasks->stages[0]['capture'], 'capture is a stage flag, legal beside tasks:');

        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessageMatches('/"capture" must be a boolean/');

        (new WorkflowRegistry($this->tempDir . '/workflows'))->fromYamlString(
            "name: ybad\n"
            . "description: four\n"
            . "stages:\n"
            . "  - name: work\n"
            . "    agent: coder\n"
            . "    prompt: check\n"
            . "    capture: 'yes'\n",
            'ybad',
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

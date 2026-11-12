<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use SugarCraft\Code\Agents\AgentResult;
use SugarCraft\Code\Agents\AgentStatus;
use SugarCraft\Code\Agents\AgentWorkerPool;
use SugarCraft\Code\Agents\ExecutorInterface;
use SugarCraft\Code\Agents\SubAgent;
use SugarCraft\Code\Workflows\UnsupportedStageTypeException;
use SugarCraft\Code\Workflows\Workflow;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowLinter;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use PHPUnit\Framework\TestCase;

/**
 * C1: the stage-type verdict list has ONE home. The engine walker, the linter
 * and the YAML parser all consumed to agree by coincidence; a fifth shape
 * added to only one of them would lint clean, parse clean and then die at
 * dispatch (or the inverse). These pins hold the seam closed.
 */
final class WorkflowStageTypeParityTest extends TestCase
{
    private string $tempDir;

    /** @var list<string> */
    private array $parityPrompts = [];

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . '/candy_code_wf_parity_' . \uniqid('', true);
        @mkdir($this->tempDir . '/workflows', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeParityDirectory($this->tempDir);
    }

    public function testTheSharedListCarriesExactlyTheFiveStageShapes(): void
    {
        self::assertSame(
            ['stage', 'parallel', 'pipeline', 'verification', 'map'],
            Workflow::STAGE_TYPES,
            'the order spells the engine refusal sentence; adding a shape means adding it here, nowhere else'
        );
    }

    public function testTheEngineAndTheLinterBothConsumeTheSharedList(): void
    {
        // Read each class' OWN file (reflection), not a hand-spelled path: the
        // pin must follow the source it guards even if the tree is rearranged.
        $engine = (string) file_get_contents((new \ReflectionClass(WorkflowEngine::class))->getFileName());
        $linter = (string) file_get_contents((new \ReflectionClass(WorkflowLinter::class))->getFileName());

        self::assertStringContainsString('in_array($stageType, Workflow::STAGE_TYPES, true)', $engine);
        self::assertStringContainsString('= Workflow::STAGE_TYPES;', $linter);
        // Neither surface may keep a hand-copied roster next to the shared
        // one. The needles are BRACKETED array-openings — the engine refusal
        // SENTENCE legitimately spells "'stage', 'parallel', ..." in prose, so
        // only a literal array re-appearing counts as a divergent roster.
        self::assertStringNotContainsString("['parallel', 'stage'", $engine);
        self::assertStringNotContainsString("['stage', 'parallel'", $engine);
        self::assertStringNotContainsString("['stage', 'parallel'", $linter);
        self::assertStringNotContainsString("['parallel', 'stage'", $linter);
    }

    public function testTheEngineRefusalSentenceIsBuiltFromTheSameSpellingsTheYamlTierAccepts(): void
    {
        $this->parityPrompts = [];
        $registry = new WorkflowRegistry($this->tempDir . '/workflows');
        $engine = new WorkflowEngine($registry, new AgentWorkerPool(1, $this->parityExecutor()));

        // A DTO with a shape NO tier knows reaches the walker's whitelist; its
        // refusal must name the very list the YAML tier validates against.
        $registry->register(new Workflow('bad-type', 'direct DTO', [['name' => 'x', 'type' => 'wat', 'tasks' => []]]));

        try {
            $engine->run('bad-type');
            self::fail('the walker must refuse an unsupported stage type');
        } catch (UnsupportedStageTypeException $error) {
            self::assertSame(
                "Stage type 'wat' is not supported. Only 'stage', 'parallel', 'pipeline', 'verification', and 'map' are implemented.",
                $error->getMessage()
            );
        }
        self::assertSame([], $this->parityPrompts, 'the refusal happens before any agent is dispatched');
    }

    public function testTheLinterFlagsTheSameBadShapeTheEngineRefuses(): void
    {
        $workflow = new Workflow('bad-type', 'direct DTO', [['name' => 'x', 'type' => 'wat', 'tasks' => []]]);
        $report = WorkflowLinter::lint($workflow);

        $errorMessages = array_values(array_map(
            static fn (array $finding): string => $finding['severity'] . ':' . $finding['message'],
            array_values(array_filter($report->findings, static fn (array $finding): bool => $finding['severity'] === 'error')),
        ));
        self::assertGreaterThanOrEqual(1, count($errorMessages), 'the lint must carry an error for the unsupported stage type');
        self::assertStringContainsString('wat', implode("\n", $errorMessages));
    }

    public function testTheVerifierVerdictTokensStayAtExactlyThreeRejectedInvalidEscalated(): void
    {
        // The scanVerdict taxonomy is the OTHER half of the parity: the YAML
        // reject: arm feeds this vocabulary, so a rename/addition here must be
        // a deliberate single-site decision. Pinned against the private consts.
        $reflected = new \ReflectionClass(WorkflowEngine::class);

        self::assertSame('rejected', $reflected->getConstant('VERDICT_REJECTED'));
        self::assertSame('invalid', $reflected->getConstant('VERDICT_INVALID'));
        self::assertSame('escalated', $reflected->getConstant('VERDICT_ESCALATED'));
    }

    /**
     * Records every dispatched task text into $parityPrompts and answers with
     * a canned success. Public because the anonymous ExecutorInterface below
     * reaches in — same shape as the pause-carry harnesses in this family.
     */
    public function dispatchParity(SubAgent $agent): AgentResult
    {
        $this->parityPrompts[] = $agent->task;

        return new AgentResult(
            agentId: $agent->id,
            status: AgentStatus::Completed,
            output: 'OUT[' . $agent->task . ']',
            tokensUsed: 10,
            costUsd: 0.5,
            startedAt: new \DateTimeImmutable(),
            completedAt: new \DateTimeImmutable(),
        );
    }

    private function parityExecutor(): ExecutorInterface
    {
        $test = $this;

        return new class($test) implements ExecutorInterface {
            public function __construct(
                private readonly WorkflowStageTypeParityTest $test,
            ) {}

            public function execute(SubAgent $agent, \SugarCraft\Code\Providers\CompleteRequest $request): AgentResult
            {
                return $this->test->dispatchParity($agent);
            }

            public function executeStream(SubAgent $agent, \SugarCraft\Code\Providers\CompleteRequest $request): \Generator
            {
                yield $this->execute($agent, $request);
            }

            public function cancel(string $agentId): void
            {
            }

            public function cancelAll(): void
            {
            }
        };
    }

    private function removeParityDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            if (is_dir($full)) {
                $this->removeParityDirectory($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }
}

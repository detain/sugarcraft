<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use InvalidArgumentException;
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
use SugarCraft\Code\Workflows\WorkflowTask;

/**
 * C7' Option A's YAML/PHP surface: the two new keys parse at the shared task
 * arm, refuse malformed shapes with the family's parser voice, and thread
 * onto every SubAgent the engine builds. The pool mechanism itself — the
 * re-armable idle deadline, the distinct expiry cause, the separate restart
 * budget — is pinned by tests/Agents/AgentWorkerPoolStallTest.php on the
 * real fork path; this file owns only the road from the workflow author's
 * page to the SubAgent that dispatch carries.
 */
final class WorkflowStallRestartTest extends TestCase
{
    private string $tempDir;

    /**
     * @var list<array{int, int}>
     */
    private array $captured = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/candy_code_wf_stall_' . uniqid('', true);
        \mkdir($this->tempDir . '/workflows', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testTheBuilderRefusesAStallWindowBelowOneSecond(): void
    {
        try {
            Tasks::agent('coder')->stallTimeout(0);
            self::fail('a zero-second idle bound is a bound that fires instantly, not no bound');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('stallTimeout must be at least 1 second, got 0', $error->getMessage());
        }
    }

    public function testTheBuilderRefusesANegativeRestartBudget(): void
    {
        try {
            Tasks::agent('coder')->restarts(-1);
            self::fail('a negative restart budget must not ride into the pool');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('restarts cannot be negative, got -1', $error->getMessage());
        }
    }

    /**
     * The stall pair parses through the same arms every other task key uses:
     * quoted whole numbers are ints by the time they reach the DTO, and a
     * task that spells neither key carries the inert shape (null / 0) that
     * leaves every existing byte on the wire untouched.
     */
    public function testTheStallPairParsesTypedAndDefaultsInert(): void
    {
        $workflow = $this->registry()->fromYamlString(
            "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: loud\n"
            . "    tasks:\n"
            . "      - agent: coder\n"
            . "        prompt: p\n"
            . '        stallTimeout: "45"' . "\n"
            . '        restarts: "2"' . "\n"
            . "  - name: quiet\n"
            . "    agent: coder\n"
            . "    prompt: q\n"
        );

        $spelled = $workflow->stages[0]['tasks'][0];
        self::assertInstanceOf(WorkflowTask::class, $spelled);
        self::assertSame(45, $spelled->stallTimeout, 'a quoted whole number must land typed');
        self::assertSame(2, $spelled->restarts, 'a quoted whole number must land typed');

        $untouched = $workflow->stages[1]['tasks'][0];
        self::assertInstanceOf(WorkflowTask::class, $untouched);
        self::assertNull($untouched->stallTimeout, 'an unstated idle bound stays unset, never invented');
        self::assertSame(0, $untouched->restarts, 'an unstated restart budget is zero, the inert default');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedStallShapes(): array
    {
        $body = static fn(string $line): string => "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: one\n"
            . "    agent: coder\n"
            . "    prompt: p\n"
            . "    {$line}\n";

        return [
            'a zero idle bound' => [
                $body('stallTimeout: 0'),
                'at least 1, got 0',
            ],
            'a fractional idle bound' => [
                $body('stallTimeout: 2.5'),
                'must be a whole number, got float',
            ],
            'a negative restart budget' => [
                $body('restarts: -1'),
                'at least 0, got -1',
            ],
            'a made-up restart count' => [
                $body('restarts: many'),
                'must be a whole number, got string',
            ],
        ];
    }

    /**
     * @dataProvider refusedStallShapes
     */
    public function testARefusedStallShapeDiesAtLoad(string $yaml, string $needle): void
    {
        try {
            $this->registry()->fromYamlString($yaml);
            self::fail("the stall shape '{$needle}' still loaded");
        } catch (WorkflowLoadException $error) {
            self::assertStringContainsString($needle, $error->getMessage());
        }
    }

    /**
     * End-to-end threading: a builder-spelled pair lands on the SubAgent the
     * engine constructs, in the same shape the YAML pair above parses to.
     * This pool runs inline (no forked executor), which is exactly why the
     * capture is safe here — the idle bound itself only arms on the fork
     * path, and the stall tests own that; this owns the thread.
     */
    public function testTheEngineThreadsTheStallPairOntoEverySubAgentItBuilds(): void
    {
        $registry = $this->registry();
        $registry->register(
            (new WorkflowBuilder())
                ->name('stalls')
                ->stage('work', Tasks::agent('coder')->prompt('p')->stallTimeout(90)->restarts(2))
                ->build()
        );

        $engine = new WorkflowEngine($registry, new AgentWorkerPool(1, $this->capturingStallExecutor()));
        $result = $engine->run('stalls');

        self::assertSame(WorkflowStatus::Completed, $result->status);
        self::assertSame([[90, 2]], $this->captured, 'the SubAgent left the engine without the stall pair');
    }

    private function capturingStallExecutor(): ExecutorInterface
    {
        return new class ($this) implements ExecutorInterface {
            public function __construct(private WorkflowStallRestartTest $test) {}

            public function execute(SubAgent $subAgent, CompleteRequest $request): AgentResult
            {
                return $this->test->recordStallCarry($subAgent);
            }

            public function executeStream(SubAgent $subAgent, CompleteRequest $request): \Generator
            {
                yield $this->execute($subAgent, $request);
            }

            public function cancel(string $agentId): void {}

            public function cancelAll(): void {}
        };
    }

    /**
     * @internal Consumed by the anonymous executor above.
     */
    public function recordStallCarry(SubAgent $subAgent): AgentResult
    {
        $this->captured[] = [$subAgent->stallTimeout, $subAgent->stallRestarts];

        return new AgentResult(
            agentId: $subAgent->id,
            status: AgentStatus::Completed,
            output: 'carried',
        );
    }

    private function registry(): WorkflowRegistry
    {
        return new WorkflowRegistry($this->tempDir . '/workflows');
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        foreach ((array) \scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (\is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            \unlink($path);
        }

        \rmdir($dir);
    }
}

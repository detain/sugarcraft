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
use SugarCraft\Code\Workflows\WorkflowLinter;
use SugarCraft\Code\Workflows\WorkflowLoadException;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowStatus;

/**
 * wf-impl P2-2 (engine half): the stage key `announce:` plus the engine's
 * mandatory notices (from.max truncation, minSuccess casualties, the
 * maxAgents ceiling), collected on WorkflowResult::$announcements and
 * streamed through the optional sink.
 */
final class WorkflowAnnouncementsTest extends TestCase
{
    private string $tempDir;
    private WorkflowRegistry $registry;

    /** @var list<string> */
    public array $prompts = [];

    /** @var array<string, string> prompt prefix => output */
    public array $outputs = [];

    /** @var list<string> prompt prefixes that fail */
    public array $failing = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/candy_code_wf_announce_' . uniqid('', true);
        mkdir($this->tempDir . '/workflows', 0755, true);
        $this->registry = new WorkflowRegistry($this->tempDir . '/workflows');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($this->tempDir);
    }

    /** @internal the fake executor's body */
    public function dispatch(SubAgent $agent): AgentResult
    {
        $this->prompts[] = $agent->task;
        foreach ($this->failing as $prefix) {
            if (str_starts_with($agent->task, $prefix)) {
                return new AgentResult(agentId: $agent->id, status: AgentStatus::Failed, error: new \RuntimeException('boom'), completedAt: new \DateTimeImmutable());
            }
        }
        $output = 'OUT[' . $agent->task . ']';
        foreach ($this->outputs as $prefix => $text) {
            if (str_starts_with($agent->task, $prefix)) {
                $output = $text;
            }
        }

        return new AgentResult(agentId: $agent->id, status: AgentStatus::Completed, output: $output, completedAt: new \DateTimeImmutable());
    }

    // ------------------------------------------------------------------ announce:

    public function testAnAnnounceTemplateIsInterpolatedAtSettleAndStreamedToTheSink(): void
    {
        $this->outputs['find'] = '3 leaks';
        $this->registry->register($this->registry->fromYamlString(
            "name: talk\nstages:\n"
            . "  - name: find\n    prompt: find\n    announce: 'Found {{find.output}}; next is fix'\n"
            . "  - name: fix\n    prompt: fix\n",
        ));

        $heard = [];
        $engine = $this->engine();
        $engine->setAnnouncementSink(static function (string $line, array $meta) use (&$heard): void {
            $heard[] = [$line, $meta];
        });
        $result = $engine->run('talk');

        self::assertTrue($result->isSuccess(), (string) $result->firstFailure()?->error);
        self::assertSame(['Found 3 leaks; next is fix'], $result->announcements);
        self::assertCount(1, $heard);
        self::assertSame('Found 3 leaks; next is fix', $heard[0][0]);
        self::assertSame('announce', $heard[0][1]['kind']);
        self::assertSame('find', $heard[0][1]['stage']);
        self::assertIsString($heard[0][1]['workflowId']);
    }

    public function testARunWithNothingToAnnounceCarriesAnEmptyList(): void
    {
        $result = $this->engine()->runWorkflow((new WorkflowBuilder())->name('q')->description('d')
            ->stage('a', Tasks::agent('x')->prompt('a'))
            ->build());

        self::assertTrue($result->isSuccess());
        self::assertSame([], $result->announcements);
    }

    public function testAThrowingSinkNeverBreaksTheRun(): void
    {
        $engine = $this->engine();
        $engine->setAnnouncementSink(static function (): void {
            throw new \RuntimeException('listener died');
        });

        $result = $engine->runWorkflow((new WorkflowBuilder())->name('q')->description('d')
            ->stage('a', Tasks::agent('x')->prompt('a'))->announce('done a')
            ->build());

        self::assertTrue($result->isSuccess());
        self::assertSame(['done a'], $result->announcements);
    }

    public function testBlankAnnouncesAreRefused(): void
    {
        try {
            $this->registry->fromYamlString("name: t\nstages:\n  - name: a\n    prompt: p\n    announce: '  '\n");
            self::fail('a blank announce loaded');
        } catch (WorkflowLoadException $e) {
            self::assertStringContainsString('"announce" must be a non-empty template string', $e->getMessage());
        }

        try {
            $this->registry->fromYamlString("name: t\nstages:\n  - name: a\n    prompt: p\n    announce: [x]\n");
            self::fail('a list announce loaded');
        } catch (WorkflowLoadException $e) {
            self::assertStringContainsString('"announce" must be a non-empty template string, got array', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        (new WorkflowBuilder())->announce('no stage yet');
    }

    public function testTheLinterRefusesABlankAnnounceOnAHandBuiltStage(): void
    {
        $workflow = (new WorkflowBuilder())->name('w')->description('d')->stage('a', Tasks::agent('x')->prompt('p'))->build();
        $stages = $workflow->stages;
        $stages[0]['announce'] = '';

        $lint = WorkflowLinter::lint($workflow->withStages($stages));

        self::assertStringContainsString('announce', $lint->renderFindings());
        self::assertGreaterThan(0, $lint->errorCount());
    }

    // ------------------------------------------------------------------ mandatory notices

    public function testAFromMaxTruncationIsAnnounced(): void
    {
        $this->outputs['find'] = '{"f":[{"id":"a"},{"id":"b"},{"id":"c"},{"id":"d"},{"id":"e"}]}';
        $result = $this->engine()->runWorkflow((new WorkflowBuilder())->name('cut')->description('d')
            ->stage('find', Tasks::agent('x')->prompt('find')->outputSchema(['type' => 'object']))
            ->parallel('v', [Tasks::agent('x')->prompt('check {{item.id}}')], from: ['stage' => 'find', 'pointer' => '/f', 'key' => '/id', 'max' => 2])
            ->build());

        self::assertTrue($result->isSuccess(), (string) $result->firstFailure()?->error);
        self::assertSame(["Stage 'v': from.max kept 2 of 5 items; 3 dropped (listed in the stage output)"], $result->announcements);
    }

    public function testMinSuccessCasualtiesAreAnnouncedMetOrNot(): void
    {
        $this->failing = ['two'];
        $result = $this->engine()->runWorkflow((new WorkflowBuilder())->name('ms')->description('d')
            ->parallel('fan', [
                Tasks::agent('x')->name('one')->prompt('one'),
                Tasks::agent('x')->name('two')->prompt('two'),
                Tasks::agent('x')->name('three')->prompt('three'),
            ])->minSuccess(2)
            ->build());

        self::assertTrue($result->isSuccess(), (string) $result->firstFailure()?->error);
        self::assertCount(1, $result->announcements);
        self::assertStringStartsWith("Stage 'fan': 1 of 3 agents failed, minSuccess 2 met; failed: two", $result->announcements[0]);
    }

    public function testTheMaxAgentsCeilingIsAnnouncedOnce(): void
    {
        $result = $this->engine()->runWorkflow((new WorkflowBuilder())->name('cap')->description('d')->maxAgents(1)
            ->stage('a', Tasks::agent('x')->prompt('a'))
            ->parallel('b', [Tasks::agent('x')->prompt('b1'), Tasks::agent('x')->prompt('b2')])
            ->build());

        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertSame(['a'], $this->prompts);
        self::assertSame(["Stage 'b': agent ceiling reached (1): 1 agent dispatched and a stage needed 2 more"], $result->announcements);
    }

    // ------------------------------------------------------------------ helpers

    private function engine(): WorkflowEngine
    {
        $test = $this;
        $executor = new class ($test) implements ExecutorInterface {
            public function __construct(private readonly WorkflowAnnouncementsTest $test)
            {
            }

            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                return $this->test->dispatch($agent);
            }

            public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
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

        return new WorkflowEngine($this->registry, new AgentWorkerPool(1, $executor));
    }
}

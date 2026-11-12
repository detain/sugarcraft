<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Events\SubAgentActivity;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tests\Workflows\WorkflowPlanRunRecordTest;
use SugarCraft\Code\Tools\BuiltIn\WorkflowTool;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowRegistry;

/**
 * wf-impl C5: the Workflow tool's resumable runs (P1-8), report-side capture
 * of every stage (P0-4), the `budget` input (P1-7), narration (P2-2) and the
 * session roster for `preset:` (P1-6).
 */
final class WorkflowToolResumeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-workflow-tool-resume-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/workflows', 0o700, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($this->dir);
    }

    public function testARunReturnsItsRunIdAndLeavesARecordTheWorkflowCommandCanRead(): void
    {
        $result = $this->tool($this->provider())->execute(['plan' => WorkflowPlanRunRecordTest::PLAN]);

        $this->assertFalse($result->isError(), $result->content());
        $document = WorkflowToolTest::document($result->content());
        $runId = $document['runId'];
        $this->assertMatchesRegularExpression('/\Aplan-' . substr(hash('sha256', WorkflowPlanRunRecordTest::PLAN), 0, 8) . '-1\z/', $runId);
        $this->assertStringContainsString("Run ID {$runId} (resumeFrom).", strtok($result->content(), "\n"));
        $this->assertFileExists($this->dir . '/workflows/.running/' . $runId . '.json');
        $this->assertFileExists($this->dir . '/workflows/.running/' . $runId . '.plan');

        // The same record `/workflow status <runId>` reads through a registry engine.
        $engine = new WorkflowEngine(new WorkflowRegistry($this->dir . '/workflows'));
        $this->assertSame('completed', $engine->status($runId)->value);
    }

    public function testResumeFromWithAnEditedPlanCarriesTheUnchangedPrefixOnly(): void
    {
        $provider = $this->provider();
        $tool = $this->tool($provider);
        $runId = WorkflowToolTest::document($tool->execute(['plan' => WorkflowPlanRunRecordTest::PLAN])->content())['runId'];
        $this->assertCount(3, $provider->requests);

        $edited = str_replace('do c', 'do C better', WorkflowPlanRunRecordTest::PLAN);
        $result = $tool->execute(['plan' => $edited, 'resumeFrom' => $runId]);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertCount(4, $provider->requests, 'only the edited last stage ran');
        $document = WorkflowToolTest::document($result->content());
        $this->assertSame($runId, $document['runId'], 'the run keeps its ID');
        $this->assertSame(2, $document['carried']);
        $this->assertTrue($document['stages'][0]['carried']);
        $this->assertTrue($document['stages'][1]['carried']);
        $this->assertArrayNotHasKey('carried', $document['stages'][2]);
        $this->assertStringContainsString('2 stages carried from the record at no cost.', $result->content());

        $same = $tool->execute(['plan' => $edited, 'resumeFrom' => $runId]);
        $this->assertCount(4, $provider->requests, 'same plan + same context: everything is carried');
        $this->assertSame(3, WorkflowToolTest::document($same->content())['carried']);
    }

    public function testAnUnknownOrForeignRunIdIsAnErrorWithNothingDispatched(): void
    {
        $provider = $this->provider();
        foreach (['plan-00000000-1', 'deploy-1a2b3c4d'] as $id) {
            $result = $this->tool($provider)->execute(['plan' => WorkflowPlanRunRecordTest::PLAN, 'resumeFrom' => $id]);
            $this->assertTrue($result->isError());
            $this->assertStringStartsWith('Error: the plan was not resumed: ', $result->content());
        }
        $this->assertSame([], $provider->requests);

        $bad = $this->tool($provider)->execute(['plan' => WorkflowPlanRunRecordTest::PLAN, 'resumeFrom' => 7]);
        $this->assertSame('Error: "resumeFrom" must be a runId string an earlier Workflow call returned.', $bad->content());
    }

    public function testEveryStageIsCapturedWithoutChangingWhatLaterStagesRead(): void
    {
        $provider = $this->provider();
        $result = $this->tool($provider)->execute(['plan' => WorkflowPlanRunRecordTest::PLAN]);
        $document = WorkflowToolTest::document($result->content());

        foreach ($document['stages'] as $index => $row) {
            $this->assertArrayHasKey('capture', $row);
            $this->assertStringEndsWith(sprintf('.stages/%d-%s.txt', $index, $row['name']), $row['capture']);
            $this->assertSame($row['output'], file_get_contents($row['capture']));
        }
        // Stage b's prompt got a's full text, not a capture envelope.
        $this->assertStringContainsString('do b after answer 1', self::lastUserText($provider->requests[1]));
    }

    public function testTheBudgetInputCapsTheRunAndTheHeadlineReportsSpendAgainstIt(): void
    {
        $provider = $this->provider(tokens: 100);
        $tool = $this->tool($provider);

        $paused = $tool->execute(['plan' => WorkflowPlanRunRecordTest::PLAN, 'budget' => 150]);
        $document = WorkflowToolTest::document($paused->content());
        $this->assertSame('paused', $document['status']);
        $this->assertSame(['tokens' => 150, 'spent' => 200], $document['budget']);
        $this->assertStringContainsString('200 tokens of a 150-token budget', $paused->content());
        $this->assertStringContainsString('The run is paused; call again with resumeFrom: ' . $document['runId'], $paused->content());
        $this->assertCount(2, $provider->requests);
        $this->assertNotSame([], $document['announcements'], 'a budget stop always announces');

        $resumed = $tool->execute(['plan' => WorkflowPlanRunRecordTest::PLAN, 'resumeFrom' => $document['runId'], 'budget' => 1000]);
        $this->assertSame('completed', WorkflowToolTest::document($resumed->content())['status']);
        $this->assertCount(3, $provider->requests, 'only the stage the budget stopped ran');

        // The tighter of budget and config.maxTokens wins.
        $plan = "config:\n  maxTokens: 120\n" . WorkflowPlanRunRecordTest::PLAN;
        $tight = WorkflowToolTest::document($tool->execute(['plan' => $plan, 'budget' => 5000])->content());
        $this->assertSame(120, $tight['budget']['tokens']);

        foreach ([0, -5, '100', 1.5] as $bad) {
            $refused = $tool->execute(['plan' => WorkflowPlanRunRecordTest::PLAN, 'budget' => $bad]);
            $this->assertSame('Error: "budget" must be a whole number of tokens, at least 1.', $refused->content());
        }
    }

    public function testAnnouncementsStreamOnTheLiveLineAndRideTheResult(): void
    {
        $beats = [];
        $plan = "name: talk\nstages:\n  - name: a\n    prompt: do a\n    announce: 'a said {{a.output}}'\n";
        $result = $this->tool($this->provider(), static function (SubAgentActivity $beat) use (&$beats): void {
            $beats[] = $beat;
        })->execute(['id' => 'call-7', 'plan' => $plan]);

        $document = WorkflowToolTest::document($result->content());
        $this->assertSame(['a said answer 1'], $document['announcements']);

        $this->assertSame([SubAgentActivity::OP_STARTED, SubAgentActivity::OP_PROGRESS, SubAgentActivity::OP_FINISHED], array_map(static fn (SubAgentActivity $b): string => $b->op, $beats));
        $this->assertSame([1, 2, 3], array_map(static fn (SubAgentActivity $b): int => $b->seq, $beats));
        $this->assertSame('call-7', $beats[0]->parentCallId);
        $this->assertSame('a said answer 1', $beats[1]->items[0]->delta);
        $this->assertSame(SubAgentActivity::OUTCOME_COMPLETE, $beats[2]->outcome);
        $this->assertCount(1, array_unique(array_map(static fn (SubAgentActivity $b): string => $b->id, $beats)));
    }

    public function testPresetsResolveAgainstTheSessionRosterAndLintNamesTheKnownOnes(): void
    {
        $provider = $this->provider();
        $roster = static fn (): array => [new Agent('reviewer', 'reviews', 'You review.', 'm', 'p', [], [], [], true)];

        $ok = $this->tool($provider)->withAgentRoster($roster)->execute(['plan' => "stages:\n  - name: a\n    prompt: x\n    preset: reviewer\n"]);
        $this->assertFalse($ok->isError(), $ok->content());

        $unknown = $this->tool($provider)->withAgentRoster($roster)->execute(['plan' => "stages:\n  - name: a\n    prompt: x\n    preset: ghost\n"]);
        $this->assertTrue($unknown->isError());
        $this->assertStringContainsString('lint found 1 error', $unknown->content());
        $this->assertStringContainsString('preset "ghost" is not an agent preset this session knows (known: reviewer)', $unknown->content());
    }

    /** @param ?\Closure(SubAgentActivity): void $emitter */
    private function tool(ScriptedProvider $provider, ?\Closure $emitter = null): WorkflowTool
    {
        return (new WorkflowTool($this->dir))
            ->withWorkflowsPath($this->dir . '/workflows')
            ->withEngine(EngineBackend::new($provider, 'm'), null, $emitter);
    }

    /** Answers `answer N` for the Nth request, spending $tokens each. */
    private function provider(int $tokens = 0): ScriptedProvider
    {
        $count = 0;

        return new ScriptedProvider([static function (CompleteRequest $request) use (&$count, $tokens): CompleteResponse {
            $count++;

            return new CompleteResponse(content: 'answer ' . $count, tokensUsed: $tokens);
        }]);
    }

    private static function lastUserText(CompleteRequest $request): string
    {
        return print_r($request->messages, true);
    }
}

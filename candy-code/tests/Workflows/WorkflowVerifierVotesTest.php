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
use SugarCraft\Code\Workflows\TaskBuilder;
use SugarCraft\Code\Workflows\Tasks;
use SugarCraft\Code\Workflows\WorkflowBuilder;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowLinter;
use SugarCraft\Code\Workflows\WorkflowLoadException;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowStatus;

/**
 * P1-4: a verification stage's verifier can be a PANEL — `votes: N`
 * independent dispatches (optionally one `lenses:` entry each, read as
 * `{{lens}}`) judged by `quorum: Q` votes that do not reject. ESCALATE in any
 * vote outranks the quorum, `retries` re-runs the whole panel, and
 * `{{<stage>.votes}}` exposes every vote.
 */
final class WorkflowVerifierVotesTest extends TestCase
{
    private string $tempDir;
    private WorkflowRegistry $registry;

    /** @var list<string> */
    public array $prompts = [];

    /** @var \Closure(SubAgent): AgentResult */
    public \Closure $answer;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/candy_code_wf_votes_' . uniqid('', true);
        mkdir($this->tempDir . '/workflows', 0755, true);
        $this->registry = new WorkflowRegistry($this->tempDir . '/workflows');
        $this->answer = fn (SubAgent $a): AgentResult => $this->ok($a, 'OUT[' . $a->task . ']');
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->tempDir);
    }

    // ------------------------------------------------------------------ grammar

    public function testTheYamlPanelKeysReachTheVerifierDto(): void
    {
        $workflow = $this->registry->fromYamlString(
            "name: v\nstages:\n  - name: check\n    type: verification\n    task: {prompt: do}\n"
            . "    verifier: {prompt: 'judge via {{lens}}', reject: '/REJECT/', votes: 3, lenses: [correctness, security, ' repro '], quorum: '2'}\n"
        );

        $verifier = $workflow->stages[0]['verifier'];
        self::assertSame(3, $verifier->votes);
        self::assertSame(['correctness', 'security', 'repro'], $verifier->lenses);
        self::assertSame(2, $verifier->quorum);
        self::assertTrue($verifier->declaresPanel());
        self::assertFalse($workflow->stages[0]['task']->declaresPanel());
    }

    public function testAnUndeclaredVerifierKeepsTheDefaults(): void
    {
        $task = Tasks::agent('a')->prompt('p')->build();

        self::assertSame(1, $task->votes);
        self::assertNull($task->lenses);
        self::assertNull($task->quorum);
        self::assertFalse($task->declaresPanel());
        self::assertSame(1, $task->effectiveQuorum());
    }

    public function testTheDefaultQuorumIsTheMajority(): void
    {
        self::assertSame(1, TaskBuilder::defaultQuorum(1));
        self::assertSame(2, TaskBuilder::defaultQuorum(2));
        self::assertSame(2, TaskBuilder::defaultQuorum(3));
        self::assertSame(3, TaskBuilder::defaultQuorum(4));
        self::assertSame(3, TaskBuilder::defaultQuorum(5));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedPanels(): iterable
    {
        yield 'zero votes' => ['{prompt: j, votes: 0}', '"votes" must be at least 1, got 0'];
        yield 'float votes' => ['{prompt: j, votes: 1.5}', '"votes" must be a whole number, got float'];
        yield 'lenses not a list' => ['{prompt: j, votes: 1, lenses: security}', '"lenses" must be a list of lens names, one per vote, got string'];
        yield 'empty lenses' => ['{prompt: j, lenses: []}', 'lenses must be a non-empty list of lens names, one per vote, got an empty list'];
        yield 'a blank lens' => ["{prompt: j, votes: 2, lenses: [a, '  ']}", 'lenses entry #2 must be a non-empty string, got an empty string'];
        yield 'a non-string lens' => ['{prompt: j, votes: 2, lenses: [a, 3]}', 'lenses entry #2 must be a non-empty string, got int'];
        yield 'lens count mismatch' => ['{prompt: j, votes: 3, lenses: [a, b]}', 'lenses lists 2 lenses but votes is 3; give exactly one lens per vote'];
        yield 'lenses without votes' => ['{prompt: j, lenses: [a, b]}', 'lenses lists 2 lenses but votes is 1'];
        yield 'quorum above votes' => ['{prompt: j, votes: 3, quorum: 4}', 'quorum 4 exceeds votes 3; at most 3 votes can pass'];
        yield 'zero quorum' => ['{prompt: j, votes: 3, quorum: 0}', '"quorum" must be at least 1, got 0'];
    }

    /**
     * @dataProvider refusedPanels
     */
    public function testAMalformedPanelDiesAtLoad(string $verifier, string $needle): void
    {
        try {
            $this->registry->fromYamlString(
                "name: v\nstages:\n  - name: check\n    type: verification\n    task: {prompt: do}\n    verifier: {$verifier}\n"
            );
            self::fail("'{$needle}' still loaded");
        } catch (WorkflowLoadException $e) {
            self::assertStringContainsString("stage 'check' \"verifier\"", $e->getMessage());
            self::assertStringContainsString($needle, $e->getMessage());
        }
    }

    public function testTheBuilderHoldsThePanelLaw(): void
    {
        $task = Tasks::agent('v')->prompt('p')->votes(3)->lenses(['a', 'b', 'c'])->quorum(3)->build();
        self::assertSame(['a', 'b', 'c'], $task->lenses);
        self::assertSame(3, $task->effectiveQuorum());

        foreach ([
            static fn () => Tasks::agent('v')->votes(0),
            static fn () => Tasks::agent('v')->quorum(0),
            static fn () => Tasks::agent('v')->lenses([]),
            static fn () => Tasks::agent('v')->lenses(['ok', '']),
            static fn () => Tasks::agent('v')->votes(2)->lenses(['a'])->build(),
            static fn () => Tasks::agent('v')->votes(2)->quorum(3)->build(),
        ] as $i => $attempt) {
            try {
                $attempt();
                self::fail("attempt #{$i} was accepted");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAPanelKeyOffTheVerifierSlotRefusesTheRunBeforeAnyDispatch(): void
    {
        foreach ([
            "  - name: plain\n    prompt: p\n    votes: 3\n",
            "  - name: check\n    type: verification\n    task: {prompt: do, quorum: 1}\n    verifier: {prompt: j}\n",
            "  - name: fan\n    parallel: true\n    agents:\n      - {type: a, prompt: p, lenses: [x]}\n",
        ] as $stage) {
            $this->prompts = [];
            $result = $this->engine()->runWorkflow($this->registry->fromYamlString("name: v\nstages:\n" . $stage));

            self::assertSame(WorkflowStatus::Failed, $result->status);
            self::assertStringContainsString(
                'votes, lenses and quorum convene a verifier panel and belong on the verifier slot of a verification stage',
                (string) $result->firstFailure()?->error,
            );
            self::assertSame([], $this->prompts, 'nothing was dispatched');
        }
    }

    // ------------------------------------------------------------------ run time

    public function testLensesInterpolatePerVoteAndAMetQuorumPasses(): void
    {
        $this->answer = fn (SubAgent $a): AgentResult => str_contains($a->task, 'security')
            ? $this->ok($a, "looked hard\nREJECT: injection in line 4")
            : $this->ok($a, str_starts_with($a->task, 'judge') ? 'PASS ' . $a->task : 'the fix');

        $result = $this->runPanel(
            "    verifier: {prompt: 'judge {{prevResult}} via {{lens}}', reject: '/REJECT/', votes: 3, lenses: [correctness, security, repro], quorum: 2}\n"
            . "  - name: report\n    prompt: 'votes {{check.votes}}'\n",
        );

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        $judged = array_values(array_filter($this->prompts, static fn (string $p): bool => str_starts_with($p, 'judge')));
        sort($judged);
        self::assertSame(['judge the fix via correctness', 'judge the fix via repro', 'judge the fix via security'], $judged);

        $check = $result->stageResults[0];
        self::assertStringStartsWith("the fix\n", (string) $check->output);
        self::assertCount(4, $check->agents, 'the task plus three votes are billed');

        $votes = json_decode((string) $result->context['check.votes'], true);
        self::assertSame([
            ['lens' => 'correctness', 'rejected' => false, 'output' => 'PASS judge the fix via correctness'],
            ['lens' => 'security', 'rejected' => true, 'output' => "looked hard\nREJECT: injection in line 4"],
            ['lens' => 'repro', 'rejected' => false, 'output' => 'PASS judge the fix via repro'],
        ], $votes);
        self::assertSame('votes ' . $result->context['check.votes'], end($this->prompts), '{{check.votes}} interpolates the JSON');
    }

    public function testAMissedQuorumFailsQuotingEveryRejectingVote(): void
    {
        $this->answer = fn (SubAgent $a): AgentResult => match (true) {
            str_contains($a->task, 'via a') => $this->ok($a, 'REJECT: a says no'),
            str_contains($a->task, 'via b') => $this->ok($a, "fine\nREJECT: b says no"),
            default => $this->ok($a, str_starts_with($a->task, 'judge') ? 'ok' : 'work'),
        };

        $result = $this->runPanel("    verifier: {prompt: 'judge via {{lens}}', reject: '/REJECT/', votes: 3, lenses: [a, b, c]}\n");

        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertSame(
            'verdict rejected by the panel: 1 of 3 votes passed and the quorum is 2; '
            . 'vote 1 (a) matched /REJECT/ on: "REJECT: a says no"; vote 2 (b) matched /REJECT/ on: "REJECT: b says no"',
            $result->firstFailure()?->error,
        );
        self::assertSame(1, $this->countPrefix('do'), 'the task ran once');
    }

    public function testAVoteThatFailsMechanicallyCountsAsARejection(): void
    {
        $this->answer = fn (SubAgent $a): AgentResult => str_contains($a->task, 'vote two')
            ? new AgentResult(agentId: $a->id, status: AgentStatus::TimedOut, error: new \RuntimeException('too slow'), completedAt: new \DateTimeImmutable())
            : $this->ok($a, 'fine');

        $result = $this->runPanel("    verifier: {prompt: 'judge {{lens}}', votes: 2, lenses: ['vote one', 'vote two']}\n");

        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertSame(
            'verdict rejected by the panel: 1 of 2 votes passed and the quorum is 2; vote 2 (vote two) timed out: too slow',
            $result->firstFailure()?->error,
        );
    }

    public function testAnExplicitQuorumBelowTheMajorityPasses(): void
    {
        $this->answer = fn (SubAgent $a): AgentResult => str_contains($a->task, 'judge') && !str_contains($a->task, '#1')
            ? $this->ok($a, 'REJECT')
            : $this->ok($a, 'ok');

        // Without lenses {{lens}} stays verbatim; the votes are identical
        // refuters told apart here only by the counter in the script.
        $counter = 0;
        $base = $this->answer;
        $this->answer = function (SubAgent $a) use (&$counter, $base): AgentResult {
            if (str_starts_with($a->task, 'judge')) {
                $counter++;
                self::assertSame('judge {{lens}}', $a->task);

                return $this->ok($a, $counter === 1 ? 'ok' : 'REJECT');
            }

            return $base($a);
        };

        $result = $this->runPanel("    verifier: {prompt: 'judge {{lens}}', reject: '/REJECT/', votes: 3, quorum: 1}\n");

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame(3, $counter);
        $votes = json_decode((string) $result->context['check.votes'], true);
        self::assertSame([null, null, null], array_column($votes, 'lens'));
    }

    public function testEscalationInAnyVoteOutranksAMetQuorumAndIsNeverRetried(): void
    {
        $this->answer = fn (SubAgent $a): AgentResult => str_contains($a->task, 'via b')
            ? $this->ok($a, "mostly fine\nESCALATE: is the API meant to be public?")
            : $this->ok($a, 'ok');

        $result = $this->runPanel("    verifier: {prompt: 'judge via {{lens}}', reject: '/REJECT/', votes: 3, lenses: [a, b, c], retries: 2}\n");

        self::assertSame(WorkflowStatus::Failed, $result->status);
        $error = (string) $result->firstFailure()?->error;
        self::assertStringStartsWith(WorkflowEngine::ESCALATION_MARKER . 'verifier vote 2 (b) asked for a human decision on: "ESCALATE: is the API meant to be public?"', $error);
        self::assertSame(3, $this->countPrefix('judge'), 'one panel round only: an escalation burns no retry');
        self::assertStringContainsString('ESCALATE: is the API', (string) $result->stageResults[0]->output, 'the question survives in the output');
    }

    public function testRetriesReRunTheWholePanelAfterAMissedQuorum(): void
    {
        $round = 0;
        $this->answer = function (SubAgent $a) use (&$round): AgentResult {
            if (!str_starts_with($a->task, 'judge')) {
                return $this->ok($a, 'work');
            }
            $round++;

            return $this->ok($a, $round <= 2 ? 'REJECT' : 'ok');
        };

        $result = $this->runPanel("    verifier: {prompt: 'judge', reject: '/REJECT/', votes: 2, retries: 1}\n");

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame(4, $this->countPrefix('judge'), 'two full panel rounds');
        self::assertSame(1, $this->countPrefix('do'), 'the task is not re-run');
        self::assertCount(5, $result->stageResults[0]->agents, 'every vote of every round is billed');
    }

    public function testExhaustedRetriesSayHowManyPanelRoundsFailed(): void
    {
        $this->answer = fn (SubAgent $a): AgentResult => $this->ok($a, str_starts_with($a->task, 'judge') ? 'REJECT' : 'work');

        $result = $this->runPanel("    verifier: {prompt: 'judge', reject: '/REJECT/', votes: 2, retries: 1}\n");

        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertStringEndsWith('[failed on all 2 panel rounds]', (string) $result->firstFailure()?->error);
        self::assertSame(4, $this->countPrefix('judge'));
    }

    public function testASchemaVerifierRecordsEachVotesDocument(): void
    {
        $this->answer = fn (SubAgent $a): AgentResult => str_starts_with($a->task, 'judge')
            ? $this->ok($a, str_contains($a->task, 'via x') ? '{"verdict":"REJECT"}' : '{"verdict":"PASS"}')
            : $this->ok($a, 'work');

        $result = $this->runPanel("    verifier: {prompt: 'judge via {{lens}}', reject: '/REJECT/', votes: 2, lenses: [x, y], quorum: 1, outputSchema: {type: object}}\n");

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame([
            ['lens' => 'x', 'rejected' => true, 'data' => ['verdict' => 'REJECT']],
            ['lens' => 'y', 'rejected' => false, 'data' => ['verdict' => 'PASS']],
        ], json_decode((string) $result->context['check.votes'], true));
        self::assertSame('{"verdict":"PASS"}', $result->context['@results']['check_verifier_2']);
    }

    public function testEveryVoteCountsAgainstMaxAgents(): void
    {
        $workflow = $this->registry->fromYamlString(
            "name: v\nconfig: {maxAgents: 3}\nstages:\n  - name: check\n    type: verification\n    task: {prompt: do}\n"
            . "    verifier: {prompt: judge, votes: 3}\n"
        );

        $result = $this->engine()->runWorkflow($workflow);

        self::assertSame(WorkflowStatus::Failed, $result->status);
        self::assertStringContainsString('agent ceiling reached (3): this run has dispatched 1 agent and this stage needs 3 more', (string) $result->firstFailure()?->error);
        self::assertSame(['do'], $this->prompts, 'the panel wave forks nothing past the ceiling');
    }

    public function testASingleVoteVerifierStaysOnTheLegacyPath(): void
    {
        $result = $this->runPanel("    verifier: {prompt: 'judge {{prevResult}}'}\n");

        self::assertSame(WorkflowStatus::Completed, $result->status);
        self::assertArrayNotHasKey('check.votes', $result->context);
        self::assertSame('OUT[judge OUT[do]]', $result->context['@results']['check_verifier']);
    }

    // ------------------------------------------------------------------ lint

    public function testTheLinterMirrorsThePanelLaw(): void
    {
        $workflow = (new WorkflowBuilder())->name('l')
            ->stage('plain', Tasks::agent('a')->prompt('use {{lens}}')->votes(2))
            ->withVerification('check', Tasks::agent('a')->prompt('do'), Tasks::agent('v')->prompt('judge {{lens}}')->votes(3))
            ->withVerification('lensed', Tasks::agent('a')->prompt('do'), Tasks::agent('v')->prompt('judge {{lens}}')->votes(2)->lenses(['x', 'y'])->reject('/NO/'))
            ->build();

        $messages = array_map(
            static fn (array $f): string => $f['severity'] . ' ' . $f['stage'] . ' ' . $f['message'],
            WorkflowLinter::lint($workflow)->findings,
        );

        self::assertContains('error plain votes, lenses and quorum convene a verifier panel and belong on the verifier slot of a verification stage — the engine refuses the run on this plan.', $messages);
        self::assertContains('warning plain {{lens}} is interpolated only inside a verifier panel that declares lenses; here it stays verbatim in the prompt.', $messages);
        self::assertContains('warning check/verifier {{lens}} is interpolated only inside a verifier panel that declares lenses; here it stays verbatim in the prompt.', $messages);
        self::assertContains("warning check/verifier This verifier panel has no reject pattern, so only a vote that fails or times out counts against the quorum; add reject to make a vote's words a verdict.", $messages);
        foreach ($messages as $message) {
            self::assertStringNotContainsString('lensed', $message, 'a lensed panel with a reject pattern lints clean');
        }
    }

    // ------------------------------------------------------------------ helpers

    private function runPanel(string $verifierAndRest): \SugarCraft\Code\Workflows\WorkflowResult
    {
        $workflow = $this->registry->fromYamlString(
            "name: v\nstages:\n  - name: check\n    type: verification\n    task: {prompt: do}\n" . $verifierAndRest
        );

        return $this->engine()->runWorkflow($workflow);
    }

    private function countPrefix(string $prefix): int
    {
        return count(array_filter($this->prompts, static fn (string $p): bool => str_starts_with($p, $prefix)));
    }

    public function dispatch(SubAgent $agent): AgentResult
    {
        $this->prompts[] = $agent->task;

        return ($this->answer)($agent);
    }

    private function ok(SubAgent $agent, string $output): AgentResult
    {
        return new AgentResult(
            agentId: $agent->id,
            status: AgentStatus::Completed,
            output: $output,
            tokensUsed: 1,
            costUsd: 0.01,
            startedAt: new \DateTimeImmutable(),
            completedAt: new \DateTimeImmutable(),
        );
    }

    private function engine(): WorkflowEngine
    {
        $executor = new class ($this) implements ExecutorInterface {
            public function __construct(private readonly WorkflowVerifierVotesTest $test)
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

        return new WorkflowEngine($this->registry, new AgentWorkerPool(1, $executor));
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

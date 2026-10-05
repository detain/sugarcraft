<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Live\AgentInbox;
use SugarCraft\Crush\Agents\Live\MessageMode;
use SugarCraft\Crush\Agents\Live\SubAgentTranscriptLog;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Protocol\ErrorCode;
use SugarCraft\Crush\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Crush\Tests\Server\Support\WireClient;
use SugarCraft\Crush\Tools\ToolResult as EngineToolResult;
use SugarCraft\Crush\Workflows\WorkflowEngineInterface;
use SugarCraft\Crush\Workflows\WorkflowResult;
use SugarCraft\Crush\Workflows\WorkflowStatus;

/**
 * Roadmap O-6c: the `agents.*` and `workflow.*` methods a browser's
 * sub-agent tree, Agent View and workflow panel are built on — the tree folded
 * from the session's beats (live and logged), a run's own transcript read in
 * pages, messages and controls into a running run's mailbox, the refusals for
 * a finished one, and `/workflow` over the wire with the runs the transcript
 * holds.
 */
final class AgentsWorkflowMethodsTest extends TestCase
{
    private ProtocolFixture $fixture;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $workflowCalls = [];

    protected function setUp(): void
    {
        $this->fixture = ProtocolFixture::new(workflows: $this->engine());
    }

    protected function tearDown(): void
    {
        $this->fixture->tearDown();
    }

    public function testTheSubtreeFoldsEveryBeatOfARunAndKeepsWhatOnlyStartedSays(): void
    {
        [$client, $sessionId] = $this->turn();
        $this->fixture->backend->emit(self::beat(SubAgentActivity::OP_STARTED, 'run-1', 1, task: 'review the parser', description: 'review'));
        $this->fixture->backend->emit(self::beat(SubAgentActivity::OP_PROGRESS, 'run-1', 2, tail: 'reading Parser.php'));
        $this->fixture->run(0.08);

        $items = $client->call('agents.subtree', ['sessionId' => $sessionId])['items'];
        self::assertCount(1, $items);
        self::assertSame('run-1', $items[0]['id']);
        self::assertSame(SubAgentActivity::OP_PROGRESS, $items[0]['op'], 'the newest beat');
        self::assertSame('review the parser', $items[0]['task'], 'the task survives a beat that leaves it empty');
        self::assertSame('review', $items[0]['description']);
        self::assertSame('c1', $items[0]['parentCallId']);

        $this->fixture->backend->emit(self::beat(SubAgentActivity::OP_FINISHED, 'run-1', 3, outcome: SubAgentActivity::OUTCOME_COMPLETE));
        $this->fixture->run(0.08);
        $this->fixture->backend->settle(Message::assistant('done'));
        $this->fixture->run();

        // A feed attached later (a restarted server) has heard nothing: the
        // durable started/finished pair in the session's log still names it.
        $this->fixture->context->dropFeed($sessionId);
        $items = $client->call('agents.subtree', ['sessionId' => $sessionId])['items'];
        self::assertCount(1, $items);
        self::assertSame(SubAgentActivity::OP_FINISHED, $items[0]['op']);
        self::assertSame('review the parser', $items[0]['task']);
        self::assertSame(SubAgentActivity::OUTCOME_COMPLETE, $items[0]['outcome']);
    }

    public function testAMessageAndAControlReachARunningRunsMailbox(): void
    {
        [$client, $sessionId] = $this->turn();
        $this->fixture->backend->emit(self::beat(SubAgentActivity::OP_STARTED, 'run-2', 1, task: 'fix it'));
        $this->fixture->run(0.08);

        $sent = $client->call('agents.message', ['sessionId' => $sessionId, 'agentId' => 'run-2', 'text' => 'also check the tests']);
        self::assertSame('run-2', $sent['agentId']);
        self::assertSame('queued', $sent['status']);
        self::assertIsString($sent['msgId']);

        $paused = $client->call('agents.control', ['sessionId' => $sessionId, 'agentId' => 'run-2', 'verb' => 'pause']);
        self::assertSame('queued', $paused['status']);

        $inbox = $this->fixture->hub->workspace()->agentInbox($sessionId);
        self::assertInstanceOf(AgentInbox::class, $inbox);
        $controls = $inbox->takeControls('run-2');
        self::assertSame(['pause'], \array_map(static fn ($m): string => $m->text, $controls));
        $delivered = $inbox->drain('run-2');
        self::assertCount(1, $delivered);
        self::assertSame('also check the tests', $delivered[0]->text);
        self::assertTrue($delivered[0]->isFromUser(), 'signed as the user with the launch key');
        self::assertSame(MessageMode::Steer, $delivered[0]->mode);
    }

    public function testTalkingToARunThatCannotHearIsRefusedWithAReason(): void
    {
        [$client, $sessionId] = $this->turn();
        $this->fixture->backend->emit(self::beat(SubAgentActivity::OP_STARTED, 'run-3', 1, task: 't'));
        $this->fixture->backend->emit(self::beat(SubAgentActivity::OP_FINISHED, 'run-3', 2, outcome: SubAgentActivity::OUTCOME_COMPLETE));
        $this->fixture->backend->emit(self::beat(SubAgentActivity::OP_STARTED, 'run-4', 1, task: 't'));
        $this->fixture->backend->emit(self::beat(SubAgentActivity::OP_FINISHED, 'run-4', 2, outcome: SubAgentActivity::OUTCOME_COMPLETE, resumeId: 'res-4'));
        $this->fixture->run(0.08);

        $unknown = $client->request('agents.message', ['sessionId' => $sessionId, 'agentId' => 'nobody', 'text' => 'hi']);
        self::assertSame(ErrorCode::NotFound->value, $unknown['error']['code']);
        self::assertSame('agent_not_found', $unknown['error']['data']['kind']);

        $noResume = $client->request('agents.message', ['sessionId' => $sessionId, 'agentId' => 'run-3', 'text' => 'more']);
        self::assertSame('not_resumable', $noResume['error']['data']['kind']);

        $noEngine = $client->request('agents.message', ['sessionId' => $sessionId, 'agentId' => 'run-4', 'text' => 'more']);
        self::assertSame(ErrorCode::UnsupportedInServer->value, $noEngine['error']['code']);
        self::assertSame('resume_unavailable', $noEngine['error']['data']['kind']);

        $finished = $client->request('agents.control', ['sessionId' => $sessionId, 'agentId' => 'run-3', 'verb' => 'cancel']);
        self::assertSame('agent_finished', $finished['error']['data']['kind']);

        $badId = $client->request('agents.message', ['sessionId' => $sessionId, 'agentId' => '../etc', 'text' => 'x']);
        self::assertSame(ErrorCode::InvalidParams->value, $badId['error']['code']);
        $badVerb = $client->request('agents.control', ['sessionId' => $sessionId, 'agentId' => 'run-4', 'verb' => 'explode']);
        self::assertSame(ErrorCode::InvalidParams->value, $badVerb['error']['code']);
    }

    public function testARunsTranscriptIsReadInPagesOfWholeScrubbedLines(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $log = SubAgentTranscriptLog::forRun($sessionId, 'run-5');
        self::assertTrue($log->user("review \x1b[31mthe\x1b[0m parser"));
        self::assertTrue($log->toolCall('k1', 'Read', ['file_path' => 'src/Parser.php']));
        self::assertTrue($log->toolResult('k1', 'Read', true, \str_repeat('line of code' . "\n", 200)));
        self::assertTrue($log->assistant("looks fine\u{E000}"));
        self::assertTrue($log->status('finished', 'complete', null));

        $first = $client->call('agents.transcript', ['sessionId' => $sessionId, 'agentId' => 'run-5', 'limit' => 1024]);
        self::assertSame('run-5', $first['agentId']);
        self::assertTrue($first['more'], 'the 1 KiB page cannot hold the tool result line');
        self::assertSame('user', $first['items'][0]['t']);
        self::assertSame('review the parser', $first['items'][0]['text'], 'terminal escapes are scrubbed');

        $items = $first['items'];
        $offset = $first['offset'];
        for ($page = 0; $page < 10; $page++) {
            $next = $client->call('agents.transcript', ['sessionId' => $sessionId, 'agentId' => 'run-5', 'offset' => $offset]);
            \array_push($items, ...$next['items']);
            $offset = $next['offset'];
            if (!$next['more']) {
                break;
            }
        }
        self::assertSame(['user', 'tool_call', 'tool_result', 'assistant', 'status'], \array_column($items, 't'));
        self::assertSame(['file_path' => 'src/Parser.php'], $items[1]['args']);
        self::assertTrue($items[2]['ok']);
        self::assertSame('looks fine', $items[3]['text'], 'private-use codepoints are scrubbed');
        self::assertSame('complete', $items[4]['outcome']);
        \clearstatcache(true, $log->path());
        self::assertSame(\filesize($log->path()), $offset);

        $missing = $client->request('agents.transcript', ['sessionId' => $sessionId, 'agentId' => 'run-none']);
        self::assertSame('transcript_not_found', $missing['error']['data']['kind']);
    }

    public function testWorkflowRunOccupiesTheTurnAndItsReportIsListedWithTheToolRuns(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);

        $listed = $client->call('workflow.list');
        self::assertTrue($listed['available']);
        self::assertSame([['name' => 'deploy'], ['name' => 'review']], $listed['items']);

        $run = $client->call('workflow.run', ['sessionId' => $sessionId, 'name' => 'review', 'vars' => ['branch' => 'main', 'strict' => true]]);
        self::assertSame(['rows', 'effects'], \array_keys($run));
        $this->fixture->run(0.3);

        self::assertSame([['review', ['branch' => 'main', 'strict' => 'true']]], $this->workflowCalls);
        $runs = $client->call('workflow.runs', ['sessionId' => $sessionId])['items'];
        self::assertCount(1, $runs);
        self::assertSame('command', $runs[0]['source']);
        self::assertSame('review', $runs[0]['name']);
        self::assertSame('completed', $runs[0]['status']);
        self::assertSame('wf_review_1', $runs[0]['workflowId']);

        self::assertSame(['workflowId' => 'wf_review_1', 'status' => 'completed'], $client->call('workflow.status', ['workflowId' => 'wf_review_1']));
        $unknown = $client->request('workflow.status', ['workflowId' => 'nope']);
        self::assertSame('workflow_not_found', $unknown['error']['data']['kind']);

        $spaced = $client->request('workflow.run', ['sessionId' => $sessionId, 'name' => 'review', 'vars' => ['msg' => 'two words']]);
        self::assertSame('invalid_workflow_vars', $spaced['error']['data']['kind'], '/workflow run splits on whitespace');
        $badName = $client->request('workflow.run', ['sessionId' => $sessionId, 'name' => '../x']);
        self::assertSame('invalid_workflow_name', $badName['error']['data']['kind']);
    }

    public function testTheModelsWorkflowToolCallsAreListedLiveThenWithTheirReport(): void
    {
        [$client, $sessionId] = $this->turn();
        $this->fixture->backend->emit(new ToolStarted('w1', 'Workflow', ['plan' => "name: survey\nstages:\n  - name: look\n    prompt: look"]));
        $this->fixture->run(0.08);

        $runs = $client->call('workflow.runs', ['sessionId' => $sessionId])['items'];
        self::assertSame([['source' => 'tool', 'toolCallId' => 'w1', 'name' => 'survey', 'status' => 'running', 'running' => true]], $runs);

        $this->fixture->backend->emit(new ToolFinished('w1', 'Workflow', new EngineToolResult('w1', "Workflow 'survey' completed: 1 of 1 stage succeeded, 10 tokens, \$0.0000.\n\n### Stage 1: look (completed)")));
        $this->fixture->run(0.08);
        $this->fixture->backend->settle(Message::assistant('surveyed'));
        $this->fixture->run();

        $runs = $client->call('workflow.runs', ['sessionId' => $sessionId])['items'];
        self::assertCount(1, $runs);
        self::assertSame('tool', $runs[0]['source']);
        self::assertSame('survey', $runs[0]['name']);
        self::assertSame('completed', $runs[0]['status']);
        self::assertFalse($runs[0]['running']);
        self::assertStringContainsString('Stage 1: look', $runs[0]['report']);
    }

    public function testAWorkspaceWithoutAWorkflowEngineSaysSo(): void
    {
        $bare = ProtocolFixture::new();
        try {
            $client = $bare->client();
            $sessionId = $bare->session($client);
            self::assertSame(['available' => false, 'items' => []], $client->call('workflow.list'));
            $refused = $client->request('workflow.run', ['sessionId' => $sessionId, 'name' => 'review']);
            self::assertSame(ErrorCode::UnsupportedInServer->value, $refused['error']['code']);
            self::assertSame('workflows_unavailable', $refused['error']['data']['kind']);
        } finally {
            $bare->tearDown();
        }
    }

    // ── harness ─────────────────────────────────────────────────────────

    /** @return array{0: WireClient, 1: string} a client and a session whose turn is running */
    private function turn(): array
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $client->call('session.subscribe', ['sessionId' => $sessionId]);
        $this->fixture->run();
        $client->call('session.send', ['sessionId' => $sessionId, 'text' => 'delegate it']);

        return [$client, $sessionId];
    }

    private static function beat(
        string $op,
        string $id,
        int $seq,
        string $task = '',
        string $tail = '',
        string $description = '',
        string $outcome = '',
        ?string $resumeId = null,
    ): SubAgentActivity {
        return new SubAgentActivity(
            op: $op,
            id: $id,
            name: 'reviewer',
            task: $task,
            seq: $seq,
            tail: $tail,
            parentCallId: 'c1',
            description: $description,
            outcome: $outcome,
            resumeId: $resumeId,
        );
    }

    private function engine(): WorkflowEngineInterface
    {
        $calls = &$this->workflowCalls;

        return new class ($calls) implements WorkflowEngineInterface {
            /** @var array<string, WorkflowStatus> */
            private array $finished = [];

            /** @param list<array{0: string, 1: array<string, mixed>}> $calls */
            public function __construct(private array &$calls)
            {
            }

            public function run(string $workflowPath, array $context = [], ?CancellationToken $cancellation = null): WorkflowResult
            {
                $this->calls[] = [$workflowPath, $context];
                $id = 'wf_' . $workflowPath . '_' . \count($this->calls);
                $this->finished[$id] = WorkflowStatus::Completed;

                return new WorkflowResult($id, WorkflowStatus::Completed);
            }

            public function pause(string $workflowId): void
            {
            }

            public function resume(string $workflowId, ?CancellationToken $cancellation = null): WorkflowResult
            {
                return new WorkflowResult($workflowId, WorkflowStatus::Completed);
            }

            public function getStatus(string $workflowId): WorkflowStatus
            {
                return $this->finished[$workflowId]
                    ?? throw new \SugarCraft\Crush\Workflows\WorkflowNotRunningException('no run ' . $workflowId);
            }

            public function listWorkflows(): array
            {
                return ['deploy', 'review'];
            }
        };
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\Live\AgentInbox;
use SugarCraft\Crush\Agents\Live\AgentTranscriptTail;
use SugarCraft\Crush\Agents\Live\SubAgentTranscriptLog;
use SugarCraft\Crush\Agents\SuspendedDelegations;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Sessions\StoppableDaemonFixtureTrait;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap P-E3: `Ctrl+X b` in the Agent View sends a `background` control to
 * a running foreground run. The run stops at its next tool or step, saved,
 * and a background session continues the same conversation (4.3-2's
 * BackgroundSupervisor handoff), so its Task call returns at once with the
 * session's id and the parent turn goes on; the foreground leg ends with a
 * `backgrounded` finished frame naming the session.
 *
 * The daemons here run with `SUGARCRUSH_BACKEND_CMD` set, which hands them a
 * command backend: a background agent needs the engine, so each settles
 * Failed at once — the spawn is real, and no provider runs anywhere.
 */
final class TaskToolPromoteToBackgroundTest extends TestCase
{
    use StoppableDaemonFixtureTrait;

    /** Scratch temp root for the supervisors. SHORT: socket paths must fit 108 bytes. */
    private string $root = '';

    private string $storeDir = '';

    private string $session = '';

    /** @var list<SubAgentActivity> */
    private array $frames = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['proc_open', 'pcntl_fork', 'posix_setsid', 'posix_getuid', 'stream_socket_server'] as $fn) {
            if (!function_exists($fn)) {
                $this->markTestSkipped("{$fn}() unavailable");
            }
        }

        $this->root = sys_get_temp_dir() . '/tp' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o700);
        $this->storeDir = $this->root . '/suspended';
        $this->session = 'promote-' . bin2hex(random_bytes(5));
        $this->setUpDaemonFixture();
        putenv('SUGARCRUSH_BACKEND_CMD=cat >/dev/null; printf not-an-engine');
    }

    protected function tearDown(): void
    {
        $this->tearDownDaemonFixture();
        $this->removeTree($this->root);

        parent::tearDown();
    }

    public function testTheRunHandsItselfToABackgroundSessionAndTheCallReturnsAtOnce(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'probe', [])]),
            new CompleteResponse(content: 'never reached in the foreground'),
        ]);
        $supervisor = new BackgroundSupervisor(tempRoot: $this->root);
        // The user presses Ctrl+X b while the run's tool is working.
        $probe = $this->probe(fn (): mixed => $this->inbox()->control($this->runId(), AgentInbox::BACKGROUND_VERB));

        $result = $this->task($provider, $probe, $supervisor)->execute($this->call());

        $this->assertFalse($result->isError(), $result->content());
        $this->assertMatchesRegularExpression(
            '/\A\{"agent_id":"(sess_\d{14}_[0-9a-f]{8})","status":"running","background":true\}\n\n/',
            $result->content(),
        );
        $this->assertStringContainsString('The user moved this sub-agent to the background after 1 step', $result->content());
        $this->assertStringContainsString('DO NOT sleep or poll', $result->content());
        $this->assertCount(1, $provider->requests, 'the foreground went no further: its next step is the session\'s');

        preg_match('/"agent_id":"([^"]+)"/', $result->content(), $m);
        $id = $m[1];
        $session = $supervisor->getSession($id);
        $this->assertNotNull($session, 'the session was spawned');
        $this->assertSame('look (@coder)', $session->name);
        $this->assertStringContainsString('moved this run to the background', $session->task, 'it is told why it picks up mid-run');
        $this->trackDaemon(json_decode((string) file_get_contents($this->indexDir() . '/' . $id . '.json'), true));

        // The foreground leg's finished frame: backgrounded, naming the
        // session, with the id the session resumes.
        $finished = $this->finished();
        $this->assertSame(SubAgentActivity::OUTCOME_BACKGROUNDED, $finished->outcome);
        $this->assertStringContainsString('goes on as background session ' . $id, $finished->tail);
        $this->assertNotNull($finished->resumeId);

        // What the session resumes is the run so far.
        $saved = (new SuspendedDelegations($this->storeDir))->load((string) $finished->resumeId);
        $this->assertNotNull($saved);
        $this->assertSame('coder', $saved['agent']);
        $this->assertNotSame([], $saved['transcript']);
    }

    public function testARunThatCannotGoToTheBackgroundSaysSoAndKeepsRunning(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'probe', [])]),
            new CompleteResponse(content: 'finished here'),
        ]);
        $probe = $this->probe(fn (): mixed => $this->inbox()->control($this->runId(), AgentInbox::BACKGROUND_VERB));

        // No supervisor bound: this launch starts no background sessions.
        $result = $this->task($provider, $probe, null)->execute($this->call());

        $this->assertFalse($result->isError(), $result->content());
        $this->assertStringContainsString('finished here', $result->content());
        $this->assertSame(SubAgentActivity::OUTCOME_COMPLETE, $this->finished()->outcome);
        $this->assertContains('stayed in the foreground', $this->loggedStatuses());
    }

    public function testASessionThatCannotStartLeavesTheRunStoppedAndResumable(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'probe', [])]),
            new CompleteResponse(content: 'never reached'),
        ]);
        // A temp root that is a FILE: no private IPC directory can go there.
        $blocked = $this->root . '/blocked';
        file_put_contents($blocked, '');
        $probe = $this->probe(fn (): mixed => $this->inbox()->control($this->runId(), AgentInbox::BACKGROUND_VERB));

        $result = $this->task($provider, $probe, new BackgroundSupervisor(tempRoot: $blocked))->execute($this->call());

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('was stopped to move it to the background, but no background session could be started', $result->content());
        $this->assertMatchesRegularExpression('/"resume": "[0-9a-f]{16}"/', $result->content(), 'it stays resumable');
        $this->assertSame(SubAgentActivity::OUTCOME_CANCELLED, $this->finished()->outcome);
    }

    // ── harness ─────────────────────────────────────────────────────────

    private function runId(): string
    {
        $this->assertNotSame([], $this->frames, 'fixture: the run has started');

        return $this->frames[0]->id;
    }

    private function finished(): SubAgentActivity
    {
        $finished = array_values(array_filter(
            $this->frames,
            static fn (SubAgentActivity $frame): bool => $frame->op === SubAgentActivity::OP_FINISHED,
        ));
        $this->assertCount(1, $finished, 'one finished frame');

        return $finished[0];
    }

    private function inbox(): AgentInbox
    {
        $inbox = AgentInbox::forSession($this->session);
        $this->assertNotNull($inbox);

        return $inbox;
    }

    /**
     * @return list<string> the outcomes of the run's logged status items
     */
    private function loggedStatuses(): array
    {
        $log = SubAgentTranscriptLog::forRun($this->session, $this->runId());
        if (!is_file($log->path())) {
            return [];
        }
        $items = AgentTranscriptTail::of($log->path())->next()[0];

        return array_values(array_map(
            static fn (array $item): string => (string) ($item['outcome'] ?? ''),
            array_filter($items, static fn (array $item): bool => $item['t'] === SubAgentTranscriptLog::T_STATUS),
        ));
    }

    private function task(ScriptedProvider $provider, Tool $probe, ?BackgroundSupervisor $supervisor): TaskTool
    {
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry(), toolRegistry: [$probe], toolUniverse: [$probe]);
        $manager->register(RosterAgent::named('coder', ['probe'], maxTurns: 5));
        $task = (new TaskTool($manager, suspended: new SuspendedDelegations($this->storeDir)))->withEngine(
            EngineBackend::new($provider, 'm')->withoutHooks()->withTools([$probe])->withSessionId($this->session),
            null,
            function (SubAgentActivity $activity): void {
                $this->frames[] = $activity;
            },
        );

        return $supervisor === null ? $task : $task->withBackgroundSupervisor($supervisor, $this->fixtureHome);
    }

    /**
     * @return array<string, string>
     */
    private function call(): array
    {
        return ['id' => 'call_1', 'agent' => 'coder', 'prompt' => 'look around', 'description' => 'look'];
    }

    /** @param array<string, mixed> $record */
    private function trackDaemon(array $record): void
    {
        $this->fixtureDaemons[] = ['pid' => (int) $record['pid'], 'startTime' => $record['startTime'] ?? null];
        foreach (['socketPath', 'bufferPath', 'tokenPath'] as $key) {
            $this->fixtureFiles[] = (string) $record[$key];
        }
        $this->fixtureFiles[] = $record['bufferPath'] . '.log';
    }

    private function indexDir(): string
    {
        return $this->root . '/' . BackgroundSupervisor::IPC_DIR_PREFIX . posix_getuid() . BackgroundSupervisor::INDEX_DIR_SUFFIX;
    }

    /**
     * A tool that runs $during while the run is busy with it — the moment
     * the user acts.
     */
    private function probe(\Closure $during): Tool
    {
        return new class ($during) implements Tool {
            public function __construct(private \Closure $during)
            {
            }

            public function name(): string
            {
                return 'probe';
            }

            public function description(): string
            {
                return 'acts while it runs';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                ($this->during)();

                return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: 'probed');
            }
        };
    }
}

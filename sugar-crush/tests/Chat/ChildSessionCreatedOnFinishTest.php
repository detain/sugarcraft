<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\Live\AgentLiveRegistry;
use SugarCraft\Crush\Agents\Live\SubAgentTranscriptLog;
use SugarCraft\Crush\Agents\SuspendedDelegations;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\ToolEventPumpMsg;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap P-C1: a finished delegated run becomes a `subagent` child session
 * of the session that delegated it — created by the PARENT when the finished
 * frame lands, with the transcript the run's own process logged.
 */
final class ChildSessionCreatedOnFinishTest extends TestCase
{
    private const GENERATION = 4;

    private string $dir;

    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sc_child_session_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
        $this->store = new EnhancedSessionStore($this->dir . '/session.db');
        $this->store->createSession('parent-1', 'scripted', 'm');
    }

    protected function tearDown(): void
    {
        unset($this->store);
        $this->remove($this->dir);
    }

    public function testTheFinishedFrameOnTheLivePumpStoresTheRunAsAChildSession(): void
    {
        $manager = $this->manager();
        $log = SubAgentTranscriptLog::forRun('parent-1', 'run_a', $this->dir . '/subagents');
        $log->user('Map the login flow');
        $log->toolCall('c1', 'Grep', ['pattern' => 'LoginController']);
        $log->toolResult('c1', 'Grep', true, 'routes/web.php:12');
        $log->assistant('Wired in routes/web.php.');
        $log->status('complete', 'complete', null);

        $inbox = new \ArrayObject();
        AgentLiveRegistry::of($inbox, static fn (): float => 1000.0);
        $chat = new Chat(history: [Message::user('go')], backend: new EchoBackend(), inFlight: true, generation: self::GENERATION, liveToolEvents: $inbox, agentManager: $manager);
        $inbox[] = [self::GENERATION, new SubAgentActivity('started', 'run_a', 'coder', 'Map the login flow', 1, '', model: 'm', parentCallId: 'tc_1', description: 'Map the login flow', transcriptLog: $log->path(), parentSessionId: 'parent-1')];
        $inbox[] = [self::GENERATION, new SubAgentActivity('finished', 'run_a', 'coder', '', 2, 'Wired in routes/web.php.', parentCallId: 'tc_1', description: 'Map the login flow', outcome: 'complete', transcriptLog: $log->path(), parentSessionId: 'parent-1')];
        for ($i = 0; $i < 3; $i++) {
            [$chat] = $chat->update(new ToolEventPumpMsg());
        }

        $childId = $manager->childSessionIdOf('run_a');
        $this->assertNotNull($childId, 'the parent stored the finished run');
        $child = $this->store->getSession($childId);
        $this->assertSame('subagent', $child['kind']);
        $this->assertSame('parent-1', $child['parent_id']);
        $this->assertSame('tc_1', $child['parent_call_id'], 'the Task call that spawned it reopens it after a restart');
        $this->assertSame('coder', $child['agent']);
        $this->assertSame('complete', $child['status']);
        $this->assertSame('Map the login flow (@coder)', $child['name']);

        $rows = $this->store->loadTranscript($childId);
        $this->assertIsArray($rows);
        $contents = self::contents($rows);
        $this->assertContains('Map the login flow', $contents);
        $this->assertContains('Wired in routes/web.php.', $contents);
        $this->assertContains('routes/web.php:12', $contents, 'the tool result is a finished row');
    }

    public function testAFailedRunIsStoredFailedAndTheFrameNamesItsChild(): void
    {
        $manager = $this->manager();
        $log = SubAgentTranscriptLog::forRun('parent-1', 'run_b', $this->dir . '/subagents');
        $log->user('try');
        $log->status('failed', 'failed', 'step cap');

        $stamped = $manager->projectRemoteSubAgent(new SubAgentActivity('finished', 'run_b', 'coder', '', 2, '', outcome: 'failed', error: 'step cap', transcriptLog: $log->path(), parentSessionId: 'parent-1'));

        $this->assertNotNull($stamped->childSessionId);
        $this->assertSame($stamped->childSessionId, $manager->childSessionIdOf('run_b'));
        $this->assertSame('failed', $this->store->getSession($stamped->childSessionId)['status']);
        $this->assertSame($stamped->childSessionId, SubAgentActivity::fromArray($stamped->toArray())?->childSessionId, 'the stamp survives the wire');
    }

    public function testNothingIsStoredFromALogOutsideTheTranscriptRootOrWithoutAParent(): void
    {
        $manager = $this->manager();
        $outside = $this->dir . '/elsewhere/s/a.jsonl';

        $this->assertNull($manager->projectRemoteSubAgent(new SubAgentActivity('finished', 'x', 'coder', '', 1, '', outcome: 'complete', transcriptLog: $outside, parentSessionId: 'parent-1'))->childSessionId);
        $this->assertNull($manager->projectRemoteSubAgent(new SubAgentActivity('finished', 'y', 'coder', '', 1, '', outcome: 'complete', transcriptLog: $this->dir . '/subagents/s/y.jsonl'))->childSessionId);
        $this->assertSame([], $this->store->childrenOf('parent-1'));
    }

    /**
     * End to end from the child's side: a Task run on an engine that belongs
     * to a session writes its whole conversation, names the log on its
     * bracketing frames, and a resume continues the same log — which the
     * parent re-saves into the same child rather than storing it twice.
     */
    public function testATaskRunLogsItselfAndAResumeContinuesTheSameChild(): void
    {
        $manager = $this->manager();
        $probe = self::probe();
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'probe', ['path' => 'src'])]),
            new CompleteResponse(content: 'first report'),
            new CompleteResponse(content: 'follow-up answer'),
        ]);
        $frames = [];
        $task = (new TaskTool($manager, suspended: new SuspendedDelegations($this->dir . '/suspended')))
            ->withTranscriptRoot($this->dir . '/subagents')
            ->withEngine(
                EngineBackend::new($provider, 'm')->withTools([$probe])->withSessionId('parent-1'),
                null,
                static function (SubAgentActivity $frame) use (&$frames): void {
                    $frames[] = $frame;
                },
            );

        $first = $task->execute(['id' => 'tc_1', 'description' => 'Survey src', 'prompt' => 'Survey src', 'agent' => 'coder']);
        $this->assertFalse($first->isError(), $first->content());
        $started = $frames[0];
        $finished = $frames[\count($frames) - 1];
        $this->assertSame('started', $started->op);
        $this->assertSame('parent-1', $started->parentSessionId);
        $this->assertNotNull($started->transcriptLog);
        $this->assertSame($started->transcriptLog, $finished->transcriptLog);
        foreach ($frames as $frame) {
            if ($frame->op === 'progress') {
                $this->assertNull($frame->transcriptLog, 'progress beats do not repeat the path');
            }
        }
        $this->assertSame(
            ['user', 'tool_call', 'tool_result', 'assistant', 'status'],
            array_column(\SugarCraft\Crush\Agents\Live\AgentTranscriptTail::readAll($started->transcriptLog), 't'),
        );

        foreach ($frames as $frame) {
            $manager->projectRemoteSubAgent($frame);
        }
        $childId = $manager->childSessionIdOf($finished->id);
        $this->assertNotNull($childId);

        preg_match('/"resume": "([0-9a-f]{16})"/', $first->content(), $m);
        $frames = [];
        $task->execute(['id' => 'tc_2', 'description' => 'Follow up', 'prompt' => 'And tests?', 'agent' => 'coder', 'resume' => $m[1]]);
        $this->assertSame($started->transcriptLog, $frames[0]->transcriptLog, 'the resume writes the same log');
        foreach ($frames as $frame) {
            $manager->projectRemoteSubAgent($frame);
        }

        $this->assertCount(1, $this->store->childrenOf('parent-1'), 'one conversation, one child session');
        $contents = self::contents($this->store->loadTranscript($childId) ?? []);
        $this->assertContains('first report', $contents);
        $this->assertContains('And tests?', $contents);
        $this->assertContains('follow-up answer', $contents);
    }

    public function testARunWithNoSessionKeepsNoLog(): void
    {
        $frames = [];
        $task = (new TaskTool($this->manager(), suspended: new SuspendedDelegations($this->dir . '/suspended')))
            ->withTranscriptRoot($this->dir . '/subagents')
            ->withEngine(EngineBackend::new(new ScriptedProvider([new CompleteResponse(content: 'ok')]), 'm'), null, static function (SubAgentActivity $frame) use (&$frames): void {
                $frames[] = $frame;
            });

        $task->execute(['description' => 'd', 'prompt' => 'p', 'agent' => 'coder']);

        $this->assertNull($frames[0]->transcriptLog);
        $this->assertDirectoryDoesNotExist($this->dir . '/subagents');
    }

    /**
     * @param list<Message|array<string, mixed>> $rows
     * @return list<string>
     */
    private static function contents(array $rows): array
    {
        return array_map(static fn (Message|array $row): string => \is_array($row) ? (string) ($row['content'] ?? '') : $row->content, $rows);
    }

    private function manager(): AgentManager
    {
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry());
        $manager->register(RosterAgent::named('coder', ['probe']));
        $store = $this->store;
        $manager->recordChildSessionsIn(static fn (): EnhancedSessionStore => $store, $this->dir . '/subagents');

        return $manager;
    }

    /**
     * @return Tool&object{calls: list<array<string, mixed>>}
     */
    private static function probe(): Tool
    {
        return new class implements Tool {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function name(): string
            {
                return 'probe';
            }

            public function description(): string
            {
                return 'probe';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                $this->calls[] = $args;

                return new ToolResult((string) ($args['id'] ?? ''), 'src has 3 files');
            }
        };
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            @rmdir($path);

            return;
        }
        @unlink($path);
    }
}

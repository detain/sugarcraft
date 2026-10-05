<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents\Live;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\Live\AgentInbox;
use SugarCraft\Crush\Agents\Live\AgentTranscriptTail;
use SugarCraft\Crush\Agents\Live\SubAgentTranscriptLog;
use SugarCraft\Crush\Agents\SuspendedDelegations;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap P-D3: a delegated run obeys the user's `cancel`, `pause` and
 * `resume` controls from its mailbox (Appendix P §5.5) — a soft cancel stops
 * it resumable at its next step, a pause holds it at its next step boundary
 * with heartbeats, and the pause is capped so the parent's Task call can
 * never hang on it.
 */
final class SoftCancelPauseTest extends TestCase
{
    private string $storeDir;

    private string $session;

    /** The run's id, from its started frame. */
    private ?string $runId = null;

    protected function setUp(): void
    {
        $this->storeDir = sys_get_temp_dir() . '/sc_soft_cancel_' . bin2hex(random_bytes(6));
        $this->session = 'soft-' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storeDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storeDir);
    }

    public function testASoftCancelStopsTheRunResumableAtItsNextStep(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'probe', [])]),
            new CompleteResponse(content: 'never reached'),
        ]);
        // The user presses "cancel" while the run's tool is working.
        $probe = $this->probe(fn (): mixed => $this->inbox()->control((string) $this->runId, 'cancel'));

        $result = $this->task($provider, $probe)->execute($this->call());

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('was cancelled by the user (from the Agent View)', $result->content());
        $this->assertMatchesRegularExpression('/"resume": "[0-9a-f]{16}"/', $result->content(), 'it stays resumable');
        $this->assertCount(1, $provider->requests, 'the next step never went out');
    }

    public function testACancelFromTheLaunchingAgentSaysSo(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'probe', [])]),
            new CompleteResponse(content: 'never reached'),
        ]);
        // `Subagents cancel` (roadmap 4.4) sends the same verb as the parent.
        $probe = $this->probe(fn (): mixed => $this->inbox()->control((string) $this->runId, 'cancel', \SugarCraft\Crush\Agents\Live\AgentMessage::FROM_PARENT));

        $result = $this->task($provider, $probe)->execute($this->call());

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('was cancelled by the agent that launched it (Subagents cancel)', $result->content());
        $this->assertStringNotContainsString('by the user', $result->content());
    }

    public function testAPauseHoldsTheRunAtItsNextStepUntilResumed(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'probe', [])]),
            new CompleteResponse(content: 'all done'),
        ]);
        $probe = $this->probe(fn (): mixed => $this->inbox()->control((string) $this->runId, 'pause'));
        $beats = 0;
        $resumed = false;
        // The user lets it go while it waits. The beat is how the wait shows
        // it is alive, so it is where the test acts — once the run says it is
        // paused (beats also come while it works, before it ever is).
        $heartbeat = function () use (&$beats, &$resumed): void {
            $beats++;
            if (!$resumed && \in_array('paused by the user', $this->loggedStatuses(), true)) {
                $resumed = true;
                $this->inbox()->control((string) $this->runId, 'resume');
            }
        };

        $result = $this->task($provider, $probe, $heartbeat)->execute($this->call());

        $this->assertFalse($result->isError(), $result->content());
        $this->assertStringContainsString('all done', $result->content());
        $this->assertGreaterThanOrEqual(1, $beats, 'the pause fed the parent\'s watchdog');
        $statuses = $this->loggedStatuses();
        $this->assertContains('paused by the user', $statuses);
        $this->assertContains('resumed by the user', $statuses);
    }

    public function testAPauseIsCappedSoTheParentNeverHangs(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'probe', [])]),
            new CompleteResponse(content: 'went on'),
        ]);
        $probe = $this->probe(fn (): mixed => $this->inbox()->control((string) $this->runId, 'pause'));
        // Every read of the run's clock is 400 s later: the cap runs out on
        // the second slice, with no resume ever sent.
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now += 400.0;
        };

        $result = $this->task($provider, $probe, null, $clock)->execute($this->call());

        $this->assertFalse($result->isError(), $result->content());
        $this->assertStringContainsString('went on', $result->content());
        $this->assertContains('resumed after the 10 min pause cap', $this->loggedStatuses());
    }

    public function testAControlFromAnotherLaunchIsDroppedNotObeyed(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'probe', [])]),
            new CompleteResponse(content: 'kept going'),
        ]);
        // Signed with a key that is not this launch's: a forged "user" cancel.
        $forger = AgentInbox::forSession($this->session, str_repeat('k', 32));
        $this->assertNotNull($forger);
        $probe = $this->probe(fn (): mixed => $forger->control((string) $this->runId, 'cancel'));

        $result = $this->task($provider, $probe)->execute($this->call());

        $this->assertFalse($result->isError(), $result->content());
        $dropped = array_filter($this->loggedStatuses(), static fn (string $outcome): bool => str_starts_with($outcome, 'dropped a'));
        $this->assertCount(1, $dropped, 'dropped, and the drop is on the record');
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
        if ($this->runId === null) {
            return [];
        }
        $log = SubAgentTranscriptLog::forRun($this->session, $this->runId);
        if (!is_file($log->path())) {
            return [];
        }
        $items = AgentTranscriptTail::of($log->path())->next()[0];

        return array_values(array_map(
            static fn (array $item): string => (string) ($item['outcome'] ?? ''),
            array_filter($items, static fn (array $item): bool => $item['t'] === SubAgentTranscriptLog::T_STATUS),
        ));
    }

    /**
     * @param (\Closure(): void)|null  $heartbeat
     * @param (\Closure(): float)|null $clock
     */
    private function task(ScriptedProvider $provider, Tool $probe, ?\Closure $heartbeat = null, ?\Closure $clock = null): TaskTool
    {
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry(), toolRegistry: [$probe], toolUniverse: [$probe]);
        $manager->register(RosterAgent::named('coder', ['probe'], maxTurns: 5));
        $task = new TaskTool($manager, suspended: new SuspendedDelegations($this->storeDir));
        if ($clock !== null) {
            $task = $task->withActivityClock($clock);
        }

        return $task->withEngine(
            EngineBackend::new($provider, 'm')->withTools([$probe])->withSessionId($this->session),
            $heartbeat,
            function (SubAgentActivity $activity): void {
                $this->runId ??= $activity->id;
            },
        );
    }

    /**
     * @return array<string, string>
     */
    private function call(): array
    {
        return ['id' => 'call_1', 'agent' => 'coder', 'prompt' => 'look around', 'description' => 'look'];
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

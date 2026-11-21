<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Agents\Live\AgentRunCards;
use SugarCraft\Code\Agents\SubAgent;
use SugarCraft\Code\Agents\SuspendedDelegations;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Events\SubAgentActivity;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Skills\Skill;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Support\ToolCancelRequests;
use SugarCraft\Code\Tests\Support\RosterAgent;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\BuiltIn\SkillTool;
use SugarCraft\Code\Tools\BuiltIn\TaskTool;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolCall;
use SugarCraft\Code\Tools\ToolResult;

/**
 * CC1C-c1: the behaviour `AgentManager::executeSubAgent()` had and the live
 * Task path lacked, merged into {@see TaskTool} ahead of that method's
 * removal. One test per merged behaviour, each driven through the real engine
 * loop on the in-process row the session's {@see AgentManager} holds.
 *
 * What executeSubAgent() did that TaskTool already does under another name is
 * recorded in the CC1C-c1 commit message and is not re-pinned here. The two
 * entries that message filed as present but were not have since been merged:
 * per-chunk usage telemetry (TaskTool's chunk usage observer raises the row on
 * every streamed chunk, pinned here and in TaskToolProgressFramesTest) and
 * per-chunk streamed output (a prose burst lands on the row's output as it
 * streams, pinned here).
 */
final class TaskToolExecuteMergeTest extends TestCase
{
    private string $storeDir;

    /** the scratch root of a forked skill's SKILL.md, removed in tearDown */
    private ?string $skillRoot = null;

    protected function setUp(): void
    {
        $this->storeDir = sys_get_temp_dir() . '/sc_task_merge_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        ToolCancelRequests::forget();
        if ($this->skillRoot !== null) {
            @unlink($this->skillRoot . '/skills/fork-check/SKILL.md');
            @rmdir($this->skillRoot . '/skills/fork-check');
            @rmdir($this->skillRoot . '/skills');
            @rmdir($this->skillRoot);
            $this->skillRoot = null;
        }
        foreach (glob($this->storeDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storeDir);
    }

    /**
     * A throw from outside the turn boundary leaves the row terminal, as
     * executeSubAgent()'s catch-all did: FAILED with the message and an end
     * of span when the run had not settled, and the settled outcome kept
     * when it had. The throwable still reaches the caller either way.
     *
     * @return array<string, array{string, string}>
     */
    public static function throwingFrames(): array
    {
        return [
            'before the run settles' => [SubAgentActivity::OP_STARTED, SubAgent::STATUS_FAILED],
            'after the run settled' => [SubAgentActivity::OP_FINISHED, SubAgent::STATUS_COMPLETE],
        ];
    }

    #[DataProvider('throwingFrames')]
    public function testAThrowOutsideTheTurnLeavesTheRowTerminal(string $throwOn, string $expectedStatus): void
    {
        $manager = self::sessionManager([]);
        $task = (new TaskTool($manager, suspended: new SuspendedDelegations($this->storeDir)))
            ->withEngine(EngineBackend::new(new ScriptedProvider([new CompleteResponse(content: 'reported')]), 'm'))
            ->withSubAgentEmitter(static function (SubAgentActivity $activity) use ($throwOn): void {
                if ($activity->op === $throwOn) {
                    throw new \RuntimeException('activity socket closed');
                }
            });

        $caught = null;
        try {
            $task->execute(self::delegation());
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'the emitter failure must still reach the caller');
        $this->assertSame('activity socket closed', $caught->getMessage());

        $runs = $manager->runs();
        $this->assertCount(1, $runs);
        $row = $runs[0];
        $this->assertSame($expectedStatus, $row->status);
        $this->assertNotNull($row->completedAt, 'a terminal row has an end of span, so elapsed time stops counting');
        $this->assertFalse($row->isRunning());
        $this->assertSame([], $manager->liveRuns());
        if ($expectedStatus === SubAgent::STATUS_FAILED) {
            $this->assertSame('activity socket closed', $row->error);
        } else {
            $this->assertNull($row->error, 'a truthful COMPLETE is not rewritten by a later throw');
        }
    }

    /**
     * The run's card is settled with its row: a throw that lands after the
     * card was recorded RUNNING (with this live process's pid) must not leave
     * it RUNNING, or `Subagents list`/`wait` reads the dead run as running
     * for as long as the session lives. A card the run already settled keeps
     * its outcome.
     *
     * @return array<string, array{string, string}>
     */
    public static function throwingFramesForCards(): array
    {
        return [
            'before the run settles' => [SubAgentActivity::OP_STARTED, SubAgentActivity::OUTCOME_FAILED],
            'after the run settled' => [SubAgentActivity::OP_FINISHED, SubAgentActivity::OUTCOME_COMPLETE],
        ];
    }

    #[DataProvider('throwingFramesForCards')]
    public function testAThrowOutsideTheTurnLeavesTheRunCardTerminal(string $throwOn, string $expectedCardStatus): void
    {
        $session = 'merge-' . bin2hex(random_bytes(5));
        $manager = self::sessionManager([]);
        $task = (new TaskTool($manager, suspended: new SuspendedDelegations($this->storeDir)))
            ->withEngine(EngineBackend::new(new ScriptedProvider([new CompleteResponse(content: 'reported')]), 'm')->withSessionId($session))
            ->withSubAgentEmitter(static function (SubAgentActivity $activity) use ($throwOn): void {
                if ($activity->op === $throwOn) {
                    throw new \RuntimeException('activity socket closed');
                }
            });

        $caught = null;
        try {
            $task->execute(self::delegation());
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'the emitter failure must still reach the caller');

        $cards = AgentRunCards::runs($session);
        $this->assertCount(1, $cards, 'the run recorded its card before the throw');
        $card = $cards[0];
        $this->assertSame($manager->runs()[0]->id, $card['runId']);
        $this->assertSame($expectedCardStatus, $card['status']);
        $this->assertSame($expectedCardStatus, AgentRunCards::statusOf($card), 'a live pid no longer reads the dead run as running');
        $this->assertGreaterThan(0, $card['finishedAt']);
        if ($expectedCardStatus === SubAgentActivity::OUTCOME_FAILED) {
            $this->assertSame('activity socket closed', $card['error']);
        } else {
            $this->assertSame('', $card['error'], 'a settled card is not rewritten by a later throw');
        }
    }

    /**
     * A row that already settled COMPLETE keeps its card out of the throw's
     * reach: when the run's own card settle was lost (AgentRunCards::updateRun()
     * answers null on a failed lock or write) and the finished frame then
     * throws, the card must not be rewritten to FAILED with the throw's
     * message — the run delivered its report, and its row says so — and it
     * must not stay RUNNING with this live pid either, or statusOf() reads
     * the finished run as running for as long as the session lives.
     */
    public function testAThrowAfterASettledRowSettlesItsCardToTheRunsOwnOutcome(): void
    {
        $session = 'merge-' . bin2hex(random_bytes(5));
        $manager = self::sessionManager([]);
        $task = (new TaskTool($manager, suspended: new SuspendedDelegations($this->storeDir)))
            ->withEngine(EngineBackend::new(new ScriptedProvider([new CompleteResponse(content: 'reported')]), 'm')->withSessionId($session))
            ->withSubAgentEmitter(static function (SubAgentActivity $activity) use ($session): void {
                if ($activity->op !== SubAgentActivity::OP_FINISHED) {
                    return;
                }
                // Stand in for the settle write $finish lost: the card is back
                // to RUNNING, the row already COMPLETE.
                $runId = AgentRunCards::runs($session)[0]['runId'];
                AgentRunCards::updateRun($session, $runId, static fn (array $card): array => [
                    'status' => AgentRunCards::STATUS_RUNNING,
                    'error' => '',
                    'finishedAt' => 0,
                ] + $card);

                throw new \RuntimeException('activity socket closed');
            });

        $caught = null;
        try {
            $task->execute(self::delegation());
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'the emitter failure must still reach the caller');

        $this->assertSame(SubAgent::STATUS_COMPLETE, $manager->runs()[0]->status);
        $cards = AgentRunCards::runs($session);
        $this->assertCount(1, $cards);
        $this->assertSame(SubAgentActivity::OUTCOME_COMPLETE, $cards[0]['status'], 'the lost settle is written again with the run\'s own outcome, never the throw\'s');
        $this->assertSame(SubAgentActivity::OUTCOME_COMPLETE, AgentRunCards::statusOf($cards[0]), 'a settled run never reads as running for the life of the session');
        $this->assertGreaterThan(0, $cards[0]['finishedAt'], 'the card records when the run ended');
        $this->assertSame('', $cards[0]['error'], 'the throw\'s message is not why the run ended');
    }

    /**
     * A row the run already settled FAILED or STOPPED keeps that outcome when
     * its finished frame then throws: the provider's error text and the
     * cancel are why the run ended, not the socket that broke afterwards, and
     * the card keeps the outcome and error its own settle wrote.
     *
     * @return array<string, array{\Closure(): ProviderInterface, list<string>, string, string}>
     */
    public static function settledUnfinishedRuns(): array
    {
        return [
            'failed on a provider error' => [
                static fn (): ProviderInterface => new ScriptedProvider([new \RuntimeException('401 Unauthorized')]),
                [],
                SubAgent::STATUS_FAILED,
                SubAgentActivity::OUTCOME_FAILED,
            ],
            'stopped by a cancel' => [
                static fn (): ProviderInterface => new ScriptedProvider([new CompleteResponse(content: 'reported')]),
                ['task-call'],
                SubAgent::STATUS_STOPPED,
                SubAgentActivity::OUTCOME_CANCELLED,
            ],
        ];
    }

    /**
     * @param \Closure(): ProviderInterface $provider
     * @param list<string> $cancelled call ids the user asked to stop
     */
    #[DataProvider('settledUnfinishedRuns')]
    public function testALateThrowKeepsAFailedOrStoppedRowsOutcome(\Closure $provider, array $cancelled, string $expectedStatus, string $expectedCardStatus): void
    {
        ToolCancelRequests::listen(static fn (): array => $cancelled);
        $session = 'merge-' . bin2hex(random_bytes(5));
        $manager = self::sessionManager([]);
        $settled = null;
        $task = (new TaskTool($manager, suspended: new SuspendedDelegations($this->storeDir)))
            ->withEngine(EngineBackend::new($provider(), 'm')->withSessionId($session))
            ->withSubAgentEmitter(static function (SubAgentActivity $activity) use ($manager, $session, &$settled): void {
                if ($activity->op !== SubAgentActivity::OP_FINISHED) {
                    return;
                }
                $row = $manager->runs()[0];
                $settled = ['status' => $row->status, 'error' => $row->error, 'card' => AgentRunCards::runs($session)[0]];

                throw new \RuntimeException('activity socket closed');
            });

        $caught = null;
        try {
            $task->execute(self::delegation() + ['id' => 'task-call']);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'the emitter failure must still reach the caller');
        $this->assertSame('activity socket closed', $caught->getMessage());
        $this->assertNotNull($settled, 'the run settled and sent its finished frame');
        $this->assertSame($expectedStatus, $settled['status'], 'the run settled itself before the throw');
        $this->assertNotSame('', (string) $settled['error']);

        $row = $manager->runs()[0];
        $this->assertSame($expectedStatus, $row->status, 'a settled outcome is not rewritten by a later throw');
        $this->assertSame($settled['error'], $row->error, 'the run\'s own reason is kept, never the throw\'s message');
        $this->assertNotNull($row->completedAt);

        $cards = AgentRunCards::runs($session);
        $this->assertCount(1, $cards);
        $this->assertSame($expectedCardStatus, $settled['card']['status']);
        $this->assertSame($expectedCardStatus, $cards[0]['status'], 'the card keeps the outcome its own settle wrote');
        $this->assertSame($settled['card']['error'], $cards[0]['error'], 'the card\'s error is not the throw\'s message');
        $this->assertSame($expectedCardStatus, AgentRunCards::statusOf($cards[0]));
    }

    /**
     * The row reads STREAMING once provider output arrives, as
     * executeSubAgent() marked its streaming runs — RUNNING before the first
     * delta, STREAMING while the run is under way, terminal at the end.
     */
    public function testTheRowReadsStreamingOnceProviderOutputArrives(): void
    {
        $seen = [];
        $manager = null;
        $probe = self::statusProbe(static function () use (&$manager, &$seen): void {
            $seen['at the tool call'] = $manager->runs()[0]->status;
        });
        $manager = self::sessionManager([$probe]);
        $provider = new ScriptedProvider([
            [
                new CompleteResponse(content: 'Looking at it'),
                new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'StatusProbe', [])]),
            ],
            [new CompleteResponse(content: 'reported')],
        ], streams: true);
        $task = (new TaskTool($manager, suspended: new SuspendedDelegations($this->storeDir)))
            ->withEngine(EngineBackend::new($provider, 'm')->withTools([$probe]))
            ->withSubAgentEmitter(static function (SubAgentActivity $activity) use (&$manager, &$seen): void {
                if ($activity->op === SubAgentActivity::OP_STARTED) {
                    $seen['at the started frame'] = $manager->runs()[0]->status;
                }
            });

        $result = $task->execute(self::delegation());

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame([
            'at the started frame' => SubAgent::STATUS_RUNNING,
            'at the tool call' => SubAgent::STATUS_STREAMING,
        ], $seen);
        $this->assertSame(SubAgent::STATUS_COMPLETE, $manager->runs()[0]->status);
    }

    /**
     * The row's tokens and cost rise on every streamed chunk, as
     * executeSubAgent() folded each chunk's usage, not only once a provider
     * response completes; the step's billed usage then replaces the chunk
     * figures instead of being added to them.
     */
    public function testTheRowCountsEachStreamedChunkAndSettlesOnTheBilledUsage(): void
    {
        $probe = self::statusProbe(static function (): void {
        });
        $manager = self::sessionManager([$probe]);
        $provider = new ScriptedProvider([
            [
                new CompleteResponse(content: 'looking ', tokensUsed: 100, costUsd: 0.01),
                new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'StatusProbe', [])], tokensUsed: 200, costUsd: 0.02),
            ],
            [
                new CompleteResponse(content: 'the ', tokensUsed: 30, costUsd: 0.003),
                new CompleteResponse(content: 'report', tokensUsed: 20, costUsd: 0.002),
            ],
        ], streams: true);
        $now = 0.0;
        $tokens = [];
        $task = (new TaskTool($manager, suspended: new SuspendedDelegations($this->storeDir)))
            ->withEngine(EngineBackend::new($provider, 'm')->withTools([$probe]))
            ->withSubAgentEmitter(static function (SubAgentActivity $activity) use (&$tokens): void {
                $tokens[] = $activity->tokensUsed;
            })
            ->withActivityClock(static function () use (&$now): float {
                return $now += 1.0;
            });

        $result = $task->execute(self::delegation());

        $this->assertFalse($result->isError(), $result->content());
        $this->assertContains(330, $tokens, 'the second reply\'s first chunk is counted while that reply still streams');
        $row = $manager->runs()[0];
        $this->assertSame(350, $row->tokensUsed, 'chunk figures are replaced by the step\'s billed usage, not added to it');
        $this->assertEqualsWithDelta(0.035, $row->costUsd, 1e-9);
    }

    /**
     * A run that only writes prose is live while it streams, as
     * executeSubAgent() appended each chunk to the row's output: the row
     * carries the burst's newest text on one `writing:` trail line, so
     * liveRuns() lists it mid-stream instead of treating it as silent until
     * it settles.
     */
    public function testAProseOnlyRunIsLiveWhileItStreams(): void
    {
        $manager = self::sessionManager([]);
        $provider = new ScriptedProvider([
            [
                new CompleteResponse(content: 'the first part '),
                new CompleteResponse(content: 'the second part'),
            ],
        ], streams: true);
        $now = 0.0;
        $seen = [];
        $task = (new TaskTool($manager, suspended: new SuspendedDelegations($this->storeDir)))
            ->withEngine(EngineBackend::new($provider, 'm'))
            ->withSubAgentEmitter(static function (SubAgentActivity $activity) use (&$manager, &$seen): void {
                if ($activity->op === SubAgentActivity::OP_PROGRESS) {
                    $seen[] = array_map(static fn (SubAgent $run): string => $run->output, $manager->liveRuns());
                }
            })
            ->withActivityClock(static function () use (&$now): float {
                return $now += 1.0;
            });

        $result = $task->execute(self::delegation());

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame([
            ['writing: the first part'],
            ['writing: the first part the second part'],
        ], $seen, 'each chunk reaches the row while the run still streams, on one trail line');
        $this->assertSame([], $manager->liveRuns(), 'the settled run leaves the live set');
        $this->assertSame('the first part the second part', $manager->runs()[0]->output, 'the report still wins at settle');
    }

    /**
     * The prose bound cuts bytes, so a multibyte character can straddle the
     * cut. When the kept window collapses under the trail cap, tailClip()
     * leaves it as is, so the stray continuation byte must be shed before:
     * the `writing:` line stays valid UTF-8 and JSON-encodable.
     */
    public function testTheProseBoundNeverSplitsACodepointOntoTheTrailLine(): void
    {
        $manager = self::sessionManager([]);
        // 400 + 2 + 317 + 2 = 721 bytes: past the 640-byte bound, and the
        // newest 320 bytes start on the second byte of the 'é'.
        $content = str_repeat('x', 400) . 'é' . str_repeat(' ', 317) . 'ok';
        $provider = new ScriptedProvider([
            [new CompleteResponse(content: $content)],
        ], streams: true);
        $now = 0.0;
        $seen = [];
        $task = (new TaskTool($manager, suspended: new SuspendedDelegations($this->storeDir)))
            ->withEngine(EngineBackend::new($provider, 'm'))
            ->withSubAgentEmitter(static function (SubAgentActivity $activity) use (&$manager, &$seen): void {
                if ($activity->op === SubAgentActivity::OP_PROGRESS) {
                    foreach ($manager->liveRuns() as $run) {
                        $seen[] = $run->output;
                    }
                }
            })
            ->withActivityClock(static function () use (&$now): float {
                return $now += 1.0;
            });

        $result = $task->execute(self::delegation());

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame(['writing: ok'], $seen, 'the split codepoint is shed, not shown');
        foreach ($seen as $output) {
            $this->assertSame(1, preg_match('//u', $output), 'the trail line is valid UTF-8');
            $this->assertNotFalse(json_encode(['output' => $output]));
        }
    }

    /**
     * A forked skill's run is wrapped the same way: SkillTool hands a
     * `context: fork` skill to TaskTool::runForkedSkill(), and a throw from
     * outside that run's turn (here its activity emitter) must still leave
     * the row terminal with an end of span, out of liveRuns(), and its card
     * off RUNNING. A row that already settled keeps its own outcome.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function throwingFramesForAForkedSkill(): array
    {
        return [
            'before the run settles' => [SubAgentActivity::OP_STARTED, SubAgent::STATUS_FAILED, SubAgentActivity::OUTCOME_FAILED],
            'after the run settled' => [SubAgentActivity::OP_FINISHED, SubAgent::STATUS_COMPLETE, SubAgentActivity::OUTCOME_COMPLETE],
        ];
    }

    #[DataProvider('throwingFramesForAForkedSkill')]
    public function testAThrowOutsideAForkedSkillsTurnLeavesTheRowTerminal(string $throwOn, string $expectedStatus, string $expectedCardStatus): void
    {
        $session = 'merge-' . bin2hex(random_bytes(5));
        $manager = self::sessionManager([]);
        $task = (new TaskTool($manager, suspended: new SuspendedDelegations($this->storeDir)))
            ->withEngine(EngineBackend::new(new ScriptedProvider([new CompleteResponse(content: 'reported')]), 'm')->withSessionId($session))
            ->withSubAgentEmitter(static function (SubAgentActivity $activity) use ($throwOn): void {
                if ($activity->op === $throwOn) {
                    throw new \RuntimeException('activity socket closed');
                }
            });
        $skillTool = (new SkillTool($this->forkSkillRegistry()))->withForkDelegate($task);

        $caught = null;
        try {
            $skillTool->execute(['name' => 'fork-check', 'id' => 'skill-call']);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'the emitter failure must still reach the SkillTool caller');
        $this->assertSame('activity socket closed', $caught->getMessage());

        $runs = $manager->runs();
        $this->assertCount(1, $runs, 'the fork ran as a delegation on the session manager');
        $row = $runs[0];
        $this->assertSame('checker', $row->agent->name, 'the skill\'s agent: preset carried the run');
        $this->assertSame($expectedStatus, $row->status);
        $this->assertNotNull($row->completedAt, 'a terminal row has an end of span, so elapsed time stops counting');
        $this->assertFalse($row->isRunning());
        $this->assertSame([], $manager->liveRuns(), 'a dead forked run is not listed as live');
        if ($expectedStatus === SubAgent::STATUS_FAILED) {
            $this->assertSame('activity socket closed', $row->error);
        } else {
            $this->assertNull($row->error, 'a truthful COMPLETE is not rewritten by a later throw');
        }

        $cards = AgentRunCards::runs($session);
        $this->assertCount(1, $cards, 'the forked run recorded its card before the throw');
        $this->assertSame($row->id, $cards[0]['runId']);
        $this->assertSame($expectedCardStatus, $cards[0]['status']);
        $this->assertSame($expectedCardStatus, AgentRunCards::statusOf($cards[0]), 'a live pid no longer reads the dead run as running');
    }

    /**
     * @return array<string, string>
     */
    private static function delegation(): array
    {
        return ['description' => 'Merge check', 'prompt' => 'Check the tree', 'agent' => 'checker'];
    }

    /**
     * A registry holding one `context: fork` skill read from a real SKILL.md,
     * routed to the `checker` preset the session roster carries — the shape
     * SkillTool's fork branch reads before it calls runForkedSkill().
     */
    private function forkSkillRegistry(): SkillRegistry
    {
        $this->skillRoot = sys_get_temp_dir() . '/sc_task_merge_skill_' . bin2hex(random_bytes(6));
        $dir = $this->skillRoot . '/skills/fork-check';
        $this->assertTrue(mkdir($dir, 0o700, true));
        file_put_contents(
            $dir . '/SKILL.md',
            "---\nname: fork-check\ndescription: Fork check\ncontext: fork\nagent: checker\n---\n\nCheck the tree.\n",
        );

        $registry = new SkillRegistry();
        $registry->register(['fork-check' => Skill::fromFile($dir . '/SKILL.md')]);

        return $registry;
    }

    /**
     * @param list<Tool> $registry
     */
    private static function sessionManager(array $registry): AgentManager
    {
        $manager = new AgentManager(
            new ScriptedProvider([]),
            new SkillRegistry(),
            toolRegistry: $registry,
            toolUniverse: $registry,
        );
        $manager->register(RosterAgent::named('checker'));

        return $manager;
    }

    /**
     * @param \Closure(): void $onCall
     */
    private static function statusProbe(\Closure $onCall): Tool
    {
        return new class ($onCall) implements Tool {
            public function __construct(private \Closure $onCall)
            {
            }

            public function name(): string
            {
                return 'StatusProbe';
            }

            public function description(): string
            {
                return 'reads the delegated row while the run is under way';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                ($this->onCall)();

                return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: 'probed');
            }
        };
    }
}

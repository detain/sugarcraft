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
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\RosterAgent;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
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
 * recorded in the CC1C-c1 commit message and is not re-pinned here.
 */
final class TaskToolExecuteMergeTest extends TestCase
{
    private string $storeDir;

    protected function setUp(): void
    {
        $this->storeDir = sys_get_temp_dir() . '/sc_task_merge_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
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
     * @return array<string, string>
     */
    private static function delegation(): array
    {
        return ['description' => 'Merge check', 'prompt' => 'Check the tree', 'agent' => 'checker'];
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

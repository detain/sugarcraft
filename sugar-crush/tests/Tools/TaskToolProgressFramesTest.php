<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\SuspendedDelegations;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;

/**
 * The Task tool's VISIBLE-RUN path: per-chunk activity lands on the row's
 * rolling output inside the callbacks (not only at terminal settle), and a
 * per-turn emitter receives the started/progress/finished beat sequence the
 * parent dashboard projects. The defect this pins was two-part — discarded
 * onEvent/onReasoning chunks AND a report written only at settle, which
 * active()/liveOutputs() filter out — so a delegation looked dead from the
 * first frame to the last.
 *
 * Harness fixtures (probe/manager/call) are byte-identical copies of the
 * TaskToolEngineTest helpers: DuplicatedTestHelperDriftTest tolerates
 * exact copies, punishes near-copies — divergence here would be drift.
 */
final class TaskToolProgressFramesTest extends TestCase
{
    private string $storeDir;

    private SuspendedDelegations $store;

    protected function setUp(): void
    {
        $this->storeDir = sys_get_temp_dir() . '/sc_task_frames_' . bin2hex(random_bytes(6));
        $this->store = new SuspendedDelegations($this->storeDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storeDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storeDir);
    }

    public function testTheBeatSequenceIsStartedToolProgressProgressFinishedInRisingSeqs(): void
    {
        $probe = self::probe('probe');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'probe', [])]),
            new CompleteResponse(content: 'the report'),
        ]);
        $manager = self::manager([$probe], RosterAgent::named('coder', ['probe'], maxTurns: 5));
        $engine = EngineBackend::new($provider, 'm')->withTools([$probe, new TaskTool($manager)]);

        /** @var list<SubAgentActivity> $frames */
        $frames = [];
        $emitter = static function (SubAgentActivity $activity) use (&$frames): void {
            $frames[] = $activity;
        };

        $result = (new TaskTool($manager))->withEngine($engine, null, $emitter)->withActivityClock(self::eagerClock())->execute(self::call());

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame(
            ['started', 'progress', 'progress', 'progress', 'finished'],
            array_map(static fn (SubAgentActivity $f): string => $f->op, $frames),
            'the tool boundaries and the report\'s prose beat out immediately; the run opens and closes exactly once',
        );
        $this->assertSame([1, 2, 3, 4, 5], array_map(static fn (SubAgentActivity $f): int => $f->seq, $frames));

        [$started, $toolIn, $toolOut, $prose, $finished] = $frames;
        $this->assertSame(
            [['t' => 'text', 'delta' => 'the report']],
            array_map(static fn (\SugarCraft\Crush\Agents\Live\ActivityItem $i): array => $i->toArray(), $prose->items),
            'P-B2: the run\'s prose rides as a text item, for the parent\'s live line',
        );
        $this->assertSame('coder', $started->name);
        $this->assertSame('Audit candy-core and report the findings', $started->task, 'started carries the prompt snippet');
        $this->assertSame('', $started->tail, 'nothing has been produced at the open beat');
        $this->assertStringStartsWith('subagent_' . getmypid() . '_', $started->id, 'the id is pid-namespaced (E329)');
        foreach ($frames as $frame) {
            $this->assertSame($started->id, $frame->id, 'one run, one key, every frame');
        }
        $this->assertSame('-> probe()', $toolIn->tail, 'the call is described, as the transcript describes it');
        $this->assertSame('', $toolIn->task, 'only started carries the task; later frames stay cheap');
        $this->assertStringContainsString('<- probe', $toolOut->tail, 'the trail accumulates');
        $this->assertSame('the report', $finished->tail, 'the terminal tail carries the report itself');
    }

    public function testAFailedRunKeepsItsTrailOnTheRowInsteadOfGoingBlank(): void
    {
        $probe = self::probe('probe');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'probe', [])]),
            new \RuntimeException('boom'),
        ]);
        $manager = self::manager([$probe], RosterAgent::named('coder', ['probe'], maxTurns: 5));
        $engine = EngineBackend::new($provider, 'm')->withTools([$probe]);

        $result = (new TaskTool($manager, suspended: $this->store))->withEngine($engine, null, static function (): void {})->execute(self::call());

        $this->assertTrue($result->isError());
        $rows = $manager->subAgentsOf('coder');
        $this->assertCount(1, $rows);
        $this->assertSame(SubAgent::STATUS_FAILED, $rows[0]->status);
        $this->assertStringContainsString('-> probe', $rows[0]->output, 'what the run GOT THROUGH survives the failure');
    }

    public function testAStepCapRefusalStillLeavesTheTrailAsTheRowsStory(): void
    {
        $probe = self::probe('probe');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'probe', [])]),
        ]);
        $manager = self::manager([$probe], RosterAgent::named('coder', ['probe'], maxTurns: 1));
        $engine = EngineBackend::new($provider, 'm')->withTools([$probe]);

        $result = (new TaskTool($manager, suspended: $this->store))->withEngine($engine, null, static function (): void {})->execute(self::call());

        $this->assertTrue($result->isError(), 'no report means a refusal');
        $rows = $manager->subAgentsOf('coder');
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('-> probe', $rows[0]->output, 'the empty-report settle keeps the trail, not an empty string');
    }

    public function testAppendActivityEvictsWholeLinesFromTheHead(): void
    {
        $method = new \ReflectionMethod(TaskTool::class, 'appendActivity');
        $method->setAccessible(true);

        $grown = '';
        for ($i = 1; $i <= 2000; $i++) {
            $grown = $method->invoke(null, $grown, 'line-' . $i);
        }

        $this->assertLessThanOrEqual(TaskTool::ACTIVITY_TAIL_BYTES, strlen($grown));
        $lines = explode("\n", $grown);
        $this->assertSame('line-2000', end($lines), 'the newest line always survives');
        $this->assertSame(1, preg_match('/^line-\d+$/', $lines[0]), 'the oldest survivor is WHOLE — eviction cuts at line boundaries');
        $this->assertGreaterThanOrEqual(2, count($lines), 'more than one line fits the cap — eviction is not just clipping');
    }

    public function testTailClipNeverSplitsAUtfEightCodepoint(): void
    {
        $method = new \ReflectionMethod(TaskTool::class, 'tailClip');
        $method->setAccessible(true);

        $fire = str_repeat('🔥', 40);
        $clipped = $method->invoke(null, $fire, 30);

        $this->assertSame(1, preg_match('//u', $clipped), 'a clipped tail must be valid UTF-8 or the frame breaks the JSON/serialize boundary downstream');
        $this->assertStringStartsWith('…', $clipped, 'a clipped tail announces itself');
        $this->assertLessThanOrEqual(30, strlen($clipped), 'the cap is a promise: marker bytes are reserved INSIDE it, not bolted on');
        $this->assertSame(1, preg_match('/^…(🔥+)$/u', $clipped), 'the kept tail is whole codepoints taken off the END');

        $this->assertSame('', $method->invoke(null, $fire, 2), 'a cap below the ellipsis itself carries nothing displayable');

        $this->assertSame('short', $method->invoke(null, 'short', 30), 'under the cap is the identity');

        $snip = new \ReflectionMethod(TaskTool::class, 'snippet');
        $snip->setAccessible(true);
        $clippedTask = $snip->invoke(null, str_repeat('x', 100), 10);
        $this->assertSame(10, strlen($clippedTask), 'the head clip honours the same promise as the tail clip');
        $this->assertStringEndsWith('…', $clippedTask);
    }

    public function testAnUnboundEmitterStillCompletesTheDelegation(): void
    {
        $probe = self::probe('probe');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'probe', [])]),
            new CompleteResponse(content: 'the report'),
        ]);
        $manager = self::manager([$probe], RosterAgent::named('coder', ['probe'], maxTurns: 5));
        $engine = EngineBackend::new($provider, 'm')->withTools([$probe, new TaskTool($manager)]);

        $result = (new TaskTool($manager))->withEngine($engine)->execute(self::call());

        $this->assertFalse($result->isError(), $result->content());
        $rows = $manager->subAgentsOf('coder');
        $this->assertSame(SubAgent::STATUS_COMPLETE, $rows[0]->status);
        $this->assertSame('the report', $rows[0]->output);
    }

    /**
     * The row counts tokens while the run works, not only once it settles:
     * each beat carries the run's running total, and the finished beat the
     * settled one — the same figure the row ends on, counted once.
     */
    public function testBeatsCarryTheRunsRunningTokenTotal(): void
    {
        $probe = self::probe('probe');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'probe', [])], tokensUsed: 300),
            new CompleteResponse(content: 'the report', tokensUsed: 200),
        ]);
        $manager = self::manager([$probe], RosterAgent::named('coder', ['probe'], maxTurns: 5));
        $engine = EngineBackend::new($provider, 'm')->withTools([$probe, new TaskTool($manager)]);

        /** @var list<SubAgentActivity> $frames */
        $frames = [];
        $emitter = static function (SubAgentActivity $activity) use (&$frames): void {
            $frames[] = $activity;
        };

        $result = (new TaskTool($manager))->withEngine($engine, null, $emitter)->withActivityClock(self::eagerClock())->execute(self::call());

        $this->assertFalse($result->isError(), $result->content());
        $tokens = array_map(static fn (SubAgentActivity $f): int => $f->tokensUsed, $frames);
        $this->assertSame(0, $tokens[0], 'nothing is billed at the open beat');
        $this->assertSame(300, $tokens[1], 'the first step is counted before its tool runs');
        $this->assertSame(500, $tokens[count($tokens) - 1], 'the finished beat carries the settled total');
        $this->assertSame(500, $manager->subAgentsOf('coder')[0]->tokensUsed, 'the live count is replaced, never added to');
        $this->assertSame(200, $frames[count($frames) - 1]->contextTokens, 'the current context is the latest request, not the total');
        $this->assertSame('m', $frames[0]->model, 'the run names the model it really runs on — the engine\'s');
        $this->assertSame('m', $manager->subAgentsOf('coder')[0]->model());
        $this->assertSame(2, $frames[count($frames) - 1]->lines, 'the trail line count rides the finished beat: one call in, one out');
    }

    /**
     * A tool call in the trail says what it did, the way the transcript row
     * does — the model's description, or the argument dump — not just which
     * tool ran.
     */
    public function testTrailLinesDescribeTheCall(): void
    {
        $probe = self::probe('probe');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [
                new ToolCall('call_1', 'probe', ['path' => 'src/Foo.php']),
                new ToolCall('call_2', 'probe', ['description' => 'Look at the config']),
            ]),
            new CompleteResponse(content: 'the report'),
        ]);
        $manager = self::manager([$probe], RosterAgent::named('coder', ['probe'], maxTurns: 5));
        $engine = EngineBackend::new($provider, 'm')->withTools([$probe, new TaskTool($manager)]);

        $tails = [];
        $emitter = static function (SubAgentActivity $activity) use (&$tails): void {
            $tails[] = $activity->tail;
        };
        (new TaskTool($manager))->withEngine($engine, null, $emitter)->withActivityClock(self::eagerClock())->execute(self::call());

        $trail = implode("\n", $tails);
        $this->assertStringContainsString('-> probe(path: "src/Foo.php")', $trail);
        $this->assertStringContainsString('-> probe: Look at the config', $trail);

        // The same calls, structured, for the Tools pane: each settled ok.
        $calls = $manager->subAgentsOf('coder')[0]->recentCalls;
        $this->assertSame(['probe(path: "src/Foo.php")', 'probe: Look at the config'], array_column($calls, 'label'));
        $this->assertSame([SubAgentActivity::CALL_OK, SubAgentActivity::CALL_OK], array_column($calls, 'state'));
    }

    /**
     * A long thought is folded into several trail lines, but it is ONE
     * thought: the label goes on its first line only, and a tool boundary
     * opens the next thought under a fresh label.
     */
    public function testALongThoughtIsLabelledOnceAndAToolBoundaryReopensTheLabel(): void
    {
        $probe = self::probe('probe');
        // A streamed thought arrives in chunks and is folded every
        // THINK_LINE_BYTES, so one burst becomes several trail lines.
        $burst = static fn (string $call): array => [
            ...array_fill(0, 4, new CompleteResponse(content: '', reasoning: str_repeat('weighing auth ', 7))),
            new CompleteResponse(content: '', toolCalls: [new ToolCall($call, 'probe', [])]),
        ];
        $provider = new ScriptedProvider([
            $burst('call_1'),
            $burst('call_2'),
            [new CompleteResponse(content: 'the report')],
        ], streams: true);
        $manager = self::manager([$probe], RosterAgent::named('coder', ['probe'], maxTurns: 5));
        $engine = EngineBackend::new($provider, 'm')->withTools([$probe, new TaskTool($manager)]);

        $trail = [];
        $emitter = static function (SubAgentActivity $a) use (&$trail): void {
            if ($a->op === SubAgentActivity::OP_PROGRESS) {
                $trail = explode("\n", $a->tail);
            }
        };
        (new TaskTool($manager))->withEngine($engine, null, $emitter)->withActivityClock(self::eagerClock())->execute(self::call());

        $labelled = array_values(array_filter($trail, static fn (string $l): bool => str_starts_with($l, 'thinking: ')));
        $continued = array_values(array_filter($trail, static fn (string $l): bool => str_starts_with($l, '  ')));
        $this->assertCount(2, $labelled, 'one label per thought — two thoughts, split by a tool call');
        $this->assertNotEmpty($continued, 'a long thought continues under its label, unlabelled');
    }

    /**
     * The relay seam Runtime uses for a forked sibling: the copy reports
     * through the relay's emitter while the run it delegates — engine,
     * manager, parallel-safety — is untouched, and the original keeps its own.
     */
    public function testWithSubAgentEmitterRebindsOnlyTheEmitter(): void
    {
        $manager = self::manager([self::probe('probe')], RosterAgent::named('coder', ['probe'], maxTurns: 5));
        $engine = EngineBackend::new(new ScriptedProvider([]), 'm');
        $bound = static function (SubAgentActivity $beat): void {};
        $relayed = static function (SubAgentActivity $beat): void {};

        $tool = (new TaskTool($manager))->withEngine($engine, null, $bound);
        $copy = $tool->withSubAgentEmitter($relayed);

        $this->assertSame($bound, $tool->subAgentEmitter());
        $this->assertSame($relayed, $copy->subAgentEmitter());
        $this->assertTrue($copy->isParallelSafe(), 'the copy still carries the engine and manager');
        $this->assertNull((new TaskTool($manager))->subAgentEmitter(), 'an unbound tool has nothing to relay');
    }

    /**
     * The StreamsActivity seam a forked member runs: every beat of the run
     * goes to the sink, none to the emitter the original was bound with.
     */
    public function testWithActivitySinkRoutesEveryBeatToTheSink(): void
    {
        $probe = self::probe('probe');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'probe', [])]),
            new CompleteResponse(content: 'the report'),
        ]);
        $manager = self::manager([$probe], RosterAgent::named('coder', ['probe'], maxTurns: 5));
        $engine = EngineBackend::new($provider, 'm')->withTools([$probe]);
        $bound = [];
        $sink = new class implements \SugarCraft\Crush\Tools\ActivitySink {
            /** @var list<SubAgentActivity> */
            public array $beats = [];

            public function emit(SubAgentActivity $activity): void
            {
                $this->beats[] = $activity;
            }
        };

        $tool = (new TaskTool($manager))->withEngine($engine, null, static function (SubAgentActivity $beat) use (&$bound): void {
            $bound[] = $beat;
        });
        $result = $tool->withActivitySink($sink)->execute(self::call());

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame([], $bound, 'the turn-pinned emitter is never asked to write');
        $this->assertSame(SubAgentActivity::OP_STARTED, $sink->beats[0]->op);
        $this->assertSame(SubAgentActivity::OP_FINISHED, end($sink->beats)->op);
    }

    /**
     * P-B1: with the buffer's clock frozen nothing is ever due, so every item
     * the run produced rides its finished frame — coalesced, with the v2
     * identity, stats and the real outcome.
     */
    public function testAFrozenClockCoalescesTheRunsItemsIntoItsFinishedFrame(): void
    {
        $probe = self::probe('probe');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'Grep', ['pattern' => 'Login', 'path' => 'src/'])], tokensUsed: 300),
            new CompleteResponse(content: 'the report', tokensUsed: 200),
        ]);
        $grep = self::probe('Grep');
        $manager = self::manager([$probe, $grep], RosterAgent::named('coder', ['probe', 'Grep'], maxTurns: 5));
        $engine = EngineBackend::new($provider, 'm')->withTools([$probe, $grep]);

        /** @var list<SubAgentActivity> $frames */
        $frames = [];
        $result = (new TaskTool($manager, suspended: $this->store))
            ->withEngine($engine, null, static function (SubAgentActivity $a) use (&$frames): void {
                $frames[] = $a;
            })
            ->withActivityClock(static fn (): float => 100.0)
            ->execute(self::call(['id' => 'tc_parent']));

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame(['started', 'finished'], array_map(static fn (SubAgentActivity $f): string => $f->op, $frames));
        [$started, $finished] = $frames;

        foreach ($frames as $frame) {
            $this->assertSame('tc_parent', $frame->parentCallId, 'every frame names the Task row it belongs under');
            $this->assertSame('Audit candy-core', $frame->description);
        }
        $this->assertSame([], $started->items);
        $this->assertSame(
            [
                ['t' => 'tool_started', 'callId' => 'call_1', 'tool' => 'Grep', 'summary' => '"Login" src/'],
                ['t' => 'tool_finished', 'callId' => 'call_1', 'tool' => 'Grep', 'ok' => true, 'ms' => $finished->items[1]->ms],
                // P-B2: the report's prose, coalesced into the same frame.
                ['t' => 'text', 'delta' => 'the report'],
            ],
            array_map(static fn (\SugarCraft\Crush\Agents\Live\ActivityItem $i): array => $i->toArray(), $finished->items),
        );
        $this->assertSame(2, $finished->stats['step']);
        $this->assertSame(5, $finished->stats['maxSteps']);
        $this->assertSame(1, $finished->stats['tools']);
        $this->assertGreaterThan(0.0, $finished->stats['startedAt']);
        $this->assertSame(SubAgentActivity::OUTCOME_COMPLETE, $finished->outcome);
        $this->assertNull($finished->error);
        $this->assertNotNull($finished->resumeId, 'the finished frame names the id the run resumes by');
        $this->assertStringContainsString((string) $finished->resumeId, $result->content());
    }

    public function testAFailedRunFinishesWithAFailedOutcomeAndItsReason(): void
    {
        $probe = self::probe('probe');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'probe', [])]),
            new \RuntimeException('boom'),
        ]);
        $manager = self::manager([$probe], RosterAgent::named('coder', ['probe'], maxTurns: 5));
        $engine = EngineBackend::new($provider, 'm')->withTools([$probe]);

        /** @var list<SubAgentActivity> $frames */
        $frames = [];
        (new TaskTool($manager, suspended: $this->store))
            ->withEngine($engine, null, static function (SubAgentActivity $a) use (&$frames): void {
                $frames[] = $a;
            })
            ->execute(self::call());

        $finished = end($frames);
        $this->assertSame(SubAgentActivity::OP_FINISHED, $finished->op);
        $this->assertSame(SubAgentActivity::OUTCOME_FAILED, $finished->outcome, 'a failed run no longer reads as complete');
        $this->assertStringContainsString('boom', (string) $finished->error);
        $this->assertNotNull($finished->resumeId);
    }

    public function testAQueuedMembersPlaceholderBeatNamesItsAgentAndParentCall(): void
    {
        $manager = self::manager([self::probe('probe')], RosterAgent::named('coder', ['probe'], maxTurns: 5));

        $beat = (new TaskTool($manager))->queuedActivity(new ToolCall('tc_9', 'Task', []), self::call());

        $this->assertNotNull($beat);
        $this->assertSame(SubAgentActivity::OP_QUEUED, $beat->op);
        $this->assertSame(SubAgentActivity::queuedId('tc_9'), $beat->id);
        $this->assertSame('coder', $beat->name);
        $this->assertSame('tc_9', $beat->parentCallId);
        $this->assertSame('Audit candy-core', $beat->description);
        $this->assertSame('Audit candy-core and report the findings', $beat->task);
        $this->assertNull((new TaskTool($manager))->queuedActivity(new ToolCall('tc_9', 'Task', []), ['prompt' => 'x']), 'no agent, no row');
    }

    /**
     * A clock that reads one second later on every call: every item is due
     * the moment it is added, which is the one-frame-per-boundary pacing the
     * older assertions in this file were written against.
     *
     * @return \Closure(): float
     */
    private static function eagerClock(): \Closure
    {
        $now = 0.0;

        return static function () use (&$now): float {
            return $now += 1.0;
        };
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private static function call(array $overrides = []): array
    {
        return $overrides + [
            'description' => 'Audit candy-core',
            'prompt' => 'Audit candy-core and report the findings',
            'agent' => 'coder',
        ];
    }

    /**
     * @param list<Tool> $registry
     */
    private static function manager(array $registry, Agent $agent): AgentManager
    {
        $manager = new AgentManager(
            new ScriptedProvider([]),
            new SkillRegistry(),
            toolRegistry: $registry === [] ? null : $registry,
            toolUniverse: $registry === [] ? null : $registry,
        );
        $manager->register($agent);

        return $manager;
    }

    /**
     * @return Tool&object{calls: list<array<string, mixed>>}
     */
    private static function probe(string $name): Tool
    {
        return new class ($name) implements Tool {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function __construct(private string $name)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'records its calls';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                $id = (string) ($args['id'] ?? '');
                unset($args['id']);
                $this->calls[] = $args;

                return new ToolResult(toolCallId: $id, content: $this->name . ' ok');
            }
        };
    }
}

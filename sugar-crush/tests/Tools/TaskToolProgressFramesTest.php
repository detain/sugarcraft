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

        $result = (new TaskTool($manager))->withEngine($engine, null, $emitter)->execute(self::call());

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame(
            ['started', 'progress', 'progress', 'finished'],
            array_map(static fn (SubAgentActivity $f): string => $f->op, $frames),
            'the tool boundaries beat out immediately; the run opens and closes exactly once',
        );
        $this->assertSame([1, 2, 3, 4], array_map(static fn (SubAgentActivity $f): int => $f->seq, $frames));

        [$started, $toolIn, $toolOut, $finished] = $frames;
        $this->assertSame('coder', $started->name);
        $this->assertSame('Audit candy-core and report the findings', $started->task, 'started carries the prompt snippet');
        $this->assertSame('', $started->tail, 'nothing has been produced at the open beat');
        $this->assertStringStartsWith('subagent_' . getmypid() . '_', $started->id, 'the id is pid-namespaced (E329)');
        foreach ($frames as $frame) {
            $this->assertSame($started->id, $frame->id, 'one run, one key, every frame');
        }
        $this->assertSame('-> probe', $toolIn->tail);
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

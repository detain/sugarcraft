<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use SugarCraft\Code\Agents\AgentResult;
use SugarCraft\Code\Agents\AgentStatus;
use SugarCraft\Code\Agents\AgentWorkerPool;
use SugarCraft\Code\Agents\ExecutorInterface;
use SugarCraft\Code\Agents\SubAgent;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookDispatcher;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookInterface;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Workflows\WorkflowBuilder;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowEngineInterface;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowResult;
use SugarCraft\Code\Workflows\WorkflowStatus;
use SugarCraft\Code\Workflows\Tasks;
use PHPUnit\Framework\TestCase;

/**
 * C6: the engine's stage-lifecycle announcements, traced end to end.
 *
 * Every hook lands as a real HookDispatchResult produced by a real
 * HookDispatcher over recording HookInterface fakes, so the tests police
 * the ORDER of events, the payload each carries, and — the adjudications
 * of record — which moments deliberately raise NOTHING:
 *
 *  - an unarmed engine (null dispatcher) answers byte-identically to its
 *    armed twin: zero behavior change for every host that wires nothing;
 *  - a skipped stage raises ONLY StageComplete with status 'skipped' and
 *    round 0 (no round ever ran) — never StageStarted;
 *  - a cache-carried stage replay announces NOTHING (no dispatch
 *    happened), while the replaying run still announces its terminal;
 *  - Paused and Cancelled runs raise no WorkflowComplete: both are
 *    resumable surfaces, and a terminal that fires twice per logical run
 *    (pause, then resume-complete) would double-count every consumer;
 *  - a failed stage raises exactly ONE StageFail — never also
 *    StageComplete — and the Failed run still reaches its terminal.
 *
 * The nested-include test pins the hierarchy each run announces at the
 * single choke: the inner run completes first, the outer second.
 */
final class WorkflowStageHookEventsTest extends TestCase
{
    /** @var list<string> directories to sweep in tearDown */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->wf6RemoveTree($dir);
        }
        $this->tempDirs = [];
        parent::tearDown();
    }

    public function testASimpleRunTracesStartedThenCompletePerStageAndTheTerminalOnce(): void
    {
        $trace = new \ArrayObject();
        $registry = new WorkflowRegistry();
        $registry->register($this->wf6RegisterTwo($registry));
        $engine = $this->wf6Engine($registry, $this->wf6RecorderDispatcher($trace));

        $result = $engine->run('two');

        self::assertSame('completed', $result->status->value);
        self::assertSame(
            ['StageStarted', 'StageComplete', 'StageStarted', 'StageComplete', 'WorkflowComplete'],
            $this->wf6EventNames($trace),
        );

        [$firstStart, $firstDone, $secondStart, $secondDone, $terminal] = $trace->getArrayCopy();

        self::assertSame(
            ['workflow', 'stage', 'round'],
            array_keys($firstStart['payload']),
            'a StageStarted payload carries exactly the workflow, stage and round',
        );
        self::assertStringStartsWith(
            'two-',
            $firstStart['payload']['workflow'],
            'run identity carries the per-run stamp the engine mints',
        );
        $runId = $firstStart['payload']['workflow'];
        foreach ($trace as $frame) {
            self::assertSame($runId, $frame['payload']['workflow'], 'one run raises one identity on every frame');
        }
        self::assertSame('stage-1', $firstStart['payload']['stage']);
        self::assertSame(1, $firstStart['payload']['round'], 'a plain stage settles on round 1');

        self::assertSame(
            ['workflow', 'stage', 'status', 'round'],
            array_keys($firstDone['payload']),
            'the complete frame carries stage, verdict and settling round',
        );
        self::assertSame('completed', $firstDone['payload']['status']);

        self::assertSame('stage-2', $secondStart['payload']['stage']);
        self::assertSame('completed', $secondDone['payload']['status']);

        self::assertSame(
            ['workflow', 'status', 'stages', 'totalTokens', 'totalCost'],
            array_keys($terminal['payload']),
            'the terminal carries the run totals, not per-stage fields',
        );
        self::assertSame('completed', $terminal['payload']['status']);
        self::assertSame(2, $terminal['payload']['stages']);
        self::assertSame(300, $terminal['payload']['totalTokens']);
        self::assertEqualsWithDelta(0.03, $terminal['payload']['totalCost'], 1e-9);
    }

    public function testAnUnarmedEngineAnswersByteIdenticallyToItsArmedTwin(): void
    {
        // The constructor default IS the null dispatcher: an unarmed engine
        // must not merely "work" — it must decide identically to the same
        // engine armed with a dispatcher nobody subscribed to, and that
        // armed-with-nothing run must itself raise zero observable verdict
        // differences. This is the zero-behavior-change pin for every host
        // that never passes the new argument (Bootstrap's TUI seat today).
        $registry = new WorkflowRegistry();
        $this->wf6RegisterTwo($registry);

        $silent = new HookDispatcher(new HookRegistry());

        $unarmedResult = $this->wf6Engine($registry)->run('two');
        $armedQuiet = $this->wf6Engine($registry, $silent)->run('two');

        self::assertSame($unarmedResult->status, $armedQuiet->status);
        self::assertSame(
            $this->wf6RowDump($unarmedResult),
            $this->wf6RowDump($armedQuiet),
            'stage rows are byte-identical armed-with-nothing vs unarmed',
        );
        self::assertSame($unarmedResult->totalTokens, $armedQuiet->totalTokens);
        self::assertSame($unarmedResult->totalCost, $armedQuiet->totalCost);
    }

    public function testALoopedStageAnnouncesEveryRoundButSettlesExactlyOnce(): void
    {
        $trace = new \ArrayObject();
        $calls = 0;
        $registry = new WorkflowRegistry();
        $registry->register((new WorkflowBuilder())
            ->name('spin')
            ->description('looped until DONE')
            ->stage('spin', Tasks::agent('coder')->prompt('work until DONE'))
            ->loop(['max' => 5, 'until' => ['stage' => 'spin', 'contains' => 'DONE']])
            ->build());

        $engine = $this->wf6Engine(
            $registry,
            $this->wf6RecorderDispatcher($trace),
            function (int $call) use (&$calls): AgentResult {
                ++$calls;

                return $this->wf6Result($call === 1 ? 'not yet' : 'DONE');
            },
        );

        $result = $engine->run('spin');

        self::assertSame('completed', $result->status->value);
        self::assertSame(2, $calls, 'the body ran for two rounds');
        self::assertSame(
            ['StageStarted', 'StageStarted', 'StageComplete', 'WorkflowComplete'],
            $this->wf6EventNames($trace),
            'every round announces, the stage settles once',
        );
        self::assertSame([1, 2], [
            $trace[0]['payload']['round'],
            $trace[1]['payload']['round'],
        ]);
        self::assertSame(2, $trace[2]['payload']['round'], 'the settle carries the FINAL round');
        self::assertSame('completed', $trace[2]['payload']['status']);
        self::assertSame(1, $trace[3]['payload']['stages'], 'one row settled after two rounds');
    }

    public function testASkippedStageRaisesOnlyTheCompleteWithRoundZeroAndNoStarted(): void
    {
        $trace = new \ArrayObject();
        $prompts = [];
        $registry = new WorkflowRegistry();
        $registry->register((new WorkflowBuilder())
            ->name('guarded')
            ->description('a when-clause that never lands')
            ->stage('gate', Tasks::agent('coder')->prompt('do the gate'))
            ->stage('maybe', Tasks::agent('coder')->prompt('do the maybe'))
            ->when(['stage' => 'gate', 'contains' => 'NEVER'])
            ->build());

        $engine = $this->wf6Engine(
            $registry,
            $this->wf6RecorderDispatcher($trace),
            function (int $call, string $prompt) use (&$prompts): AgentResult {
                $prompts[] = $prompt;

                return $this->wf6SealedResult($call, 'gate' === $prompt ? 'OUT[do the gate]' : 'x');
            },
        );

        $result = $engine->run('guarded');

        self::assertSame('completed', $result->status->value);
        self::assertSame(['do the gate'], $prompts, 'the skipped body never dispatched');
        self::assertSame(
            ['StageStarted', 'StageComplete', 'StageComplete', 'WorkflowComplete'],
            $this->wf6EventNames($trace),
            'a skip raises a bare StageComplete — no StageStarted precedes it',
        );

        $skip = $trace[2]['payload'];
        self::assertSame('maybe', $skip['stage']);
        self::assertSame('skipped', $skip['status']);
        self::assertSame(0, $skip['round'], 'round 0 is the marker that no round ever ran');
    }

    /**
     * The C13 cache door answers from the pause record's carried rows
     * (prior['recordedResults'] + stageInputHashes), NOT from a second
     * fresh run — a run never replays another run's cache. So the
     * carried-stage silence is pinned where the door actually fires: a
     * resume whose record already carries stage c's answer. Stage a lands
     * below the resume index (also silent), b is the only live dispatch.
     */
    public function testAResumeCarriedStageAnnouncesNothingWhileTheResumedRunTerminatesOnce(): void
    {
        $root = $this->wf6TempRoot();
        $registry = new WorkflowRegistry($root . '/workflows');
        $registry->register((new WorkflowBuilder())
            ->name('three')
            ->description('a then b then c')
            ->stage('a', Tasks::agent('coder')->prompt('do a'))
            ->stage('b', Tasks::agent('coder')->prompt('do b using {{a.output}}'))
            ->stage('c', Tasks::agent('coder')->prompt('do c using {{b.output}}'), cache: true)
            ->build());

        $this->wf6WritePausedRecord($root);

        $trace = new \ArrayObject();
        $calls = 0;
        $engine = $this->wf6Engine(
            $registry,
            $this->wf6RecorderDispatcher($trace),
            function (int $call) use (&$calls): AgentResult {
                ++$calls;

                return $this->wf6Result('FRESH[b]', 10, 0.5);
            },
            $root,
        );

        $resumed = $engine->resume('three');

        self::assertSame('completed', $resumed->status->value);
        self::assertSame(1, $calls, 'only the un-carried stage dispatched');
        self::assertSame(
            ['StageStarted', 'StageComplete', 'WorkflowComplete'],
            $this->wf6EventNames($trace),
            'a carried replay announces NOTHING per stage; the run still terminates once',
        );
        self::assertSame('b', $trace[0]['payload']['stage'], 'b is the only live dispatch');
        self::assertSame('CACHED[c]', $resumed->stageResults[2]->output, 'c rode the record, not a dispatch');
        self::assertSame(3, $trace[2]['payload']['stages'], 'all three rows settled into the terminal count');
        self::assertSame('completed', $trace[2]['payload']['status']);
        self::assertStringStartsWith('three-', $trace[2]['payload']['workflow']);
        self::assertSame(30, $trace[2]['payload']['totalTokens'], 'carried 20 + fresh 10');
        self::assertEqualsWithDelta(1.5, $trace[2]['payload']['totalCost'], 1e-9);
    }

    public function testAPausedRunRaisesNoTerminal(): void
    {
        $trace = new \ArrayObject();
        $registry = new WorkflowRegistry();
        $registry->register($this->wf6RegisterTwo($registry));

        $engine = null;
        $engine = $this->wf6Engine(
            $registry,
            $this->wf6RecorderDispatcher($trace),
            function (int $call, string $prompt) use (&$engine): AgentResult {
                $result = $this->wf6SealedResult($call, 'OUT[' . $prompt . ']');
                if ('do stage-1' === $prompt) {
                    self::assertInstanceOf(WorkflowEngine::class, $engine);
                    $engine->pause('two');
                }

                return $result;
            },
        );

        $result = $engine->run('two');

        self::assertSame('paused', $result->status->value);
        self::assertSame(
            ['StageStarted', 'StageComplete'],
            $this->wf6EventNames($trace),
            'a Paused run raises no WorkflowComplete — the resume run is the natural terminal',
        );
    }

    public function testACancelledRunRaisesNoTerminal(): void
    {
        $trace = new \ArrayObject();
        $registry = new WorkflowRegistry();
        $registry->register($this->wf6RegisterTwo($registry));

        $token = new CancellationToken();
        $engine = $this->wf6Engine(
            $registry,
            $this->wf6RecorderDispatcher($trace),
            function (int $call, string $prompt) use ($token): AgentResult {
                $result = $this->wf6SealedResult($call, 'OUT[' . $prompt . ']');
                if ('do stage-1' === $prompt) {
                    $token->cancel();
                }

                return $result;
            },
        );

        $result = $engine->run('two', [], $token);

        self::assertSame('cancelled', $result->status->value);
        self::assertSame(
            ['StageStarted', 'StageComplete'],
            $this->wf6EventNames($trace),
            'a Cancelled run raises no WorkflowComplete — consumers count completed and failed runs only',
        );
    }

    public function testARejectedVerdictRaisesStageFailOnceAndTheFailedTerminalOnce(): void
    {
        $trace = new \ArrayObject();
        $registry = new WorkflowRegistry();
        $registry->register((new WorkflowBuilder())
            ->name('checked')
            ->description('a reviewer that rejects')
            ->withVerification(
                'check',
                Tasks::agent('coder')->prompt('do the work'),
                Tasks::agent('reviewer')->prompt('review {{prevResult}}')->reject('/REJECT/'),
            )
            ->build());

        $engine = $this->wf6Engine(
            $registry,
            $this->wf6RecorderDispatcher($trace),
            function (int $call, string $prompt): AgentResult {
                // The worker answers first; the reviewer's rendered prompt
                // carries the verdict pattern (same routing the engine's own
                // verdict-rejection suite relies on).
                return $this->wf6SealedResult(
                    $call,
                    \str_contains($prompt, 'do the work') ? 'built' : 'REJECT: defect',
                );
            },
        );

        $result = $engine->run('checked');

        self::assertSame('failed', $result->status->value);
        self::assertSame(
            ['StageStarted', 'StageFail', 'WorkflowComplete'],
            $this->wf6EventNames($trace),
            'a failing stage raises exactly ONE StageFail and never also a StageComplete',
        );

        $fail = $trace[1]['payload'];
        self::assertSame('check', $fail['stage']);
        self::assertSame('failed', $fail['status']);
        self::assertStringContainsString('verdict rejected', $fail['error']);

        $terminal = $trace[2]['payload'];
        self::assertSame('failed', $terminal['status'], 'a Failed run IS a natural terminal');
    }

    public function testANestedIncludeAnnouncesItsOwnTerminalBeforeTheOuterOne(): void
    {
        $trace = new \ArrayObject();
        $registry = new WorkflowRegistry();
        $registry->register((new WorkflowBuilder())
            ->name('echo')
            ->description('inner')
            ->stage('say', Tasks::agent('coder')->prompt('say {{topic}}'))
            ->build());
        $registry->register((new WorkflowBuilder())
            ->name('wrap')
            ->description('outer')
            ->stage('pre', Tasks::agent('coder')->prompt('do the pre'))
            ->uses('body', 'echo', ['topic' => '{{pre.output}}'])
            ->build());

        $engine = $this->wf6Engine(
            $registry,
            $this->wf6RecorderDispatcher($trace),
            function (int $call, string $prompt): AgentResult {
                return $this->wf6SealedResult($call, 'OUT[' . $prompt . ']');
            },
        );

        $result = $engine->run('wrap');

        self::assertSame('completed', $result->status->value);
        self::assertSame(
            [
                'StageStarted', 'StageComplete',          // outer: pre
                'StageStarted',                            // outer: body (uses:)
                'StageStarted', 'StageComplete',           // inner: say
                'WorkflowComplete',                        // inner terminal FIRST
                'StageComplete',                           // outer: body settles
                'WorkflowComplete',                        // outer terminal
            ],
            $this->wf6EventNames($trace),
            'each run announces exactly one terminal at the choke, inner before outer',
        );

        $innerTerminal = $trace[5]['payload'];
        $outerTerminal = $trace[7]['payload'];
        self::assertNotSame($innerTerminal['workflow'], $outerTerminal['workflow']);
        self::assertStringStartsWith('echo-', $innerTerminal['workflow'], 'the inner run announces under its own stamped id');
        self::assertStringStartsWith('wrap-', $outerTerminal['workflow'], 'the outer run announces under its own stamped id');
        self::assertSame(1, $innerTerminal['stages'], 'the inner run carried its own single row');
        self::assertSame(2, $outerTerminal['stages'], 'the outer run folded pre + body into two rows');
    }

    /**
     * The TUI seat wires the session hook chain AFTER building the engine
     * (the workspace closes first), so armWorkflow() must produce exactly
     * the trace a constructor-armed twin produced.
     */
    public function testArmWorkflowBindsTheDispatcherOntoAnEngineBuiltUnarmed(): void
    {
        $trace = new \ArrayObject();
        $hooks = new HookManager($this->wf6RecorderRegistry($trace));

        $registry = new WorkflowRegistry();
        $registry->register($this->wf6RegisterTwo($registry));
        $engine = $this->wf6Engine($registry);
        $hooks->armWorkflow($engine);

        $result = $engine->run('two');

        self::assertSame('completed', $result->status->value);
        self::assertSame(
            ['StageStarted', 'StageComplete', 'StageStarted', 'StageComplete', 'WorkflowComplete'],
            $this->wf6EventNames($trace),
            'a post-build arm announces exactly what a constructor arm announced',
        );
    }

    /**
     * The CLI verb seat, headless hosts, and every interface double ride the
     * guard: null and non-concrete engines are refused silently, so no call
     * ever reaches the double's throwing surface.
     */
    public function testArmWorkflowLeavesNullAndForeignEnginesAlone(): void
    {
        $hooks = new HookManager(new HookRegistry());

        $hooks->armWorkflow(null);

        $double = new class implements WorkflowEngineInterface {
            public function run(string $workflowPath, array $context = [], ?CancellationToken $cancellation = null): WorkflowResult
            {
                throw new \LogicException('not reached here');
            }

            public function pause(string $workflowId): void
            {
                throw new \LogicException('not reached here');
            }

            public function resume(string $workflowId, ?CancellationToken $cancellation = null): WorkflowResult
            {
                throw new \LogicException('not reached here');
            }

            public function status(string $workflowId): WorkflowStatus
            {
                throw new \LogicException('not reached here');
            }

            public function listWorkflows(): array
            {
                throw new \LogicException('not reached here');
            }
        };

        $hooks->armWorkflow($double);

        // Reaching the end IS the assertion — a dropped instanceof guard
        // fatals on bindHookDispatcher(), the method the double lacks.
        self::addToAssertionCount(2);
    }

    /**
     * Structural wiring pins: the chat() seat carries the session arm line,
     * and the CLI verb seat never gains one — headless runs stay unarmed by
     * owner law, so the two build sites must not drift back together.
     */
    public function testTheChatSeatArmsTheSessionEngineAndTheCliVerbDoesNot(): void
    {
        $bootstrap = \file_get_contents(\dirname(__DIR__, 2) . '/src/Cli/Bootstrap.php');
        self::assertIsString($bootstrap);

        $seatStart = \strpos($bootstrap, '$workspace = \SugarCraft\Code\Host\WorkspaceContext::new(');
        self::assertIsInt($seatStart, 'the chat() workspace build site must keep its anchor');
        $chainStart = \strpos($bootstrap, '->withService(', $seatStart);
        self::assertIsInt($chainStart);
        self::assertStringContainsString(
            '$workspace->hooks?->armWorkflow($workspace->workflowEngine);',
            \substr($bootstrap, $seatStart, $chainStart - $seatStart),
            'the TUI seat hands the session hook chain to its workflow engine',
        );

        $subcommands = \file_get_contents(\dirname(__DIR__, 2) . '/src/Cli/Subcommands.php');
        self::assertIsString($subcommands);
        $verbStart = \strpos($subcommands, 'private static function workflowVerbEngine');
        self::assertIsInt($verbStart, 'the CLI verb seat must keep its anchor');
        $verbBody = \substr($subcommands, $verbStart, 900);
        self::assertStringContainsString(
            'bindEngineBackend($backend)',
            $verbBody,
            'the slice really reached the verb seat body',
        );
        self::assertStringNotContainsString(
            'armWorkflow',
            $verbBody,
            'the CLI verb seat stays unarmed — a headless run announces nothing',
        );
    }

    // -------------------------------------------------------------- helpers

    private function wf6TempRoot(): string
    {
        $dir = \sys_get_temp_dir() . '/wf6-c6-' . \bin2hex(\random_bytes(6));
        \mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function wf6RegisterTwo(WorkflowRegistry $registry): object
    {
        $workflow = (new WorkflowBuilder())
            ->name('two')
            ->description('two plain stages')
            ->stage('stage-1', Tasks::agent('coder')->prompt('do stage-1'))
            ->stage('stage-2', Tasks::agent('coder')->prompt('do stage-2'))
            ->build();
        $registry->register($workflow);

        return $workflow;
    }

    /**
     * @param ?\Closure(int $call, string $prompt): AgentResult $responder
     */
    private function wf6Engine(
        WorkflowRegistry $registry,
        ?HookDispatcher $dispatcher = null,
        ?\Closure $responder = null,
        ?string $root = null,
    ): WorkflowEngine {
        $root ??= $this->wf6TempRoot();
        $calls = 0;
        $responder ??= function (int $call, string $prompt) use (&$calls): AgentResult {
            return $this->wf6SealedResult($call, 'OUT[' . $prompt . ']');
        };

        $executor = $this->getMockBuilder(ExecutorInterface::class)
            ->onlyMethods(['execute', 'executeStream', 'cancel', 'cancelAll'])
            ->getMock();
        $executor->method('execute')->willReturnCallback(
            function (SubAgent $agent, CompleteRequest $request) use (&$calls, $responder): AgentResult {
                ++$calls;

                return $responder($calls, (string) $agent->task);
            },
        );

        return new WorkflowEngine(
            registry: $registry,
            pool: new AgentWorkerPool(2, $executor),
            environmentRoot: $root,
            hookDispatcher: $dispatcher,
        );
    }

    /**
     * A real dispatcher over four recording hooks — one per lifecycle event
     * — so the trace is what the hook system actually observed. The matcher
     * is the always-allow regex spelled through chr(42) so it never joins
     * the GlobDialect corpus.
     */
    private function wf6RecorderDispatcher(\ArrayObject $trace): HookDispatcher
    {
        return new HookDispatcher($this->wf6RecorderRegistry($trace));
    }

    /**
     * The same four recording hooks over a bare registry — the shape a
     * session HookManager is built on, so the armWorkflow() tests can wire
     * the manager without re-spelling the recorders.
     */
    private function wf6RecorderRegistry(\ArrayObject $trace): HookRegistry
    {
        $registry = new HookRegistry();
        foreach (
            [
                HookEvent::StageStarted,
                HookEvent::StageComplete,
                HookEvent::StageFail,
                HookEvent::WorkflowComplete,
            ] as $event
        ) {
            $registry->register(new class ($event, $trace) implements HookInterface {
                public function __construct(
                    private readonly HookEvent $event,
                    private readonly \ArrayObject $trace,
                ) {}

                public function name(): string
                {
                    return 'c6-recorder-' . $this->event->name;
                }

                public function event(): HookEvent
                {
                    return $this->event;
                }

                public function matcher(): string
                {
                    return '.' . \chr(42);
                }

                public function execute(HookContext $context): HookResult
                {
                    $this->trace->append([
                        'event' => $this->event->name,
                        'payload' => $context->toolArgs,
                    ]);

                    return HookResult::allow('observed');
                }
            });
        }

        return $registry;
    }

    /** @return list<string> */
    private function wf6EventNames(\ArrayObject $trace): array
    {
        return \array_map(static fn (array $row): string => $row['event'], $trace->getArrayCopy());
    }

    /** @return list<string> */
    private function wf6RowDump(WorkflowResult $result): array
    {
        // Structural fingerprint only — the same fields every host reads.
        $rows = [];
        foreach ($result->stageResults as $row) {
            $rows[] = $row->stageName . '|' . $row->status->value . '|' . ($row->output ?? '');
        }

        return $rows;
    }

    private function wf6SealedResult(int $call, string $output): AgentResult
    {
        return $this->wf6Result($output, 100 * $call, 0.01 * $call);
    }

    private function wf6Result(string $output, int $tokens = 100, float $cost = 0.01): AgentResult
    {
        return new AgentResult(
            agentId: 'agent-' . \uniqid((string) \getmypid(), true),
            status: AgentStatus::Completed,
            output: $output,
            tokensUsed: $tokens,
            costUsd: $cost,
            startedAt: new \DateTimeImmutable(),
            completedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * The C13 recipe, ported byte-faithful from WorkflowStageResultCacheTest:
     * a stage's identity is its type plus each task's (prompt, model, schema).
     */
    private function wf6RecipeHash(string $prompt): string
    {
        return hash(
            'sha256',
            json_encode(
                ['type' => 'stage', 'tasks' => [['prompt' => $prompt, 'model' => null, 'schema' => null]]],
                JSON_UNESCAPED_SLASHES,
            ),
        );
    }

    /**
     * A paused run of 'three' whose record carries a finished 'a' row and a
     * hash-matched 'c' row, shape-identical to the source file's writeRecord.
     */
    private function wf6WritePausedRecord(string $root): void
    {
        mkdir($root . '/workflows/.running', 0755, true);
        $atom = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        file_put_contents(
            $root . '/workflows/.running/three.json',
            (string) json_encode([
                'workflowId' => 'three-eeeeeeee',
                'workflowPath' => 'three',
                'status' => WorkflowStatus::Paused->value,
                'stagesCompleted' => 1,
                'context' => ['a.output' => 'OUT[do a]'],
                'stageResults' => [
                    [
                        'stageName' => 'a',
                        'status' => WorkflowStatus::Completed->value,
                        'output' => 'OUT[do a]',
                        'error' => null,
                        'agents' => [[
                            'agentId' => 'a-1',
                            'status' => AgentStatus::Completed->value,
                            'output' => 'OUT[do a]',
                            'error' => null,
                            'tokensUsed' => 10,
                            'costUsd' => 0.5,
                            'startedAt' => $atom,
                            'completedAt' => $atom,
                            'attempts' => 1,
                        ]],
                        'startedAt' => $atom,
                        'completedAt' => $atom,
                    ],
                    [
                        'stageName' => 'c',
                        'status' => WorkflowStatus::Completed->value,
                        'output' => 'CACHED[c]',
                        'error' => null,
                        'agents' => [[
                            'agentId' => 'c-1',
                            'status' => AgentStatus::Completed->value,
                            'output' => 'CACHED[c]',
                            'error' => null,
                            'tokensUsed' => 10,
                            'costUsd' => 0.5,
                            'startedAt' => $atom,
                            'completedAt' => $atom,
                            'attempts' => 1,
                        ]],
                        'startedAt' => $atom,
                        'completedAt' => $atom,
                    ],
                ],
                'totalTokens' => 20,
                'totalCost' => 1.0,
                'startedAt' => $atom,
                'pausedAt' => $atom,
                'stageInputHashes' => ['c' => $this->wf6RecipeHash('do c using {{b.output}}')],
            ]),
        );
    }

    private function wf6RemoveTree(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach ((array) \scandir($dir) as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (\is_dir($path)) {
                $this->wf6RemoveTree($path);

                continue;
            }
            @\unlink($path);
        }
        @\rmdir($dir);
    }
}
<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\ExecutorInterface;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\DrivesWorkflowRunsTrait;
use SugarCraft\Crush\Tui\Components\AgentSplitColumn;
use SugarCraft\Crush\Tui\Renderer;
use SugarCraft\Crush\Workflows\Tasks;
use SugarCraft\Crush\Workflows\WorkflowBuilder;
use SugarCraft\Crush\Workflows\WorkflowEngine;
use SugarCraft\Crush\Workflows\WorkflowEngineInterface;
use SugarCraft\Crush\Workflows\WorkflowRegistry;
use SugarCraft\Crush\Workflows\WorkflowResult;
use SugarCraft\Crush\Workflows\WorkflowStatus;

/**
 * `/workflow run` paints while it runs.
 *
 * ## What was actually broken, and it was two things
 *
 * The split-pane compositor has been wired since Phase 8 item 4 and a user had
 * never seen it. The reason on record was that `Chat::workflowRun()` called
 * `WorkflowEngine::run()` synchronously inside `update()`, so candy-core's
 * `Program` could not tick a repaint until the run was over.
 *
 * That was true and it was not sufficient. Measured, a build that fixed ONLY
 * the synchrony would still have painted a blank pane forever:
 * `AgentManager::liveOutputs()` filters for sub-agents that are non-terminal
 * AND have produced text, and on the worker-pool path `SubAgent::$output` had
 * exactly one writer — `AgentManager::drain()`, which sets the final text and
 * the terminal status in the same breath. There was no instant at which a
 * pool-executed sub-agent was both running and non-empty.
 *
 * So there are two claims here and they need separate tests:
 *
 *  - the loop is free while the workflow runs, and
 *  - what it paints in that window is the agent's real, partial output.
 *
 * ## Why this test forks real workers
 *
 * `AgentWorkerPool` is `final`, and its only injection seam is an
 * `ExecutorInterface` — which `startAgent()` runs INLINE, so an injected
 * executor never forks, never idles and never publishes progress. A test built
 * on one would exercise neither mechanism while looking like it did.
 * So this drives the shipped default: real `ProcessExecutor`, real
 * `pcntl_fork()`, real `proc_open()`ed worker. It costs about a second.
 *
 * @see \SugarCraft\Crush\Chat::workflowRun()
 * @see \SugarCraft\Crush\Agents\AgentWorkerPool::idle()
 * @see \SugarCraft\Crush\Agents\AgentWorkerPool::pumpProgress()
 */
final class WorkflowLivePaneTest extends TestCase
{
    use DrivesWorkflowRunsTrait;

    /** The name the workflow's parallel task carries, and so the agent's. */
    private const AGENT_NAME = 'docs-explorer';

    /**
     * Cells of the running worker's own text — PAST ITS OWN NAME TAG — that
     * the pane has to be showing.
     *
     * ## Why a length and not a string
     *
     * Every assertion in this file used to name the SIMULATION STUB's output
     * literally: `ProcessExecutor::createInlineWorkerScript()` emits
     * `"[<name>] Processing: <task>"`, and three anchors across two tests
     * matched on `Processing:`. That pinned the stub, not the mechanism — the
     * day the worker talks to a model it emits no such string, and one of the
     * three (a `assertStringNotContainsString()`) would have gone on passing
     * for the rest of the project's life while asserting nothing at all.
     *
     * So the anchor is derived at paint time from
     * {@see AgentManager::liveOutputs()} — whatever the live worker has
     * actually produced — and this constant is the only thing left that a
     * rewrite of the worker could invalidate.
     *
     * ## ⚠️ WHAT THIS USED TO SAY, AND WHY THE FIRST DERIVED PROBE WAS BLIND
     *
     * WHAT IT USED TO SAY: the window was bounded "below, by coincidence: 16
     * cells of the worker's own first line appearing verbatim inside the
     * agent's tile is not something a piece of unrelated chrome does by
     * accident."
     *
     * WHAT IS TRUE NOW: for THIS worker those 16 cells were the chrome's own
     * datum. The stub tags every line `[<name>] `, `[docs-explorer] ` is
     * exactly 16 cells, so {@see livenessProbe()} returned the AGENT NAME and
     * nothing else — and the name is handed straight to
     * {@see AgentSplitColumn::state()}, which could therefore paint a
     * satisfying tile without ever reading a byte from the worker. Measured on
     * PHP 8.3.6 in this lane: a mutant whose `outputBuffer:` argument was
     * `self::tail('[' . $name . '] MUTANT never came from the worker')` passed
     * this whole file — `OK (11 tests, 49 assertions)`, rc 0.
     *
     * WHY THIS STILL EARNS ITS PLACE: the length is still the right knob, the
     * OFFSET it is taken from was the defect. {@see livenessProbe()} now skips
     * a leading `[<agent name>] ` tag before slicing and FAILS a probe that
     * still contains the agent's name, so what the tile has to be showing is
     * 16 cells the compositor had no other way to obtain. The same mutant now
     * reds.
     *
     * ## The window's upper bound is asserted, not described
     *
     * ⚠️ WHAT THE OLD "measured on the 120x40 frame" SENTENCE CLAIMED: "the
     * tile is 40 cells, inner 36, and the stub's 34-cell first line survives
     * whole." The two arithmetic figures are right and re-derive —
     * {@see Renderer::agentSplitWidth()} at `cols = 120` gives
     * `min(60, max(24, intdiv(120, 3))) = 40`, and
     * {@see AgentSplitColumn::render()} clips body lines to
     * `$width - AgentSplitColumn::PANE_CHROME` = 36. The rest was wrong twice.
     *
     * WHAT IS TRUE NOW (PHP 8.3.6, this lane, measured by instrumenting a
     * scratch copy of this file rather than by reading the stub): the live
     * buffer holds NO newline at all — `AgentWorkerPool::pumpProgress()`
     * concatenates both `streaming` chunks — so the "first line" is one
     * 88-cell string, `'[docs-explorer] Processing: explore the
     * docs[docs-explorer] Completed task successfully.'`. The stub's first
     * EMITTED line is 44 cells, not 34, and it does not survive whole: the
     * painted row is `│ [docs-explorer] Processing: explore  │`, clipped at
     * inner 36.
     *
     * Which is why the fit is no longer a sentence here. The positive test
     * measures the tile's own top border and asserts that the probe's end
     * offset — tag included — lands inside the clip. Raise this constant past
     * what the tile can show and that assertion says so by name.
     */
    private const LIVE_TEXT_PROBE_CELLS = 16;

    private ProviderInterface $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Renderer::resetSizeCache();
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('TestProvider');
    }

    protected function tearDown(): void
    {
        Renderer::resetSizeCache();
        parent::tearDown();
    }

    // =====================================================================
    // Claim 1: update() returns before the workflow has done anything
    // =====================================================================

    /**
     * The submit tick must return with the command echoed, `inFlight` set, a
     * Cmd to drive — and the engine untouched.
     *
     * The engine flag is the assertion that matters. "history has one entry"
     * would also pass on a build that ran the whole workflow inline and simply
     * forgot to append the reply; only "run() has not been entered yet" says
     * the work was deferred.
     */
    public function testSubmittingAWorkflowReturnsFromUpdateBeforeTheEngineIsEverCalled(): void
    {
        $engine = new class implements WorkflowEngineInterface {
            public bool $entered = false;

            public function run(string $workflowPath, array $context = []): WorkflowResult
            {
                $this->entered = true;

                return new WorkflowResult(
                    workflowId: 'wf-1',
                    status: WorkflowStatus::Completed,
                    stageResults: [],
                    totalTokens: 0,
                    totalCost: 0.0,
                );
            }

            public function pause(string $workflowId): void {}

            public function resume(string $workflowId): WorkflowResult
            {
                throw new \RuntimeException('not used');
            }

            public function getStatus(string $workflowId): WorkflowStatus
            {
                return WorkflowStatus::Completed;
            }

            public function listWorkflows(): array
            {
                return [];
            }
        };

        [$next, $cmd] = $this->submitWorkflowCommand(
            new Chat(inputBuf: '/workflow run anything', workflowEngine: $engine),
        );

        $this->assertFalse($engine->entered, 'The workflow ran inside update(); that IS the freeze.');
        $this->assertCount(1, $next->history);
        $this->assertTrue($next->inFlight);

        // And it does run, once the loop drives it — otherwise "never entered"
        // would be satisfied by a build that dropped the command entirely.
        [$after] = $next->update($this->settleWorkflowCmd($cmd));
        $this->assertTrue($engine->entered);
        $this->assertStringContainsString('completed', $after->history[1]->content);
        $this->assertFalse($after->inFlight);
    }

    // =====================================================================
    // Claim 2: a frame paints, WITH the running agent's output in it
    // =====================================================================

    /**
     * THE test. A painter timer on the same loop the workflow fiber is driven
     * from — standing in for `Program`'s repaint timer — renders the real
     * `Tui\Renderer::renderView()` frame on every tick, and at least one of
     * those frames, taken while the workflow was still running, has to carry
     * the sub-agent's live text.
     *
     * The trap this is written against: a test that asserts the pane had
     * content only AFTER the run settled would pass on the unfixed build, so
     * every recorded frame is stamped with whether the workflow had finished,
     * and only the un-settled ones count.
     *
     * ## The second trap, and it is the one this test used to fall into
     *
     * The recorded liveness anchor was the SIMULATION STUB's literal output —
     * a frame counted as live if it contained `Processing:`. That is a
     * statement about `ProcessExecutor::createInlineWorkerScript()`, not about
     * the compositor: a real worker satisfies none of it, so the day the stub
     * goes the probe has nothing to look for and the test either fails for the
     * wrong reason or (see the sibling test) silently stops asserting.
     *
     * The anchor now comes out of the running system. On each tick the probe
     * reads {@see AgentManager::liveOutputs()} FIRST and renders SECOND, so the
     * text it is about to look for is text the frame has already been given —
     * a worker that emits `Processing:`, a JSON delta or a haiku all pin the
     * same thing. What is asserted is that the agent's OWN TILE is showing the
     * agent's OWN BYTES while the workflow is un-settled; nothing in it names
     * the worker's implementation.
     *
     * ## ⚠️ The third trap, which the first rewrite of this test fell into
     *
     * "The agent's own bytes" is only a claim if the probe carries bytes the
     * compositor could not have invented. It did not: the derived probe came
     * out as exactly `[docs-explorer] `, the agent's own name, which
     * {@see AgentSplitColumn::state()} is handed as a parameter. A mutant that
     * built the tile body from `$name` alone passed. The probe now starts
     * AFTER the tag and refuses to contain the name — see
     * {@see LIVE_TEXT_PROBE_CELLS} and {@see livenessProbe()} for the
     * measurement and the fix.
     */
    public function testAFramePaintsTheRunningAgentsOutputWhileTheWorkflowIsStillRunning(): void
    {
        $this->requireForking();

        $manager = new AgentManager($this->createMock(ProviderInterface::class), new SkillRegistry());
        $chat = new Chat(
            inputBuf: '/workflow run live',
            workflowEngine: $this->engineWithOneParallelAgent(),
            agentManager: $manager,
        );

        [$next, $cmd] = $this->submitWorkflowCommand($chat);
        $app = App::new($this->provider, 'test-model')->withChat($next);

        $settled = false;
        $ticks = 0;

        /** @var list<array{text: string, frame: string}> */
        $liveFrames = [];

        $painter = Loop::get()->addPeriodicTimer(
            0.02,
            function () use ($app, $manager, &$settled, &$ticks, &$liveFrames): void {
                $ticks++;
                if ($settled) {
                    return;
                }

                // Source first, frame second — see the docblock. The pane
                // derives from this same accessor, so a frame rendered after
                // the read can only carry MORE than the read saw, never less.
                $text = $manager->liveOutputs()[self::AGENT_NAME] ?? '';
                $frame = Ansi::strip(Renderer::renderView($app, 120, 40)->body);

                if ($text !== '') {
                    $liveFrames[] = ['text' => $text, 'frame' => $frame];
                }
            },
        );

        $msg = $this->settleWorkflowCmd($cmd);
        $settled = true;
        Loop::get()->cancelTimer($painter);

        $this->assertGreaterThan(
            1,
            $ticks,
            'The loop ticked at most once during the run, so update() was still holding it.',
        );

        $this->assertNotSame(
            [],
            $liveFrames,
            'No frame was painted while the sub-agent had live output: '
            . 'either nothing repainted, or liveOutputs() was empty the whole time.',
        );

        // It is the AGENT's tile, and the text in it is the worker's own
        // streamed bytes — not some other piece of chrome that happens to
        // contain the same characters. Everything below is asserted against
        // the pane COLUMN alone (see paneColumn()), so a match anywhere in the
        // menu bar, the transcript or the status line does not count.
        $text = $liveFrames[0]['text'];
        $column = $this->paneColumn($liveFrames[0]['frame']);

        $this->assertStringContainsString(
            '╭ ' . self::AGENT_NAME . ' ',
            $column,
            'The split column exists but is not the agent\'s tile.',
        );
        $this->assertStringContainsString(
            '[working]',
            $column,
            'The tile is up but does not claim the agent is still working.',
        );

        // And the slice really is a slice: the echoed command sits in the chat
        // column, left of the split, and must not be inside it. Without this,
        // a paneColumn() that quietly returned the whole frame would make all
        // three assertions above frame-wide again.
        $this->assertStringNotContainsString(
            'user> /workflow run live',
            $column,
            'paneColumn() is not isolating the split column.',
        );

        [$probe, $probeEnd] = $this->livenessProbe($text);
        $this->assertStringContainsString(
            $probe,
            $column,
            'The tile is up and marked [working], but it is not showing what the '
            . 'worker actually produced. liveOutputs() held ' . var_export($text, true)
            . ' when this frame was rendered.',
        );

        // And the window fits inside the tile — MEASURED off the tile that was
        // actually painted, not asserted in prose. The top border's own length
        // is the column budget AgentSplitColumn was given, and it clips every
        // body line to that minus its chrome. This is the assertion that
        // reports it when LIVE_TEXT_PROBE_CELLS is raised past what a tile can
        // show, instead of the containment assertion above failing as though
        // the compositor were broken.
        $tileInner = mb_strlen($this->tileTopBorder($column)) - AgentSplitColumn::PANE_CHROME;
        $this->assertLessThanOrEqual(
            $tileInner,
            $probeEnd,
            'The probe ends at cell ' . $probeEnd . ' of the worker\'s line and the '
            . 'tile clips at ' . $tileInner . ', so no correct compositor could show '
            . 'it. Lower LIVE_TEXT_PROBE_CELLS.',
        );

        // And the run really did finish afterwards, so the frames above were
        // mid-run rather than the whole thing having failed instantly.
        [$after] = $next->update($msg);
        $this->assertStringContainsString("**Workflow 'live'", $after->history[1]->content);
    }

    /**
     * The pane comes DOWN. Nothing clears `SubAgent::$output`, so a compositor
     * that only ever gained content would leave a column open for the rest of
     * the session — which is what the liveness filter is for, and which the
     * progress pump must not defeat by leaving a finished agent `streaming`.
     *
     * ## This was the rotting one
     *
     * The frame assertion here was `assertStringNotContainsString('Processing:')`
     * — the SIMULATION STUB's literal output, negated. A negative assertion
     * against a string the system has stopped producing passes forever and
     * means nothing, so the day the worker changed, this test would have gone
     * on reporting green while checking that a frame does not contain a word
     * nothing in the build emits. It is replaced by the tile's own structure,
     * which every worker produces or fails to produce identically.
     *
     * And it is guarded against the OTHER way of passing for nothing: a run in
     * which the worker never spoke at all would leave `liveOutputs()` empty and
     * no tile in the frame, and every assertion below would hold. So the
     * sub-agent is checked to have finished WITH text — the pane is down
     * because the liveness filter dropped a completed agent, not because there
     * was never anything to paint.
     *
     * ## What the frame negations do and do not cover, stated exactly
     *
     * All three of them are scoped: two name THIS agent, one names
     * `[working]`. That is narrower than "no tile is open", and measurably so
     * — injecting `['other-agent' => 'zzz mutant filler']` into
     * {@see Renderer::liveAgentOutputs()} leaves a stopped tile standing in
     * the frame and reds none of them. The unscoped statement is the
     * `liveAgentOutputs()` assertion added below them, which is the
     * compositor's actual source for the split.
     *
     * The probe negation is also deliberately FRAME-wide rather than
     * tile-scoped, which is broader than "the tile is gone": it additionally
     * says the transcript does not echo the worker's raw bytes. That is a real
     * property of today's build and it is asserted on purpose, but it is the
     * one assertion here that a legitimate future feature (streaming agent
     * output into the transcript) would red without anything being wrong with
     * the pane.
     */
    public function testThePaneIsGoneOnceTheWorkflowHasFinished(): void
    {
        $this->requireForking();

        $manager = new AgentManager($this->createMock(ProviderInterface::class), new SkillRegistry());
        $chat = new Chat(
            inputBuf: '/workflow run live',
            workflowEngine: $this->engineWithOneParallelAgent(),
            agentManager: $manager,
        );

        [$next, $cmd] = $this->submitWorkflowCommand($chat);
        [$after] = $next->update($this->settleWorkflowCmd($cmd));

        $this->assertSame([], $manager->liveOutputs());

        // Anti-vacuity: the worker really did run and really did produce text,
        // so the empty liveOutputs() above is the FILTER's doing.
        $finished = $manager->subAgentsOf(self::AGENT_NAME);
        $this->assertCount(1, $finished, 'The parallel stage did not file a sub-agent.');
        $this->assertNotSame(
            '',
            $finished[0]->output,
            'The worker finished without producing any output, so "the pane is down" '
            . 'is true for the wrong reason and this test proves nothing.',
        );
        $this->assertTrue(
            $finished[0]->isComplete() || $finished[0]->isStopped(),
            'The sub-agent is neither complete nor stopped, so the filter had no '
            . 'reason to drop it and the assertions below are about a different bug.',
        );

        $app = App::new($this->provider, 'test-model')->withChat($after);

        // No tile for ANY agent, which the frame negations below cannot say.
        // They are all scoped to THIS agent's name or to `[working]`, so a
        // compositor that left a DIFFERENT agent's tile up in a non-working
        // state satisfies every one of them — measured: injecting
        // `['other-agent' => 'zzz mutant filler']` into liveAgentOutputs() left
        // this test green. This reads the renderer's own source for the split,
        // which is a different method from the `liveOutputs()` asserted above.
        $this->assertSame(
            [],
            (new \ReflectionMethod(Renderer::class, 'liveAgentOutputs'))->invoke(null, $app),
            'The renderer still has a live-agent map, so the split column opens for '
            . 'whatever is in it — including an agent this test never names.',
        );

        $frame = Ansi::strip(Renderer::renderView($app, 120, 40)->body);

        // The TILE is gone, not merely one string the stub used to emit.
        $this->assertStringNotContainsString(
            '╭ ' . self::AGENT_NAME . ' ',
            $frame,
            'The finished agent still has a tile in the split column.',
        );
        $this->assertStringNotContainsString(
            '[working]',
            $frame,
            'Something in the frame still claims an agent is working.',
        );
        [$goneProbe] = $this->livenessProbe($finished[0]->output);
        $this->assertStringNotContainsString(
            $goneProbe,
            $frame,
            'The finished worker\'s output is still being painted somewhere in the frame.',
        );
    }

    /**
     * A fiber that throws still RELEASES the turn.
     *
     * The driver's catch is the last line of defence — the fiber body catches
     * `\Throwable` itself, so nothing ordinary reaches it — and it is exactly
     * the kind of guard that rots untested. What makes it load-bearing is that
     * the alternative is not "an ugly stack trace": a Cmd that neither
     * resolves nor rejects leaves `inFlight` latched forever, with no message
     * and no way back. Rejecting instead would be no better, because
     * candy-core dispatches `ExceptionMsg` for that and this model does not
     * handle it.
     */
    public function testAThrowingFiberSettlesWithAnErrorNoticeRatherThanLatchingTheTurnForever(): void
    {
        $fiber = new \Fiber(static function (): string {
            throw new \RuntimeException('the driver had to catch this');
        });

        $cmd = (new \ReflectionMethod(Chat::class, 'driveWorkflowFiber'))->invoke(new Chat(), $fiber);

        $msg = $this->settleWorkflowCmd($cmd, 3.0);
        $this->assertStringContainsString('the driver had to catch this', $msg->message->content);

        // And it is an ordinary AssistantMsg, so the arm that clears inFlight
        // runs — the whole reason this resolves rather than rejects.
        [$after] = (new Chat(inFlight: true))->update($msg);
        $this->assertFalse($after->inFlight);
    }

    // =====================================================================
    // The two mechanisms, unit-level
    // =====================================================================

    /**
     * `idle()` suspends its fiber instead of sleeping when it has one.
     *
     * Asserted by RESUMING it and reading the return value, not merely by
     * `isSuspended()`: a stub that suspended and then did nothing would
     * satisfy the flag, and the property under test is that the call chain
     * carries on exactly where it stopped.
     */
    public function testIdleSuspendsTheFiberDrivingItRatherThanSleeping(): void
    {
        $idle = new \ReflectionMethod(AgentWorkerPool::class, 'idle');
        $pool = new AgentWorkerPool();

        $reached = false;
        $fiber = new \Fiber(function () use ($idle, $pool, &$reached): string {
            $idle->invoke($pool);
            $reached = true;

            return 'resumed past the idle';
        });

        $fiber->start();

        $this->assertTrue($fiber->isSuspended(), 'idle() did not yield to the loop; the TUI stays frozen.');
        $this->assertFalse($reached, 'idle() returned instead of suspending.');

        $fiber->resume();

        $this->assertTrue($fiber->isTerminated());
        $this->assertSame('resumed past the idle', $fiber->getReturn());
    }

    /**
     * Outside a fiber it must still block, or `bin/` and every synchronous
     * caller turns `executeAll()`'s outer loop into a hot spin.
     */
    public function testIdleStillSleepsWhenNoFiberIsDrivingIt(): void
    {
        $idle = new \ReflectionMethod(AgentWorkerPool::class, 'idle');
        $pool = new AgentWorkerPool();

        $before = microtime(true);
        $idle->invoke($pool);
        $elapsed = microtime(true) - $before;

        $this->assertGreaterThan(0.001, $elapsed, 'idle() returned instantly outside a fiber — that is a busy spin.');
    }

    /**
     * The progress pump mirrors a running worker's published bytes onto the
     * SubAgent, cumulatively, and marks it as streaming.
     */
    public function testPumpProgressMirrorsAPublishedPartialOntoTheRunningSubAgent(): void
    {
        $pool = new AgentWorkerPool();
        $agent = $this->subAgent('a-1');
        $this->putRunning($pool, $agent, 4242);

        $publish = new \ReflectionMethod(AgentWorkerPool::class, 'publishProgress');
        $pump = new \ReflectionMethod(AgentWorkerPool::class, 'pumpProgress');

        $publish->invoke($pool, 'a-1', 'reading README');
        $pump->invoke($pool);

        $this->assertSame('reading README', $agent->output);
        $this->assertSame(SubAgent::STATUS_STREAMING, $agent->status);

        $publish->invoke($pool, 'a-1', ' and CONTRIBUTING');
        $pump->invoke($pool);

        $this->assertSame(
            'reading README and CONTRIBUTING',
            $agent->output,
            'The pump replaced the buffer instead of tracking the whole published stream.',
        );
    }

    /**
     * A worker whose stream ends without a terminal message is a FAILURE, not
     * "whatever it last said".
     *
     * `runStreaming()` skips `Streaming` results when picking the outcome, and
     * the difference only shows on a truncated stream — which is exactly the
     * shape a killed or crashed worker produces. Reporting the last chunk
     * instead would hand `AgentManager::drain()` a `Streaming` status to map,
     * and a sub-agent that died mid-sentence would settle looking like one
     * that had answered.
     */
    public function testATruncatedStreamIsReportedAsAFailureNotAsItsLastChunk(): void
    {
        $executor = new class implements ExecutorInterface {
            public function execute(SubAgent $subAgent, CompleteRequest $request): AgentResult
            {
                throw new \RuntimeException('not used');
            }

            public function executeStream(SubAgent $subAgent, CompleteRequest $request): \Generator
            {
                yield new AgentResult(
                    agentId: $subAgent->id,
                    status: AgentStatus::Streaming,
                    output: 'half a sen',
                    startedAt: new \DateTimeImmutable(),
                );
            }

            public function cancel(string $agentId): void {}

            public function cancelAll(): void {}
        };

        $result = (new \ReflectionMethod(AgentWorkerPool::class, 'runStreaming'))->invoke(
            new AgentWorkerPool(),
            $executor,
            $this->subAgent('truncated'),
            new CompleteRequest(model: 'm', messages: []),
        );

        $this->assertSame(AgentStatus::Failed, $result->status);
        $this->assertStringContainsString('no terminal result', (string) $result->error?->getMessage());
    }

    /**
     * A settled sub-agent is left alone: its result is authoritative and
     * re-marking it `streaming` would keep a finished tile on screen forever.
     */
    public function testPumpProgressLeavesASettledSubAgentUntouched(): void
    {
        $pool = new AgentWorkerPool();
        $agent = $this->subAgent('a-2');
        $agent->status = SubAgent::STATUS_COMPLETE;
        $agent->output = 'the final answer';
        $this->putRunning($pool, $agent, 4243);

        (new \ReflectionMethod(AgentWorkerPool::class, 'publishProgress'))->invoke($pool, 'a-2', 'a stale partial');
        (new \ReflectionMethod(AgentWorkerPool::class, 'pumpProgress'))->invoke($pool);

        $this->assertSame('the final answer', $agent->output);
        $this->assertSame(SubAgent::STATUS_COMPLETE, $agent->status);
    }

    /**
     * A dispatch clears whatever the last one left behind — at the CALL SITE,
     * not merely in a helper nobody proves is called.
     *
     * The probe fires from inside the executor, i.e. after `startAgent()` has
     * dispatched and before any result exists. Asserting the file is gone once
     * `executeAll()` returns would prove nothing: `extractResult()` deletes it
     * on the way out too, so that version of this test passes on a build with
     * no pre-dispatch clear at all. (It did. That is why it is written this
     * way.)
     */
    public function testEveryDispatchStartsFromACleanProgressFile(): void
    {
        $agent = $this->subAgent('reused-id');

        $executor = new class implements ExecutorInterface {
            public ?\Closure $probe = null;
            public ?bool $tailPresentAtDispatch = null;

            public function execute(SubAgent $subAgent, CompleteRequest $request): AgentResult
            {
                $this->tailPresentAtDispatch = ($this->probe)();

                return new AgentResult(
                    agentId: $subAgent->id,
                    status: AgentStatus::Completed,
                    output: 'done',
                    startedAt: new \DateTimeImmutable(),
                    completedAt: new \DateTimeImmutable(),
                );
            }

            public function executeStream(SubAgent $subAgent, CompleteRequest $request): \Generator
            {
                yield $this->execute($subAgent, $request);
            }

            public function cancel(string $agentId): void {}

            public function cancelAll(): void {}
        };

        $pool = new AgentWorkerPool(1, $executor);
        $progressFile = new \ReflectionMethod(AgentWorkerPool::class, 'progressFile');
        $path = $progressFile->invoke($pool, 'reused-id');
        $executor->probe = static fn(): bool => file_exists($path);

        // An earlier dispatch of this id died without tidying up.
        (new \ReflectionMethod(AgentWorkerPool::class, 'publishProgress'))
            ->invoke($pool, 'reused-id', 'output from the run that died');
        $this->assertFileExists($path, 'Setup is wrong: there is no stale tail to inherit.');

        iterator_to_array($pool->executeAll([$agent], new CompleteRequest(model: 'm', messages: [])));

        $this->assertFalse(
            $executor->tailPresentAtDispatch,
            'The agent was dispatched with the previous run\'s output still in its progress file.',
        );
    }

    /**
     * And the pump really does track the whole published stream rather than
     * the latest chunk — the reason the file is read in full each poll.
     */
    public function testThePumpTracksTheWholePublishedStreamNotTheLatestChunk(): void
    {
        $pool = new AgentWorkerPool();
        $agent = $this->subAgent('a-3');
        $this->putRunning($pool, $agent, 4244);

        $publish = new \ReflectionMethod(AgentWorkerPool::class, 'publishProgress');
        $pump = new \ReflectionMethod(AgentWorkerPool::class, 'pumpProgress');

        $publish->invoke($pool, 'a-3', 'first');
        $pump->invoke($pool);
        $publish->invoke($pool, 'a-3', '/second');
        $publish->invoke($pool, 'a-3', '/third');
        $pump->invoke($pool);

        $this->assertSame('first/second/third', $agent->output);

        (new \ReflectionMethod(AgentWorkerPool::class, 'discardProgress'))->invoke($pool, 'a-3');
        $this->assertFileDoesNotExist(
            (new \ReflectionMethod(AgentWorkerPool::class, 'progressFile'))->invoke($pool, 'a-3'),
        );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * The split column, sliced out of a stripped frame.
     *
     * `renderView()` composes the shell band and the agent column side by side
     * and pads every row to the full terminal width, so the column is a fixed
     * cell range on every line — the range that opens at the tile's own top
     * border. Isolating it is what lets the assertions above say "in the
     * agent's tile" rather than "somewhere in 120x40 cells of chrome", which
     * matters now that what they look for is arbitrary worker text.
     *
     * Returns `''` when there is no tile at all; the caller's border assertion
     * is the one that then reports it, with a better message than a slice
     * helper could.
     */
    private function paneColumn(string $frame): string
    {
        $marker = '╭ ' . self::AGENT_NAME . ' ';
        $lines = explode("\n", $frame);

        $column = null;
        foreach ($lines as $line) {
            $at = mb_strpos($line, $marker);
            if ($at !== false) {
                $column = $at;
                break;
            }
        }

        if ($column === null) {
            return '';
        }

        $sliced = [];
        foreach ($lines as $line) {
            $sliced[] = mb_strlen($line) > $column ? mb_substr($line, $column) : '';
        }

        return implode("\n", $sliced);
    }

    /**
     * The bytes of a live buffer that a tile has to be showing, and where they
     * end within the line they came from.
     *
     * The first WIDE ENOUGH non-blank line, clipped to
     * {@see LIVE_TEXT_PROBE_CELLS} — the pane splits its buffer on newlines and
     * clips each one, so a probe that spanned a newline could not appear in any
     * frame however correct the compositor was, and one longer than the tile's
     * inner width could not either. Both are properties of the pane, not of the
     * worker, which is why the probe is derived rather than written down.
     *
     * ## WHAT THIS USED TO SAY
     *
     * "The FIRST non-blank line", full stop — and it FAILED on any worker whose
     * first line was shorter than the probe.
     *
     * ## WHAT IS TRUE NOW
     *
     * The worker behind this file is no longer the fabricating stub that tagged
     * every line `[<name>] ` and so was always wide. It is a real provider
     * relay ({@see \SugarCraft\Crush\Agents\ProcessExecutor::createLiveWorkerScript()}),
     * and a real answer opens with whatever the model opens with — for the
     * offline EchoProvider that is a nine-cell `You said:`. Insisting on the
     * first line would have made this helper a test of the provider's
     * salutation length.
     *
     * ## WHY THE RULE STILL EARNS ITS PLACE
     *
     * Every property it was defending is unchanged and still enforced below: a
     * probe must fit inside ONE line, must be a full {@see LIVE_TEXT_PROBE_CELLS}
     * wide, and must not be made of the agent's own name. Only the choice of
     * WHICH line moved, and the pane clips every line the same way, so any line
     * that can carry a full-width probe is as good as the first. A buffer with
     * no such line still fails outright rather than probing weakly.
     *
     * ## The slice does not start at cell 0, and that is the whole point
     *
     * {@see AgentSplitColumn::state()} is handed the agent's NAME alongside
     * its output. Any part of the probe that is also part of the name is
     * therefore something the compositor can produce out of data it already
     * holds, and a probe made entirely of it proves nothing about the worker —
     * which is exactly what happened: this stub tags every line
     * `[<name>] `, and for `docs-explorer` that tag is 16 cells, so the first
     * version of this method returned the name and a mutant that fabricated
     * the whole buffer from `$name` survived. See {@see LIVE_TEXT_PROBE_CELLS}
     * for the measurement.
     *
     * So the tag is skipped when present, and a probe that still carries the
     * agent's name fails here rather than passing weakly at the call site.
     * A worker that does not tag its lines simply starts at 0 and is held to
     * the same no-name rule.
     *
     * @return array{0: string, 1: int} the probe, and the cell offset one past
     *                                  its end within its line — what the
     *                                  caller compares against the tile's clip
     */
    private function livenessProbe(string $liveText): array
    {
        $tag = '[' . self::AGENT_NAME . '] ';

        foreach (explode("\n", $liveText) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $offset = str_starts_with($line, $tag) ? mb_strlen($tag) : 0;
            $probe = mb_substr($line, $offset, self::LIVE_TEXT_PROBE_CELLS);

            // Too narrow to prove anything — try the next line rather than
            // failing, and fail below only if NO line is wide enough.
            if (mb_strlen($probe) < self::LIVE_TEXT_PROBE_CELLS) {
                continue;
            }

            self::assertStringNotContainsString(
                self::AGENT_NAME,
                $probe,
                'The probe is made of the agent\'s own name, which '
                . 'AgentSplitColumn::state() is handed directly and can paint without '
                . 'reading the worker at all. A probe like that cannot distinguish a '
                . 'live tile from a fabricated one — measured, it did not.',
            );

            return [$probe, $offset + self::LIVE_TEXT_PROBE_CELLS];
        }

        self::fail(
            'The worker published a live buffer with no line carrying '
            . self::LIVE_TEXT_PROBE_CELLS . ' cells past its own name tag, so there '
            . 'is no window here wide enough to tell a live tile from a fabricated '
            . 'one: ' . var_export($liveText, true),
        );
    }

    /**
     * The agent tile's top border row, out of an isolated pane column.
     *
     * Line 0 of a {@see paneColumn()} slice is NOT the border — it is whatever
     * the menu bar had at that cell offset — so the row has to be found rather
     * than indexed. Its length is the column budget
     * {@see AgentSplitColumn::render()} was given, which is the only place a
     * test can read that budget without reflecting into
     * {@see Renderer::agentSplitWidth()}.
     */
    private function tileTopBorder(string $column): string
    {
        $marker = '╭ ' . self::AGENT_NAME . ' ';

        foreach (explode("\n", $column) as $line) {
            if (str_starts_with($line, $marker)) {
                return $line;
            }
        }

        self::fail('The isolated pane column has no tile top border in it.');
    }

    private function requireForking(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('proc_open')) {
            $this->markTestSkipped('The live pane needs real forked workers.');
        }
    }

    /**
     * A one-task parallel stage on the shipped default executor.
     *
     * One task, not several: the assertion is about a pane appearing, and a
     * second worker only doubles the fork cost.
     */
    private function engineWithOneParallelAgent(): WorkflowEngine
    {
        $registry = new WorkflowRegistry();
        $registry->register(
            (new WorkflowBuilder())
                ->name('live')
                ->description('One agent, long enough to paint around')
                ->parallel('explore', [
                    Tasks::agent(self::AGENT_NAME)->prompt('explore the docs'),
                ])
                ->build(),
        );

        // The pool is passed rather than defaulted so the DEFAULT executor it
        // builds has a provider to consult. workerProvider, not an injected
        // executor: injecting one sets AgentWorkerPool::$customExecutor, which
        // routes dispatch down the synchronous in-parent path — and that path
        // publishes no progress at all, which is precisely the mechanism this
        // file exists to test. ['type' => 'echo'] is EchoProvider, a real
        // ProviderInterface with no network behind it; without a provider the
        // worker refuses rather than fabricating
        // (ProcessExecutor::createLiveWorkerScript()).
        return new WorkflowEngine($registry, new AgentWorkerPool(workerProvider: ['type' => 'echo']));
    }

    private function subAgent(string $id): SubAgent
    {
        return new SubAgent(
            id: $id,
            agent: new Agent(
                name: self::AGENT_NAME,
                description: 'explore',
                prompt: '',
                model: 'claude-sonnet-4-6',
                provider: 'anthropic',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
            task: 'explore the docs',
        );
    }

    /** File $agent into the pool exactly as a forked dispatch would have. */
    private function putRunning(AgentWorkerPool $pool, SubAgent $agent, int $pid): void
    {
        $active = new \ReflectionProperty(AgentWorkerPool::class, 'active');
        $active->setValue($pool, [$agent->id => $agent]);

        $pids = new \ReflectionProperty(AgentWorkerPool::class, 'activePids');
        $pids->setValue($pool, [$agent->id => $pid]);
    }
}

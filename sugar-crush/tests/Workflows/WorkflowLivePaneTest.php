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
     * Cells of the running worker's own text the pane has to be showing.
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
     * So the anchor is now derived at paint time from
     * {@see AgentManager::liveOutputs()} — whatever the live worker has
     * actually produced — and this constant is the only thing left that a
     * rewrite of the worker could invalidate. It is a WINDOW, and it is bounded
     * from both sides:
     *
     *  - Below, by coincidence: 16 cells of the worker's own first line
     *    appearing verbatim inside the agent's tile is not something a piece of
     *    unrelated chrome does by accident.
     *  - Above, by the tile's clip.
     *    {@see \SugarCraft\Crush\Tui\Components\AgentSplitColumn::render()}
     *    clips each line to `$width - AgentSplitColumn::PANE_CHROME`, and
     *    {@see Renderer::agentSplitWidth()} never opens the column below 80
     *    terminal columns, so the narrowest inner text width this can meet is
     *    comfortably wider than 16. Measured on the 120x40 frame these tests
     *    render: the tile is 40 cells, inner 36, and the stub's 34-cell first
     *    line survives whole.
     *
     * Raise it and the assertion starts failing on narrow tiles for reasons
     * that have nothing to do with liveness.
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

        $probe = $this->livenessProbe($text);
        $this->assertStringContainsString(
            $probe,
            $column,
            'The tile is up and marked [working], but it is not showing what the '
            . 'worker actually produced. liveOutputs() held ' . var_export($text, true)
            . ' when this frame was rendered.',
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

        $frame = Ansi::strip(
            Renderer::renderView(App::new($this->provider, 'test-model')->withChat($after), 120, 40)->body,
        );

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
        $this->assertStringNotContainsString(
            $this->livenessProbe($finished[0]->output),
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
     * The bytes of a live buffer that a tile has to be showing.
     *
     * The FIRST non-blank line, clipped to {@see LIVE_TEXT_PROBE_CELLS} — the
     * pane splits its buffer on newlines and clips each one, so a probe that
     * spanned a newline could not appear in any frame however correct the
     * compositor was, and one longer than the tile's inner width could not
     * either. Both are properties of the pane, not of the worker, which is why
     * the probe is derived rather than written down.
     */
    private function livenessProbe(string $liveText): string
    {
        foreach (explode("\n", $liveText) as $line) {
            if (trim($line) === '') {
                continue;
            }

            return mb_substr($line, 0, self::LIVE_TEXT_PROBE_CELLS);
        }

        self::fail(
            'The worker published a live buffer with no non-blank line in it, so '
            . 'there is nothing a pane could have painted: ' . var_export($liveText, true),
        );
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

        return new WorkflowEngine($registry);
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

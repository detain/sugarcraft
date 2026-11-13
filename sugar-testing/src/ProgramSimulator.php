<?php

declare(strict_types=1);

namespace SugarCraft\Testing;

use RuntimeException;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\ExecRequest;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\ColorProfileMsg;
use SugarCraft\Core\Msg\EnvMsg;
use SugarCraft\Core\Msg\InterruptMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\SuspendMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\PrintMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\RawMsg;
use SugarCraft\Core\SequenceMsg;
use SugarCraft\Core\TickRequest;
use SugarCraft\Core\Util\ColorProfile;

/**
 * Drives a {@see Program} (or bare Model) with scripted input, deterministically.
 *
 * ProgramSimulator wraps a Program and allows enqueuing messages via
 * {@see addMsgs()}, then drains the queue via {@see run()} which invokes
 * init(), update(), and view() in sequence — without touching real
 * stdin/stdout or installing signal handlers.
 *
 * Control-layer parity: messages are routed through the interception
 * ladder {@see Program::dispatch()} uses for the control messages listed
 * below, so a model never sees what the runtime would have swallowed.
 * The mirror is partial: AsyncCmd and SubscriptionsMsg are
 * NOT intercepted here (they fall through to update(), where production
 * would never show them), the ScreenStackPushed/Popped root-model routing
 * and a wrapped Program's ProgramOptions::$filter are not applied at all,
 * and {@see SuspendMsg} is dropped outright rather than delivered as the
 * runtime delivers it. See the README "Simulation subset" section.
 *  - {@see BatchMsg} / {@see SequenceMsg} — their Cmd closures are executed
 *    (through the cmd-runner hooks, so they are captured like any cmd) and
 *    the messages they return are dispatched; the batch itself never reaches
 *    update(). The simulator runs both strictly in list order; production
 *    Batch schedules its cmds through the loop's tick queue, so if a model
 *    depends on Batch-vs-Sequence scheduling differences, test that on a
 *    real Program, not here.
 *  - {@see TickRequest} — its produce closure fires IMMEDIATELY (there is
 *    no virtual clock; the simulator has no timers). Subscription stand-ins
 *    for timers live in {@see ScriptedInput::ticks()}.
 *  - {@see RawMsg} / {@see PrintMsg} — appended verbatim to the output
 *    stream ({@see TestResult::$output}); the model is not updated and no
 *    frame is rendered for them, matching the runtime's side-channel.
 *  - {@see ExecRequest} — THROWS. Exec suspends the real terminal; the
 *    simulator has none to hand over. A test that exercises exec belongs at
 *    the ProcessExecutor/integration layer, not the TEA-unit layer.
 *  - {@see SuspendMsg} — DROPPED, a divergence from the runtime: real
 *    Program::dispatch() delivers it to update() and later emits ResumeMsg;
 *    the simulator emits neither, so Ctrl-Z-aware models cannot exercise
 *    suspend/resume under it.
 *  - {@see QuitMsg} / {@see InterruptMsg} — the drain STOPS, and these
 *    messages never reach the model (exactly what Program does); messages
 *    still queued behind the stop are dropped and counted in
 *    {@see TestResult::$droppedAfterStop}.
 *
 * The runtime's startup trio (WindowSizeMsg, EnvMsg, ColorProfileMsg, which
 * Program dispatches BEFORE init()) is NOT emitted by default. Opt in with
 * {@see withWindowSize()}, {@see withEnvironment()}, and
 * {@see withColorProfile()} to replay it — a model whose behavior depends
 * on those messages (palette, width veto) is only testable with them armed.
 *
 * The fake-cmd runner hook lets tests decide the message injected for each
 * command; to skip the commands' side effects entirely, also opt out of
 * execution with {@see withRealCmdRunner(false)}.
 *
 * Clone discipline (enforced): the with*() configuration setters CLONE and
 * must be called BEFORE any message is queued. addMsgs() MUTATES the handle
 * and marks it sent; a with*() call on a handle that has already sent throws
 * RuntimeException instead of silently cloning away the queued messages.
 * Configure first, then addMsgs — or addMsgs on a throwaway handle only if
 * you never reconfigure it (see tests/ControlParitySimulatorTest.php for
 * the pinned contract).
 *
 * @see Assertions::assertGoldenAnsi() for snapshot assertions
 */
final class ProgramSimulator
{
    /** @var list<Msg> */
    private array $queue = [];

    /** @var list<\Closure> */
    private array $capturedCmds = [];

    /** @var string */
    private string $outputBytes = '';

    /** Safety bound on update-legs per drained message chain (see pump()). */
    private const MAX_CYCLES = 10_000;

    /**
     * Hard ceiling on pump() queue iterations (any message processed, not
     * just update legs) — see pump(): the Batch/Sequence/TickRequest legs
     * continue before the update-leg cycle counter, so a producer that
     * re-enqueues forever never touches MAX_CYCLES.
     */
    private const MAX_PUMP_ITERATIONS = 100_000;

    private ?\Closure $fakeCmdRunner = null;

    /** Set once a QuitMsg/InterruptMsg has been intercepted mid-drain. */
    private bool $stopped = false;

    /** Messages skipped because the drain had already stopped. */
    private int $dropped = 0;

    /** Set by addMsgs()/send(): with*() configuration must happen before it. */
    private bool $hasSent = false;

    /**
     * When true, executes cmds and threads their returned messages through
     * update(). When false, captures cmds but does NOT execute them (safe
     * deterministic mode). Defaults to true (execute mode) for backward
     * compatibility with existing tests.
     */
    private bool $executeCmds = true;

    /** Startup-trio opt-ins (null = not armed; see class docblock). */
    private ?WindowSizeMsg $startupSize = null;

    private ?EnvMsg $startupEnv = null;

    private ?ColorProfileMsg $startupProfile = null;

    /**
     * @param private readonly Program $program PHP's readonly keyword prevents
     *                                          re-assignment of this property,
     *                                          but does NOT prevent internal
     *                                          mutation of the Program or its
     *                                          model. The model is safe to
     *                                          read but may be modified by
     *                                          update() calls during run().
     */
    private function __construct(
        private readonly Program $program,
    ) {}

    /**
     * Factory — wrap a Program or a bare Model for testing.
     *
     * A bare {@see Model} is convenient for behavior tests that only
     * exercise init/update/view and don't need to configure runtime
     * {@see ProgramOptions}; it is wrapped in a default {@see Program}
     * (no I/O happens because the simulator never calls Program::run()).
     */
    public static function for(Model|Program $program): self
    {
        if ($program instanceof Model) {
            $program = new Program($program);
        }

        return new self($program);
    }

    /**
     * Enqueue messages for the {@see run()} cycle, mutating this handle.
     *
     * The primary spelling (renamed from send(), which remains as a
     * deprecated alias). Mutates and returns $this: the queue lives on this
     * instance, so the handle you configured is the handle you run. The
     * with*() configuration setters CLONE — call them BEFORE the first
     * addMsgs(), or reconfigure a fresh handle (the class docblock states
     * the enforced discipline).
     *
     * @return $this
     */
    public function addMsgs(Msg ...$msgs): self
    {
        foreach ($msgs as $msg) {
            $this->queue[] = $msg;
        }
        $this->hasSent = true;

        return $this;
    }

    /**
     * @deprecated since 2026-11 — use {@see addMsgs()}; identical behaviour,
     *             kept as an alias so consumers migrate deliberately.
     *
     * @return $this
     */
    public function send(Msg ...$msgs): self
    {
        return $this->addMsgs(...$msgs);
    }

    /**
     * Guard for every with*() setter: configuration after sending is a named
     * throw, not a silent queue loss (see class docblock clone discipline).
     */
    private function assertConfigureBeforeSend(): void
    {
        if ($this->hasSent) {
            throw new \RuntimeException(Lang::t('simulator.configure_after_send'));
        }
    }

    /**
     * Install a fake runner that decides the message injected for each Cmd.
     *
     * The supplied closure receives each captured Cmd (\Closure) and may
     * return a Msg to inject, or null to skip. Naming caveat: the fake does
     * NOT stop execution by itself — the real Cmd closure still runs for
     * its side effects under the default execute mode. Pair with
     * {@see withRealCmdRunner(false)} for capture-without-executing
     * determinism.
     *
     * Clones — throws RuntimeException if this handle has already sent
     * (addMsgs): configure first, then queue.
     *
     * @param \Closure(\Closure): ?Msg $runner
     * @return $this
     */
    public function withFakeCmdRunner(\Closure $runner): self
    {
        $this->assertConfigureBeforeSend();

        $sim = clone $this;
        $sim->fakeCmdRunner = $runner;
        return $sim;
    }

    /**
     * Opt-out of cmd execution for deterministic capture-only mode.
     *
     * By default (or when called with true), cmds are executed and their
     * returned messages are threaded through update(). When called with
     * false, cmds are captured but NOT executed — this is the safe,
     * deterministic mode that avoids side effects from sync cmds.
     *
     * Clones — throws RuntimeException on an already-sent handle; configure first.
     *
     * @param bool $execute Pass false to capture without executing
     * @return $this
     */
    public function withRealCmdRunner(bool $execute): self
    {
        $this->assertConfigureBeforeSend();

        $sim = clone $this;
        $sim->executeCmds = $execute;
        return $sim;
    }

    /**
     * Arm the startup WindowSizeMsg (dispatched before init(), like Program).
     *
     * Clones — throws RuntimeException on an already-sent handle; configure first.
     */
    public function withWindowSize(int $cols, int $rows): self
    {
        $this->assertConfigureBeforeSend();

        $sim = clone $this;
        $sim->startupSize = new WindowSizeMsg($cols, $rows);
        return $sim;
    }

    /**
     * Arm the startup EnvMsg (dispatched before init(), like Program).
     *
     * Clones — throws RuntimeException on an already-sent handle; configure first.
     */
    public function withEnvironment(array $vars): self
    {
        $this->assertConfigureBeforeSend();

        $sim = clone $this;
        $sim->startupEnv = new EnvMsg($vars);
        return $sim;
    }

    /**
     * Arm the startup ColorProfileMsg (dispatched before init(), like
     * Program) — the message candy-top's color veto keys off.
     *
     * Clones — throws RuntimeException on an already-sent handle; configure first.
     */
    public function withColorProfile(ColorProfile $profile): self
    {
        $this->assertConfigureBeforeSend();

        $sim = clone $this;
        $sim->startupProfile = new ColorProfileMsg($profile);
        return $sim;
    }

    /**
     * Drain the message queue, running init/update/view in sequence.
     *
     * The program loop is not used — we call the Model's methods directly
     * so tests remain deterministic and side-effect-free. Routing matches
     * {@see Program::dispatch()}: control messages (Batch, Sequence,
     * TickRequest, Raw, Print, Suspend, Quit, Interrupt) are intercepted
     * and never reach the model; Quit/Interrupt end the drain early.
     * ExecRequest throws (see class docblock).
     *
     * Subscriptions stand in for the runtime's timers on a virtual clock:
     * each one fires once after init() and once after every {@see addMsgs()}
     * message, and what it produces is delivered right then, before the next
     * sent message — the runtime delivers every subscription message it
     * produces, so none may be dropped here either. Messages a subscription
     * produces do not themselves fire subscriptions again: a subscription
     * that produces on every call would otherwise never let run() return,
     * where the runtime spaces those fires out by its interval.
     *
     * run() is repeatable: every call replays init() and the sent messages
     * from the Program's model, and nothing a previous run produced leaks
     * into the next.
     *
     * @return TestResult
     */
    public function run(): TestResult
    {
        $this->capturedCmds = [];
        $this->outputBytes  = '';
        $this->stopped      = false;
        $this->dropped      = 0;
        $frames             = [];

        // We use a simplified approach: just call init/update/view directly.
        $model = $this->getModelFromProgram();

        // Startup trio, before init() — exactly what Program::run() dispatches
        // first, but only when armed (default: nothing, historical behavior).
        foreach ([$this->startupSize, $this->startupEnv, $this->startupProfile] as $startupMsg) {
            if ($startupMsg === null) {
                continue;
            }

            $model = $this->pump($model, $startupMsg, $frames);
        }

        // Call init() once at startup and thread any produced message.
        $initCmd = $model->init();
        $initMsg = $this->runCmd($initCmd);
        if ($initMsg !== null) {
            $model = $this->pump($model, $initMsg, $frames);
        }

        // Fire subscriptions once after init to collect startup messages.
        $model = $this->fireSubscriptions($model, $frames);

        // Process the sent messages in order; subscriptions fire after each.
        foreach ($this->queue as $msg) {
            if ($this->stopped) {
                $this->dropped++;
                continue;
            }

            $model = $this->pump($model, $msg, $frames);
            if (!$this->stopped) {
                $model = $this->fireSubscriptions($model, $frames);
            }
        }

        // Final view call.
        $finalView = '';
        if ($model instanceof Model) {
            $finalViewResult = $model->view();
            $finalView = is_string($finalViewResult) ? $finalViewResult : '';
            $frames[]  = $finalView;
        }

        return new TestResult(
            model: $model,
            view: $finalView,
            cmds: $this->capturedCmds,
            output: $this->outputBytes,
            frames: $frames,
            droppedAfterStop: $this->dropped,
        );
    }

    /**
     * Fire every subscription the model currently wants and deliver what
     * they produce through the dispatch pump, in subscription order.
     *
     * The set is read once, from the model as it stands now; the produced
     * messages are then applied in turn. They are applied here rather than
     * appended to the send() queue: a fixed-length drain of that queue left
     * them unprocessed, and the queue outlived run(), so the next run()
     * replayed them on top of its own.
     *
     * @param list<string> $frames
     */
    private function fireSubscriptions(Model $model, array &$frames): Model
    {
        foreach ($this->pumpSubscriptions($model) as $msg) {
            if ($this->stopped) {
                $this->dropped++;
                continue;
            }

            $model = $this->pump($model, $msg, $frames);
        }

        return $model;
    }

    /**
     * Invoke each subscription's produce closure once and collect the
     * messages they return.
     *
     * @param Model $model
     * @return list<Msg>
     */
    private function pumpSubscriptions(Model $model): array
    {
        $subs = $model->subscriptions();
        if ($subs === null) {
            return [];
        }

        $produced = [];
        foreach ($subs->all() as $subscription) {
            $msg = ($subscription->produce)();
            if ($msg !== null) {
                $produced[] = $msg;
            }
        }

        return $produced;
    }

    /**
     * The dispatch() ladder, simulator edition: intercept the control
     * messages enumerated in the class docblock, as {@see Program::dispatch()}
     * does for those, and run ordinary messages through update() — draining
     * whatever the update's cmd (or a batch member) produces, iteratively,
     * through this same pump. The mirror is partial: see the class docblock
     * and the README "Simulation subset" section for what is NOT intercepted.
     *
     * Two bounds guard the drain, both fixed throws naming themselves: the
     * update-leg cycle counter (self::MAX_CYCLES = 10,000) catches models
     * that produce a cmd on every update; a queue-iteration ceiling
     * (self::MAX_PUMP_ITERATIONS = 100,000) catches legs that never reach
     * an update at all — a Batch/Sequence member or TickRequest producer
     * whose result re-enqueues forever would otherwise spin the loop with
     * the cycle counter untouched.
     *
     * @param list<string> $frames view() outputs collected across the run
     */
    private function pump(Model $model, Msg $msg, array &$frames): Model
    {
        $pending    = [$msg];
        $cycles     = 0;
        $iterations = 0;

        while ($pending !== []) {
            if (++$iterations > self::MAX_PUMP_ITERATIONS) {
                throw new RuntimeException(
                    Lang::t('simulator.pump_overflow', ['max' => self::MAX_PUMP_ITERATIONS])
                );
            }

            if ($this->stopped) {
                $this->dropped += count($pending);
                break;
            }

            $msg = array_shift($pending);

            if ($msg instanceof BatchMsg || $msg instanceof SequenceMsg) {
                // The runtime never feeds a batch to the model: its members
                // are scheduled as cmds. Run them through the same cmd hooks
                // so capture/execute semantics apply, threading results.
                foreach ($msg->cmds as $cmd) {
                    if (!$cmd instanceof \Closure) {
                        throw new RuntimeException(Lang::t('simulator.batch_member_not_closure', [
                            'class' => $msg::class,
                            'type' => get_debug_type($cmd),
                        ]));
                    }

                    $produced = $this->runCmd($cmd);
                    if ($produced !== null) {
                        $pending[] = $produced;
                    }
                }

                continue;
            }

            if ($msg instanceof TickRequest) {
                // No virtual clock here: the request fires immediately and
                // its result dispatches like any message (Program::dispatch
                // recurses the same way from inside its one-shot timer).
                $produced = ($msg->produce)();
                if ($produced !== null) {
                    $pending[] = $produced;
                }

                continue;
            }

            if ($msg instanceof RawMsg) {
                // Side-channel: bytes verbatim, no model touch, no frame.
                $this->outputBytes .= $msg->bytes;
                continue;
            }

            if ($msg instanceof PrintMsg) {
                $this->outputBytes .= $msg->text . "\n";
                continue;
            }

            if ($msg instanceof ExecRequest) {
                throw new RuntimeException(Lang::t('simulator.exec_unsupported'));
            }

            if ($msg instanceof SuspendMsg) {
                // DIVERGENCE from the runtime, not parity: Program delivers
                // SuspendMsg to update() and later emits ResumeMsg. The
                // simulator has no terminal to hand over and no signal
                // plumbing, so it drops the message and emits neither.
                continue;
            }

            if ($msg instanceof QuitMsg || $msg instanceof InterruptMsg) {
                $this->stopped = true;
                $this->dropped += count($pending);
                break;
            }

            // Ordinary message: the update leg.
            if ($cycles++ > self::MAX_CYCLES) {
                throw new RuntimeException(
                    Lang::t('simulator.cmd_loop_overflow', ['max' => self::MAX_CYCLES])
                );
            }

            [$model, $cmd] = $model->update($msg);

            // Capture view output after each update.
            $viewOutput = $model->view();
            if (is_string($viewOutput)) {
                $this->outputBytes .= $viewOutput;
                $frames[]           = $viewOutput;
            }

            $producedMsg = $this->runCmd($cmd);
            if ($producedMsg !== null) {
                $pending[] = $producedMsg;
            }
        }

        return $model;
    }

    /**
     * Extract the model from a Program instance.
     *
     * Uses the Program's public {@see Program::model()} accessor rather than
     * Reflection. Not `getModel()`: that is a deprecated delegating alias
     * sugar-core keeps only for external callers, and the harness must not be
     * the reason it lingers (guarded by tests/DeprecatedCoreApiUsageTest.php).
     *
     * @return Model
     */
    private function getModelFromProgram(): Model
    {
        return $this->program->model();
    }

    /**
     * Run a Cmd (closure) and return any produced message.
     *
     * @param \Closure|null $cmd
     * @return ?Msg The message produced by the cmd, or null
     */
    private function runCmd(?\Closure $cmd): ?Msg
    {
        if ($cmd === null) {
            return null;
        }

        if ($this->fakeCmdRunner !== null) {
            $this->capturedCmds[] = $cmd;
            // Execute the cmd for side effects, then let fakeRunner inject a msg.
            if ($this->executeCmds === true) {
                $cmd();
            }
            return ($this->fakeCmdRunner)($cmd);
        }

        // Capture the cmd (for inspection) and optionally execute.
        $this->capturedCmds[] = $cmd;

        if ($this->executeCmds === false) {
            // Capture-only mode: don't execute side-effecting cmds.
            return null;
        }

        // Execute the cmd and return any produced message.
        return $cmd();
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Testing;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Program;

/**
 * Drives a TEA {@see Program} with scripted input for deterministic testing.
 *
 * ProgramSimulator wraps a Program and allows enqueuing messages via
 * {@see send()}, then drains the queue via {@see run()} which invokes
 * init(), update(), and view() in sequence — without touching real
 * stdin/stdout or installing signal handlers.
 *
 * The fake-cmd runner hook lets tests intercept commands that would
 * otherwise have side-effects (exec, clipboard, etc.) and either skip
 * them or return controlled message sequences.
 *
 * @see Mirrors charmbracelet/bubbletea — pioneering what issue #1654 never shipped
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

    private ?\Closure $fakeCmdRunner = null;

    /**
     * When true, executes cmds and threads their returned messages through
     * update(). When false, captures cmds but does NOT execute them (safe
     * deterministic mode). Defaults to true (execute mode) for backward
     * compatibility with existing tests.
     */
    private bool $executeCmds = true;

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
     * A bare {@see Model} is convenient for behaviour tests that only
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
     * Enqueue a message for the next {@see run()} cycle.
     * Fluent — returns $this for chaining.
     *
     * @return $this
     */
    public function send(Msg $msg): self
    {
        $this->queue[] = $msg;
        return $this;
    }

    /**
     * Replace the cmd-runner with a fake that captures instead of executing.
     *
     * The supplied closure receives each captured Cmd (\Closure) and may
     * return a Msg to inject, or null to skip.
     *
     * @param \Closure(\Closure): ?Msg $runner
     * @return $this
     */
    public function withFakeCmdRunner(\Closure $runner): self
    {
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
     * @param bool $execute Pass false to capture without executing
     * @return $this
     */
    public function withRealCmdRunner(bool $execute): self
    {
        $sim = clone $this;
        $sim->executeCmds = $execute;
        return $sim;
    }

    /**
     * Drain the message queue, running init/update/view in sequence.
     *
     * The program loop is not used — we call the Model's methods directly
     * so tests remain deterministic and side-effect-free.
     *
     * Subscriptions stand in for the runtime's timers on a virtual clock:
     * each one fires once after init() and once after every {@see send()}
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
        $this->outputBytes = '';

        // We use a simplified approach: just call init/update/view directly.
        $model = $this->getModelFromProgram();

        // Call init() once at startup and thread any produced message.
        $initCmd = $model->init();
        $initMsg = $this->runCmd($initCmd);
        if ($initMsg !== null) {
            [$model, ] = $this->applyMsg($model, $initMsg);
        }

        // Fire subscriptions once after init to collect startup messages.
        $model = $this->fireSubscriptions($model);

        // Process the sent messages in order; subscriptions fire after each.
        foreach ($this->queue as $msg) {
            [$model, ] = $this->applyMsg($model, $msg);
            $model = $this->fireSubscriptions($model);
        }

        // Final view call.
        $finalView = '';
        if ($model instanceof Model) {
            $finalViewResult = $model->view();
            $finalView = is_string($finalViewResult) ? $finalViewResult : '';
        }

        return new TestResult(
            model: $model,
            view: $finalView,
            cmds: $this->capturedCmds,
            output: $this->outputBytes,
        );
    }

    /**
     * Fire every subscription the model currently wants and deliver what
     * they produce through update(), in subscription order.
     *
     * The set is read once, from the model as it stands now; the produced
     * messages are then applied in turn. They are applied here rather than
     * appended to the send() queue: a fixed-length drain of that queue left
     * them unprocessed, and the queue outlived run(), so the next run()
     * replayed them on top of its own.
     */
    private function fireSubscriptions(Model $model): Model
    {
        foreach ($this->pumpSubscriptions($model) as $msg) {
            [$model, ] = $this->applyMsg($model, $msg);
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
     * Apply a single message to the model, running any resulting command
     * and iteratively draining cmd-produced messages (bounded to prevent
     * infinite loops).
     *
     * The $maxCycles limit of 10,000 prevents infinite cmd loops in models
     * that produce a cmd on every update (e.g., models with continuous
     * subscriptions). This is a safety guard — if your model legitimately
     * needs more cycles, you can increase this limit, but it typically
     * indicates a model design issue. For stress tests with many tick
     * messages, consider using a smaller tick count or mocking subscriptions.
     *
     * @param Model $model
     * @param Msg $msg
     * @return array{0: Model, 1: ?\Closure} Updated model and any cmd
     */
    private function applyMsg(Model $model, Msg $msg): array
    {
        $cycleCount = 0;
        $maxCycles = 10_000;

        while (true) {
            if ($cycleCount++ > $maxCycles) {
                throw new \RuntimeException(
                    Lang::t('simulator.cmd_loop_overflow', ['max' => $maxCycles])
                );
            }

            [$model, $cmd] = $model->update($msg);

            // Capture view output after each update.
            $viewOutput = $model->view();
            if (is_string($viewOutput)) {
                $this->outputBytes .= $viewOutput;
            }

            // Run the cmd and get any produced message.
            $producedMsg = $this->runCmd($cmd);

            // If no message was produced, we're done with this update cycle.
            if ($producedMsg === null) {
                break;
            }

            // A cmd produced a message — feed it back into update().
            $msg = $producedMsg;
        }

        return [$model, $cmd ?? null];
    }

    /**
     * Extract the model from a Program instance.
     *
     * Uses the Program's public {@see Program::model()} accessor rather than
     * Reflection. Not `getModel()`: that is a deprecated delegating alias
     * candy-core keeps only for external callers, and the harness must not be
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

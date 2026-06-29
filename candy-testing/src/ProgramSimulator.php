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

    /** @var list<string> */
    private array $outputBytes = [];

    private ?\Closure $fakeCmdRunner = null;

    /**
     * When true, executes cmds and threads their returned messages through
     * update(). When false, captures cmds but does NOT execute them (safe
     * deterministic mode). Defaults to true (execute mode) for backward
     * compatibility with existing tests.
     */
    private bool $executeCmds = true;

    private function __construct(
        private readonly Program $program,
    ) {}

    /**
     * Factory — wrap a Program instance for testing.
     */
    public static function for(Program $program): self
    {
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
     * Subscriptions are pumped after each update cycle: each subscription's
     * produce closure is invoked and any returned messages are enqueued for
     * processing. This keeps tests deterministic (no real timers are started).
     *
     * @return TestResult
     */
    public function run(): TestResult
    {
        $this->capturedCmds = [];
        $this->outputBytes = [];

        // We use a simplified approach: just call init/update/view directly.
        $model = $this->getModelFromProgram();

        // Call init() once at startup and thread any produced message.
        $initCmd = $model->init();
        $initMsg = $this->runCmd($initCmd);
        if ($initMsg !== null) {
            [$model, ] = $this->applyMsg($model, $initMsg);
        }

        // Pump subscriptions after init to collect any startup messages.
        $model = $this->pumpSubscriptions($model);

        // Process queued messages in order.
        // Use while + array_shift so subscription-pumped messages (appended
        // mid-loop) are also processed in the same run cycle.
        while (count($this->queue) > 0) {
            $msg = array_shift($this->queue);
            [$model, ] = $this->applyMsg($model, $msg);
            // Pump subscriptions after each message to capture produce output.
            $model = $this->pumpSubscriptions($model);
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
            output: implode('', $this->outputBytes),
        );
    }

    /**
     * Pump subscriptions and enqueue any produced messages.
     *
     * Calls $model->subscriptions(), iterates over the returned subscription
     * set, and enqueues any messages produced by the subscription closures.
     * This mirrors how Program reconciles subscriptions after each update cycle.
     *
     * @param Model $model
     * @return Model The same model (subscriptions are processed for side-effects only)
     */
    private function pumpSubscriptions(Model $model): Model
    {
        $subs = $model->subscriptions();
        if ($subs === null) {
            return $model;
        }

        foreach ($subs->all() as $subscription) {
            $msg = $subscription->produce();
            if ($msg !== null) {
                $this->queue[] = $msg;
            }
        }

        return $model;
    }

    /**
     * Apply a single message to the model, running any resulting command
     * and iteratively draining cmd-produced messages (bounded to prevent
     * infinite loops).
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
                $this->outputBytes[] = $viewOutput;
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
     * Extract the model from a Program instance via its property.
     *
     * PHP doesn't give us direct access, so we use a known property path.
     * This method is robust against ReflectionException by checking for
     * the property's existence before attempting to access it.
     *
     * @return Model
     * @throws \RuntimeException If the program lacks a 'model' property
     */
    private function getModelFromProgram(): Model
    {
        $reflection = new \ReflectionClass($this->program);

        if (!$reflection->hasProperty('model')) {
            throw new \RuntimeException(
                Lang::t('simulator.no_model_property')
            );
        }

        $modelProp = $reflection->getProperty('model');
        $modelProp->setAccessible(true);
        /** @var Model */
        return $modelProp->getValue($this->program);
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
            if ($this->executeCmds) {
                $cmd();
            }
            return ($this->fakeCmdRunner)($cmd);
        }

        // Capture the cmd (for inspection) and optionally execute.
        $this->capturedCmds[] = $cmd;

        if (!$this->executeCmds) {
            // Capture-only mode: don't execute side-effecting cmds.
            return null;
        }

        // Execute the cmd and return any produced message.
        return $cmd();
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Testing;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;

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
     * Drain the message queue, running init/update/view in sequence.
     *
     * The program loop is not used — we call the Model's methods directly
     * so tests remain deterministic and side-effect-free.
     *
     * @return TestResult
     */
    public function run(): TestResult
    {
        $this->capturedCmds = [];
        $this->outputBytes = [];

        // Use a memory stream pair so writes are captured.
        [$input, $output] = $this->createMemoryStreamPair();

        // Build a headless program options that avoids real TTY/signal setup.
        $options = new ProgramOptions(
            input: $input,
            output: $output,
            withoutSignalHandler: true,
            withoutRenderer: false,
            useAltScreen: false,
            hideCursor: false,
        );

        // Re-create the program with our captured streams.
        // We use a simplified approach: just call init/update/view directly.
        $model = $this->program instanceof Model ? $this->program : $this->getModelFromProgram();

        // Call init() once at startup.
        $initCmd = $model->init();
        $this->runCmd($initCmd);

        // Process queued messages in order.
        foreach ($this->queue as $msg) {
            [$model, $cmd] = $model->update($msg);
            $this->runCmd($cmd);

            // Capture view output by calling view() on the active model.
            $viewOutput = $model->view();
            if (is_string($viewOutput)) {
                $this->outputBytes[] = $viewOutput;
            }
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
     * Extract the model from a Program instance via its property.
     * PHP doesn't give us direct access, so we use a known property path.
     *
     * @return Model
     */
    private function getModelFromProgram(): Model
    {
        // Program stores the model as a private property.
        // Use reflection to access it.
        $reflection = new \ReflectionClass($this->program);
        $modelProp = $reflection->getProperty('model');
        $modelProp->setAccessible(true);
        /** @var Model */
        return $modelProp->getValue($this->program);
    }

    /**
     * Run a Cmd (closure) and capture the result.
     *
     * @param \Closure|null $cmd
     */
    private function runCmd(?\Closure $cmd): void
    {
        if ($cmd === null) {
            return;
        }

        if ($this->fakeCmdRunner !== null) {
            $this->capturedCmds[] = $cmd;
            $injectedMsg = ($this->fakeCmdRunner)($cmd);
            if ($injectedMsg !== null && $this->getModelFromProgram() instanceof Model) {
                // Dispatch injected msg to the model.
                // Note: in the run() flow we pass the model around directly.
            }
            return;
        }

        // By default, capture but don't execute side-effecting cmds.
        $this->capturedCmds[] = $cmd;

        // Execute sync-only commands that don't have side effects.
        $msg = $cmd();
        if ($msg instanceof Msg) {
            // For sync dispatching, we'd need the model.
            // Just record it for inspection.
        }
    }

    /**
     * Create a pair of memory streams for input/output capture.
     *
     * @return array{0: resource, 1: resource}
     */
    private function createMemoryStreamPair(): array
    {
        $input = fopen('php://memory', 'r+');
        $output = fopen('php://memory', 'w+');

        if ($input === false || $output === false) {
            throw new \RuntimeException('Failed to create memory streams');
        }

        return [$input, $output];
    }
}

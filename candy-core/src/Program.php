<?php

declare(strict_types=1);

namespace CandyCore\Core;

use CandyCore\Core\Msg\QuitMsg;
use CandyCore\Core\Msg\WindowSizeMsg;
use CandyCore\Core\Util\Ansi;
use CandyCore\Core\Util\Tty;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

/**
 * The Elm-architecture runtime: ties together input, model, render,
 * and the event loop.
 *
 * Lifecycle (handled internally):
 *
 *   1. Optionally enter alt screen + hide cursor + enable raw mode.
 *   2. Emit an initial WindowSizeMsg.
 *   3. Run model->init() and dispatch any returned Cmd.
 *   4. Watch the input stream; parse bytes into Msgs and feed update().
 *   5. Render the latest view() at the configured framerate.
 *   6. On QuitMsg / SIGINT / loop stop: tear down and return the model.
 */
final class Program
{
    private Model $model;
    private readonly LoopInterface $loop;
    /** @var resource */
    private $input;
    /** @var resource */
    private $output;
    private readonly InputReader $reader;
    private readonly Renderer $renderer;
    private readonly Tty $tty;
    private bool $dirty = true;
    private bool $running = false;
    /** @var list<Msg> */
    private array $pending = [];

    public function __construct(
        Model $initialModel,
        private readonly ProgramOptions $options = new ProgramOptions(),
    ) {
        $this->model = $initialModel;
        $this->loop  = $options->loop ?? Loop::get();
        $this->input  = $options->input  ?? STDIN;
        $this->output = $options->output ?? STDOUT;
        $this->reader = new InputReader();
        $this->renderer = new Renderer($this->output);
        $this->tty = new Tty($this->input);
    }

    /**
     * Run the program loop until a QuitMsg is dispatched (or the loop is
     * stopped externally). Returns the final Model.
     */
    public function run(): Model
    {
        $this->running = true;
        $this->setupTerminal();
        $this->installSignalHandlers();

        // Initial size + init Cmd.
        $size = $this->tty->size();
        $this->dispatch(new WindowSizeMsg($size['cols'], $size['rows']));

        $initCmd = $this->model->init();
        if ($initCmd !== null) {
            $this->scheduleCmd($initCmd);
        }

        // Drain any messages queued via send() before run().
        $this->drainPending();

        // Pending dispatch may have already requested quit. Skip the loop.
        if (!$this->running) {
            $this->teardownTerminal();
            return $this->model;
        }

        // Stream watcher.
        @stream_set_blocking($this->input, false);
        $this->loop->addReadStream($this->input, function ($stream): void {
            $bytes = @fread($stream, 4096);
            if ($bytes === false || $bytes === '') {
                return;
            }
            foreach ($this->reader->parse($bytes) as $msg) {
                $this->dispatch($msg);
                if (!$this->running) {
                    return;
                }
            }
        });

        // Render tick.
        $tickInterval = 1.0 / max(1.0, $this->options->framerate);
        $tickTimer = $this->loop->addPeriodicTimer($tickInterval, function (): void {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            if ($this->dirty) {
                $this->renderer->render($this->model->view());
                $this->dirty = false;
            }
        });

        $this->loop->run();

        $this->loop->cancelTimer($tickTimer);
        $this->loop->removeReadStream($this->input);

        $this->teardownTerminal();
        return $this->model;
    }

    /**
     * Queue a Msg for dispatch. If the loop is running it is delivered on the
     * next tick; otherwise it is buffered until run() drains pending messages.
     */
    public function send(Msg $msg): void
    {
        if (!$this->running) {
            $this->pending[] = $msg;
            return;
        }
        $this->loop->futureTick(function () use ($msg): void {
            $this->dispatch($msg);
        });
    }

    public function quit(): void
    {
        $this->send(new QuitMsg());
    }

    private function dispatch(Msg $msg): void
    {
        if ($msg instanceof BatchMsg) {
            foreach ($msg->cmds as $cmd) {
                $this->scheduleCmd($cmd);
            }
            return;
        }
        if ($msg instanceof QuitMsg) {
            $this->running = false;
            $this->loop->stop();
            return;
        }

        [$nextModel, $cmd] = $this->model->update($msg);
        $this->model = $nextModel;
        $this->dirty = true;
        if ($cmd !== null) {
            $this->scheduleCmd($cmd);
        }
    }

    private function scheduleCmd(\Closure $cmd): void
    {
        $this->loop->futureTick(function () use ($cmd): void {
            $msg = $cmd();
            if ($msg !== null) {
                $this->dispatch($msg);
            }
        });
    }

    private function drainPending(): void
    {
        $queue = $this->pending;
        $this->pending = [];
        foreach ($queue as $msg) {
            $this->dispatch($msg);
            if (!$this->running) {
                return;
            }
        }
    }

    private function setupTerminal(): void
    {
        if ($this->options->useAltScreen) {
            fwrite($this->output, Ansi::altScreenEnter());
        }
        if ($this->options->hideCursor) {
            fwrite($this->output, Ansi::cursorHide());
        }
        $this->tty->enableRawMode();
    }

    private function teardownTerminal(): void
    {
        $this->tty->restore();
        if ($this->options->hideCursor) {
            fwrite($this->output, Ansi::cursorShow());
        }
        if ($this->options->useAltScreen) {
            fwrite($this->output, Ansi::altScreenLeave());
        }
    }

    private function installSignalHandlers(): void
    {
        if (!$this->options->catchInterrupts || !function_exists('pcntl_signal')) {
            return;
        }
        pcntl_signal(SIGINT, function (): void {
            $this->running = false;
            $this->loop->stop();
        });
        if (defined('SIGWINCH')) {
            pcntl_signal(SIGWINCH, function (): void {
                $size = $this->tty->size();
                $this->send(new WindowSizeMsg($size['cols'], $size['rows']));
            });
        }
    }
}

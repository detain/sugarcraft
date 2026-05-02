<?php

declare(strict_types=1);

namespace CandyCore\Core;

use CandyCore\Core\Msg\ColorProfileMsg;
use CandyCore\Core\Msg\EnvMsg;
use CandyCore\Core\Msg\ExecMsg;
use CandyCore\Core\Msg\InterruptMsg;
use CandyCore\Core\Msg\QuitMsg;
use CandyCore\Core\Msg\ResumeMsg;
use CandyCore\Core\Msg\SuspendMsg;
use CandyCore\Core\Msg\WindowSizeMsg;
use CandyCore\Core\Util\Ansi;
use CandyCore\Core\Util\ColorProfile;
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
    private bool $escapeFlushPending = false;
    private bool $running = false;
    /** @var list<Msg> */
    private array $pending = [];
    private ?string $lastWindowTitle = null;
    private ?Cursor $lastCursor = null;
    private bool $lastCursorHidden = false;
    private ?Progress $lastProgress = null;
    private ?Util\Color $lastForegroundColor = null;
    private ?Util\Color $lastBackgroundColor = null;
    private ?MouseMode $activeMouseMode = null;
    private ?bool $activeFocusReporting = null;
    private ?bool $activeBracketedPaste = null;

    public function __construct(
        Model $initialModel,
        private readonly ProgramOptions $options = new ProgramOptions(),
    ) {
        $this->model = $initialModel;
        $this->loop  = $options->loop ?? Loop::get();
        $this->input  = $options->input  ?? STDIN;
        $this->output = $options->output ?? STDOUT;
        // openTty: if requested AND we'd otherwise be reading from a
        // piped stdin, open /dev/tty directly so the program can still
        // see keys. Caller-supplied resources always win — the flag
        // only kicks in when no input/output were passed.
        if ($options->openTty && $options->input === null && $options->output === null) {
            $opened = Tty::openTty();
            if ($opened !== null) {
                [$this->input, $this->output] = $opened;
            }
        }
        $this->reader = new InputReader();
        $this->renderer = new Renderer(
            $this->output,
            inline:   $options->inlineMode,
            cellDiff: $options->cellDiffRenderer,
        );
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

        // Initial size + env + colour-profile + init Cmd. Each
        // honours the matching ProgramOptions override when set —
        // unset falls back to the live tty / getenv() / detect().
        $size = $this->options->windowSize ?? $this->tty->size();
        $this->dispatch(new WindowSizeMsg($size['cols'], $size['rows']));
        $this->dispatch(new EnvMsg($this->options->environment ?? $this->collectEnv()));
        $this->dispatch(new ColorProfileMsg($this->options->colorProfile ?? ColorProfile::detect()));

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
                // Real EOF: drop the watcher so the loop doesn't spin
                // delivering readable notifications for a closed pipe.
                if (feof($stream)) {
                    $this->loop->removeReadStream($stream);
                }
                return;
            }
            foreach ($this->reader->parse($bytes) as $msg) {
                $this->dispatch($msg);
                if (!$this->running) {
                    return;
                }
            }
            // A lone ESC byte is buffered for disambiguation (could be a
            // CSI / Alt-key prefix). Promote it to a standalone Escape
            // after a brief delay if no follow-up arrives.
            if ($this->reader->hasPendingEscape()) {
                $this->scheduleEscapeFlush();
            }
        });

        // Render tick.
        $tickInterval = 1.0 / max(1.0, $this->options->framerate);
        $tickTimer = $this->loop->addPeriodicTimer($tickInterval, function (): void {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            if ($this->dirty) {
                $this->renderFrame();
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
        if ($msg instanceof SequenceMsg) {
            $this->scheduleSequence($msg->cmds);
            return;
        }
        if ($msg instanceof TickRequest) {
            $this->loop->addTimer($msg->seconds, function () use ($msg): void {
                $produced = ($msg->produce)();
                if ($produced !== null) {
                    $this->dispatch($produced);
                }
            });
            return;
        }
        if ($msg instanceof RawMsg) {
            // Side-channel: write bytes verbatim, no re-render, no model
            // notification. Caller is responsible for cursor-area effects.
            fwrite($this->output, $msg->bytes);
            return;
        }
        if ($msg instanceof PrintMsg) {
            // Print above the program region. The renderer's diff state
            // is now stale, so reset it so the next render() repaints
            // every visible row.
            fwrite($this->output, $msg->text . "\n");
            $this->renderer->reset();
            $this->dirty = true;
            return;
        }
        if ($msg instanceof ExecRequest) {
            $this->runExec($msg);
            return;
        }
        if ($msg instanceof SuspendMsg) {
            $this->suspendProgram();
            return;
        }
        if ($msg instanceof InterruptMsg) {
            $this->running = false;
            $this->loop->stop();
            return;
        }
        if ($msg instanceof QuitMsg) {
            $this->running = false;
            $this->loop->stop();
            return;
        }

        // WithFilter pre-processor.
        if ($this->options->filter !== null) {
            $filtered = ($this->options->filter)($this->model, $msg);
            if ($filtered === null) {
                return;
            }
            $msg = $filtered;
        }

        [$nextModel, $cmd] = $this->model->update($msg);
        $this->model = $nextModel;
        $this->dirty = true;
        if ($cmd !== null) {
            $this->scheduleCmd($cmd);
        }
    }

    /**
     * Run the supplied Cmds one at a time — wait for each Cmd's Msg
     * to be dispatched (and processed by update()) before starting
     * the next.
     *
     * @param list<\Closure> $cmds
     */
    private function scheduleSequence(array $cmds): void
    {
        if ($cmds === []) {
            return;
        }
        $remaining = $cmds;
        $runNext = null;
        $runNext = function () use (&$remaining, &$runNext): void {
            if ($remaining === []) {
                return;
            }
            $cmd = array_shift($remaining);
            $msg = $cmd();
            if ($msg !== null) {
                $this->dispatch($msg);
            }
            // Schedule the next one on a future tick so any update()
            // triggered by $msg has a chance to run first.
            $this->loop->futureTick($runNext);
        };
        $this->loop->futureTick($runNext);
    }

    /**
     * Run an external command with the TTY released. Tears down
     * terminal state, runs the child, restores state, then dispatches
     * the result as `ExecMsg` (and optionally a model-shaped follow-up
     * via the `$onComplete` callback).
     */
    private function runExec(ExecRequest $req): void
    {
        $this->teardownTerminal();
        $err = null;
        $exit = -1;
        $stdout = '';
        $stderr = '';

        try {
            $descriptors = $req->captureOutput
                ? [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']]
                : [0 => STDIN, 1 => STDOUT, 2 => STDERR];
            $cmd = is_array($req->command) ? $req->command : (string) $req->command;
            $proc = @proc_open($cmd, $descriptors, $pipes);
            if (!is_resource($proc)) {
                throw new \RuntimeException('proc_open failed for: ' . (is_array($cmd) ? implode(' ', $cmd) : $cmd));
            }
            if ($req->captureOutput) {
                $stdout = is_resource($pipes[1]) ? (string) stream_get_contents($pipes[1]) : '';
                $stderr = is_resource($pipes[2]) ? (string) stream_get_contents($pipes[2]) : '';
                if (is_resource($pipes[1])) fclose($pipes[1]);
                if (is_resource($pipes[2])) fclose($pipes[2]);
            }
            $exit = proc_close($proc);
        } catch (\Throwable $t) {
            $err = $t;
        }
        $this->setupTerminal();
        $this->renderer->reset();
        $this->dirty = true;

        $produced = $req->onComplete !== null
            ? ($req->onComplete)($exit, $stdout, $stderr, $err)
            : null;
        $this->dispatch(new ExecMsg($exit, $err, $stdout, $stderr));
        if ($produced !== null) {
            $this->dispatch($produced);
        }
    }

    /**
     * Implement Ctrl-Z semantics: tear down, deliver SuspendMsg, raise
     * SIGTSTP on this process group with the default handler so the
     * process actually stops, then on SIGCONT restore terminal state
     * and dispatch ResumeMsg.
     */
    private function suspendProgram(): void
    {
        // Notify the model first so it can stash any state it cares about.
        if ($this->options->filter === null) {
            [$nextModel, $cmd] = $this->model->update(new SuspendMsg());
            $this->model = $nextModel;
            if ($cmd !== null) {
                $this->scheduleCmd($cmd);
            }
        }

        if (!function_exists('pcntl_signal') || !defined('SIGTSTP') || !defined('SIGCONT')) {
            // Without pcntl we can't actually suspend; emit Resume
            // immediately so the model knows nothing happened.
            $this->dispatch(new ResumeMsg());
            return;
        }

        $this->teardownTerminal();
        // Reset SIGTSTP to default and re-raise it on this process.
        pcntl_signal(SIGTSTP, SIG_DFL);
        if (function_exists('posix_getpid')) {
            posix_kill(posix_getpid(), SIGTSTP);
        }
        // When the process resumes (SIGCONT) it picks back up here.
        // Reinstall handlers + terminal state.
        pcntl_signal(SIGTSTP, function (): void {
            $this->send(new SuspendMsg());
        });
        $this->setupTerminal();
        $this->renderer->reset();
        $this->dirty = true;
        $this->dispatch(new ResumeMsg());
    }

    /**
     * Promote a buffered lone-ESC byte to a {@see KeyMsg} after a short
     * settling window. If a follow-up byte arrives in time and resolves
     * the buffer (e.g. a CSI sequence), {@see InputReader::flushPending()}
     * returns null and nothing is dispatched.
     */
    private function scheduleEscapeFlush(): void
    {
        if ($this->escapeFlushPending) {
            return;
        }
        $this->escapeFlushPending = true;
        $this->loop->addTimer(0.05, function (): void {
            $this->escapeFlushPending = false;
            $msg = $this->reader->flushPending();
            if ($msg !== null) {
                $this->dispatch($msg);
            }
        });
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
        // Bubble Tea v2 enables grapheme-cluster mode (DEC 2027) by
        // default to fix long-standing emoji-width drift. We follow.
        if ($this->options->unicodeMode) {
            fwrite($this->output, Ansi::unicodeOn());
        }
        match ($this->options->mouseMode) {
            MouseMode::CellMotion => fwrite($this->output, Ansi::mouseCellMotionOn()),
            MouseMode::AllMotion  => fwrite($this->output, Ansi::mouseAllMotionOn()),
            MouseMode::Off        => null,
        };
        $this->activeMouseMode = $this->options->mouseMode;
        if ($this->options->reportFocus) {
            fwrite($this->output, Ansi::focusReportingOn());
        }
        $this->activeFocusReporting = $this->options->reportFocus;
        if ($this->options->bracketedPaste) {
            fwrite($this->output, Ansi::bracketedPasteOn());
        }
        $this->activeBracketedPaste = $this->options->bracketedPaste;
        $this->tty->enableRawMode();
    }

    private function teardownTerminal(): void
    {
        $this->tty->restore();
        if ($this->activeBracketedPaste) {
            fwrite($this->output, Ansi::bracketedPasteOff());
        }
        if ($this->activeFocusReporting) {
            fwrite($this->output, Ansi::focusReportingOff());
        }
        match ($this->activeMouseMode) {
            MouseMode::CellMotion => fwrite($this->output, Ansi::mouseCellMotionOff()),
            MouseMode::AllMotion  => fwrite($this->output, Ansi::mouseAllMotionOff()),
            MouseMode::Off, null  => null,
        };
        if ($this->options->unicodeMode) {
            fwrite($this->output, Ansi::unicodeOff());
        }
        if ($this->options->hideCursor) {
            fwrite($this->output, Ansi::cursorShow());
        }
        if ($this->options->useAltScreen) {
            fwrite($this->output, Ansi::altScreenLeave());
        }
    }

    /**
     * Render one frame. If the model returned a {@see View}, emit
     * any side-effect escapes (window title, cursor shape) that
     * differ from the previously-emitted set, then paint the body.
     */
    private function renderFrame(): void
    {
        if ($this->options->withoutRenderer) {
            // Headless mode: still call view() so the model's
            // computation runs (and any errors surface) but skip
            // emitting any output.
            $this->model->view();
            return;
        }
        $rendered = $this->model->view();
        if ($rendered instanceof View) {
            $this->applyViewSideEffects($rendered);
            $body = $rendered->body;
        } else {
            $body = $rendered;
        }
        $this->renderer->render($body);
    }

    private function applyViewSideEffects(View $view): void
    {
        if ($view->mouseMode !== null && $view->mouseMode !== $this->activeMouseMode) {
            // Turn the previous mode off, then the new one on. Both
            // pairs happily no-op when fed back to themselves.
            match ($this->activeMouseMode) {
                MouseMode::CellMotion => fwrite($this->output, Ansi::mouseCellMotionOff()),
                MouseMode::AllMotion  => fwrite($this->output, Ansi::mouseAllMotionOff()),
                MouseMode::Off, null  => null,
            };
            match ($view->mouseMode) {
                MouseMode::CellMotion => fwrite($this->output, Ansi::mouseCellMotionOn()),
                MouseMode::AllMotion  => fwrite($this->output, Ansi::mouseAllMotionOn()),
                MouseMode::Off        => null,
            };
            $this->activeMouseMode = $view->mouseMode;
        }

        if ($view->reportFocus !== null && $view->reportFocus !== $this->activeFocusReporting) {
            fwrite($this->output, $view->reportFocus
                ? Ansi::focusReportingOn()
                : Ansi::focusReportingOff());
            $this->activeFocusReporting = $view->reportFocus;
        }

        if ($view->bracketedPaste !== null && $view->bracketedPaste !== $this->activeBracketedPaste) {
            fwrite($this->output, $view->bracketedPaste
                ? Ansi::bracketedPasteOn()
                : Ansi::bracketedPasteOff());
            $this->activeBracketedPaste = $view->bracketedPaste;
        }

        if ($view->windowTitle !== null && $view->windowTitle !== $this->lastWindowTitle) {
            fwrite($this->output, Ansi::setWindowTitle($view->windowTitle));
            $this->lastWindowTitle = $view->windowTitle;
        }

        if ($view->progressBar !== null && !$this->progressEquals($view->progressBar, $this->lastProgress)) {
            fwrite($this->output, Ansi::setProgressBar($view->progressBar->state, $view->progressBar->percent));
            $this->lastProgress = $view->progressBar;
        }

        if ($view->foregroundColor !== null && !$this->colorEquals($view->foregroundColor, $this->lastForegroundColor)) {
            $c = $view->foregroundColor;
            fwrite($this->output, Ansi::setForegroundColor($c->r, $c->g, $c->b));
            $this->lastForegroundColor = $c;
        }

        if ($view->backgroundColor !== null && !$this->colorEquals($view->backgroundColor, $this->lastBackgroundColor)) {
            $c = $view->backgroundColor;
            fwrite($this->output, Ansi::setBackgroundColor($c->r, $c->g, $c->b));
            $this->lastBackgroundColor = $c;
        }

        if ($view->cursor === null) {
            // null cursor → hide.
            if (!$this->lastCursorHidden) {
                fwrite($this->output, Ansi::cursorHide());
                $this->lastCursorHidden = true;
            }
            return;
        }

        // Show cursor if it was hidden.
        if ($this->lastCursorHidden) {
            fwrite($this->output, Ansi::cursorShow());
            $this->lastCursorHidden = false;
        }

        $cur  = $view->cursor;
        $prev = $this->lastCursor;
        if ($prev === null
            || $prev->shape !== $cur->shape
            || $prev->blink !== $cur->blink) {
            fwrite($this->output, Ansi::cursorShape($cur->shape, $cur->blink));
        }
        if ($cur->row !== null && $cur->col !== null) {
            fwrite($this->output, Ansi::cursorTo($cur->row, $cur->col));
        }
        $this->lastCursor = $cur;
    }

    private function progressEquals(?Progress $a, ?Progress $b): bool
    {
        return $a !== null && $b !== null
            && $a->state === $b->state
            && $a->percent === $b->percent;
    }

    private function colorEquals(?Util\Color $a, ?Util\Color $b): bool
    {
        return $a !== null && $b !== null
            && $a->r === $b->r && $a->g === $b->g && $a->b === $b->b;
    }

    /**
     * Snapshot the current process environment for the startup
     * {@see EnvMsg}. We pull from PHP's `getenv()` (no args) which
     * returns every variable PHP knows about, regardless of the
     * `variables_order` ini setting.
     *
     * @return array<string,string>
     */
    private function collectEnv(): array
    {
        $env = getenv();
        if (!is_array($env)) {
            return [];
        }
        /** @var array<string,string> $env */
        return $env;
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
        // Ctrl-Z (SIGTSTP) → emit SuspendMsg via send() so the
        // dispatcher runs the actual suspend/resume cycle inside the
        // event loop.
        if (defined('SIGTSTP')) {
            pcntl_signal(SIGTSTP, function (): void {
                $this->send(new SuspendMsg());
            });
        }
        // SIGCONT can fire spuriously (kill -CONT $$) — turn it into a
        // ResumeMsg so models that care can re-emit state.
        if (defined('SIGCONT')) {
            pcntl_signal(SIGCONT, function (): void {
                $this->send(new ResumeMsg());
            });
        }
    }
}

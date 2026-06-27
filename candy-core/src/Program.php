<?php

declare(strict_types=1);

namespace SugarCraft\Core;

use SugarCraft\Core\Msg\ColorProfileMsg;
use SugarCraft\Core\Msg\EnvMsg;
use SugarCraft\Core\Msg\ExceptionMsg;
use SugarCraft\Core\Msg\ExecMsg;
use SugarCraft\Core\Msg\InterruptMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\ResumeMsg;
use SugarCraft\Core\Msg\ScreenStackPoppedMsg;
use SugarCraft\Core\Msg\ScreenStackPushedMsg;
use SugarCraft\Core\Msg\SubscriptionsMsg;
use SugarCraft\Core\Msg\SuspendMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Tty;
use SugarCraft\Core\Util\NullLogger;
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
    /** Signature of the image layer painted last frame, to skip redundant repaints. */
    private string $lastImageSignature = '';
    /** The resolved body rendered last frame; `null` until the first render. */
    private ?string $lastRenderedBody = null;
    private ?Util\Color $lastForegroundColor = null;
    private ?Util\Color $lastBackgroundColor = null;
    private ?MouseMode $activeMouseMode = null;
    private ?bool $activeFocusReporting = null;
    private ?bool $activeBracketedPaste = null;
    private ?Recorder $recorder = null;
    /** @var array<string, array{0: mixed, 1: Subscription}> */
    private array $activeSubscriptions = [];
    /** @var \Closure(\Throwable): void */
    private \Closure $exceptionHandler;
    private float $lastFrameDuration = 0.0;

    /**
     * Wrap a {@see Model} with runtime options.
     *
     * The default {@see ProgramOptions} hits sane defaults: STDIN /
     * STDOUT, alt-screen on, raw mode on, mouse off, signal handlers
     * installed. Override per-field for inline mode, custom streams,
     * or to wire a non-default {@see Loop}.
     */
    public function __construct(
        Model $initialModel,
        private readonly ProgramOptions $options = new ProgramOptions(),
    ) {
        $this->model = $initialModel;
        $this->loop  = $options->loop ?? Loop::get();
        $this->input  = $options->input  ?? STDIN;
        $this->output = $options->output ?? STDOUT;
        $this->exceptionHandler = static fn(\Throwable $t) => throw $t;
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
        $this->tty = new Tty($this->input, $options->termios);
    }

    /**
     * Tee the program's input bytes, output bytes, and lifecycle events
     * to the given {@see Recorder}. Pass null to detach.
     *
     * Returns `$this` so callers can chain at construction:
     * `(new Program($model))->withRecorder($recorder)->run();`
     *
     * Only one recorder per program — calling again replaces the previous
     * one (and does NOT close it; that's the caller's responsibility).
     * The recorder's {@see Recorder::close()} fires automatically when
     * the program loop ends, after a final {@see Recorder::recordQuit()}
     * if the program exited via QuitMsg.
     */
    public function withRecorder(?Recorder $recorder): self
    {
        $this->recorder = $recorder;
        $this->renderer->setRecorder($recorder);
        return $this;
    }

    /**
     * Attach a logger to receive program lifecycle events.
     *
     * @param object $logger Object with PSR-3 style log methods (info, error, warning, etc.)
     */
    public function withLogger(object $logger): self
    {
        // Logger is stored but not yet integrated; reserved for future use.
        return $this;
    }

    /**
     * Override the exception handler that receives any uncaught throwable
     * during the program loop. The default re-throws.
     *
     * @param \Closure(\Throwable): void $handler
     */
    public function withExceptionHandler(\Closure $handler): self
    {
        $next = clone $this;
        $next->exceptionHandler = $handler;
        return $next;
    }

    /**
     * Return the duration in seconds of the last rendered frame.
     */
    public function lastFrameDuration(): float
    {
        return $this->lastFrameDuration;
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

        $initCmd = $this->activeModel()->init();
        if ($initCmd !== null) {
            $this->scheduleCmd($initCmd);
        }

        // Drain any messages queued via send() before run().
        $this->drainPending();

        // Initial subscription reconciliation — start whatever the model wants.
        $this->reconcileWantedSubscriptions();

        // Pending dispatch may have already requested quit. Skip the loop.
        if (!$this->running) {
            $this->cancelAllSubscriptions();
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
            $this->recorder?->recordInputBytes($bytes);
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
        $this->cancelAllSubscriptions();

        $this->teardownTerminal();
        $this->recorder?->close();
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

    /**
     * Cooperative shutdown — enqueues a {@see QuitMsg}. The next
     * `update()` cycle observes it; the model can react to QuitMsg
     * before the runtime tears down. Pair with {@see kill()} when
     * you need an immediate hard-stop with no model interaction.
     */
    public function quit(): void
    {
        $this->send(new QuitMsg());
    }

    /**
     * Hard-stop the program. Unlike {@see quit()} (which routes through
     * the message queue and lets the model react to {@see QuitMsg} on
     * its next update tick), {@see kill()} stops the loop synchronously,
     * tears down the terminal, and returns. The model is not notified.
     */
    public function kill(): void
    {
        if (!$this->running) {
            return;
        }
        $this->running = false;
        $this->loop->stop();
    }

    /**
     * Block until the loop has stopped. Mirrors bubbletea's
     * `Program.Wait()`. In PHP {@see run()} is itself a blocking call
     * so this is mainly useful as a status query — it returns true if
     * the program is no longer running.
     */
    public function wait(): bool
    {
        return !$this->running;
    }

    /**
     * Release the terminal back to a cooked / line-buffered state so
     * the caller can shell out to `$EDITOR`-style external commands
     * without fighting the renderer for raw-mode keys. Pair with
     * {@see restoreTerminal()} once the external command exits.
     */
    public function releaseTerminal(): void
    {
        $this->teardownTerminal();
    }

    /**
     * Re-acquire the terminal after a {@see releaseTerminal()} call:
     * re-enter alt screen / raw mode / mouse / paste / focus modes
     * exactly as run() did at startup, then trigger a full repaint on
     * the next tick.
     */
    public function restoreTerminal(): void
    {
        $this->setupTerminal();
        $this->renderer->reset();
        $this->lastRenderedBody = null;
        $this->lastImageSignature = '';
        $this->dirty = true;
    }

    /**
     * Print a single line above the program region. Equivalent to
     * sending `Cmd::println($text)()` directly through the message
     * queue, but reachable as an instance method for callers that hold
     * a {@see Program} reference (matches bubbletea's
     * `Program.Println(...)`).
     */
    public function println(string ...$parts): void
    {
        $this->send(new PrintMsg(implode(' ', $parts)));
    }

    /**
     * Queue a PrintMsg with a formatted string.
     *
     * Mirrors the existing `Cmd::printf($format, ...$args)()` factory but
     * reachable as an instance method for callers that hold a Program
     * reference (matches bubbletea's `Program.Printf(...)`).
     */
    public function printf(string $format, mixed ...$args): void
    {
        $this->send(new PrintMsg(sprintf($format, ...$args)));
    }

    /**
     *
     * @return array<string,string>
     */
    private function collectEnv(): array
    {
        $env = getenv();
        // getenv() returns array<string,string>|false; the false case
        // only exists on PHP < 8.1 which this project doesn't support.
        /** @var array<string,string> */
        return $env;
    }

    /**
     * Enable bracketed paste mode at runtime.
     *
     * Writes Ansi::bracketedPasteOn() to the output and sets
     * $activeBracketedPaste to true. Returns true on success.
     */
    public function enableBracketedPaste(): bool
    {
        $this->writeOutput(Ansi::bracketedPasteOn());
        $this->activeBracketedPaste = true;
        return true;
    }

    /**
     * Disable bracketed paste mode at runtime.
     *
     * Writes Ansi::bracketedPasteOff() to the output and sets
     * $activeBracketedPaste to false. Returns true on success.
     */
    public function disableBracketedPaste(): bool
    {
        $this->writeOutput(Ansi::bracketedPasteOff());
        $this->activeBracketedPaste = false;
        return true;
    }

    /**
     * Return the model that should receive update/view calls.
     * If the root model exposes screens(), route to the active screen.
     */
    private function activeModel(): Model
    {
        if ($this->model instanceof ScreenStackCapable) {
            $screens = $this->model->screens();
            if (!$screens->isEmpty()) {
                return $screens->current()->model;
            }
        }
        return $this->model;
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
            $this->writeOutput($msg->bytes);
            return;
        }
        if ($msg instanceof PrintMsg) {
            // Print above the program region. The renderer's diff state
            // is now stale, so reset it so the next render() repaints
            // every visible row.
            $this->writeOutput($msg->text . "\n");
            $this->renderer->reset();
            $this->lastRenderedBody = null;
            $this->lastImageSignature = '';
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
            // Record the quit marker, but keep the recorder open so
            // teardownTerminal()'s output bytes (alt-screen leave, mode
            // resets, etc.) make it into the cassette. run() closes the
            // recorder once the loop exits and teardown is complete —
            // crucial for replay parity, since a fresh Program emits
            // those same bytes on its way out.
            $this->recorder?->recordQuit();
            $this->running = false;
            $this->loop->stop();
            return;
        }
        if ($msg instanceof WindowSizeMsg) {
            // Recorded for both the startup dispatch and SIGWINCH-driven
            // updates; the model still receives the Msg via update() below.
            $this->recorder?->recordResize($msg->cols, $msg->rows);
        }
        if ($msg instanceof AsyncCmd) {
            $msg->promise->then(function (?Msg $resolvedMsg): void {
                if ($resolvedMsg !== null) {
                    $this->dispatch($resolvedMsg);
                }
            })->otherwise(function (\Throwable $e): void {
                // Dispatch ExceptionMsg for backward compat and also
                // invoke the user-configured exception handler.
                $this->dispatch(new ExceptionMsg($e));
                ($this->exceptionHandler)($e);
            });
            return;
        }
        if ($msg instanceof SubscriptionsMsg) {
            $this->reconcileSubscriptions($msg->subscriptions);
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

        // ScreenStack infrastructure messages are always routed to the
        // root model (which owns the ScreenStack), not to the active screen.
        if ($msg instanceof ScreenStackPushedMsg || $msg instanceof ScreenStackPoppedMsg) {
            if ($this->model instanceof ScreenStackCapable) {
                [$nextModel, $cmd] = $this->model->update($msg);
                $this->model = $nextModel;
                $this->dirty = true;
                if ($cmd !== null) {
                    $this->scheduleCmd($cmd);
                }
            }
            return;
        }

        $active = $this->activeModel();
        [$nextModel, $cmd] = $active->update($msg);
        if ($active === $this->model) {
            // Active model is the root model (not a nested screen), so
            // its update() returned the updated root model.
            $this->model = $nextModel;
        }
        $this->dirty = true;
        if ($cmd !== null) {
            $this->scheduleCmd($cmd);
        }

        // Reconcile subscriptions after every update cycle.
        $this->reconcileWantedSubscriptions();

        // Reconcile component lifecycle hooks after every update cycle.
        // Composite models accumulate mount/unmount Cmds during update();
        // drain them here so onMount/onUnmount side-effects fire promptly.
        if ($this->model instanceof Composite) {
            $this->drainCompositePendingCmds();
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
            // Use the program's configured input/output where possible — only
            // fall back to STDIN/STDOUT/STDERR when those resources are gone
            // (e.g. tests that closed the streams). Mirrors upstream bubbletea
            // PR #1680 — avoid a panic when the program was started with a
            // null/closed input handle.
            $childIn  = is_resource($this->input) ? $this->input : STDIN;
            $childOut = is_resource($this->output) ? $this->output : STDOUT;
            $descriptors = $req->captureOutput
                ? [0 => $childIn, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']]
                : [0 => $childIn, 1 => $childOut, 2 => STDERR];
            $cmd = is_array($req->command) ? $req->command : (string) $req->command;
            $proc = @proc_open($cmd, $descriptors, $pipes);
            if (!is_resource($proc)) {
                throw new \RuntimeException(Lang::t('program.proc_open_failed', [
                    'cmd' => is_array($cmd) ? implode(' ', $cmd) : $cmd,
                ]));
            }
            if ($req->captureOutput) {
                $stdout = is_resource($pipes[1]) ? (string) stream_get_contents($pipes[1]) : '';
                $stderr = is_resource($pipes[2]) ? (string) stream_get_contents($pipes[2]) : '';
                if (is_resource($pipes[1])) {
                    fclose($pipes[1]);
                }
                if (is_resource($pipes[2])) {
                    fclose($pipes[2]);
                }
            }
            $exit = proc_close($proc);
        } catch (\Throwable $t) {
            $err = $t;
        }
        $this->setupTerminal();
        $this->renderer->reset();
        $this->lastRenderedBody = null;
        $this->lastImageSignature = '';
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
        $this->lastRenderedBody = null;
        $this->lastImageSignature = '';
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
            $this->writeOutput(Ansi::altScreenEnter());
        }
        if ($this->options->hideCursor) {
            $this->writeOutput(Ansi::cursorHide());
        }
        // Bubble Tea v2 enables grapheme-cluster mode (DEC 2027) by
        // default to fix long-standing emoji-width drift. We follow.
        if ($this->options->unicodeMode) {
            $this->writeOutput(Ansi::unicodeOn());
        }
        match ($this->options->mouseMode) {
            MouseMode::CellMotion => $this->writeOutput(Ansi::mouseCellMotionOn()),
            MouseMode::AllMotion  => $this->writeOutput(Ansi::mouseAllMotionOn()),
            MouseMode::Off        => null,
        };
        $this->activeMouseMode = $this->options->mouseMode;
        if ($this->options->reportFocus) {
            $this->writeOutput(Ansi::focusReportingOn());
        }
        $this->activeFocusReporting = $this->options->reportFocus;
        if ($this->options->bracketedPaste) {
            $this->writeOutput(Ansi::bracketedPasteOn());
        }
        $this->activeBracketedPaste = $this->options->bracketedPaste;
        $this->tty->enableRawMode();
    }

    private function teardownTerminal(): void
    {
        $this->tty->restore();
        if ($this->activeBracketedPaste) {
            $this->writeOutput(Ansi::bracketedPasteOff());
        }
        if ($this->activeFocusReporting) {
            $this->writeOutput(Ansi::focusReportingOff());
        }
        match ($this->activeMouseMode) {
            MouseMode::CellMotion => $this->writeOutput(Ansi::mouseCellMotionOff()),
            MouseMode::AllMotion  => $this->writeOutput(Ansi::mouseAllMotionOff()),
            MouseMode::Off, null  => null,
        };
        if ($this->options->unicodeMode) {
            $this->writeOutput(Ansi::unicodeOff());
        }
        if ($this->options->hideCursor) {
            $this->writeOutput(Ansi::cursorShow());
        }
        if ($this->options->useAltScreen) {
            $this->writeOutput(Ansi::altScreenLeave());
        }
    }

    /**
     * Render one frame. If the model returned a {@see View}, emit
     * any side-effect escapes (window title, cursor shape) that
     * differ from the previously-emitted set, then paint the body.
     */
    private function renderFrame(): void
    {
        $frameStart = microtime(true);

        if ($this->options->withoutRenderer) {
            // Headless mode: still call view() so the model's
            // computation runs (and any errors surface) but skip
            // emitting any output.
            $this->activeModel()->view();
            $this->lastFrameDuration = microtime(true) - $frameStart;
            return;
        }
        $rendered = $this->activeModel()->view();
        $images = [];
        if ($rendered instanceof View) {
            $this->applyViewSideEffects($rendered);
            $body = $rendered->body;
            $images = $rendered->images;
        } else {
            $body = $rendered;
        }

        $paints = [];
        if ($images !== []) {
            [$body, $paints] = ImageOverlay::resolve($body, $images);
        }

        $bodyChanged = $body !== $this->lastRenderedBody;
        $this->lastRenderedBody = $body;

        $this->renderer->render($body);
        $this->paintImageLayer($paints, $bodyChanged);

        $this->lastFrameDuration = microtime(true) - $frameStart;
    }

    /**
     * Paint the pixel-graphics image layer on top of the just-rendered text.
     *
     * Graphics blobs are re-emitted when the image set changed OR when the text
     * frame changed — a text diff erases the cells it repaints, including any
     * image pixels there, so an unchanged image still has to be redrawn over a
     * changed frame. When neither changed, the images already on screen are
     * left untouched (no flicker, no bandwidth) at idle.
     *
     * @param list<array{row: int, col: int, bytes: string}> $paints
     */
    private function paintImageLayer(array $paints, bool $bodyChanged): void
    {
        $signature = ImageOverlay::signature($paints);
        if (!$bodyChanged && $signature === $this->lastImageSignature) {
            return;
        }

        if ($paints !== []) {
            $this->writeOutput(ImageOverlay::paint($paints));
        }
        $this->lastImageSignature = $signature;
    }

    private function applyViewSideEffects(View $view): void
    {
        if ($view->mouseMode !== null && $view->mouseMode !== $this->activeMouseMode) {
            // Turn the previous mode off, then the new one on. Both
            // pairs happily no-op when fed back to themselves.
            match ($this->activeMouseMode) {
                MouseMode::CellMotion => $this->writeOutput(Ansi::mouseCellMotionOff()),
                MouseMode::AllMotion  => $this->writeOutput(Ansi::mouseAllMotionOff()),
                MouseMode::Off, null  => null,
            };
            match ($view->mouseMode) {
                MouseMode::CellMotion => $this->writeOutput(Ansi::mouseCellMotionOn()),
                MouseMode::AllMotion  => $this->writeOutput(Ansi::mouseAllMotionOn()),
                MouseMode::Off        => null,
            };
            $this->activeMouseMode = $view->mouseMode;
        }

        if ($view->reportFocus !== null && $view->reportFocus !== $this->activeFocusReporting) {
            $this->writeOutput($view->reportFocus
                ? Ansi::focusReportingOn()
                : Ansi::focusReportingOff());
            $this->activeFocusReporting = $view->reportFocus;
        }

        if ($view->bracketedPaste !== null && $view->bracketedPaste !== $this->activeBracketedPaste) {
            $this->writeOutput($view->bracketedPaste
                ? Ansi::bracketedPasteOn()
                : Ansi::bracketedPasteOff());
            $this->activeBracketedPaste = $view->bracketedPaste;
        }

        if ($view->windowTitle !== null && $view->windowTitle !== $this->lastWindowTitle) {
            $this->writeOutput(Ansi::setWindowTitle($view->windowTitle));
            $this->lastWindowTitle = $view->windowTitle;
        }

        if ($view->progressBar !== null && !$this->progressEquals($view->progressBar, $this->lastProgress)) {
            $this->writeOutput(Ansi::setProgressBar($view->progressBar->state, $view->progressBar->percent));
            $this->lastProgress = $view->progressBar;
        }

        if ($view->foregroundColor !== null && !$this->colorEquals($view->foregroundColor, $this->lastForegroundColor)) {
            $c = $view->foregroundColor;
            $this->writeOutput(Ansi::setForegroundColor($c->r, $c->g, $c->b));
            $this->lastForegroundColor = $c;
        }

        if ($view->backgroundColor !== null && !$this->colorEquals($view->backgroundColor, $this->lastBackgroundColor)) {
            $c = $view->backgroundColor;
            $this->writeOutput(Ansi::setBackgroundColor($c->r, $c->g, $c->b));
            $this->lastBackgroundColor = $c;
        }

        if ($view->cursor === null) {
            // null cursor → hide.
            if (!$this->lastCursorHidden) {
                $this->writeOutput(Ansi::cursorHide());
                $this->lastCursorHidden = true;
            }
            return;
        }

        // Show cursor if it was hidden.
        if ($this->lastCursorHidden) {
            $this->writeOutput(Ansi::cursorShow());
            $this->lastCursorHidden = false;
        }

        $cur  = $view->cursor;
        $prev = $this->lastCursor;
        if ($prev === null
            || $prev->shape !== $cur->shape
            || $prev->blink !== $cur->blink) {
            $this->writeOutput(Ansi::cursorShape($cur->shape, $cur->blink));
        }
        if ($cur->row !== null && $cur->col !== null) {
            $this->writeOutput(Ansi::cursorTo($cur->row, $cur->col));
        }
        $this->lastCursor = $cur;
    }

    /**
     * Write a chunk to the program's output stream and tee it to the
     * recorder if one is attached. Every byte that leaves the runtime —
     * renderer frames, RawMsg / PrintMsg, terminal-mode bytes, cursor /
     * title / progress emitters — flows through here so cassettes
     * reflect what the user actually saw.
     */
    private function writeOutput(string $bytes): void
    {
        // Loop over short writes: a single fwrite() to a PTY/pipe (e.g. an
        // SSH session) with a partially full kernel buffer consumes only what
        // fits and returns a short count. Dropping the tail left mode-setting
        // sequences and large RawMsg/PrintMsg payloads partially emitted.
        $len = strlen($bytes);
        $written = 0;
        while ($written < $len) {
            $n = @fwrite($this->output, $written === 0 ? $bytes : substr($bytes, $written));
            if ($n === false) {
                break;
            }
            if ($n === 0) {
                $w = [$this->output];
                $r = $e = null;
                if (@stream_select($r, $w, $e, 1) === false) {
                    break;
                }
                continue;
            }
            $written += $n;
        }
        $this->recorder?->recordOutput($bytes);
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

    private function installSignalHandlers(): void
    {
        if ($this->options->withoutSignalHandler
            || !$this->options->catchInterrupts
            || !function_exists('pcntl_signal')
        ) {
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

    /**
     * Resolve the subscription set the model currently wants.
     *
     * An explicit `subscriptions:` closure on ProgramOptions wins (it can wrap
     * or override the model's own declaration). Otherwise we honor the model
     * directly — {@see Model::subscriptions()} is a required interface method,
     * so a model that declares a tick must get it started without every
     * consumer having to hand-wire the closure. Models that don't subscribe
     * use the {@see SubscriptionCapable} trait and return null → empty set.
     */
    private function wantedSubscriptions(): ?Subscriptions
    {
        return $this->options->subscriptions !== null
            ? ($this->options->subscriptions)($this->model)
            : $this->model->subscriptions();
    }

    /**
     * Re-evaluate the model's wanted subscriptions and reconcile against the
     * active set. Called every update cycle, so the hot path — a model that
     * wants no subscriptions and has none active — must stay allocation-free:
     * skip entirely rather than building an empty {@see Subscriptions} just to
     * diff it against nothing.
     */
    private function reconcileWantedSubscriptions(): void
    {
        $wanted = $this->wantedSubscriptions();
        if ($wanted === null && $this->activeSubscriptions === []) {
            return;
        }
        $this->reconcileSubscriptions($wanted ?? new Subscriptions());
    }

    /**
     * Diff the currently-active subscriptions against the wanted set
     * and reconcile: cancel any not in $wanted, start any new ones.
     */
    private function reconcileSubscriptions(Subscriptions $wanted): void
    {
        $wantedIds = [];
        foreach ($wanted->all() as $sub) {
            $wantedIds[$sub->id] = true;
            if (!isset($this->activeSubscriptions[$sub->id])) {
                $this->activeSubscriptions[$sub->id] = [
                    $this->startSubscription($sub),
                    $sub,
                ];
            }
        }
        // Cancel subscriptions that are no longer wanted.
        foreach ($this->activeSubscriptions as $id => [$timer, ]) {
            if (!isset($wantedIds[$id])) {
                $this->loop->cancelTimer($timer);
                unset($this->activeSubscriptions[$id]);
            }
        }
    }

    /**
     * Start a subscription and return its timer handle.
     *
     * @return \React\EventLoop\TimerInterface
     */
    private function startSubscription(Subscription $sub): mixed
    {
        return match ($sub->kind) {
            Kind::Tick => $this->loop->addPeriodicTimer(
                $sub->params['seconds'],
                function () use ($sub): void {
                    $msg = ($sub->produce)();
                    if ($msg !== null) {
                        $this->dispatch($msg);
                    }
                },
            ),
            Kind::Key => $this->loop->addPeriodicTimer(
                0.1,
                function () use ($sub): void {
                    $msg = ($sub->produce)();
                    if ($msg !== null) {
                        $this->dispatch($msg);
                    }
                },
            ),
            Kind::Signal => $this->installSignalSubscription($sub),
            Kind::Custom => $this->loop->addPeriodicTimer(
                $sub->params['interval'] ?? 1.0,
                function () use ($sub): void {
                    $msg = ($sub->produce)();
                    if ($msg !== null) {
                        $this->dispatch($msg);
                    }
                },
            ),
        };
    }

    /**
     * Install a signal handler for the given subscription.
     *
     * @return \React\EventLoop\TimerInterface
     */
    private function installSignalSubscription(Subscription $sub): mixed
    {
        $signo = $sub->params['signo'] ?? 0;
        $handler = function (int $signo) use ($sub): void {
            $msg = ($sub->produce)();
            if ($msg !== null) {
                $this->dispatch($msg);
            }
        };
        if (function_exists('pcntl_signal')) {
            pcntl_signal($signo, $handler);
        }
        // Return a no-op timer to satisfy the return type; signal handlers
        // are managed via pcntl_signal directly.
        return $this->loop->addPeriodicTimer(86400, static function (): void {
            // No-op: signal handlers fire via pcntl_signal, not this timer.
        });
    }

    /**
     * Cancel all active subscriptions. Called during teardown.
     */
    private function cancelAllSubscriptions(): void
    {
        foreach ($this->activeSubscriptions as [$timer, ]) {
            $this->loop->cancelTimer($timer);
        }
        $this->activeSubscriptions = [];
    }

    /**
     * Drain and execute any pending lifecycle Cmds accumulated in the
     * current Composite model, then clear them for the next cycle.
     */
    private function drainCompositePendingCmds(): void
    {
        if (!$this->model instanceof Composite) {
            return;
        }
        $cmds = $this->model->pendingCmds();
        if ($cmds === []) {
            return;
        }
        // Create a new Composite with the same children but no pending Cmds,
        // so they are not re-drained on the next tick.
        $this->model = new Composite($this->model->children());
        foreach ($cmds as $cmd) {
            $this->scheduleCmd($cmd);
        }
    }
}

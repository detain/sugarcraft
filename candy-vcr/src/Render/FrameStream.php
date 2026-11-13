<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Render;

use SugarCraft\AnsiParser\Parser\HandlerAdapter;
use SugarCraft\AnsiParser\Parser\Parser;
use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\PtySystemFactory;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Player;
use SugarCraft\Vterm\Parser\CsiHandlerImpl;
use SugarCraft\Vterm\Parser\OscHandlerImpl;
use SugarCraft\Vterm\Parser\RendererHandler;
use SugarCraft\Vterm\Snapshot;
use SugarCraft\Vterm\Terminal;

/**
 * Iterator of Snapshot frames at fps cadence.
 *
 * Lazily walks the Player's cassette events, feeds bytes to the Terminal
 * on each event, advances a virtual clock by event timestamps, and yields
 * a terminal snapshot every 1/fps seconds. Only one frame is held in
 * memory at a time (the current one) — the previous reference is only
 * kept during iteration for potential dedup use by FrameDedup.
 *
 * ## Echo mode (default — `$shell === null`)
 *
 * Input events feed their raw keystroke bytes straight to the Terminal, so the
 * frames show the typed characters. No subprocess runs and the render is a pure
 * function of the cassette — byte-identical across runs. This is the path the
 * committed golden tapes exercise.
 *
 * ## Exec mode (`$shell !== null`, opt-in via `Set Shell` / `--shell` / `--exec`)
 *
 * A single persistent shell is spawned under a real PTY at the terminal's
 * dimensions. Input events are WRITTEN to the PTY master (child stdin) instead
 * of being fed to the Terminal — the PTY's own echo plus the program's output
 * come back through the master. Before every snapshot the master is drained
 * (non-blocking) for up to one frame interval of wall time and whatever the
 * program wrote is fed to the Terminal. So a `Sleep Ns` window after `Enter`
 * becomes the real read window during which program output streams in on the
 * frame clock — the same "render on the tape clock" model as charmbracelet/vhs,
 * which lets candy-vcr render program-output demos, not just keystrokes.
 *
 * When exec is requested but the PTY layer is unavailable (no ext-ffi /
 * /dev/ptmx / non-POSIX host, or the spawn fails) the stream logs a warning and
 * transparently falls back to echo mode, so an FFI-less host still renders the
 * (shell-less) goldens and never hard-fails a batch render.
 *
 * @implements \IteratorAggregate<int, Snapshot>
 */
final class FrameStream implements \IteratorAggregate
{
    /** POSIX SIGHUP — delivered to the shell on teardown (terminal hang-up). */
    private const SIGHUP = 1;

    /** Fallback shell when a requested name cannot be resolved to a path. */
    private const FALLBACK_SHELL = '/bin/sh';

    /**
     * Cross-stage contract with the encoder, not API noise: a Snapshot event
     * records its path here ({@see processSnapshot()}), and TapeToGif consumes
     * (and re-nulls) it when writing the matching frame. FrameStream is a
     * stateful streaming renderer — these two flags ride the same live model
     * as the PTY master below, which is why they are plain public slots.
     */
    public ?string $pendingScreenshotPath = null;

    /** Mirrored Hide/Show events toggle this; the encoder snapshots it per frame. */
    public bool $captureCursor = true;

    /**
     * Live PTY master while exec mode is running; null in echo mode or after
     * teardown. Set by {@see setupExec()}, torn down by {@see teardownExec()}.
     */
    private ?MasterPty $execMaster = null;

    /** Live child shell handle while exec mode is running; null otherwise. */
    private ?Child $execChild = null;

    /** True once the PTY master has reported EOF, to stop draining a dead child. */
    private bool $execEof = false;

    /**
     * Hard ceiling on emitted frames (overridable per render via the
     * `maxFrames` option). A degenerate tape must die loudly on this number,
     * never loop until the harness timeout-kills it (finding 001).
     */
    public const DEFAULT_MAX_FRAMES = 100_000;

    /**
     * Hard ceiling on virtual tape runtime in seconds (overridable via the
     * `maxRuntimeSeconds` option) — one day. `{"t":1e17}` style corruptions
     * hit this guard and raise a named error instead of spinning.
     */
    public const DEFAULT_MAX_RUNTIME_SECONDS = 86_400.0;

    /**
     * Longest run of unchanged frame boundaries FrameStream will suppress
     * before re-emitting identically — mirrors the default hold ceiling of
     * FrameDedup::dedup() so the two layers' max-hold contract agrees.
     */
    private const HOLD_MAX_CADENCE = 300;

    public function __construct(
        private Player $player,
        private Terminal $terminal,
        private float $fps = 30.0,
        private ?string $shell = null,
        private int $maxFrames = self::DEFAULT_MAX_FRAMES,
        private ?float $maxRuntimeSeconds = null,
    ) {
        // Parse-don't-validate at the boundary — getIterator
        // divides 1.0/$fps on the shared clock, and TapeToGif casts the
        // option to float before constructing this. A non-positive or
        // non-finite fps must halt here, with every other render path
        // (echo and exec alike) behind the same door.
        if (!is_finite($fps) || $fps <= 0.0) {
            throw new \InvalidArgumentException(
                "FrameStream fps must be a positive finite number of frames per second, got {$fps}."
            );
        }
        if ($maxFrames < 1) {
            throw new \InvalidArgumentException(
                "FrameStream maxFrames must be at least 1, got {$maxFrames}."
            );
        }
        if ($maxRuntimeSeconds !== null && (!is_finite($maxRuntimeSeconds) || $maxRuntimeSeconds <= 0.0)) {
            throw new \InvalidArgumentException(
                "FrameStream maxRuntimeSeconds must be a positive finite number of seconds, got {$maxRuntimeSeconds}."
            );
        }
    }

    /** @return \Traversable<int, Snapshot> */
    public function getIterator(): \Traversable
    {
        $cassette = $this->player->cassette;
        $terminal = $this->terminal;
        $fps = $this->fps;
        $playbackSpeed = $cassette->header->playbackSpeed;

        $frameInterval = 1.0 / $fps;

        $events = $cassette->events;
        $eventCount = count($events);
        $endTime = $cassette->endTime;
        $runtimeCap = $this->maxRuntimeSeconds ?? self::DEFAULT_MAX_RUNTIME_SECONDS;

        if ($eventCount === 0 && ($endTime === null || $endTime <= 0.0)) {
            return;
        }

        // Opt-in exec mode. setupExec() returns false (and falls back to echo)
        // when a shell was requested but the PTY layer is unusable on this host.
        $exec = $this->shell !== null
            && $this->setupExec($cassette->header->cols, $cassette->header->rows);

        try {
            $virtualTime = 0.0;
            $nextSnapshotTime = 0.0;
            $frameIndex = 0;
            $lastSnapshotTime = -1.0;
            $previousEventTime = 0.0;
            $lastYieldedRevision = null;
            $lastYieldedCaptureCursor = null;
            $skippedBoundaries = 0;

            // Boundary emission is decided by frameDue() below; the yields
            // themselves stay inline in each drive loop because generators
            // propagate yields lexically — this stream is the render
            // pipeline's feed. (No closure wrapper: the terminal instance
            // mutates across Resize events and by-value capture would go
            // stale.)

            for ($i = 0; $i < $eventCount; $i++) {
                $event = $events[$i];

                // Apply playback speed scaling to inter-event delta
                if ($playbackSpeed !== null && $playbackSpeed > 0.0) {
                    $scaledDelta = ($event->t - $previousEventTime) / $playbackSpeed;
                    $virtualTime += $scaledDelta;
                    $previousEventTime = $event->t;
                } else {
                    $virtualTime = $event->t;
                    $previousEventTime = $event->t;
                }

                // Fail fast on a corrupted clock: a tape whose virtual time
                // leaps past the runtime cap dies here, loudly, with the
                // offending timestamp in the message — never an hour of frames.
                if ($virtualTime > $runtimeCap) {
                    throw new \RuntimeException(
                        "tape event at t={$event->t}s exceeds the render runtime cap of {$runtimeCap}s "
                            . '(frame/loop safety); raise the maxRuntimeSeconds option if this tape is genuine.'
                    );
                }

                // Two-phase flush: boundaries strictly earlier than this event
                // must show the PRE-event grid; only the boundary exactly at
                // the event time (or, failing that, the flush after the loop)
                // shows it fed. Feeding before the flush rendered typing
                // ~0.6s early and made the opening frame show the first
                // keystroke (finding 003).
                $fed = false;
                while ($virtualTime >= $nextSnapshotTime) {
                    if (!$fed && $nextSnapshotTime >= $virtualTime) {
                        $terminal = $this->processEvent($event, $terminal);
                        $fed = true;
                    }
                    if ($exec) {
                        // The wall-clock frame boundary IS the read window: drain
                        // program output for up to one frame interval before the
                        // snapshot, so output lands on the frame it arrived on.
                        $this->drainExec($terminal, $frameInterval);
                    }
                    if ($this->frameDue($terminal, $lastYieldedRevision, $lastYieldedCaptureCursor, $skippedBoundaries)) {
                        $lastSnapshotTime = $nextSnapshotTime;
                        yield $frameIndex => $terminal->snapshot($nextSnapshotTime);
                        $frameIndex++;
                        $this->guardFrameCap($frameIndex);
                        $lastYieldedRevision = $terminal->revision();
                        $lastYieldedCaptureCursor = $this->captureCursor;
                        $skippedBoundaries = 0;
                    } else {
                        $skippedBoundaries++;
                    }
                    $nextSnapshotTime += $frameInterval;
                }
                if (!$fed) {
                    $terminal = $this->processEvent($event, $terminal);
                }
            }

            // Trailing flush: hold the final grid until the tape's true end.
            // A compiled tape knows its end even past the last event (a
            // trailing Sleep), so the GIF keeps that pause visible (finding 003).
            $endAt = max($virtualTime, $endTime ?? 0.0);
            if ($endAt > $runtimeCap) {
                throw new \RuntimeException(
                    "tape end time at t={$endAt}s exceeds the render runtime cap of {$runtimeCap}s "
                        . '(frame/loop safety); raise the maxRuntimeSeconds option if this tape is genuine.'
                );
            }
            while ($endAt >= $nextSnapshotTime) {
                if ($exec) {
                    $this->drainExec($terminal, $frameInterval);
                }
                if ($this->frameDue($terminal, $lastYieldedRevision, $lastYieldedCaptureCursor, $skippedBoundaries)) {
                    $lastSnapshotTime = $nextSnapshotTime;
                    yield $frameIndex => $terminal->snapshot($nextSnapshotTime);
                    $frameIndex++;
                    $this->guardFrameCap($frameIndex);
                    $lastYieldedRevision = $terminal->revision();
                    $lastYieldedCaptureCursor = $this->captureCursor;
                    $skippedBoundaries = 0;
                } else {
                    $skippedBoundaries++;
                }
                $nextSnapshotTime += $frameInterval;
            }
            if ($frameIndex === 0 || $endAt > $lastSnapshotTime) {
                if ($exec) {
                    $this->drainExec($terminal, $frameInterval);
                }
                yield $frameIndex => $terminal->snapshot($endAt);
            }
        } finally {
            if ($exec) {
                $this->teardownExec();
            }
        }
    }

    /**
     * Hard frame ceiling: past it the stream aborts with a named error rather
     * than streaming duplicates until something external kills the process.
     */
    private function guardFrameCap(int $frameIndex): void
    {
        if ($frameIndex > $this->maxFrames) {
            $cap = $this->maxFrames;
            throw new \RuntimeException(
                "tape exceeded the render cap of {$cap} frames (frame/loop safety); "
                    . 'raise the maxFrames option if this tape is genuine.'
            );
        }
    }

    /**
     * Decide whether the frame boundary at hand must be emitted: the opening
     * frame always is; a Hide/Show flip or a pending Screenshot is contractual
     * (the encoder reads those flags off this frame); every 300th consecutive
     * idle boundary re-emits to honour the dedup hold ceiling; otherwise the
     * terminal's monotonic revision answers for the grid.
     */
    private function frameDue(Terminal $terminal, ?int $lastRevision, ?bool $lastCaptureCursor, int $skipped): bool
    {
        if ($lastRevision === null) {
            return true;
        }
        if ($this->captureCursor !== $lastCaptureCursor) {
            return true;
        }
        if ($this->pendingScreenshotPath !== null) {
            return true;
        }
        if ($skipped + 1 >= self::HOLD_MAX_CADENCE) {
            return true;
        }

        return $terminal->revision() !== $lastRevision;
    }

    /**
     * Process a single event, feeding appropriate bytes to the terminal.
     * Returns the (potentially new) terminal instance to use for subsequent events.
     */
    private function processEvent(Event $event, Terminal $terminal): Terminal
    {
        return match ($event->kind) {
            EventKind::Input => $this->processInput($event, $terminal),
            EventKind::Output => $this->processOutput($event, $terminal),
            EventKind::Resize => $this->processResize($event, $terminal),
            EventKind::Quit => $terminal,
            EventKind::Snapshot => $this->processSnapshot($event, $terminal),
            EventKind::Hide => $this->processHide($terminal),
            EventKind::Show => $this->processShow($terminal),
        };
    }

    private function processSnapshot(Event $event, Terminal $terminal): Terminal
    {
        $path = $event->payload['path'] ?? null;
        $this->pendingScreenshotPath = is_string($path) ? $path : null;
        return $terminal;
    }

    private function processHide(Terminal $terminal): Terminal
    {
        $this->captureCursor = false;
        return $terminal;
    }

    private function processShow(Terminal $terminal): Terminal
    {
        $this->captureCursor = true;
        return $terminal;
    }

    private function processInput(Event $event, Terminal $terminal): Terminal
    {
        if (!isset($event->payload['b']) || !is_string($event->payload['b'])) {
            return $terminal;
        }
        $bytes = $event->payload['b'];

        // Exec mode: keystrokes go to the child's stdin. The typed characters
        // come back to the Terminal via the PTY echo + program output on the
        // next drain — feeding them here too would double them.
        if ($this->execMaster !== null) {
            try {
                $this->execMaster->write($bytes);
            } catch (\Throwable) {
                // A broken pipe (child already gone) is non-fatal: the drain
                // loop will observe EOF and the render still finishes.
            }
            return $terminal;
        }

        $terminal->feed($bytes);
        return $terminal;
    }

    private function processOutput(Event $event, Terminal $terminal): Terminal
    {
        if (isset($event->payload['b']) && is_string($event->payload['b'])) {
            $terminal->feed($event->payload['b']);
        }
        return $terminal;
    }

    /**
     * Apply a resize by growing/shrinking the CURRENT grid — the visible
     * screen survives the boundary, clamped to the new geometry (finding 038
     * resize half). The exec-mode master PTY (when still open) is resized
     * first, best-effort, so the live child re-negotiates its window before
     * subsequent output is read.
     */
    private function processResize(Event $event, Terminal $terminal): Terminal
    {
        $cols = $event->payload['cols'] ?? null;
        $rows = $event->payload['rows'] ?? null;
        if (is_numeric($cols) && is_numeric($rows)) {
            $colsInt = (int) $cols;
            $rowsInt = (int) $rows;
            if ($colsInt > 0 && $rowsInt > 0) {
                if ($this->execMaster !== null && !$this->execMaster->isClosed()) {
                    try {
                        $this->execMaster->resize($colsInt, $rowsInt);
                    } catch (\Throwable) {
                        // Best-effort — a failed TIOCSWINSZ must not abort the render.
                    }
                }
                return self::resizePreserving($terminal, $colsInt, $rowsInt);
            }
        }
        return $terminal;
    }

    /**
     * Resize the emulator's grid WITHOUT wiping it.
     *
     * `Buffer::resize` copies the intersecting region (crop on shrink, blank
     * fill on growth, wide-cell boundary repair included); the cursor is
     * clamped back into the new geometry, exactly like the emulator handlers
     * do on SIGWINCH. Rewiring a fresh Parser/CSI/OSC trio over the new
     * grid+cursor keeps the live instance byte-compatible for later feeds.
     * What cannot survive is terminal-internal state the render model does
     * not read back (SGR pen, DEC modes, alt-screen): the previous
     * `Terminal::new` wiped ALL of it, which made every mid-tape resize a
     * black flash — keeping the grid is the finding's bar (038 resize half).
     */
    private static function resizePreserving(Terminal $terminal, int $cols, int $rows): Terminal
    {
        $grid = $terminal->grid()->resize($cols, $rows);
        $cursor = $terminal->cursor();
        $cursor = $cursor->at(min($cursor->row, $rows - 1), min($cursor->col, $cols - 1));

        $csi = new CsiHandlerImpl($grid, $cursor, $terminal->theme());
        $osc = new OscHandlerImpl($csi);
        $handler = new RendererHandler($csi, new HandlerAdapter($csi, $osc));
        $parser = new Parser($handler, maxStringBuffer: Terminal::MAX_STRING_BUFFER);

        return new Terminal($cols, $rows, $grid, $cursor, $parser, $csi, $osc, $terminal->theme());
    }

    /**
     * Spawn the persistent shell under a fresh PTY sized to the terminal grid,
     * flip the master non-blocking, and stash the master + child for the drain
     * loop. Returns true when exec mode is live, false when the PTY layer is
     * unavailable (caller then renders echo-only). Never throws — a PTY failure
     * degrades to a warning + echo fallback so a batch render keeps going.
     */
    private function setupExec(int $cols, int $rows): bool
    {
        if (!$this->ptyLayerAvailable()) {
            $this->warnExecFallback('PTY layer unavailable (needs ext-ffi + a readable/writable /dev/ptmx on POSIX)');
            return false;
        }

        try {
            $system = PtySystemFactory::default();
            $pair = $system->open($cols, $rows);
            $child = $pair->slave()->spawn(
                $this->shellCommand(),
                $this->execEnv(),
                $cols,
                $rows,
                controllingTerminal: true,
            );
            $master = $pair->master();

            // Non-blocking master so the per-frame drain never stalls the render
            // when the child has nothing to say.
            $stream = $master->stream();
            if (is_resource($stream)) {
                stream_set_blocking($stream, false);
            }

            $this->execMaster = $master;
            $this->execChild = $child;
            $this->execEof = false;
            return true;
        } catch (\Throwable $e) {
            $this->warnExecFallback('shell spawn failed: ' . $e->getMessage());
            $this->execMaster = null;
            $this->execChild = null;
            return false;
        }
    }

    /**
     * Drain program output for up to $windowSeconds of wall time, feeding every
     * chunk to the Terminal. Doubles as the frame's real-time pacer: when the
     * child is idle the blocking read consumes the window, so a `Sleep` in the
     * tape takes real time (during which output can arrive); when output is
     * flowing the reads return early and the window is consumed by data. Bounded
     * by the deadline, so an endlessly-chatty child can never hang a frame.
     */
    private function drainExec(Terminal $terminal, float $windowSeconds): void
    {
        $master = $this->execMaster;
        if ($master === null || $this->execEof) {
            return;
        }

        $deadline = microtime(true) + $windowSeconds;
        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0.0) {
                return;
            }
            try {
                $chunk = $master->read(8192, $remaining);
            } catch (\Throwable) {
                return;
            }
            if ($chunk === null) {
                // Timeout with no data — re-check the deadline and keep waiting.
                continue;
            }
            if ($chunk === '') {
                // EOF: the child closed the PTY. Stop draining for the rest of
                // the render; FrameDedup preserves the trailing hold duration.
                $this->execEof = true;
                return;
            }
            $terminal->feed($chunk);
        }
    }

    /**
     * Tear down the exec session: hang up the shell, close the master, and reap
     * the child. Bounded so it can never block the caller — a shell that ignores
     * SIGHUP is escalated to SIGKILL. Mirrors RecordCommand's cleanup shape.
     */
    private function teardownExec(): void
    {
        $child = $this->execChild;
        $master = $this->execMaster;

        try {
            if ($child !== null && !$child->exited()) {
                try {
                    $child->kill(self::SIGHUP);
                } catch (\Throwable) {
                    // fall through to close + escalation
                }
            }

            // Closing the controlling-terminal master also delivers SIGHUP to
            // the child's foreground process group.
            if ($master !== null && !$master->isClosed()) {
                try {
                    $master->close();
                } catch (\Throwable) {
                    // best-effort
                }
            }

            if ($child !== null) {
                // Give the shell a brief, bounded chance to exit on SIGHUP; if it
                // clings on, SIGKILL guarantees the reap can't hang.
                for ($i = 0; $i < 50 && !$child->exited(); $i++) {
                    usleep(10_000);
                }
                if (!$child->exited()) {
                    try {
                        $child->kill(MasterPty::SIGKILL);
                    } catch (\Throwable) {
                        // best-effort
                    }
                }
                try {
                    $child->wait();
                } catch (\Throwable) {
                    // already reaped / never started
                }
            }
        } finally {
            $this->execMaster = null;
            $this->execChild = null;
        }
    }

    /**
     * Cheap capability probe before attempting a spawn — mirrors the gate in
     * tests/Support/RequiresWorkingPty so a requested shell degrades cleanly on
     * an FFI-less / non-POSIX host instead of throwing.
     */
    private function ptyLayerAvailable(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }
        if (!extension_loaded('ffi')) {
            return false;
        }
        return is_readable('/dev/ptmx') && is_writable('/dev/ptmx');
    }

    /**
     * Resolve the configured shell to an argv, forced interactive (`-i`) so it
     * reads and executes the typed commands and prints a prompt — the terminal
     * look a demo wants. A bare name (e.g. "sh", "bash") is resolved against the
     * common bin directories; an absolute path is used verbatim.
     *
     * Deliberately NOT a login shell (`-l`): a render must be reproducible, and
     * login profiles inject host-specific noise (MOTD, custom prompts, and on
     * some hosts a profile that errors under a different shell). RecordCommand
     * uses `-l` because it captures a real user session; a tape render wants the
     * clean, deterministic shell instead.
     *
     * @return list<string>
     */
    private function shellCommand(): array
    {
        $shell = trim((string) $this->shell);
        if ($shell === '') {
            $shell = self::FALLBACK_SHELL;
        }
        if (!str_starts_with($shell, '/')) {
            $shell = $this->locateShell($shell) ?? self::FALLBACK_SHELL;
        }
        return [$shell, '-i'];
    }

    /**
     * Resolve a bare shell name to an absolute path by probing the conventional
     * bin directories, then `$PATH`. Returns null when nothing is found.
     */
    private function locateShell(string $name): ?string
    {
        foreach (['/bin/', '/usr/bin/', '/usr/local/bin/'] as $dir) {
            $candidate = $dir . $name;
            if (is_executable($candidate)) {
                return $candidate;
            }
        }
        $path = (string) (getenv('PATH') ?: '');
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidate = rtrim($dir, '/') . '/' . $name;
            if (is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Minimal, predictable child environment: a colour-capable TERM plus the
     * inherited PATH/HOME/LANG, overlaid with the tape's own `Env` directives
     * (carried on the cassette header) so `Env FOO bar` reaches the program.
     *
     * @return array<string, string>
     */
    private function execEnv(): array
    {
        $env = [
            'TERM' => (string) (getenv('TERM') ?: 'xterm-256color'),
            'PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => (string) (getenv('HOME') ?: '/tmp'),
            'LANG' => (string) (getenv('LANG') ?: 'C.UTF-8'),
        ];
        foreach ($this->player->cassette->header->env as $key => $value) {
            $env[$key] = $value;
        }
        return $env;
    }

    /**
     * Surface an exec→echo fallback without aborting the render. error_log() goes
     * to stderr, matching how TapeToGif reports an unknown theme.
     */
    private function warnExecFallback(string $reason): void
    {
        error_log("candy-vcr: Set Shell requested but exec mode unavailable ({$reason}); rendering keystrokes only.");
    }
}

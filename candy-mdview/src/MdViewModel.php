<?php

declare(strict_types=1);

namespace SugarCraft\MdView;

use SugarCraft\Forms\Viewport\Viewport;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\Width;

/**
 * Pager Model used by {@see RenderCommand} when `-p` / `--pager` is set.
 * Wraps a {@see Viewport} containing the already-rendered (styled) text.
 * Standard reader keys are forwarded to the Viewport; plain `q` / `Esc` /
 * `Ctrl+C` exit the loop (modified runes like Alt+q belong to the reader,
 * never to the exit gate).
 *
 * Resize: every `WindowSizeMsg` (SIGWINCH, plus the startup dispatch)
 * rebuilds the viewport geometry at the real terminal size, so wrapped
 * rows are clamped to the CURRENT width — a stale width used to let
 * over-long rows tear the alt-screen via terminal auto-wrap.
 *
 * Document mode: {@see withDocument()} adds a one-line status header
 * (name · scroll% · reload counter) and reserves its row. Plain
 * {@see fromContent()} stays chrome-free so golden frames never move.
 *
 * File-watch auto-reload: when armed with {@see withWatch()}, the model
 * rides a `Cmd::tick()` re-arm chain (the sugar-widgets Stopwatch shape)
 * that sweeps the source file's (mtime, size) tuple once per interval
 * through {@see FileWatcher::pollTuple()} — one non-blocking stat per
 * tick, no Generator, no sleep inside the loop. A changed tuple re-reads
 * the file, runs it through the injected re-render closure, and swaps the
 * Viewport content in place. The tick interval is an idle bound (never a
 * request deadline), and a deleted-then-recreated file reloads when it
 * reappears because the sweep keeps polling the baseline. Refused
 * replacements (oversize, device swap, binary) never blank the pager: the
 * last good frame stays, the tuple is consumed, and the header marks `↻!`
 * until the next accepted reload.
 */
final class MdViewModel implements Model
{
    /** Pager file-watch sweep interval in seconds (idle poll cadence). */
    public const RELOAD_POLL_SECONDS = 0.5;

    public static function fromContent(string $content, int $width = 80, int $height = 24): self
    {
        $vp = Viewport::new($width, max(1, $height))->setContent($content);
        // Sole genesis site: every later state change rides copy().
        return new self($vp, false);
    }

    private function __construct(
        public readonly Viewport $viewport,
        public readonly bool $exited,
        /** Source file to sweep, null when the watch is unarmed. */
        public readonly ?string $watchPath = null,
        /** fn(rawMarkdown): renderedText — supplied by {@see RenderCommand}. */
        private readonly ?\Closure $reRender = null,
        /** Baseline (mtime, size); [0, 0] means "file was absent at arm time". */
        private readonly int $watchMtime = 0,
        private readonly int $watchSize = 0,
        /** How many successful reloads this pager has applied. */
        public readonly int $reloadCount = 0,
        /** Status-header label (file basename); null keeps the frame chrome-free. */
        public readonly ?string $documentName = null,
        /** True when the latest sweep's replacement was refused at the gate. */
        public readonly bool $reloadBlocked = false,
    ) {}

    /**
     * Structural clone with named overrides — the ONE derivation site, so
     * no state field can be silently zeroed by a hand-rolled copy.
     *
     * @param array{viewport?:Viewport,exited?:bool,watchPath?:?string,reRender?:?\Closure,watchMtime?:int,watchSize?:int,reloadCount?:int,documentName?:?string,reloadBlocked?:bool} $overrides
     */
    private function copy(array $overrides = []): self
    {
        // array_key_exists, not ??: an override may explicitly carry null
        // (the quit branch drops the watch closure and path).
        $pick = static fn(string $key, mixed $current): mixed =>
            array_key_exists($key, $overrides) ? $overrides[$key] : $current;

        return new self(
            $pick('viewport', $this->viewport),
            $pick('exited', $this->exited),
            $pick('watchPath', $this->watchPath),
            $pick('reRender', $this->reRender),
            $pick('watchMtime', $this->watchMtime),
            $pick('watchSize', $this->watchSize),
            $pick('reloadCount', $this->reloadCount),
            $pick('documentName', $this->documentName),
            $pick('reloadBlocked', $this->reloadBlocked),
        );
    }

    /**
     * Enter document mode: paint a status header and reserve its row. The
     * viewport height shrinks by one so header + body never exceed the
     * terminal, and every later resize recomputes with the same accounting.
     */
    public function withDocument(string $name): self
    {
        $vp = $this->viewport->withSize($this->viewport->width, max(1, $this->viewport->height - 1));
        return $this->copy(['viewport' => $vp, 'documentName' => $name]);
    }

    /**
     * Arm the auto-reload watch on $path. Every returned instance carries
     * the closure forward (immutability), so later key handling cannot
     * silently drop the watch state.
     *
     * @param \Closure(string):string $reRender Turns freshly read Markdown
     *                                          into rendered pager text.
     */
    public function withWatch(string $path, \Closure $reRender): self
    {
        $baseline = FileWatcher::snapshot($path) ?? [0, 0];

        return $this->copy([
            'watchPath'  => $path,
            'reRender'   => $reRender,
            'watchMtime' => $baseline[0],
            'watchSize'  => $baseline[1],
        ]);
    }

    public function isWatching(): bool
    {
        return $this->watchPath !== null;
    }

    public function init(): ?\Closure
    {
        return $this->watchPath === null ? null : $this->armReloadTick();
    }

    /**
     * @return array{0:Model, 1:?\Closure}
     */
    public function update(Msg $msg): array
    {
        if ($this->exited === true) {
            return [$this, null];
        }
        if ($msg instanceof WindowSizeMsg) {
            // The shared Viewport discards non-key messages, so geometry
            // repair must happen here, before the passthrough.
            $height = max(1, $msg->rows - $this->chromeRows());
            if ($msg->cols === $this->viewport->width && $height === $this->viewport->height) {
                return [$this, null];
            }
            return [$this->copy(['viewport' => $this->viewport->withSize($msg->cols, $height)]), null];
        }
        if ($msg instanceof ReloadTickMsg && $msg->id === ReloadTickMsg::ID && $this->watchPath !== null) {
            $next = $this->sweepForReload();
            // One chain, one re-arm per handled tick (Stopwatch pattern) —
            // re-arming on unrelated messages would fork parallel chains.
            return [$next, $next->armReloadTick()];
        }
        if ($msg instanceof KeyMsg) {
            $unmodified = $msg->ctrl === false && $msg->alt === false && $msg->shift === false;
            if ($msg->type === KeyType::Escape
                || ($msg->ctrl && $msg->rune === 'c')
                || ($msg->type === KeyType::Char && $msg->rune === 'q' && $unmodified)) {
                // Quitting ends the watch chain too: nothing may keep
                // sweeping a file the user has stopped reading.
                return [$this->copy([
                    'exited'     => true,
                    'watchPath'  => null,
                    'reRender'   => null,
                    'watchMtime' => 0,
                    'watchSize'  => 0,
                ]), Cmd::quit()];
            }
        }
        [$next, $cmd] = $this->viewport->update($msg);
        return [$this->copy(['viewport' => $next]), $cmd];
    }

    /**
     * One non-blocking (mtime, size) sweep; returns a content-refreshed
     * copy when the file moved, `$this` (identical instance) when it did
     * not. A replacement refused at the document gate (grown over cap,
     * swapped to a device, binary payload) keeps the last good frame,
     * consumes the tuple, and marks the header `↻!` — re-reading refused
     * bytes every tick would re-pay the slurp forever, and the next real
     * edit presents a fresh tuple. Render failures likewise keep the last
     * good frame: the pager never blanks on a transient half-written save.
     */
    private function sweepForReload(): self
    {
        if ($this->watchPath === null || $this->reRender === null) {
            return $this;
        }

        $changed = FileWatcher::pollTuple($this->watchPath, $this->watchMtime, $this->watchSize);
        if ($changed === null) {
            return $this;
        }

        try {
            // The same door every launch lane uses — no cap-free back
            // entrance for the sweep that runs for the whole session.
            $raw = DocumentGate::readFile($this->watchPath);
        } catch (\InvalidArgumentException) {
            // Refused replacement: keep the frame, consume the tuple, flag
            // the header. "Stat said there, the read said gone" collapses
            // here too — FileWatcher::pollTuple answers null once the path
            // is truly absent, so delete-then-recreate recovery keeps its
            // baseline through the sweep that finds nothing.
            return $this->copy([
                'watchMtime'    => $changed[0],
                'watchSize'     => $changed[1],
                'reloadBlocked' => true,
            ]);
        }

        return $this->copy([
            'viewport'      => $this->viewport->setContent(($this->reRender)($raw)),
            'watchMtime'    => $changed[0],
            'watchSize'     => $changed[1],
            'reloadCount'   => $this->reloadCount + 1,
            'reloadBlocked' => false,
        ]);
    }

    /** Re-arms the one-shot sweep timer; the chain continues per handled tick. */
    private function armReloadTick(): \Closure
    {
        return Cmd::tick(
            self::RELOAD_POLL_SECONDS,
            static fn(): Msg => new ReloadTickMsg(),
        );
    }

    public function view(): string
    {
        $body = $this->viewport->view();
        if ($this->documentName === null) {
            return $body;
        }
        return $this->statusLine() . "\n" . $body;
    }

    /**
     * Locale-neutral status header: `name · 42%` plus a `↻N` reload marker
     * only after the first auto-reload. Symbols, not words, so the row
     * needs no translation and cannot overflow narrow boxes; it is clipped
     * and padded to exactly the viewport width.
     */
    private function statusLine(): string
    {
        $percent = (int) round($this->viewport->scrollPercent() * 100);
        $line    = $this->documentName . ' · ' . $percent . '%';
        if ($this->reloadBlocked === true) {
            // A refused replacement reads louder than the success counter:
            // ↻N says "auto-reload is alive", ↻! says "the last change was
            // refused at the document door — the frame you see is stale".
            $line .= ' · ↻!';
        } elseif ($this->reloadCount > 0) {
            $line .= ' · ↻' . $this->reloadCount;
        }
        $width = $this->viewport->width;
        return Width::padRight(Width::truncate($line, max(0, $width)), $width);
    }

    /** Rows the status header reserves from the terminal height. */
    private function chromeRows(): int
    {
        return $this->documentName === null ? 0 : 1;
    }

    public function isExited(): bool { return $this->exited; }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}

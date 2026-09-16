<?php

declare(strict_types=1);

namespace SugarCraft\Glow;

use SugarCraft\Bits\Viewport\Viewport;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * Pager Model used by {@see RenderCommand} when `-p` / `--pager` is set.
 * Wraps a {@see Viewport} containing the already-rendered (styled) text.
 * Standard reader keys are forwarded to the Viewport; `q` / `Esc` /
 * `Ctrl+C` exit the loop.
 *
 * File-watch auto-reload (E735 step 10.25): when armed with
 * {@see withWatch()}, the model rides a `Cmd::tick()` re-arm chain (the
 * sugar-bits Stopwatch shape) that sweeps the source file's
 * (mtime, size) tuple once per interval through {@see FileWatcher::pollTuple()}
 * — one non-blocking stat per tick, no Generator, no sleep inside the
 * loop. A changed tuple re-reads the file, runs it through the injected
 * re-render closure, and swaps the Viewport content in place. The tick
 * interval is an idle bound (E646: never a request deadline), and a
 * deleted-then-recreated file reloads when it reappears because the
 * sweep keeps polling the baseline.
 */
final class GlowModel implements Model
{
    /** Pager file-watch sweep interval in seconds (idle poll cadence, E646-legal). */
    public const RELOAD_POLL_SECONDS = 0.5;

    public static function fromContent(string $content, int $width = 80, int $height = 24): self
    {
        $vp = Viewport::new($width, max(1, $height))->setContent($content);
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
    ) {}

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

        return new self(
            $this->viewport,
            $this->exited,
            $path,
            $reRender,
            $baseline[0],
            $baseline[1],
            $this->reloadCount,
        );
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
        if ($msg instanceof ReloadTickMsg && $msg->id === ReloadTickMsg::ID && $this->watchPath !== null) {
            $next = $this->sweepForReload();
            // One chain, one re-arm per handled tick (Stopwatch pattern) —
            // re-arming on unrelated messages would fork parallel chains.
            return [$next, $next->armReloadTick()];
        }
        if ($msg instanceof KeyMsg) {
            if ($msg->type === KeyType::Escape
                || ($msg->ctrl && $msg->rune === 'c')
                || ($msg->type === KeyType::Char && $msg->rune === 'q' && $msg->ctrl === false)) {
                return [new self($this->viewport, true), Cmd::quit()];
            }
        }
        [$next, $cmd] = $this->viewport->update($msg);
        return [$this->withViewport($next), $cmd];
    }

    /**
     * One non-blocking (mtime, size) sweep; returns a content-refreshed
     * copy when the file moved, `$this` (identical instance) when it did
     * not. Read/render failures keep the last good frame: the pager never
     * blanks on a transient half-written save.
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

        $raw = @file_get_contents($this->watchPath);
        if ($raw === false) {
            // Stat said "there", the read said "gone" — keep the baseline
            // so the next sweep retries (covers delete-then-recreate).
            return $this;
        }

        return new self(
            $this->viewport->setContent(($this->reRender)($raw)),
            $this->exited,
            $this->watchPath,
            $this->reRender,
            $changed[0],
            $changed[1],
            $this->reloadCount + 1,
        );
    }

    /** Re-arms the one-shot sweep timer; the chain continues per handled tick. */
    private function armReloadTick(): \Closure
    {
        return Cmd::tick(
            self::RELOAD_POLL_SECONDS,
            static fn(): Msg => new ReloadTickMsg(),
        );
    }

    /** Copies the watch state onto a viewport-derived successor. */
    private function withViewport(Viewport $viewport): self
    {
        return new self(
            $viewport,
            $this->exited,
            $this->watchPath,
            $this->reRender,
            $this->watchMtime,
            $this->watchSize,
            $this->reloadCount,
        );
    }

    public function view(): string
    {
        return $this->viewport->view();
    }

    public function isExited(): bool { return $this->exited; }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}

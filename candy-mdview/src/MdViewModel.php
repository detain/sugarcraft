<?php

declare(strict_types=1);

namespace SugarCraft\MdView;

use SugarCraft\Forms\Viewport\Viewport;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\I18n\T;
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
 * rides a fixed 0.5 s `ReloadTickMsg` chain (MdViewModel::RELOAD_POLL_SECONDS,
 * re-armed per handled tick — the sugar-widgets Stopwatch shape). Each sweep
 * is ONE non-blocking (mtime, size) probe through {@see FileWatcher::pollTuple()};
 * a differing tuple consumes the baseline and queues the read+re-render for
 * the FOLLOWING tick, so a save burst never parks the loop behind a parse
 * while keys wait, and rapid edits coalesce to the newest bytes (an interval
 * bound, never a request deadline). Reload applies when the recreated file's
 * (mtime, size) differs from the baseline — a restore to the identical
 * fingerprint stays invisible, and `r` forces a reload of the current bytes
 * either way. Refused replacements (oversize, device swap, binary) and
 * throwing re-renders never blank the pager: the last good frame stays, the
 * tuple is consumed, and the header marks `↻!` until the next accepted
 * reload.
 */
final class MdViewModel implements Model
{
    /** Pager file-watch sweep interval in seconds (fixed tick, re-armed per handled ReloadTickMsg). */
    public const RELOAD_POLL_SECONDS = 0.5;

    /**
     * The chords this model owns (the Viewport takes every navigation key).
     * Config's keymap block may replace either rune; the Escape and Ctrl+C
     * quit legs are structural and never remap.
     */
    public const DEFAULT_KEYMAP = ['quit' => 'q', 'reload' => 'r'];

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
        /** True between change-detection and the deferred read+re-render tick. */
        public readonly bool $pendingReload = false,
        /** fn(liveCols): renderedText — set ONLY when the width was not pinned (finding 014). */
        private readonly ?\Closure $reflowAt = null,
        /** action => chord for this model's own keys; starts at DEFAULT_KEYMAP. */
        public readonly array $keymap = self::DEFAULT_KEYMAP,
    ) {}

    /**
     * Structural clone with named overrides — the ONE derivation site, so
     * no state field can be silently zeroed by a hand-rolled copy.
     *
     * @param array{viewport?:Viewport,exited?:bool,watchPath?:?string,reRender?:?\Closure,watchMtime?:int,watchSize?:int,reloadCount?:int,documentName?:?string,reloadBlocked?:bool,pendingReload?:bool,reflowAt?:?\Closure,keymap?:array<string,string>} $overrides
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
            $pick('pendingReload', $this->pendingReload),
            $pick('reflowAt', $this->reflowAt),
            $pick('keymap', $this->keymap),
        );
    }

    /**
     * Enter document mode: paint a status header and reserve its row. The
     * viewport height shrinks by one so header + body never exceed the
     * terminal, and every later resize recomputes with the same accounting.
     */
    public function withDocument(string $name): self
    {
        $vp = $this->viewport->withSize($this->viewport->width, max(0, $this->viewport->height - 1));
        return $this->copy(['viewport' => self::withIndicator($vp, $name), 'documentName' => $name]);
    }

    /**
     * Discoverability for hidden tails (finding 089): a document that
     * overflows its rows paints the forms scrollbar; one that fits must
     * stay byte-identical to the plain path. The flag is re-synced ONLY at
     * content/height transitions (the steady state returns the SAME
     * viewport — no clone per frame, no per-keystroke width re-scan).
     * The richer `›` right-edge corner rides the Viewport maxXOffset memo
     * (finding 012 / S22) — owner-gated upgrade, deliberately not taken.
     */
    private static function withIndicator(Viewport $viewport, ?string $documentName): Viewport
    {
        if ($documentName === null) {
            return $viewport;
        }
        $overflow = count($viewport->lines) > $viewport->height;

        return $viewport->showScrollbar === $overflow ? $viewport : $viewport->withScrollbar($overflow);
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

    /**
     * Arm the resize-reflow path (finding 014). The closure receives the
     * live column count on every geometry-changing WindowSizeMsg and returns
     * the fully re-rendered document text; the viewport re-clips scroll
     * offsets against it. Armed ONLY for an unpinned width — an explicit
     * --width is a user decision the resize leg must never override.
     *
     * @param \Closure(int):string $reflowAt
     */
    public function withReflow(\Closure $reflowAt): self
    {
        return $this->copy(['reflowAt' => $reflowAt]);
    }

    /**
     * Replace the default chords for this model's own keys (the config
     * file's keymap door, finding 036). Overrides merge onto the current
     * map, so a partial keymap keeps every untouched binding; the Escape
     * and Ctrl+C quit legs are structural and stay whatever the map says.
     *
     * @param array<string,string> $overrides action => chord ("x", "ctrl-x", "alt-x")
     */
    public function withKeymap(array $overrides): self
    {
        $map = $this->keymap;
        foreach ($overrides as $action => $chord) {
            $map[$action] = $chord;
        }
        return $this->copy(['keymap' => $map]);
    }

    /**
     * Does this keystroke play the configured chord? A bare rune means the
     * UNMODIFIED key (today's `q` law); `ctrl-`/`alt-` prefixes name the
     * deliberate modifier chords.
     */
    private static function chordMatches(KeyMsg $msg, string $chord): bool
    {
        if ($msg->type !== KeyType::Char) {
            return false;
        }
        if (str_starts_with($chord, 'ctrl-')) {
            return $msg->ctrl === true && $msg->alt === false && $msg->rune === substr($chord, 5);
        }
        if (str_starts_with($chord, 'alt-')) {
            return $msg->alt === true && $msg->ctrl === false && $msg->rune === substr($chord, 4);
        }
        return $msg->ctrl === false && $msg->alt === false && $msg->shift === false && $msg->rune === $chord;
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
            // 1-row terminal (finding 038): the header reserves its row from
            // the REAL height, so at rows=1 the body gets zero rows — never
            // the old max(1, …) overshoot that painted 2 frame lines into a
            // 1-row alt screen and tore every later row (same corruption
            // class as the width leg).
            $height = max(0, $msg->rows - $this->chromeRows());
            if ($msg->cols === $this->viewport->width && $height === $this->viewport->height) {
                return [$this, null];
            }
            $viewport = $this->viewport->withSize($msg->cols, $height);
            if ($this->reflowAt !== null && $msg->cols !== $this->viewport->width) {
                // glow parity (finding 014): with --width omitted the document
                // REFLOWS at the live column count — the README has promised
                // this since day one while the code only ever moved the clip
                // window. With --width given, $reflowAt is null and reflow is
                // refused exactly as the user pinned it.
                $viewport = $viewport->setContent(($this->reflowAt)($msg->cols));
            }
            $viewport = self::withIndicator($viewport, $this->documentName);
            return [$this->copy(['viewport' => $viewport]), null];
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
                || self::chordMatches($msg, $this->keymap['quit'])) {
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
            if (self::chordMatches($msg, $this->keymap['reload']) && $this->watchPath !== null) {
                // Force-reload (finding 044): a rewrite preserving BOTH
                // second-granularity mtime and byte size is invisible to the
                // tuple forever, so `r` re-snapshots the baseline against the
                // current bytes and queues the deferred apply — the same
                // no-park shape the sweep uses. The quit gate stays q/Esc/Ctrl+C.
                $now = FileWatcher::snapshot($this->watchPath) ?? [$this->watchMtime, $this->watchSize];

                return [$this->copy([
                    'watchMtime'    => $now[0],
                    'watchSize'     => $now[1],
                    'pendingReload' => true,
                ]), null];
            }
        }
        [$next, $cmd] = $this->viewport->update($msg);
        return [$this->copy(['viewport' => $next]), $cmd];
    }

    /**
     * One non-blocking (mtime, size) sweep. Returns `$this` (identical
     * instance) when nothing moved, a baseline-consuming copy when the file
     * changed (the apply rides the FOLLOWING tick — finding 017), the
     * applied copy when a queued reload comes due, and a refused copy when
     * the queued bytes fail the document gate or the re-render throws.
     */
    private function sweepForReload(): self
    {
        if ($this->watchPath === null || $this->reRender === null) {
            return $this;
        }

        if ($this->pendingReload === true) {
            return $this->applyPendingReload($this->watchPath, $this->reRender);
        }

        $changed = FileWatcher::pollTuple($this->watchPath, $this->watchMtime, $this->watchSize);
        if ($changed === null) {
            return $this;
        }

        // Detecting a change and applying it live on different ticks so the
        // loop never parks behind a parse while keys queue (finding 017).
        // The tuple is consumed NOW; further edits before the apply tick
        // only rewrite the baseline — the apply reads whatever bytes are
        // current, which coalesces a burst to its last save (drop-if-stale,
        // the (mtime, size) law already means last-write-wins).
        return $this->copy([
            'watchMtime'    => $changed[0],
            'watchSize'     => $changed[1],
            'pendingReload' => true,
        ]);
    }

    /**
     * The follow-up tick's apply pass: read through the shared document
     * gate and swap the frame. A refused read (oversize, device swap,
     * binary — finding 003) or a throwing re-render (finding 019) keeps the
     * last good frame and marks the header `↻!`. Both consume the baseline
     * the detection tick snapshotted: refused or blowing-up bytes are never
     * re-read every 0.5 s, and the next real edit (or `r`) gets a fresh
     * attempt.
     *
     * @param \Closure(string):string $reRender
     */
    private function applyPendingReload(string $path, \Closure $reRender): self
    {
        // The baseline tracks whatever bytes THIS pass observed, newest
        // wins — including a rewrite that landed between detection and read.
        $seen = FileWatcher::snapshot($path) ?? [$this->watchMtime, $this->watchSize];

        try {
            $raw     = FrontMatter::strip(DocumentGate::readFile($path));
            $content = $reRender($raw);
        } catch (\InvalidArgumentException) {
            return $this->refusedReload($seen);
        } catch (\Throwable) {
            // sugar-markdown can throw on hostile-but-readable input. One
            // bad save must not kill the live pager mid-read; retrying the
            // SAME bytes would re-run the same deterministic throw, so the
            // tuple is consumed here too — a deliberate divergence from the
            // old "keep the baseline" wording, stated in this docblock.
            return $this->refusedReload($seen);
        }

        return $this->copy([
            'viewport'      => self::withIndicator($this->viewport->setContent($content), $this->documentName),
            'watchMtime'    => $seen[0],
            'watchSize'     => $seen[1],
            'reloadCount'   => $this->reloadCount + 1,
            'reloadBlocked' => false,
            'pendingReload' => false,
        ]);
    }

    /**
     * @param array{0:int, 1:int} $seen
     */
    private function refusedReload(array $seen): self
    {
        return $this->copy([
            'watchMtime'    => $seen[0],
            'watchSize'     => $seen[1],
            'pendingReload' => false,
            'reloadBlocked' => true,
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
        // Horizontal-scroll right-clip (finding 001, mdview's defensive leg):
        // Viewport::view() left-shifts with Width::dropAnsi when xOffset > 0
        // and skips the right truncation, sugar-core's Renderer maps frame
        // lines 1:1 to rows with no clipping of its own and never disables
        // DECAWM — an over-viewport row wraps and smears every subsequent
        // row, tearing the alt screen in the DEFAULT mode (width 0 hands
        // paragraphs as single long lines to exactly this path). The
        // root fix belongs to sugar-forms (its xOffset branch); until that
        // leg lands, the consumer clips its own frame: every emitted line
        // is ANSI-aware truncated to the viewport width, so the frame
        // always satisfies Width::of(line) <= width.
        if ($this->viewport->xOffset > 0 && $this->viewport->width > 0) {
            $body = implode("\n", array_map(
                fn (string $line): string => Width::truncateAnsi($line, $this->viewport->width),
                explode("\n", $body),
            ));
        }
        if ($this->documentName === null) {
            return $body;
        }
        if ($this->viewport->height <= 0) {
            // Zero body rows (a 1-row terminal, finding 038): the header
            // ALONE is the frame — appending "\n" to it would paint a
            // second line the terminal cannot hold. An empty document at
            // a real height still gets its blank body row above.
            return $this->statusLine();
        }
        return $this->statusLine() . "\n" . $body;
    }

    /**
     * Status header: `name · 42%` plus a `↻N` reload marker only after the
     * first auto-reload. Symbols, not words, so the row needs no translation
     * and cannot overflow narrow boxes; it is clipped and padded to exactly
     * the viewport width. The glyphs themselves are Unicode — on a locale
     * that does not promise UTF-8 (C/POSIX, or an explicit non-UTF-8
     * charset) they would arrive as mojibake, so the row falls back to the
     * pure-ASCII shape `name 42% (r3)` instead. Unknown charset (no locale
     * variable at all) stays Unicode: the modern default.
     */
    private function statusLine(): string
    {
        $percent = (int) round($this->viewport->scrollPercent() * 100);
        $ascii   = self::headerIsAsciiOnly();
        $sep     = $ascii ? ' ' : ' · ';
        $line    = $this->documentName . $sep . $percent . '%';
        if ($this->reloadBlocked === true) {
            // A refused replacement reads louder than the success counter:
            // ↻N says "auto-reload is alive", ↻! says "the last change was
            // refused at the document door — the frame you see is stale".
            $line .= $ascii ? ' (r!)' : ' · ↻!';
        } elseif ($this->reloadCount > 0) {
            $line .= $ascii ? ' (r' . $this->reloadCount . ')' : ' · ↻' . $this->reloadCount;
        }
        $width = $this->viewport->width;
        return Width::padRight(Width::truncate($line, max(0, $width)), $width);
    }

    /**
     * Does the current locale environment forbid non-ASCII in the header?
     * `T::charset()` names any declared charset; its null answer is
     * ambiguous — unset locale (today's UTF-8-by-default world) versus the
     * C/POSIX locales, which it deliberately skips — so the tie is broken
     * with the same first-value-takes-position chain.
     */
    private static function headerIsAsciiOnly(): bool
    {
        $charset = T::charset();
        if ($charset !== null) {
            return preg_match('/^UTF-?8$/i', $charset) !== 1;
        }
        foreach (['LC_ALL', 'LC_MESSAGES', 'LANG'] as $var) {
            $raw = (string) ($_SERVER[$var] ?? getenv($var));
            if ($raw === '') {
                continue; // takes no position, exactly like T::charset()
            }
            return $raw === 'C' || $raw === 'POSIX';
        }
        return false;
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

<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Terminal;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stream\ReadableStreamInterface;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cursor\Cursor;
use SugarCraft\Ansi\Parser\Parser;
use SugarCraft\Vt\Handler\ScreenHandler;
use SugarCraft\Vt\Mode\Mode;
use SugarCraft\Vt\Screen\Screen;
use SugarCraft\Vt\Screen\Scrollback;
use SugarCraft\Vt\Sgr\Sgr;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Public terminal facade.
 *
 * Holds a {@see Parser} and a {@see ScreenHandler} that owns the
 * Buffer, Cursor, Sgr pen, and Mode. `feed()` drives bytes through the
 * parser; accessors return the handler's current state.
 * `feedAsync()`/`feedStream()` are the ReactPHP entry points for the same
 * machine — they deliver the terminal→host reply channel as promise
 * values instead of requiring the caller to poll `replies()`.
 */
final class Terminal
{
    private Parser $parser;
    private ScreenHandler $handler;
    private int $scrollbackSize;

    public function __construct(
        int $cols,
        int $rows,
        ?Buffer $buffer = null,
        ?Cursor $cursor = null,
        ?Mode $mode = null,
        int $scrollbackSize = 1000,
    ) {
        $this->scrollbackSize = $scrollbackSize;
        $this->handler = new ScreenHandler(
            buffer: $buffer ?? new Buffer($cols, $rows),
            cursor: $cursor,
            sgr: Sgr::empty(),
            mode: $mode,
            scrollback: new Scrollback($scrollbackSize),
        );
        // 64 KiB string-buffer cap (candy-ansi default) bounds OSC/DCS payload
        // memory; reduced from the fork's 1 MiB per the W1.2 security item.
        // The handler is the parser's sink AND a SubparamsAwareHandler, so the
        // colon continuation flags SGR needs arrive by push — no late-bound
        // back-reference to this parser is wired anywhere.
        $this->parser = new Parser($this->handler, maxStringBuffer: 65536);
    }

    /**
     * Canonical factory — mirrors the constructor params directly.
     */
    public static function new(int $cols = 80, int $rows = 24, ?int $scrollbackSize = null): self
    {
        return new self($cols, $rows, null, null, null, $scrollbackSize ?? 1000);
    }

    /**
     * @deprecated use `Terminal::new()` instead
     */
    public static function create(int $cols = 80, int $rows = 24): self
    {
        return self::new($cols, $rows);
    }

    /**
     * Drive bytes through the parser.
     *
     * Backwards-compatible query→reply channel (audit §B): when
     * `$respond` is given, the full queue of terminal→host answers —
     * DA1/DA2, DECRPM, CPR, XTWINOPS … — produced by this feed (plus any
     * earlier ones still queued) is handed to it in request order as the
     * raw bytes a real terminal would write back to the application's
     * tty. Without a callback the answers queue for {@see replies()}
     * draining, which keeps the original `feed(string): void` contract
     * untouched for all existing callers.
     *
     * Mirrors charmbracelet/x/vt's `Emulator.Read()` io.Pipe semantics in
     * pull form (x/vt emulator.go L265-281).
     *
     * @param (callable(string): void)|null $respond
     */
    public function feed(string $bytes, ?callable $respond = null): void
    {
        $this->parser->feed($bytes);
        if ($respond !== null) {
            foreach ($this->handler->replies as $reply) {
                $respond($reply);
            }
            $this->handler->replies = [];
        }
    }

    /**
     * Drain the queued terminal→host replies (DA1/DA2, DECRPM, CPR,
     * XTWINOPS …) in request order.
     *
     * @return list<string>
     */
    public function replies(): array
    {
        $pending = $this->handler->replies;
        $this->handler->replies = [];
        return $pending;
    }

    /**
     * Async form of {@see feed()}: parse `$bytes` and resolve with the
     * terminal→host answer bytes this feed produced (plus any earlier ones
     * still queued), concatenated in request order — exactly the byte
     * stream `$respond` would have received from {@see feed()}, delivered
     * as the promise value instead of a callback.
     *
     * The parser is pure CPU work with no I/O wait, so the promise is
     * already resolved on return; the async win is composability (the
     * reply channel joins promise pipelines without a callback) and one
     * honest type for callers that must handle replies either way.
     * Mirrors charmbracelet/x/vt `Emulator.Read()` io.Pipe semantics in
     * future form (x/vt emulator.go L265-281).
     *
     * @return PromiseInterface<string>
     */
    public function feedAsync(string $bytes): PromiseInterface
    {
        $this->parser->feed($bytes);
        return resolve($this->drainReplyBytes());
    }

    /**
     * Pump a stream of terminal input through the parser as it arrives.
     *
     * Each `data` event feeds the parser incrementally — no chunking or
     * buffering is imposed, so a sequence split across two events parses
     * exactly as one feed would. On `end` the parser is {@see flush()}ed
     * so an unterminated trailing OSC/DCS still dispatches, then the
     * promise settles. Reply-channel semantics follow {@see feed()}:
     * with `$respond` given, every answer byte string is handed to it the
     * moment its sequence dispatches and the promise resolves with the
     * (empty) remainder; without it, answers queue normally and the
     * promise resolves with all of them concatenated, the caller's
     * single drain for the whole session.
     *
     * A stream that is already closed or ended when attached yields a
     * rejected promise; a stream that `close`s without ever signalling
     * `end` (truncated/aborted input) rejects too, because silently
     * resolving on half-read input would hide a real transport failure.
     * Listeners remove themselves once settled, so a late `data` on a
     * closed pump cannot re-enter the parser.
     *
     * @param (callable(string): void)|null $respond
     *
     * @return PromiseInterface<string>
     */
    public function feedStream(ReadableStreamInterface $input, ?callable $respond = null): PromiseInterface
    {
        if (!$input->isReadable()) {
            return reject(new \RuntimeException('Cannot pump a terminal input stream that is not readable (already ended or closed).'));
        }
        /** @var Deferred<string> $deferred */
        $deferred = new Deferred();
        /** @var array<string, callable> $listeners */
        $listeners = [];
        $settled = false;
        $detach = static function () use ($input, &$listeners): void {
            foreach ($listeners as $event => $listener) {
                $input->removeListener($event, $listener);
            }
            $listeners = [];
        };
        $listeners['data'] = $onData = function (string $chunk) use ($respond): void {
            $this->parser->feed($chunk);
            if ($respond !== null) {
                foreach ($this->handler->replies as $reply) {
                    $respond($reply);
                }
                $this->handler->replies = [];
            }
        };
        $listeners['error'] = $onError = function (\Throwable $error) use ($deferred, $detach, &$settled): void {
            if ($settled) {
                return;
            }
            $settled = true;
            $detach();
            $deferred->reject($error);
        };
        $listeners['end'] = $onEnd = function () use ($respond, $deferred, $detach, &$settled): void {
            if ($settled) {
                return;
            }
            $settled = true;
            $this->parser->flush();
            $tail = $this->drainReplyBytes();
            if ($respond !== null && $tail !== '') {
                $respond($tail);
                $tail = '';
            }
            $detach();
            $deferred->resolve($tail);
        };
        // React emits `close` after every `end` and after an abort; only
        // the one that finds us unsettled matters (end wins when both come).
        $listeners['close'] = $onClose = function () use ($deferred, $detach, &$settled): void {
            if ($settled) {
                return;
            }
            $settled = true;
            $detach();
            $deferred->reject(new \RuntimeException('Terminal input stream closed before signalling end-of-stream.'));
        };
        foreach ($listeners as $event => $listener) {
            $input->on($event, $listener);
        }
        return $deferred->promise();
    }

    /**
     * Force any in-flight string sequence (OSC/DCS/SOS/PM/APC) to
     * dispatch with its current payload and reset to ground. Useful at
     * end-of-stream when you can't wait for a real terminator byte.
     */
    public function flush(): void
    {
        $this->parser->flush();
    }

    /**
     * Consume the whole reply queue as one concatenated byte string,
     * in request order — the delivery shape shared by the async feeds.
     */
    private function drainReplyBytes(): string
    {
        $bytes = implode('', $this->handler->replies);
        $this->handler->replies = [];
        return $bytes;
    }

    public function screen(): Screen
    {
        return Screen::fromBuffer($this->handler->buffer, $this->handler->scrollback);
    }

    public function cursor(): Cursor
    {
        return $this->handler->cursor;
    }

    /**
     * True while a DECAWM deferred wrap is armed: a glyph has landed in
     * the last column and the cursor is parked there until the next
     * graphic print consumes it (mirrors xterm `_wrapnext` / charmbracelet
     * x/vt `Emulator.atPhantom`).
     */
    public function isWrapPending(): bool
    {
        return $this->handler->wrapPending;
    }

    public function mode(): Mode
    {
        return $this->handler->mode;
    }

    public function windowTitle(): ?string
    {
        return $this->handler->windowTitle;
    }

    /** @return array<int, \SugarCraft\Vt\Color\Color> Indexed palette overrides set via OSC 4. */
    public function palette(): array
    {
        return $this->handler->palette;
    }

    /**
     * Clipboard events recorded from OSC 52 sequences.
     *
     * Each entry: `['kind' => 'write'|'read', 'selection' => string, 'payload' => string]`
     * (`payload` is base64-encoded content for writes; absent for reads).
     *
     * @return list<array{kind: string, selection: string, payload?: string}>
     */
    public function clipboardEvents(): array
    {
        return $this->handler->clipboardEvents;
    }

    public function resize(int $cols, int $rows): void
    {
        if ($cols < 1 || $rows < 1) {
            throw new \InvalidArgumentException('cols and rows must be >= 1');
        }
        $oldRows = $this->handler->buffer->rows;
        // A full-screen scroll region must track the screen across a resize
        // GROWTH: under the scrollback full-screen gate (w4-vt) a stale
        // [0, oldRows-1] region becomes a strict sub-region after the view
        // grows, permanently silencing history for apps that never re-issue
        // DECSTBM (the plain-shell-in-a-resized-window case).
        $wasFullScreenRegion = $this->handler->scrollRegionTop === 0
            && $this->handler->scrollRegionBottom === $oldRows - 1;
        $this->handler->buffer = $this->handler->buffer->resize($cols, $rows);
        // Deferred-wrap + bounds maintenance on the new geometry, mirroring
        // charmbracelet/x/vt Emulator.Resize (emulator.go L218-221): a
        // phantom cell that became a plain in-bounds position after a width
        // growth resolves to a real advance; any out-of-range cursor is
        // clamped back into the grid.
        $cursor = $this->handler->cursor;
        if ($this->handler->wrapPending && $cursor->col < $cols - 1) {
            $this->handler->wrapPending = false;
            $this->handler->cursor = $cursor->withCol($cursor->col + 1);
            $cursor = $this->handler->cursor;
        }
        $clampedCol = min($cursor->col, $cols - 1);
        $clampedRow = min($cursor->row, $rows - 1);
        if ($clampedCol !== $cursor->col || $clampedRow !== $cursor->row) {
            $this->handler->cursor = $cursor->withCol($clampedCol)->withRow($clampedRow);
        }
        if ($this->handler->scrollRegionBottom > $rows - 1) {
            $this->handler->scrollRegionBottom = $rows - 1;
            $this->handler->scrollRegionTop = min($this->handler->scrollRegionTop, $rows - 1);
        } elseif ($wasFullScreenRegion) {
            $this->handler->scrollRegionBottom = $rows - 1;
        }
    }

    /**
     * Snapshot clone: a fresh Parser starts in Ground, so any in-flight
     * string sequence (partial OSC/DCS payload, partial UTF-8 rune) is
     * intentionally dropped — the clone captures committed state only.
     * ScreenHandler::__clone() deep-copies the mutable Buffer/Scrollback
     * so the clone can never write through into this terminal's grid.
     */
    public function __clone(): void
    {
        // The handler's colon flags arrive by push (SubparamsAwareHandler), so
        // the clone needs no re-attach dance: constructing the clone's own
        // Parser over the cloned handler means the clone's SGR dispatches read
        // the clone's flags — the stale-back-reference this re-attach once
        // patched simply cannot occur any more.
        $this->handler = clone $this->handler;
        $this->parser = new Parser($this->handler, maxStringBuffer: 65536);
    }

    /** @internal */
    public function withBuffer(Buffer $buf): self
    {
        $clone = clone $this;
        $clone->handler->buffer = $buf;
        return $clone;
    }

    /** @internal */
    public function withCursor(Cursor $cursor): self
    {
        $clone = clone $this;
        $clone->handler->cursor = $cursor;
        return $clone;
    }

    /** @internal */
    public function withMode(Mode $mode): self
    {
        $clone = clone $this;
        $clone->handler->mode = $mode;
        return $clone;
    }

    /** @internal */
    public function withWindowTitle(?string $title): self
    {
        $clone = clone $this;
        $clone->handler->windowTitle = $title;
        return $clone;
    }

    /**
     * Replace the active tab stops with explicit column indices.
     *
     * Pass column numbers (0-based). Out-of-range or duplicate entries
     * are normalised. To clear all tab stops pass an empty array.
     *
     * @param list<int> $cols
     */
    public function withTabStops(array $cols): self
    {
        $clone = clone $this;
        $stops = [];
        foreach ($cols as $c) {
            $i = (int) $c;
            if ($i >= 0) {
                $stops[$i] = true;
            }
        }
        $clone->handler->tabStops = $stops;
        return $clone;
    }

    /**
     * Return a new Terminal with a different scrollback buffer size.
     * The new size applies to future scrolling; existing scrollback is kept.
     */
    public function withScrollbackSize(int $size): self
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('scrollbackSize must be >= 1');
        }
        $clone = clone $this;
        $clone->scrollbackSize = $size;
        $clone->handler->scrollback = new Scrollback($size);
        return $clone;
    }

    /**
     * Return a new Terminal reporting the given nominal cell pixel size
     * in XTWINOPS 14t/16t replies. The emulator renders no font, so the
     * classic 8×16 defaults are advisory; mosaic's `probeFontSize()` only
     * consumes them as cell/window ratios.
     */
    public function withCellPixels(int $widthPx, int $heightPx): self
    {
        if ($widthPx < 1 || $heightPx < 1) {
            throw new \InvalidArgumentException('cell pixel size must be >= 1');
        }
        $clone = clone $this;
        $clone->handler->cellWidthPx = $widthPx;
        $clone->handler->cellHeightPx = $heightPx;
        return $clone;
    }

    /**
     * Programmatically enter the alternate screen buffer (DEC 1049 mode).
     * Saves the main screen buffer, cursor, and SGR state.
     */
    public function enableAltScreen(): void
    {
        $this->handler->enterAltScreen();
    }

    /**
     * Programmatically leave the alternate screen buffer.
     * Restores the main screen buffer, cursor, and SGR state.
     */
    public function disableAltScreen(): void
    {
        $this->handler->leaveAltScreen();
    }

    /**
     * Returns true if the alternate screen buffer is currently active.
     */
    public function isAltScreen(): bool
    {
        return $this->handler->mode->isAltScreen();
    }
}

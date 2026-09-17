<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Terminal;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stream\ReadableStreamInterface;
use SugarCraft\Async\CancellationToken;
use SugarCraft\Async\OperationCancelledException;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cursor\Cursor;
use SugarCraft\Ansi\Parser\Parser;
use SugarCraft\Vt\Handler\ScreenHandler;
use SugarCraft\Vt\Mode\Mode;
use SugarCraft\Vt\Msg\FocusInMsg;
use SugarCraft\Vt\Msg\FocusOutMsg;
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
        ?\Closure $onFocusEvent = null,
    ) {
        $this->scrollbackSize = $scrollbackSize;
        $this->handler = new ScreenHandler(
            buffer: $buffer ?? new Buffer($cols, $rows),
            cursor: $cursor,
            sgr: Sgr::empty(),
            mode: $mode,
            scrollback: new Scrollback($scrollbackSize),
            onFocusEvent: $onFocusEvent,
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
     * closed pump cannot re-enter the parser — and that holds even when
     * the caller's `$respond` throws: the pump rejects its promise with
     * the callback's error and detaches before the exception surfaces to
     * the emitter (on the `data` route it re-throws for parity with the
     * sync `feed($bytes, $respond)` contract, where a throwing callback
     * bubbles to whoever wrote the bytes). On that data-route failure the
     * replies spliced out of the queue but not yet handed over are DROPPED
     * with the dead pump — unlike sync `feed()`, which leaves its queue
     * intact for a later retry, a rejected promise is the caller's notice
     * and a resurrected answer on a dead stream would be a phantom.
     *
     * Cancellation is cooperative and immediate: pass a candy-async
     * {@see CancellationToken} (owned by the caller's
     * {@see \SugarCraft\Async\CancellationSource}) as `$cancellation` and the
     * caller can abort mid-stream — the moment `cancel()` is reached the pump
     * detaches every stream listener and rejects the promise with
     * {@see OperationCancelledException}, so a late `data` write can no longer
     * re-enter the parser. This closes the reviewer-agreed gap where the only
     * way to stop a pump was to end the promise's *consumer*, leaving the data
     * listener attached until the stream itself ended or errored (n3, wave-6
     * handoff §6.1). The race is single-shot: whichever of `end`/`error`/
     * `close`/cancel arrives first owns the one settlement (the same
     * settle-once latch the failure paths use), and cancellation is idempotent
     * — a `cancel()` that arrives after the pump already settled is a no-op.
     * The pump never closes or pauses `$input` (the stream belongs to the
     * caller), it only stops listening. Mirrors the token-cooperative bridge the
     * candy-async consumers use (`CancellableQuery::wrap()`): `null` leaves
     * today's behaviour byte-identical (the pre-existing `end`/`error`/`close`
     * settlement paths are untouched); a non-null token rejects promptly with an
     * {@see OperationCancelledException} (a `\RuntimeException` subclass, so
     * callers already catching stream rejections catch a cancel unchanged).
     * Because {@see CancellationToken} offers no callback unregistration, a
     * token reused across pumps accumulates one settled-guarded closure per
     * pump — prefer one token per pump.
     *
     * @param (callable(string): void)|null $respond
     *
     * @return PromiseInterface<string>
     */
    public function feedStream(
        ReadableStreamInterface $input,
        ?callable $respond = null,
        ?CancellationToken $cancellation = null,
    ): PromiseInterface {
        if (!$input->isReadable()) {
            return reject(new \RuntimeException('Cannot pump a terminal input stream that is not readable (already ended or closed).'));
        }
        /** @var Deferred<string> $deferred */
        $deferred = new Deferred();
        /** @var array<string, callable> $listeners */
        $listeners = [];
        /** @var bool $settled single-settlement latch, by-ref'd into every listener */
        $settled = false;
        $detach = static function () use ($input, &$listeners): void {
            foreach ($listeners as $event => $listener) {
                $input->removeListener($event, $listener);
            }
            $listeners = [];
        };
        $listeners['data'] = function (string $chunk) use ($respond, $deferred, $detach, &$settled): void {
            if ($settled) {
                return; // defense in depth: a settled pump never re-enters the parser.
            }
            try {
                $this->parser->feed($chunk);
                if ($respond === null) {
                    return;
                }
                // Clear-by-slice BEFORE delivering: a callback that throws
                // mid-loop must not leave already-delivered replies queued
                // for duplicate delivery on the next chunk (round-1 review n2).
                $pending = $this->handler->replies;
                $this->handler->replies = [];
                foreach ($pending as $reply) {
                    $respond($reply);
                }
            } catch (\Throwable $error) {
                // Anything the callee throws — parser internals (round-2
                // review n5) or the caller's own $respond — settles the
                // pump exactly once: reject, detach, then re-throw for
                // parity with the sync feed($bytes, $respond) contract,
                // where the exception bubbles to whoever wrote the bytes.
                // Replies spliced out but never handed over are dropped
                // with the dead pump — the rejection is the caller's
                // notice; a half-dead pump must not re-deliver them.
                $settled = true;
                $detach();
                $deferred->reject($error);
                throw $error;
            }
        };
        $listeners['error'] = function (\Throwable $error) use ($deferred, $detach, &$settled): void {
            if ($settled) {
                return;
            }
            $settled = true;
            $detach();
            $deferred->reject($error);
        };
        $listeners['end'] = function () use ($respond, $deferred, $detach, &$settled): void {
            if ($settled) {
                return;
            }
            $settled = true;
            $tail = '';
            try {
                $this->parser->flush();
                $tail = $this->drainReplyBytes();
                if ($respond !== null && $tail !== '') {
                    $respond($tail);
                    $tail = '';
                }
            } catch (\Throwable $error) {
                // A throwing $respond must still settle and detach: leaving
                // the promise pending with live listeners on a dead pump
                // would hang the caller forever (round-1 review M1).
                $detach();
                $deferred->reject($error);

                return;
            }
            $detach();
            $deferred->resolve($tail);
        };
        // React emits `close` after every `end` and after an abort; only
        // the one that finds us unsettled matters (end wins when both come).
        $listeners['close'] = function () use ($deferred, $detach, &$settled): void {
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
        if ($cancellation !== null) {
            // Cooperative abort: on `cancel()` the pump detaches exactly as the
            // natural settle paths do, then rejects with the candy-async
            // cancellation signal, so a caller holding a CancellationSource can
            // stop the pump mid-stream instead of only ending its consumer (n3).
            // Registered AFTER the listeners attach so an already-cancelled token
            // — whose onCancel fires synchronously — still has live listeners to
            // remove. Guarded by the same $settled latch the failure paths use,
            // so a cancel racing end/error/close loses cleanly: exactly one
            // settlement, and a reject-after-settle is ignored regardless.
            $cancellation->onCancel(static function () use ($deferred, $detach, &$settled): void {
                if ($settled) {
                    return;
                }
                $settled = true;
                $detach();
                $deferred->reject(new OperationCancelledException(
                    'Terminal input stream pump cancelled by its CancellationToken.',
                ));
            });
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

    /**
     * Focus events recorded while DECSET 1004 was active (CSI I / CSI O).
     *
     * Each entry is a {@see FocusInMsg} or {@see FocusOutMsg}, oldest first.
     * Consumers wanting live notification instead of draining this list pass
     * an `onFocusEvent` callback to the constructor (findings #30).
     *
     * @return list<FocusInMsg|FocusOutMsg>
     */
    public function focusEvents(): array
    {
        return $this->handler->focusEvents;
    }

    public function resize(int $cols, int $rows): void
    {
        if ($cols < 1 || $rows < 1) {
            throw new \InvalidArgumentException('cols and rows must be >= 1');
        }
        // Resize both screens: the saved alt-state buffer must follow the
        // active one, or leaving DEC 1049 later would restore a stale-size
        // grid into a resized terminal (real terminals resize both; E725).
        $this->handler->resizeBuffers($cols, $rows);
    }

    /**
     * Snapshot clone (w4-vt deep-clone semantics): a fresh Parser starts in
     * Ground, so any in-flight string sequence (partial OSC/DCS payload,
     * partial UTF-8 rune) is intentionally dropped — the clone captures
     * committed state only. ScreenHandler::__clone() deep-copies the mutable
     * Buffer/Scrollback (and the saved alt-state buffer) so the clone can
     * never write through into this terminal's grid.
     * The alt-screen saved slots ride that deep copy (E725).
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

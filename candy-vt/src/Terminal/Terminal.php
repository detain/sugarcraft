<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Terminal;

use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cursor\Cursor;
use SugarCraft\Ansi\Parser\Parser;
use SugarCraft\Vt\Handler\ScreenHandler;
use SugarCraft\Vt\Mode\Mode;
use SugarCraft\Vt\Screen\Screen;
use SugarCraft\Vt\Screen\Scrollback;
use SugarCraft\Vt\Sgr\Sgr;

/**
 * Public terminal facade.
 *
 * Holds a {@see Parser} and a {@see ScreenHandler} that owns the
 * Buffer, Cursor, Sgr pen, and Mode. `feed()` drives bytes through the
 * parser; accessors return the handler's current state.
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
     * Force any in-flight string sequence (OSC/DCS/SOS/PM/APC) to
     * dispatch with its current payload and reset to ground. Useful at
     * end-of-stream when you can't wait for a real terminator byte.
     */
    public function flush(): void
    {
        $this->parser->flush();
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
        }
    }

    public function __clone(): void
    {
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

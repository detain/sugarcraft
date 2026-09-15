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

    public function feed(string $bytes): void
    {
        $this->parser->feed($bytes);
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
        // Resize both screens: the saved alt-state buffer must follow the
        // active one, or leaving DEC 1049 later would restore a stale-size
        // grid into a resized terminal (real terminals resize both; E725).
        $this->handler->resizeBuffers($cols, $rows);
    }

    /**
     * Clone semantics (E725, documented as-is): the {@see ScreenHandler}
     * is shallow-cloned and the {@see Parser} is rebuilt fresh on the clone.
     *
     * Copied per clone: handler object identity (its scalars — tabStops,
     * scrollRegion bounds, windowTitle — are PHP-copied) and all parse
     * machine state, which resets to Ground. Any in-flight sequence
     * (partial UTF-8 rune, unterminated CSI/OSC/DCS string) is dropped,
     * never resumed; `feed()` after a clone starts parsing clean.
     *
     * Shared with the original: the Buffer/Cursor/Sgr/Mode/Scrollback
     * instances themselves — PHP's shallow `clone` copies the handler's
     * property references, not the objects. That is safe for the readonly
     * value objects (every mutation replaces the whole instance) and is
     * deliberate for the in-place-mutated Buffer/Scrollback: the internal
     * `with*()` façade methods re-point only the clone's handler slots, so
     * snapshots isolate state changes, while a raw `clone $terminal` keeps
     * both terminals viewing the same screen — a live window, by design.
     * The alt-screen saved slots (savedBuffer/Cursor/Sgr) ride the clone
     * by reference for the same reason.
     */
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

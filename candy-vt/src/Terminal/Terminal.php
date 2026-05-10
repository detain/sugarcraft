<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Terminal;

use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cursor\Cursor;
use SugarCraft\Vt\Handler\ScreenHandler;
use SugarCraft\Vt\Mode\Mode;
use SugarCraft\Vt\Parser\Parser;
use SugarCraft\Vt\Screen\Screen;
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

    public function __construct(
        int $cols,
        int $rows,
        ?Buffer $buffer = null,
        ?Cursor $cursor = null,
        ?Mode $mode = null,
    ) {
        $this->handler = new ScreenHandler(
            buffer: $buffer ?? new Buffer($cols, $rows),
            cursor: $cursor,
            sgr: Sgr::empty(),
            mode: $mode,
        );
        $this->parser = new Parser($this->handler);
    }

    public static function create(int $cols = 80, int $rows = 24): self
    {
        return new self($cols, $rows);
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
        return Screen::fromBuffer($this->handler->buffer);
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
        $this->handler->buffer = $this->handler->buffer->resize($cols, $rows);
    }

    public function __clone(): void
    {
        $this->handler = clone $this->handler;
        $this->parser = new Parser($this->handler);
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

<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Handler;

use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cell\Cell;
use SugarCraft\Vt\Cursor\Cursor;
use SugarCraft\Vt\Mode\Mode;
use SugarCraft\Vt\Parser\Handler;
use SugarCraft\Vt\Sgr\Sgr;

/**
 * Default {@see Handler} implementation: mutates a Buffer + Cursor +
 * Sgr + Mode triple in response to parser actions.
 *
 * State is held as public mutable references so {@see \SugarCraft\Vt\Terminal\Terminal}
 * (or a test harness) can read it back after `feed()`. Sub-handlers
 * (SgrHandler, CursorHandler) are stateless and receive parser params.
 *
 * PR3 covers SGR + cursor moves + DEC mode 25 (cursor visibility).
 * Erase, scroll, alt-screen, OSC, DCS, and tabs land in later slices.
 */
final class ScreenHandler implements Handler
{
    public Buffer $buffer;
    public Cursor $cursor;
    public Sgr $sgr;
    public Mode $mode;
    public ?string $windowTitle = null;

    private SgrHandler $sgrHandler;
    private CursorHandler $cursorHandler;
    private EraseHandler $eraseHandler;
    private ScrollHandler $scrollHandler;

    public function __construct(
        Buffer $buffer,
        ?Cursor $cursor = null,
        ?Sgr $sgr = null,
        ?Mode $mode = null,
    ) {
        $this->buffer = $buffer;
        $this->cursor = $cursor ?? new Cursor();
        $this->sgr = $sgr ?? Sgr::empty();
        $this->mode = $mode ?? new Mode();
        $this->sgrHandler = new SgrHandler();
        $this->cursorHandler = new CursorHandler();
        $this->eraseHandler = new EraseHandler();
        $this->scrollHandler = new ScrollHandler();
    }

    public function printChar(string $rune): void
    {
        $r = $this->cursor->row;
        $c = $this->cursor->col;
        if ($r >= 0 && $r < $this->buffer->rows && $c >= 0 && $c < $this->buffer->cols) {
            $this->buffer->put($r, $c, new Cell(grapheme: $rune, sgr: $this->sgr));
        }
        // Auto-wrap is part of the scroll/margin work in PR4; for now clamp at the right edge.
        $this->cursor = $this->cursor->withCol(min($this->buffer->cols - 1, $c + 1));
    }

    public function execute(int $byte): void
    {
        match ($byte) {
            0x08 => $this->backspace(),
            0x09 => $this->horizontalTab(),
            0x0A, 0x0B, 0x0C => $this->lineFeed(),
            0x0D => $this->carriageReturn(),
            0x84 => $this->index(),       // IND (C1)
            0x85 => $this->nextLine(),     // NEL (C1)
            0x8D => $this->reverseIndex(), // RI  (C1)
            default => null,
        };
    }

    public function csiDispatch(int $final, array $params, int $prefix, int $intermediate): void
    {
        switch (chr($final)) {
            case 'm':
                $this->sgr = $this->sgrHandler->apply($params, $this->sgr);
                return;
            case 'A': case 'B': case 'C': case 'D': case 'E': case 'F': case 'G':
            case 'H': case 'd': case 'f': case 's': case 'u':
                $this->cursor = $this->cursorHandler->apply($final, $params, $this->cursor, $this->buffer);
                return;
            case 'K': case 'J': case 'X': case 'P': case '@':
                $this->eraseHandler->apply($final, $params, $this->buffer, $this->cursor);
                return;
            case 'S': case 'T':
                $this->scrollHandler->applyCsi($final, $params, $this->buffer);
                return;
            case 'h':
                if ($prefix === ord('?')) {
                    $this->setDecMode($params, true);
                }
                return;
            case 'l':
                if ($prefix === ord('?')) {
                    $this->setDecMode($params, false);
                }
                return;
            default:
                return;
        }
    }

    public function escDispatch(int $final, int $intermediate): void
    {
        match ($final) {
            0x37 /* '7' */ => $this->cursor = $this->cursor->save(),
            0x38 /* '8' */ => $this->cursor = $this->cursor->restore(),
            0x44 /* 'D' */ => $this->index(),
            0x45 /* 'E' */ => $this->nextLine(),
            0x4D /* 'M' */ => $this->reverseIndex(),
            default => null,
        };
    }

    public function oscDispatch(string $data): void
    {
        // OSC handling lands in PR6.
    }

    public function dcsDispatch(int $final, array $params, int $prefix, int $intermediate, string $data): void
    {
        // No-op — DCS dispatch is scoped to later slices.
    }

    public function sosPmApcDispatch(string $kind, string $data): void
    {
        // No-op.
    }

    private function backspace(): void
    {
        $this->cursor = $this->cursor->withCol(max(0, $this->cursor->col - 1));
    }

    private function horizontalTab(): void
    {
        // Default tab stops every 8 columns; configurable in PR7.
        $next = (intdiv($this->cursor->col, 8) + 1) * 8;
        $this->cursor = $this->cursor->withCol(min($this->buffer->cols - 1, $next));
    }

    private function lineFeed(): void
    {
        $this->index();
    }

    private function carriageReturn(): void
    {
        $this->cursor = $this->cursor->withCol(0);
    }

    private function index(): void
    {
        $this->cursor = $this->scrollHandler->index($this->buffer, $this->cursor);
    }

    private function reverseIndex(): void
    {
        $this->cursor = $this->scrollHandler->reverseIndex($this->buffer, $this->cursor);
    }

    private function nextLine(): void
    {
        $this->cursor = $this->scrollHandler->nextLine($this->buffer, $this->cursor);
    }

    /** @param list<int> $params */
    private function setDecMode(array $params, bool $set): void
    {
        foreach ($params as $p) {
            if ($p === 25) {
                $this->mode = $this->mode->withCursorVisible($set);
                $this->cursor = $this->cursor->withVisible($set);
            }
            // Other DEC modes (1049, 2004, 1000/1002/1003/1006, 2026) land in PR5.
        }
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Handler;

use SugarCraft\Core\Util\Width;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cell\Cell;
use SugarCraft\Vt\Color\Color;
use SugarCraft\Vt\Cursor\Cursor;
use SugarCraft\Vt\Hyperlink\Hyperlink;
use SugarCraft\Vt\Mode\Mode;
use SugarCraft\Vt\Parser\Handler;
use SugarCraft\Vt\Sgr\Sgr;

/**
 * Default {@see Handler} implementation: mutates a Buffer + Cursor +
 * Sgr + Mode triple in response to parser actions.
 *
 * State is held as public mutable references so {@see \SugarCraft\Vt\Terminal\Terminal}
 * (or a test harness) can read it back after `feed()`. Sub-handlers
 * (SgrHandler, CursorHandler, EraseHandler, ScrollHandler, ModeHandler)
 * are stateless and receive parser params.
 *
 * Holds optional saved-main state for the alt-screen swap (DEC 1049):
 * when alt mode is entered, the current Buffer/Cursor/Sgr move into the
 * `saved*` fields and a fresh blank Buffer takes the active slot.
 */
final class ScreenHandler implements Handler
{
    public Buffer $buffer;
    public Cursor $cursor;
    public Sgr $sgr;
    public Mode $mode;
    public ?string $windowTitle = null;

    /** @var array<int, Color> Indexed palette overrides set via OSC 4. */
    public array $palette = [];

    /** Active OSC 8 hyperlink — attached to every cell printed while non-null. */
    public ?Hyperlink $currentHyperlink = null;

    /** @var list<array{kind: string, selection: string, payload?: string}> */
    public array $clipboardEvents = [];

    /** @var array<int, bool> Active tab stops, keyed by column. */
    public array $tabStops;

    /** Top row of the DECSTBM scroll region (0-indexed inclusive). */
    public int $scrollRegionTop = 0;

    /** Bottom row of the DECSTBM scroll region (0-indexed inclusive). */
    public int $scrollRegionBottom;

    private ?Buffer $savedBuffer = null;
    private ?Cursor $savedCursor = null;
    private ?Sgr $savedSgr = null;

    private SgrHandler $sgrHandler;
    private CursorHandler $cursorHandler;
    private EraseHandler $eraseHandler;
    private ScrollHandler $scrollHandler;
    private ModeHandler $modeHandler;
    private OscHandler $oscHandler;
    private TabHandler $tabHandler;

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
        $this->modeHandler = new ModeHandler();
        $this->oscHandler = new OscHandler();
        $this->tabHandler = new TabHandler();
        $this->tabStops = TabHandler::defaults($buffer->cols);
        $this->scrollRegionTop = 0;
        $this->scrollRegionBottom = $buffer->rows - 1;
    }

    public function printChar(string $rune): void
    {
        $r = $this->cursor->row;
        $c = $this->cursor->col;

        if ($r < 0 || $r >= $this->buffer->rows) {
            return;
        }

        $width = Width::string($rune);
        if ($width <= 0) {
            // Zero-width: combining marks, ZWJ, etc. We don't compose
            // them onto the previous cell yet — silently skip so they
            // don't desync the column count.
            return;
        }

        // Wide chars need room for the trailing continuation cells. If
        // they don't fit, clamp without writing — auto-wrap lands once
        // DECSTBM margins are wired.
        if ($c + $width > $this->buffer->cols) {
            $this->cursor = $this->cursor->withCol($this->buffer->cols - 1);
            return;
        }

        $cell = new Cell(
            grapheme: $rune,
            sgr: $this->sgr,
            hyperlink: $this->currentHyperlink,
        );
        $this->buffer->put($r, $c, $cell);
        for ($i = 1; $i < $width; $i++) {
            $this->buffer->put($r, $c + $i, Cell::continuation($cell));
        }

        $this->cursor = $this->cursor->withCol(min($this->buffer->cols - 1, $c + $width));
    }

    public function execute(int $byte): void
    {
        match ($byte) {
            0x08 => $this->backspace(),
            0x09 => $this->horizontalTab(),
            0x0A, 0x0B, 0x0C => $this->lineFeed(),
            0x0D => $this->carriageReturn(),
            0x84 => $this->index(),         // IND (C1)
            0x85 => $this->nextLine(),       // NEL (C1)
            0x88 => $this->setTabStop(),     // HTS (C1)
            0x8D => $this->reverseIndex(),   // RI  (C1)
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
                $this->scrollHandler->applyCsi($final, $params, $this->buffer, $this->scrollRegionTop, $this->scrollRegionBottom);
                return;
            case 'r':
                $this->setScrollRegion($params);
                return;
            case 'I': case 'Z':
                $this->cursor = $this->cursor->withCol(
                    $this->tabHandler->applyCsi($final, $params, $this->cursor->col, $this->tabStops, $this->buffer->cols),
                );
                return;
            case 'g':
                $mode = $params[0] ?? -1;
                $this->tabStops = $this->tabHandler->clear($mode === -1 ? 0 : $mode, $this->cursor->col, $this->tabStops);
                return;
            case 'h':
                if ($prefix === ord('?')) {
                    $this->modeHandler->apply($params, true, $this);
                }
                return;
            case 'l':
                if ($prefix === ord('?')) {
                    $this->modeHandler->apply($params, false, $this);
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
            0x48 /* 'H' */ => $this->setTabStop(),
            0x4D /* 'M' */ => $this->reverseIndex(),
            default => null,
        };
    }

    public function oscDispatch(string $data): void
    {
        $this->oscHandler->apply($data, $this);
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
        $this->cursor = $this->cursor->withCol(
            $this->tabHandler->forward($this->cursor->col, $this->tabStops, $this->buffer->cols),
        );
    }

    private function setTabStop(): void
    {
        if ($this->cursor->col >= 0 && $this->cursor->col < $this->buffer->cols) {
            $this->tabStops[$this->cursor->col] = true;
        }
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
        $this->cursor = $this->scrollHandler->index($this->buffer, $this->cursor, $this->scrollRegionTop, $this->scrollRegionBottom);
    }

    private function reverseIndex(): void
    {
        $this->cursor = $this->scrollHandler->reverseIndex($this->buffer, $this->cursor, $this->scrollRegionTop, $this->scrollRegionBottom);
    }

    private function nextLine(): void
    {
        $this->cursor = $this->scrollHandler->nextLine($this->buffer, $this->cursor, $this->scrollRegionTop, $this->scrollRegionBottom);
    }

    /**
     * DECSTBM — set top and bottom margins of the scroll region.
     *
     * Params: [top;bottom] where 1 is the topmost row.
     * Defaults: top=1, bottom=rows.
     * A missing or identical top/bottom resets to the full screen.
     *
     * @param list<int> $params
     */
    private function setScrollRegion(array $params): void
    {
        $top = ($params[0] ?? -1) === -1 ? 1 : (int) $params[0];
        $bottom = ($params[1] ?? -1) === -1 ? $this->buffer->rows : (int) $params[1];

        // Clamp to valid range (VT100 spec: top >= 1, bottom <= rows).
        if ($top < 1) {
            $top = 1;
        }
        if ($bottom > $this->buffer->rows) {
            $bottom = $this->buffer->rows;
        }
        if ($top > $bottom) {
            return; // Invalid; ignore.
        }

        $this->scrollRegionTop = $top - 1;       // Convert to 0-indexed.
        $this->scrollRegionBottom = $bottom - 1;  // Convert to 0-indexed.
    }

    /**
     * Enter the alt screen (DEC 1049 set). Saves the current Buffer +
     * Cursor + Sgr and swaps in a fresh blank Buffer of the same size.
     * Idempotent — re-entering while already in alt mode is a no-op.
     */
    public function enterAltScreen(): void
    {
        if ($this->mode->isAltScreen()) {
            return;
        }
        $this->savedBuffer = $this->buffer;
        $this->savedCursor = $this->cursor;
        $this->savedSgr = $this->sgr;
        $this->buffer = new Buffer($this->buffer->cols, $this->buffer->rows);
        $this->cursor = new Cursor(visible: $this->cursor->visible);
        $this->sgr = Sgr::empty();
        $this->mode = $this->mode->withAltScreenVariant(Mode::ALT_FULL);
    }

    /**
     * Leave the alt screen (DEC 1049 reset). Restores the saved Buffer
     * + Cursor + Sgr. No-op if not currently in alt mode.
     */
    public function leaveAltScreen(): void
    {
        if ($this->mode->altScreenVariant !== Mode::ALT_FULL || $this->savedBuffer === null) {
            return;
        }
        $this->buffer = $this->savedBuffer;
        $this->cursor = $this->savedCursor ?? $this->cursor;
        $this->sgr = $this->savedSgr ?? Sgr::empty();
        $this->savedBuffer = null;
        $this->savedCursor = null;
        $this->savedSgr = null;
        $this->mode = $this->mode->withAltScreenVariant(Mode::ALT_NONE);
    }

    /**
     * Enter the alt screen without saving cursor or SGR (DECSET 47, 1047).
     * Only swaps the buffer — cursor visibility is preserved.
     * Idempotent within the same variant.
     */
    public function enterAltScreenNoSave(): void
    {
        if ($this->mode->altScreenVariant === Mode::ALT_NO_SAVE) {
            return;
        }
        $this->savedBuffer = $this->buffer;
        // Do NOT save cursor or SGR
        $this->buffer = new Buffer($this->buffer->cols, $this->buffer->rows);
        $this->mode = $this->mode->withAltScreenVariant(Mode::ALT_NO_SAVE);
    }

    /**
     * Leave the alt screen (DECSET 47, 1047 reset). Restores the saved Buffer
     * only. Cursor and SGR are NOT restored (they were not saved).
     */
    public function leaveAltScreenNoSave(): void
    {
        if ($this->mode->altScreenVariant !== Mode::ALT_NO_SAVE || $this->savedBuffer === null) {
            return;
        }
        $this->buffer = $this->savedBuffer;
        $this->savedBuffer = null;
        // Do NOT restore cursor or SGR
        $this->mode = $this->mode->withAltScreenVariant(Mode::ALT_NONE);
    }

    /**
     * Enter the alt screen with cursor save only (DECSET 1048).
     * Saves cursor position but NOT buffer or SGR. The buffer is swapped
     * to a fresh blank one and cursor is reset to origin. On exit, only
     * the cursor is restored.
     */
    public function enterAltScreenCursorOnly(): void
    {
        if ($this->mode->altScreenVariant === Mode::ALT_CURSOR_ONLY) {
            return;
        }
        $this->savedCursor = $this->cursor;
        $this->buffer = new Buffer($this->buffer->cols, $this->buffer->rows);
        $this->cursor = new Cursor(visible: $this->cursor->visible);
        $this->mode = $this->mode->withAltScreenVariant(Mode::ALT_CURSOR_ONLY);
    }

    /**
     * Leave the alt screen (DECSET 1048 reset). Restores cursor position only.
     * Buffer and SGR are NOT restored (they were not saved).
     */
    public function leaveAltScreenCursorOnly(): void
    {
        if ($this->mode->altScreenVariant !== Mode::ALT_CURSOR_ONLY || $this->savedCursor === null) {
            return;
        }
        $this->cursor = $this->savedCursor;
        $this->savedCursor = null;
        // Do NOT restore buffer or SGR
        $this->mode = $this->mode->withAltScreenVariant(Mode::ALT_NONE);
    }
}

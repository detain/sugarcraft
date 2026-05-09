<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Handler;

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
    }

    public function printChar(string $rune): void
    {
        $r = $this->cursor->row;
        $c = $this->cursor->col;
        if ($r >= 0 && $r < $this->buffer->rows && $c >= 0 && $c < $this->buffer->cols) {
            $this->buffer->put($r, $c, new Cell(
                grapheme: $rune,
                sgr: $this->sgr,
                hyperlink: $this->currentHyperlink,
            ));
        }
        // Auto-wrap is deferred until DECSTBM margins land; clamp at the right edge.
        $this->cursor = $this->cursor->withCol(min($this->buffer->cols - 1, $c + 1));
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
                $this->scrollHandler->applyCsi($final, $params, $this->buffer);
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

    /**
     * Enter the alt screen (DEC 1049 set). Saves the current Buffer +
     * Cursor + Sgr and swaps in a fresh blank Buffer of the same size.
     * Idempotent — re-entering while already in alt mode is a no-op.
     */
    public function enterAltScreen(): void
    {
        if ($this->mode->altScreen) {
            return;
        }
        $this->savedBuffer = $this->buffer;
        $this->savedCursor = $this->cursor;
        $this->savedSgr = $this->sgr;
        $this->buffer = new Buffer($this->buffer->cols, $this->buffer->rows);
        $this->cursor = new Cursor(visible: $this->cursor->visible);
        $this->sgr = Sgr::empty();
        $this->mode = $this->mode->withAltScreen(true);
    }

    /**
     * Leave the alt screen (DEC 1049 reset). Restores the saved Buffer
     * + Cursor + Sgr. No-op if not currently in alt mode.
     */
    public function leaveAltScreen(): void
    {
        if (!$this->mode->altScreen || $this->savedBuffer === null) {
            return;
        }
        $this->buffer = $this->savedBuffer;
        $this->cursor = $this->savedCursor ?? $this->cursor;
        $this->sgr = $this->savedSgr ?? Sgr::empty();
        $this->savedBuffer = null;
        $this->savedCursor = null;
        $this->savedSgr = null;
        $this->mode = $this->mode->withAltScreen(false);
    }
}

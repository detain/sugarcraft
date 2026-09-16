<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Handler;

use Closure;
use SugarCraft\Core\Util\Width;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cell\Cell;
use SugarCraft\Vt\Charset\Charsets;
use SugarCraft\Vt\Color\Color;
use SugarCraft\Vt\Cursor\Cursor;
use SugarCraft\Vt\Hyperlink\Hyperlink;
use SugarCraft\Vt\Msg\FocusInMsg;
use SugarCraft\Vt\Msg\FocusOutMsg;
use SugarCraft\Vt\Mode\Mode;
use SugarCraft\Ansi\Parser\Handler;
use SugarCraft\Vt\Screen\Scrollback;
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
    public Scrollback $scrollback;

    /** @var array<int, Color> Indexed palette overrides set via OSC 4. */
    public array $palette = [];

    /** Active OSC 8 hyperlink — attached to every cell printed while non-null. */
    public ?Hyperlink $currentHyperlink = null;

    /** @var list<array{kind: string, selection: string, payload?: string}> */
    public array $clipboardEvents = [];

    /** @var array<int, bool> Active tab stops, keyed by column. */
    public array $tabStops;

    /** Focus events recorded when DECSET 1004 is active (CSI I / CSI O). */
    public array $focusEvents = [];

    /**
     * DECAWM wrap-through ("phantom") flag — a glyph has landed in the
     * last column and the advance to (row+1, 0) is deferred until the
     * next graphic print consumes it.
     *
     * Lives on the handler, not the Cursor, mirroring
     * `charmbracelet/x/vt Emulator.atPhantom` (emulator.go L72-74).
     * Cleared by CR, LF/IND, BS, every CSI cursor movement, DECRC, ECH,
     * IL/DL, RIS/DECSTR and resize; DELIBERATELY preserved by RI/back-index,
     * tabs (HT/CHT/CBT), ED/EL and query reports — exactly the xterm
     * roster (see vt/cc.go "This does not reset the phantom state").
     * The flag itself is set by GEOMETRY after any print, not gated on
     * the mode, so DECAWM can be re-enabled mid-wrap and the deferred
     * advance resumes (xterm overwrite-then-wrap DECAWM semantics).
     *
     * IL/DL homing deliberately DROPS the phantom (charmbracelet's
     * setCursorX(0,true) keeps it, but a phantom armed at the old right
     * margin is meaningless at column 0 and keeping it shifts whole
     * lines — see InsertDeleteLinesTest).
     *
     * @see https://vt100.net/docs/vt510-rm/chapter4.html (DECAWM)
     * @see xterm ctlseqs "DECAWM" wraparound paragraph
     */
    public bool $wrapPending = false;

    /**
     * Late-bound ECMA-48 colon-continuation flags for the CSI sequence
     * currently being dispatched — wired by {@see
     * \SugarCraft\Vt\Terminal\Terminal::new()} to the owning parser's
     * {@see \SugarCraft\Ansi\Parser\Parser::subparams()}. SGR needs them to
     * tell `CSI 4 : 3 m` (curly underline) from `CSI 4 ; 3 m` (underline +
     * italic), because candy-ansi flattens both to [4, 3]. Unattached —
     * direct construction — SGR keeps its historical next-slot peek.
     *
     * @var (Closure(): list<bool>)|null
     */
    private ?Closure $subparamsProvider = null;

    /**
     * Wire the parser's sub-parameter continuation flags for SGR colon
     * handling. Called once at terminal construction; see
     * {@see $subparamsProvider}.
     *
     * @param Closure(): list<bool> $provider
     */
    public function attachSubparamsProvider(Closure $provider): void
    {
        $this->subparamsProvider = $provider;
    }

    /**
     * Continuation flags for the in-flight dispatch, or null when no parser
     * is attached. Safe to call only from inside a dispatch — the parser has
     * not cleared its flags yet at that point.
     *
     * @return list<bool>|null
     */
    private function currentSubparams(): ?array
    {
        $provider = $this->subparamsProvider;
        return $provider === null ? null : $provider();
    }

    /**
     * Query→reply channel: answer strings produced by DA1/DA2/DSR-CPR/
     * DECRQM/XTWINOPS requests, in request order. The {@see
     * \SugarCraft\Vt\Terminal\Terminal} facade either forwards each
     * reply to a `feed()` callback or drains them via `replies()`.
     *
     * @var list<string>
     */
    public array $replies = [];

    /**
     * Backlog cap for {@see $replies}: hostile streams (candy-pty renders
     * untrusted program output) can spam queries nobody drains, and a
     * pull queue has no io.Pipe-style write blocking. Drop-oldest ring.
     */
    private const MAX_REPLIES = 1024;

    /**
     * SCS designation state: G0..G3 charset bytes ('B', '0', 'U', …).
     *
     * @var array{0: string, 1: string, 2: string, 3: string}
     *
     * @see ECMA-48 §25 designation; xterm ctlseqs SCS (ESC ( ) * +)
     */
    public array $charsets = [Charsets::ASCII, Charsets::ASCII, Charsets::ASCII, Charsets::ASCII];

    /** Which designations occupy GL: 0 = G0 (SI/LS0), 1 = G1 (SO/LS1). */
    public int $gl = 0;

    /** Pending SS2/SS3 single shift (2, 3) or null — applies to one graphic only. */
    public ?int $singleShift = null;

    /** Cell width in pixels reported by XTWINOPS 16t/14t (emulator has no font). */
    public int $cellWidthPx = 8;

    /** Cell height in pixels reported by XTWINOPS 16t/14t (emulator has no font). */
    public int $cellHeightPx = 16;

    /** Top row of the DECSTBM scroll region (0-indexed inclusive). */
    public int $scrollRegionTop = 0;

    /** Bottom row of the DECSTBM scroll region (0-indexed inclusive). */
    public int $scrollRegionBottom;

    private ?Buffer $savedBuffer = null;
    private ?Cursor $savedCursor = null;
    private ?Sgr $savedSgr = null;

    /** Per-screen phantom flag carried across alt-screen swaps (xterm keeps _wrapnext per screen). */
    private ?bool $savedWrapPending = null;

    /**
     * AUX alt-screen slot companions (DEC 1049): the SCS designations, GL
     * invocation and DECOM state riding alongside the saved cursor/pen, so
     * leaving the alt screen restores the FULL rendition context the VT500
     * DECSC-style save implies, not just position + colour.
     *
     * @var array{0: string, 1: string, 2: string, 3: string}|null
     */
    private ?array $savedCharsets = null;
    private ?int $savedGl = null;
    private ?bool $savedOriginMode = null;

    /**
     * GENERAL save slot (VT500 §DECSC/§DECRC): the ESC 7/ESC 8 and CSI s/CSI u
     * pair — ONE slot, deliberately shared, exactly as xterm merges DECSC and
     * the SCO extended-cursor save. The position half lives on the Cursor
     * itself (`savedRow`/`savedCol` via {@see Cursor::save()}); these fields
     * complete the VT500 snapshot with the active SGR rendition, the GL/GR
     * charset designations and the origin mode. `null` in {@see $generalSavedSgr}
     * means "never saved" — DECRC then degrades to the historical position-only
     * no-op instead of conjuring defaults.
     *
     * Independent of the AUX alt-screen slot above: the 1049 swap never
     * restores or consumes these as ITS state — it only parks/unparks them
     * alongside the Cursor's position half so each screen owns its own
     * DECSC slot, exactly as xterm's per-screen `saved_cursor` does.
     */
    private ?Sgr $generalSavedSgr = null;
    /** @var array{0: string, 1: string, 2: string, 3: string}|null */
    private ?array $generalSavedCharsets = null;
    private ?int $generalSavedGl = null;
    private ?bool $generalSavedOriginMode = null;

    /**
     * The GENERAL slot's rendition companions parked while an alt screen is
     * active. The DECSC save is PER SCREEN (xterm indexes its saved cursor
     * by screen buffer): entering the alt screen hands DECRC a slot
     * belonging to that screen — the fresh Cursor's, position half starting
     * empty — not the main screen's. These fields mirror that by stashing
     * the main screen's companions for the duration; the position half
     * already lives on the Cursor objects being swapped. Unlike xterm (whose
     * alt-slot save lingers after exit), the in-alt save is discarded when
     * the alt screen dies — same hygiene as the alt buffer itself.
     *
     * @var null|array{0: ?Sgr, 1: ?array, 2: ?int, 3: ?bool}
     */
    private ?array $parkedGeneral = null;

    /**
     * Synchronized-output (DEC 2026) mutation queue.
     * When $mode->syncUpdate is true, all buffer mutations are held here
     * and flushed atomically when the mode is disabled.
     *
     * @var list<array{row: int, col: int, cell: Cell}>
     */
    private array $pendingMutations = [];

    private SgrHandler $sgrHandler;
    private CursorHandler $cursorHandler;
    private EraseHandler $eraseHandler;
    private ScrollHandler $scrollHandler;
    private ModeHandler $modeHandler;
    private OscHandler $oscHandler;
    private TabHandler $tabHandler;

    /**
     * @param ?Cursor $cursor Injected cursor state, or a fresh homed one.
     * @param ?Mode $mode Injected mode state, or a fresh power-on one.
     *
     * `Cursor::$shape` and `Mode::$cursorShape` are a PAIR — the DECSCUSR
     * handler writes both from one escape (see {@see self::csiDispatch()},
     * case 'q') and every rebuild in this class keeps them equal. Because
     * the two are independent optional parameters, nothing about their types
     * enforces that, so a caller could inject `cursor: new Cursor(shape: 5)`
     * with no `$mode` and start life diverged (5 vs 0). The half that was NOT
     * injected therefore follows the half that was, so the pair is consistent
     * from construction onward. When BOTH are supplied they are taken
     * verbatim — an explicit pair is the caller's own statement of intent,
     * and the next `CSI Ps SP q` resyncs it anyway. The invariant this
     * establishes is upheld by every path the handler itself takes; it is not
     * enforced against an outside writer, because `$cursor` and `$mode` are
     * public properties and {@see \SugarCraft\Vt\Terminal\Terminal::withCursor()}
     * / {@see \SugarCraft\Vt\Terminal\Terminal::withMode()} assign one half
     * without reconciling the other (both `@internal`, no in-tree caller).
     */
    public function __construct(
        Buffer $buffer,
        ?Cursor $cursor = null,
        ?Sgr $sgr = null,
        ?Mode $mode = null,
        ?Scrollback $scrollback = null,
    ) {
        $this->buffer = $buffer;
        $this->cursor = $cursor ?? new Cursor();
        $this->sgr = $sgr ?? Sgr::empty();
        $this->mode = $mode ?? new Mode();
        if ($cursor !== null && $mode === null) {
            $this->mode = $this->mode->withCursorShape($cursor->shape);
        } elseif ($mode !== null && $cursor === null) {
            $this->cursor = $this->cursor->withShape($mode->cursorShape);
        }
        $this->scrollback = $scrollback ?? new Scrollback();
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
        $rune = $this->translateRune($rune);

        $r = $this->cursor->row;
        $c = $this->cursor->col;

        if ($r < 0 || $r >= $this->buffer->rows) {
            return;
        }

        $width = Width::string($rune);
        if ($width <= 0) {
            // Zero-width: combining marks, ZWJ, ZWNJ, variation selectors, etc.
            // Width::string() correctly classifies all zero-width Unicode marks,
            // including U+0300-U+036F, U+1DC0-U+1DFF, U+20D0-U+20FF, U+FE00-FE0F,
            // U+FE20-U+FE2F, and zero-width joiners.
            $this->attachCombiningChar($rune);
            return;
        }

        // DECAWM wrap-through: a graphic landed in the last column deferred
        // its advance; the NEXT graphic consumes it here — move to (row+1, 0)
        // (scrolling at the region bottom) before writing. Mirrors xterm:
        // the wrap decision is made when the next character arrives, so a
        // cursor-position query, erase, or movement in between sees the
        // cursor still in the last column.
        if ($this->wrapPending && $this->mode->autoWrap) {
            $this->cursor = $this->lineFeedNext();
            $r = $this->cursor->row;
            $c = $this->cursor->col;
        }

        // If the char doesn't fit at all (too wide even at col 0), clamp.
        if ($c + $width > $this->buffer->cols) {
            if ($this->mode->autoWrap) {
                $this->cursor = $this->lineFeedNext();
                $r = $this->cursor->row;
                $c = $this->cursor->col;
                // If still doesn't fit after wrap, clamp.
                if ($c + $width > $this->buffer->cols) {
                    $this->cursor = $this->cursor->withCol($this->buffer->cols - 1);
                    $this->wrapPending = false;
                    return;
                }
            } else {
                // No wrap available: the wide glyph is discarded (nothing
                // is written, cursor parks on the last column). The phantom
                // flag is untouched — xterm's cursor_off recomputes it only
                // when a cell is actually painted.
                $this->cursor = $this->cursor->withCol($this->buffer->cols - 1);
                return;
            }
        }

        $cell = new Cell(
            grapheme: $rune,
            sgr: $this->sgr,
            hyperlink: $this->currentHyperlink,
        );
        $this->putCell($r, $c, $cell);
        for ($i = 1; $i < $width; $i++) {
            $this->putCell($r, $c + $i, Cell::continuation($cell));
        }

        // Deferred wrap (xterm `cursor_off` semantics): the cursor parks on
        // the last column with the wrap flag set instead of advancing a full
        // line; the advance happens on the next graphic print. The flag is
        // set by GEOMETRY alone so a DECAWM toggle mid-wrap behaves like
        // xterm — printing with ?7l overwrites the last cell (the flag
        // re-arms), and re-enabling ?7h resumes the deferred wrap.
        $nextCol = $c + $width;
        $this->cursor = $this->cursor->withCol(min($this->buffer->cols - 1, $nextCol));
        $this->wrapPending = $nextCol >= $this->buffer->cols;
    }

    public function execute(int $byte): void
    {
        match ($byte) {
            0x08 => $this->backspace(),
            0x09 => $this->horizontalTab(),
            // SO (LS1) / SI (LS0): shift G1 / G0 into GL. ECMA-48 NISO/LS0;
            // xterm treats them as G1→GL / G0→GL (SI=0x0F selects G0).
            0x0E => $this->gl = 1,
            0x0F => $this->gl = 0,
            0x0A, 0x0B, 0x0C => $this->lineFeed(),
            0x0D => $this->carriageReturn(),
            0x84 => $this->index(),         // IND (C1)
            0x85 => $this->nextLine(),       // NEL (C1)
            0x88 => $this->setTabStop(),     // HTS (C1)
            0x8D => $this->reverseIndex(),   // RI  (C1)
            0x8E => $this->singleShift = 2,  // SS2 — G2 for the next graphic
            0x8F => $this->singleShift = 3,  // SS3 — G3 for the next graphic
            default => null,
        };
    }

    public function csiDispatch(int $final, array $params, int $prefix, int $intermediate): void
    {
        $finalChar = chr($final);

        // Handle focus events first (CSI I / CSI O) when DECSET 1004 is active.
        if ($finalChar === 'I' && $this->mode->reportFocusEvents) {
            $this->focusEvents[] = new FocusInMsg();
            return;
        }
        if ($finalChar === 'O' && $this->mode->reportFocusEvents) {
            $this->focusEvents[] = new FocusOutMsg();
            return;
        }

        switch ($finalChar) {
            case 'm':
                $this->sgr = $this->sgrHandler->apply($params, $this->sgr, $this->currentSubparams());
                return;
            case 'A': case 'B': case 'C': case 'D': case 'E': case 'F': case 'G':
            case 'H': case 'd': case 'f':
                $this->cursor = $this->cursorHandler->apply(
                    $final,
                    $params,
                    $this->cursor,
                    $this->buffer,
                    $this->scrollRegionTop,
                    $this->scrollRegionBottom,
                    $this->mode->originMode,
                );
                // A graphic-position change disarms the deferred wrap
                // (xterm reset_wrapnext).
                $this->wrapPending = false;
                return;
            case 's':
            case 'u':
                // Private-marker forms are NOT the SCO aliases. xterm switches
                // the whole final-byte table on a `?` marker (VTPrsTbl.c:558
                // `?` = CASE_DEC_STATE → charproc.c:3898-3900 parsestate =
                // dec_table), where 's' saves the DEC private MODE settings
                // (VTPrsTbl.c:4195 CASE_XTERM_SAVE → charproc.c:6209-6210 →
                // savemodes(), "process xterm private modes save",
                // charproc.c:8049-8053) — a feature this emulator does not
                // model, a deliberate gap — and 'u' is ignored outright
                // (VTPrsTbl.c:4198 CASE_GROUND_STATE). tmux agrees by
                // table miss: its CSI entries carry ('s', "") and ('u', "")
                // only (input.c:345,347), marker bytes 0x3c-0x3f collect into
                // the key's interm_buf (input.c:577), and a miss returns
                // before the switch (input.c:1483-1487). So `CSI ? u` — the
                // kitty keyboard-capability QUERY — must be inert here: no
                // cursor move, no GENERAL-slot touch. (We still do not answer
                // it: no kitty reply channel, by design.) Intermediate bytes
                // are deliberately NOT gated on this pair — `CSI SP s`/`CSI !
                // s` still save here, inert in both refs (xterm csi_sp_table
                // 's' = GROUND, VTPrsTbl.c:1920; csi_ex_table VTPrsTbl.c:1271;
                // tmux strcmp miss, input.c:771-779): the marked finals are
                // the query hazard this gate answers.
                if ($prefix !== 0) {
                    return;
                }
                // CSI s / CSI u — SCO save/restore-cursor aliases of DECSC/
                // DECRC: same GENERAL slot as ESC 7/8, and the phantom cell
                // survives the snapshot (it is DECRC that clears on the way
                // back). `CSI Ps s` / `CSI Ps u` (params, NO SP intermediate)
                // stay save/restore: xterm routes them to the very same
                // ANSI_SC/ANSI_RC cases (VTPrsTbl.c:623,626) — it merely
                // no-ops the non-default param spelling via only_default()
                // (charproc.c:4899,4912-4913 + 2219-2223; xterm's LR-margin
                // DECSLRM branch at charproc.c:4885-4898 is not modelled —
                // candy-vt has no DECLRMM) — while tmux ignores the param
                // (input.c:1820-1831 → input_save_state/input_restore_state,
                // input.c:849-871). Never a cursor shape: DECSCUSR needs the
                // SP intermediate + 'q' (xterm csi_sp_table, VTPrsTbl.c:1917-
                // 1918; tmux input.c:342 `{ 'q', " ", INPUT_CSI_DECSCUSR }`),
                // handled by the `case 'q'` arm below — following tmux here
                // keeps the request in save/restore, silently swallowed.
                if ($finalChar === 's') {
                    $this->saveCursor();
                } else {
                    $this->restoreCursor();
                }
                return;
            case 'K': case 'J': case 'X': case 'P': case '@':
                if ($finalChar === 'J' && ($params[0] ?? -1) === 3 && $intermediate === 0
                    && ($prefix === 0 || $prefix === 0x3F /* '?' — xterm routes ED by Ps, private marker immaterial */)) {
                    // ED 3 — "clear scrollback": the ring drains, the
                    // visible screen and cursor are untouched. Extra params
                    // (`CSI 3 ; Ps J`) are ignored — xterm has no defined
                    // second param for ED 3. Xterm-side nuance NOT modelled:
                    // xterm gates this branch on its `eraseSavedLines`
                    // resource (default true; when false the sequence is a
                    // total no-op) — this emulator has no scrollback-retention
                    // setting, so the ring alone is the whole of the
                    // scrollback feature and the clear always applies.
                    // (candy-vcr VtParityTest: renderer has no ring at all.)
                    // The drain is immediate even inside a DEC 2026 window —
                    // the ring is history, not screen state, and has no
                    // queue slot.
                    $this->scrollback->clear();
                    return;
                }
                // Synchronized output (DEC 2026): erases must join the REAL
                // pending queue BY REFERENCE. Passing the array by value —
                // the historical bug — made EraseHandler append into a
                // throw-away copy, so every ED/EL/ECH/DCH/ICH issued inside
                // a `CSI ?2026h … CSI ?2026l` window silently vanished on
                // flush. Queue-ordering is preserved relative to queued
                // putCell/attachCombiningChar mutations.
                if ($this->mode->syncUpdate) {
                    $this->eraseHandler->apply($final, $params, $this->buffer, $this->cursor, $this->sgr, $this->pendingMutations);
                } else {
                    $this->eraseHandler->apply($final, $params, $this->buffer, $this->cursor, $this->sgr);
                }
                // ECH erases from the phantom cell forward → disarm; EL/ED
                // never touch the wrap flag (xterm leaves _wrapnext alone).
                if ($finalChar === 'X') {
                    $this->wrapPending = false;
                }
                return;
            case 'S': case 'T':
                $first = $params[0] ?? -1;
                $count = $first === -1 ? 1 : max(1, $first);
                if ($finalChar === 'S') {
                    $this->scrollUp($count);
                } else {
                    $this->scrollDown($count);
                }
                return;
            case 'L':
                // IL — insert lines (ECMA-48 8.4.15). Only inside DECSTBM;
                // no scrollback push (xterm: region-internal shift).
                if ($prefix === 0 && $intermediate === 0) {
                    $this->insertLines($params);
                }
                return;
            case 'M':
                // DL — delete lines (ECMA-48 8.4.10).
                if ($prefix === 0 && $intermediate === 0) {
                    $this->deleteLines($params);
                }
                return;
            case 'r':
                $this->setScrollRegion($params);
                return;
            case 'c':
                // DA — Device Attributes. CSI c = DA1, CSI > c = DA2.
                $this->deviceAttributes($prefix, $params);
                return;
            case 'n':
                // DSR — Device Status Report: 5 = terminal OK, 6 = CPR.
                // DECXCPR (CSI ? 6 n) is not modelled — silent, like xterm
                // built without the decCPRRequests resource.
                if ($prefix === 0) {
                    $this->deviceStatus($params);
                }
                return;
            case 't':
                // XTWINOPS window manipulation queries (14t/16t/18t answered).
                if ($prefix === 0) {
                    $this->windowOps($params);
                }
                return;
            case 'p':
                if ($intermediate === ord('!') && $prefix === 0) {
                    $this->softReset();          // DECSTR — CSI ! p
                } elseif ($intermediate === ord('$')) {
                    $this->requestMode($params, $prefix); // DECRQM — CSI Ps $ p
                }
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
            case 'q':
                // DECSCUSR — cursor shape (CSI Ps SP q). Ps is stored verbatim:
                // a non-conformant value (e.g. `CSI 9 SP q`) is kept as given
                // rather than ignored the way xterm's switch drops it. Both
                // fields still receive the same number, so the pair invariant
                // survives; only the 0-6 vocabulary in CursorShape is unenforced.
                // intermediate 0x20 = space (SP), final = 'q' (0x71).
                if ($intermediate === ord(' ') && $prefix === 0) {
                    $shape = $params[0] ?? 0;
                    $this->cursor = $this->cursor->withShape($shape);
                    $this->mode = $this->mode->withCursorShape($shape);
                }
                return;
            case 'h':
                if ($prefix === ord('?')) {
                    $wasSync = $this->mode->syncUpdate;
                    $this->modeHandler->apply($params, true, $this);
                    // DEC 2026 sync-update: flush pending mutations when
                    // exiting synchronized mode (was on, now off).
                    if ($wasSync && !$this->mode->syncUpdate) {
                        $this->flushPendingMutations();
                    }
                }
                return;
            case 'l':
                if ($prefix === ord('?')) {
                    $wasSync = $this->mode->syncUpdate;
                    $this->modeHandler->apply($params, false, $this);
                    // DEC 2026 sync-update: flush pending mutations when
                    // exiting synchronized mode (was on, now off).
                    if ($wasSync && !$this->mode->syncUpdate) {
                        $this->flushPendingMutations();
                    }
                }
                return;
            default:
                return;
        }
    }

    public function escDispatch(int $final, int $intermediate): void
    {
        // ESC # <final> — DEC's private "hash" family, decoded BEFORE the
        // SCS designation fallback: '#' (0x23) is NOT a charset designator,
        // so letting it reach designate() would silently swallow DECALN.
        // Authoritative encoding is `ESC # 8` — VT510 ch.4 ("ESC # 8 invokes
        // the Screen Alignment test"), DEC ansicode.txt "#8 DECALN", and the
        // vttest vector decaln(){ esc("#8"); }. NOTE FOR FUTURE READERS: the
        // `ESC [ # 8` spelling that circulates in some references is a
        // misquote — after `ESC [`, '#' enters xterm's separate palette-stack
        // substate (csi_hash_table[] = the XT{PUSH,POP,REPORT}-COLORS family
        // `CSI # P/Q/R/S`) where '8' is a collected digit, not a final; no
        // surveyed emulator dispatches DECALN from a CSI form and the
        // candy-ansi transition table we mirror agrees. Do not "fix" the
        // parser for it. Unimplemented # finals (DECDHL ESC # 3/# 4,
        // DECSWL/DECDWL ESC # 5/# 6 — follow-up work) fall through to
        // designate(), preserving their historical silent-ignore exactly.
        // (The candy-ansi parser keeps a SINGLE intermediate byte, last-wins,
        // so the malformed double-intermediate stream `ESC ( # 8` arrives as
        // ('8', '#') and now runs DECALN where it used to be ignored — an
        // accepted consequence of mirroring the upstream table; xterm ignores
        // it, garbage streams are the only way to send it.)
        if ($intermediate === 0x23 /* '#' */) {
            match ($final) {
                0x38 /* '8' */ => $this->displayAlignmentTest(),
                default => $this->designate($intermediate, $final),
            };
            return;
        }

        if ($intermediate !== 0) {
            $this->designate($intermediate, $final);
            return;
        }

        match ($final) {
            0x37 /* '7' */ => $this->saveCursor(),
            0x38 /* '8' */ => $this->restoreCursor(),
            0x44 /* 'D' */ => $this->index(),
            0x45 /* 'E' */ => $this->nextLine(),
            0x48 /* 'H' */ => $this->setTabStop(),
            0x4D /* 'M' */ => $this->reverseIndex(),
            0x63 /* 'c' */ => $this->hardReset(), // RIS — full reset to power-on
            // LS2 / LS3 locking shifts into GL (ECMA-48, mirrors
            // charmbracelet x/vt handlers.go RegisterEscHandler 'n'/'o').
            0x6E /* 'n' */ => $this->gl = 2,
            0x6F /* 'o' */ => $this->gl = 3,
            // ESC F = S7C1T (ECMA-48): switch the C1 transmission form.
            // This emulator receives BOTH (the parser dispatches
            // 0x84/0x85/0x8D C1 bytes and their ESC-7bit equivalents
            // alike), so acceptance is a documented no-op.
            0x46 /* 'F' */ => null,
            // ESC G = the legacy SCO idiom "DEC Special Graphics into
            // GL" (deliverable 4): designate '0' on G0 and hang it in GL.
            0x47 /* 'G' */ => $this->decSpecialGl(),
            default => null,
        };
    }

    /** ESC G — DEC Special Graphics designated straight into GL (SCO hardware idiom). */
    private function decSpecialGl(): void
    {
        $this->designate(0x28 /* '(' */, ord('0'));
        $this->gl = 0;
    }

    /**
     * DECSC — ESC 7 / CSI s: snapshot the GENERAL save slot.
     *
     * VT500 §DECSC saves cursor position, active SGR rendition, the
     * G0..G3 charset designations (SCS state) and DECOM. The position
     * half rides on the Cursor value object (`savedRow`/`savedCol`), so
     * the slot survives DECSTR/DECALN exactly as before; the remaining
     * three halves land in the `generalSaved*` fields. The AUX alt-screen
     * slot is untouched — the two saves are independent.
     */
    private function saveCursor(): void
    {
        $this->cursor = $this->cursor->save();
        $this->generalSavedSgr = $this->sgr;
        $this->generalSavedCharsets = $this->charsets;
        $this->generalSavedGl = $this->gl;
        $this->generalSavedOriginMode = $this->mode->originMode;
    }

    /**
     * Stash the GENERAL slot's rendition companions at an alt-screen entry
     * and start the alt screen with an EMPTY slot — matching the fresh
     * Cursor the swap installs on the position half (xterm's saved cursor is
     * per-screen). Counterpart of {@see unparkGeneralCompanions()}.
     */
    private function parkGeneralCompanions(): void
    {
        $this->parkedGeneral = [
            $this->generalSavedSgr,
            $this->generalSavedCharsets,
            $this->generalSavedGl,
            $this->generalSavedOriginMode,
        ];
        $this->generalSavedSgr = null;
        $this->generalSavedCharsets = null;
        $this->generalSavedGl = null;
        $this->generalSavedOriginMode = null;
    }

    /** Hand the main screen's parked GENERAL companions back at alt-screen exit. */
    private function unparkGeneralCompanions(): void
    {
        if ($this->parkedGeneral === null) {
            return;
        }
        [$this->generalSavedSgr, $this->generalSavedCharsets, $this->generalSavedGl, $this->generalSavedOriginMode] = $this->parkedGeneral;
        $this->parkedGeneral = null;
    }

    /**
     * DECRC — ESC 8 / CSI u: restore the GENERAL save slot.
     *
     * A position change, so the phantom cell is dropped (VT500 also
     * restores the DECARM wrap flag with it; this port keeps its
     * established clear-on-move geometry). With no save on record the
     * restore degrades to the historical position-only no-op — the
     * pen, designations and origin mode stay as they are.
     */
    private function restoreCursor(): void
    {
        $this->cursor = $this->cursor->restore();
        $this->wrapPending = false;
        if ($this->generalSavedSgr === null) {
            return;
        }
        $this->sgr = $this->generalSavedSgr;
        /** @var array{0: string, 1: string, 2: string, 3: string} $sets */
        $sets = $this->generalSavedCharsets;
        $this->charsets = $sets;
        $this->gl = $this->generalSavedGl ?? 0;
        $this->mode = $this->mode->withOriginMode($this->generalSavedOriginMode ?? false);
    }

    /**
     * SCS — designate a charset into G0..G3 (ECMA-48 §25, xterm ctlseqs
     * "Single character selections and invocation of the character sets").
     *
     * ESC ( c → G0, ESC ) c → G1, ESC * c → G2, ESC + c → G3. Recognised
     * designation finals: 'B' US ASCII, '0' DEC Special Graphics,
     * 'A' United Kingdom, 'U' ISO Latin-1 (no-break space); anything else
     * leaves the previous designation untouched, as upstream's
     * handler-returns-false fallthrough does.
     *
     * Mirrors charmbracelet/x/vt handlers.go RegisterEscHandler SCS block.
     */
    private function designate(int $intermediate, int $final): void
    {
        $index = match ($intermediate) {
            0x28 /* '(' */ => 0,
            0x29 /* ')' */ => 1,
            0x2A /* '*' */ => 2,
            0x2B /* '+' */ => 3,
            default => null, // ESC #, SP, etc. — not designations; ignore.
        };
        if ($index === null) {
            return;
        }
        $charset = match ($final) {
            ord('A'), ord('B'), ord('U'), ord('0') => chr($final),
            default => null, // Unknown 94/96-set finals keep the old set.
        };
        if ($charset === null) {
            return;
        }
        /** @var array{0: string, 1: string, 2: string, 3: string} $sets */
        $sets = $this->charsets;
        $sets[$index] = $charset;
        $this->charsets = $sets;
    }

    public function oscDispatch(string $data): void
    {
        $this->oscHandler->apply($data, $this);
    }

    public function dcsDispatch(int $final, array $params, int $prefix, int $intermediate, string $data): void
    {
        // No-op — DCS dispatch is scoped to later slices; the parser
        // params are part of the Handler contract, reserved for that
        // future DCS handling.
    }

    public function sosPmApcDispatch(string $kind, string $data): void
    {
        // No-op — SOS/PM/APC strings are consumed and discarded until
        // there is a consumer for them.
    }

    private function backspace(): void
    {
        // BS moves the cursor back out of the phantom cell (charmbracelet
        // x/vt moveCursor resets it; xterm do_backspaces → reset_wrapnext).
        $this->wrapPending = false;
        $this->cursor = $this->cursor->withCol(max(0, $this->cursor->col - 1));
    }

    private function horizontalTab(): void
    {
        // Tabs deliberately do NOT reset the deferred-wrap flag — the
        // graphic still owes the line advance. Matches charmbracelet x/vt
        // nextTab ("we use t.scr.setCursor here because we don't want to
        // reset the phantom state") and xterm's _wrapnext survival over HT.
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
        $this->wrapPending = false;
        $this->cursor = $this->cursor->withCol(0);
    }

    private function index(): void
    {
        // IND/LF consumes vertical motion — the phantom cell is gone
        // (charmbracelet x/vt index() "always clears"; xterm too).
        $this->wrapPending = false;
        if ($this->cursor->row >= $this->scrollRegionBottom) {
            $this->scrollUp(1);
        } else {
            $this->cursor = $this->cursor->withRow($this->cursor->row + 1);
        }
    }

    private function reverseIndex(): void
    {
        // RI / back-index: vertical move only — xterm's reverse_index and
        // charmbracelet x/vt both preserve the phantom state here.
        if ($this->cursor->row <= $this->scrollRegionTop) {
            $this->scrollDown(1);
        } else {
            $this->cursor = $this->cursor->withRow($this->cursor->row - 1);
        }
    }

    private function nextLine(): void
    {
        $this->cursor = $this->cursor->withCol(0);
        $this->index();
    }

    /**
     * Move cursor to column 0 of the next line, scrolling if at the
     * bottom of the scroll region. Returns the new cursor.
     */
    private function lineFeedNext(): Cursor
    {
        $this->wrapPending = false;
        $this->cursor = $this->cursor->withCol(0);
        if ($this->cursor->row >= $this->scrollRegionBottom) {
            $this->scrollUp(1);
            return $this->cursor;
        }
        $this->cursor = $this->cursor->withRow($this->cursor->row + 1);
        return $this->cursor;
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

        // VT510 §DECSTBM: "moves the cursor to column 1, line 1 of the
        // page" — the margins set homes the cursor; xterm's CursorSet honours
        // a live DECOM, so under origin mode the home lands on the region
        // top instead (DECOM survives DECSTBM in both — it resets only on
        // RIS/DECSTR). An invalid region took the early return above and
        // homes nothing.
        $this->cursor = $this->cursor
            ->withRow($this->mode->originMode ? $this->scrollRegionTop : 0)
            ->withCol(0);
        // Column-home + row move: the phantom flag cannot survive it.
        $this->wrapPending = false;
    }

    // ─── IL / DL (ECMA-48 insert/delete lines) ──────────────────────────────

    /**
     * IL — CSI Ps L: insert Ps blank lines at the cursor row, shifting
     * lines down within the DECSTBM region.
     *
     * Per VT500 §IL and xterm ctlseqs: ignored when the cursor sits
     * outside the scroll region; on success the cursor moves to the left
     * margin (column 0) on its original row, exactly like
     * charmbracelet/x/vt handlers.go IL; scrolled-off content is lost
     * (no scrollback push — IL/DL edits stay region-internal).
     *
     * @param list<int> $params
     */
    private function insertLines(array $params): void
    {
        $first = $params[0] ?? -1;
        if ($first === 0) {
            return; // Explicit CSI 0 L is a no-op (charm InsertLine guard).
        }
        $count = $first < 0 ? 1 : $first;
        $row = $this->cursor->row;
        if ($row < $this->scrollRegionTop || $row > $this->scrollRegionBottom) {
            return;
        }
        $this->scrollHandler->insertLines(
            $this->buffer,
            $this->scrollRegionTop,
            $this->scrollRegionBottom,
            $row,
            $count,
        );
        // Column-home is a movement: the phantom (armed against the OLD
        // right margin) must not survive it, or the next graphic would
        // skip a line. Documented divergence from charm's keep-phantom
        // setCursorX(0,true), which corrupts content under this port's
        // geometry-only flag.
        $this->cursor = $this->cursor->withCol(0);
        $this->wrapPending = false;
    }

    /**
     * DL — CSI Ps M: delete Ps lines at the cursor row, shifting lines
     * up within the DECSTBM region; blanks land at the region bottom.
     *
     * Same region-clipping + column-home rules as {@see insertLines()}.
     * When the deletion starts exactly at the region top and the region
     * covers the WHOLE screen, the deleted rows fall into scrollback first
     * — mirroring charmbracelet/x/vt `Screen.DeleteLine`'s
     * `scrollback.PushN` guard, so `less`-style scrolling up through
     * deleted context keeps working. A sub-region DL leaves the ring
     * untouched: nothing crossed the screen edge (see {@see scrollUp()}).
     *
     * @param list<int> $params
     */
    private function deleteLines(array $params): void
    {
        $first = $params[0] ?? -1;
        if ($first === 0) {
            return; // Explicit CSI 0 M is a no-op (charm DeleteLine guard).
        }
        $count = $first < 0 ? 1 : $first;
        $row = $this->cursor->row;
        if ($row < $this->scrollRegionTop || $row > $this->scrollRegionBottom) {
            return;
        }
        if ($row === $this->scrollRegionTop && $this->regionIsFullScreen()) {
            $lines = min($count, $this->scrollRegionBottom - $row + 1);
            for ($i = 0; $i < $lines; $i++) {
                $this->scrollback->push($this->rowAt($row + $i));
            }
        }
        $this->scrollHandler->deleteLines(
            $this->buffer,
            $this->scrollRegionTop,
            $this->scrollRegionBottom,
            $row,
            $count,
        );
        $this->cursor = $this->cursor->withCol(0); // Phantom dropped — see insertLines().
        $this->wrapPending = false;
    }

    // ─── Query → reply channel ───────────────────────────────────────────────

    /** Queue one reply, dropping the oldest past {@see self::MAX_REPLIES}. */
    private function reply(string $bytes): void
    {
        if (\count($this->replies) >= self::MAX_REPLIES) {
            array_shift($this->replies);
        }
        $this->replies[] = $bytes;
    }

    /**
     * DA / DA2 — Device Attributes request (xterm ctlseqs "Device Attributes").
     *
     * DA1 (CSI c / CSI 0 c) is answered with the same attribute list
     * charmbracelet/x/vt emits — `ESC [ ?62;1;6;22 c` (VT220, 132-column,
     * selective erase, ANSI colour) — deliberately WITHOUT the `;4` sixel
     * attribute: the emulator renders no sixel, and candy-mosaic's
     * `Detect::parseDa1Reply()` scans for `;4;` / `;4c` / `?4c` to enable
     * sixel, so this reply correctly keeps mosaic on its text/half-block
     * fallback. DA2 (CSI > c) answers `ESC [ >1;10;0 c`, again mirroring
     * upstream. Requests with other first params (vendor DA variants)
     * are left unanswered, as upstream guards them.
     *
     * @see https://vt100.net/docs/vt510-rm/chapter4.html (Device Attributes)
     * @see charmbracelet/x/vt handlers.go PrimaryDeviceAttributes/SecondaryDeviceAttributes
     *
     * @param array<int, int|string> $params CSI parameter list as dispatched by the parser
     */
    private function deviceAttributes(int $prefix, array $params): void
    {
        if ($prefix === ord('>')) {
            if (($params[0] ?? -1) > 0) {
                return;
            }
            $this->reply("\x1b[>1;10;0c");
            return;
        }
        if ($prefix === 0 && ($params[0] ?? -1) <= 0) {
            $this->reply("\x1b[?62;1;6;22c");
        }
        // Other private prefixes (ESC [ = / < c) belong to vendor
        // trees we do not emulate — silent, like xterm unbound DA requests.
    }

    /**
     * DSR — CSI Ps n: 5 → terminal OK (`ESC [ 0 n`), 6 → cursor position
     * report (`ESC [ row ; col R`, 1-based).
     *
     * Answering CPR does NOT disturb a pending DECAWM wrap: the report
     * only reads state — xterm likewise leaves `_wrapnext` set while the
     * cursor keeps reporting the last column it painted.
     *
     * @param list<int> $params
     *
     * @see ECMA-48 §9.41 (MSR/DSR), xterm ctlseqs DSR
     */
    private function deviceStatus(array $params): void
    {
        $first = $params[0] ?? -1;
        if ($first === -1 || $first === 5) {
            $this->reply("\x1b[0n");
            return;
        }
        if ($first === 6) {
            $this->reply("\x1b[" . ($this->cursor->row + 1) . ';' . ($this->cursor->col + 1) . 'R');
        }
        // 15/25/26/55 (printer/status) — not modelled, no reply (xterm
        // with printer off behaves the same).
    }

    /**
     * DECRQM — CSI ? Ps $ p (private) / CSI Ps $ p (ANSI).
     *
     * Replies DECRPM `ESC [ ? Ps ; Pd $ y` with Pd: 0 unrecognized,
     * 1 set, 2 reset (3/4 permanently set/reset are never produced — no
     * mode here is hardware-locked). ANSI (non-private) modes report 0:
     * this emulator routes only DEC private modes through ModeHandler.
     *
     * @param list<int> $params
     *
     * @see xterm ctlseqs DECRQM/DECRPM
     * @see VT500 §DECRCQM
     */
    private function requestMode(array $params, int $prefix): void
    {
        $mode = $params[0] ?? -1;
        if ($mode <= 0) {
            return;
        }
        $private = $prefix === ord('?');
        if ($private) {
            $state = $this->decModeStatus($mode);
            $this->reply("\x1b[?{$mode};{$state}\$y");
            return;
        }
        $this->reply("\x1b[{$mode};0\$y");
    }

    /** Map a DEC private mode number to DECRQM status (0/1/2). */
    private function decModeStatus(int $mode): int
    {
        $on = static fn (bool $v): int => $v ? 1 : 2;
        return match ($mode) {
            6 => $on($this->mode->originMode),
            7 => $on($this->mode->autoWrap),
            25 => $on($this->mode->cursorVisible),
            47, 1047 => $on($this->mode->altScreenVariant === Mode::ALT_NO_SAVE),
            1048 => $on($this->mode->altScreenVariant === Mode::ALT_CURSOR_ONLY),
            1049 => $on($this->mode->altScreenVariant === Mode::ALT_FULL),
            1000 => $on($this->mode->mouseAny),
            1001, 1005, 1015 => $on($this->mode->mouseHighlights),
            1002 => $on($this->mode->mouseCellMotion),
            1003 => $on($this->mode->mouseExtended),
            1004 => $on($this->mode->reportFocusEvents),
            1006 => $on($this->mode->mouseSgr),
            2004 => $on($this->mode->bracketedPaste),
            2026 => $on($this->mode->syncUpdate),
            default => 0,
        };
    }

    /**
     * XTWINOPS — CSI Ps t window queries (xterm ctlseqs "XTWINOPS").
     *
     * 16t answers `ESC [ 6 ; height ; width t` and 14t answers
     * `ESC [ 4 ; height ; width t` from the emulator's nominal cell
     * metrics (8×16 px unless `cellWidthPx`/`cellHeightPx` were set) —
     * the exact field order candy-mosaic's `Detect::parseXtwinoReply()`
     * expects. 18t answers `ESC [ 8 ; rows ; cols t` from true geometry.
     * A bare `CSI t` defaults to 1t in xterm (de-iconify, no reply), so
     * a missing Ps stays silent rather than emitting an unsolicited 4t.
     *
     * @param list<int> $params
     *
     * @see https://invisible-island.net/xterm/ctlseqs/ctlseqs.html#h3-Window-manipulation-functions
     */
    private function windowOps(array $params): void
    {
        $rows = $this->buffer->rows;
        $cols = $this->buffer->cols;
        match ($params[0] ?? 0) {
            14 => $this->reply("\x1b[4;" . ($rows * $this->cellHeightPx) . ';' . ($cols * $this->cellWidthPx) . 't'),
            16 => $this->reply("\x1b[6;{$this->cellHeightPx};{$this->cellWidthPx}t"),
            18 => $this->reply("\x1b[8;{$rows};{$cols}t"),
            default => null, // Resize/move requests (0-13, 15, 17, 19, 20+): not answered.
        };
    }

    // ─── Reset family (RIS / DECSTR / DECALN) ────────────────────────────────

    /**
     * RIS — ESC c, full reset to the power-on state.
     *
     * RESETS: screen contents (fresh Buffer), cursor to home with the
     * DECAWM phantom flag and saved cursor dropped, the DECSCUSR cursor
     * shape on both `cursor->shape` and `mode->cursorShape`, SGR pen, every DEC
     * mode to its power-on value (DECAWM on, DECOM off, DECTCEM
     * visible …), DECSTBM margins to full screen, tab stops to the
     * 8-column default, SCS designations + GL shift + single-shift slot,
     * active OSC 8 hyperlink, the alt-screen swap (returns to main
     * screen), and any queued synchronized-update mutations.
     * PRESERVED: scrollback ring — like charmbracelet/x/vt
     * `Emulator.fullReset()`, which resets both Screen buffers but never
     * the Scrollback (only ED 3 clears it), and xterm, whose RIS erase
     * does not feed or flush the ring; window title; indexed palette;
     * recorded clipboard/focus event logs; the pending reply queue.
     *
     * @see https://vt100.net/docs/vt510-rm/chapter4.html (RIS)
     * @see charmbracelet/x/vt Emulator.fullReset
     */
    public function hardReset(): void
    {
        $cols = $this->buffer->cols;
        $rows = $this->buffer->rows;

        $this->buffer = new Buffer($cols, $rows);
        $this->cursor = new Cursor();
        $this->wrapPending = false;
        $this->sgr = Sgr::empty();
        $this->mode = new Mode();
        $this->tabStops = TabHandler::defaults($cols);
        $this->scrollRegionTop = 0;
        $this->scrollRegionBottom = $rows - 1;
        $this->charsets = [Charsets::ASCII, Charsets::ASCII, Charsets::ASCII, Charsets::ASCII];
        $this->gl = 0;
        $this->singleShift = null;
        $this->currentHyperlink = null;
        $this->savedBuffer = null;
        $this->savedCursor = null;
        $this->savedSgr = null;
        $this->savedWrapPending = null;
        $this->savedCharsets = null;
        $this->savedGl = null;
        $this->savedOriginMode = null;
        $this->generalSavedSgr = null;
        $this->generalSavedCharsets = null;
        $this->generalSavedGl = null;
        $this->generalSavedOriginMode = null;
        $this->parkedGeneral = null;
        $this->pendingMutations = [];
    }

    /**
     * DECSTR — CSI ! p, soft reset.
     *
     * Design choice — xterm's ctlseqs records DECSTR in a single line;
     * VT510 Table 5-9 enumerates DEC hardware, whose variant resets more.
     * We follow a deliberately narrower subset than xterm-411 where the
     * buffer geometry is concerned (see DIVERGENCE), while matching xterm
     * on cursor shape.
     *
     * Resets: SGR pen, cursor home + visible, DECOM off, DECAWM back to its
     * power-on ON, sync output off (flushing whatever the queue held), wrap
     * flag dropped, and the DECSCUSR cursor shape — on BOTH
     * {@see Cursor::$shape} and {@see Mode::$cursorShape}.
     * SCOPED SUBSET: the DEC-private extension modes outside this list —
     * mouse tracking, bracketed paste, focus reporting, alt screen — SURVIVE.
     * Cursor shape is NOT in that list; it is reset, for the source-backed
     * reason below.
     *
     * CURSOR SHAPE: xterm resets DECSCUSR on the soft reset. `CASE_DECSTR`
     * (`charproc.c:6154-6156`) calls `VTReset(xw, False, False)`, which runs
     * `ReallyReset()` with `full == False` (`charproc.c:14358`); its cursor
     * block sits at `charproc.c:14377-14387`, ABOVE the RIS-only
     * `if (full) {` that opens at `charproc.c:14432`, so the soft reset
     * executes it too. That block sets `screen->cursor_set = ON`, calls
     * `InitCursorShape(screen, screen)` (`charproc.c:14379`) and, under
     * `OPT_BLINK_CURS`, `screen->cursor_blink_esc = 0` (`charproc.c:14382`).
     * `InitCursorShape` (`charproc.c:10315-10317`) recomputes
     * `screen->cursor_shape` purely from the `cursorUnderLine`/`cursorBar` X
     * resources (`charproc.c:569-570`, both default `False`) — never from the
     * DECSCUSR-set value, because `CASE_DECSCUSR` (`charproc.c:4949-5005`)
     * writes only `screen->cursor_shape` (`charproc.c:4968-4988`) and
     * `screen->cursor_blink_esc` (`charproc.c:4999`), never those resources.
     * With stock resources DECSTR therefore lands on a
     * steady, non-blinking block: a shape set by `CSI Ps SP q` does not
     * outlive it. candy-vt models no X-resource configuration, so "back to
     * the initial shape" is the 0 that {@see Cursor} and {@see Mode} already
     * default to. The two fields are reset TOGETHER for the same reason the
     * CSI Ps SP q handler sets them together (see {@see self::csiDispatch()}):
     * an earlier revision zeroed `cursor->shape` while leaving
     * `mode->cursorShape` at its DECSCUSR value, so `CSI 4 SP q` then
     * `CSI ! p` left the two fields holding different shapes. Nothing in-tree
     * renders or reports either one yet: the only consumers of
     * `Mode::$cursorShape` are {@see Mode::equals()} and the reconcile in
     * {@see self::__construct()}, and no rasterizer reads this Cursor class,
     * so this is state-integrity work, not a visible-drawing fix.
     *
     * DIVERGENCE (our choice, not xterm's): xterm also resets the scrolling
     * region (`resetMarginMode(xw)`, `charproc.c:14398`, likewise above the
     * `if (full)` gate), the character-set designations
     * (`resetCharsets(screen)`, `charproc.c:14410`, same side of the gate),
     * and the DECSC saved cursor — the DECSTR branch itself issues
     * `CursorSave(xw)` then forces `screen->sc[screen->whichBuf].row` and
     * `.col` to 0 (`charproc.c:14559-14561`), i.e. it overwrites the save
     * slot with home rather than preserving it. candy-vt keeps margins,
     * tab stops, charsets, the saved cursor and the scrollback across
     * DECSTR; only the items in the Resets list above move. Tab stops and
     * the scrollback agree with xterm, whose `TabReset` is inside
     * `if (full)` (`charproc.c:14449`) and whose scrollback flush is gated
     * on the separate `saved` argument (`charproc.c:14372-14375`).
     *
     * @see https://vt100.net/docs/vt510-rm/DECSTR.html (DECSTR)
     * @see https://invisible-island.net/xterm/ctlseqs/ctlseqs.html (DECSTR)
     */
    public function softReset(): void
    {
        if ($this->mode->syncUpdate) {
            // Exiting synchronized output — same flush rule as CSI 2026 l.
            $this->flushPendingMutations();
        }
        $this->sgr = Sgr::empty();
        // `shape: 0` here equals Cursor's own default, so deleting it would
        // change nothing today — it is written explicitly so the reset is
        // legible at the call site and survives a future change to that
        // default. The behavioural half of this reset is the
        // ->withCursorShape(0) on the mode below. See the CURSOR SHAPE
        // paragraph above for why xterm mandates a reset at all.
        $this->cursor = new Cursor(
            visible: true,
            shape: 0,
            savedRow: $this->cursor->savedRow,
            savedCol: $this->cursor->savedCol,
        );
        $this->wrapPending = false;
        $this->mode = $this->mode
            ->withOriginMode(false)
            ->withAutoWrap(true)
            ->withCursorVisible(true)
            ->withSyncUpdate(false)
            ->withCursorShape(0);
        $this->pendingMutations = [];
    }

    /**
     * DECALN — ESC # 8, screen-alignment pattern.
     *
     * Fills the whole screen with 'E' (default rendition), resets the scroll
     * margins to full, homes the cursor, resets the SGR pen, and resets the
     * character-set designations to ASCII.
     *
     * xterm-411 `charproc.c` `CASE_DECALN` does, in order (eliding three steps
     * that change no persistent emulator state: the leading `HideCursor`
     * draw-time cursor bookkeeping — DECALN does not alter DECTCEM visibility —
     * the `xtermParseRect` fill-argument setup, and the trailing `ResetState(sp)`
     * parser housekeeping): clear ORIGIN
     * (`UIntClr(xw->flags, ORIGIN)`) — DECOM goes OFF, never toggled-then-
     * restored as was previously mis-stated here; clear the pending-wrap FLAG
     * (`screen->do_wrap = False`) — NOT the DECAWM mode; `resetRendition`;
     * `resetMargins`; `xterm_ResetDouble`; `CursorSet(screen, 0, 0, ...)`; and
     * `ScrnFillRectangle(..., 'E', nrc_ASCII, ...)`. This method reproduces every
     * step of that list which candy-vt models; `xterm_ResetDouble` has no
     * counterpart because line-width/height modes (DECDHL/DECSWL/DECDWL) are
     * unimplemented here — see {@see self::escDispatch()}. Because the fill walks
     * the whole buffer (rows × cols) and homes to absolute 0,0, the 'E' pattern
     * covers the screen regardless of any DECSTBM region or origin mode left
     * behind, and origin mode is off afterwards.
     *
     * DECSCUSR cursor shape is DELIBERATELY PRESERVED: CASE_DECALN does not
     * touch cursor style, so `cursor->shape` is carried through (see the body)
     * and stays equal to `mode->cursorShape`.
     *
     * Charset DEVIATION (the `$this->charsets` / `$this->gl` / `$this->singleShift`
     * assignments below): candy-vt resets the G0-G3 designations, GL and any
     * armed single-shift PERSISTENTLY to ASCII. xterm's `CASE_DECALN` issues no
     * SCS designator at all — it leaves the designation state untouched and
     * simply passes `nrc_ASCII` as the charset ARGUMENT of `ScrnFillRectangle`,
     * filling with a raw ASCII 'E' — so a line-drawing designation set before
     * DECALN resumes on the next printable in xterm, but is gone here.
     * Deliberate, documented divergence, not an oversight.
     *
     * The authoritative wire encoding is `ESC # 8` (bytes 1B 23 38): DEC
     * VT510 ch.4, DEC ansicode.txt, xterm ctlseqs, and vttest all agree.
     * The `ESC [ # 8` rendering seen in some references is a misquote —
     * in xterm `ESC [` + `#` switches to the palette-stack substate
     * (`csi_hash_table[]`, the `CSI # P/Q/R/S` XT*COLORS family), never a
     * DECALN trigger. The sequence reaches here through
     * {@see self::escDispatch()}; candy-core's `Ansi::decaln()` emits the
     * same bytes, closing the emitter→emulator round trip.
     *
     * @see https://vt100.net/docs/vt510-rm/DECALN.html (DECALN)
     * @see https://invisible-island.net/xterm/ctlseqs/ctlseqs.html (ESC # 8; CSI # = palette stack)
     */
    public function displayAlignmentTest(): void
    {
        $blank = new Cell(grapheme: 'E');
        for ($r = 0; $r < $this->buffer->rows; $r++) {
            for ($c = 0; $c < $this->buffer->cols; $c++) {
                $this->buffer->put($r, $c, $blank);
            }
        }
        $this->sgr = Sgr::empty();
        // Re-home and reset rendition, but CARRY the DECSCUSR shape through:
        // xterm-411 CASE_DECALN never touches cursor style, so `cursor->shape`
        // and `mode->cursorShape` (set together by the CSI Ps SP q handler) must
        // stay in agreement across DECALN.
        $this->cursor = new Cursor(
            visible: $this->cursor->visible,
            shape: $this->cursor->shape,
            savedRow: $this->cursor->savedRow,
            savedCol: $this->cursor->savedCol,
        );
        $this->wrapPending = false;
        // xterm-411 CASE_DECALN does `UIntClr(xw->flags, ORIGIN)` — DECOM goes
        // OFF and is never restored. (It also clears the pending-wrap FLAG above,
        // not the DECAWM MODE.)
        $this->mode = $this->mode->withOriginMode(false);
        $this->scrollRegionTop = 0;
        $this->scrollRegionBottom = $this->buffer->rows - 1;
        $this->charsets = [Charsets::ASCII, Charsets::ASCII, Charsets::ASCII, Charsets::ASCII];
        $this->gl = 0;
        $this->singleShift = null;
    }

    /**
     * Resolve a rune through the GL-invoked designation (or the armed
     * SS2/SS3 set), consuming any single shift.
     */
    private function translateRune(string $rune): string
    {
        if ($this->singleShift !== null) {
            $charset = $this->charsets[$this->singleShift];
            $this->singleShift = null;
            return Charsets::translate($charset, $rune);
        }
        return Charsets::translate($this->charsets[$this->gl], $rune);
    }

    /**
     * Push the top $count rows of the scroll region into scrollback,
     * then delegate the actual buffer shift to ScrollHandler.
     */
    private function scrollUp(int $count): void
    {
        $height = $this->scrollRegionBottom - $this->scrollRegionTop + 1;
        $count = min($count, $height);
        if ($count <= 0) {
            return;
        }

        // Scrollback is the history of what ran off the TOP OF THE SCREEN.
        // A scroll inside a strict DECSTBM sub-region (top > 0, or bottom
        // short of the last row) shifts nothing off the screen edge, so it
        // must not feed the ring — the old unconditional push let
        // htop/tmux-style region redraws bury real history in placeholder
        // rows (VT500 §scroll region hygiene; port spec pins the full-screen
        // gate. xterm is slightly looser — a top-anchored region still
        // feeds history — documented divergence, `top===0 &&
        // bottom===rows-1` is the stricter, predictable half).
        if ($this->regionIsFullScreen()) {
            for ($i = 0; $i < $count; $i++) {
                $this->scrollback->push($this->rowAt($this->scrollRegionTop + $i));
            }
        }

        $this->scrollHandler->scrollUp(
            $this->buffer,
            $this->scrollRegionTop,
            $this->scrollRegionBottom,
            $count,
        );
    }

    /**
     * Push the bottom $count rows of the scroll region into scrollback,
     * then delegate the actual buffer shift to ScrollHandler.
     */
    private function scrollDown(int $count): void
    {
        $height = $this->scrollRegionBottom - $this->scrollRegionTop + 1;
        $count = min($count, $height);
        if ($count <= 0) {
            return;
        }

        // Full-screen gate — see scrollUp(). RI at a sub-region top
        // reverse-scrolls inside the region without touching the screen
        // edge, so nothing enters (and nothing should) the ring.
        if ($this->regionIsFullScreen()) {
            for ($i = 0; $i < $count; $i++) {
                $this->scrollback->push($this->rowAt($this->scrollRegionBottom - $i));
            }
        }

        $this->scrollHandler->scrollDown(
            $this->buffer,
            $this->scrollRegionTop,
            $this->scrollRegionBottom,
            $count,
        );
    }

    /** True when the DECSTBM region spans the entire screen — the only geometry whose scroll feeds scrollback. */
    private function regionIsFullScreen(): bool
    {
        return $this->scrollRegionTop === 0
            && $this->scrollRegionBottom === $this->buffer->rows - 1;
    }

    /**
     * Copy the row at the given absolute index as `array<int, Cell>`.
     *
     * @return array<int, Cell>
     */
    private function rowAt(int $row): array
    {
        $cells = [];
        for ($c = 0; $c < $this->buffer->cols; $c++) {
            $cells[] = $this->buffer->cell($row, $c);
        }
        return $cells;
    }

    /**
     * Enter the alt screen (DEC 1049 set). Saves the current Buffer +
     * Cursor + Sgr + SCS designations + origin mode and swaps in a fresh
     * blank Buffer of the same size. This is the AUX save slot — the
     * DECSC/CSI s GENERAL slot is never consumed by it (xterm keeps the two
     * independent; `less` saving with ESC 7 before entering alt must still
     * find its rendition on the way back). The companions only PARK for the
     * duration so the alt screen starts with its own empty slot, matching
     * xterm's per-screen saved cursor.
     * Idempotent — re-entering while already in alt mode is a no-op.
     *
     * The fresh cursor homes and keeps DECTCEM visibility, but CARRIES the
     * DECSCUSR shape across the swap — see the body for the xterm-411 lines
     * that make cursor style per-terminal rather than per-buffer.
     */
    public function enterAltScreen(): void
    {
        if ($this->mode->isAltScreen()) {
            return;
        }
        $this->parkGeneralCompanions();
        $this->savedBuffer = $this->buffer;
        $this->savedCursor = $this->cursor;
        $this->savedSgr = $this->sgr;
        $this->savedWrapPending = $this->wrapPending;
        $this->savedCharsets = $this->charsets;
        $this->savedGl = $this->gl;
        $this->savedOriginMode = $this->mode->originMode;
        $this->buffer = new Buffer($this->buffer->cols, $this->buffer->rows);
        // CARRY the DECSCUSR shape through the swap: xterm's alt-screen entry
        // is `CursorSave(xw); ToAlternate(xw, True); ClearScreen(xw);`
        // (`charproc.c:7732-7745`, case `srm_OPT_ALTBUF_CURSOR`) and none of
        // those touches cursor style — `SavedCursor` (`ptyx.h:2347-2363`) has
        // no shape member and `ToAlternate`/`SwitchBufs` (`charproc.c:9529-9545`,
        // `charproc.c:9564-9594`) never write `screen->cursor_shape`, which is a
        // single per-terminal field (`ptyx.h:2806`) rather than a per-buffer one.
        // Homing the cursor must therefore leave the shape alone, keeping
        // `cursor->shape` equal to `mode->cursorShape` while the alt screen is live.
        $this->cursor = new Cursor(visible: $this->cursor->visible, shape: $this->cursor->shape);
        $this->wrapPending = false;
        $this->sgr = Sgr::empty();
        $this->mode = $this->mode->withAltScreenVariant(Mode::ALT_FULL);
    }

    /**
     * Leave the alt screen (DEC 1049 reset). Restores the saved Buffer
     * + Cursor + Sgr + SCS + origin mode. No-op if not currently in alt mode.
     */
    public function leaveAltScreen(): void
    {
        if ($this->mode->altScreenVariant !== Mode::ALT_FULL || $this->savedBuffer === null) {
            return;
        }
        $this->unparkGeneralCompanions();
        $this->buffer = $this->savedBuffer;
        $this->cursor = $this->savedCursor ?? $this->cursor;
        $this->sgr = $this->savedSgr ?? Sgr::empty();
        $this->wrapPending = $this->savedWrapPending ?? false;
        $this->charsets = $this->savedCharsets ?? $this->charsets;
        $this->gl = $this->savedGl ?? $this->gl;
        $this->mode = $this->mode->withOriginMode($this->savedOriginMode ?? $this->mode->originMode);
        $this->savedBuffer = null;
        $this->savedCursor = null;
        $this->savedSgr = null;
        $this->savedWrapPending = null;
        $this->savedCharsets = null;
        $this->savedGl = null;
        $this->savedOriginMode = null;
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
     * to a fresh blank one and cursor is reset to origin, keeping its
     * DECTCEM visibility and DECSCUSR shape. On exit, only
     * the cursor is restored.
     */
    public function enterAltScreenCursorOnly(): void
    {
        if ($this->mode->altScreenVariant === Mode::ALT_CURSOR_ONLY) {
            return;
        }
        if (!$this->mode->isAltScreen()) {
            $this->parkGeneralCompanions();
        }
        $this->savedCursor = $this->cursor;
        $this->savedWrapPending = $this->wrapPending;
        $this->buffer = new Buffer($this->buffer->cols, $this->buffer->rows);
        // Homed, visibility kept, DECSCUSR shape CARRIED through. NOTE the
        // asymmetry with xterm: DEC 1048 there is `srm_SAVE_CURSOR`
        // (`ptyx.h:1275`), a bare `CursorSave(xw)` / `CursorRestore(xw)` with
        // NO buffer swap at all (`charproc.c:7915-7922`); candy-vt's 1048 is a
        // cursor-only alt swap, which is this port's own pre-existing model.
        // The shape survives either way for the same reason — cursor style is
        // per-terminal and `SavedCursor` carries none (see
        // {@see self::enterAltScreen()} for those lines).
        $this->cursor = new Cursor(visible: $this->cursor->visible, shape: $this->cursor->shape);
        $this->wrapPending = false;
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
        $this->unparkGeneralCompanions();
        $this->cursor = $this->savedCursor;
        $this->wrapPending = $this->savedWrapPending ?? false;
        $this->savedCursor = null;
        $this->savedWrapPending = null;
        // Do NOT restore buffer or SGR
        $this->mode = $this->mode->withAltScreenVariant(Mode::ALT_NONE);
    }

    /**
     * Append a combining character to the previous cell (column cur-1).
     * If the previous cell is out of bounds or is a continuation cell,
     * the combining mark is silently dropped.
     *
     * When synchronized-output (DEC 2026) mode is active, the mutation is
     * queued for later flush; otherwise it is applied immediately.
     */
    private function attachCombiningChar(string $combining): void
    {
        $r = $this->cursor->row;
        // While the phantom cell is armed the last glyph sits UNDER the
        // cursor (not one before it) — attach there instead.
        $c = $this->wrapPending ? $this->cursor->col : $this->cursor->col - 1;

        if ($c < 0) {
            return; // Nothing before cursor to attach to.
        }

        $prev = $this->buffer->cell($r, $c);

        // Don't attach to a wide-char continuation cell — skip.
        if ($prev->continuation) {
            return;
        }

        $updated = $prev->withCombining($combining);

        if ($this->mode->syncUpdate) {
            $this->pendingMutations[] = ['row' => $r, 'col' => $c, 'cell' => $updated];
            return;
        }
        $this->buffer->put($r, $c, $updated);
    }

    /**
     * Write a cell to the buffer, or queue it when synchronized output
     * (DEC 2026) mode is active.
     *
     * @see flushPendingMutations()
     */
    private function putCell(int $row, int $col, Cell $cell): void
    {
        if ($this->mode->syncUpdate) {
            $this->pendingMutations[] = ['row' => $row, 'col' => $col, 'cell' => $cell];
            return;
        }
        $this->buffer->put($row, $col, $cell);
    }

    /**
     * Replay all pending mutations accumulated during synchronized-output
     * (DEC 2026) mode and clear the queue.
     */
    private function flushPendingMutations(): void
    {
        foreach ($this->pendingMutations as $mutation) {
            $this->buffer->put($mutation['row'], $mutation['col'], $mutation['cell']);
        }
        $this->pendingMutations = [];
    }

    /**
     * Deep-copy on clone: PHP's default semantics would leave the clone
     * writing THROUGH into the original's grid and scrollback ring, so
     * `clone $terminal` (the snapshot/rewind idiom every consumer reaches
     * for) silently corrupted the state it was meant to preserve.
     *
     * Only the two mutable aggregates matter — {@see Buffer}'s grid and
     * the alt-screen {@see Scrollback} ring (plus the parked main-screen
     * buffer while in alt). Cursor, Sgr, Mode, Cell, Color and Hyperlink
     * are immutable value objects: every mutation replaces rather than
     * edits, so sharing them across clones is safe. Arrays
     * (charsets/replies/tabStops/palette/pendingMutations) copy by value,
     * and the objects inside them are immutable for the same reason.
     */
    public function __clone(): void
    {
        $this->buffer = clone $this->buffer;
        $this->scrollback = clone $this->scrollback;
        if ($this->savedBuffer !== null) {
            $this->savedBuffer = clone $this->savedBuffer;
        }
    }
}

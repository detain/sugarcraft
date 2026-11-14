<?php

declare(strict_types=1);

namespace SugarCraft\Readline;

use SugarCraft\Core\Util\Width;
use SugarCraft\Readline\History\HistoryInterface;
use SugarCraft\Readline\History\SearchDirection;
use SugarCraft\Readline\History\SearchHit;
use SugarCraft\Readline\Mode\ModeInterface;
use SugarCraft\Readline\Mode\ViMode;

/**
 * Single-line text input with cursor, optional validation, auto-completion,
 * and hidden (password) display mode.
 *
 * State machine: feed character input via {@see handleChar()} and named keys
 * via {@see handleKey()}. Each call returns a new immutable instance.
 *
 * Port-of-spirit (not literal) of erikgeiser/promptkit `textinput`.
 *
 * @see https://github.com/erikgeiser/promptkit
 */
final class TextPrompt
{
    /**
     * History search direction: toward older entries (readline Ctrl+R).
     *
     * @deprecated The polarity's home is now {@see SearchDirection}
     *     (finding 091); this alias IS the enum case (the int-backed value
     *     was 1), so `$d === TextPrompt::SEARCH_REVERSE` keeps working for
     *     hosts that compare it. Use SearchDirection::Reverse.
     *     REMOVED-IN: 2.0.0.
     */
    public const SEARCH_REVERSE = SearchDirection::Reverse;

    /**
     * Default (emacs-style) Ctrl movement → the canonical navigation keys
     * the rest of handleKeyDirect() already implements. Applied before the
     * match so a plain TextPrompt moves with Ctrl+A/B/E/F/P/N.
     *
     * @var array<string, string>
     */
    /** How many (newest) history entries autosuggest scans per repaint — finding 014. */
    private const AUTOSUGGEST_SCAN_WINDOW = 200;

    private const EMACS_MOVEMENT_ALIASES = [
        'ctrl_a' => Key::Home,
        'ctrl_e' => Key::End,
        'ctrl_b' => Key::Left,
        'ctrl_f' => Key::Right,
        'ctrl_p' => Key::Up,
        'ctrl_n' => Key::Down,
    ];

    /**
     * History search direction: toward newer entries (readline Ctrl+S).
     *
     * @deprecated Superseded by {@see SearchDirection::Forward} (finding
     *     091); the alias keeps the legacy value (-1). REMOVED-IN: 2.0.0.
     */
    public const SEARCH_FORWARD = SearchDirection::Forward;

    /**
     * Default rejection painted when a validator says no without a reason.
     * Rides the lang/ catalog (key text.invalid_input); en value is the
     * original baked 'Invalid input' — zero render diff (S-09).
     */
    private static function invalidInputMessage(): string
    {
        return Lang::t('text.invalid_input');
    }

    /** Text typed by the user. The label is rendered separately by {@see view()}. */
    private string $buffer = '';

    /** Cursor column inside {@see $buffer} (in characters, not bytes). */
    private int $cursor = 0;

    private bool $hidden    = false;
    private int $charLimit  = 0;       // 0 = unlimited
    private bool $submitted = false;
    private bool $aborted   = false;
    private string $error   = '';

    /** @var list<string> */
    private array $completions = [];

    /**
     * Lazy completion source attached by withCompleter() (finding 089);
     * null means the static {@see $completions} list is the candidate set.
     *
     * @var (callable(string $buffer, int $cursor): list<string>)|null
     */
    private $completer = null;

    /** @var (callable(string): bool)|null */
    private $validator = null;

    private string $labelStyle      = '1;36';   // bold cyan
    private string $cursorStyle     = '7';      // reverse
    private string $errorStyle      = '31';     // red
    private string $completionStyle = '90';     // bright black
    private string $hideMask        = '*';

    /**
     * Visible width in terminal CELLS for view()'s single-line buffer row,
     * or null when the caller has not chosen one. null means "auto":
     * Readline::run() adopts COLUMNS for a prompt left at null. A non-null
     * width always wins and is never overwritten — whether a positive
     * bound or an explicit 0 (unbounded). Clipping keeps the cursor inside
     * the window and marks clipped edges with '…'.
     */
    private ?int $displayWidth = null;

    /**
     * Buffer index where the current consecutive-insert run ends, or -1 when
     * no run is open. Consecutive handleChar() calls that keep advancing the
     * caret share ONE undo snapshot (one undo cancels a typed word, vim-style)
     * instead of flooding the stack with single-character states.
     */
    private int $undoRunEnd = -1;

    /** @var list<string> Candidates offered by the last Tab, empty if not cycling. */
    private array $completionRing = [];

    /** Index into {@see $completionRing} the buffer currently holds. */
    private int $completionRingIndex = -1;

    /**
     * Bounded candidate preview armed by Tab on an empty buffer (finding 085).
     * It replaces the old auto-commit of an arbitrary first candidate: the
     * first Tab lists candidates under the prompt, the next Tab advances into
     * the ring and the cycling arm takes over from there. The flag is cleared
     * by the advance and by withCompletions(), and the row paints only while
     * the buffer is still empty, so no keystroke can strand a stale preview.
     */
    private ?string $completionHint = null;

    /**
     * History store consulted by ↑/↓ navigation: the LIVE handle the
     * caller passed to withHistory(). Navigation state lives on the prompt
     * ({@see $historyPosition}), never in the store's own cursor, so every
     * prompt sharing a store sees the same entries without drifting.
     */
    private ?HistoryInterface $history = null;

    /**
     * The history handle passed to withHistory(), used for persistence
     * (push). Same object as {@see $history}: the prompt never mints a
     * private copy, so navigation, search and push share one live store.
     */
    private ?HistoryInterface $historyOriginal = null;

    /**
     * Navigation cursor into history: -1 = live buffer (no history entry selected).
     * 0 = most recent entry, higher = older entries.
     */
    private int $historyPosition = -1;

    /**
     * Saved buffer captured when history navigation begins, so it can be
     * restored when the user navigates past the oldest entry.
     */
    private ?string $bufferBeforeHistory = null;

    /** Active key-binding mode (vi or emacs), or null for default bindings. */
    private ?ModeInterface $mode = null;

    /** Paint the vi submode indicator row in view(); off by default (BC). */
    private bool $modeIndicator = false;

    /** Undo/redo manager for buffer changes. */
    private ?UndoManager $undoManager = null;

    /** Whether fish-style autosuggest from history is enabled. */
    private bool $autoSuggestEnabled = true;

    /** Whether incremental history search (Ctrl+R / Ctrl+S) is active. */
    private bool $searching = false;

    /** Query typed so far while incremental search is active. */
    private string $searchQuery = '';

    /** Direction the active search scans in. */
    private SearchDirection $searchDirection = SearchDirection::Reverse;

    /** History index of the current search match; -1 = no match yet. */
    private int $searchIndex = -1;

    /** True when the current query has no match (readline "failed" indicator). */
    private bool $searchFailed = false;

    /** Line + cursor captured when search began, restored on cancel. */
    private ?string $bufferBeforeSearch = null;
    private int $cursorBeforeSearch = 0;

    public function __construct(private readonly string $label)
    {
    }

    public static function new(string $label): self
    {
        return new self($label);
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    public function withDefault(string $value): self
    {
        $clone = clone $this;
        // Clamp to charLimit if set (ordering caveat: withCharLimit() should precede
        // withDefault() for the clamp to engage, matching upstream promptkit behavior).
        if ($clone->charLimit > 0) {
            $clone->buffer = self::sliceChars($value, 0, $clone->charLimit);
            $clone->cursor = self::charCount($clone->buffer);
        } else {
            $clone->buffer = $value;
            $clone->cursor = self::charCount($value);
        }
        return $clone;
    }

    public function withHidden(bool $hidden = true, string $mask = '*'): self
    {
        $clone = clone $this;
        $clone->hidden   = $hidden;
        $clone->hideMask = $mask;
        return $clone;
    }

    /** @param list<string> $completions */
    public function withCompletions(array $completions): self
    {
        $clone = clone $this;
        $clone->completions = array_values($completions);
        // A new candidate set invalidates any armed empty-buffer preview.
        $clone->completionHint = null;
        return $clone;
    }

    /**
     * Attach a lazy completion source (finding 089).
     *
     * The callable is invoked once per Tab as (string $buffer, int $cursor)
     * and answers with the candidate list for that context. The cursor lets
     * a completer slice the word under the caret itself — path completion
     * matches only the last segment — instead of the host rebuilding the
     * whole list and prompt on every keystroke. With a completer attached
     * its returned list IS the candidate set (no outer prefix filter is
     * applied); the static {@see withCompletions()} list stays the fallback
     * while no completer is set. Pass null to fall back to the list again.
     *
     * @param (callable(string $buffer, int $cursor): list<string>)|null $completer
     */
    public function withCompleter(?callable $completer): self
    {
        $clone = clone $this;
        $clone->completer = $completer;
        return $clone;
    }

    /**
     * Gate `submit()` on a rule that can tell the user WHY.
     *
     * The callable receives the buffer and answers with:
     *  - `true` (or any truthy non-string) — accept;
     *  - `false`, `''` or any falsy value — reject with the default
     *    `Invalid input` message;
     *  - a non-empty string — reject and paint THAT string as the error
     *    row (sanitized, cell-clipped to the display width).
     *
     * Rejection still never happens by throwing — but a throwing validator
     * is now also caught: the exception is contained as a default-message
     * rejection instead of escaping `submit()`, `handleKey()` and `run()`.
     *
     * @param callable(string): bool|string $fn
     */
    public function withValidator(callable $fn): self
    {
        $clone = clone $this;
        $clone->validator = $fn;
        return $clone;
    }

    public function withCharLimit(int $limit): self
    {
        $clone = clone $this;
        $clone->charLimit = max(0, $limit);
        return $clone;
    }

    /**
     * Attach a history store.
     *
     * The store handed in is the LIVE one: submit() pushes recalled or
     * newly typed lines into it directly, and navigation and search
     * consult the same handle, so entries pushed earlier in the session
     * are reachable by Up/Down (and Ctrl+R) without rebuilding the
     * prompt chain. Neither read path moves the store's own cursor (they
     * use the read-only search() window with indices kept on the prompt),
     * so navigation state is per-prompt by construction and search and
     * navigation can never disagree about what the store holds.
     */
    public function withHistory(HistoryInterface $history): self
    {
        $clone = clone $this;
        $clone->history = $history;
        $clone->historyOriginal = $history;
        return $clone;
    }

    /**
     * Attach a key-binding mode (ViMode or EmacsMode), replacing the
     * default keymap's Escape-to-abort: in `EmacsMode` Escape arms the
     * meta prefix (a second Escape cancels it), and in `ViMode` Escape
     * only switches submodes — it never aborts. Both modes still abort on
     * Ctrl+C (vi additionally aborts on `Q` in normal mode), but callers
     * who want the plain Escape-abort affordance should leave the mode
     * unattached.
     */
    public function withMode(ModeInterface $mode): self
    {
        $clone = clone $this;
        $clone->mode = $mode;
        return $clone;
    }

    /**
     * Paint the vi submode (`--NORMAL--` / `--VISUAL--` / `--INSERT--`) as
     * a dim bottom row of view() when a {@see ViMode} is attached, so the
     * current input state is legible without host-side rendering. Off by
     * default; without a vi mode attached it paints nothing.
     */
    public function withModeIndicator(bool $enabled = true): self
    {
        $clone = clone $this;
        $clone->modeIndicator = $enabled;
        return $clone;
    }

    public function withUndoManager(UndoManager $undoManager): self
    {
        $clone = clone $this;
        $clone->undoManager = $undoManager;
        return $clone;
    }

    public function withAutoSuggest(bool $enabled): self
    {
        $clone = clone $this;
        $clone->autoSuggestEnabled = $enabled;
        return $clone;
    }

    /**
     * Bound the buffer row to $cells terminal columns; 0 is an explicit
     * unbounded row (rendered full width, and Readline::run() will NOT
     * adopt COLUMNS over it). Pass null to return to the unset state,
     * which is the only state eligible for COLUMNS adoption.
     * Prevents the repaint staircase: a wrapped row breaks the row-count
     * bookkeeping repaint() relies on, so long values are windowed around
     * the cursor instead. Cells (not chars) — CJK/emoji widths are honored.
     */
    public function withDisplayWidth(?int $cells): self
    {
        $clone = clone $this;
        $clone->displayWidth = $cells === null ? null : max(0, $cells);
        return $clone;
    }

    /** Current display viewport width in cells; null = unset (COLUMNS-eligible), 0 = explicit unbounded. */
    public function displayWidth(): ?int
    {
        return $this->displayWidth;
    }

    /**
     * The attached editing mode, or null for the default keymap. Queryable
     * so callers can render their own mode indicator — view() itself never
     * paints mode state.
     */
    public function mode(): ?ModeInterface
    {
        return $this->mode;
    }

    // -------------------------------------------------------------------------
    // Input
    // -------------------------------------------------------------------------

    public function handleChar(string $char): self
    {
        if ($this->submitted || $this->aborted) {
            return $this;
        }
        if ($char === '' || self::charCount($char) !== 1) {
            return $this;
        }
        // While searching, typed characters refine the query instead of the
        // buffer, so this runs before the charLimit guard.
        if ($this->searching) {
            return $this->refineSearch($char);
        }
        // A mode may claim plain characters as commands (vi normal mode, an
        // armed emacs meta prefix). Route them through the mode instead of
        // inserting — otherwise the Readline dispatch would type straight
        // over the mode's key table.
        if ($this->mode !== null && $this->mode->consumesCharacters()) {
            return $this->mode->handleKey($this, $char);
        }
        // Past the searching/mode claim points, only plain text belongs in
        // the buffer. Control bytes (including \n, \r, NUL, DEL) are keys,
        // never content: force-fed through this door they used to forge
        // multi-line values that corrupted newline-delimited history files.
        if (\ord($char) < 32 || \ord($char) === 127) {
            return $this;
        }
        if ($this->charLimit > 0 && self::charCount($this->buffer) >= $this->charLimit) {
            return $this;
        }

        $clone = clone $this;
        // The history store is shared across clones on purpose: navigation
        // state lives in $historyPosition and search() is read-only, so
        // there is nothing per-instance to isolate here (cloning the store
        // on every keystroke only threw away its write-side caches).
        // Consecutive inserts share ONE undo snapshot (pushUndoOn folds the
        // run); skipped on hidden prompts so secrets never enter undo state.
        $this->pushUndoOn($clone, true);
        $clone->buffer = self::sliceChars($clone->buffer, 0, $clone->cursor)
                       . $char
                       . self::sliceChars($clone->buffer, $clone->cursor);
        $clone->cursor++;
        // The caret now sits at the end of the open insert run; the next
        // handleChar that lands here folds into the same undo snapshot.
        $clone->undoRunEnd = $clone->cursor;
        $clone->error = '';
        // Reset history navigation so ↑ goes back to the start of history.
        $clone->historyPosition = -1;
        $clone->bufferBeforeHistory = null;
        return $clone;
    }

    /**
     * Insert a run of pasted text at the cursor as PLAIN text.
     *
     * Unlike repeated handleChar() calls this bypasses the active mode (a
     * vi-normal-mode paste must not execute letters as commands) and drops
     * control bytes (a pasted newline must not forge a submit). The Readline
     * pump routes bracketed PasteEvents through here whenever a prompt
     * exposes it.
     */
    public function insertPastedText(string $text): self
    {
        // Single-line prompt: drop every control byte except TAB — including
        // newlines, which would otherwise forge a multi-line value.
        $clean = preg_replace('/[\x00-\x08\x0a-\x1f\x7f]/u', '', $text) ?? '';
        if ($clean === '') {
            return $this;
        }

        if ($this->searching) {
            // Paste while searching refines the query, char by char — the
            // buffer must not be touched mid-search.
            $prompt = $this;
            foreach (mb_str_split($clean, 1, 'UTF-8') as $char) {
                $prompt = $prompt->refineSearch($char);
            }
            return $prompt;
        }

        if ($this->submitted || $this->aborted) {
            return $this;
        }

        $clone = clone $this;
        if ($clone->charLimit > 0) {
            $room = $clone->charLimit - self::charCount($clone->buffer);
            if ($room <= 0) {
                return $this;
            }
            $clean = self::sliceChars($clean, 0, $room);
        }
        $this->pushUndoOn($clone);
        $clone->buffer = self::sliceChars($clone->buffer, 0, $clone->cursor)
                       . $clean
                       . self::sliceChars($clone->buffer, $clone->cursor);
        $clone->cursor += self::charCount($clean);
        $clone->error = '';
        $clone->historyPosition = -1;
        $clone->bufferBeforeHistory = null;
        return $clone;
    }

    public function handleKey(string $key): self
    {
        if ($this->submitted || $this->aborted) {
            return $this;
        }

        // One canonical key language: raw control bytes become symbolic
        // names here so modes and search arms see the same strings whether
        // they were driven through Readline or fed bytes directly.
        $key = Key::normalize($key);

        // Active incremental search owns every key — checked before mode
        // delegation so vi/emacs bindings can't hijack search navigation.
        if ($this->searching) {
            return $this->handleSearchKey($key);
        }

        // Delegate to active key-binding mode if set
        if ($this->mode !== null) {
            return $this->mode->handleKey($this, $key);
        }

        return $this->handleKeyDirect($key);
    }

    /**
     * Handle a key directly, bypassing the active mode.
     * Used internally by modes to apply standard TextPrompt operations.
     */
    public function handleKeyDirect(string $key): self
    {
        if ($this->submitted || $this->aborted) {
            return $this;
        }

        // Same canonical language as handleKey(); idempotent for the
        // symbolic names modes replay.
        $key = Key::normalize($key);

        // The default keymap is emacs-style (GNU readline's default): bare
        // Ctrl movement works on a plain prompt, no EmacsMode attachment
        // required. The mode keeps its own arms for the meta-prefix
        // extensions (ESC b/f/d, alt_*).
        $key = self::EMACS_MOVEMENT_ALIASES[$key] ?? $key;

        // History navigation: ↑ / ↓
        if ($key === Key::Up || $key === Key::Down) {
            return $this->navigateHistory($key);
        }

        return match ($key) {
            Key::Left      => $this->moveCursor(-1),
            Key::Right     => $this->moveCursor(1),
            // Space is a printable character, but the symbolic name is
            // reserved (isPrintable() routes real space keys here, not to
            // handleChar), so it must have its own arm or it vanishes.
            Key::Space     => $this->handleChar(' '),
            Key::Home      => $this->moveCursorTo(0),
            Key::End       => $this->moveCursorTo(self::charCount($this->buffer)),
            Key::Backspace => $this->deleteBeforeCursor(),
            // Ctrl+H is the historic terminal erase key (many terminals
            // send it for the Backspace key itself; sugar-input hands the
            // raw byte through Key::normalize as 'backspace', while an
            // explicit Ctrl+H arrives symbolically).
            'ctrl_h', 'ctrl_backspace' => $this->deleteBeforeCursor(),
            Key::Delete    => $this->deleteUnderCursor(),
            Key::CtrlU     => $this->deleteAllBeforeCursor(),
            Key::CtrlK     => $this->deleteAllAfterCursor(),
            Key::Tab       => $this->applyCompletion(),
            Key::Enter     => $this->submit(),
            'ctrl_r' => $this->startHistorySearch(SearchDirection::Reverse),
            'ctrl_s' => $this->startHistorySearch(SearchDirection::Forward),
            // Ctrl+Z / Ctrl+Y are the keyboard doors to the undo stack —
            // the Key::Undo/Key::Redo names below stay available for
            // callers that produce them programmatically.
            'ctrl_z'      => $this->undo(),
            'ctrl_y'      => $this->redo(),
            // Ctrl+D mirrors GNU readline's delete-char-or-eof: it deletes
            // the character under the cursor, and on an empty line it acts
            // as end-of-input (abort).
            'ctrl_d'       => $this->buffer === '' ? $this->abort() : $this->deleteUnderCursor(),
            Key::Undo      => $this->undo(),
            Key::Redo      => $this->redo(),
            Key::Escape, Key::CtrlC => $this->abort(),
            Key::CtrlW     => $this->deleteWordBefore(),
            default        => $this,
        };
    }

    public function submit(): self
    {
        if ($this->submitted || $this->aborted) {
            return $this;
        }
        $clone = clone $this;
        if ($clone->validator !== null) {
            $rejection = self::validatorRejection($clone->validator, $clone->buffer);
            if ($rejection !== null) {
                $clone->error = $rejection;
                return $clone;
            }
        }
        // Push to the ORIGINAL history so the caller's reference is updated.
        // Hidden (password) prompts never persist their input: the secret
        // stays out of both in-memory and on-disk history.
        if ($clone->historyOriginal !== null && $clone->buffer !== '' && !$clone->hidden) {
            $clone->historyOriginal->push($clone->buffer);
        }
        $clone->submitted = true;
        $clone->error     = '';
        return $clone;
    }

    /**
     * Interpret a validator verdict as the rejection message to paint
     * (null = accepted). A validator that throws is contained: the
     * exception becomes the default rejection rather than escaping the
     * key loop (rejection still never travels by throw — see withValidator).
     */
    private static function validatorRejection(callable $validator, string $buffer): ?string
    {
        try {
            $verdict = $validator($buffer);
        } catch (\Throwable) {
            return self::invalidInputMessage();
        }

        if ($verdict === true) {
            return null;
        }

        if (is_string($verdict)) {
            return $verdict === '' ? self::invalidInputMessage() : $verdict;
        }

        return $verdict ? null : self::invalidInputMessage();
    }

    public function abort(): self
    {
        if ($this->submitted || $this->aborted) {
            return $this;
        }
        $clone = clone $this;
        $clone->aborted = true;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Undo / Redo
    // -------------------------------------------------------------------------

    private function undo(): self
    {
        if ($this->undoManager === null || !$this->undoManager->canUndo()) {
            return $this;
        }
        [$newManager, $restored, $ok] = $this->undoManager->undo($this->buffer);
        if (!$ok) {
            return $this;
        }
        $clone = clone $this;
        $clone->undoManager = $newManager;
        $clone->buffer = $restored;
        $clone->cursor = self::charCount($restored);
        $clone->undoRunEnd = -1;
        $clone->error = '';
        return $clone;
    }

    private function redo(): self
    {
        if ($this->undoManager === null || !$this->undoManager->canRedo()) {
            return $this;
        }
        [$newManager, $restored, $ok] = $this->undoManager->redo($this->buffer);
        if (!$ok) {
            return $this;
        }
        $clone = clone $this;
        $clone->undoManager = $newManager;
        $clone->buffer = $restored;
        $clone->cursor = self::charCount($restored);
        $clone->undoRunEnd = -1;
        $clone->error = '';
        return $clone;
    }

    // -------------------------------------------------------------------------
    // History navigation
    // -------------------------------------------------------------------------

    /**
     * Navigate history with ↑ (previous) or ↓ (next) keys.
     *
     * $historyPosition is the authoritative index into the newest-first
     * entry list (-1 = live buffer). The store is consulted through the
     * same live handle incremental search uses ({@see searchSource()}),
     * read-only via search() with an empty query — it never uses the store's
     * own cursor, and the store is never cloned/reset per keystroke (that
     * made every ↑ re-return the newest entry, so 2nd-older history was
     * unreachable).
     */
    private function navigateHistory(string $key): self
    {
        if ($this->history === null) {
            return $this;
        }

        $clone = clone $this;
        // Recalled entries are not part of any typing run.
        $clone->undoRunEnd = -1;

        if ($key === Key::Up) {
            $target = $clone->historyPosition === -1 ? 0 : $clone->historyPosition + 1;
            if ($clone->historyPosition === -1 && $clone->buffer !== '') {
                // Starting navigation from a live buffer: remember it so ↓
                // at the newest entry can restore what was typed.
                $clone->bufferBeforeHistory = $clone->buffer;
            }
            $found = $clone->searchSource()->search('', $target, SearchDirection::Reverse);
            if ($found === null) {
                // Already at the oldest entry — stay put, like GNU readline.
                return $clone;
            }
            $clone->historyPosition = $target;
            $clone->buffer = $found->entry;
            $clone->cursor = self::charCount($found->entry);
            return $clone;
        }

        // Key::Down
        if ($clone->historyPosition === -1) {
            // Already at live buffer — nothing to navigate.
            return $clone;
        }
        $target = $clone->historyPosition - 1;
        $found = $target >= 0 ? $clone->searchSource()->search('', $target, SearchDirection::Forward) : null;
        if ($found === null) {
            // Stepping past the newest entry returns the saved buffer.
            $clone->buffer = $clone->bufferBeforeHistory ?? '';
            $clone->cursor = self::charCount($clone->buffer);
            $clone->historyPosition = -1;
            $clone->bufferBeforeHistory = null;
        } else {
            $clone->historyPosition = $target;
            $clone->buffer = $found->entry;
            $clone->cursor = self::charCount($found->entry);
        }
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Incremental history search (readline Ctrl+R / Ctrl+S)
    // -------------------------------------------------------------------------

    /**
     * Enter incremental history search mode.
     *
     * While active: typed characters refine the query, Ctrl+R/Ctrl+S step to
     * the next match older/newer, Enter accepts the match into the buffer,
     * and Escape/Ctrl+G cancels back to the pre-search line. With an empty
     * history the mode still engages but renders a "failed" indicator, so the
     * user gets feedback instead of a dead prompt.
     *
     * @param SearchDirection|int $direction Enum (canonical); a raw int rides the
     *                                         legacy polarity (>= 0 reverse, < 0 forward).
     */
    public function startHistorySearch(SearchDirection|int $direction = SearchDirection::Reverse): self
    {
        if ($this->submitted || $this->aborted || $this->history === null) {
            return $this;
        }
        $clone = clone $this;
        $clone->searching = true;
        $clone->searchDirection = self::toDirection($direction);
        $clone->searchQuery = '';
        $clone->searchIndex = -1;
        $clone->bufferBeforeSearch = $clone->buffer;
        $clone->cursorBeforeSearch = $clone->cursor;
        // Empty history can never match anything: flag as failed immediately
        // so view() shows "(failed …)" feedback rather than a broken state.
        $clone->searchFailed = $clone->searchSource()->search('', 0, SearchDirection::Reverse) === null;
        return $clone;
    }

    private function handleSearchKey(string $key): self
    {
        // refineSearch forwards RAW control bytes; normalize at the entry so
        // every command below is reachable whether it arrives symbolically
        // (handleKey path) or as a raw keystroke byte (pump path).
        $key = Key::normalize($key);

        return match ($key) {
            // Repeated Ctrl+R / Ctrl+S step past the current match.
            'ctrl_r' => $this->stepSearch(SearchDirection::Reverse),
            'ctrl_s' => $this->stepSearch(SearchDirection::Forward),
            Key::Enter         => $this->acceptSearch(),
            Key::Escape, Key::CtrlG, Key::CtrlC
                               => $this->cancelSearch(),
            Key::Backspace     => $this->eraseSearchChar(),
            // Anything else is swallowed: search mode owns the keyboard until
            // it is accepted or cancelled (matches readline-lite behavior).
            default            => $this,
        };
    }

    /** Append a typed character to the query and re-run the search. */
    private function refineSearch(string $char): self
    {
        // Control bytes reaching handleChar() are search commands, not query text.
        if (\ord($char[0]) < 32 || $char === "\x7f") {
            return $this->handleSearchKey($char);
        }
        $clone = clone $this;
        $clone->searchQuery .= $char;
        // Re-anchor at the current match: readline keeps the same entry as
        // long as it still matches the extended query.
        $from = max(0, $clone->searchIndex);
        return $clone->applySearchResult(
            $clone->searchSource()->search($clone->searchQuery, $from, $clone->searchDirection)
        );
    }

    /** Move to the next match in $direction, past the current one. */
    private function stepSearch(SearchDirection $direction): self
    {
        $clone = clone $this;
        $clone->searchDirection = $direction;
        // No match yet: start scanning from the newest entry rather than
        // skipping past a match that does not exist.
        $from = $clone->searchIndex === -1 ? 0 : $clone->searchIndex + $direction->value;
        return $clone->applySearchResult(
            $clone->searchSource()->search($clone->searchQuery, $from, $direction)
        );
    }

    /** Remove the last query character and re-search from the newest entry. */
    private function eraseSearchChar(): self
    {
        if ($this->searchQuery === '') {
            return $this;
        }
        $clone = clone $this;
        $clone->searchQuery = self::sliceChars(
            $clone->searchQuery,
            0,
            self::charCount($clone->searchQuery) - 1
        );
        // A shorter query may match a newer entry, so restart from the top.
        return $clone->applySearchResult(
            $clone->searchSource()->search($clone->searchQuery, 0, SearchDirection::Reverse)
        );
    }

    /**
     * Exit search keeping the current match in the buffer.
     *
     * Deliberately does NOT auto-submit: the user reviews the recalled line
     * and presses Enter again to submit, avoiding accidental execution.
     */
    private function acceptSearch(): self
    {
        $clone = clone $this;
        if ($clone->searchIndex === -1) {
            // Nothing ever matched — fall back to the pre-search line.
            $clone->buffer = $clone->bufferBeforeSearch ?? '';
            $clone->cursor = self::charCount($clone->buffer);
        }
        return $clone->clearSearchState();
    }

    /** Exit search restoring the exact pre-search line and cursor. */
    private function cancelSearch(): self
    {
        $clone = clone $this;
        $clone->buffer = $clone->bufferBeforeSearch ?? '';
        $clone->cursor = min($clone->cursorBeforeSearch, self::charCount($clone->buffer));
        return $clone->clearSearchState();
    }

    /**
     * Paint a search hit onto the buffer. Pure: mutates a fresh copy of the
     * receiver (callers already pass their working clone, which carries the
     * updated query/direction state into it).
     *
     * @param SearchHit|null $match
     */
    private function applySearchResult(?SearchHit $match): self
    {
        $clone = clone $this;
        if ($match === null) {
            // Keep the last successful match visible; only flag the failure.
            $clone->searchFailed = true;
            return $clone;
        }
        $clone->searchFailed = false;
        $clone->searchIndex = $match->index;
        $clone->buffer = $match->entry;
        $clone->cursor = self::charCount($match->entry);
        $clone->undoRunEnd = -1;
        return $clone;
    }

    /** Leave search mode, dropping all query state. Pure (returns a copy). */
    private function clearSearchState(): self
    {
        $clone = clone $this;
        $clone->searching = false;
        $clone->searchQuery = '';
        $clone->searchIndex = -1;
        $clone->searchFailed = false;
        $clone->bufferBeforeSearch = null;
        $clone->cursorBeforeSearch = 0;
        $clone->undoRunEnd = -1;
        return $clone;
    }

    /**
     * History read by navigation and searching: the live (caller-owned)
     * store, matching what autosuggest reads, so entries pushed after
     * withHistory() are found by Up/Down and Ctrl+R alike.
     */
    private function searchSource(): HistoryInterface
    {
        return $this->historyOriginal ?? $this->history;
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    /** The user's typed input. Empty string when aborted. */
    public function value(): string
    {
        return $this->aborted ? '' : $this->buffer;
    }

    /** Cursor column inside {@see value()} (0..length). */
    public function cursor(): int
    {
        return $this->cursor;
    }

    public function isSubmitted(): bool
    {
        return $this->submitted;
    }
    public function isAborted(): bool
    {
        return $this->aborted;
    }
    public function error(): string
    {
        return $this->error;
    }

    /** Whether incremental history search is active. */
    public function isSearching(): bool
    {
        return $this->searching;
    }

    /** Query typed so far in the active incremental search ('' when inactive). */
    public function searchQuery(): string
    {
        return $this->searchQuery;
    }

    /** First completion that starts with the current input, or null. */
    public function suggestion(): ?string
    {
        if ($this->buffer === '') {
            return null;
        }
        foreach ($this->completions as $c) {
            if (str_starts_with($c, $this->buffer)) {
                return $c;
            }
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    public function view(): string
    {
        if ($this->searching) {
            return $this->searchView();
        }

        $display = $this->hidden
            ? str_repeat($this->hideMask, self::charCount($this->buffer))
            : Ansi::sanitize($this->buffer);

        // Window the buffer into the display viewport when one is set
        // (null lets Readline adopt COLUMNS; an explicit width — bound or
        // 0 — always wins).
        [$display, $viewCursor] = $this->fitToDisplay($display, $this->cursor);

        $before = self::sliceChars($display, 0, $viewCursor);
        $under  = self::sliceChars($display, $viewCursor, 1);
        $after  = self::sliceChars($display, $viewCursor + 1);

        // Compute fish-style autosuggestion from history. A hidden prompt
        // is excluded twice over: nothing is computed from the secret, and
        // no suggestion can echo context around it.
        $autoSuggestText = '';
        if ($this->autoSuggestEnabled && !$this->hidden
            && $this->historyOriginal !== null && $this->buffer !== '') {
            $autoSuggestText = Ansi::sanitize($this->computeAutoSuggest());
        }

        $line = Ansi::wrap(Ansi::sanitize($this->label), $this->labelStyle)
              . $before
              . Ansi::wrap($under === '' ? ' ' : $under, $this->cursorStyle)
              . $after;

        $lines = [$line];

        if ($this->error !== '') {
            $lines[] = RowFit::row([[Ansi::sanitize($this->error), $this->errorStyle]], $this->displayWidth);
        }

        // Auxiliary rows (completion hint, fish-style autosuggest) sit under
        // the main line: pad to the END of the visible window (cell-aware,
        // wide glyphs included), never to the caret column, so the dim tail
        // marks where the buffer actually stops on screen. With a bound set,
        // the tail is clipped into the remaining cells exactly like the main
        // row — an auxiliary row must never wrap the repaint the windowing
        // exists to protect.
        $padCells = Width::of(Ansi::sanitize($this->label)) + Width::of($display);
        $pad = str_repeat(' ', max(0, $padCells));
        $tailCells = ($this->displayWidth !== null && $this->displayWidth > 0)
            ? $this->displayWidth - $padCells
            : null;

        $hint = $this->suggestion();
        if (!$this->hidden && $hint !== null && $hint !== $this->buffer) {
            // Character-aware tail (substr/strlen would split UTF-8).
            $tail = self::sliceChars($hint, self::charCount($this->buffer));
            $tail = Ansi::sanitize($tail);
            if ($tailCells !== null) {
                if ($tailCells < 1) {
                    // No room under the pad: the hint row cannot paint.
                } else {
                    $lines[] = $pad . Ansi::wrap(self::clipToCells($tail, $tailCells), $this->completionStyle);
                }
            } else {
                $lines[] = $pad . Ansi::wrap($tail, $this->completionStyle);
            }
        }

        // Render fish-style autosuggestion in dim gray.
        if ($autoSuggestText !== '' && ($tailCells === null || $tailCells >= 1)) {
            $lines[] = $pad . Ansi::wrap(
                $tailCells !== null ? self::clipToCells($autoSuggestText, $tailCells) : $autoSuggestText,
                '2', // dim style
            );
        }

        // Tab-armed candidate preview (finding 085): painted only while the
        // buffer is still empty; advancing into the ring clears the flag.
        if (!$this->hidden && $this->buffer === '' && $this->completionHint !== null) {
            $preview = Ansi::sanitize($this->completionHint);
            if ($tailCells === null || $tailCells >= 1) {
                $lines[] = $pad . Ansi::wrap(
                    $tailCells !== null ? self::clipToCells($preview, $tailCells) : $preview,
                    $this->completionStyle,
                );
            }
        }

        // Optional vi submode indicator (off by default): without it a
        // keyboard-only or screen-reader user in normal mode sees a dead
        // prompt with no clue why typing does nothing. Painted as a dim
        // bottom status row, mirroring vim's own statusline.
        if ($this->modeIndicator && $this->mode instanceof ViMode) {
            $lines[] = Ansi::wrap(match ($this->mode->viMode()) {
                'normal' => '--NORMAL--',
                'visual' => '--VISUAL--',
                default  => '--INSERT--',
            }, '2');
        }

        return implode("\n", $lines);
    }

    /**
     * Clip to at most $cells display columns (cell-aware, same per-cluster
     * measurement as fitToDisplay()). On truncation the last usable cell is
     * replaced by '…' so the clipped end is visible, never silently lost.
     */
    private static function clipToCells(string $text, int $cells): string
    {
        return RowFit::clip($text, $cells);
    }

    /**
     * Render the readline-style incremental-search line, e.g.
     * `(reverse-i-search)`git': git status` with the cursor after the match.
     * On a hidden prompt the query is masked with the same idiom as the
     * buffer: search still works (matches are found), but neither the typed
     * query nor the matched entry ever reaches the frame in clear text.
     */
    private function searchView(): string
    {
        // Whole-line key (LOCALES.md): direction + failure wording live in
        // the sentence itself, not in fragments spliced around it here.
        $lineKey = 'text.search_line_' . ($this->searchDirection === SearchDirection::Reverse ? 'reverse' : 'forward')
            . ($this->searchFailed ? '_failed' : '');
        $labelRaw = Ansi::sanitize($this->label);
        $display = $this->hidden
            ? str_repeat($this->hideMask, self::charCount($this->buffer))
            : Ansi::sanitize($this->buffer);
        $query = $this->hidden
            ? str_repeat($this->hideMask, self::charCount($this->searchQuery))
            : Ansi::sanitize($this->searchQuery);

        // The whole line — label, `(reverse-i-search)`q': ` chrome, the
        // matched entry, and one cursor cell — must fit the bound like any
        // other row: it is painted through the same repaint() maths and a
        // wrapped search line desyncs the frame exactly like a wrapped
        // buffer. The expendable tail is the entry echo, so it clips first;
        // when even the fixed chrome cannot fit (a 19-cell indicator in a
        // tiny window), the whole plain line falls back to one last-resort
        // clip that sacrifices the label's style to keep the row on one
        // line — a bound is a promise the prompt never makes the host wrap
        // on.
        $bound = $this->displayWidth;
        if ($bound !== null && $bound > 0) {
            $head = Lang::t($lineKey, ['label' => $labelRaw, 'query' => $query]);
            $avail = $bound - Width::of($head) - 1;
            if ($avail >= 0) {
                return Lang::t($lineKey, ['label' => Ansi::wrap($labelRaw, $this->labelStyle), 'query' => $query])
                    . self::clipToCells($display, $avail)
                    . Ansi::wrap(' ', $this->cursorStyle);
            }
            return self::clipToCells($head . $display, max(0, $bound - 1))
                . Ansi::wrap(' ', $this->cursorStyle);
        }

        return Lang::t($lineKey, ['label' => Ansi::wrap($labelRaw, $this->labelStyle), 'query' => $query])
            . $display
            . Ansi::wrap(' ', $this->cursorStyle);
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Record the pre-edit buffer on the undo stack — suppressed entirely on
     * hidden prompts so secrets never enter undo state (and through it,
     * snapshots of the prompt object).
     *
     * Passing $insertRun folds a consecutive typing run into ONE snapshot:
     * while the caret sits exactly where the previous inserted character
     * landed, the run is open and nothing new is pushed; any other mutation
     * (delete, paste, navigation) pushes and closes the run.
     */
    private function pushUndoOn(self $clone, bool $insertRun = false): void
    {
        if ($clone->undoManager === null || $clone->hidden) {
            return;
        }
        if ($insertRun && $this->undoRunEnd === $clone->cursor) {
            return; // run already snapshotted at its start
        }
        $clone->undoManager = $clone->undoManager->push($clone->buffer);
        $clone->undoRunEnd = -1;
    }

    /**
     * Clip $display to the display viewport, keeping the character under
     * $cursor visible. Returns [window, windowCursor] in characters; clipped
     * edges carry a '…' marker that costs one of the two cells reserved for
     * it below, so the worst case never wraps.
     *
     * @return array{0: string, 1: int}
     */
    private function fitToDisplay(string $display, int $cursor): array
    {
        if ($this->displayWidth === null || $this->displayWidth <= 0) {
            return [$display, $cursor];
        }
        // Label cells + one cell under the cursor + two edge markers.
        $budget = $this->displayWidth - Width::of(Ansi::sanitize($this->label)) - 3;
        if ($budget < 1) {
            $budget = 1;
        }
        if (Width::of($display) <= $budget) {
            return [$display, $cursor];
        }

        $chars  = mb_str_split($display, 1, 'UTF-8');
        $count  = count($chars);
        if ($count === 0) {
            return ['', 0];
        }
        $widths = array_map(static fn (string $c): int => max(1, Width::of($c)), $chars);
        // The caret may sit one past the last character; the window must
        // still contain the character preceding it.
        $at = max(0, min($cursor, $count - 1));

        // Grow right from the cursor first (ahead-of-caret context is what
        // users read while typing), then spend what remains growing left.
        $left  = $at;
        $right = $at;
        $used  = $widths[$at];
        while ($right + 1 < $count && $used + $widths[$right + 1] <= $budget) {
            $right++;
            $used += $widths[$right];
        }
        while ($left - 1 >= 0 && $used + $widths[$left - 1] <= $budget) {
            $left--;
            $used += $widths[$left];
        }

        $marker = $left > 0 ? 1 : 0;
        $window = implode('', array_slice($chars, $left, $right - $left + 1));
        if ($marker === 1) {
            $window = '…' . $window;
        }
        if ($right < $count - 1) {
            $window .= '…';
        }

        $clamped      = max($left, min($cursor, $right + 1));
        $windowCursor = $clamped - $left + $marker;

        return [$window, $windowCursor];
    }

    /**
     * Delete the codepoint range [from, to) in ONE snapshot.
     *
     * The atomic-kill law (GNU readline): a kill is a single edit - one
     * undo frame, never a replay of per-character Deletes that would make
     * the user mash Ctrl+Z to walk it back. Modes and hosts use this
     * instead of synthesising motion+Delete key sequences; the default
     * map's word-kill is the same shape implemented over the buffer
     * directly (see deleteWordBefore).
     *
     * Both bounds are clamped to the buffer; an empty or inverted range
     * returns the prompt untouched (no undo frame is pushed). The cursor
     * lands at `from`, where the deleted text used to start.
     */
    public function deleteRange(int $from, int $to): self
    {
        $len = self::charCount($this->buffer);
        $from = max(0, min($len, $from));
        $to = max(0, min($len, $to));
        if ($to <= $from) {
            return $this;
        }

        $clone = clone $this;
        $this->pushUndoOn($clone);
        $clone->buffer = self::sliceChars($this->buffer, 0, $from)
            . self::sliceChars($this->buffer, $to);
        $clone->cursor = $from;
        $clone->error = '';

        return $clone;
    }

    /**
     * Jump the caret straight to a codepoint index - O(1), not a replay of
     * Left/Right presses (finding 017: word motions under EmacsMode walked
     * one clone per column). Clamped to the buffer; a no-op jump returns
     * the same instance. As with the arrow keys, a caret jump closes any
     * open insert run so typing after it starts a fresh undo segment.
     */
    public function withCursor(int $position): self
    {
        $clamped = max(0, min(self::charCount($this->buffer), $position));
        if ($clamped === $this->cursor) {
            return $this;
        }

        $clone = clone $this;
        $clone->cursor = $clamped;
        // A caret jump closes any open insert run: typing after it starts
        // a fresh undo segment rather than folding into the previous run.
        $clone->undoRunEnd = -1;

        return $clone;
    }

    private function moveCursor(int $delta): self
    {
        $target = $this->cursor + $delta;
        return $this->moveCursorTo($target);
    }

    private function moveCursorTo(int $position): self
    {
        return $this->withCursor($position);
    }

    private function deleteBeforeCursor(): self
    {
        if ($this->cursor === 0) {
            return $this;
        }
        $clone = clone $this;
        $this->pushUndoOn($clone);
        $clone->buffer = self::sliceChars($clone->buffer, 0, $clone->cursor - 1)
                       . self::sliceChars($clone->buffer, $clone->cursor);
        $clone->cursor--;
        $clone->error = '';
        return $clone;
    }

    private function deleteUnderCursor(): self
    {
        if ($this->cursor >= self::charCount($this->buffer)) {
            return $this;
        }
        $clone = clone $this;
        $this->pushUndoOn($clone);
        $clone->buffer = self::sliceChars($clone->buffer, 0, $clone->cursor)
                       . self::sliceChars($clone->buffer, $clone->cursor + 1);
        $clone->error = '';
        return $clone;
    }

    private function deleteAllBeforeCursor(): self
    {
        if ($this->cursor === 0) {
            return $this;
        }
        $clone = clone $this;
        $this->pushUndoOn($clone);
        $clone->buffer = self::sliceChars($clone->buffer, $clone->cursor);
        $clone->cursor = 0;
        $clone->error  = '';
        return $clone;
    }

    private function deleteAllAfterCursor(): self
    {
        if ($this->cursor >= self::charCount($this->buffer)) {
            return $this;
        }
        $clone = clone $this;
        $this->pushUndoOn($clone);
        $clone->buffer = self::sliceChars($clone->buffer, 0, $clone->cursor);
        $clone->error  = '';
        return $clone;
    }

    /**
     * Delete the word before the cursor.
     *
     * Uses the same word-boundary classification as EmacsMode:
     * word chars = [a-zA-Z0-9_\p{L}] (Unicode-aware letter).
     */
    private function deleteWordBefore(): self
    {
        if ($this->cursor === 0) {
            return $this;
        }
        $buffer = $this->buffer;
        $cursor = $this->cursor;

        // Skip non-word chars before cursor
        $start = $cursor;
        while ($start > 0 && !$this->isWordChar($buffer, $start - 1)) {
            $start--;
        }
        // Skip word chars
        while ($start > 0 && $this->isWordChar($buffer, $start - 1)) {
            $start--;
        }

        if ($start === $cursor) {
            return $this;
        }

        $clone = clone $this;
        $this->pushUndoOn($clone);
        $clone->buffer = self::sliceChars($buffer, 0, $start)
                       . self::sliceChars($buffer, $cursor);
        $clone->cursor = $start;
        $clone->error = '';
        return $clone;
    }

    private function isWordChar(string $buffer, int $pos): bool
    {
        $char = mb_substr($buffer, $pos, 1, 'UTF-8');
        return $char !== '' && preg_match('/[a-zA-Z0-9_\p{L}]/u', $char) === 1;
    }

    private function applyCompletion(): self
    {
        // Cycling: the buffer currently holds one of the candidates offered
        // by the last Tab, so Tab walks to the next one (classic readline
        // behavior — repeated Tab cycles the match list instead of no-op'ing).
        if (
            $this->completionRing !== []
            && $this->buffer === ($this->completionRing[$this->completionRingIndex] ?? null)
        ) {
            $clone = clone $this;
            $next = ($this->completionRingIndex + 1) % count($this->completionRing);
            $clone->buffer = $this->completionRing[$next];
            $clone->cursor = self::charCount($clone->buffer);
            $clone->completionRingIndex = $next;
            $clone->error = '';
            return $clone;
        }

        $prefix = $this->buffer;
        if ($this->completer !== null) {
            // Lazy seam (finding 089): the completer owns the context slice,
            // so its answer IS the candidate set — no outer prefix filter.
            $candidates = array_values(($this->completer)($this->buffer, $this->cursor));
        } else {
            $candidates = array_values(array_filter(
                $this->completions,
                static fn(string $c): bool => str_starts_with($c, $prefix),
            ));
        }
        if ($candidates === []) {
            return $this;
        }

        $clone = clone $this;
        $clone->completionRing = $candidates;
        $common = self::longestCommonPrefix($candidates);
        if ($common !== $prefix) {
            // Ambiguous: complete to the longest common prefix; the next Tab
            // will move from the prefix into the first full candidate.
            $clone->buffer = $common;
            $clone->cursor = self::charCount($common);
            $clone->completionRingIndex = -1;
        } else {
            if ($prefix === '') {
                // Finding 085: Tab on an empty buffer used to commit
                // candidates[0] wholesale — one keystroke inserting an
                // arbitrary first match with no menu. First Tab arms the
                // bounded preview; the next Tab advances into the ring.
                // A hidden prompt cannot paint the preview, so it keeps the
                // plain commit rather than swallowing a key invisibly.
                if (!$this->hidden && $this->completionHint !== null) {
                    $clone->buffer = $candidates[0];
                    $clone->cursor = self::charCount($clone->buffer);
                    $clone->completionRingIndex = 0;
                    $clone->completionHint = null;
                    $clone->error = '';
                    $clone->undoRunEnd = -1;
                    return $clone;
                }
                if (!$this->hidden) {
                    $clone->completionRing = $candidates;
                    $clone->completionRingIndex = -1;
                    $clone->completionHint = self::completionPreview($candidates);
                    return $clone;
                }
            }
            $clone->buffer = $candidates[0];
            $clone->cursor = self::charCount($clone->buffer);
            $clone->completionRingIndex = 0;
        }
        $clone->error = '';
        $clone->undoRunEnd = -1;
        return $clone;
    }

    /** @param list<string> $candidates @return string */
    private static function longestCommonPrefix(array $candidates): string
    {
        if ($candidates === []) {
            return '';
        }
        $common = $candidates[0];
        foreach ($candidates as $c) {
            $chars = mb_str_split($common, 1, 'UTF-8');
            $other = mb_str_split($c, 1, 'UTF-8');
            $i = 0;
            while ($i < count($chars) && $i < count($other) && $chars[$i] === $other[$i]) {
                $i++;
            }
            $common = implode('', array_slice($chars, 0, $i));
        }
        return $common;
    }

    /**
     * Bounded single-line preview of completion candidates: the first few
     * names plus an overflow count (finding 085).
     *
     * @param non-empty-list<string> $candidates
     */
    private static function completionPreview(array $candidates): string
    {
        $shown = array_slice($candidates, 0, 4);
        $text = implode('   ', $shown);
        $rest = count($candidates) - count($shown);
        if ($rest > 0) {
            $text .= '   +' . $rest;
        }
        return $text;
    }

    private static function charCount(string $s): int
    {
        return mb_strlen($s, 'UTF-8');
    }

    private static function sliceChars(string $s, int $start, ?int $length = null): string
    {
        return $length === null
            ? mb_substr($s, $start, null, 'UTF-8')
            : mb_substr($s, $start, $length, 'UTF-8');
    }

    /**
     * Compute fish-style autosuggestion from history.
     *
     * Finds the first history entry that starts with the current buffer
     * and returns the remainder of that entry.
     */
    private function computeAutoSuggest(): string
    {
        if ($this->historyOriginal === null || $this->buffer === '') {
            return '';
        }

        // Bounded scan of the storage-order view — index 0 is the NEWEST
        // entry (finding 014): entries() is a read view (arrays copy on
        // write), so a repaint costs at most AUTOSUGGEST_SCAN_WINDOW
        // comparisons — no history clone, no position-replay walk per
        // keystroke. Same first-match-wins order the old previous() walk
        // had; entries older than the window simply stop competing.
        $entries = $this->historyOriginal->entries();
        $limit = min(\count($entries), self::AUTOSUGGEST_SCAN_WINDOW);
        for ($i = 0; $i < $limit; $i++) {
            if (str_starts_with($entries[$i], $this->buffer)) {
                // Return the remainder after the buffer prefix.
                return self::sliceChars($entries[$i], self::charCount($this->buffer));
            }
        }

        return '';
    }

    /**
     * Map a legacy int polarity onto the canonical enum (finding 091):
     * >= 0 scans toward older entries, < 0 toward newer — the exact step
     * law the int constants always carried.
     */
    private static function toDirection(SearchDirection|int $direction): SearchDirection
    {
        return $direction instanceof SearchDirection
            ? $direction
            : ($direction >= 0 ? SearchDirection::Reverse : SearchDirection::Forward);
    }
}

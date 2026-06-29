<?php

declare(strict_types=1);

namespace SugarCraft\Readline;

use SugarCraft\Readline\History\HistoryInterface;
use SugarCraft\Readline\Mode\ModeInterface;

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

    /** @var (callable(string): bool)|null */
    private $validator = null;

    private string $labelStyle      = '1;36';   // bold cyan
    private string $cursorStyle     = '7';      // reverse
    private string $errorStyle      = '31';     // red
    private string $completionStyle = '90';     // bright black
    private string $hideMask        = '*';

    /** History store for ↑/↓ navigation (cloned per-operation for independent state). */
    private ?HistoryInterface $history = null;

    /**
     * The original history passed to withHistory(), used for persistence (push).
     * This is separate from $history so that cloning in navigation doesn't affect
     * the caller's history reference.
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

    /** Undo/redo manager for buffer changes. */
    private ?UndoManager $undoManager = null;

    /** Syntax highlighter for buffer display. */
    private ?Highlight $highlight = null;

    /** Whether fish-style autosuggest from history is enabled. */
    private bool $autoSuggestEnabled = true;

    public function __construct(private readonly string $label) {}

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
        return $clone;
    }

    /** @param callable(string): bool $fn  Receives the user input; return false to reject. */
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

    public function withHistory(HistoryInterface $history): self
    {
        $clone = clone $this;
        // Clone the history so each TextPrompt instance has independent navigation state.
        $clone->history = clone $history;
        // Keep reference to the original history for persistence (push) operations.
        $clone->historyOriginal = $history;
        return $clone;
    }

    public function withMode(ModeInterface $mode): self
    {
        $clone = clone $this;
        $clone->mode = $mode;
        return $clone;
    }

    public function withUndoManager(UndoManager $undoManager): self
    {
        $clone = clone $this;
        $clone->undoManager = $undoManager;
        return $clone;
    }

    public function withHighlight(Highlight $highlight): self
    {
        $clone = clone $this;
        $clone->highlight = $highlight;
        return $clone;
    }

    public function withAutoSuggest(bool $enabled): self
    {
        $clone = clone $this;
        $clone->autoSuggestEnabled = $enabled;
        return $clone;
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
        if ($this->charLimit > 0 && self::charCount($this->buffer) >= $this->charLimit) {
            return $this;
        }

        $clone = clone $this;
        // Clone history so each TextPrompt instance has independent navigation state.
        if ($clone->history !== null) {
            $clone->history = clone $clone->history;
        }
        // Push current state to undo manager before modification.
        if ($clone->undoManager !== null) {
            $clone->undoManager = $clone->undoManager->push($clone->buffer);
        }
        $clone->buffer = self::sliceChars($clone->buffer, 0, $clone->cursor)
                       . $char
                       . self::sliceChars($clone->buffer, $clone->cursor);
        $clone->cursor++;
        $clone->error = '';
        // Reset history navigation so ↑ goes back to the start of history.
        $clone->historyPosition = -1;
        $clone->bufferBeforeHistory = null;
        return $clone;
    }

    public function handleKey(string $key): self
    {
        if ($this->submitted || $this->aborted) {
            return $this;
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

        // History navigation: ↑ / ↓
        if ($key === Key::Up || $key === Key::Down) {
            return $this->navigateHistory($key);
        }

        return match ($key) {
            Key::Left      => $this->moveCursor(-1),
            Key::Right     => $this->moveCursor(1),
            Key::Home      => $this->moveCursorTo(0),
            Key::End       => $this->moveCursorTo(self::charCount($this->buffer)),
            Key::Backspace => $this->deleteBeforeCursor(),
            Key::Delete    => $this->deleteUnderCursor(),
            Key::CtrlU     => $this->deleteAllBeforeCursor(),
            Key::CtrlK     => $this->deleteAllAfterCursor(),
            Key::Tab       => $this->applyCompletion(),
            Key::Enter     => $this->submit(),
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
        if ($clone->validator !== null && !($clone->validator)($clone->buffer)) {
            $clone->error = 'Invalid input';
            return $clone;
        }
        // Push to the ORIGINAL history so the caller's reference is updated.
        if ($clone->historyOriginal !== null && $clone->buffer !== '') {
            $clone->historyOriginal->push($clone->buffer);
        }
        $clone->submitted = true;
        $clone->error     = '';
        return $clone;
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
        $clone->error = '';
        return $clone;
    }

    // -------------------------------------------------------------------------
    // History navigation
    // -------------------------------------------------------------------------

    /**
     * Navigate history with ↑ (previous) or ↓ (next) keys.
     */
    private function navigateHistory(string $key): self
    {
        if ($this->history === null) {
            return $this;
        }

        $clone = clone $this;
        // Clone history so each TextPrompt instance has independent navigation state.
        if ($clone->history !== null) {
            $clone->history = clone $clone->history;
            // Reset history object's position so getPrevious() fetches from newest.
            $clone->history->reset();
        }

        if ($key === Key::Up) {
            if ($clone->historyPosition === -1) {
                // Starting history navigation: save current buffer.
                if ($clone->buffer !== '') {
                    $clone->bufferBeforeHistory = $clone->buffer;
                }
                $entry = $clone->history->getPrevious();
                if ($entry !== null) {
                    $clone->historyPosition = 0;
                    $clone->buffer = $entry;
                    $clone->cursor = self::charCount($entry);
                }
            } else {
                $entry = $clone->history->getPrevious();
                if ($entry !== null) {
                    $clone->historyPosition++;
                    $clone->buffer = $entry;
                    $clone->cursor = self::charCount($entry);
                }
            }
        } else {
            // Key::Down
            if ($clone->historyPosition === -1) {
                // Already at live buffer — nothing to navigate.
                return $clone;
            }
            $entry = $clone->history->getNext();
            if ($entry === null) {
                // Exhausted history; restore saved buffer.
                $clone->buffer = $clone->bufferBeforeHistory ?? '';
                $clone->cursor = self::charCount($clone->buffer);
                $clone->historyPosition = -1;
                $clone->bufferBeforeHistory = null;
            } else {
                $clone->historyPosition--;
                $clone->buffer = $entry;
                $clone->cursor = self::charCount($entry);
            }
        }

        return $clone;
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
    public function cursor(): int { return $this->cursor; }

    public function isSubmitted(): bool { return $this->submitted; }
    public function isAborted(): bool   { return $this->aborted; }
    public function error(): string     { return $this->error; }

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
        $display = $this->hidden
            ? str_repeat($this->hideMask, self::charCount($this->buffer))
            : Ansi::sanitize($this->buffer);

        $before = self::sliceChars($display, 0, $this->cursor);
        $under  = self::sliceChars($display, $this->cursor, 1);
        $after  = self::sliceChars($display, $this->cursor + 1);

        // Apply syntax highlighting if set.
        $highlightedBuffer = $display;
        if ($this->highlight !== null) {
            $spans = $this->highlight->highlight($display);
            $highlightedBuffer = '';
            foreach ($spans as $span) {
                if ($span['style'] === '') {
                    $highlightedBuffer .= $span['text'];
                } else {
                    $highlightedBuffer .= Ansi::wrap($span['text'], $span['style']);
                }
            }
        }

        // Compute fish-style autosuggestion from history.
        $autoSuggestText = '';
        if ($this->autoSuggestEnabled && $this->historyOriginal !== null && $this->buffer !== '') {
            $autoSuggestText = Ansi::sanitize($this->computeAutoSuggest());
        }

        $line = Ansi::wrap(Ansi::sanitize($this->label), $this->labelStyle)
              . $before
              . Ansi::wrap($under === '' ? ' ' : $under, $this->cursorStyle)
              . $after;

        $lines = [$line];

        if ($this->error !== '') {
            $lines[] = Ansi::wrap(Ansi::sanitize($this->error), $this->errorStyle);
        }

        $hint = $this->suggestion();
        if ($hint !== null && $hint !== $this->buffer) {
            $tail = substr($hint, strlen($this->buffer));
            $lines[] = str_repeat(' ', self::charCount($this->label) + $this->cursor)
                     . Ansi::wrap(Ansi::sanitize($tail), $this->completionStyle);
        }

        // Render fish-style autosuggestion in dim gray.
        if ($autoSuggestText !== '') {
            $lines[] = str_repeat(' ', self::charCount($this->label) + $this->cursor)
                     . Ansi::wrap($autoSuggestText, '2'); // dim style
        }

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function moveCursor(int $delta): self
    {
        $target = $this->cursor + $delta;
        return $this->moveCursorTo($target);
    }

    private function moveCursorTo(int $position): self
    {
        $clamped = max(0, min(self::charCount($this->buffer), $position));
        if ($clamped === $this->cursor) {
            return $this;
        }
        $clone = clone $this;
        $clone->cursor = $clamped;
        return $clone;
    }

    private function deleteBeforeCursor(): self
    {
        if ($this->cursor === 0) {
            return $this;
        }
        $clone = clone $this;
        if ($clone->undoManager !== null) {
            $clone->undoManager = $clone->undoManager->push($clone->buffer);
        }
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
        if ($clone->undoManager !== null) {
            $clone->undoManager = $clone->undoManager->push($clone->buffer);
        }
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
        if ($clone->undoManager !== null) {
            $clone->undoManager = $clone->undoManager->push($clone->buffer);
        }
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
        if ($clone->undoManager !== null) {
            $clone->undoManager = $clone->undoManager->push($clone->buffer);
        }
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
        if ($clone->undoManager !== null) {
            $clone->undoManager = $clone->undoManager->push($clone->buffer);
        }
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
        $hint = $this->suggestion();
        if ($hint === null || $hint === $this->buffer) {
            return $this;
        }
        $clone = clone $this;
        $clone->buffer = $hint;
        $clone->cursor = self::charCount($hint);
        $clone->error  = '';
        return $clone;
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

        // Scan history entries (newest first) for one that starts with buffer.
        // We need to peek at history without advancing position.
        // Clone the history to peek.
        $history = clone $this->historyOriginal;
        $history->reset();

        while (true) {
            $entry = $history->getPrevious();
            if ($entry === null) {
                break;
            }
            if (str_starts_with($entry, $this->buffer)) {
                // Return the remainder after the buffer prefix.
                return self::sliceChars($entry, self::charCount($this->buffer));
            }
        }

        return '';
    }
}

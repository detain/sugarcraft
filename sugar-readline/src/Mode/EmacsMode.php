<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Mode;

use SugarCraft\Readline\Key;
use SugarCraft\Readline\TextPrompt;

/**
 * Emacs-style key-binding mode for TextPrompt.
 *
 * Key bindings (in addition to TextPrompt defaults — Enter, Tab, Backspace,
 * Delete, arrows, Ctrl+U/K/W/C and friends still come from
 * TextPrompt::handleKeyDirect()):
 * - Ctrl+A     → move cursor to line start
 * - Ctrl+E     → move cursor to line end
 * - Ctrl+B     → move cursor left (back)
 * - Ctrl+F     → move cursor right (forward)
 * - Alt+B      → move one word backward
 * - Alt+F      → move one word forward
 * - Alt+D      → delete word after cursor
 * - Ctrl+W     → delete word before cursor
 * - Ctrl+T     → transpose characters (readline(3) semantics, see transposeChars())
 * - Ctrl+L     → no-op (clear screen — TTY level)
 * - Ctrl+P     → history previous (Up arrow)
 * - Ctrl+N     → history next (Down arrow)
 * - Ctrl+R     → reverse incremental history search
 * - Ctrl+S     → forward incremental history search
 *
 * Meta (Alt) chords arrive in two shapes and both are accepted: terminals
 * with meta-sends-escape send Escape followed by the letter, and
 * kitty-keyboard terminals report the Alt modifier directly (mapped to
 * 'alt_b'/'alt_f'/'alt_d'). Escape by itself arms the meta prefix; a second
 * Escape cancels it (like GNU readline) — while the prefix is armed, Escape
 * does NOT abort. Callers who want Escape to quit should leave this mode
 * unattached, where Escape stays bound to abort.
 *
 * Mirrors erikgeiser/promptkit emacs mode.
 */
final class EmacsMode implements ModeInterface
{
    public function __construct()
    {
    }

    public function name(): string
    {
        return 'emacs';
    }

    public function consumesCharacters(): bool
    {
        // Only while a meta prefix is armed: the next letter is an Alt chord,
        // not a character to insert.
        return $this->altPrefix;
    }

    public function handleKey(TextPrompt $prompt, string $key): TextPrompt
    {
        $key = Key::normalize($key);

        // Meta chords reported directly by extended-key terminals (kitty):
        // Alt+B / Alt+F / Alt+D — no Escape prefix state involved.
        if (\in_array($key, ['alt_b', 'alt_f', 'alt_d'], true)) {
            return $this->handleAltKey($prompt, substr($key, 4));
        }

        // Ctrl+A → line start
        if ($key === 'ctrl_a') {
            return $this->attachTo($prompt->handleKeyDirect(Key::Home));
        }

        // Ctrl+E → line end
        if ($key === 'ctrl_e') {
            return $this->attachTo($prompt->handleKeyDirect(Key::End));
        }

        // Ctrl+B → move left
        if ($key === 'ctrl_b') {
            return $this->attachTo($prompt->handleKeyDirect(Key::Left));
        }

        // Ctrl+F → move right
        if ($key === 'ctrl_f') {
            return $this->attachTo($prompt->handleKeyDirect(Key::Right));
        }

        // Ctrl+P → history previous (Up)
        if ($key === 'ctrl_p') {
            return $this->attachTo($prompt->handleKeyDirect(Key::Up));
        }

        // Ctrl+N → history next (Down)
        if ($key === 'ctrl_n') {
            return $this->attachTo($prompt->handleKeyDirect(Key::Down));
        }

        // Ctrl+R / Ctrl+S → reverse / forward incremental history search.
        // Once active, TextPrompt::handleKey() intercepts every key before
        // delegating to this mode, so only search entry is handled here.
        if ($key === 'ctrl_r' || $key === Key::CtrlR) {
            return $this->attachTo($prompt->startHistorySearch(TextPrompt::SEARCH_REVERSE));
        }
        if ($key === 'ctrl_s' || $key === Key::CtrlS) {
            return $this->attachTo($prompt->startHistorySearch(TextPrompt::SEARCH_FORWARD));
        }

        // Ctrl+W → delete word before cursor
        if ($key === 'ctrl_w') {
            return $this->attachTo($this->deleteWordBefore($prompt));
        }

        // Ctrl+T → transpose characters
        if ($key === 'ctrl_t') {
            return $this->attachTo($this->transposeChars($prompt));
        }

        // Ctrl+L → clear screen (no-op at prompt level, just keep prompt)
        if ($key === 'ctrl_l') {
            return $this->attachTo($prompt);
        }

        // Escape arms the meta prefix; a second Escape cancels it (GNU
        // readline: ESC ESC aborts the pending meta binding, not the prompt).
        if ($key === Key::Escape) {
            return $this->withAltPrefix(!$this->isAltPrefix())->attachTo($prompt);
        }

        // If we are in Alt-prefix waiting mode, handle Alt+B / Alt+F / Alt+D
        if ($this->isAltPrefix()) {
            return $this->handleAltKey($prompt, $key);
        }

        // Otherwise, delegate to standard TextPrompt handling via handleKeyDirect
        // (handleKey would re-enter the mode causing infinite recursion)
        return $this->attachTo($prompt->handleKeyDirect($key));
    }

    // -------------------------------------------------------------------------
    // Alt-prefix (Escape) handling
    // -------------------------------------------------------------------------

    private bool $altPrefix = false;

    private function isAltPrefix(): bool
    {
        return $this->altPrefix;
    }

    private function withAltPrefix(bool $state): self
    {
        if ($state === $this->altPrefix) {
            return $this;
        }
        $clone = clone $this;
        $clone->altPrefix = $state;
        return $clone;
    }

    private function handleAltKey(TextPrompt $prompt, string $key): TextPrompt
    {
        // Clear alt prefix state
        $next = $this->withAltPrefix(false);

        // Alt+B → word backward
        if ($key === 'b') {
            return $next->attachTo($this->wordBack($prompt));
        }

        // Alt+F → word forward
        if ($key === 'f') {
            return $next->attachTo($this->wordForward($prompt));
        }

        // Alt+D → delete word after cursor
        if ($key === 'd') {
            return $next->attachTo($this->deleteWordAfter($prompt));
        }

        // Unknown Alt+key — no-op
        return $next->attachTo($prompt);
    }

    // -------------------------------------------------------------------------
    // Word movement
    // -------------------------------------------------------------------------

    private function wordForward(TextPrompt $prompt): TextPrompt
    {
        $buffer = $prompt->value();
        $cursor = $prompt->cursor();
        $len = mb_strlen($buffer, 'UTF-8');

        if ($cursor >= $len) {
            return $prompt;
        }

        // Skip current word chars
        while ($cursor < $len && $this->isWordChar($buffer, $cursor)) {
            $cursor++;
        }
        // Skip non-word chars
        while ($cursor < $len && !$this->isWordChar($buffer, $cursor)) {
            $cursor++;
        }

        return $this->moveCursorTo($prompt, $cursor);
    }

    private function wordBack(TextPrompt $prompt): TextPrompt
    {
        $buffer = $prompt->value();
        $cursor = $prompt->cursor();

        if ($cursor <= 0) {
            return $prompt;
        }

        // Skip previous non-word chars
        while ($cursor > 0 && !$this->isWordChar($buffer, $cursor - 1)) {
            $cursor--;
        }
        // Skip word chars
        while ($cursor > 0 && $this->isWordChar($buffer, $cursor - 1)) {
            $cursor--;
        }

        return $this->moveCursorTo($prompt, $cursor);
    }

    private function isWordChar(string $buffer, int $pos): bool
    {
        $char = mb_substr($buffer, $pos, 1, 'UTF-8');
        return $char !== '' && preg_match('/[a-zA-Z0-9_\p{L}]/u', $char) === 1;
    }

    private function moveCursorTo(TextPrompt $prompt, int $position): TextPrompt
    {
        $current = $prompt->cursor();
        $delta = $position - $current;
        if ($delta === 0) {
            return $prompt;
        }
        $key = $delta < 0 ? Key::Left : Key::Right;
        $count = abs($delta);
        foreach (range(1, $count) as $_) {
            $prompt = $prompt->handleKeyDirect($key);
        }
        return $prompt;
    }

    // -------------------------------------------------------------------------
    // Deletion
    // -------------------------------------------------------------------------

    private function deleteWordBefore(TextPrompt $prompt): TextPrompt
    {
        $buffer = $prompt->value();
        $cursor = $prompt->cursor();

        if ($cursor === 0) {
            return $prompt;
        }

        $start = $cursor;

        // Skip non-word chars before cursor
        while ($start > 0 && !$this->isWordChar($buffer, $start - 1)) {
            $start--;
        }
        // Skip word chars
        while ($start > 0 && $this->isWordChar($buffer, $start - 1)) {
            $start--;
        }

        if ($start === $cursor) {
            return $prompt;
        }

        // Delete from $start to $cursor using handleKeyDirect to avoid re-entry
        $clone = clone $prompt;
        $clone = $clone->handleKeyDirect(Key::Home); // move to start
        // Walk cursor from home to $start
        for ($i = 0; $i < $start; $i++) {
            $clone = $clone->handleKeyDirect(Key::Right);
        }
        // Now delete everything from $start to original cursor
        $diff = $cursor - $start;
        for ($i = 0; $i < $diff; $i++) {
            $clone = $clone->handleKeyDirect(Key::Delete);
        }

        return $clone;
    }

    private function deleteWordAfter(TextPrompt $prompt): TextPrompt
    {
        $buffer = $prompt->value();
        $cursor = $prompt->cursor();
        $len = mb_strlen($buffer, 'UTF-8');

        if ($cursor >= $len) {
            return $prompt;
        }

        $end = $cursor;

        // Skip word chars
        while ($end < $len && $this->isWordChar($buffer, $end)) {
            $end++;
        }
        // Skip non-word chars
        while ($end < $len && !$this->isWordChar($buffer, $end)) {
            $end++;
        }

        if ($end === $cursor) {
            return $prompt;
        }

        // Apply the deletion by calling handleKeyDirect to avoid re-entry
        $diff = $end - $cursor;
        $p = $prompt;
        for ($i = 0; $i < $diff; $i++) {
            $p = $p->handleKeyDirect(Key::Delete);
        }
        return $p;
    }

    // -------------------------------------------------------------------------
    // Transpose
    // -------------------------------------------------------------------------

    /**
     * Transpose the character before the cursor with the character at the
     * cursor, following readline(3) `transpose-chars` semantics:
     *
     * - Cursor in the middle (0 < cursor < length): swap buffer[cursor-1]
     *   and buffer[cursor]; the cursor ends just after the swapped pair.
     * - Cursor at end of line (length > 0): swap the last two characters
     *   and leave the cursor at end of line.
     * - Empty buffer, single character, or cursor at column 0: no-op.
     *
     * Implemented as a key replay (rewind to the pair, two Deletes, two
     * inserts) so buffer/cursor/undo stay inside TextPrompt's own mutation
     * path — modes never touch prompt state directly.
     */
    private function transposeChars(TextPrompt $prompt): TextPrompt
    {
        $buffer = $prompt->value();
        $cursor = $prompt->cursor();
        $len = mb_strlen($buffer, 'UTF-8');

        // Need at least 2 characters, and a character to the left of the
        // cursor — transpose at column 0 has nothing before it to swap.
        if ($len < 2 || $cursor === 0) {
            return $prompt;
        }

        // Index of the FIRST character of the pair to swap. At end of line
        // that is the last pair; in the middle it is the pair straddling the
        // cursor (the character before it and the character under it).
        $pairStart = $cursor >= $len ? $len - 2 : $cursor - 1;

        $first  = mb_substr($buffer, $pairStart, 1, 'UTF-8');
        $second = mb_substr($buffer, $pairStart + 1, 1, 'UTF-8');

        // Rewind to the pair start, delete both characters forward, then
        // re-insert them swapped. Each Delete consumes the character AT the
        // cursor, and each handleChar appends at the cursor, so the cursor
        // lands immediately after the swapped pair.
        $p = $prompt->handleKeyDirect(Key::Home);
        for ($i = 0; $i < $pairStart; $i++) {
            $p = $p->handleKeyDirect(Key::Right);
        }
        $p = $p->handleKeyDirect(Key::Delete);
        $p = $p->handleKeyDirect(Key::Delete);
        $p = $p->handleChar($second);
        $p = $p->handleChar($first);

        return $p;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function attachTo(TextPrompt $prompt): TextPrompt
    {
        return $prompt->withMode($this);
    }
}

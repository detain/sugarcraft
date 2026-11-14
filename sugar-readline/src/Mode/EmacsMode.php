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
 * Not a port: erikgeiser/promptkit has no emacs mode — the repo research
 * table (`docs/research/libraries/sugar-readline-research.md`, capability
 * census) records promptkit as ❌ for vi/emacs/custom bindings. This mode
 * is a beyond-upstream addition implementing the GNU readline chord family;
 * the behavioural contract is the README's "Editing modes (Vi vs Emacs)"
 * section.
 */
final class EmacsMode implements ModeInterface
{
    /** Whether the meta (Escape) prefix is currently armed. */
    private bool $altPrefix = false;

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
        if ($key === Key::CtrlA) {
            return $this->attachTo($prompt->handleKeyDirect(Key::Home));
        }

        // Ctrl+E → line end
        if ($key === Key::CtrlE) {
            return $this->attachTo($prompt->handleKeyDirect(Key::End));
        }

        // Ctrl+B → move left
        if ($key === Key::CtrlB) {
            return $this->attachTo($prompt->handleKeyDirect(Key::Left));
        }

        // Ctrl+F → move right
        if ($key === Key::CtrlF) {
            return $this->attachTo($prompt->handleKeyDirect(Key::Right));
        }

        // Ctrl+P → history previous (Up)
        if ($key === Key::CtrlP) {
            return $this->attachTo($prompt->handleKeyDirect(Key::Up));
        }

        // Ctrl+N → history next (Down)
        if ($key === Key::CtrlN) {
            return $this->attachTo($prompt->handleKeyDirect(Key::Down));
        }

        // Ctrl+R / Ctrl+S → reverse / forward incremental history search.
        // Once active, TextPrompt::handleKey() intercepts every key before
        // delegating to this mode, so only search entry is handled here.
        if ($key === Key::CtrlR) {
            return $this->attachTo($prompt->startHistorySearch(TextPrompt::SEARCH_REVERSE));
        }
        if ($key === Key::CtrlS) {
            return $this->attachTo($prompt->startHistorySearch(TextPrompt::SEARCH_FORWARD));
        }

        // Ctrl+W → delete word before cursor
        if ($key === Key::CtrlW) {
            return $this->attachTo($this->deleteWordBefore($prompt));
        }

        // Ctrl+T → transpose characters
        if ($key === Key::CtrlT) {
            return $this->attachTo($this->transposeChars($prompt));
        }

        // Ctrl+L → clear screen (no-op at prompt level, just keep prompt)
        if ($key === Key::CtrlL) {
            return $this->attachTo($prompt);
        }

        // Escape arms the meta prefix; a second Escape cancels it (GNU
        // readline: ESC ESC aborts the pending meta binding, not the prompt).
        if ($key === Key::Escape) {
            return $this->withAltPrefix(!$this->isAltPrefix())->attachTo($prompt);
        }

        // If we are in Alt-prefix waiting mode, handle Alt+B / Alt+F / Alt+D
        if ($this->isAltPrefix()) {
            return $this->handleAltKey($prompt, $key, true);
        }

        // Otherwise, delegate to standard TextPrompt handling via handleKeyDirect
        // (handleKey would re-enter the mode causing infinite recursion)
        return $this->attachTo($prompt->handleKeyDirect($key));
    }

    // -------------------------------------------------------------------------
    // Alt-prefix (Escape) handling
    // -------------------------------------------------------------------------

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

    /**
     * Resolve a key arriving under the meta (Alt) prefix.
     *
     * Bound chords (B/F/D) consume the keystroke. An UNBOUND key while the
     * prefix was armed by Escape must not be silently eaten — GNU readline
     * dispatches it as if the Escape had never been typed, which is what
     * keeps Ctrl+C, Enter, arrows and plain characters working between the
     * prefix and its binding ($forwardUnmatched). Kitty-style `alt_x`
     * chords never armed a prefix to begin with, so their unbound members
     * stay no-ops: there is no "the user meant the plain key" story to tell.
     */
    private function handleAltKey(TextPrompt $prompt, string $key, bool $forwardUnmatched = false): TextPrompt
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

        // Unknown Alt+key while armed: the Escape is spent, the key is not.
        // Printable characters land in the buffer (the pump routes real
        // typing through handleChar, and keys reaching this arm are already
        // symbolic names); everything else re-enters normal mode handling
        // with the prefix cleared, so it cannot loop back here.
        if ($forwardUnmatched) {
            $base = $next->attachTo($prompt);
            return mb_strlen($key, 'UTF-8') === 1
                ? $base->handleChar($key)
                : $base->handleKey($key);
        }

        // Unbound kitty-style alt_ chord — no-op.
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

        return $prompt->withCursor($cursor);
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

        return $prompt->withCursor($cursor);
    }

    private function isWordChar(string $buffer, int $pos): bool
    {
        $char = mb_substr($buffer, $pos, 1, 'UTF-8');
        return $char !== '' && preg_match('/[a-zA-Z0-9_\p{L}]/u', $char) === 1;
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

        // One atomic kill: a single undo frame for the whole word
        // (finding 017 - the old replay pushed one frame per character).
        return $prompt->deleteRange($start, $cursor);
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

        // One atomic kill (deleteRange keeps the cursor at $cursor -
        // exactly where the replay chain left it, but in one undo frame).
        return $prompt->deleteRange($cursor, $end);
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
     * Implemented as one range-delete of the pair plus two re-inserts, so
     * buffer/cursor/undo stay inside TextPrompt's own mutation path — modes
     * never touch prompt state directly.
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

        // Delete the pair as one range, then re-insert it swapped: each
        // handleChar appends at the cursor, so the cursor lands right
        // after the swapped pair - the same terminal state the old
        // Home+Right+Delete replay produced, without its per-column clones.
        $p = $prompt->deleteRange($pairStart, $pairStart + 2);

        return $p->handleChar($second)->handleChar($first);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function attachTo(TextPrompt $prompt): TextPrompt
    {
        return $prompt->withMode($this);
    }
}

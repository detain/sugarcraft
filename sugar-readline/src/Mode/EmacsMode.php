<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Mode;

use SugarCraft\Readline\Key;
use SugarCraft\Readline\TextPrompt;

/**
 * Emacs-style key-binding mode for TextPrompt.
 *
 * Key bindings (in addition to TextPrompt defaults):
 * - Ctrl+A     → move cursor to line start
 * - Ctrl+E     → move cursor to line end
 * - Ctrl+B     → move cursor left (back)
 * - Ctrl+F     → move cursor right (forward)
 * - Alt+B      → move one word backward
 * - Alt+F      → move one word forward
 * - Ctrl+W     → delete word before cursor
 * - Alt+D       → delete word after cursor
 * - Ctrl+T      → transpose characters
 * - Ctrl+L      → no-op (clear screen — TTY level)
 * - Ctrl+P     → history previous (Up arrow)
 * - Ctrl+N     → history next (Down arrow)
 *
 * Alt-prefixed keys arrive as Escape prefix followed by the character.
 *
 * Mirrors erikgeiser/promptkit emacs mode.
 */
final class EmacsMode implements ModeInterface
{
    public function __construct(
        private readonly TextPrompt $originalPrompt = new TextPrompt(''),
    ) {}

    public function name(): string
    {
        return 'emacs';
    }

    public function handleKey(TextPrompt $prompt, string $key): TextPrompt
    {
        // Ctrl+A → line start
        if ($key === $this->ctrlChar('a')) {
            return $this->attachTo($prompt->handleKeyDirect(Key::Home));
        }

        // Ctrl+E → line end
        if ($key === $this->ctrlChar('e')) {
            return $this->attachTo($prompt->handleKeyDirect(Key::End));
        }

        // Ctrl+B → move left
        if ($key === $this->ctrlChar('b')) {
            return $this->attachTo($prompt->handleKeyDirect(Key::Left));
        }

        // Ctrl+F → move right
        if ($key === $this->ctrlChar('f')) {
            return $this->attachTo($prompt->handleKeyDirect(Key::Right));
        }

        // Ctrl+P → history previous (Up)
        if ($key === $this->ctrlChar('p')) {
            return $this->attachTo($prompt->handleKeyDirect(Key::Up));
        }

        // Ctrl+N → history next (Down)
        if ($key === $this->ctrlChar('n')) {
            return $this->attachTo($prompt->handleKeyDirect(Key::Down));
        }

        // Ctrl+W → delete word before cursor
        if ($key === $this->ctrlChar('w')) {
            return $this->attachTo($this->deleteWordBefore($prompt));
        }

        // Ctrl+T → transpose characters
        if ($key === $this->ctrlChar('t')) {
            return $this->attachTo($this->transposeChars($prompt));
        }

        // Ctrl+L → clear screen (no-op at prompt level, just keep prompt)
        if ($key === $this->ctrlChar('l')) {
            return $this->attachTo($prompt);
        }

        // Escape prefix for Alt-based bindings
        if ($key === Key::Escape) {
            return $this->withAltPrefix(true)->attachTo($prompt);
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

    private function transposeChars(TextPrompt $prompt): TextPrompt
    {
        $buffer = $prompt->value();
        $cursor = $prompt->cursor();
        $len = mb_strlen($buffer, 'UTF-8');

        // Need at least 2 characters to transpose
        if ($len < 2 || $cursor === 0) {
            return $prompt;
        }

        // At end of buffer: transpose previous two characters
        if ($cursor >= $len) {
            $lastChar = mb_substr($buffer, -1, 1, 'UTF-8');
            $secondLast = mb_substr($buffer, -2, 1, 'UTF-8');
            // Move cursor back one
            $p = $prompt->handleKeyDirect(Key::Left);
            // Delete last char and insert in correct order using handleKeyDirect
            $p = $p->handleKeyDirect(Key::Delete);  // delete last char (now at cursor)
            $p = $p->handleKeyDirect(Key::Left);     // back to second-last position
            $p = $p->handleKeyDirect(Key::Delete);  // delete second-last
            $p = $p->handleChar($secondLast); // insert second-last
            $p = $p->handleChar($lastChar);   // insert last
            return $p;
        }

        // In middle: swap char at cursor with previous char
        $prevChar = mb_substr($buffer, $cursor - 1, 1, 'UTF-8');
        $currChar = mb_substr($buffer, $cursor, 1, 'UTF-8');

        // Simpler approach: move to position, delete both, insert reversed using handleKeyDirect
        $p = $prompt;
        // Go to the character before cursor
        if ($cursor > 1) {
            for ($i = 0; $i < $cursor - 1; $i++) {
                $p = $p->handleKeyDirect(Key::Left);
            }
        } else {
            $p = $p->handleKeyDirect(Key::Left); // at position 0, one left = at 0, cursor now 0
        }
        // Delete two chars and insert reversed
        $p = $p->handleKeyDirect(Key::Delete); // delete prev
        $p = $p->handleKeyDirect(Key::Delete); // delete curr
        $p = $p->handleChar($currChar);
        $p = $p->handleChar($prevChar);
        return $p;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function ctrlChar(string $char): string
    {
        $code = ord($char) - ord('a') + 1;
        return chr($code);
    }

    private function attachTo(TextPrompt $prompt): TextPrompt
    {
        return $prompt->withMode($this);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Mode;

use SugarCraft\Readline\Key;
use SugarCraft\Readline\TextPrompt;

/**
 * Vi-style key-binding mode for TextPrompt.
 *
 * Submodes:
 * - insert: default; ESC enters normal mode; typing inserts characters via TextPrompt
 * - normal: h/l/0/$/b/w move cursor; i/a/A switch to insert mode; dd deletes line
 * - visual: v from normal; movement extends selection (selection stored in prompt mode)
 *
 * Mirrors erikgeiser/promptkit vi mode.
 */
final class ViMode implements ModeInterface
{
    private const VI_MODE_INSERT = 'insert';
    private const VI_MODE_NORMAL = 'normal';
    private const VI_MODE_VISUAL = 'visual';

    /** @var self::VI_MODE_* */
    private string $viMode = self::VI_MODE_INSERT;

    /** Set to a motion character when waiting for motion key (e.g. 'd' + next key). */
    private ?string $pendingMotion = null;

    public function __construct(
        private readonly TextPrompt $originalPrompt = new TextPrompt(''),
    ) {}

    public function name(): string
    {
        return 'vi';
    }

    public function handleKey(TextPrompt $prompt, string $key): TextPrompt
    {
        // Always delegate Escape to normal mode switch
        if ($key === Key::Escape) {
            return $this->withViMode(self::VI_MODE_NORMAL)->attachTo($prompt);
        }

        return match ($this->viMode) {
            self::VI_MODE_INSERT => $this->handleInsertMode($prompt, $key),
            self::VI_MODE_NORMAL => $this->handleNormalMode($prompt, $key),
            self::VI_MODE_VISUAL => $this->handleVisualMode($prompt, $key),
            default               => $prompt,
        };
    }

    // -------------------------------------------------------------------------
    // Insert mode — delegate to TextPrompt, track state
    // -------------------------------------------------------------------------

    private function handleInsertMode(TextPrompt $prompt, string $key): TextPrompt
    {
        // Use handleKeyDirect to avoid infinite recursion through handleKey->mode->handleKey
        $handled = $prompt->handleKeyDirect($key);
        // Re-attach our mode so vi state is preserved
        return $this->attachTo($handled);
    }

    // -------------------------------------------------------------------------
    // Normal mode — vi navigation and actions
    // -------------------------------------------------------------------------

    private function handleNormalMode(TextPrompt $prompt, string $key): TextPrompt
    {
        // Handle pending motion (e.g. 'd' was pressed, waiting for second 'd')
        if ($this->pendingMotion !== null) {
            return $this->resolvePendingMotion($prompt, $key);
        }

        return match ($key) {
            // Movement
            'h' => $this->withViMode(self::VI_MODE_NORMAL)
                ->attachTo($this->moveCursor($prompt, -1)),
            'l' => $this->withViMode(self::VI_MODE_NORMAL)
                ->attachTo($this->moveCursor($prompt, 1)),
            'w' => $this->withViMode(self::VI_MODE_NORMAL)
                ->attachTo($this->wordForward($prompt)),
            'b' => $this->withViMode(self::VI_MODE_NORMAL)
                ->attachTo($this->wordBack($prompt)),
            '0' => $this->withViMode(self::VI_MODE_NORMAL)
                ->attachTo($prompt->handleKeyDirect(Key::Home)),
            '$' => $this->withViMode(self::VI_MODE_NORMAL)
                ->attachTo($prompt->handleKeyDirect(Key::End)),

            // Enter insert mode
            'i' => $this->withViMode(self::VI_MODE_INSERT)->attachTo($prompt),
            'a' => $this->withViMode(self::VI_MODE_INSERT)
                ->attachTo($prompt->handleKeyDirect(Key::Right)),
            'A' => $this->withViMode(self::VI_MODE_INSERT)
                ->attachTo($prompt->handleKeyDirect(Key::End)),

            // Enter visual mode
            'v' => $this->withViMode(self::VI_MODE_VISUAL)->attachTo($prompt),

            // Delete motions
            'd' => $this->withViMode(self::VI_MODE_NORMAL)
                ->withPendingMotion('d')->attachTo($prompt),

            // Yank line (yy) — not implemented yet, just enter insert at line start
            'y' => $this->withViMode(self::VI_MODE_NORMAL)
                ->withPendingMotion('y')->attachTo($prompt),

            // History navigation (Ctrl+P = Up, Ctrl+N = Down)
            "\x10" => $this->withViMode(self::VI_MODE_NORMAL)
                ->attachTo($prompt->handleKeyDirect(Key::Up)),
            "\x0e" => $this->withViMode(self::VI_MODE_NORMAL)
                ->attachTo($prompt->handleKeyDirect(Key::Down)),

            default => $this->withViMode(self::VI_MODE_NORMAL)->attachTo($prompt),
        };
    }

    // -------------------------------------------------------------------------
    // Visual mode — character-wise selection
    // -------------------------------------------------------------------------

    private function handleVisualMode(TextPrompt $prompt, string $key): TextPrompt
    {
        // Escape cancels visual mode back to normal
        if ($key === Key::Escape) {
            return $this->withViMode(self::VI_MODE_NORMAL)->attachTo($prompt);
        }

        // Handle movement keys in visual mode
        $movedPrompt = match ($key) {
            'h' => $this->moveCursor($prompt, -1),
            'l' => $this->moveCursor($prompt, 1),
            'w' => $this->wordForward($prompt),
            'b' => $this->wordBack($prompt),
            '0' => $prompt->handleKeyDirect(Key::Home),
            '$' => $prompt->handleKeyDirect(Key::End),
            default => null,
        };

        if ($movedPrompt !== null) {
            // Still in visual mode, cursor moved for selection
            return $this->withViMode(self::VI_MODE_VISUAL)->attachTo($movedPrompt);
        }

        // ESC already handled above
        return $this->withViMode(self::VI_MODE_VISUAL)->attachTo($prompt);
    }

    // -------------------------------------------------------------------------
    // Pending motion resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve a pending motion (e.g. 'dd' = delete line).
     */
    private function resolvePendingMotion(TextPrompt $prompt, string $key): TextPrompt
    {
        $motion = $this->pendingMotion;
        $nextMode = self::VI_MODE_NORMAL;

        if ($motion === 'd' && $key === 'd') {
            // dd — delete entire line
            $prompt = $this->deleteLine($prompt);
            $nextMode = self::VI_MODE_INSERT;
        } elseif ($motion === 'y' && $key === 'y') {
            // yy — yank line (stored in internal buffer, not yet exposed)
            $nextMode = self::VI_MODE_INSERT;
        }
        // Other motion combinations: not implemented, fall through

        return $this->withViMode($nextMode)->withPendingMotion(null)->attachTo($prompt);
    }

    // -------------------------------------------------------------------------
    // Cursor movement helpers
    // -------------------------------------------------------------------------

    private function moveCursor(TextPrompt $prompt, int $delta): TextPrompt
    {
        $target = $prompt->cursor() + $delta;
        return $this->moveCursorTo($prompt, $target);
    }

    private function wordForward(TextPrompt $prompt): TextPrompt
    {
        // Move cursor to end of next word
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

        // Skip previous word chars (backwards)
        while ($cursor > 0 && !$this->isWordChar($buffer, $cursor - 1)) {
            $cursor--;
        }
        // Skip word chars
        while ($cursor > 0 && $this->isWordChar($buffer, $cursor - 1)) {
            $cursor--;
        }

        return $this->moveCursorTo($prompt, $cursor);
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

    private function deleteLine(TextPrompt $prompt): TextPrompt
    {
        // Go to start of line and delete everything after
        $p = $prompt->handleKeyDirect(Key::Home);
        $p = $p->handleKeyDirect(Key::CtrlK);
        return $p;
    }

    private function isWordChar(string $buffer, int $pos): bool
    {
        $char = mb_substr($buffer, $pos, 1, 'UTF-8');
        return $char !== '' && preg_match('/[a-zA-Z0-9_\p{L}]/u', $char) === 1;
    }

    // -------------------------------------------------------------------------
    // Builder helpers (immutable)
    // -------------------------------------------------------------------------

    private function withViMode(string $viMode): self
    {
        if ($viMode === $this->viMode) {
            return $this;
        }
        $clone = clone $this;
        $clone->viMode = $viMode;
        return $clone;
    }

    private function withPendingMotion(?string $motion): self
    {
        if ($motion === $this->pendingMotion) {
            return $this;
        }
        $clone = clone $this;
        $clone->pendingMotion = $motion;
        return $clone;
    }

    /**
     * Attach this mode to the given prompt, returning a new prompt with the mode set.
     */
    private function attachTo(TextPrompt $prompt): TextPrompt
    {
        return $prompt->withMode($this);
    }

    // -------------------------------------------------------------------------
    // Accessors (for testing)
    // -------------------------------------------------------------------------

    /** Current vi submode name. */
    public function viMode(): string
    {
        return $this->viMode;
    }
}

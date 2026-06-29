<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Mode;

use SugarCraft\Forms\Vim\VimAction;
use SugarCraft\Forms\Vim\VimKeyHandler;
use SugarCraft\Forms\Vim\VimState;
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
 * Uses VimKeyHandler from candy-forms for key-to-action mapping.
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
    // Normal mode — vi navigation and actions (delegates to VimKeyHandler)
    // -------------------------------------------------------------------------

    private function handleNormalMode(TextPrompt $prompt, string $key): TextPrompt
    {
        // Handle pending motion (e.g. 'd' was pressed, waiting for second 'd')
        if ($this->pendingMotion !== null) {
            return $this->resolvePendingMotion($prompt, $key);
        }

        // Normalize key for VimKeyHandler
        $normalizedKey = $this->normalizeKey($key);
        if ($normalizedKey === null) {
            return $this->withViMode(self::VI_MODE_NORMAL)->attachTo($prompt);
        }

        [$normKey, $ctrl] = $normalizedKey;
        $action = VimKeyHandler::handle($normKey, VimState::Normal, VimKeyHandler::FEAT_ALL, $ctrl);

        if ($action === null || $action === VimAction::NoOp) {
            return $this->withViMode(self::VI_MODE_NORMAL)->attachTo($prompt);
        }

        return $this->consumeAction($prompt, $action, $key);
    }

    /**
     * Normalize a sugar-readline key to VimKeyHandler format.
     *
     * @return array{0: string, 1: bool}|null [normalized key, ctrl flag] or null if not handled
     */
    private function normalizeKey(string $key): ?array
    {
        // Handle Ctrl+P/Ctrl+N as history navigation
        if ($key === "\x10") {
            return ['ctrl_p', false];
        }
        if ($key === "\x0e") {
            return ['ctrl_n', false];
        }

        // Single character keys (a-z, 0-9, etc.)
        if (strlen($key) === 1 && ord($key) >= 32 && ord($key) <= 126) {
            $ord = ord($key);
            // Check if it's an uppercase letter (65-90) -> make it lowercase
            if ($ord >= 65 && $ord <= 90) {
                $key = chr($ord + 32); // lowercase
            }
            // Check if it's a special vim key name passed as string
            // But for single chars, just return the lowercase char
            return [$key, false];
        }

        // Special key names
        return match ($key) {
            Key::Left      => ['left', false],
            Key::Right     => ['right', false],
            Key::Up        => ['up', false],
            Key::Down      => ['down', false],
            Key::Home      => ['0', false],     // 0 = beginning of line
            Key::End       => ['$', false],      // $ = end of line
            default        => null,
        };
    }

    /**
     * Consume a VimAction and execute it on the prompt.
     */
    private function consumeAction(TextPrompt $prompt, VimAction $action, string $originalKey): TextPrompt
    {
        $nextMode = $this->viMode;

        return match (true) {
            // State transitions
            $action === VimAction::EnterNormalMode
                => $this->withViMode(self::VI_MODE_NORMAL)->attachTo($prompt),

            $action === VimAction::EnterInsertMode
                => $this->handleEnterInsertMode($prompt, $originalKey),

            $action === VimAction::EnterVisualMode
                => $this->withViMode(self::VI_MODE_VISUAL)->attachTo($prompt),

            // Cursor movements
            $action === VimAction::CursorLeft
                => $this->withViMode(self::VI_MODE_NORMAL)
                    ->attachTo($this->moveCursor($prompt, -1)),

            $action === VimAction::CursorRight
                => $this->withViMode(self::VI_MODE_NORMAL)
                    ->attachTo($this->moveCursor($prompt, 1)),

            $action === VimAction::CursorWordForward
                => $this->withViMode(self::VI_MODE_NORMAL)
                    ->attachTo($this->wordForward($prompt)),

            $action === VimAction::CursorWordBackward
                => $this->withViMode(self::VI_MODE_NORMAL)
                    ->attachTo($this->wordBack($prompt)),

            $action === VimAction::CursorLineStart
                => $this->withViMode(self::VI_MODE_NORMAL)
                    ->attachTo($prompt->handleKeyDirect(Key::Home)),

            $action === VimAction::CursorLineEnd
                => $this->withViMode(self::VI_MODE_NORMAL)
                    ->attachTo($prompt->handleKeyDirect(Key::End)),

            // History navigation
            $action === VimAction::HistoryUp
                => $this->withViMode(self::VI_MODE_NORMAL)
                    ->attachTo($prompt->handleKeyDirect(Key::Up)),

            $action === VimAction::HistoryDown
                => $this->withViMode(self::VI_MODE_NORMAL)
                    ->attachTo($prompt->handleKeyDirect(Key::Down)),

            // Delete motions
            $action === VimAction::DeleteLine
                => $this->withViMode(self::VI_MODE_NORMAL)
                    ->withPendingMotion('d')->attachTo($prompt),

            // Yank line (yy) — pending motion
            $action === VimAction::YankLine
                => $this->withViMode(self::VI_MODE_NORMAL)
                    ->withPendingMotion('y')->attachTo($prompt),

            default
                => $this->withViMode(self::VI_MODE_NORMAL)->attachTo($prompt),
        };
    }

    /**
     * Handle EnterInsertMode with cursor adjustments for a/A/I.
     */
    private function handleEnterInsertMode(TextPrompt $prompt, string $originalKey): TextPrompt
    {
        // Normalize the key for comparison
        $normKey = strlen($originalKey) === 1 ? strtolower($originalKey) : $originalKey;

        // 'a' = append (move cursor right before entering insert mode)
        if ($normKey === 'a') {
            $prompt = $prompt->handleKeyDirect(Key::Right);
        }
        // 'A' = append at end of line
        elseif ($normKey === 'A') {
            $prompt = $prompt->handleKeyDirect(Key::End);
        }
        // 'I' = insert at beginning of line
        elseif ($normKey === 'I') {
            $prompt = $prompt->handleKeyDirect(Key::Home);
        }
        // 'i' = just enter insert mode at current position

        return $this->withViMode(self::VI_MODE_INSERT)->attachTo($prompt);
    }

    // -------------------------------------------------------------------------
    // Visual mode — character-wise selection (delegates to VimKeyHandler)
    // -------------------------------------------------------------------------

    private function handleVisualMode(TextPrompt $prompt, string $key): TextPrompt
    {
        // Escape cancels visual mode back to normal
        if ($key === Key::Escape) {
            return $this->withViMode(self::VI_MODE_NORMAL)->attachTo($prompt);
        }

        // Normalize key for VimKeyHandler
        $normalizedKey = $this->normalizeKey($key);
        if ($normalizedKey === null) {
            return $this->withViMode(self::VI_MODE_VISUAL)->attachTo($prompt);
        }

        [$normKey] = $normalizedKey;
        $action = VimKeyHandler::handle($normKey, VimState::Visual, VimKeyHandler::FEAT_VISUAL, false);

        if ($action === null || $action === VimAction::NoOp) {
            return $this->withViMode(self::VI_MODE_VISUAL)->attachTo($prompt);
        }

        // Execute the action in visual mode
        return match (true) {
            $action === VimAction::CursorLeft
                => $this->withViMode(self::VI_MODE_VISUAL)
                    ->attachTo($this->moveCursor($prompt, -1)),

            $action === VimAction::CursorRight
                => $this->withViMode(self::VI_MODE_VISUAL)
                    ->attachTo($this->moveCursor($prompt, 1)),

            $action === VimAction::CursorWordForward
                => $this->withViMode(self::VI_MODE_VISUAL)
                    ->attachTo($this->wordForward($prompt)),

            $action === VimAction::CursorWordBackward
                => $this->withViMode(self::VI_MODE_VISUAL)
                    ->attachTo($this->wordBack($prompt)),

            $action === VimAction::CursorLineStart
                => $this->withViMode(self::VI_MODE_VISUAL)
                    ->attachTo($prompt->handleKeyDirect(Key::Home)),

            $action === VimAction::CursorLineEnd
                => $this->withViMode(self::VI_MODE_VISUAL)
                    ->attachTo($prompt->handleKeyDirect(Key::End)),

            default
                => $this->withViMode(self::VI_MODE_VISUAL)->attachTo($prompt),
        };
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
            // dd — delete entire line; stay in normal mode
            $prompt = $this->deleteLine($prompt);
        } elseif ($motion === 'y' && $key === 'y') {
            // yy — yank line (stored in internal buffer, not yet exposed); stay in normal mode
        }
        // Other motion combinations: not implemented, fall through
        // $nextMode stays VI_MODE_NORMAL

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

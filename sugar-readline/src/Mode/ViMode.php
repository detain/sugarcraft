<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Mode;

use SugarCraft\Forms\Vim\TextObject;
use SugarCraft\Forms\Vim\TextObjectScope;
use SugarCraft\Forms\Vim\VimAction;
use SugarCraft\Forms\Vim\VimKeyHandler;
use SugarCraft\Forms\Vim\VimOperator;
use SugarCraft\Forms\Vim\VimState;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\TextPrompt;

/**
 * Vi-style key-binding mode for TextPrompt.
 *
 * Submodes:
 * - insert: default; ESC enters normal mode; typing inserts characters via TextPrompt
 * - normal: h/l/0/$/b/w move cursor; i/a/A switch to insert mode; dd deletes line;
 *   text objects c/d/y + i/a + target (ci" di( da{ yiw ...) via candy-forms TextObject
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

    /** Set when a pending operator was followed by i/a — waiting for the text-object target key. */
    private ?TextObjectScope $pendingScope = null;

    public function __construct(
        private readonly TextPrompt $originalPrompt = new TextPrompt(''),
    ) {
    }

    public function name(): string
    {
        return 'vi';
    }

    public function handleKey(TextPrompt $prompt, string $key): TextPrompt
    {
        // Always delegate Escape to normal mode switch. Standard vi pulls the
        // cursor back onto the last character when leaving insert mode at EOL.
        if ($key === Key::Escape) {
            // Escape also cancels any half-typed operator sequence (d…, ci…).
            return $this->withViMode(self::VI_MODE_NORMAL)
                ->withPendingMotion(null)
                ->withPendingScope(null)
                ->attachTo($this->clampToLastChar($prompt));
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
                    ->attachTo($this->clampToLastChar($prompt->handleKeyDirect(Key::End))),

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

            // Change (cc / c + text object) — pending motion
            $action === VimAction::ChangeLine
                => $this->withViMode(self::VI_MODE_NORMAL)
                    ->withPendingMotion('c')->attachTo($prompt),

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
     * Resolve a pending motion (e.g. 'dd' = delete line, 'ci"' text object).
     */
    private function resolvePendingMotion(TextPrompt $prompt, string $key): TextPrompt
    {
        // Operator + i/a already consumed — this key is the text-object target.
        if ($this->pendingScope !== null) {
            return $this->resolveTextObject($prompt, $key);
        }

        $motion = $this->pendingMotion;

        // i/a after an operator starts a text object (ci", da{, yiw ...).
        $scope = TextObjectScope::fromKey($key);
        if ($scope !== null && $motion !== null && VimOperator::fromKey($motion) !== null) {
            return $this->withPendingScope($scope)->attachTo($prompt);
        }

        $nextMode = self::VI_MODE_NORMAL;

        if ($motion === 'd' && $key === 'd') {
            // dd — delete entire line; stay in normal mode
            $prompt = $this->deleteLine($prompt);
        } elseif ($motion === 'c' && $key === 'c') {
            // cc — change entire line: clear it and enter insert mode
            $prompt = $this->deleteLine($prompt);
            $nextMode = self::VI_MODE_INSERT;
        } elseif ($motion === 'y' && $key === 'y') {
            // yy — yank line (stored in internal buffer, not yet exposed); stay in normal mode
        }
        // Other motion combinations: not implemented, fall through
        // $nextMode stays VI_MODE_NORMAL

        return $this->withViMode($nextMode)->withPendingMotion(null)->attachTo($prompt);
    }

    /**
     * Resolve the final key of an operator + i/a + target sequence via
     * candy-forms (VimKeyHandler::handleTextObject). Unresolvable objects
     * (unknown target, unmatched delimiter, cursor outside every pair)
     * cancel the sequence with no buffer change, like vim's beep.
     */
    private function resolveTextObject(TextPrompt $prompt, string $key): TextPrompt
    {
        $operator = VimOperator::fromKey($this->pendingMotion ?? '');
        $scope = $this->pendingScope;
        $cleared = $this->withPendingMotion(null)->withPendingScope(null);

        if ($operator === null || $scope === null) {
            return $cleared->withViMode(self::VI_MODE_NORMAL)->attachTo($prompt);
        }

        [$action, $range] = VimKeyHandler::handleTextObject(
            $operator,
            $scope,
            $key,
            $prompt->value(),
            $prompt->cursor(),
        );

        if ($range === null) {
            return $cleared->withViMode(self::VI_MODE_NORMAL)->attachTo($prompt);
        }

        return match ($action) {
            // diw / di( / da" ... — remove the range, cursor rests at its start
            VimAction::DeleteTextObject
                => $cleared->withViMode(self::VI_MODE_NORMAL)
                    ->attachTo($this->clampToLastChar($this->deleteRange($prompt, $range))),

            // ciw / ci" ... — remove the range and type in its place
            VimAction::ChangeTextObject
                => $cleared->withViMode(self::VI_MODE_INSERT)
                    ->attachTo($this->deleteRange($prompt, $range)),

            // yiw / ya( ... — buffer untouched; cursor moves to the object's
            // start per vim. No register yet, matching existing yy behavior.
            VimAction::YankTextObject
                => $cleared->withViMode(self::VI_MODE_NORMAL)
                    ->attachTo($this->moveCursorTo($prompt, $range->start)),

            default
                => $cleared->withViMode(self::VI_MODE_NORMAL)->attachTo($prompt),
        };
    }

    /**
     * Delete a resolved character range from the prompt buffer, leaving
     * the cursor at the range start. Uses key replay (move + backspace),
     * consistent with deleteLine()'s Home+CtrlK approach.
     */
    private function deleteRange(TextPrompt $prompt, TextObject $range): TextPrompt
    {
        $count = $range->end - $range->start;
        if ($count <= 0) {
            return $this->moveCursorTo($prompt, $range->start);
        }

        $prompt = $this->moveCursorTo($prompt, $range->end);
        for ($i = 0; $i < $count; $i++) {
            $prompt = $prompt->handleKeyDirect(Key::Backspace);
        }
        return $prompt;
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

    /**
     * Vi normal mode rests the cursor ON the last character, never past it
     * (unlike readline End, which sits after it). Pull back one column when
     * the cursor overshoots a non-empty buffer.
     */
    private function clampToLastChar(TextPrompt $prompt): TextPrompt
    {
        $len = mb_strlen($prompt->value(), 'UTF-8');
        if ($len > 0 && $prompt->cursor() >= $len) {
            return $prompt->handleKeyDirect(Key::Left);
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

    private function withPendingScope(?TextObjectScope $scope): self
    {
        if ($scope === $this->pendingScope) {
            return $this;
        }
        $clone = clone $this;
        $clone->pendingScope = $scope;
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

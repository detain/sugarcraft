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
 * - normal: h/l/0/$/b/w move cursor; j/k step history; i/a/A/I switch to
 *   insert mode; x deletes and p puts via the unnamed register; u undoes;
 *   Enter submits; dd deletes line; yy yanks it;
 *   text objects c/d/y + i/a + target (ci" di( da{ yiw ...) via sugar-forms TextObject
 * - visual: v from normal; movement keys reposition the cursor, but the
 *   selection itself is neither rendered nor operated on yet (motion-only
 *   scaffold — there is no d/y-over-selection yet)
 *
 * Uses VimKeyHandler from sugar-forms for key-to-action mapping.
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

    /** Vi's unnamed register: the last text deleted or yanked in normal mode. */
    private string $register = '';

    public function __construct()
    {
    }

    public function name(): string
    {
        return 'vi';
    }

    public function handleKey(TextPrompt $prompt, string $key): TextPrompt
    {
        // Canonicalize once: callers may feed raw control bytes or symbolic
        // names (Key::normalize is idempotent, so both reach the same arms).
        $key = Key::normalize($key);

        // ESC+letter merged into one event ('alt_x' — what sugar-input
        // reports when meta and body arrive in the same read chunk): vi
        // reads that as Escape, THEN the letter as a command.
        if (str_starts_with($key, 'alt_')) {
            $letter = substr($key, 4);
            if ($letter !== '' && mb_strlen($letter, 'UTF-8') === 1) {
                $normal = $this->withViMode(self::VI_MODE_NORMAL)
                    ->withPendingMotion(null)
                    ->withPendingScope(null);
                return $normal->handleKey($normal->clampToLastChar($prompt), $letter);
            }
        }

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

    public function consumesCharacters(): bool
    {
        // Normal and visual submodes read plain letters as vi commands
        // (h/l/i/dd/ci"…), not as text to insert. Insert mode types normally.
        return $this->viMode !== self::VI_MODE_INSERT;
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

        // Enter in normal mode is readline's RET: submit. VimKeyHandler maps
        // no Enter, so without this arm a vi user could never finish the
        // exchange from the keyboard.
        if ($key === Key::Enter) {
            return $this->withViMode(self::VI_MODE_NORMAL)
                ->attachTo($prompt->handleKeyDirect(Key::Enter));
        }

        // Incremental search starts in ANY mode (documented contract): vi
        // normal routes Ctrl+R / Ctrl+S straight to the prompt's search arms.
        if ($key === Key::CtrlR || $key === Key::CtrlS) {
            return $this->withViMode(self::VI_MODE_NORMAL)
                ->attachTo($prompt->handleKeyDirect($key));
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
        // Handle Ctrl+P/Ctrl+N as history navigation (symbolic — raw bytes
        // were canonicalized by handleKey()'s normalize).
        if ($key === 'ctrl_p') {
            return ['ctrl_p', false];
        }
        if ($key === 'ctrl_n') {
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
            Key::Space     => ['right', false],  // bare space moves right, like 'l'
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

            // j/k — on a single-line prompt vim's line-down/up have no other
            // meaning, so they walk history exactly like the arrow keys.
            $action === VimAction::CursorDown
                => $this->withViMode(self::VI_MODE_NORMAL)
                    ->attachTo($prompt->handleKeyDirect(Key::Down)),

            $action === VimAction::CursorUp
                => $this->withViMode(self::VI_MODE_NORMAL)
                    ->attachTo($prompt->handleKeyDirect(Key::Up)),

            // x — delete the character under the cursor into the register.
            $action === VimAction::DeleteChar
                => $this->deleteChar($prompt),

            // u — undo the most recent buffer change.
            $action === VimAction::Undo
                => $this->withViMode(self::VI_MODE_NORMAL)
                    ->attachTo($prompt->handleKeyDirect(Key::Undo)),

            // p — put the register after the cursor.
            $action === VimAction::Paste
                => $this->putRegister($prompt),

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
        // A and I are distinct commands — compare the ORIGINAL key before any
        // case folding. VimKeyHandler lowercases when it maps a/A/i/I all to
        // EnterInsertMode, so the caller's key is the only signal left; the
        // old pre-comparison strtolower made the A/I branches dead code.
        if ($originalKey === 'A') {
            $prompt = $prompt->handleKeyDirect(Key::End);
        } elseif ($originalKey === 'I') {
            $prompt = $prompt->handleKeyDirect(Key::Home);
        } elseif ($originalKey === 'a') {
            // 'a' = append (move cursor right before entering insert mode)
            $prompt = $prompt->handleKeyDirect(Key::Right);
        }
        // 'i' = just enter insert mode at current position

        return $this->withViMode(self::VI_MODE_INSERT)->attachTo($prompt);
    }

    /** x: delete the character under the cursor, storing it in the register. */
    private function deleteChar(TextPrompt $prompt): TextPrompt
    {
        $value = $prompt->value();
        $cursor = $prompt->cursor();
        if ($cursor >= mb_strlen($value, 'UTF-8')) {
            // Nothing under the cursor (past EOL): vim beeps, we stay put.
            return $this->withViMode(self::VI_MODE_NORMAL)->attachTo($prompt);
        }

        $deleted = mb_substr($value, $cursor, 1, 'UTF-8');
        $after = $prompt
            ->handleKeyDirect(Key::Right)
            ->handleKeyDirect(Key::Backspace);

        return $this
            ->withRegister($deleted)
            ->withViMode(self::VI_MODE_NORMAL)
            ->attachTo($after);
    }

    /** p: put the register after the cursor as literal text. */
    private function putRegister(TextPrompt $prompt): TextPrompt
    {
        if ($this->register === '') {
            return $this->withViMode(self::VI_MODE_NORMAL)->attachTo($prompt);
        }

        $after = $prompt
            ->handleKeyDirect(Key::Right)
            ->insertPastedText($this->register);

        return $this->withViMode(self::VI_MODE_NORMAL)->attachTo($after);
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
        $register = $this->register;

        if ($motion === 'd' && $key === 'd') {
            // dd — delete entire line (into the register), stay in normal mode
            $register = $prompt->value();
            $prompt = $this->deleteLine($prompt);
        } elseif ($motion === 'c' && $key === 'c') {
            // cc — change entire line: clear it (into the register) and type
            $register = $prompt->value();
            $prompt = $this->deleteLine($prompt);
            $nextMode = self::VI_MODE_INSERT;
        } elseif ($motion === 'y' && $key === 'y') {
            // yy — copy the whole line into the unnamed register; stay normal
            $register = $prompt->value();
        }
        // Other motion combinations: not implemented, fall through
        // $nextMode stays VI_MODE_NORMAL

        return $this
            ->withRegister($register)
            ->withViMode($nextMode)
            ->withPendingMotion(null)
            ->attachTo($prompt);
    }

    /**
     * Resolve the final key of an operator + i/a + target sequence via
     * sugar-forms (VimKeyHandler::handleTextObject). Unresolvable objects
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

        $rangeText = mb_substr(
            $prompt->value(),
            $range->start,
            max(0, $range->end - $range->start),
            'UTF-8',
        );

        return match ($action) {
            // diw / di( / da" ... — remove the range (into the register),
            // cursor rests at its start
            VimAction::DeleteTextObject
                => $cleared->withRegister($rangeText)->withViMode(self::VI_MODE_NORMAL)
                    ->attachTo($this->clampToLastChar($this->deleteRange($prompt, $range))),

            // ciw / ci" ... — remove the range (into the register) and type in its place
            VimAction::ChangeTextObject
                => $cleared->withRegister($rangeText)->withViMode(self::VI_MODE_INSERT)
                    ->attachTo($this->deleteRange($prompt, $range)),

            // yiw / ya( ... — copy the object into the unnamed register and
            // move the cursor to its start, per vim. The buffer is untouched.
            VimAction::YankTextObject
                => $cleared->withRegister($rangeText)->withViMode(self::VI_MODE_NORMAL)
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

    private function withRegister(string $register): self
    {
        if ($register === $this->register) {
            return $this;
        }
        $clone = clone $this;
        $clone->register = $register;
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

    /** Vi's unnamed register: the last text deleted or yanked in normal mode. */
    public function register(): string
    {
        return $this->register;
    }
}

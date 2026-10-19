<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\TextArea\TextArea;

/**
 * The vim-operator state the COMPOSER keeps above the sugar-forms Vim layer
 * (plan_comparison.md §3 TUI-3 Cut A).
 *
 * WHY THIS CLASS EXISTS AT ALL — the layering decision. sugar-forms' TextArea
 * (w5f) owns the STATE MACHINE: `$vimMode`, `VimState`, i/a/I/A/Esc,
 * h j k l w b 0 $ x, v/V visual selection and the insert-mode passthrough are
 * all resolved INSIDE the widget, and this class never duplicates one of them.
 * What the widget deliberately does NOT own — per its own consumeVimAction
 * docblock, "the pending-operator and clipboard family … fall through
 * untouched" — is the two-keystroke operator grammar: `dd`, `dw`, `yy`, `p`.
 * That grammar spans keystrokes, and its natural home is the host that also
 * owns the draft's meaning (submitting, popups, the queue), which is Chat.
 * So the split is: lib = mode machine + single-key motions; app = operator
 * state + register. This class is that app-side half, kept PURE — every entry
 * point takes the widget and returns a new one; nothing here reads a setting,
 * a clock or a file.
 *
 * The motions the lib already binds (w b h l $ 0) are resolved for operator
 * ranges by DELEGATING the key to the widget and reading the caret it moved
 * to — not by re-implementing the boundary. `e` is computed here because the
 * lib binds no word-END; its word characters match the lib's `[a-zA-Z0-9_]`
 * exactly, so `dw` and `de` agree with the same notion of "word" the plain
 * `w` motion uses. `j`/`k`/`<CR>` resolve LINE-wise directly (reading the
 * public `$lines`), because delegating the caret there would fight the
 * column-preserving clamp and the line-spanning lib `e` never reaches.
 *
 * Deliberately NOT implemented (recorded, not silently skipped): counts
 * (`3dd`), the change operator (`cw` — it would have to reach into Insert
 * state the same way `o` does, after a delete, for one more keystroke of
 * ambiguity), text objects (`di"`), and the named registers. The plan's
 * minimum key set — i a I A o O Esc h j k l w b e 0 $ x dd D v V d y — plus
 * the paste that makes `y` observable (`p`/`P`) is the contract, and an
 * unbound continuation is a BEEP: the pending operator cancels and the key
 * is swallowed, exactly like the lib's own "beep (no-op) on anything it
 * declines to translate".
 *
 * Immutability: `pending` is `'d'`/`'y'` or null; `register` holds the last
 * killed/yanked text and `registerLinewise` says how `p` should re-insert it.
 * The register is carried across operator cycles and across clones (it rides
 * Chat's `mutate()` by value); a submit does not clear it, same as a turn
 * does not clear the draft's kill-ring in any editor.
 */
final class ComposerVim
{
    /**
     * Runes this class claims from Chat's delegation path while NORMAL state
     * has no operator pending: `d`/`y` ARM an operator, the rest act at once.
     * Every other printable belongs to the lib (`i a I A h j k l w b 0 $ x v V`
     * are bound there) or is a normal-mode beep the lib already swallows
     * (`o O e p P D Y` fall through its handler as unbound NoOps — which is
     * precisely the gap this class fills).
     */
    private const CLAIMED_RUNES = ['d', 'y', 'D', 'Y', 'o', 'O', 'e', 'p', 'P'];

    /**
     * Char continuations resolved as character-wise motions: the caret after
     * delegating the key to the widget marks the far end of the span. `$` and
     * `0` ride the same rule (line end / line start are just motions).
     */
    private const CHAR_MOTIONS = ['w', 'b', 'h', 'l', '$', '0'];

    public function __construct(
        public readonly ?string $pending = null,
        public readonly string $register = '',
        public readonly bool $registerLinewise = false,
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    /** Same register, no pending operator — the "forgotten `d`" cleanup the caller re-routes with. */
    public function pendingCleared(): self
    {
        return new self(null, $this->register, $this->registerLinewise);
    }

    /** @return $this */
    private function with(
        ?string $pending = null,
        ?string $register = null,
        ?bool $registerLinewise = null,
    ): self {
        return new self(
            $pending,
            $register ?? $this->register,
            $registerLinewise ?? $this->registerLinewise,
        );
    }

    /** Whether the app layer claims this plain Char in NORMAL state. */
    public static function claims(string $rune): bool
    {
        return \in_array($rune, self::CLAIMED_RUNES, true);
    }

    /**
     * A key arrived while an operator is pending.
     *
     * Returns [widget, state, $handled]. `$handled === false` means the key
     * is NOT vim-operator material at all (Tab, an arrow, a ctrl chord, a
     * paste): the CALLER must clear `pending` and re-route the key so the
     * existing arm for it runs unchanged — this is what keeps the palette,
     * the popups and the queue owning their own keys one keystroke after a
     * forgotten `d`. Escape always cancels HERE (handled): while a modal owns
     * the keys the caller's own gate keeps this method out of the way, so
     * reaching it means there is nothing more specific for Escape to close.
     *
     * @return array{TextArea, self, bool}
     */
    public static function resolve(KeyMsg $key, TextArea $input, self $state): array
    {
        $op = $state->pending;
        if ($op === null) {
            return [$input, $state, false];
        }

        if ($key->type === KeyType::Escape) {
            return [$input, $state->with(pending: null), true];
        }

        if ($key->type !== KeyType::Char || $key->ctrl || $key->alt) {
            // Enter is the one non-Char key with a vim meaning under an
            // operator (`d<CR>` kills the current line); Chat routes it here
            // only when no popup owns Enter, and the caller re-routes it on
            // `false` otherwise. Everything else re-routes after the cancel.
            if ($key->type === KeyType::Enter && !$key->alt && !$key->shift && !$key->ctrl) {
                [$out, $done] = self::applyLinewise($op, $input, $state, $input->row, $input->row);

                return [$out, $done, true];
            }

            return [$input, $state->with(pending: null), false];
        }

        $rune = $key->rune;

        // dd / yy — the doubled operator is whole-line, the classic spelling.
        if ($rune === $op) {
            [$out, $done] = self::applyLinewise($op, $input, $state, $input->row, $input->row);

            return [$out, $done, true];
        }

        // d j / y k span the caret line plus one, line-wise.
        if ($rune === 'j' || $rune === 'k') {
            $last = $input->lineCount() - 1;
            $other = $rune === 'j' ? min($last, $input->row + 1) : max(0, $input->row - 1);
            if ($other === $input->row) {
                return [$input, $state->with(pending: null), true]; // beep: nowhere to go
            }

            [$out, $done] = self::applyLinewise($op, $input, $state, min($input->row, $other), max($input->row, $other));

            return [$out, $done, true];
        }

        if ($rune === 'e') {
            $end = self::wordEndCol($input->lines[$input->row] ?? '', $input->col);
            [$out, $done] = self::applyCharwise($op, $input, $state, $end);

            return [$out, $done, true];
        }

        if (\in_array($rune, self::CHAR_MOTIONS, true)) {
            // Ask the lib for the boundary by MOVING there, then read the
            // caret — the motion algorithm stays the widget's one definition.
            [$moved] = $input->update($key);
            if ($moved->row === $input->row && $moved->col === $input->col) {
                return [$input, $state->with(pending: null), true]; // beep: empty motion
            }

            [$out, $done] = self::applySpan($op, $input, $state, $input->row, $input->col, $moved->row, $moved->col);

            return [$out, $done, true];
        }

        // Unknown continuation: beep (consume) and cancel — a stray letter
        // after a forgotten `d` must never type itself or move anything.
        return [$input, $state->with(pending: null), true];
    }

    /**
     * A plain Char in NORMAL state with no operator pending (the caller has
     * the same two guards; this method trusts them). Arming keys return the
     * widget untouched; acting keys edit through the widget's own public
     * surface, and the two Insert-mode entries (`o`/`O`) enter Insert by
     * FEEDING the widget the `i` key — the lib owns its state machine, so
     * this class never pokes `$vimState` (it could not: there is no setter).
     *
     * @return array{TextArea, self}
     */
    public static function start(KeyMsg $key, TextArea $input, self $state): array
    {
        switch ($key->rune) {
            case 'd':
            case 'y':
                return [$input, $state->with(pending: $key->rune)];

            case 'D':
                // d$ — kill caret..EOL, caret clamps by the delete itself.
                return self::applyCharwise('d', $input, $state, self::lineLen($input, $input->row));

            case 'Y':
                // vim's Y is the linewise sibling of yy.
                return self::applyLinewise('y', $input, $state, $input->row, $input->row);

            case 'e':
                // Bare `e` is a motion: caret to the end of the word ahead.
                $end = self::wordEndCol($input->lines[$input->row] ?? '', $input->col);

                return [$input->setCursor($input->row, $end), $state];

            case 'o':
                return [self::openLine($input, $input->row + 1), $state];

            case 'O':
                return [self::openLine($input, $input->row), $state];

            case 'p':
            case 'P':
                return [self::paste($input, $state, after: $key->rune === 'p'), $state];
        }

        // claims() and this switch must agree; unreachable by construction.
        throw new \LogicException('ComposerVim::start() asked about unclaimed rune ' . $key->rune);
    }

    // ---- operators -------------------------------------------------------

    /**
     * Apply d/y to whole lines `$from`..`$to` inclusive. Delete lands the
     * caret at column 0 of the first surviving line of the killed range
     * (vim's dd behaviour); yank does not move the caret at all. Returns the
     * widget plus the state carrying the fresh register (pending cleared —
     * `with()` defaults it to null).
     *
     * @return array{TextArea, self}
     */
    private static function applyLinewise(string $op, TextArea $input, self $state, int $from, int $to): array
    {
        $lines = $input->lines;
        $killed = \array_slice($lines, $from, $to - $from + 1);
        $next = $state->with(register: implode("\n", $killed), registerLinewise: true);
        if ($op === 'y') {
            return [$input, $next];
        }

        $survivors = \array_merge(\array_slice($lines, 0, $from), \array_slice($lines, $to + 1));
        if ($survivors === []) {
            $survivors = [''];
        }

        $rewritten = $input->setValue(implode("\n", $survivors));

        return [$rewritten->setCursor(min($from, $rewritten->lineCount() - 1), 0), $next];
    }

    /**
     * Character-wise operator from the caret to `$endCol`, motion-end
     * EXCLUSIVE — the same convention the delegated `w`/`b` spans produce
     * (the caret ends ON the first character after the word, which is exactly
     * the range `dw` kills). `e` passes `wordEndCol()`'s one-past-last-letter,
     * so `de` kills the word the caret sits inside, inclusive, as vim does.
     * Backward spans (`db`, `d0`, `dh`) normalize and kill the same text they
     * would if typed forward; `D` reaching EOL is inclusive by nature.
     *
     * @return array{TextArea, self}
     */
    private static function applyCharwise(string $op, TextArea $input, self $state, int $endCol): array
    {
        return self::applySpan($op, $input, $state, $input->row, $input->col, $input->row, $endCol);
    }

    /**
     * Operator over the caret→motion span across (at most one crossing of)
     * rows. Yank never edits or moves; delete splices and lands the caret at
     * the ORIGINAL point (clamped into the surviving text).
     *
     * @param int $r0 start row, int $c0 start col, int $r1 end row, int $c1 end col (exclusive)
     *
     * @return array{TextArea, self}
     */
    private static function applySpan(string $op, TextArea $input, self $state, int $r0, int $c0, int $r1, int $c1): array
    {
        // Normalize a backwards motion (db, d0, dh).
        if ($r1 < $r0 || ($r1 === $r0 && $c1 < $c0)) {
            [$r0, $c0, $r1, $c1] = [$r1, $c1, $r0, $c0];
        }

        $lines = $input->lines;
        $text = $r0 === $r1
            ? mb_substr($lines[$r0], $c0, $c1 - $c0, 'UTF-8')
            : implode("\n", self::spanLines($lines, $r0, $c0, $r1, $c1));
        $next = $state->with(register: $text, registerLinewise: false);
        if ($op === 'y') {
            // The delegated motion already moved the caret; put it back.
            return [$input->setCursor($input->row, $input->col), $next];
        }

        $merged = mb_substr($lines[$r0], 0, $c0, 'UTF-8') . mb_substr($lines[$r1], $c1, null, 'UTF-8');
        $survivors = \array_merge(
            \array_slice($lines, 0, $r0),
            [$merged],
            \array_slice($lines, $r1 + 1),
        );

        $rewritten = $input->setValue(implode("\n", $survivors));

        return [
            $rewritten->setCursor(min($r0, $rewritten->lineCount() - 1), min($c0, self::rowLen($rewritten, min($r0, $rewritten->lineCount() - 1)))),
            $next,
        ];
    }

    /** @param list<string> $lines @return list<string> */
    private static function spanLines(array $lines, int $r0, int $c0, int $r1, int $c1): array
    {
        $out = [mb_substr($lines[$r0], $c0, null, 'UTF-8')];
        for ($r = $r0 + 1; $r < $r1; $r++) {
            $out[] = $lines[$r];
        }
        $out[] = mb_substr($lines[$r1], 0, $c1, 'UTF-8');

        return $out;
    }

    // ---- o/O and p/P ------------------------------------------------------

    /**
     * Open a new blank line at `$atRow` and enter Insert ON it: splice the
     * buffer through the public API, park the caret at column 0, then hand
     * the widget a synthesized `i` — the lib's own EnterInsertMode path, so
     * the state machine stays the lib's property.
     */
    private static function openLine(TextArea $input, int $atRow): TextArea
    {
        $lines = $input->lines;
        $atRow = max(0, min(\count($lines), $atRow));
        $spliced = \array_merge(
            \array_slice($lines, 0, $atRow),
            [''],
            \array_slice($lines, $atRow),
        );

        $placed = $input->setValue(implode("\n", $spliced))->setCursor($atRow, 0);
        [$inserting] = $placed->update(new KeyMsg(KeyType::Char, 'i'));

        return $inserting;
    }

    /**
     * Re-insert the register. Linewise (from dd/yy/Y/d<CR>/dj) inserts the
     * held lines on a NEW row below (`p`) or above (`P`) the caret row and
     * lands the caret at column 0 of the pasted block — a yank of whole lines
     * must not glue text onto the caret's line, that is what charwise is for.
     * Charwise pastes inline: `p` after the caret's column, `P` at it, caret
     * one cell past the pasted text — the cursor conventions of the mode the
     * user just left.
     */
    private static function paste(TextArea $input, self $state, bool $after): TextArea
    {
        if ($state->register === '') {
            return $input;
        }

        $lines = $input->lines;
        if ($state->registerLinewise) {
            $block = explode("\n", $state->register);
            $at = $after ? $input->row + 1 : $input->row;
            $spliced = \array_merge(
                \array_slice($lines, 0, $at),
                $block,
                \array_slice($lines, $at),
            );
            $rewritten = $input->setValue(implode("\n", $spliced));

            return $rewritten->setCursor(min($at, $rewritten->lineCount() - 1), 0);
        }

        $row = $input->row;
        $line = $lines[$row] ?? '';
        $at = $after ? min(mb_strlen($line, 'UTF-8'), $input->col + 1) : $input->col;
        $spliced = mb_substr($line, 0, $at, 'UTF-8') . $state->register . mb_substr($line, $at, null, 'UTF-8');
        $next = [...$lines];
        $next[$row] = $spliced;
        $rewritten = $input->setValue(implode("\n", $next));
        $caret = $at + mb_strlen($state->register, 'UTF-8');

        return $rewritten->setCursor($row, $after ? max(0, $caret - 1) : min($caret, self::rowLen($rewritten, $row)));
    }

    // ---- word end -----------------------------------------------------------

    /**
     * One-past the last character of the word `e` lands ON from `$col`
     * (exclusive-start walk: at least one cell right, skip a punctuation/
     * whitespace run, then consume a word run) — the classic e-boundary over
     * the lib's `[a-zA-Z0-9_]` word characters. `$col` at/after EOL answers
     * `$col` back, so `de`/`d$` at the end of a line produce the empty span
     * the caller beeps on. Line-local, like every other motion here: the
     * lib's `w` is line-local too, and an app-side `e` that crossed rows the
     * `w` cannot would be two notions of word in one composer.
     */
    public static function wordEndCol(string $line, int $col): int
    {
        $len = mb_strlen($line, 'UTF-8');
        if ($col >= $len) {
            return $col;
        }

        $pos = $col + 1;
        while ($pos < $len && !self::isWordChar($line, $pos)) {
            $pos++;
        }
        while ($pos < $len && self::isWordChar($line, $pos)) {
            $pos++;
        }

        return $pos;
    }

    private static function isWordChar(string $line, int $pos): bool
    {
        $char = mb_substr($line, $pos, 1, 'UTF-8');

        return $char !== '' && preg_match('/[a-zA-Z0-9_]/', $char) === 1;
    }

    private static function lineLen(TextArea $input, int $row): int
    {
        return mb_strlen($input->lines[$row] ?? '', 'UTF-8');
    }

    private static function rowLen(TextArea $input, int $row): int
    {
        return mb_strlen($input->lines[$row] ?? '', 'UTF-8');
    }
}

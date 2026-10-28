<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\TextArea\TextArea;
use SugarCraft\Forms\Vim\TextObject;
use SugarCraft\Forms\Vim\TextObjectScope;
use SugarCraft\Forms\Vim\VimKeyHandler;
use SugarCraft\Forms\Vim\VimOperator;

/**
 * The vim-operator state the COMPOSER keeps above the sugar-forms Vim layer
 * (plan_comparison.md §3 TUI-3 Cut A; the C-TUI-1 step added counts, the
 * change operator and text objects).
 *
 * WHY THIS CLASS EXISTS AT ALL — the layering decision. sugar-forms' TextArea
 * (w5f) owns the STATE MACHINE: `$vimMode`, `VimState`, i/a/I/A/Esc,
 * h j k l w b 0 $ x, v/V visual selection and the insert-mode passthrough are
 * all resolved INSIDE the widget, and this class never duplicates one of them.
 * What the widget deliberately does NOT own — per its own consumeVimAction
 * docblock, the "pending-operator and clipboard family" of multi-keystroke
 * NORMAL-mode grammar (`dd`, `dw`, `yy`, `p`, `3dd`, `cw`, `di"`) falls
 * through untouched.
 * What it DOES own since TUI-3A: the visual selection and the operators
 * over it — inside a live selection `d`/`x` delete the span (caret lands
 * at its start) and `y` copies it through the widget's own
 * `Cmd::setClipboard` → OSC 52 channel. That visual yank deliberately
 * does NOT feed THIS class's register — the widget owns no register and
 * must not reach into the host's; `p`/`P` therefore paste only what the
 * NORMAL-mode operators stored. Bridging clipboard→register is the
 * host's decision, recorded as a seam rather than silently widened.
 * That grammar spans keystrokes, and its natural home is the host that also
 * owns the draft's meaning (submitting, popups, the queue), which is Chat.
 * So the split is: lib = mode machine + single-key motions + visual
 * operators + text-object GEOMETRY (`TextObject`, consumed unchanged — the
 * readline ViMode library path already drove it; the composer path starts
 * here); app = pending-operator state + register. This class is that
 * app-side half, kept PURE — every entry
 * point takes the widget and returns a new one; nothing here reads a setting,
 * a clock or a file.
 *
 * The motions the lib already binds (w b h l $ 0) are resolved for operator
 * ranges by DELEGATING the key to the widget and reading the caret it moved
 * to — not by re-implementing the boundary; with a count armed the SAME
 * delegation simply asks the widget again, so `d2w` cuts exactly where two
 * plain `w` presses move. `e` is computed here because the lib binds no
 * word-END; its word characters match the lib's `[a-zA-Z0-9_]`
 * exactly, so `dw` and `de` agree with the same notion of "word" the plain
 * `w` motion uses. `j`/`k`/`<CR>` resolve LINE-wise directly (reading the
 * public `$lines`), because delegating the caret there would fight the
 * column-preserving clamp and the line-spanning lib `e` never reaches.
 *
 * What the C-TUI-1 grammar adds, precisely:
 * - `count`: a digit arms one, further digits append (vim's decimal rule);
 *   it multiplies the doubled operator (`3dd`), the `dj`/`dk` span, `x`, the
 *   pastes and the delegated motions (`3w`, `d2w`). Whole-line conveniences
 *   (`D`/`Y`/`o`/`O`) spend the count without multiplying.
 * - the change operator: `c`, `cc`, `C` and `cw` (vim's rule: bare `cw` is
 *   `ce`; with a count it is the motion span) kill through the SAME span
 *   operator `d` uses — so the change also updates the register, as vim's
 *   does — and then enter Insert by FEEDING the widget `i`, the exact
 *   `openLine()` precedent: this class never pokes `$vimState` (it could
 *   not: there is no setter).
 * - text objects: after an operator, `i`/`a` arm a `pendingScope`; the next
 *   key resolves through the lib's own `VimKeyHandler::handleTextObject()`
 *   on the caret's line and lands in `applySpan()`. No object geometry is
 *   re-implemented app-side.
 * - the `0` ambiguity is settled the way vim settles it: a BARE `0` stays
 *   the lib's line-start motion (`d0` cuts to the head of the line); a `0`
 *   AFTER a digit appends to the count. That is why `claimsKey` is an
 *   instance predicate keyed on the armed count.
 *
 * Deliberately NOT implemented (recorded, not silently skipped): the named
 * registers (`"a` etc.) — `p`/`P` paste the one unnamed register. The plan's
 * minimum key set — i a I A o O Esc h j k l w b e 0 $ x dd D v V d y — plus
 * the paste that makes `y` observable (`p`/`P`) and the C-TUI-1 additions
 * (`c`/`C`, counts, `i`/`a` objects) is the contract. In this
 * set `v`/`V` open the character-wise selection in the widget (V arrives
 * lower-cased and aliases v — line-wise entry awaits the E736 3.2 / R4
 * owner ruling), and `d`/`y` mean two different things by state: inside a
 * live selection they are the widget's span operators (delete / OSC-52
 * copy, TUI-3A), while in NORMAL they arm THIS class's pending grammar
 * whose kill/yank lands in the register that `p`/`P` paste. An
 * unbound continuation is a BEEP: the whole armed grammar cancels and the
 * key is swallowed, exactly like the lib's own "beep (no-op) on anything it
 * declines to translate".
 *
 * Immutability: `pending` is `'d'`/`'y'`/`'c'` or null; `pendingScope` is
 * `'i'`/`'a'` or null while a text object awaits its target; `count` is the
 * accumulated repeat or null. `register` holds the last killed/yanked text
 * and `registerLinewise` says how `p` should re-insert it.
 * The register is carried across operator cycles and across clones (it rides
 * Chat's `mutate()` by value); a submit does not clear it, same as a turn
 * does not clear the draft's kill-ring in any editor.
 */
final class ComposerVim
{
    /**
     * Runes this class claims from Chat's delegation path while NORMAL state
     * has nothing armed: `d`/`y`/`c` ARM an operator, `1`-`9` arm a count,
     * the rest act at once. Every other printable belongs to the lib
     * (`i a I A h j k l w b 0 $ x v V` are bound there) or is a normal-mode
     * beep the lib already swallows (`o O e p P D Y C` fall through its
     * handler as unbound NoOps — which is precisely the gap this class
     * fills).
     */
    private const CLAIMED_RUNES = ['d', 'y', 'c', 'D', 'Y', 'o', 'O', 'e', 'p', 'P', 'C'];

    /** Digits that START a count: `0` cannot — bare `0` is the lib's line-start motion. */
    private const COUNT_LEADING_DIGITS = ['1', '2', '3', '4', '5', '6', '7', '8', '9'];

    /**
     * Keys the lib binds (or this class computes) that ACT through an armed
     * count; claimed only once `count` is non-null, which keeps the unarmed
     * `0`/`w`/`x` ownership byte-identical to the pre-C-TUI-1 claim set.
     * (`d`/`y`/`c` are claimed always and arm the operator keeping the
     * count instead.)
     */
    private const COUNT_ACTUATORS = ['0', 'w', 'b', 'h', 'l', '$', 'j', 'k', 'x', 'e', 'p', 'P', 'D', 'Y', 'o', 'O'];

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
        public readonly ?string $pendingScope = null,
        public readonly ?int $count = null,
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    /** Same register, nothing armed (operator, scope or count) — the "forgotten" cleanup the caller re-routes with. */
    public function pendingCleared(): self
    {
        return new self(null, $this->register, $this->registerLinewise);
    }

    /** Whether ANY part of the multi-keystroke grammar is waiting for its next key. */
    public function isArmed(): bool
    {
        return $this->pending !== null || $this->pendingScope !== null || $this->count !== null;
    }

    /**
     * Clone with fresh grammar fields; `null` means CLEAR for `pending`,
     * `pendingScope` and `count` (callers keep a value by re-passing it),
     * while the register pair inherits unless passed.
     *
     * @return $this
     */
    private function with(
        ?string $pending = null,
        ?string $register = null,
        ?bool $registerLinewise = null,
        ?string $pendingScope = null,
        ?int $count = null,
    ): self {
        return new self(
            $pending,
            $register ?? $this->register,
            $registerLinewise ?? $this->registerLinewise,
            $pendingScope,
            $count,
        );
    }

    /**
     * Whether THIS state claims the plain Char in NORMAL state. Instance
     * method on purpose: `0` (and every lib-bound motion, whose claim only
     * matters for predicate completeness) keys on the armed count, so an
     * unarmed `0` stays the lib's line-start motion and `d0` survives the
     * count grammar.
     */
    public function claimsKey(string $rune): bool
    {
        if (\in_array($rune, self::CLAIMED_RUNES, true) || \in_array($rune, self::COUNT_LEADING_DIGITS, true)) {
            return true;
        }

        return $this->count !== null && \in_array($rune, self::COUNT_ACTUATORS, true);
    }

    /**
     * A key arrived while the grammar is armed ({@see isArmed()}).
     *
     * Returns [widget, state, $handled]. `$handled === false` means the key
     * is NOT vim-operator material at all (Tab, an arrow, a ctrl chord, a
     * paste): the CALLER must drop the whole armed state and re-route the
     * key so the existing arm for it runs unchanged — this is what keeps the
     * palette, the popups and the queue owning their own keys one keystroke
     * after a forgotten `d` or a forgotten count. Escape always cancels HERE
     * (handled): while a modal owns
     * the keys the caller's own gate keeps this method out of the way, so
     * reaching it means there is nothing more specific for Escape to close.
     *
     * @return array{TextArea, self, bool}
     */
    public static function resolve(KeyMsg $key, TextArea $input, self $state): array
    {
        $op = $state->pending;

        if ($op === null && $state->count === null) {
            // Nothing armed: not our key at all (the caller's own gate keeps
            // this the cold path it has always been).
            return [$input, $state, false];
        }

        if ($key->type === KeyType::Escape) {
            return [$input, $state->with(), true];
        }

        $count = $state->count ?? 1;

        if ($key->type !== KeyType::Char || $key->ctrl || $key->alt) {
            // Enter is the one non-Char key with a vim meaning under an
            // operator (`d<CR>` kills the current line, `2d<CR>` two lines);
            // Chat routes it here only when no popup owns Enter, and the
            // caller re-routes it on `false` otherwise. Under a bare count
            // Enter is the user submitting — cancel and re-route.
            if ($op !== null && $key->type === KeyType::Enter && !$key->alt && !$key->shift && !$key->ctrl) {
                [$out, $done] = self::applyLinewise($op, $input, $state, $input->row, min($input->lineCount() - 1, $input->row + $count - 1));
                if ($op === 'c') {
                    $out = self::enterInsert($out);
                }

                return [$out, $done, true];
            }

            return [$input, $state->with(), false];
        }

        $rune = $key->rune;

        if ($state->count !== null) {
            // Digits append to the count wherever the grammar stands. The
            // LEADING zero rides the same number-building rule — but a bare
            // `0` with NO count armed falls through to the line-start
            // motion below instead: `d0` survives the count grammar.
            if ($rune === '0' || \in_array($rune, self::COUNT_LEADING_DIGITS, true)) {
                return [$input, $state->with(pending: $op, pendingScope: $state->pendingScope, count: $count * 10 + (int) $rune), true];
            }
        } elseif (\in_array($rune, self::COUNT_LEADING_DIGITS, true)) {
            return [$input, $state->with(pending: $op, pendingScope: $state->pendingScope, count: (int) $rune), true];
        }

        if ($op === null) {
            return self::resolveCountedMotion($key, $rune, $input, $state, $count);
        }

        if ($state->pendingScope !== null) {
            return self::resolveTextObject($op, $state, $input, $rune);
        }

        // `i`/`a` after an operator arm a text-object SCOPE.
        if ($rune === 'i' || $rune === 'a') {
            return [$input, $state->with(pending: $op, pendingScope: $rune, count: $state->count), true];
        }

        // dd / yy / cc — the doubled operator is whole-line, the classic
        // spelling, ×count rows.
        if ($rune === $op) {
            [$out, $done] = self::applyLinewise($op, $input, $state, $input->row, min($input->lineCount() - 1, $input->row + $count - 1));
            if ($op === 'c') {
                $out = self::enterInsert($out);
            }

            return [$out, $done, true];
        }

        // d j / y k span the caret line plus `$count` rows, line-wise.
        if ($rune === 'j' || $rune === 'k') {
            $last = $input->lineCount() - 1;
            $other = $rune === 'j' ? min($last, $input->row + $count) : max(0, $input->row - $count);
            if ($other === $input->row) {
                return [$input, $state->with(), true]; // beep: nowhere to go
            }

            [$out, $done] = self::applyLinewise($op, $input, $state, min($input->row, $other), max($input->row, $other));

            return [$out, $done, true];
        }

        if ($rune === 'e' || ($op === 'c' && $rune === 'w' && $state->count === null)) {
            // `e`, and vim's rule that a BARE `cw` is `ce`; with a count
            // `cw` joins the delegated-motion family below instead.
            $end = self::walkWordEnd($input->lines[$input->row] ?? '', $input->col, $count);
            if ($end === $input->col) {
                return [$input, $state->with(), true]; // beep: empty motion
            }
            [$out, $done] = self::applyCharwise($op, $input, $state, $end);
            if ($op === 'c') {
                $out = self::enterInsert($out);
            }

            return [$out, $done, true];
        }

        if (\in_array($rune, self::CHAR_MOTIONS, true)) {
            // Ask the lib for the boundary by MOVING there (×count), then
            // read the caret — the motion algorithm stays the widget's one
            // definition.
            $moved = $input;
            for ($n = 0; $n < $count; $n++) {
                [$moved] = $moved->update($key);
            }
            if ($moved->row === $input->row && $moved->col === $input->col) {
                return [$input, $state->with(), true]; // beep: empty motion
            }

            [$out, $done] = self::applySpan($op, $input, $state, $input->row, $input->col, $moved->row, $moved->col);
            if ($op === 'c') {
                $out = self::enterInsert($out);
            }

            return [$out, $done, true];
        }

        // Unknown continuation: beep (consume) and cancel — a stray letter
        // after a forgotten `d` must never type itself or move anything.
        return [$input, $state->with(), true];
    }

    /**
     * A key arrived with a COUNT armed but no operator: the count turns the
     * lib's own motions into actions ×count, arms an operator through the
     * digits it keeps, and runs the single-keystroke actors (`e`, `p`, `P`,
     * `D`, `Y`, `o`, `O`) at once. A key the grammar has no reading for
     * clears the count and re-routes UNHANDLED — so `3v` enters the lib's
     * visual selection and a forgotten `3` costs exactly the keystroke it
     * lands on, same shape as the operator path's contract.
     *
     * @return array{TextArea, self, bool}
     */
    private static function resolveCountedMotion(KeyMsg $key, string $rune, TextArea $input, self $state, int $count): array
    {
        switch ($rune) {
            case 'd':
            case 'y':
            case 'c':
                return [$input, $state->with(pending: $rune, count: $state->count), true];

            case 'w':
            case 'b':
            case 'h':
            case 'l':
            case '$':
            case '0':
            case 'j':
            case 'k':
            case 'x':
                // Pure motions (and the lib's `x`): delegate ×count. `0`
                // reaches here only with a count armed (the claim guard), so
                // it appends no phantom digit — the append branch ran first.
                $moved = $input;
                for ($n = 0; $n < $count; $n++) {
                    [$moved] = $moved->update($key);
                }

                return [$moved, $state->with(), true];

            case 'e':
                return [$input->setCursor($input->row, self::walkWordEnd($input->lines[$input->row] ?? '', $input->col, $count)), $state->with(), true];

            case 'p':
            case 'P':
                $out = $input;
                for ($n = 0; $n < $count; $n++) {
                    $out = self::paste($out, $state, after: $rune === 'p');
                }

                return [$out, $state->with(), true];

            case 'D':
                return [...self::applyCharwise('d', $input, $state, self::rowLen($input, $input->row)), true];

            case 'Y':
                return [...self::applyLinewise('y', $input, $state, $input->row, $input->row), true];

            case 'o':
                return [self::openLine($input, $input->row + 1), $state->with(), true];

            case 'O':
                return [self::openLine($input, $input->row), $state->with(), true];
        }

        return [$input, $state->with(), false];
    }

    /**
     * The target key of an armed text object (`d i w`, `c a "`): resolved by
     * the lib's own `TextObject` geometry on the caret's single line, then
     * landed in the SAME `applySpan()` the motions use. No range is a BEEP
     * that cancels the whole grammar.
     *
     * @return array{TextArea, self, bool}
     */
    private static function resolveTextObject(string $op, self $state, TextArea $input, string $rune): array
    {
        $operator = VimOperator::fromKey($op);
        $scope = $state->pendingScope !== null ? TextObjectScope::fromKey($state->pendingScope) : null;
        $range = null;
        if ($operator !== null && $scope !== null && TextObject::isTarget($rune)) {
            [, $range] = VimKeyHandler::handleTextObject($operator, $scope, $rune, $input->lines[$input->row] ?? '', $input->col);
        }

        if ($range === null) {
            return [$input, $state->with(), true]; // beep: no object under the caret
        }

        [$out, $done] = self::applySpan($op, $input, $state, $input->row, $range->start, $input->row, $range->end);
        if ($op === 'c') {
            $out = self::enterInsert($out);
        }

        return [$out, $done, true];
    }

    /**
     * A plain Char in NORMAL state with nothing armed (the caller has the
     * same guards; this method trusts them). Arming keys return the widget
     * untouched; acting keys edit through the widget's own public
     * surface, and the Insert entries (`o`/`O`, and `C` through the
     * `enterInsert` half of this class's rule for `c`) enter Insert by
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
            case 'c':
                return [$input, $state->with(pending: $key->rune)];

            case '1':
            case '2':
            case '3':
            case '4':
            case '5':
            case '6':
            case '7':
            case '8':
            case '9':
                // A count arms WITHOUT touching the buffer; bare `0` never
                // reaches start() (claimsKey keeps it for the lib).
                return [$input, $state->with(count: (int) $key->rune)];

            case 'D':
                // d$ — kill caret..EOL, caret clamps by the delete itself.
                return self::applyCharwise('d', $input, $state, self::rowLen($input, $input->row));

            case 'C':
                // c$ — the same kill, then the lib's Insert to type the
                // replacement in.
                [$changed, $kept] = self::applyCharwise('c', $input, $state, self::rowLen($input, $input->row));

                return [self::enterInsert($changed), $kept];

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

        // claimsKey() and this switch must agree; unreachable by construction.
        throw new \LogicException('ComposerVim::start() asked about unclaimed rune ' . $key->rune);
    }

    // ---- operators -------------------------------------------------------

    /**
     * Feed the widget a synthesized `i` — the lib's own EnterInsertMode
     * path, shared by `o`/`O` and by every `c` completion (`cc`, `cw`,
     * `ci"`, `C`): the change is always "the d kill, then this".
     */
    private static function enterInsert(TextArea $widget): TextArea
    {
        [$inserting] = $widget->update(new KeyMsg(KeyType::Char, 'i'));

        return $inserting;
    }

    /**
     * Apply d/y/c to whole lines `$from`..`$to` inclusive. Delete lands the
     * caret at column 0 of the first surviving line of the killed range
     * (vim's dd behaviour); yank does not move the caret at all. Returns the
     * widget plus the state carrying the fresh register (the whole armed
     * grammar clears — `with()` defaults it away).
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
     * the ORIGINAL point (clamped into the surviving text). The change
     * operator `c` rides the delete half — callers that must then type the
     * replacement feed it through `enterInsert()`.
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
     * the widget a synthesized `i` — see `enterInsert()` for why feeding the
     * key is the only lawful entry.
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

        return self::enterInsert($placed);
    }

    /**
     * Re-insert the register. Linewise (from dd/yy/Y/d<CR>/dj) inserts the
     * held lines on a NEW row below (`p`) or above (`P`) the caret row and
     * lands the caret at column 0 of the pasted block — a yank of whole lines
     * must not glue text onto the caret's line, that is what charwise is for.
     * Charwise pastes inline: `p` after the caret's column, `P` at it, caret
     * one cell past the pasted text — the cursor conventions of the mode the
     * user just left. A counted paste (`2p`) runs this once per repeat.
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
     * Repeat the word-end walk `$count` times: at least one cell right per
     * step, stopping at the col it cannot pass (EOL), so `3e` walks three
     * words and `de` at EOL still answers the identity the caller beeps on.
     */
    private static function walkWordEnd(string $line, int $col, int $count): int
    {
        $end = $col;
        for ($n = 0; $n < $count; $n++) {
            $next = self::wordEndCol($line, $end);
            if ($next === $end) {
                break;
            }
            $end = $next;
        }

        return $end;
    }

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

    private static function rowLen(TextArea $input, int $row): int
    {
        return mb_strlen($input->lines[$row] ?? '', 'UTF-8');
    }
}

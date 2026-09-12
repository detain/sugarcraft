<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Palette\PaletteState;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tui\Components\MenuBar;

/**
 * E3 (the caret half): the palette query stopped being append-only.
 *
 * `handlePaletteKey()` used to know two edits - `query . rune` and a
 * code-point `dropLast` - so every keystroke landed at the end and Backspace
 * always ate the last thing typed. Left/Right/Home/End/Delete fell through
 * the `default` arm as no-ops: the caret the user could see (the `█` tail at
 * the end) was the only caret that existed. This pins the missing half:
 *
 * - motion keys move a real caret (`PaletteState::$queryCursor`), clamped at
 *   both ends;
 * - typing INSERTS at the caret and Backspace erases BEFORE it, degenerating
 *   to the old append/drop behaviour exactly when the caret sits at the end -
 *   which is the invariant that keeps the append-only captures (ChatTest's
 *   typed queries, {@see PaletteCaretHonoursHandoffTest}'s byte-identity)
 *   green without one of them being rewritten;
 * - Delete erases forward, a capability the old key table simply did not have;
 * - every cluster crossing is grapheme-atomic, so the `e` + combining-acute
 *   pair the draft could already orphan is orphaned by no palette key here.
 *
 * Paint is asserted on the `renderPalette()` overlay itself - the same
 * reflection entry {@see PaletteCaretHonoursHandoffTest} uses - because the
 * caret splice and the abandonment rule live in one expression there, and a
 * behaviour test on state alone would let a builder that ignores the cursor
 * pass.
 */
final class PaletteQueryCaretTest extends TestCase
{
    use HomeSandboxTrait;

    private string $homeSandbox = '';

    protected function setUp(): void
    {
        // Constructing a Chat walks the skill trees under HOME; sandbox both
        // spellings so nothing here depends on what this machine has installed.
        $this->homeSandbox = $this->useHomeSandbox(
            sys_get_temp_dir() . '/crush_palette_caret_home_' . bin2hex(random_bytes(6)),
        );
    }

    protected function tearDown(): void
    {
        Renderer::setPaletteAbandoned(false);
        MenuBar::closeMenu();
        Renderer::scanner()->clear();
        $this->restoreHomeSandbox();
        @rmdir($this->homeSandbox);

        parent::tearDown();
    }

    // =====================================================================
    // behaviour - the state machine
    // =====================================================================

    /**
     * Typing still APPENDS while the caret trails at the end - the old
     * keyboard contract survives as the caret-at-end special case, and every
     * later arm here builds on it.
     */
    public function testTypingStillAppendsWithTheCaretTrailingAtTheEnd(): void
    {
        $chat = $this->openPalette();
        $chat = $this->type($chat, 'ab');

        self::assertSame('ab', $chat->palette()->query);
        self::assertSame(2, $chat->palette()->queryCursor);
    }

    /**
     * Left parks the caret and the next character lands AT it - the one arm
     * whose absence defined the append-only bug.
     */
    public function testTypingInsertsAtTheCaretAfterOneLeft(): void
    {
        $chat = $this->type($this->openPalette(), 'ab');
        [$chat] = $chat->update(new KeyMsg(KeyType::Left));
        self::assertSame(1, $chat->palette()->queryCursor, 'fixture: Left moves the caret one cluster');

        $chat = $this->type($chat, 'x');

        self::assertSame('axb', $chat->palette()->query);
        self::assertSame(2, $chat->palette()->queryCursor, 'the caret lands after what was just typed, not at the end');
    }

    public function testMotionClampsAtBothEnds(): void
    {
        $chat = $this->type($this->openPalette(), 'ab');
        for ($i = 0; $i < 3; $i++) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Left));
        }
        self::assertSame(0, $chat->palette()->queryCursor, 'Left at the wall stays at the wall');

        for ($i = 0; $i < 4; $i++) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Right));
        }
        self::assertSame(2, $chat->palette()->queryCursor, 'Right at the wall stays at the wall');
    }

    /**
     * Home/End are absolute jumps to the two exact boundaries - and a
     * character typed after Home prepends, proving the jump moved a real
     * caret and not just the selection row.
     */
    public function testHomeAndEndJumpToTheEdgesAndHomeInsertsAtZero(): void
    {
        $chat = $this->type($this->openPalette(), 'abc');
        [$chat] = $chat->update(new KeyMsg(KeyType::Home));
        self::assertSame(0, $chat->palette()->queryCursor);

        $chat = $this->type($chat, 'z');
        self::assertSame('zabc', $chat->palette()->query);
        self::assertSame(1, $chat->palette()->queryCursor);

        [$chat] = $chat->update(new KeyMsg(KeyType::End));
        self::assertSame(4, $chat->palette()->queryCursor);
    }

    /**
     * Backspace mid-query eats the cluster BEFORE the caret only - the tail
     * keeps its place and the caret follows the cut leftwards. Two Lefts from
     * the end of `abc` park the caret between `a` and `b`; the erase eats the
     * `a` and nothing else.
     */
    public function testBackspaceErasesBeforeTheCaretAndFollowsItLeft(): void
    {
        $chat = $this->type($this->openPalette(), 'abc');
        [$chat] = $chat->update(new KeyMsg(KeyType::End));
        [$chat] = $chat->update(new KeyMsg(KeyType::Left));
        [$chat] = $chat->update(new KeyMsg(KeyType::Left));

        [$chat] = $chat->update(new KeyMsg(KeyType::Backspace));

        self::assertSame('bc', $chat->palette()->query, 'only the cluster before the caret went');
        self::assertSame(0, $chat->palette()->queryCursor, 'the caret sits on the cut');
    }

    /**
     * Delete erases forward and the caret does NOT move - half the old key
     * table's missing capability, and the one arm no pre-E3 state could ever
     * have reached.
     */
    public function testDeleteErasesForwardWithoutMovingTheCaret(): void
    {
        $chat = $this->type($this->openPalette(), 'ab');
        [$chat] = $chat->update(new KeyMsg(KeyType::Home));

        [$chat] = $chat->update(new KeyMsg(KeyType::Delete));

        self::assertSame('b', $chat->palette()->query);
        self::assertSame(0, $chat->palette()->queryCursor);

        [$chat] = $chat->update(new KeyMsg(KeyType::End));
        [$chat] = $chat->update(new KeyMsg(KeyType::Delete));
        self::assertSame('b', $chat->palette()->query, 'Delete at the end is a no-op, not a wrap');
    }

    /**
     * The two cursors of the palette must never shadow each other: the row
     * selection rides caret moves and the caret rides row moves. Pinned at the
     * STATE level because typing a new query re-filters and legitimately
     * resets the row to 0 (withQuery's contract) — a keyboard route therefore
     * cannot hold both cursors still at once; these two withers are the whole
     * independence claim.
     */
    public function testCaretMotionKeepsTheSelectedRowAndRowSelectionKeepsTheCaret(): void
    {
        $state = (new PaletteState('root', 'abc', 0, 3))->withSelectedIndex(2);

        self::assertSame(2, $state->withQueryCursor(1)->selectedIndex, 'caret motion does not reset the row');
        self::assertSame(1, $state->withQueryCursor(1)->queryCursor);
        self::assertSame(3, $state->withSelectedIndex(4)->queryCursor, 'row motion does not reset the caret');
        self::assertSame(4, $state->withSelectedIndex(4)->selectedIndex);
    }

    /**
     * Grapheme atomicity, keyboard route: a multi-codepoint rune arrives as
     * ONE keystroke (a paste), the caret advances by its WHOLE width, and the
     * next Backspace eats the whole cluster - the `e` + combining acute pair
     * can be neither split on the way in nor orphaned on the way out.
     */
    public function testClustersSurviveInsertAndBackspaceWhole(): void
    {
        $chat = $this->type($this->openPalette(), 'a');
        // One keystroke carrying three codepoints - the shape a paste arrives
        // as, which is exactly what the insert-invariant is written against.
        [$chat] = $chat->update(new KeyMsg(KeyType::Char, "\u{1F468}\u{200D}\u{1F469}"));
        $chat = $this->type($chat, 'b');
        self::assertSame("a\u{1F468}\u{200D}\u{1F469}b", $chat->palette()->query);
        self::assertSame(5, $chat->palette()->queryCursor, 'the caret cleared the whole ZWJ cluster');

        [$chat] = $chat->update(new KeyMsg(KeyType::Backspace));
        self::assertSame("a\u{1F468}\u{200D}\u{1F469}", $chat->palette()->query, 'the plain b went first');
        self::assertSame(4, $chat->palette()->queryCursor);

        [$chat] = $chat->update(new KeyMsg(KeyType::Backspace));
        self::assertSame('a', $chat->palette()->query, 'then the whole couple - five codepoints never one');
        self::assertSame(1, $chat->palette()->queryCursor);
    }

    /**
     * The combining-mark shape E5 named, through the palette keys: the mark
     * rides its base in one cluster for both the caret and the eraser.
     */
    public function testCombiningMarkIsOneClusterForBackspace(): void
    {
        $chat = $this->type($this->openPalette(), "e\u{0301}");
        self::assertSame(2, $chat->palette()->queryCursor, 'both codepoints are one cluster and the caret sits after it');

        [$chat] = $chat->update(new KeyMsg(KeyType::Backspace));
        self::assertSame('', $chat->palette()->query, 'the orphan-mark outcome the codepoint dropLast produced is unreachable');
    }

    // =====================================================================
    // paint - the builder honours the caret
    // =====================================================================

    public function testThePaintedQueryCarriesTheCaretAtItsEnd(): void
    {
        $overlay = $this->paint($this->type($this->openPalette(), 'ab'));

        self::assertStringContainsString('🔍 ab', $overlay);
        self::assertStringContainsString("\u{2588}", $overlay, 'the keyboard claim paints while the palette drives');
        self::assertStringContainsString('🔍 ab' . "\u{2588}", $overlay, 'an end caret splices as the old tail byte did');
    }

    public function testThePaintedQuerySplicesTheCaretBetweenTheHalves(): void
    {
        $chat = $this->type($this->openPalette(), 'ab');
        [$chat] = $chat->update(new KeyMsg(KeyType::Left));
        $overlay = $this->paint($chat);

        self::assertStringContainsString('🔍 a' . "\u{2588}" . 'b', $overlay);
        self::assertStringNotContainsString('🔍 ab' . "\u{2588}", $overlay);
        self::assertSame(1, substr_count($overlay, "\u{2588}"), 'one caret, wherever it sits');
    }

    /**
     * Under the E682 hand-off the caret cell goes; with a MID caret that
     * means the two halves re-join into one word. Not asserted byte-exact:
     * {@see PaletteCaretHonoursHandoffTest} owns the end-caret exact-delta
     * contract, and the query line pads to the box width downstream, so the
     * mid-caret handed-off box simply loses its glyph and nothing else.
     */
    public function testTheAbandonedPaletteDropsTheCaretAndKeepsBothQueryHalves(): void
    {
        $chat = $this->type($this->openPalette(), 'ab');
        [$chat] = $chat->update(new KeyMsg(KeyType::Left));

        Renderer::setPaletteAbandoned(true);
        $overlay = $this->paint($chat);

        self::assertStringNotContainsString("\u{2588}", $overlay);
        self::assertStringContainsString('🔍 ab', $overlay, 'wire-not-delete: the query survives the hand-off whole');
    }

    /**
     * The renderer's own cluster defence (E5, the paint side): a caret offset
     * that names a codepoint INSIDE a cluster is snapped DOWN to the cluster
     * start - never up, which would silently swallow the cluster tail behind
     * the caret. Driven at the helper because every palette keystroke already
     * parks the caret on a boundary; the guard exists for a cursor this
     * keyboard could not produce, and `min(...)` plus the clamp prove it holds
     * without needing one.
     */
    public function testTheRendererSnapsAnInsideClusterCaretDownToTheBoundary(): void
    {
        $snap = new ReflectionMethod(Renderer::class, 'snapToClusterStart');

        $text = "e\u{0301}x";
        self::assertSame(0, $snap->invoke(null, $text, 0), 'the left end short-circuits');
        self::assertSame(0, $snap->invoke(null, $text, 1), 'one codepoint into the e-acute cluster snaps back to its start');
        self::assertSame(2, $snap->invoke(null, $text, 2), 'the boundary is left alone');
        self::assertSame(3, $snap->invoke(null, $text, 3), 'the right end short-circuits');
        self::assertSame(0, $snap->invoke(null, '', 0), 'the empty string is its own boundary');
        self::assertSame(0, $snap->invoke(null, '', 4), 'a runaway offset clamps into the text');

        $flag = "\u{1F1FA}\u{1F1F8}";
        self::assertSame(0, $snap->invoke(null, $flag, 1), 'a regional-indicator pair is one cluster');
    }

    /**
     * The cursor's parse-don't-validate boundary: the STATE clamps a runaway
     * offset into the text but deliberately does not snap it - segmentation
     * is the writer's job, the renderer defends independently (see the
     * {@see PaletteState} class docblock).
     */
    public function testTheStateClampsTheCursorWithoutSnappingIt(): void
    {
        $state = new PaletteState('root', "e\u{0301}x", 0, 1);
        self::assertSame(1, $state->queryCursor, 'construction clamps by length only');
        self::assertSame(3, $state->withQueryCursor(99)->queryCursor);
        self::assertSame(0, $state->withQueryCursor(-4)->queryCursor);

        $retyped = $state->withQuery('abc');
        self::assertSame(3, $retyped->queryCursor, 'a whole replacement parks the caret at the end');
        self::assertSame(0, $retyped->selectedIndex, 'and a replacement re-filters, so the row selection resets');
    }

    // =====================================================================
    // fixtures
    // =====================================================================

    /**
     * SGR-run stripped rows, so adjacency assertions talk about cells rather
     * than about where a colour run happens to open.
     *
     * @return list<string>
     */
    private function plainRows(string $block): array
    {
        return array_map(
            static fn (string $row): string => preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $row) ?? $row,
            explode("\n", $block),
        );
    }

    private function openPalette(): Chat
    {
        [$chat] = (new Chat())->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        self::assertNotNull($chat->palette(), 'fixture: Ctrl+P must open the palette');

        return $chat;
    }

    /** Types one keystroke per U+ codepoint (mb-aware, so fixture runes survive). */
    private function type(Chat $chat, string $runes): Chat
    {
        foreach (preg_split('//u', $runes, -1, PREG_SPLIT_NO_EMPTY) as $rune) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $rune));
        }

        return $chat;
    }

    /**
     * The palette overlay as the builder paints it, plain (SGR stripped) -
     * the splice under test is unstyled text, and stripping keeps every
     * adjacency assertion about it honest.
     */
    private function paint(Chat $chat): string
    {
        $overlay = (new ReflectionMethod(Renderer::class, 'renderPalette'))
            ->invoke(null, $chat, $chat->theme());
        self::assertIsString($overlay);
        self::assertNotSame('', $overlay, 'the fixture must reach the builder with an open palette');

        return implode("\n", $this->plainRows($overlay));
    }
}

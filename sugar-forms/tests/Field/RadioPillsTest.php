<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Field;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Forms\Field\RadioPills;

/**
 * crush_media W3.3 (droppable half): horizontal radio-pill row — Confirm's
 * raw-reverse pill idiom over an option list, saturation walk, click
 * mapping with three-cell dead zones, label-preserving option swaps, and
 * the value-editor readonly door (moving a radio pill IS committing).
 */
final class RadioPillsTest extends TestCase
{
    private function pillsAt(string ...$options): RadioPills
    {
        return RadioPills::new('mode', ...$options);
    }

    private function focusPills(RadioPills $p): RadioPills
    {
        [$focused, $cmd] = $p->focus();
        self::assertNull($cmd, 'radio focus schedules nothing');
        /** @var RadioPills $focused */
        return $focused;
    }

    private function tapKey(RadioPills $p, KeyType $type, string $rune = ''): RadioPills
    {
        [$next, $cmd] = $p->update(new KeyMsg($type, $rune));
        self::assertNull($cmd);
        /** @var RadioPills $next */
        return $next;
    }

    private function tapLeftAt(RadioPills $p, int $x, int $y = 1): RadioPills
    {
        /** @var RadioPills $next */
        [$next, ] = $p->update(new MouseMsg($x, $y, MouseButton::Left, MouseAction::Press));
        return $next;
    }

    public function testFreshRowSelectsTheFirstOption(): void
    {
        $p = $this->pillsAt('a', 'b', 'c');
        self::assertSame('a', $p->value());
        self::assertSame(0, $p->cursor());
    }

    public function testEmptyRowIsHollow(): void
    {
        $p = $this->focusPills($this->pillsAt());
        self::assertNull($p->value());
        self::assertSame($p, $this->tapKey($p, KeyType::Right), 'walking an empty row is identity');
        self::assertSame($p, $this->tapLeftAt($p, 3), 'clicking an empty row is identity');
        self::assertSame('', explode("\n", $p->view())[0] ?? '');
    }

    public function testListFormAndAliasesConstructIdentically(): void
    {
        self::assertSame(['x', 'y'], RadioPills::new('k', ['x', 'y'])->options);
        self::assertSame(['x', 'y'], $this->pillsAt('x', 'y')->options);
        self::assertSame('b', $this->pillsAt('a', 'b')->options('a', 'b', 'c')->withValue('b')->value());
    }

    public function testSaturationWalkOverKeysAndRunes(): void
    {
        $p = $this->focusPills($this->pillsAt('a', 'b', 'c'));
        $p = $this->tapKey($p, KeyType::Right);
        self::assertSame('b', $p->value());
        $p = $this->tapKey($p, KeyType::Char, 'l');
        self::assertSame('c', $p->value());
        $p = $this->tapKey($p, KeyType::Right);
        self::assertSame('c', $p->value(), 'saturates at the last pill');
        $p = $this->tapKey($p, KeyType::Char, 'h');
        self::assertSame('b', $p->value());
        $p = $this->tapKey($p, KeyType::Left);
        self::assertSame('a', $p->value());
        $p = $this->tapKey($p, KeyType::Left);
        self::assertSame('a', $p->value(), 'saturates at the first pill');
        $p = $this->tapKey($this->tapKey($p, KeyType::End), KeyType::Home);
        self::assertSame('a', $p->value());
        self::assertSame('c', $this->tapKey($p, KeyType::End)->value());
    }

    public function testUnfocusedAndFormKeysStayInert(): void
    {
        $p = $this->pillsAt('a', 'b');
        self::assertSame($p, $this->tapKey($p, KeyType::Right), 'unfocused stays deaf');
        $f = $this->focusPills($p);
        self::assertSame($f, $this->tapKey($f, KeyType::Up), 'Up/Down stay with the Form');
        self::assertSame($f, $this->tapKey($f, KeyType::Enter));
        self::assertFalse($f->consumes(new KeyMsg(KeyType::Up)), 'the row claims nothing');
    }

    public function testClickMapsCellsAndLeavesGapsInert(): void
    {
        $p = $this->focusPills($this->pillsAt('ab', 'cde', 'd'));
        // cells: 'ab' cols 1-4, gap 5-7, 'cde' cols 8-12, gap 13-15, 'd' 16-18
        self::assertSame('ab', $this->tapLeftAt($p, 1)->value());
        self::assertSame($p, $this->tapLeftAt($p, 1), 'clicking the selected pill is identity');
        self::assertSame('ab', $this->tapLeftAt($p, 4)->value(), 'right pad cell belongs to the pill');
        self::assertSame($p, $this->tapLeftAt($p, 5), 'gap is a dead zone');
        self::assertSame($p, $this->tapLeftAt($p, 7));
        self::assertSame('cde', $this->tapLeftAt($p, 8)->value());
        self::assertSame('d', $this->tapLeftAt($p, 16)->value());
        self::assertSame($p, $this->tapLeftAt($p, 19), 'past the row is inert');
    }

    public function testClickOnlyLandsOnThePillRow(): void
    {
        $p = $this->focusPills($this->pillsAt('a', 'b')->withTitle('Mode')->withDescription('pick one'));
        // view lines: 1 title, 2 description, 3 pills.
        self::assertSame($p, $this->tapLeftAt($p, 2, 1), 'title row is inert');
        self::assertSame($p, $this->tapLeftAt($p, 2, 2), 'description row is inert');
        $hit = $this->tapLeftAt($p, 7, 3);
        self::assertNotSame($p, $hit);
        self::assertSame('b', $hit->value());
    }

    public function testWheelRightButtonAndReadonlyRefuseMouse(): void
    {
        $p = $this->focusPills($this->pillsAt('a', 'b'));
        [$wheeled, ] = $p->update(new MouseWheelMsg(8, 1, MouseButton::WheelDown, MouseAction::Press));
        self::assertSame($p, $wheeled, 'crush Viewport maps wheel to Up/Down keys — no double-move');
        [$right, ] = $p->update(new MouseMsg(8, 1, MouseButton::Right, MouseAction::Press));
        self::assertSame($p, $right);
        self::assertNull($p->withReadonly(true)->handleZoneMouse(new MouseMsg(8, 1, MouseButton::Left, MouseAction::Press), 1));
    }

    public function testReadonlyFreezesKeysAndMouseButStaysSelectableInView(): void
    {
        $ro = $this->focusPills($this->pillsAt('a', 'b')->withTitle('T')->withReadonly(true));
        self::assertSame($ro, $this->tapKey($ro, KeyType::Right), 'a radio move IS a commit — frozen like a value editor');
        $clicked = $this->tapLeftAt($ro, 6);
        self::assertSame($ro, $clicked);
        self::assertStringContainsString('(read-only)', $ro->view());
    }

    public function testValueJumpAndOptionSwapKeepLabelIdentity(): void
    {
        $p = $this->pillsAt('a', 'b', 'c');
        $jumped = $p->withValue('c');
        self::assertSame('c', $jumped->value());
        self::assertSame(2, $jumped->cursor());
        self::assertSame($p, $p->withValue('zzz'), 'an unknown label is a refusal, not a reset');
        self::assertSame($p, $p->withValue(7), 'a non-string label is refused too');

        $selected = $this->tapKey($this->focusPills($p), KeyType::Right); // 'b'
        self::assertSame('b', $selected->withOptions('c', 'b', 'a')->value(), 're-order follows the label');
        self::assertSame('c', $selected->withOptions('c', 'd')->value(), 'a removed selection falls back to option 0');
        self::assertNull($selected->withOptions()->value(), 'an emptied row holds no value');
    }

    public function testEveryTransitionIsPure(): void
    {
        $base = $this->pillsAt('a', 'b');
        $moved = $this->tapKey($this->focusPills($base), KeyType::Right);
        self::assertNotSame($base, $moved);
        self::assertSame('a', $base->value(), 'the source instance never moves');
        self::assertSame('b', $moved->value());
        self::assertFalse($base->isFocused());
        self::assertTrue($moved->isFocused());
    }

    public function testPillRowByteSnapshot(): void
    {
        $row = explode("\n", $this->pillsAt('ab', 'c')->view())[0];
        $expected = Ansi::sgr(Ansi::REVERSE) . ' ab ' . Ansi::reset() . '   ' . ' c ';
        self::assertSame($expected, $row);
        $second = explode("\n", $this->pillsAt('ab', 'c')->withValue('c')->view())[0];
        self::assertSame(' ab ' . '   ' . Ansi::sgr(Ansi::REVERSE) . ' c ' . Ansi::reset(), $second);
    }

    public function testWideLabelsUseCodepointCellsForClicks(): void
    {
        $p = $this->focusPills($this->pillsAt('日本', 'x'));
        // cell widths are CODEPOINT counts (single-width assumption, the
        // Confirm law): '日本' = 2+2 pad cols 1-4, gap 5-7, 'x' cell 8-10.
        self::assertSame('x', $this->tapLeftAt($p, 10)->value());
        self::assertSame($p, $this->tapLeftAt($p, 6), 'gap after a multi-codepoint pill stays a dead zone');
    }

    public function testRevalidateIsTheIdentityContract(): void
    {
        $p = $this->pillsAt('a', 'b');
        self::assertSame($p, $p->revalidate());
        self::assertNull($p->getError());
        self::assertFalse($p->skippable());
    }

    // ------------------------------------------------------------------
    // Horizontal window (withWidth)
    // ------------------------------------------------------------------

    /**
     * The provider-wizard roster that motivated the window: eleven pills,
     * full row 123 cells, a terminal strip that used to silently cut the
     * tail off-grid.
     */
    private function nnRoster(): RadioPills
    {
        return $this->pillsAt(
            'openai', 'azure', 'anthropic', 'claude-code', 'codex', 'sglang',
            'bedrock', 'vertex', 'gemini', 'openrouter', 'custom',
        );
    }

    private function firstRow(RadioPills $p): string
    {
        return explode("\n", $p->view())[0];
    }

    private function highlight(string $label): string
    {
        return Ansi::sgr(Ansi::REVERSE) . ' ' . $label . ' ' . Ansi::reset();
    }

    public function testNoWidthKeepsTheFullRowByteIdentical(): void
    {
        // The BC law: with no budget the row is today's unwindowed line —
        // every pill present, no ellipsis anywhere.
        $row = $this->firstRow($this->nnRoster());
        self::assertStringNotContainsString('…', $row);
        self::assertStringContainsString(' custom ', $row, 'the tail pill is still emitted whole');
        self::assertGreaterThanOrEqual(120, Width::string($row));
        // A generous budget that the roster fits inside is byte-identical
        // to no budget at all.
        self::assertSame($row, $this->firstRow($this->nnRoster()->withWidth(500)));
    }

    public function testWindowFollowsSelectionAtStartMiddleAndEnd(): void
    {
        // The exact over-clip case: eleven pills against a 40-cell strip.
        $at = $this->nnRoster()->withWidth(40);

        $start = $this->firstRow($at->withValue('openai'));
        self::assertSame(
            $this->highlight('openai') . '   ' . ' azure ' . '   ' . ' anthropic ' . '   ' . '…',
            $start,
            'start window is deterministic: no head marker, tail marker for the hidden eight',
        );

        $middle = $this->firstRow($at->withValue('sglang'));
        self::assertStringContainsString($this->highlight('sglang'), $middle);
        self::assertStringStartsWith('…', $middle);
        self::assertStringEndsWith('…', $middle);

        $end = $this->firstRow($at->withValue('custom'));
        self::assertStringContainsString($this->highlight('custom'), $end, 'the selected last pill is ON the visible grid');
        self::assertStringStartsWith('…', $end);
        self::assertStringNotContainsString('openai', $end, 'the head of the roster is paged out');
    }

    public function testMarkersAppearExactlyOnClippedSides(): void
    {
        $at = $this->nnRoster()->withWidth(40);
        self::assertSame(1, substr_count($this->firstRow($at->withValue('openai')), '…'), 'nothing clipped before the first pill');
        self::assertSame(1, substr_count($this->firstRow($at->withValue('custom')), '…'), 'nothing clipped after the last pill');
        self::assertSame(2, substr_count($this->firstRow($at->withValue('sglang')), '…'));
        self::assertSame(0, substr_count($this->firstRow($at->withWidth(500)->withValue('sglang')), '…'), 'a fitting roster carries no markers');
    }

    public function testEmittedRowNeverExceedsTheBudget(): void
    {
        $labels = ['openai', 'azure', 'anthropic', 'claude-code', 'codex', 'sglang',
            'bedrock', 'vertex', 'gemini', 'openrouter', 'custom'];
        foreach ([13, 14, 20, 30, 40, 55, 64, 123] as $budget) {
            $at = $this->nnRoster()->withWidth($budget);
            foreach ($labels as $label) {
                $row = $this->firstRow($at->withValue($label));
                $visible = Width::string($row);
                self::assertLessThanOrEqual($budget, $visible, "row exceeds budget {$budget} at {$label}");
                // Independent oracle: strip the SGR runs, count cells.
                $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $row);
                self::assertLessThanOrEqual($budget, mb_strwidth($stripped, 'UTF-8'));
                if ($budget >= 13) {
                    // Every pill costs at most 13 cells padded, so from
                    // that budget up the SELECTED pill must be fully on
                    // the grid — never off-window, never truncated away.
                    self::assertStringContainsString($this->highlight($label), $row);
                }
            }
        }
    }

    public function testTightBudgetDegradesToTheSelectedPill(): void
    {
        // No window can carry markers at five cells; the rule collapses to
        // the selection alone with the markers dropped.
        $p = $this->pillsAt('a', 'b', 'c')->withWidth(5)->withValue('b');
        self::assertSame($this->highlight('b'), $this->firstRow($p));
        // Even the pill itself cannot fit: truncated to the budget, loud
        // but bounded, never an over-wide row.
        $tight = $this->pillsAt('a', 'b', 'c')->withWidth(2)->withValue('b');
        self::assertLessThanOrEqual(2, Width::string($this->firstRow($tight)));
    }

    public function testWindowBudgetSurvivesEveryMutatePath(): void
    {
        // The carrier regression that bit Confirm: windowed state must
        // ride focus, blur, title, description and option swaps.
        $at = $this->nnRoster()->withWidth(40);
        [$focused, ] = $at->focus();
        self::assertSame(40, $focused->width);
        self::assertSame(40, $focused->blur()->width);
        self::assertSame(40, $at->withTitle('Provider')->width);
        self::assertSame(40, $at->withDescription('pick one')->width);
        self::assertSame(40, $at->withOptions('x', 'y')->width);
        self::assertSame(40, $at->withReadonly(true)->width);
        self::assertLessThanOrEqual(40, Width::string($this->firstRow($at->withTitle('Provider'))), 'the windowed row still respects the budget after a title mutate');
    }

    public function testClicksFollowTheWindowedRow(): void
    {
        $at = $this->nnRoster()->withWidth(40)->withValue('custom');
        // window: marker(1) gap(3) ' gemini '(8) gap ' openrouter '(12) gap ' custom '(8)
        // cols:     1     2-4     5-12        13-15    16-27      28-30   31-38
        $click = fn (int $x): ?string => $at->handleZoneMouse(new MouseMsg($x, 1, MouseButton::Left, MouseAction::Press), 1)?->value();
        self::assertNull($click(1), 'the head marker is decorative');
        self::assertNull($click(2), 'its gap is a dead zone');
        self::assertSame('gemini', $click(5));
        self::assertSame('openrouter', $click(16));
        self::assertSame('custom', $click(31), 'the selected pill answers its own cell');
        self::assertNull($click(39), 'past the windowed row is inert');

        // Pills outside the window are unreachable while hidden — the same
        // columns now answer whatever the window moved onto them.
        $scrolled = $at->withValue('openai');
        self::assertSame('anthropic', $scrolled->handleZoneMouse(new MouseMsg(31, 1, MouseButton::Left, MouseAction::Press), 1)?->value(), 'col 31 now sits inside the windowed-in anthropic pill, not custom');
    }

    public function testNarrowWidthIsRefused(): void
    {
        foreach ([0, -1] as $bad) {
            try {
                $this->nnRoster()->withWidth($bad);
                self::fail("width {$bad} must be refused");
            } catch (\InvalidArgumentException $e) {
                self::assertSame('RadioPills width must be at least 1', $e->getMessage());
            }
        }
    }

    public function testEmptyRowUnderABudgetStaysEmpty(): void
    {
        $p = $this->pillsAt()->withWidth(10);
        self::assertSame('', $this->firstRow($p));
        self::assertNull($p->handleZoneMouse(new MouseMsg(1, 1, MouseButton::Left, MouseAction::Press), 1));
    }
}

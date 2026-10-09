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
}

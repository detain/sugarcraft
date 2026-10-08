<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Overlay;

use SugarCraft\Core\Msg;
use SugarCraft\Top\Overlay\MsgBox;
use SugarCraft\Top\View\Ink;

/** btop Menu::msgBox: input codes, button geometry and mapping. */
final class MsgBoxTest extends OverlayTestCase
{
    private static function yesCmd(): \Closure
    {
        return static fn (): ?Msg => null;
    }

    private static function confirm(): MsgBox
    {
        return MsgBox::confirm(50, 'SIGTERM', static fn (Ink $i): array => ['line one', 'line two'], self::yesCmd());
    }

    private static function ok(): MsgBox
    {
        return MsgBox::ok(45, 'error', static fn (Ink $i): array => ['a', 'b', 'c']);
    }

    public function testOkBoxClosesOnEscapeBackspaceQAndAcceptsOnOEnterSpace(): void
    {
        foreach (['escape', 'backspace', 'q'] as $k) {
            $r = self::ok()->update(self::key($k), self::ctx());
            $this->assertNull($r->overlay, $k);
            $this->assertNull($r->cmd, $k);
        }
        foreach (['o', 'O', 'enter', 'space'] as $k) {
            $this->assertNull(self::ok()->update(self::key($k), self::ctx())->overlay, $k);
        }
        foreach (['y', 'n', 'tab', 'right', 'x'] as $k) {
            $this->assertNotNull(self::ok()->update(self::key($k), self::ctx())->overlay, 'an Ok box ignores ' . $k);
        }
        $this->assertSame([MsgBox::MIN_COLS, MsgBox::MIN_ROWS], self::ok()->minSize());
        $this->assertSame([0, 0], MsgBox::ok(45, 'e', static fn (Ink $i): array => [], true)->minSize(), 'the size-error box always shows');
        $this->assertTrue(self::ok()->capturesInput());
    }

    public function testConfirmStartsOnYesAndMovesWithArrowsAndTab(): void
    {
        $box = self::confirm();
        $this->assertSame(0, $box->selected);
        $yes = $box->update(self::key('y'), self::ctx());
        $this->assertNull($yes->overlay);
        $this->assertNotNull($yes->cmd, 'Yes runs the action');
        $this->assertNull($box->update(self::key('N'), self::ctx())->cmd);
        $this->assertNull($box->update(self::key('n'), self::ctx())->overlay);

        foreach (['right', 'tab', 'left', 'shift_tab'] as $k) {
            $moved = $box->update(self::key($k), self::ctx())->overlay;
            $this->assertInstanceOf(MsgBox::class, $moved);
            $this->assertSame(1, $moved->selected, $k . ' wraps 0 -> 1');
            $back = $moved->update(self::key($k), self::ctx())->overlay;
            $this->assertInstanceOf(MsgBox::class, $back);
            $this->assertSame(0, $back->selected);
        }
        $no = $box->withSelected(1)->update(self::key('enter'), self::ctx());
        $this->assertNull($no->overlay);
        $this->assertNull($no->cmd, 'enter on No dismisses');
        $this->assertNotNull($box->update(self::key('space'), self::ctx())->cmd, 'space on Yes accepts');
    }

    public function testButtonsFollowBtopGeometry(): void
    {
        // 120x40, width 50, 2 lines: height 9, x = 60 - 25, y = 20 - 4.
        $box = self::confirm();
        $this->assertSame([35, 16, 9], $box->geometry(self::ctx(), 2));
        $map = $box->buttons(self::ctx());
        $this->assertSame([46, 20, 13, 3], $map['button1']);
        $this->assertSame([61, 20, 12, 3], $map['button2']);
        $this->assertSame(['button1'], array_keys(self::ok()->buttons(self::ctx())));
    }

    public function testClicksOnTheButtonsAcceptOrDismiss(): void
    {
        $box = self::confirm();
        $map = $box->buttons(self::ctx());
        $yes = $box->update(self::click($map['button1'][0] + 12, $map['button1'][1] + 2), self::ctx());
        $this->assertNull($yes->overlay);
        $this->assertNotNull($yes->cmd);
        $no = $box->update(self::click($map['button2'][0], $map['button2'][1]), self::ctx());
        $this->assertNull($no->overlay);
        $this->assertNull($no->cmd);
        $this->assertSame($box, $box->update(self::click(0, 0), self::ctx())->overlay, 'a click elsewhere is ignored');
        $this->assertSame($box, $box->update(self::wheel(true), self::ctx())->overlay);
    }
}

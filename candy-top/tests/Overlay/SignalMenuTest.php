<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Overlay;

use SugarCraft\Top\Collect\ProcessControl;
use SugarCraft\Top\Msg\OpenOverlayMsg;
use SugarCraft\Top\Overlay\MsgBox;
use SugarCraft\Top\Overlay\SignalMenu;
use SugarCraft\Top\Overlay\Signals;
use SugarCraft\Top\Source\Fake\FakeProcessControl;

/** btop Menu::signalChoose: grid walk, typed numbers, sending. */
final class SignalMenuTest extends OverlayTestCase
{
    private static function menu(?ProcessControl $control = null, int $pid = 1234): SignalMenu
    {
        return SignalMenu::new($pid, 'nginx', $control ?? FakeProcessControl::new());
    }

    private static function at(int $selected): SignalMenu
    {
        $r = self::feed(self::menu(), []);
        $menu = $r->overlay;
        // Type the number (btop's own way of jumping anywhere).
        foreach (str_split((string) $selected) as $d) {
            $menu = $menu?->update(self::key($d), self::ctx())->overlay;
        }
        self::assertInstanceOf(SignalMenu::class, $menu);
        self::assertSame($selected, $menu->selected);

        return $menu;
    }

    private static function step(int $from, string $key): int
    {
        $r = self::at($from)->update(self::key($key), self::ctx());
        self::assertInstanceOf(SignalMenu::class, $r->overlay);

        return $r->overlay->selected;
    }

    public function testGridWalkSkipsSixteenAndWrapsLikeBtop(): void
    {
        $this->assertSame(1, self::menu()->update(self::key('down'), self::ctx())->overlay?->selected, 'nothing selected: down picks 1');
        $this->assertSame(24, self::menu()->update(self::key('up'), self::ctx())->overlay?->selected, 'btop quirk: up from nothing lands on -1 + 25');
        $down = [1 => 6, 11 => 17, 15 => 21, 26 => 31, 27 => 2, 31 => 1, 23 => 28];
        foreach ($down as $from => $to) {
            $this->assertSame($to, self::step($from, 'down'), "down from {$from}");
            $this->assertSame($to, self::step($from, 'j'), "j from {$from}");
        }
        $up = [1 => 31, 3 => 28, 17 => 11, 21 => 15, 12 => 7, 31 => 26];
        foreach ($up as $from => $to) {
            $this->assertSame($to, self::step($from, 'up'), "up from {$from}");
            $this->assertSame($to, self::step($from, 'k'), "k from {$from}");
        }
        $this->assertSame(31, self::step(1, 'left'));
        $this->assertSame(15, self::step(17, 'left'), '16 is skipped');
        $this->assertSame(17, self::step(15, 'right'));
        $this->assertSame(1, self::step(31, 'right'));
        $this->assertSame(17, self::step(15, 'l'));
        $this->assertSame(14, self::step(15, 'h'));
    }

    public function testTypedNumbersAndBackspace(): void
    {
        $this->assertSame(15, self::at(15)->selected);
        $this->assertSame(64, self::at(7)->update(self::key('7'), self::ctx())->overlay?->selected, 'btop caps at 64');
        $this->assertSame(15, self::at(15)->update(self::key('9'), self::ctx())->overlay?->selected, 'two digits at most');
        $this->assertSame(1, self::at(15)->update(self::key('backspace'), self::ctx())->overlay?->selected);
        $this->assertSame(-1, self::at(1)->update(self::key('backspace'), self::ctx())->overlay?->selected);
    }

    public function testEnterSendsInsideTheCmdAndAFailureOpensTheErrorBox(): void
    {
        $this->assertNotNull(self::menu()->update(self::key('enter'), self::ctx())->overlay, 'nothing selected: enter does nothing');

        $control = new class () implements ProcessControl {
            /** @var list<array{int, int}> */
            public array $sent = [];

            public function signal(int $pid, int $signal): int
            {
                $this->sent[] = [$pid, $signal];

                return Signals::EPERM;
            }

            public function renice(int $pid, int $nice): int
            {
                return 0;
            }
        };
        $menu = self::menu($control);
        foreach (['1', '5'] as $d) {
            $menu = $menu->update(self::key($d), self::ctx())->overlay;
        }
        $this->assertInstanceOf(SignalMenu::class, $menu);
        $r = $menu->update(self::key('enter'), self::ctx());
        $this->assertNull($r->overlay, 'the menu closes');
        $this->assertSame([], $control->sent, 'kill() runs only in the Cmd');
        $this->assertNotNull($r->cmd);
        $failed = ($r->cmd)();
        $this->assertSame([[1234, 15]], $control->sent);
        $this->assertInstanceOf(OpenOverlayMsg::class, $failed);
        $this->assertInstanceOf(MsgBox::class, $failed->overlay);

        $ok = self::at(9)->update(self::key('space'), self::ctx());
        $this->assertNotNull($ok->cmd);
        $this->assertNull(($ok->cmd)(), 'success opens nothing');
    }

    public function testAPidBelowOneIsEsrchWithoutCallingKill(): void
    {
        $control = FakeProcessControl::new(Signals::EPERM);
        $menu = SignalMenu::new(0, '', $control)->update(self::key('9'), self::ctx())->overlay;
        $this->assertNotNull($menu);
        $cmd = $menu->update(self::key('enter'), self::ctx())->cmd;
        $this->assertNotNull($cmd);
        $msg = $cmd();
        $this->assertInstanceOf(OpenOverlayMsg::class, $msg);
        $box = $msg->overlay;
        $this->assertInstanceOf(MsgBox::class, $box);
        $this->assertStringContainsString('Process not found!', implode("\n", $box->lines(self::ctx()->ink)));
    }

    public function testMouseSelectsThenSendsAndTheHintRowsAct(): void
    {
        $map = SignalMenu::buttons(120, 40);
        // x = 60 - 40 = 20, y = 20 - 9 = 11 (1-based): signal 1 at (y + 6, x + 4).
        $this->assertSame([23, 16, 15, 1], $map['button_1']);
        $this->assertSame([23, 19, 15, 1], $map['button_17'], 'row four starts at 17');
        $this->assertArrayNotHasKey('button_16', $map);
        $this->assertSame([19, 25, 73, 1], $map['enter']);
        $this->assertSame([19, 26, 73, 1], $map['escape']);

        $picked = self::menu()->update(self::click(23 + 15, 16), self::ctx())->overlay;
        $this->assertInstanceOf(SignalMenu::class, $picked);
        $this->assertSame(2, $picked->selected);
        $this->assertNotNull($picked->update(self::click(23 + 15, 16), self::ctx())->cmd, 'clicking the selection sends it');
        $this->assertNotNull($picked->update(self::click(30, 25), self::ctx())->cmd, 'the ENTER row sends');
        $this->assertNull($picked->update(self::click(30, 26), self::ctx())->overlay, 'the ESC row aborts');
        $this->assertNull(self::menu()->update(self::key('q'), self::ctx())->overlay);
        $this->assertSame([80, 24], self::menu()->minSize());
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Overlay;

use SugarCraft\Top\Collect\ProcessControl;
use SugarCraft\Top\Msg\OpenOverlayMsg;
use SugarCraft\Top\Overlay\MsgBox;
use SugarCraft\Top\Overlay\ReniceMenu;
use SugarCraft\Top\Overlay\Signals;
use SugarCraft\Top\Source\Fake\FakeProcessControl;

/** btop Menu::reniceMenu: stepping, typing, applying. */
final class ReniceMenuTest extends OverlayTestCase
{
    private static function value(array $keys): int
    {
        $r = self::feed(ReniceMenu::new(42, 'php', FakeProcessControl::new()), $keys);
        self::assertInstanceOf(ReniceMenu::class, $r->overlay);

        return $r->overlay->value();
    }

    public function testStepsWrapInsideMinusTwentyToNineteen(): void
    {
        $this->assertSame(1, self::value(['up']));
        $this->assertSame(-1, self::value(['j']));
        $this->assertSame(-5, self::value(['left']));
        $this->assertSame(5, self::value(['l']));
        $this->assertSame(-20, self::value(['1', '9', 'up']), '19 + 1 wraps to -20');
        $this->assertSame(19, self::value(['-', '2', '0', 'down']));
        $this->assertSame(17, self::value(['-', '1', '8', 'left']), '-18 - 5 wraps by 40');
        $this->assertSame(-18, self::value(['1', '7', 'right']));
    }

    public function testTypingAndBackspace(): void
    {
        $r = self::feed(ReniceMenu::new(42, 'php', FakeProcessControl::new()), ['-', '5']);
        $this->assertInstanceOf(ReniceMenu::class, $r->overlay);
        $this->assertSame('-5', $r->overlay->edit);
        $this->assertSame(-5, $r->overlay->value());
        $this->assertSame('5', self::feed(ReniceMenu::new(1, 'x', FakeProcessControl::new()), ['5', '-'])->overlay?->edit, 'a minus only leads');
        $this->assertSame('', self::feed(ReniceMenu::new(1, 'x', FakeProcessControl::new()), ['5', 'backspace'])->overlay?->edit);
        $this->assertSame(1, self::value(['1', '5', 'backspace', 'backspace']), 'btop: emptying the field keeps the last parsed value (15 -> 1 -> 1)');
        $this->assertSame(2, self::value(['1', '5', 'backspace', 'backspace', 'up']), 'a step continues from it');
        $this->assertSame(0, self::value(['-', '3', 'backspace', 'backspace']), 'the lone minus parsed to 0 (stoi failure), and the empty field keeps that');
        $this->assertSame('', self::feed(ReniceMenu::new(1, 'x', FakeProcessControl::new()), ['5', 'up'])->overlay?->edit, 'a step clears the typed text');
        $this->assertSame(0, self::value(['-']), 'a lone minus reads 0 (btop stoi failure)');
        $this->assertSame(0, self::value(str_split('4294967276')), 'btop stoi: out of int range throws, the catch gives 0');
        $this->assertSame(0, self::value(str_split('-2147483649')));
        $this->assertSame(2147483647, self::value(str_split('2147483647')), 'in range: as typed (the kernel clamps)');
    }

    public function testEnterAppliesInsideTheCmd(): void
    {
        $control = new class () implements ProcessControl {
            /** @var list<array{int, int}> */
            public array $calls = [];

            public function signal(int $pid, int $signal): int
            {
                return 0;
            }

            public function renice(int $pid, int $nice): int
            {
                $this->calls[] = [$pid, $nice];

                return $nice < 0 ? Signals::EPERM : 0;
            }
        };
        $r = self::feed(ReniceMenu::new(42, 'php', $control), ['1', '0', 'enter']);
        $this->assertNull($r->overlay);
        $this->assertSame([], $control->calls);
        $this->assertNotNull($r->cmd);
        $this->assertNull(($r->cmd)());
        $this->assertSame([[42, 10]], $control->calls);

        $denied = self::feed(ReniceMenu::new(42, 'php', $control), ['down', 'space']);
        $this->assertNotNull($denied->cmd);
        $msg = ($denied->cmd)();
        $this->assertInstanceOf(OpenOverlayMsg::class, $msg, 'deviation: a failed renice is reported');
        $this->assertInstanceOf(MsgBox::class, $msg->overlay);

        $this->assertNull(self::feed(ReniceMenu::new(0, '', $control), ['enter'])->cmd, 'no pid, nothing to renice');
        $this->assertNull(self::feed(ReniceMenu::new(42, 'php', $control), ['escape'])->overlay);
        $this->assertNull(self::feed(ReniceMenu::new(42, 'php', $control), ['q'])->cmd);
        $this->assertSame([50, 20], ReniceMenu::new(1, 'x', $control)->minSize());
    }
}

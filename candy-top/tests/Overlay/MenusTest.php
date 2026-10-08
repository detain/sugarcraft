<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Overlay;

use SugarCraft\Top\Overlay\Menus;
use SugarCraft\Top\Overlay\MsgBox;
use SugarCraft\Top\Overlay\OverlayStack;
use SugarCraft\Top\Overlay\HelpMenu;
use SugarCraft\Top\Overlay\MainMenu;
use SugarCraft\Top\Overlay\Signals;
use SugarCraft\Top\Source\Fake\FakeProcessControl;

/** The menu factories, the signal Cmds and the stack's size rule. */
final class MenusTest extends OverlayTestCase
{
    private static function text(MsgBox $box): string
    {
        return (string) preg_replace('/\x1b\[[0-9;:]*m/', '', implode("\n", $box->lines(self::ctx()->ink)));
    }

    public function testSignalReturnNamesEachErrno(): void
    {
        $cases = [Signals::EINVAL => 'Unsupported signal!', Signals::EPERM => 'Insufficient permissions to send signal!', Signals::ESRCH => 'Process not found!', 99 => 'Unknown error! (errno: 99)'];
        foreach ($cases as $errno => $text) {
            $box = Menus::signalReturn($errno);
            $this->assertInstanceOf(MsgBox::class, $box);
            $this->assertSame('error', $box->title);
            $this->assertSame("Failure:\n" . $text, self::text($box));
        }
    }

    public function testSignalSendTitlesAndText(): void
    {
        $term = Menus::signalSend(FakeProcessControl::new(), 77, "evil\x1bname", Signals::SIGTERM);
        $this->assertInstanceOf(MsgBox::class, $term);
        $this->assertSame('SIGTERM', $term->title);
        $this->assertSame(MsgBox::YES_NO, $term->type);
        $this->assertSame("Send signal: 15 (SIGTERM)\nTo PID: 77 (evil name)", self::text($term), 'names are control-stripped');
        $this->assertSame('SIGKILL', $this->title(Signals::SIGKILL));
        $this->assertSame('signal', $this->title(1), 'btop titles only signals above 1');
        $this->assertSame('signal', $this->title(17), 'and never SIGCHLD');
    }

    private function title(int $signal): string
    {
        $box = Menus::signalSend(FakeProcessControl::new(), 1, 'x', $signal);
        $this->assertInstanceOf(MsgBox::class, $box);

        return $box->title;
    }

    public function testSizeErrorAndOptionsPlaceholder(): void
    {
        $size = Menus::sizeError();
        $this->assertInstanceOf(MsgBox::class, $size);
        $this->assertSame(45, $size->width);
        $this->assertSame("Error:\nTerminal size too small to\ndisplay menu or box!", self::text($size));
        $this->assertInstanceOf(MsgBox::class, Menus::options());
        $this->assertSame('options', Menus::options()->title);
        $this->assertInstanceOf(MainMenu::class, Menus::main());
        $this->assertInstanceOf(HelpMenu::class, Menus::help());
    }

    public function testSendSignalAndReniceCmds(): void
    {
        $this->assertNull((Menus::sendSignal(FakeProcessControl::new(), 5, 15))());
        $this->assertNotNull((Menus::sendSignal(FakeProcessControl::new(), 0, 15))(), 'pid 0 never reaches kill()');
        $this->assertNull((Menus::renice(FakeProcessControl::new(), 5, 3))());
        $this->assertNotNull((Menus::renice(FakeProcessControl::new(Signals::EPERM), 5, -3))());
    }

    public function testStackPushPopAndBtopsSizeRule(): void
    {
        $stack = OverlayStack::new();
        $this->assertTrue($stack->isEmpty());
        $this->assertNull($stack->top());
        $this->assertSame($stack, $stack->replaceTop(null, 120, 40), 'popping an empty stack is a no-op');
        $stack = $stack->push(Menus::main(), 120, 40)->push(Menus::help(), 120, 40);
        $this->assertSame(2, $stack->count());
        $this->assertInstanceOf(HelpMenu::class, $stack->top());
        $this->assertInstanceOf(MainMenu::class, $stack->replaceTop(null, 120, 40)->top());
        $this->assertInstanceOf(HelpMenu::class, $stack->replaceTop(Menus::help(), 70, 20)->top(), 'a menu staying on top is not re-checked');
        $shrunk = $stack->replaceTop(null, 79, 24);
        $this->assertSame(1, $shrunk->count(), 'btop: the uncovered menu no longer fits');
        $this->assertSame('error', $shrunk->top()?->title ?? null);
        $this->assertTrue(OverlayStack::new()->push(Menus::help(), 120, 40)->replaceTop(null, 10, 5)->isEmpty(), 'nothing uncovered, nothing checked');

        $small = $stack->push(Menus::help(), 79, 24);
        $this->assertSame(1, $small->count(), 'btop menuMask.reset(): every pending menu goes');
        $this->assertInstanceOf(MsgBox::class, $small->top());
        $this->assertSame('error', $small->top()->title);
        $this->assertSame(2, OverlayStack::new()->push(Menus::sizeError(), 120, 40)->push(Menus::signalReturn(1), 50, 20)->count(), 'a msgBox needs only 50x20');
        $this->assertSame(1, OverlayStack::new()->push(Menus::sizeError(), 10, 5)->count(), 'the size error itself always shows');
    }
}

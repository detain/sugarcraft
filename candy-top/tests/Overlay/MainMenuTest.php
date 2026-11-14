<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Overlay;

use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Msg\QuitRequestMsg;
use SugarCraft\Top\Overlay\HelpMenu;
use SugarCraft\Top\Overlay\MainMenu;
use SugarCraft\Top\Overlay\OptionsMenu;
use SugarCraft\Top\View\Surface;

/** btop Menu::mainMenu: selection, Switch to help/options, quit, mouse. */
final class MainMenuTest extends OverlayTestCase
{
    public function testSelectionWrapsWithEveryBtopKey(): void
    {
        foreach (['down', 'tab', 'j'] as $k) {
            $r = self::feed(MainMenu::new(), [$k, $k, $k]);
            $this->assertInstanceOf(MainMenu::class, $r->overlay);
            $this->assertSame(0, $r->overlay->selected, $k . ' wraps after Quit');
        }
        foreach (['up', 'shift_tab', 'k'] as $k) {
            $r = self::feed(MainMenu::new(), [$k]);
            $this->assertInstanceOf(MainMenu::class, $r->overlay);
            $this->assertSame(MainMenu::QUIT, $r->overlay->selected, $k . ' wraps to Quit');
        }
        $r = MainMenu::new()->update(self::wheel(true), self::ctx());
        $this->assertInstanceOf(MainMenu::class, $r->overlay);
        $this->assertSame(1, $r->overlay->selected);
        $this->assertSame([80, 24], MainMenu::new()->minSize());
    }

    public function testEnteringSwitchesOnTopOrQuits(): void
    {
        $options = self::feed(MainMenu::new(), ['enter']);
        $this->assertInstanceOf(MainMenu::class, $options->overlay, 'the main menu stays underneath');
        $this->assertInstanceOf(OptionsMenu::class, $options->push);

        $help = self::feed(MainMenu::new(), ['down', 'space']);
        $this->assertInstanceOf(HelpMenu::class, $help->push);
        $this->assertInstanceOf(MainMenu::class, $help->overlay);
        $this->assertSame(0, $help->overlay->selected, 'btop clears bg on Switch: it comes back reset');

        $quit = self::feed(MainMenu::new(), ['up', 'enter']);
        $this->assertNull($quit->overlay);
        $this->assertNotNull($quit->cmd);
        $this->assertInstanceOf(QuitRequestMsg::class, ($quit->cmd)(), 'the App answers with its saving quit Cmd');
    }

    public function testEscapeQAndMClose(): void
    {
        foreach (['escape', 'q', 'm'] as $k) {
            $this->assertNull(MainMenu::new()->update(self::key($k), self::ctx())->overlay, $k);
        }
        $this->assertNull(MainMenu::new()->update(self::click(0, 0), self::ctx())->overlay, 'a click outside the entries closes');
        $this->assertNotNull(MainMenu::new()->update(self::key('x'), self::ctx())->overlay);
    }

    public function testEntriesAreClickable(): void
    {
        $map = MainMenu::buttons(120, 40);
        // y = 40/2 - 10 = 10; entries from y + 7, centred on 60.
        $this->assertSame([50, 16, 19, 3], $map['button_0']);
        $this->assertSame([53, 19, 12, 3], $map['button_1']);
        $this->assertSame([53, 22, 12, 3], $map['button_2']);
        $picked = MainMenu::new()->update(self::click(55, 20), self::ctx());
        $this->assertInstanceOf(MainMenu::class, $picked->overlay);
        $this->assertSame(1, $picked->overlay->selected, 'a click selects');
        $this->assertInstanceOf(HelpMenu::class, $picked->overlay->update(self::click(55, 21), self::ctx())->push, 'a click on the selection enters it');
    }

    public function testTtyModeKeepsTheFrameCharFocusSwap(): void
    {
        // Finding 137: tty_mode used to paint every entry with the light
        // NORMAL art, collapsing focus to colour alone — invisible on
        // greyscale, whiteout and NO_COLOR hosts. The selected entry now
        // keeps the double-line SELECTED art in every mode.
        $c = self::ctx(120, 40, Config::new()->with('tty_mode', true));
        $surface = Surface::new($c->cols, $c->rows);
        MainMenu::new()->paint($surface, $c);
        $text = implode("\n", $surface->plainLines());
        $this->assertStringContainsString('╔═╗╔═╗', $text, 'the selected OPTIONS entry keeps the double-line art under tty_mode');
        $this->assertStringContainsString('┌─┐', $text, 'unselected entries stay light-lined');

        // And the double-line art follows the move: the OPTIONS-family
        // selected row is gone, the HELP-family selected row is present.
        $moved = self::feed(MainMenu::new(), ['down']);
        $this->assertInstanceOf(MainMenu::class, $moved->overlay);
        $second = Surface::new($c->cols, $c->rows);
        $moved->overlay->paint($second, $c);
        $after = implode("\n", $second->plainLines());
        $this->assertStringNotContainsString('╔═╗╔═╗', $after, 'deselected OPTIONS reverts to light art');
        $this->assertStringContainsString('╦ ╦╔═╗', $after, 'selected HELP paints its double-lined art');
    }
}

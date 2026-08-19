<?php
declare(strict_types=1);
namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tui\Components\MenuBar;

final class MenuDropdownTest extends TestCase
{
    protected function tearDown(): void { MenuBar::closeMenu(); }

    public function testDropdownAppearsInTheShellFrameWhenOpen(): void
    {
        $p = $this->createMock(ProviderInterface::class);
        $p->method('name')->willReturn('stub');
        $chat = (new Chat(history: [Message::user('hi')]))->withSize(100, 30);
        $app = App::new($p, 'm')->withChat($chat);

        MenuBar::closeMenu();
        $closed = $app->view();
        $closedBody = is_string($closed) ? $closed : $closed->body;

        MenuBar::openMenu(1);
        $open = $app->view();
        $openBody = is_string($open) ? $open : $open->body;

        $plain = static fn(string $s): string => preg_replace('/\e\[[0-9;]*m/', '', $s) ?? '';

        $this->assertNotSame($plain($closedBody), $plain($openBody), 'frame must change when a menu opens');
        // The items themselves, not just a highlighted title: opening a menu
        // used to recolour its name and show nothing, because getMenuItems()
        // had no caller and render() drew no panel.
        $this->assertStringContainsString('New session', $plain($openBody));
        $this->assertStringContainsString('Switch session', $plain($openBody));

        // The overlay must not add or remove rows -- it floats over the frame
        // so the panes below keep the geometry the layout gave them.
        $this->assertSame(
            substr_count($plain($closedBody), "\n"),
            substr_count($plain($openBody), "\n"),
            'dropdown must overlay, not reflow',
        );
    }
    /**
     * Two claims the source makes and nothing asserted, both found by mutation:
     * replacing the ACTIVE menu title's colour with the inactive one, and the
     * dropdown cursor row's style with the ordinary item style, left all 501
     * tests under tests/Tui green.
     *
     * The property is DISTINCTNESS, not a particular colour — which token plays
     * which role is the palette's business (W3), but "you can see which menu is
     * open" and "you can see which row Enter will run" are the bar's contract.
     * Asserted on the rendered bytes, in both directions: the highlighted thing
     * differs from its unhighlighted self, and the unhighlighted siblings agree
     * with each other.
     */
    public function testTheOpenMenuTitleAndTheCursorRowAreVisiblyDistinct(): void
    {
        $p = $this->createMock(ProviderInterface::class);
        $p->method('name')->willReturn('stub');
        $chat = (new Chat(history: [Message::user('hi')]))->withSize(120, 40);
        $app = App::new($p, 'm')->withChat($chat);
        $theme = $app->theme();

        // --- the open menu's title -------------------------------------------
        MenuBar::closeMenu();
        $closedBar = MenuBar::render($app, 120);

        MenuBar::openMenu(1);
        $openBar = MenuBar::render($app, 120);

        $this->assertNotSame(
            $closedBar,
            $openBar,
            'the open menu title must be painted differently from a closed one',
        );

        // --- the dropdown cursor row -----------------------------------------
        $rows = MenuBar::renderDropdown($theme);
        // [0] is the top border and [count-1] the bottom, so the item rows are
        // the slice between them; the cursor starts on the first.
        $this->assertGreaterThan(3, count($rows), 'need at least two item rows');
        $cursorRow = $rows[1];
        $plainRow = $rows[2];

        $strip = static fn(string $s): string => preg_replace('/\e\[[0-9;]*m/', '', $s) ?? '';

        // Same shape, different bytes: the difference is styling, not content
        // width, so the panel stays rectangular.
        $this->assertSame(mb_strlen($strip($cursorRow)), mb_strlen($strip($plainRow)));
        $this->assertNotSame(
            preg_replace('/[^\e\[0-9;m]/', '', $cursorRow),
            preg_replace('/[^\e\[0-9;m]/', '', $plainRow),
            'the row Enter would select must be styled differently from the others',
        );

        // ...and the OTHER direction: two non-cursor rows must share styling, or
        // the assertion above would pass on a panel that styled every row
        // differently and highlighted nothing.
        $this->assertGreaterThan(4, count($rows), 'need at least three item rows');
        $this->assertSame(
            preg_replace('/[^\e\[0-9;m]/', '', $plainRow),
            preg_replace('/[^\e\[0-9;m]/', '', $rows[3]),
            'non-cursor rows must all be painted the same way',
        );
    }
}

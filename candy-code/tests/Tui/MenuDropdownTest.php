<?php
declare(strict_types=1);
namespace SugarCraft\Code\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Tui\Components\MenuBar;
use SugarCraft\Code\Tui\Renderer as TuiRenderer;
use SugarCraft\Core\Util\Ansi;

final class MenuDropdownTest extends TestCase
{
    protected function tearDown(): void
    {
        MenuBar::closeMenu();
        TuiRenderer::setSize(200, 60); // leave the process-global size cache as found (round-61 flake law)
    }

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

    /**
     * A dropdown taller than the frame used to vanish into the bottom edge
     * silently: the rows past the last line were simply never painted, so a
     * clipped menu was indistinguishable from a short one. Now the list
     * paints down to the row above the frame's last, and the last row
     * carries a faint '…' continuation inside the panel's own box — while
     * the frame keeps its exact height, because the overlay floats over the
     * frame and never reflows it.
     */
    public function testADropdownTallerThanTheFrameAnnouncesItsOwnClipping(): void
    {
        $panel = $this->openChatMenuPanel();
        $rows = count($panel) - 2; // budget below the anchor is short by three items

        TuiRenderer::setSize(100, $rows);
        $body = Ansi::strip(TuiRenderer::renderView($this->menuApp(), 100, $rows)->body);
        $lines = explode("\n", $body);

        $this->assertCount($rows, $lines, 'the dropdown must overlay the frame, never change its row count');

        $last = (string) end($lines);
        $this->assertMatchesRegularExpression('/│ …/u', $last, "the clipped panel must announce the cut on the frame's last row: <{$last}>");

        // It paints from the top: the first item survives, the tail does not.
        $first = $this->dropdownLabel($panel[1]);
        $final = $this->dropdownLabel($panel[count($panel) - 2]);
        $this->assertStringContainsString($first, $body, 'the panel must still paint from its head');
        $this->assertStringNotContainsString($final, $body, "the clipped tail ({$final}) must not be painted past the frame");
    }

    /**
     * The other half of the pair: when the whole panel fits, no ellipsis is
     * invented and every item is visible — the indicator is a clipping
     * announcement, not panel furniture.
     */
    public function testADropdownThatFitsCarriesNoOverflowIndicator(): void
    {
        $panel = $this->openChatMenuPanel();
        $rows = count($panel) + 6; // the same panel, comfortably hostable

        TuiRenderer::setSize(100, $rows);
        $body = Ansi::strip(TuiRenderer::renderView($this->menuApp(), 100, $rows)->body);

        $this->assertDoesNotMatchRegularExpression('/│ …/u', $body, 'a panel that fits must not announce a cut');
        $this->assertStringContainsString($this->dropdownLabel($panel[count($panel) - 2]), $body, 'every item of a fitting panel must be painted');
    }

    private function menuApp(): App
    {
        $p = $this->createMock(ProviderInterface::class);
        $p->method('name')->willReturn('stub');

        return App::new($p, 'm')->withChat(new Chat(history: [Message::user('hi')]));
    }

    /**
     * @return list<string> the open menu's styled panel lines, top border to bottom border
     */
    private function openChatMenuPanel(): array
    {
        MenuBar::closeMenu();
        MenuBar::openMenu(1);
        $panel = MenuBar::renderDropdown($this->menuApp()->theme());
        $this->assertGreaterThanOrEqual(6, count($panel), 'the menu under test needs enough items to overflow a short frame');

        return $panel;
    }

    /** The plain label carried by one styled panel item row ('│ label …pad │'). */
    private function dropdownLabel(string $row): string
    {
        $plain = Ansi::strip($row);
        $inner = preg_replace(['/^\x{2502} /u', '/\x{2502}$/u'], '', $plain);

        return rtrim((string) $inner);
    }
}

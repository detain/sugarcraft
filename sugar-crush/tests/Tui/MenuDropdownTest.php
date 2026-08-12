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
}

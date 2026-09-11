<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Tui\Components\MenuBar;
use SugarCraft\Crush\Tui\Pane;

/**
 * E682 — the composite adopts the abandoned palette surface (E12-composite
 * carry on E666).
 *
 * E666 made an abandoned palette CLOSE at the keyboard choke point on the
 * next fall-through keystroke; what remained was the paint side: the F10 menu
 * and skill-picker states still painted the palette the keyboard was no
 * longer driving — the painted-but-undrivable ghost the composite chain's own
 * ONE rule forbids. These tests pin paint/drive parity:
 *
 * - suppressed while abandoned (the menu state; the agents dashboard already
 *   painted nothing and stays pinned by E666's own test);
 * - NOT suppressed where the palette is visible and drivable (a sidebar pane
 *   paints and drives alike);
 * - suppression is paint-only — the frame never fabricates the closure that
 *   belongs to {@see App::delegateToChat()};
 * - the signal is frame-local — a standalone render after a suppressed shell
 *   frame still paints the palette (the self-healing default).
 *
 * The skill-picker state shares both the predicate and the choke point with
 * the menu state; the live picker needs a non-empty skill roster, so its
 * door is pinned through the predicate rather than a second full fixture.
 */
final class AbandonedPaletteIsNotCompositedTest extends TestCase
{
    private ProviderInterface $provider;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
        Renderer::scanner()->clear();
    }

    protected function tearDown(): void
    {
        $this->resetMenuBarState();
    }

    public function testTheMenuStateStopsPaintingAPaletteItCannotDrive(): void
    {
        $open = $this->appWithPaletteOpen();

        // Fixture: with the chat driving, the palette is painted.
        $this->assertStringContainsString('🔍', self::body($open), 'fixture: the chat pane paints its palette');

        MenuBar::openMenu(1);
        $this->assertStringNotContainsString(
            '🔍',
            self::body($open),
            'E682: the composite does not paint a palette the keyboard is not driving',
        );

        // Suppressing is not closing: E666 owns the closure at the delivery
        // choke point, and painting a frame must not mutate the model.
        $this->assertNotNull($open->chat?->palette(), 'the suppressed frame leaves the state to the keystroke');
    }

    public function testASidebarPaneStillPaintsThePaletteItDrives(): void
    {
        $sidebar = $this->appWithPaletteOpen()->withPane(Pane::Files);

        $this->assertStringContainsString(
            '🔍',
            self::body($sidebar),
            'a visible, drivable palette is nobody\'s to suppress — negative scope of E682',
        );
    }

    public function testTheSignalIsFrameLocalToTheShell(): void
    {
        $open = $this->appWithPaletteOpen();
        MenuBar::openMenu(1);
        self::body($open); // the suppressed shell frame

        // Once the shell stops compositing this Chat, the standalone path —
        // which never sets the flag — paints it again: no stale suppression.
        $chat = $open->chat;
        $this->assertInstanceOf(Chat::class, $chat);
        $this->assertStringContainsString(
            '🔍',
            Renderer::render($chat),
            'the abandonment flag lives exactly as long as the frame that set it',
        );
    }

    private function appWithPaletteOpen(): App
    {
        [$app] = App::new($this->provider, 'gpt-4')
            ->withChat(new Chat(
                history: [Message::user('hello'), Message::assistant('hi')],
                backend: new EchoBackend(),
            ))
            ->update(new WindowSizeMsg(100, 30));

        [$open] = $app->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $this->assertNotNull($open->chat?->palette(), 'fixture: Ctrl+P opens the palette in Pane::Chat');

        return $open;
    }

    private static function body(App $app): string
    {
        $view = $app->view();

        return is_string($view) ? $view : $view->body;
    }

    /**
     * MenuBar keeps its menu index and row cursor in statics; reset BOTH so
     * an open menu can never leak into a later test (same guard as
     * KeyboardHandlerTest::resetMenuBarState()).
     */
    private function resetMenuBarState(): void
    {
        $reflection = new ReflectionClass(MenuBar::class);
        foreach (['activeMenu', 'activeItem'] as $name) {
            $reflection->getProperty($name)->setValue(null, 0);
        }
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tui\Commands\CommandPaletteCmd;
use SugarCraft\Crush\Tui\Components\MenuBar;
use SugarCraft\Crush\Tui\KeyboardHandler;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;
use SugarCraft\Layout\Dock\DockLayout;
use SugarCraft\Layout\Dock\Side;

/**
 * Docking L2 feature 3 — Tab moves focus across Chat plus the DOCKED panes,
 * in frame order (left column top-to-bottom, then right), and Enter is the
 * palette door for the read-only docked surfaces.
 *
 * The cycle replaced the full-strip ring walk ({@see Pane::next()}) the
 * sidebar era shipped with: once the menu bar toggles panes on and off,
 * "the next pane" means the next pane the frame persistently shows, and a
 * focus parked off that list (an undocked pane reached by a ctrl chord)
 * folds to Chat — the same anchor rule the ring states for off-strip panes.
 *
 * The Tab-in-input privilege is pinned from the other side here: the popup
 * keeps completion ONLY while Chat itself holds focus (the flipped sidebar
 * pin lives in SlashMenuTabCompletionTest), and every Enter polarity —
 * door, submit-preservation, Chat-mode silence — is answered here.
 *
 * @see App::paneCycleOrder()
 * @see App::cyclePaneFocus()
 * @see KeyboardHandler (claims() Tab/Enter arms)
 */
final class PaneFocusCycleTest extends TestCase
{
    private ProviderInterface $provider;

    /** @var list<array<string, mixed>> $manifests */
    private array $manifests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = $this->createMock(ProviderInterface::class);
        TuiRenderer::setSize(200, 60);
    }

    protected function tearDown(): void
    {
        TuiRenderer::setSize(200, 60);
        MenuBar::closeMenu();
    }

    private function shell(): App
    {
        return App::new($this->provider, 'test-model')
            ->withChat(new Chat())
            ->withOnLayoutChange(function (array $manifest): void {
                $this->manifests[] = $manifest;
            });
    }

    // =====================================================================
    // Cycle membership and order
    // =====================================================================

    public function testCycleIsChatThenLeftStackThenRightStack(): void
    {
        // Default dock: files left. Stack Tools ABOVE it by index, then dock
        // two right-side panes in order.
        $app = $this->shell()
            ->setPaneSide(Pane::Tools, Side::Left, 0)
            ->togglePaneDocking(Pane::Skills)
            ->togglePaneDocking(Pane::Settings);

        $this->assertSame(
            [Pane::Chat, Pane::Tools, Pane::Files, Pane::Skills, Pane::Settings],
            $app->paneCycleOrder(),
            'cycle must follow the painted frame: left column top-to-bottom, then right',
        );
    }

    public function testUndockingNarrowsTheCycle(): void
    {
        $app = $this->shell()->togglePaneDocking(Pane::Agents);
        $this->assertSame([Pane::Chat, Pane::Files, Pane::Agents], $app->paneCycleOrder());

        $app = $app->togglePaneDocking(Pane::Agents);
        $this->assertSame([Pane::Chat, Pane::Files], $app->paneCycleOrder());
    }

    public function testFullWidthChatCycleDegeneratesToChatAlone(): void
    {
        // Coercion: every pane off → the center takes the full width and Tab
        // is a no-op rather than a dead end (resolve() degradation already
        // pinned in the dock suite; this pins it end-to-end through the key).
        $app = $this->shell()->withDock(DockLayout::new('chat'))->withPane(Pane::Chat);
        $this->assertSame([Pane::Chat], $app->paneCycleOrder());

        [$next] = (new KeyboardHandler())->handle('tab', $app);
        $this->assertSame(Pane::Chat, $next->pane);

        [$next] = (new KeyboardHandler())->handle('shift+tab', $app);
        $this->assertSame(Pane::Chat, $next->pane);
    }

    // =====================================================================
    // Walking
    // =====================================================================

    public function testForwardAndBackwardWalksWrapWithinTheDockedSet(): void
    {
        $app = $this->shell()
            ->togglePaneDocking(Pane::Tools)
            ->togglePaneDocking(Pane::Settings);
        // Cycle: [Chat, Files, Tools, Settings].
        $handler = new KeyboardHandler();

        $walk = $app;
        foreach ([Pane::Files, Pane::Tools, Pane::Settings, Pane::Chat] as $expected) {
            [$walk] = $handler->handle('tab', $walk);
            $this->assertSame($expected, $walk->pane);
        }

        $walk = $app;
        foreach ([Pane::Settings, Pane::Tools, Pane::Files, Pane::Chat] as $expected) {
            [$walk] = $handler->handle('shift+tab', $walk);
            $this->assertSame($expected, $walk->pane);
        }
    }

    public function testOffCycleFocusFoldsToChatInBothDirections(): void
    {
        // Agents reached by its ctrl chord without being docked is the live
        // off-cycle state: Tab is "get me out", and Chat always draws.
        $app = $this->shell()->withPane(Pane::Agents);

        [$forward] = (new KeyboardHandler())->handle('tab', $app);
        $this->assertSame(Pane::Chat, $forward->pane);

        [$backward] = (new KeyboardHandler())->handle('shift+tab', $app);
        $this->assertSame(Pane::Chat, $backward->pane);
    }

    public function testCyclingFocusPersistsNothing(): void
    {
        // Focus is not layout: only the two docking mutations may write; a
        // full round-trip of the cycle must add zero manifests.
        $app = $this->shell()->togglePaneDocking(Pane::Tools);
        $this->assertCount(1, $this->manifests, 'fixture: the implicit default dock writes nothing; the toggle wrote once');

        $handler = new KeyboardHandler();
        for ($i = 0; $i < 6; $i++) {
            [$app] = $handler->handle('tab', $app);
        }

        $this->assertCount(1, $this->manifests, 'Tab focus cycling wrote the layout manifest');
    }

    // =====================================================================
    // The Enter palette door
    // =====================================================================

    public function testEnterOpensThePaletteFromADockedPaneWithAnEmptyDraft(): void
    {
        $app = $this->shell()->withPane(Pane::Files);

        $result = (new KeyboardHandler())->handleKeyMsg(new KeyMsg(KeyType::Enter), $app);

        $this->assertNotNull($result, 'the shell did not claim Enter on the docked pane');
        $this->assertInstanceOf(CommandPaletteCmd::class, $result[1]);
    }

    public function testEnterWithADraftInProgressStillFallsThroughToChat(): void
    {
        $typed = new KeyMsg(KeyType::Char, 'h');
        [$app] = $this->shell()->withPane(Pane::Files)->update($typed);

        $this->assertSame('h', $app->chat->inputBuf, 'fixture: the draft is non-empty');
        $this->assertNull(
            (new KeyboardHandler())->handleKeyMsg(new KeyMsg(KeyType::Enter), $app),
            'Enter was claimed while a draft was in progress — Chat must submit it',
        );
    }

    public function testEnterInChatModeNeverTakesTheDoor(): void
    {
        $this->assertNull(
            (new KeyboardHandler())->handleKeyMsg(new KeyMsg(KeyType::Enter), $this->shell()),
            'Enter belongs to Chat while Chat holds focus',
        );
    }

    public function testEnterOnTheAgentsDashboardDoesNotOpenAnAbandonedPalette(): void
    {
        // shellOwnsKeyboard excludes shell-owned views from the door: their
        // Enter belongs to their own selection semantics, and a palette
        // opened under them is the E666 abandoned-modal defect.
        $app = $this->shell()->withPane(Pane::Agents);

        $result = (new KeyboardHandler())->handleKeyMsg(new KeyMsg(KeyType::Enter), $app);

        $this->assertNotNull($result, 'fixture: the dashboard owns Enter');
        $this->assertNotInstanceOf(CommandPaletteCmd::class, $result[1] ?? new \stdClass());
    }

    // =====================================================================
    // The return hatch
    // =====================================================================

    public function testEscapeFromADockedPaneReturnsFocusToChat(): void
    {
        $app = $this->shell()->withPane(Pane::Tools);

        $result = (new KeyboardHandler())->handleKeyMsg(new KeyMsg(KeyType::Escape), $app);

        $this->assertNotNull($result, 'the shell did not claim Escape on a docked pane');
        $this->assertSame(Pane::Chat, $result[0]->pane);
    }

    public function testTypingStillReachesTheChatDraftFromADockPane(): void
    {
        // The capability-matrix fact the door leans on: plain characters are
        // never claimed, so the draft stays global in every focus state.
        $app = $this->shell()->withPane(Pane::Files);

        [$next] = $app->update(new KeyMsg(KeyType::Char, 'x'));

        $this->assertSame('x', $next->chat->inputBuf);
    }
}

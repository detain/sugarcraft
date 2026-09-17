<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Session\SessionStore;
use SugarCraft\Crush\Tui\SessionPicker;
use SugarCraft\Forms\ItemList\LoadMoreMsg;
use SugarCraft\Mouse\Zone;

/**
 * E744 WS4/WS5 end to end at the frame: the session picker's rows carry click
 * zones that outrank the background, wheel notches move the selection while
 * the picker owns the pointer, and the ItemList load-more edge — raised
 * through the WS1 relay as a keypress or a wheel notch — widens the store
 * page inside Chat::update().
 *
 * Zone-level mechanics (marking, whitelisting, refusal of the frame behind)
 * follow the palette precedent; {@see \SugarCraft\Crush\Tests\MouseModalGuardTest}
 * and {@see \SugarCraft\Crush\Tests\PaletteClickTest} remain the authority on
 * those halves for the palette, and this file is the picker's counterpart.
 *
 * @internal
 */
final class SessionPickerMouseTest extends TestCase
{
    private const VARS = ['SUGARCRUSH_DISABLE_MOUSE', 'SUGARCRUSH_DISABLE_MOUSE_CLICKS'];

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }
        Renderer::scanner()->clear();
        (new ReflectionProperty(Chat::class, 'clickTracker'))->setValue(null, null);
    }

    protected function tearDown(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }
        Renderer::scanner()->clear();
        (new ReflectionProperty(Chat::class, 'clickTracker'))->setValue(null, null);
        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    // =========================================================================
    // Click-to-select on the picker's own rows
    // =========================================================================

    public function testEveryPaintedPickerRowIsRegisteredAsASessionRowZone(): void
    {
        $chat = $this->pickerUp(3);
        $picker = $chat->sessionPicker();
        [$overlayWidth, $overlayHeight] = SessionPicker::overlayGeometry($chat->cols(), $chat->rows(), Renderer::SHELL_CHROME_COLS);
        $zoneLines = $picker->rowZoneLines($overlayWidth, $overlayHeight, $chat->theme());

        foreach (array_keys($zoneLines) as $row) {
            $zone = Renderer::scanner()->get(Renderer::SESSION_ROW_ZONE_PREFIX . $row);
            self::assertInstanceOf(Zone::class, $zone, "row {$row} is not clickable");
            self::assertSame($zone->startRow, $zone->endRow, 'single-row zone, like every other overlay mark');
        }
    }

    public function testClickOnAPickerRowSelectsItAndLeavesThePickerUp(): void
    {
        $chat = $this->pickerUp(3);
        $zone = Renderer::scanner()->get(Renderer::SESSION_ROW_ZONE_PREFIX . 2);
        self::assertInstanceOf(Zone::class, $zone);
        self::assertSame(0, $chat->sessionPicker()->selectedIndex(), 'fixture: row 0 starts selected');

        [$pressed] = $chat->update(new MouseClickMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Press));
        [$clicked, $cmd] = $pressed->update(new MouseReleaseMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Release));

        self::assertNotNull($clicked->sessionPicker(), 'a click SELECTS, it does not activate — resume stays behind Enter');
        self::assertSame(2, $clicked->sessionPicker()->selectedIndex());
        self::assertSame(0, $clicked->scrollOffset(), 'selecting a row never touches the transcript');
        self::assertNull($cmd, 'a mid-list selection raises no edge');
    }

    public function testTheBackgroundStillRefusesWhileThePickerIsUp(): void
    {
        $chat = $this->pickerUp(3);

        // The frame BEHIND the picker still marks its zones; clicking one must
        // stay swallowed exactly as before E744 — the narrowing of the picker
        // arm whitelists the picker's rows, not the world around them.
        $menu = Renderer::scanner()->get(Renderer::PANE_ZONE_PREFIX . 'menu');
        self::assertInstanceOf(Zone::class, $menu);

        [$pressed] = $chat->update(new MouseClickMsg($menu->startCol, $menu->startRow, MouseButton::Left, MouseAction::Press));
        [$after, $cmd] = $pressed->update(new MouseReleaseMsg($menu->startCol, $menu->startRow, MouseButton::Left, MouseAction::Release));

        self::assertNull($cmd, 'the refused background click hands back nothing');
        self::assertNull($after->palette(), 'the click that landed behind the picker opened nothing');
        self::assertNotNull($after->sessionPicker(), 'and the picker survives it');
    }

    // =========================================================================
    // Wheel: pointer-local while the picker is up
    // =========================================================================

    public function testWheelNotchesWalkThePickerSelection(): void
    {
        $chat = $this->pickerUp(3);

        [$down] = $chat->update(new MouseWheelMsg(10, 10, MouseButton::WheelDown, MouseAction::Press));
        self::assertSame(1, $down->sessionPicker()->selectedIndex());
        self::assertSame(0, $down->scrollOffset());

        [$up] = $down->update(new MouseWheelMsg(10, 10, MouseButton::WheelUp, MouseAction::Press));
        self::assertSame(0, $up->sessionPicker()->selectedIndex());
    }

    // =========================================================================
    // The load-more edge end to end
    // =========================================================================

    public function testBrowsingToTheEndOfTheFirstPageWidensTheStoreFetch(): void
    {
        $chat = $this->pickerUp(25);
        $picker = $chat->sessionPicker();
        self::assertSame(SessionPicker::PAGE_SIZE, $picker->count(), 'fixture: the picker opens on one page');
        self::assertTrue($picker->needsStoreFetch(), 'fixture: the page filled its limit, so more may exist');

        $current = $chat;
        $edge = null;
        for ($step = 0; $step < SessionPicker::PAGE_SIZE - 1 && $edge === null; $step++) {
            [$current, $edge] = $current->update(new KeyMsg(KeyType::Down));
        }
        self::assertNotNull($edge, 'the arrival on the last loaded row reached the frame as a Cmd');
        self::assertInstanceOf(LoadMoreMsg::class, $edge(), 'relayed, not swallowed, not rewritten');

        [$grown] = $current->update($edge());
        self::assertSame(25, $grown->sessionPicker()->count(), 'the widened top-N fetch grew the page under the cursor');
        self::assertSame(SessionPicker::PAGE_SIZE - 1, $grown->sessionPicker()->selectedIndex(), 'and the cursor stayed put');
        self::assertFalse($grown->sessionPicker()->needsStoreFetch(), '25 < 40 closed the edge — a short fetch ends the walk');

        // Walking on from here never refires: the rest of the list is in hand.
        $quiet = $grown;
        for ($step = 0; $step < SessionPicker::PAGE_SIZE; $step++) {
            [$quiet, $cmd] = $quiet->update(new KeyMsg(KeyType::Down));
            self::assertNull($cmd, 'no phantom LoadMore after exhaustion');
        }
        self::assertSame(24, $quiet->sessionPicker()->selectedIndex(), 'and it clamps at the true last row');
    }

    public function testASinglePagePickerNeverRaisesTheEdge(): void
    {
        $chat = $this->pickerUp(3);

        [$one] = $chat->update(new KeyMsg(KeyType::Down));
        [$two] = $one->update(new KeyMsg(KeyType::Down));
        [$end, $cmd] = $two->update(new KeyMsg(KeyType::Down));

        self::assertSame(2, $end->sessionPicker()->selectedIndex(), "fixture: arrived on the only page's last row");
        self::assertNull($cmd, 'the store closed the edge at open time: silence is the whole answer');
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /**
     * A Chat with a store holding $count named sessions, opened on the picker
     * through the real Ctrl+R route, and painted once so the zone registry
     * answers.
     */
    private function pickerUp(int $count): Chat
    {
        $dir = sys_get_temp_dir() . '/crush_picker_mouse_' . uniqid('', true);
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;

        $store = new SessionStore($dir . '/sessions.db');
        for ($i = 0; $i < $count; $i++) {
            $store->createSession(sprintf('session-%02d', $i), 'echo', 'echo-1', null, "Session {$i}");
        }

        $chat = new Chat(
            history: [Message::user('hello')],
            sessionStore: $store,
            currentSessionId: 'session-00',
        );

        [$opened] = $chat->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        self::assertNotNull($opened->sessionPicker(), "fixture: Ctrl+R opened the picker over {$count} sessions");
        $opened->view();

        return $opened;
    }
}

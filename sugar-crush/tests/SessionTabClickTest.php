<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseMotionMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Session\SessionStore;
use SugarCraft\Mouse\Sentinel;
use SugarCraft\Mouse\Zone;

/**
 * crush_feat.md section 8 E2 — click-to-switch session tab.
 *
 * Renderer-side assertions are structural snapshots of the zone registry the
 * root scan produces (the sentinels themselves never survive into the frame,
 * so the registry IS the observable output); Chat-side assertions drive
 * `update()` and assert on the returned `[Model, ?Cmd]`.
 *
 * @see Renderer::markSessionTab()
 * @see Chat::update()
 */
final class SessionTabClickTest extends TestCase
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
        $this->resetClickTracker();
    }

    protected function tearDown(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }
        Renderer::scanner()->clear();
        $this->resetClickTracker();

        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
        $this->tempDirs = [];
    }

    // =========================================================================
    // Renderer: the tab labels carry click zones
    // =========================================================================

    public function testEachSessionTabIsRegisteredAsItsOwnZone(): void
    {
        $chat = $this->chatWithSessions(['session-a' => 'Alpha', 'session-b' => 'Beta'], 'session-b');

        Renderer::render($chat);

        $alpha = Renderer::scanner()->get('tab:session-a');
        $beta = Renderer::scanner()->get('tab:session-b');

        self::assertInstanceOf(Zone::class, $alpha);
        self::assertInstanceOf(Zone::class, $beta);
        // Both tabs sit on the same (first) row, side by side and not
        // overlapping, each at least as wide as its own label. Column order
        // follows SessionStore::listSessions()' own row order, so it is
        // derived rather than assumed here.
        self::assertSame($alpha->startRow, $beta->startRow);
        [$left, $right] = $alpha->startCol <= $beta->startCol ? [$alpha, $beta] : [$beta, $alpha];
        self::assertGreaterThan($left->endCol, $right->startCol);
        self::assertGreaterThanOrEqual(strlen(' Alpha '), $alpha->width());
    }

    public function testZoneMarkersNeverReachTheRenderedFrame(): void
    {
        $chat = $this->chatWithSessions(['session-a' => 'Alpha', 'session-b' => 'Beta'], 'session-a');

        $frame = Renderer::render($chat);

        self::assertStringNotContainsString(Sentinel::OPEN, $frame);
        self::assertStringNotContainsString(Sentinel::CLOSE, $frame);
        self::assertStringContainsString('Alpha', $frame);
        self::assertStringContainsString('[Alpha]', $frame);
    }

    public function testSessionIdOutsideTheZoneIdCharsetRendersUnmarkedInsteadOfThrowing(): void
    {
        // Session ids come off disk; Mark::wrap() THROWS on an id with a
        // space, and this runs inside view() where an escaping exception
        // kills the TUI. The tab must still be drawn, just not clickable.
        $chat = $this->chatWithSessions(['bad id' => 'Bad', 'session-b' => 'Beta'], 'session-b');

        $frame = Renderer::render($chat);

        self::assertStringContainsString('Bad', $frame);
        self::assertNull(Renderer::scanner()->get('tab:bad id'));
        self::assertInstanceOf(Zone::class, Renderer::scanner()->get('tab:session-b'));
    }

    public function testNoZonesAreMarkedWhenClicksAreDisabled(): void
    {
        putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS=1');
        $chat = $this->chatWithSessions(['session-a' => 'Alpha', 'session-b' => 'Beta'], 'session-a');

        $frame = Renderer::render($chat);

        self::assertStringContainsString('Alpha', $frame);
        self::assertSame([], Renderer::scanner()->all());
    }

    // =========================================================================
    // Chat: a completed click on a tab switches the session
    // =========================================================================

    public function testLeftClickOnATabSwitchesTheCurrentSession(): void
    {
        $chat = $this->chatWithSessions(['session-a' => 'Alpha', 'session-b' => 'Beta'], 'session-b');
        Renderer::render($chat);
        $zone = Renderer::scanner()->get('tab:session-a');
        self::assertInstanceOf(Zone::class, $zone);

        [$afterPress, $pressCmd] = $chat->update($this->press($zone->startCol, $zone->startRow));
        self::assertNull($pressCmd);
        self::assertSame('session-b', $afterPress->currentSessionId(), 'press alone must not switch');

        [$afterRelease, $releaseCmd] = $afterPress->update($this->release($zone->startCol, $zone->startRow));

        self::assertNull($releaseCmd);
        self::assertSame('session-a', $afterRelease->currentSessionId());
    }

    public function testClickWorksWhenNoSessionHasBeenSelectedYet(): void
    {
        // bin/sugarcrush builds Chat with a SessionStore but no
        // currentSessionId, so this is the state a real launch is in.
        $chat = $this->chatWithSessions(['session-a' => 'Alpha', 'session-b' => 'Beta'], null);
        Renderer::render($chat);
        $zone = Renderer::scanner()->get('tab:session-b');
        self::assertInstanceOf(Zone::class, $zone);

        [$chat] = $chat->update($this->press($zone->startCol, $zone->startRow));
        // Released on the press cell: a sweep to endCol is a text selection
        // under section 8 E8, not a click.
        [$chat] = $chat->update($this->release($zone->startCol, $zone->startRow));

        self::assertSame('session-b', $chat->currentSessionId());
    }

    public function testPressOnATabAndReleaseElsewhereDoesNotSwitch(): void
    {
        // The drag-away / text-selection case the spec calls out: a stray
        // switch here is exactly what routing through ZoneClickTracker
        // prevents.
        $chat = $this->chatWithSessions(['session-a' => 'Alpha', 'session-b' => 'Beta'], 'session-b');
        Renderer::render($chat);
        $zone = Renderer::scanner()->get('tab:session-a');
        self::assertInstanceOf(Zone::class, $zone);

        [$chat] = $chat->update($this->press($zone->startCol, $zone->startRow));
        [$chat] = $chat->update($this->release($zone->startCol, $zone->startRow + 6));

        self::assertSame('session-b', $chat->currentSessionId());
    }

    public function testReleaseWithoutAPrecedingPressDoesNotSwitch(): void
    {
        $chat = $this->chatWithSessions(['session-a' => 'Alpha', 'session-b' => 'Beta'], 'session-b');
        Renderer::render($chat);
        $zone = Renderer::scanner()->get('tab:session-a');
        self::assertInstanceOf(Zone::class, $zone);

        [$chat] = $chat->update($this->release($zone->startCol, $zone->startRow));

        self::assertSame('session-b', $chat->currentSessionId());
    }

    public function testNonLeftButtonsAndNonClickEventsAreIgnored(): void
    {
        $chat = $this->chatWithSessions(['session-a' => 'Alpha', 'session-b' => 'Beta'], 'session-b');
        Renderer::render($chat);
        $zone = Renderer::scanner()->get('tab:session-a');
        self::assertInstanceOf(Zone::class, $zone);
        $col = $zone->startCol;
        $row = $zone->startRow;

        $ignored = [
            new MouseClickMsg($col, $row, MouseButton::Right, MouseAction::Press),
            new MouseReleaseMsg($col, $row, MouseButton::Right, MouseAction::Release),
            new MouseWheelMsg($col, $row, MouseButton::WheelUp, MouseAction::Press),
            new MouseMotionMsg($col, $row, MouseButton::Left, MouseAction::Motion),
        ];

        foreach ($ignored as $msg) {
            [$next, $cmd] = $chat->update($msg);
            self::assertNull($cmd);
            self::assertSame('session-b', $next->currentSessionId());
        }
    }

    public function testClickIsIgnoredWhenClicksAreDisabled(): void
    {
        $chat = $this->chatWithSessions(['session-a' => 'Alpha', 'session-b' => 'Beta'], 'session-b');
        Renderer::render($chat);
        $zone = Renderer::scanner()->get('tab:session-a');
        self::assertInstanceOf(Zone::class, $zone);

        putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS=1');
        [$chat] = $chat->update($this->press($zone->startCol, $zone->startRow));
        [$chat] = $chat->update($this->release($zone->startCol, $zone->startRow));

        self::assertSame('session-b', $chat->currentSessionId());
    }

    public function testClickOnAZoneForASessionThatNoLongerExistsIsIgnored(): void
    {
        // Zones describe the frame ALREADY on screen; the store can change
        // between that frame and the click landing.
        $chat = $this->chatWithSessions(['session-a' => 'Alpha', 'session-b' => 'Beta'], 'session-b');
        Renderer::scanner()->scan("\u{E000}tab:ghost\u{E001}Ghost\u{E000}/tab:ghost\u{E001}", 80);

        [$chat] = $chat->update($this->press(1, 1));
        [$chat] = $chat->update($this->release(1, 1));

        self::assertSame('session-b', $chat->currentSessionId());
    }

    public function testClickOutsideAnyTabZoneIsANoOp(): void
    {
        $chat = $this->chatWithSessions(['session-a' => 'Alpha', 'session-b' => 'Beta'], 'session-b');
        Renderer::render($chat);

        [$chat, $cmd] = $chat->update($this->press(200, 200));
        self::assertNull($cmd);
        [$chat, $cmd] = $chat->update($this->release(200, 200));

        self::assertNull($cmd);
        self::assertSame('session-b', $chat->currentSessionId());
    }

    public function testClickTrackerIsSharedAcrossUpdateCalls(): void
    {
        // The press half of a click has to survive into the next update(),
        // which a field on an immutable Chat could not do.
        self::assertSame(Chat::clickTracker(), Chat::clickTracker());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function press(int $col, int $row): MouseClickMsg
    {
        return new MouseClickMsg($col, $row, MouseButton::Left, MouseAction::Press);
    }

    private function release(int $col, int $row): MouseReleaseMsg
    {
        return new MouseReleaseMsg($col, $row, MouseButton::Left, MouseAction::Release);
    }

    /**
     * @param array<string, string> $sessions id => display name
     */
    private function chatWithSessions(array $sessions, ?string $current): Chat
    {
        $dir = sys_get_temp_dir() . '/crush_tabclick_' . uniqid('', true);
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;

        $store = new SessionStore($dir . '/sessions.db');
        foreach ($sessions as $id => $name) {
            $store->createSession((string) $id, 'openai', 'gpt-4', null, $name);
        }

        return new Chat(sessionStore: $store, currentSessionId: $current);
    }

    private function resetClickTracker(): void
    {
        (new \ReflectionProperty(Chat::class, 'clickTracker'))->setValue(null, null);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Message;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\Session\SessionStore;
use SugarCraft\Code\Tui\SessionPicker;
use SugarCraft\Mouse\Zone;

/**
 * Pins the agreement between the session picker's painted action glyphs and the
 * click zones {@see Renderer::markSessionActs()} mints for them (CJKW-02).
 *
 * The README's session-picker paragraph promises the highlighted row "carries a
 * clickable ✎ ★ ✕ (rename, pin, delete)". That sentence is only honest while
 * every glyph the picker paints yields a zone and every painted row without a
 * glyph yields NONE (the absent-glyph path skips silently). These pins hold the
 * contract from both ends: live frames (all verbs, selection moves, armed
 * delete, narrow overlays) and a direct unit probe of the skip path proving a
 * missing glyph can never forge a mark.
 */
final class SessionActZoneAgreementTest extends TestCase
{
    /** @var list<string> */
    private const MOUSE_ENV = ['CANDY_CODE_DISABLE_MOUSE', 'CANDY_CODE_DISABLE_MOUSE_CLICKS'];

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        foreach (self::MOUSE_ENV as $var) {
            putenv($var);
        }
        Renderer::scanner()->clear();
        (new ReflectionProperty(Chat::class, 'clickTracker'))->setValue(null, null);
    }

    protected function tearDown(): void
    {
        foreach (self::MOUSE_ENV as $var) {
            putenv($var);
        }
        Renderer::scanner()->clear();
        (new ReflectionProperty(Chat::class, 'clickTracker'))->setValue(null, null);
        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink((string) $file);
            }
            @rmdir($dir);
        }
        $this->tempDirs = [];
    }

    public function testTheHighlightedRowCarriesAZoneForEveryPaintedActionVerb(): void
    {
        $chat = $this->pickerUp(3);
        Renderer::render($chat);

        $picker = $chat->sessionPicker();
        $this->assertInstanceOf(SessionPicker::class, $picker);
        $selected = $picker->selectedIndex();

        // The prose names three verbs; the frame must mint three zones, one per
        // glyph, on the row the highlight actually sits on (never a hardcoded row).
        $ren = Renderer::scanner()->get(Renderer::SESSION_ACT_ZONE_PREFIX . $selected . ':rename');
        $pin = Renderer::scanner()->get(Renderer::SESSION_ACT_ZONE_PREFIX . $selected . ':pin');
        $del = Renderer::scanner()->get(Renderer::SESSION_ACT_ZONE_PREFIX . $selected . ':delete');
        $this->assertInstanceOf(Zone::class, $ren);
        $this->assertInstanceOf(Zone::class, $pin);
        $this->assertInstanceOf(Zone::class, $del);

        // The cluster is painted left to right as ✎ ★ ✕.
        $this->assertTrue($ren->startCol < $pin->startCol, 'rename glyph must sit left of the pin glyph');
        $this->assertTrue($pin->startCol < $del->startCol, 'pin glyph must sit left of the delete glyph');

        // Only the highlighted row carries acts (and only it paints the glyphs).
        $this->assertNull(
            Renderer::scanner()->get(Renderer::SESSION_ACT_ZONE_PREFIX . ($selected + 1) . ':pin'),
            'a non-highlighted row must not mint action zones'
        );
    }

    public function testActionZonesFollowTheHighlightWhenItMoves(): void
    {
        $chat = $this->pickerUp(3);
        [$first, $command] = $chat->update(new KeyMsg(KeyType::Down));
        $this->assertNull($command, 'Down moves the picker highlight, it dispatches nothing');
        Renderer::scanner()->clear();
        Renderer::render($first);

        $picker = $first->sessionPicker();
        $this->assertInstanceOf(SessionPicker::class, $picker);
        $moved = $picker->selectedIndex();
        $this->assertSame(1, $moved, 'the fixture must actually move the highlight');

        foreach (['rename', 'pin', 'delete'] as $verb) {
            $this->assertInstanceOf(
                Zone::class,
                Renderer::scanner()->get(Renderer::SESSION_ACT_ZONE_PREFIX . $moved . ':' . $verb),
                "the newly highlighted row must carry the {$verb} zone"
            );
            $this->assertNull(
                Renderer::scanner()->get(Renderer::SESSION_ACT_ZONE_PREFIX . 0 . ':' . $verb),
                "the row the highlight left behind must not keep the {$verb} zone"
            );
        }
    }

    public function testArmedDeleteKeepsOnlyTheDeleteZoneAndTheOthersVanishWithTheirGlyphs(): void
    {
        $chat = $this->pickerUp(2);
        Renderer::render($chat);

        $picker = $chat->sessionPicker();
        $this->assertInstanceOf(SessionPicker::class, $picker);
        $selected = $picker->selectedIndex();

        $zone = Renderer::scanner()->get(Renderer::SESSION_ACT_ZONE_PREFIX . $selected . ':delete');
        $this->assertInstanceOf(Zone::class, $zone);
        [$pressed] = $chat->update(new MouseClickMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Press));
        [$armed] = $pressed->update(new MouseReleaseMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Release));

        $armedPicker = $armed->sessionPicker();
        $this->assertInstanceOf(SessionPicker::class, $armedPicker);
        $this->assertNotNull($armedPicker->armedDeleteId(), 'the click must arm the delete');

        // Arming restyles the row: only ✕ is painted now. The absent-glyph path
        // (strrpos fails, skip) must mint zones for the painted verb ONLY — a
        // forged mark for the missing ✎/★ would keep dead click targets live.
        Renderer::scanner()->clear();
        Renderer::render($armed);

        $this->assertInstanceOf(
            Zone::class,
            Renderer::scanner()->get(Renderer::SESSION_ACT_ZONE_PREFIX . $selected . ':delete'),
            'the armed row keeps its delete zone'
        );
        $this->assertNull(
            Renderer::scanner()->get(Renderer::SESSION_ACT_ZONE_PREFIX . $selected . ':rename'),
            'a verb whose glyph is not painted must not mint a zone'
        );
        $this->assertNull(
            Renderer::scanner()->get(Renderer::SESSION_ACT_ZONE_PREFIX . $selected . ':pin'),
            'a verb whose glyph is not painted must not mint a zone'
        );
    }

    public function testANarrowOverlayPaintsNoClusterAndMintsNoActionZones(): void
    {
        $chat = $this->pickerUp(2, cols: 44);
        [$width] = SessionPicker::overlayGeometry($chat->cols(), $chat->rows(), Renderer::SHELL_CHROME_COLS);
        $this->assertLessThan(50, $width, 'the fixture must produce an overlay narrower than the cluster floor');

        Renderer::render($chat);

        $picker = $chat->sessionPicker();
        $this->assertInstanceOf(SessionPicker::class, $picker);
        $this->assertSame([], $picker->actionSegments($width, $chat->theme()), 'no segments below the cluster floor');

        $selected = $picker->selectedIndex();
        foreach (['rename', 'pin', 'delete'] as $verb) {
            $this->assertNull(
                Renderer::scanner()->get(Renderer::SESSION_ACT_ZONE_PREFIX . $selected . ':' . $verb),
                "a glyph the picker never paints must not mint a {$verb} zone"
            );
        }

        $this->assertInstanceOf(
            Zone::class,
            Renderer::scanner()->get(Renderer::SESSION_ROW_ZONE_PREFIX . $selected),
            'row zones still form — the overlay itself is painted'
        );
    }

    public function testTheSkipPathNeverForcesAZoneAndAPaintedGlyphIsWrappedOnce(): void
    {
        $markSessionActs = new ReflectionMethod(Renderer::class, 'markSessionActs');
        $markSessionActs->setAccessible(true);

        $chat = $this->pickerUp(2);
        $picker = $chat->sessionPicker();
        $this->assertInstanceOf(SessionPicker::class, $picker);
        $acts = $picker->actionSegments(76, $chat->theme());
        $this->assertCount(3, $acts, 'the fixture must capture the three styled action bytes');

        // Absent-glyph path: real styled segments, a line that carries none of
        // them. The result must be byte-identical — never a forged mark.
        $plain = 'Session 7 no glyphs here';
        $this->assertSame(
            $plain,
            $markSessionActs->invoke(null, '0', $plain, $acts),
            'the skip path must return the line untouched'
        );

        // Painted path: wrap each glyph exactly once, zone ids embedded in order.
        $styled = 'Session 7' . $acts['rename'] . ' ' . $acts['pin'] . ' ' . $acts['delete'];
        $wrapped = (string) $markSessionActs->invoke(null, '9', $styled, $acts);
        $this->assertTrue(substr_count($wrapped, "\u{e000}") === 6, 'one open sentinel per wrapped glyph');
        $this->assertTrue(substr_count($wrapped, "\u{e001}") === 6, 'one close sentinel per wrapped glyph');
        $this->assertStringContainsString('session-act:9:rename', $wrapped);
        $this->assertStringContainsString('session-act:9:pin', $wrapped);
        $this->assertStringContainsString('session-act:9:delete', $wrapped);
        $this->assertSame(
            substr_count($styled, "\u{e000}"),
            0,
            'the input line carries no sentinels of its own before marking'
        );
    }

    /**
     * A selected row titled with the pin glyph must still bind every verb to
     * the right-aligned cluster. Today the row fit pads the title run out of
     * byte-equality with the bare cluster segments (measured: a first-found
     * search variant stays green on this fixture), so this leg is a contract
     * pin for the walk's verb-to-position binding, not a live collision demo.
     */
    public function testATitleCarryingTheGlyphIsNotMistakenForTheCluster(): void
    {
        $dir = sys_get_temp_dir() . '/crush_act_agreement_' . uniqid('', true);
        mkdir($dir, 0700);
        $this->tempDirs[] = $dir;
        $store = new SessionStore($dir . '/sessions.db');
        $store->createSession('session-00', 'echo', 'echo-1', null, 'plain row');
        $store->createSession('session-01', 'echo', 'echo-1', null, "\u{2605}");
        $chat = (new Chat(
            history: [Message::user('hello')],
            sessionStore: $store,
            currentSessionId: 'session-00',
        ))->withSize(100, 30);
        [$opened] = $chat->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $this->assertNotNull($opened->sessionPicker());

        // Move the highlight onto the star-titled row and paint.
        [$moved] = $opened->update(new KeyMsg(KeyType::Down));
        Renderer::scanner()->clear();
        Renderer::render($moved);

        $picker = $moved->sessionPicker();
        $this->assertInstanceOf(SessionPicker::class, $picker);
        $selected = $picker->selectedIndex();
        $this->assertSame(1, $selected);

        $prefix = Renderer::SESSION_ACT_ZONE_PREFIX . $selected . ':';
        $rename = Renderer::scanner()->get($prefix . 'rename');
        $pin = Renderer::scanner()->get($prefix . 'pin');
        $delete = Renderer::scanner()->get($prefix . 'delete');
        $this->assertInstanceOf(Zone::class, $rename, 'a first-found pin wrap would leave no rename zone');
        $this->assertInstanceOf(Zone::class, $pin);
        $this->assertInstanceOf(Zone::class, $delete);
        $this->assertLessThan($pin->startCol, $rename->startCol, 'the pin zone must sit on the cluster, right of the title');
        $this->assertLessThan($delete->startCol, $pin->startCol);
    }

    private function pickerUp(int $count, int $cols = 100): Chat
    {
        $dir = sys_get_temp_dir() . '/crush_act_agreement_' . uniqid('', true);
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;

        $store = new SessionStore($dir . '/sessions.db');
        for ($i = 0; $i < $count; $i++) {
            $store->createSession(sprintf('session-%02d', $i), 'echo', 'echo-1', null, "Session {$i}");
        }

        $chat = (new Chat(
            history: [Message::user('hello')],
            sessionStore: $store,
            currentSessionId: 'session-00',
        ))->withSize($cols, 30);
        [$opened] = $chat->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $this->assertNotNull($opened->sessionPicker(), 'ctrl+r must open the session picker');

        return $opened;
    }
}

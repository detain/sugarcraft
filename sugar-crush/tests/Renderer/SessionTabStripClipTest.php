<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Renderer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Session\SessionStore;
use SugarCraft\Mouse\Zone;

/**
 * The session tab strip fits the terminal and carries no escape sequences
 * or line breaks from session names (audit 15b-18).
 *
 * The strip sits above the shell, outside every clip, and used to join every
 * listed session's full name. Eight sessions named "Refactor the
 * authentication middleware part N" gave a 383-cell top row at 80 columns,
 * which the terminal wraps and candy-core's absolute-cursor repaint then
 * paints one line low. Names also reached the frame raw: `/rename` stores
 * typed text as is, and other writers (another sugar-crush version, the
 * Python port, a hand-edited session.db) share the same database.
 *
 * Widths are measured on `Renderer::render()`, which returns the frame after
 * `scanRoot()` has removed the zone sentinels, so this is the width the
 * terminal paints.
 */
final class SessionTabStripClipTest extends TestCase
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
        (new \ReflectionProperty(Chat::class, 'clickTracker'))->setValue(null, null);
    }

    protected function tearDown(): void
    {
        Renderer::scanner()->clear();
        (new \ReflectionProperty(Chat::class, 'clickTracker'))->setValue(null, null);

        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
        $this->tempDirs = [];

        parent::tearDown();
    }

    /** @return iterable<string, array{int}> */
    public static function widths(): iterable
    {
        foreach ([200, 120, 80, 60, 40, 30, 20, 12, 10, 8, 7] as $cols) {
            yield "{$cols} cols" => [$cols];
        }
    }

    #[DataProvider('widths')]
    public function testLongNamesNeverMakeTheStripWiderThanTheTerminal(int $cols): void
    {
        [$store, $ids] = $this->longNamedStore(8);
        $chat = (new Chat(sessionStore: $store, currentSessionId: $ids[0]))->withSize($cols, 24);

        $frame = Renderer::render($chat);
        $rows = explode("\n", $frame);

        self::assertLessThanOrEqual($cols, Width::string($rows[0]), 'tab strip: ' . $rows[0]);
        foreach ($rows as $index => $row) {
            self::assertLessThanOrEqual($cols, Width::string($row), "row {$index}: {$row}");
        }
        self::assertCount(24, $rows, 'the strip must stay one row so the frame keeps its height');
    }

    public function testEightLongNamesAt80ColsCollapseTheOverflowIntoACountedMarker(): void
    {
        [$store, $ids] = $this->longNamedStore(8);
        $chat = (new Chat(sessionStore: $store, currentSessionId: $ids[0]))->withSize(80, 24);

        $strip = explode("\n", Renderer::render($chat))[0];

        $visible = $this->visibleTabIds($ids);
        self::assertNotSame([], $visible);
        self::assertLessThan(count($ids), count($visible), 'eight long names cannot all fit in 80 columns');
        $hidden = count($ids) - count($visible);
        self::assertStringContainsString("… +{$hidden}", $strip);
        // A clipped label ends in the ellipsis rather than a hard cut.
        self::assertMatchesRegularExpression('/Refactor[^|]*…/u', $strip);
    }

    public function testEverythingFitsWithoutAMarkerWhenThereIsRoom(): void
    {
        $store = $this->store();
        $store->createSession('session-a', 'openai', 'gpt-4', null, 'Alpha');
        $store->createSession('session-b', 'openai', 'gpt-4', null, 'Beta');
        $chat = (new Chat(sessionStore: $store, currentSessionId: 'session-b'))->withSize(80, 24);

        $strip = explode("\n", Renderer::render($chat))[0];

        self::assertStringContainsString('[Beta]', $strip);
        self::assertStringContainsString(' Alpha ', $strip);
        self::assertStringNotContainsString('…', $strip);
    }

    #[DataProvider('widths')]
    public function testTheCurrentTabStaysVisibleWhenItIsTheLastOfTwenty(int $cols): void
    {
        [$store, $ids] = $this->longNamedStore(20);
        $listed = array_map(static fn (array $row): string => (string) $row['id'], $store->listSessions());
        $last = $listed[count($listed) - 1];
        $chat = (new Chat(sessionStore: $store, currentSessionId: $last))->withSize($cols, 24);

        $strip = explode("\n", Renderer::render($chat))[0];

        self::assertLessThanOrEqual($cols, Width::string($strip));
        // The current tab is the bracketed one; even when its name has to be
        // cut to nothing, the opening bracket is kept.
        self::assertStringContainsString('[', $strip, 'current tab missing: ' . $strip);
        if ($cols >= 20) {
            self::assertInstanceOf(Zone::class, Renderer::scanner()->get('tab:' . $last));
            self::assertMatchesRegularExpression('/\[Refactor[^\]]*\]/u', $strip);
        }
    }

    public function testHostileNamesReachTheStripAsOneInertRow(): void
    {
        $store = $this->store();
        $store->createSession('session-a', 'openai', 'gpt-4', null, 'Alpha');
        $store->createSession('evil', 'openai', 'gpt-4', null, "x \e]52;c;eA==\a \e[2J y\rCR\nLF\ttab");
        $chat = (new Chat(sessionStore: $store, currentSessionId: 'evil'))->withSize(120, 24);

        $frame = Renderer::render($chat);
        $strip = explode("\n", $frame)[0];

        self::assertStringNotContainsString("\e]", $frame);
        self::assertStringNotContainsString("\e[2J", $frame);
        self::assertStringNotContainsString("\r", $frame);
        self::assertStringNotContainsString("\t", $strip);
        self::assertStringNotContainsString("\x07", $frame);
        // Escapes are dropped whole (no `]52;c;` residue) and every break
        // folds to one space, short enough here that nothing is clipped.
        self::assertStringContainsString('[x y CR LF tab]', $strip);
        self::assertStringContainsString('Alpha', $strip);
        self::assertCount(24, explode("\n", $frame));
    }

    public function testForgedZoneSentinelsInANameRegisterNoZone(): void
    {
        $store = $this->store();
        $store->createSession('session-a', 'openai', 'gpt-4', null, "\u{E000}tab:ghost\u{E001}Ghost\u{E000}/tab:ghost\u{E001}");
        $store->createSession('session-b', 'openai', 'gpt-4', null, 'Beta');
        $chat = (new Chat(sessionStore: $store, currentSessionId: 'session-b'))->withSize(80, 24);

        $strip = explode("\n", Renderer::render($chat))[0];

        self::assertNull(Renderer::scanner()->get('tab:ghost'));
        self::assertInstanceOf(Zone::class, Renderer::scanner()->get('tab:session-a'));
        // Only the sentinels go; the text between them is shown as text.
        self::assertStringContainsString(' tab:ghostGhost', $strip);
    }

    public function testANameThatSanitizesToNothingFallsBackToTheId(): void
    {
        $store = $this->store();
        $store->createSession('session-a', 'openai', 'gpt-4', null, "\e[2J\r\n\t");
        $store->createSession('session-b', 'openai', 'gpt-4', null, 'Beta');
        $chat = (new Chat(sessionStore: $store, currentSessionId: 'session-b'))->withSize(80, 24);

        $strip = explode("\n", Renderer::render($chat))[0];

        self::assertStringContainsString(' session-a ', $strip);
    }

    public function testEveryVisibleTabIsStillClickableAndHiddenTabsCarryNoZone(): void
    {
        [$store, $ids] = $this->longNamedStore(8);
        $chat = (new Chat(sessionStore: $store, currentSessionId: $ids[0]))->withSize(80, 24);
        Renderer::render($chat);

        $visible = $this->visibleTabIds($ids);
        self::assertGreaterThanOrEqual(2, count($visible));
        self::assertLessThan(count($ids), count($visible));

        foreach ($visible as $id) {
            $zone = Renderer::scanner()->get('tab:' . $id);
            self::assertInstanceOf(Zone::class, $zone);
            // Zones are 1-based: the strip is the frame's first row.
            self::assertSame(1, $zone->startRow, 'tab zones sit on the strip row');
            self::assertSame(1, $zone->endRow);
            self::assertLessThanOrEqual(80, $zone->endCol);
        }

        $target = null;
        foreach ($visible as $id) {
            if ($id !== $ids[0]) {
                $target = $id;
                break;
            }
        }
        self::assertNotNull($target);
        $zone = Renderer::scanner()->get('tab:' . $target);
        self::assertInstanceOf(Zone::class, $zone);

        [$chat] = $chat->update(new MouseClickMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Press));
        [$chat] = $chat->update(new MouseReleaseMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Release));

        self::assertSame($target, $chat->currentSessionId());
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private function visibleTabIds(array $ids): array
    {
        return array_values(array_filter(
            $ids,
            static fn (string $id): bool => Renderer::scanner()->get('tab:' . $id) instanceof Zone,
        ));
    }

    /** @return array{SessionStore, list<string>} */
    private function longNamedStore(int $count): array
    {
        $store = $this->store();
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $id = "s{$i}";
            $store->createSession($id, 'openai', 'gpt-4', null, "Refactor the authentication middleware part {$i}");
            $ids[] = $id;
        }

        return [$store, $ids];
    }

    private function store(): SessionStore
    {
        $dir = sys_get_temp_dir() . '/crush_tabstrip_' . uniqid('', true);
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;

        return new SessionStore($dir . '/sessions.db');
    }
}

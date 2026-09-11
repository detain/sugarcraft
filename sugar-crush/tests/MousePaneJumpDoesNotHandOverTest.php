<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\KeyBindingRegistry;
use SugarCraft\Crush\App\SelectPaneMsg;
use SugarCraft\Crush\Renderer;
use SugarCraft\Mouse\Zone;

/**
 * E683 — the "mouse pane-jump" hand-over door does not exist, pinned.
 *
 * The abandonment doors named in E666's prose include a mouse pane-jump.
 * Measured truth (round-65 lane bb, recorded in
 * /home/sites/crush-r61-artifacts/bb/measures.md): NO mouse path changes
 * `App::$pane`. The chrome scan registers only MenuBar title/dropdown zones;
 * the pane tabs paint without a zone; `SelectPaneMsg` has a consumer in
 * `App::update()` and no producer in `src/` (grep-pinned below). The only
 * `pane:` click zones — the status bar's pane MENU hint and the agent header
 * — land in `Chat::selectPane()`, a Chat-scope overlay chooser that never
 * touches the App pane, and both of their screen rows are below the hosted
 * content viewport anyway (a hosted frame clips them out). So the
 * first-key-closes abandonment seam has no mouse trigger today: it is a
 * keyboard-doors mechanism, correctly. This test pins the dormant truth, so
 * a future live mouse door must land with a new fixture rather than quietly
 * inherit the old prose — and pins that the registry's `mouse.pane` row
 * keeps its live status (never-remove rule: dormant rows keep their rows,
 * but here the row is genuinely live, only its DESCRIPTION was overclaiming
 * "Focus that pane"; it now reads what the drift test observes).
 */
final class MousePaneJumpDoesNotHandOverTest extends TestCase
{
    private const VARS = ['SUGARCRUSH_DISABLE_MOUSE', 'SUGARCRUSH_DISABLE_MOUSE_CLICKS'];

    protected function setUp(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }
        Renderer::scanner()->clear();
        $this->resetClickTracker();
    }

    public function testTheOnlyPaneZonesLandInChatMenuSurfaceNotAppPaneFocus(): void
    {
        $chat = new Chat();
        Renderer::render($chat);

        // `pane:agents` is the other pane: zone; it registers only with a
        // live agent roster, and its click truth (history pair appended, cmd
        // NULL, App pane untouched) is already pinned by
        // PaneClickTest::testLeftClickOnTheAgentHeaderRunsTheAgentsCommand().
        // Here the load-bearing one is `pane:menu` — the zone whose existence
        // keeps the registry's mouse.pane row LIVE.
        foreach (['pane:menu'] as $zoneId) {
            $zone = Renderer::scanner()->get($zoneId);
            self::assertInstanceOf(Zone::class, $zone, "fixture: {$zoneId} is registered by the band scan");

            [$after] = $chat->update($this->press($zone));
            [$after, $cmd] = $after->update($this->release($zone));

            self::assertNull($cmd, "{$zoneId} produces no Cmd — and SelectPaneMsg has no other mouse route");
            self::assertNotInstanceOf(
                SelectPaneMsg::class,
                $cmd,
                "{$zoneId} never dispatches the pane-hand-over message App would honour",
            );
        }
    }

    public function testMousePaneRowStaysLiveWithAnHonestDescription(): void
    {
        $row = null;
        foreach (KeyBindingRegistry::all() as $binding) {
            if ($binding->id === 'mouse.pane') {
                $row = $binding;
            }
        }

        self::assertNotNull($row, 'fixture: the mouse.pane row exists');
        self::assertTrue($row->isLive(), 'never-remove: the row stays LIVE — the pane:menu observation is real');
        self::assertStringContainsString('pane menu', strtolower($row->description) . ' ', 'the description names what the click actually opens');
        self::assertStringNotContainsString('focus that pane', strtolower($row->description), 'the overclaim is gone (E683)');
    }

    public function testNoProducerOfSelectPaneMsgExistsAnywhereInSrc(): void
    {
        // Alias-resolving producer scan, mirroring the pattern established in
        // HostedFrameReadsThePaneTest::testNothingInSrcConstructsASelectPaneMsg()
        // (tests/App/) — the naive `'new SelectPaneMsg'` needle this lane first
        // shipped is blind to `new App\SelectPaneMsg(` (relative prefix),
        // leading-backslash FQNs, and `use … as <alias>` spellings, which is
        // exactly the gap that test's docblock records. Its scanner is private
        // to that class; this is the sanctioned minimal union, not a second
        // census — same two regexes, same semantics.
        $names = ['SelectPaneMsg'];
        $producers = [];

        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../src', \FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $body = (string) file_get_contents($file->getPathname());
            if (!str_contains($body, 'SelectPaneMsg')) {
                continue;
            }

            // Every name the class can be constructed under IN THIS FILE: its
            // own, plus any `use … as` alias a plain-name scan would never see.
            $localNames = $names;
            if (preg_match('/\buse\s+[\\\\\w]*\bSelectPaneMsg\s+as\s+(\w+)\s*;/i', $body, $alias) === 1) {
                $localNames[] = $alias[1];
            }

            foreach ($localNames as $name) {
                // Any namespace prefix, relative or absolute, or none at all.
                if (preg_match('/\bnew\s+[\\\\\w]*\b' . preg_quote($name, '/') . '\s*\(/', $body) === 1) {
                    $producers[] = $file->getPathname();
                    break;
                }
            }
        }

        self::assertSame(
            [],
            $producers,
            'E683: the App pane-hand-over message has a consumer and no producer — the mouse pane-jump door is dormant by absence',
        );
    }

    private function press(Zone $zone): MouseClickMsg
    {
        return new MouseClickMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Press);
    }

    private function release(Zone $zone): MouseReleaseMsg
    {
        return new MouseReleaseMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Release);
    }

    private function resetClickTracker(): void
    {
        (new \ReflectionProperty(Chat::class, 'clickTracker'))->setValue(null, null);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\Tests\Commands\ResetsDerivedRuneSets;
use SugarCraft\Code\ToolResult;

/**
 * rv-a11y M3(c): Ctrl+O no longer stops at the newest row. The first press of
 * a burst keeps Chat's newest expansion byte-for-byte; every further press
 * within TOOL_WALK_WINDOW_SECONDS walks one transcript row older, cycling when
 * the history runs out. This is the only keyboard route to an older tool
 * result or settled thought once a newer row has arrived.
 *
 * v2:a11y-F1 (fix1021): the cursor became a place, not a moment. `Ctrl+F`
 * moves it one row older WITHOUT toggling, and a `Ctrl+O` on a settled cursor
 * toggles exactly the focused row — expand an older row directly, the click's
 * power on the keyboard. A settled cursor tracks its ROW (identity by key),
 * survives a burst window's expiry, and refuses the same modals the walk
 * refuses.
 *
 * @internal
 */
final class ToolOutputWalkTest extends TestCase
{
    use ResetsDerivedRuneSets;

    private ProviderInterface $provider;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
    }

    /** @param list<Message> $history */
    private function app(array $history): App
    {
        $chat = new Chat(history: $history, backend: new EchoBackend());
        [$app] = App::new($this->provider, 'gpt-4')
            ->withChat($chat)
            ->update(new WindowSizeMsg(100, 30));

        return $app;
    }

    private function pressO(App $app): array
    {
        return $app->update(new KeyMsg(KeyType::Char, 'o', ctrl: true));
    }

    private function pressF(App $app): array
    {
        return $app->update(new KeyMsg(KeyType::Char, 'f', ctrl: true));
    }

    public function testFocusingARowChangesWhatCtrlOToggles(): void
    {
        $app = $this->app([
            Message::assistant('a')->withToolResults([ToolResult::ok('grep', 'a', 'call_old')]),
            Message::assistant('b')->withToolResults([ToolResult::ok('grep', 'b', 'call_new')]),
        ]);

        [$afterO] = $this->pressO($app);
        [$afterF, $cmd] = $this->pressF($afterO);

        $this->assertNull($cmd, 'moving the cursor raises nothing');
        $this->assertSame(1, $afterF->toolWalkState['index']);
        $this->assertTrue($afterF->toolWalkState['settled']);
        $this->assertSame('call_old', $afterF->toolWalkState['key']);
        $this->assertFalse(
            $afterF->chat->isToolOutputExpanded('call_old'),
            'focus moves the cursor; it does not toggle the row it lands on',
        );
        $this->assertTrue(
            $afterF->chat->isToolOutputExpanded('call_new'),
            'and leaves the newest row exactly where the burst left it',
        );

        [$toggled] = $this->pressO($afterF);
        $this->assertTrue(
            $toggled->chat->isToolOutputExpanded('call_old'),
            'the focused row is what Ctrl+O now opens',
        );
        $this->assertTrue(
            $toggled->chat->isToolOutputExpanded('call_new'),
            'the newest row keeps its own state — one row was targeted, not re-walked',
        );
        $this->assertTrue($toggled->toolWalkState['settled'], 'the cursor stays put across the toggle');

        [$collapsed] = $this->pressO($toggled);
        $this->assertFalse($collapsed->chat->isToolOutputExpanded('call_old'), 'and closes again the same way');
        $this->assertTrue($collapsed->chat->isToolOutputExpanded('call_new'));
    }

    public function testAFocusedRowTracksItsRowWhenNewerRowsArrive(): void
    {
        $rows = [
            Message::assistant('a')->withToolResults([ToolResult::ok('grep', 'a', 'call_a')]),
            Message::assistant('b')->withToolResults([ToolResult::ok('grep', 'b', 'call_b')]),
            Message::assistant('c')->withToolResults([ToolResult::ok('grep', 'c', 'call_c')]),
        ];
        $app = $this->app($rows);

        [$afterO] = $this->pressO($app);
        [$afterF] = $this->pressF($afterO);
        $this->assertSame(1, $afterF->toolWalkState['index']);
        $this->assertSame('call_b', $afterF->toolWalkState['key']);

        $shifted = $afterF->withChat(new Chat(
            history: [...$rows, Message::assistant('d')->withToolResults([ToolResult::ok('grep', 'd', 'call_d')])],
            backend: new EchoBackend(),
        ));

        [$toggled] = $this->pressO($shifted);
        $this->assertTrue(
            $toggled->chat->isToolOutputExpanded('call_b'),
            'Ctrl+O opens the row that was focused, not the one its index now points at',
        );
        $this->assertFalse(
            $toggled->chat->isToolOutputExpanded('call_d'),
            'and a brand-newest arrival is NOT the target',
        );
        $this->assertSame(2, $toggled->toolWalkState['index'], 'the index follows the row');
        $this->assertSame('call_b', $toggled->toolWalkState['key']);
    }

    public function testCtrlFTypesNothingAndRaisesNothingOnAnIdleShell(): void
    {
        $app = $this->app([Message::user('hello')]);

        [$next, $cmd] = $this->pressF($app);

        $this->assertSame($app, $next, 'no rows, no cursor, no state change');
        $this->assertNull($cmd);
        $this->assertNull($next->toolWalkState);
        $this->assertSame('', $next->chat->inputBuf, 'the chord types nothing into the draft');
    }

    public function testFocusingUnderAModalIsRefused(): void
    {
        $app = $this->app([
            Message::assistant('a')->withToolResults([ToolResult::ok('grep', 'a', 'call_old')]),
            Message::assistant('b')->withToolResults([ToolResult::ok('grep', 'b', 'call_new')]),
        ]);

        [$withPalette] = $app->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $this->assertNotNull($withPalette->chat->palette(), 'fixture: the palette is up');

        [$refused, $cmd] = $this->pressF($withPalette);
        $this->assertNull($cmd);
        $this->assertNull($refused->toolWalkState, 'the chord reached the palette, not the cursor');
        $this->assertNotNull($refused->chat->palette(), 'and the palette rides on');
    }

    /**
     * v2:a11y-F1 parity with the mouse: a click on a docked Tools row names
     * the exact row, so the keyboard cursor settles there and the next
     * `Ctrl+O` answers the row the user last touched by ANY input. Driven
     * through the real chrome the way the drift observation drives
     * `mouse.side-row`: paint, read the published zone, press, release.
     */
    public function testAClickOnASideRowSettlesTheKeyboardCursor(): void
    {
        $app = $this->app([
            Message::assistant('a')->withToolResults([ToolResult::ok('grep', 'a', 'call_old')]),
            Message::assistant('b')->withToolResults([ToolResult::ok('grep', 'b', 'call_new')]),
        ]);

        $dock = \SugarCraft\Constraints\Dock\DockLayout::new('chat')
            ->withSlotAdded(\SugarCraft\Constraints\Dock\Side::Right, 'tools');
        [$docked] = $app->withDock($dock)->update(new WindowSizeMsg(160, 40));

        $click = static function (App $app): App {
            \SugarCraft\Code\Tui\Renderer::chromeScanner()->clear();
            (new \ReflectionProperty(App::class, 'chromeClickTracker'))->setValue(null, null);
            \SugarCraft\Code\Tui\Renderer::renderView($app, 160, 40);
            $zone = \SugarCraft\Code\Tui\Renderer::chromeScanner()
                ->get(Renderer::SIDE_ROW_ZONE_PREFIX . 'tools:call_old');
            self::assertNotNull($zone, 'fixture: the docked row is a click target');
            [$app] = $app->update(new \SugarCraft\Core\Msg\MouseClickMsg(
                $zone->startCol,
                $zone->startRow,
                \SugarCraft\Core\MouseButton::Left,
                \SugarCraft\Core\MouseAction::Press,
            ));
            [$app] = $app->update(new \SugarCraft\Core\Msg\MouseReleaseMsg(
                $zone->startCol,
                $zone->startRow,
                \SugarCraft\Core\MouseButton::Left,
                \SugarCraft\Core\MouseAction::Release,
            ));

            return $app;
        };

        $clicked = $click($docked);
        $this->assertNotNull($clicked->toolWalkState);
        $this->assertTrue($clicked->toolWalkState['settled']);
        $this->assertSame('call_old', $clicked->toolWalkState['key']);
        $this->assertTrue(
            $clicked->chat->isToolOutputExpanded('call_old'),
            'the click itself expanded the row it named',
        );

        [$collapsed] = $this->pressO($clicked);
        $this->assertFalse(
            $collapsed->chat->isToolOutputExpanded('call_old'),
            'and Ctrl+O answers that same row, not the newest',
        );
        $this->assertFalse(
            $collapsed->chat->isToolOutputExpanded('call_new'),
            'the newest row was never touched by either gesture',
        );
        \SugarCraft\Code\Tui\Renderer::chromeScanner()->clear();
    }

    public function testFirstPressKeepsChatsNewestExpansionByteIdentical(): void
    {
        $newest = ToolResult::ok('grep', 'b', 'call_new');
        $app = $this->app([
            Message::assistant('a')->withToolResults([ToolResult::ok('grep', 'a', 'call_old')]),
            Message::assistant('b')->withToolResults([$newest]),
        ]);

        [$next, $cmd] = $this->pressO($app);

        $this->assertNull($cmd);
        $this->assertTrue($next->chat->isToolOutputExpanded('call_new'), 'the newest tool opens');
        $this->assertFalse($next->chat->isToolOutputExpanded('call_old'), 'and the walk has not moved yet');
        $this->assertNotNull($next->toolWalkState, 'the burst cursor is armed');
        $this->assertSame(0, $next->toolWalkState['index']);
    }

    public function testSecondPressWalksOneTranscriptRowOlder(): void
    {
        $app = $this->app([
            Message::assistant('a')->withToolResults([ToolResult::ok('grep', 'a', 'call_old')]),
            Message::assistant('b')->withToolResults([ToolResult::ok('grep', 'b', 'call_new')]),
        ]);

        [$first] = $this->pressO($app);
        [$second, $cmd] = $this->pressO($first);

        $this->assertNull($cmd);
        $this->assertTrue($second->chat->isToolOutputExpanded('call_new'), 'the newest row stays as the first press left it');
        $this->assertTrue($second->chat->isToolOutputExpanded('call_old'), 'the older row opens next');
        $this->assertSame(1, $second->toolWalkState['index']);
    }

    public function testTheWalkCyclesWhenTheTranscriptRunsOut(): void
    {
        $app = $this->app([
            Message::assistant('a')->withToolResults([ToolResult::ok('grep', 'a', 'call_one')]),
            Message::assistant('b')->withToolResults([ToolResult::ok('grep', 'b', 'call_two')]),
            Message::assistant('c')->withToolResults([ToolResult::ok('grep', 'c', 'call_three')]),
        ]);

        [$a] = $this->pressO($app);            // newest (call_three)
        [$b] = $this->pressO($a);              // call_two
        [$c] = $this->pressO($b);              // call_one
        $this->assertSame(2, $c->toolWalkState['index']);

        [$d] = $this->pressO($c);              // wraps back to the newest row

        $this->assertSame(0, $d->toolWalkState['index'], 'the burst wraps to index 0');
        $this->assertFalse($d->chat->isToolOutputExpanded('call_three'), 're-opening the newest row collapses it again');
        $this->assertTrue($d->chat->isToolOutputExpanded('call_one'));
        $this->assertTrue($d->chat->isToolOutputExpanded('call_two'));
    }

    public function testThoughtsAreWalkedToo(): void
    {
        $olderThought = 'settled reasoning on the older row';
        $newestThought = 'settled reasoning on the newest row';
        $app = $this->app([
            Message::assistant('older', null, $olderThought)->withToolResults([ToolResult::ok('grep', 'a', 'call_old')]),
            Message::assistant('newest', null, $newestThought)->withToolResults([ToolResult::ok('grep', 'b', 'call_new')]),
        ]);

        [$a] = $this->pressO($app);
        $this->assertTrue($a->chat->isToolOutputExpanded(Renderer::thoughtKey($newestThought)), 'press 1 opens the newest unit (thought with its row, as Chat pairs them)');

        [$b] = $this->pressO($a);

        $this->assertTrue($b->chat->isToolOutputExpanded(Renderer::thoughtKey($olderThought)), 'the older thought row opens on the walk');
        $this->assertTrue($b->chat->isToolOutputExpanded('call_old'), 'as one unit with its tool body');
        $this->assertTrue($b->chat->isToolOutputExpanded('call_new'), 'the newest unit stays where press 1 left it');
    }

    public function testAnIdleShellWithoutToolRowsIsANoOp(): void
    {
        $app = $this->app([Message::user('hi'), Message::assistant('hello')]);

        [$next, $cmd] = $this->pressO($app);

        $this->assertNull($cmd, 'the chord raises nothing');
        $this->assertSame($app, $next, 'and mutates nothing');
        $this->assertSame('', $next->chat->inputBuf, 'Ctrl+O never types an "o" into the input');
    }

    public function testAModalOnScreenDisarmsTheWalk(): void
    {
        $app = $this->app([
            Message::assistant('a')->withToolResults([ToolResult::ok('grep', 'a', 'call_old')]),
            Message::assistant('b')->withToolResults([ToolResult::ok('grep', 'b', 'call_new')]),
        ]);

        [$armed] = $this->pressO($app);          // walk cursor sits at index 0
        $this->assertSame(0, $armed->toolWalkState['index']);

        // With the command palette up, Ctrl+O belongs to Chat's palette arm,
        // never to the walk: the burst must not resume through the modal.
        [$withPalette] = $armed->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $this->assertNotNull($withPalette->chat?->palette(), 'fixture: the palette is open');

        [$next] = $this->pressO($withPalette);

        $this->assertNotNull($next->chat?->palette(), 'the palette rides out the chord');
        $this->assertSame(0, $next->toolWalkState['index'], 'the burst cursor froze — no older row opened through the modal');
        $this->assertFalse($next->chat->isToolOutputExpanded('call_old'), 'and the older transcript row is untouched');
    }
}

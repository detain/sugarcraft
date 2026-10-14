<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Tests\Commands\ResetsDerivedRuneSets;
use SugarCraft\Crush\ToolResult;

/**
 * rv-a11y M3(c): Ctrl+O no longer stops at the newest row. The first press of
 * a burst keeps Chat's newest expansion byte-for-byte; every further press
 * within TOOL_WALK_WINDOW_SECONDS walks one transcript row older, cycling when
 * the history runs out. This is the only keyboard route to an older tool
 * result or settled thought once a newer row has arrived.
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

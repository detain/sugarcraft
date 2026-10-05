<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Host\TurnRunner;
use SugarCraft\Crush\Host\WorkspaceContext;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Todo\TodoItem;
use SugarCraft\Crush\Todo\TodoList;
use SugarCraft\Crush\Todo\TodoStatus;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Tools\BuiltIn\Todo;
use SugarCraft\Crush\Tui\Components\MenuBar;
use SugarCraft\Crush\Tui\Components\TodoPane;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;

/**
 * Roadmap 3.C: the dock's Todo pane — the session's checklist, read from
 * memory only (the runner's held list, else the newest copy in the
 * transcript), never wider than its box.
 */
final class TodoPaneTest extends TestCase
{
    public function testAnEmptySessionSaysSo(): void
    {
        $plain = self::plain(TodoPane::render(self::app([]), 30, 8));

        $this->assertStringContainsString(Pane::Todo->icon() . ' todo', $plain);
        $this->assertStringContainsString(Lang::t(TodoPane::EMPTY_TEXT), $plain);
    }

    public function testTheListComesFromTheTranscriptsNewestCopy(): void
    {
        $plain = self::plain(TodoPane::render(self::app(self::historyWith(self::list())), 40, 10));

        $this->assertStringContainsString('todo 1/3', $plain, 'the done count rides the title');
        $this->assertStringContainsString("\u{2611} Write the parser", $plain);
        $this->assertStringContainsString("\u{25B8} Wire the pane", $plain);
        $this->assertStringContainsString("\u{2610} Update the docs", $plain);
    }

    public function testTheRunnersHeldListWinsOverTheTranscript(): void
    {
        $runner = TurnRunner::new();
        $newer = TodoList::new(TodoItem::new('Wire the pane', TodoStatus::Completed), TodoItem::new('Ship it', TodoStatus::InProgress));
        $runner->saveTodos(null, null, $newer);
        $workspace = WorkspaceContext::new()->withService(TurnRunner::class, $runner);
        $app = self::app(self::historyWith(self::list()), $workspace);

        $this->assertTrue(TodoPane::todos($app)->equals($newer), 'mid-turn, the frame the runner read is newer than the folded rows');
        $this->assertStringContainsString("\u{25B8} Ship it", self::plain(TodoPane::render($app, 40, 10)));
    }

    public function testEveryLineFitsThePaneAndALongListKeepsTheActiveItemInView(): void
    {
        $items = [];
        for ($i = 1; $i <= 20; $i++) {
            $items[] = TodoItem::new("step {$i} " . str_repeat('very long words ', 6), $i < 15 ? TodoStatus::Completed : ($i === 15 ? TodoStatus::InProgress : TodoStatus::Pending));
        }
        $rendered = TodoPane::render(self::app(self::historyWith(TodoList::new(...$items))), 24, 8);

        // The box's own measure is the convention every dock pane shares
        // (SkillsPane renders the same width for the same budget); what
        // matters is that no row pushes past the border.
        $lines = explode("\n", $rendered);
        $box = Width::string($lines[0]);
        foreach ($lines as $line) {
            $this->assertSame($box, Width::string($line), 'a row is wider than the pane\'s border');
        }
        $this->assertSame(Width::string(explode("\n", \SugarCraft\Crush\Tui\Components\SkillsPane::render(self::app([]), 24, 8))[0]), $box);
        $plain = self::plain($rendered);
        $this->assertStringContainsString("\u{25B8} step 15", $plain, 'the item being worked on is in view');
        $this->assertStringContainsString('step 14', $plain, 'with the one before it for context');
        $this->assertStringNotContainsString('step 1 ', $plain);
    }

    public function testThePaneDocksOnTheRightAndRendersInTheFrame(): void
    {
        $this->assertSame(\SugarCraft\Layout\Dock\Side::Right, Pane::Todo->dockSide());

        $app = self::app(self::historyWith(self::list()))->togglePaneDocking(Pane::Todo);
        $this->assertTrue($app->isDocked(Pane::Todo));

        $frame = self::plain(TuiRenderer::renderView($app, 120, 30)->body);
        $this->assertStringContainsString('todo 1/3', $frame);
        $this->assertStringContainsString('Wire the pane', $frame);
    }

    public function testTheMenuBarAdvertisesTheTodoTab(): void
    {
        $bar = self::plain(MenuBar::render(self::app([])));

        $this->assertStringContainsString('[' . Pane::Todo->icon() . ' Todo]', $bar);
    }

    // ── harness ─────────────────────────────────────────────────────────

    /** @param list<Message> $history */
    private static function app(array $history, ?WorkspaceContext $workspace = null): App
    {
        return App::new(new EchoProvider(), 'test-model')
            ->withChat((new Chat(history: $history, workspace: $workspace))->withSize(120, 30));
    }

    /** @return list<Message> */
    private static function historyWith(TodoList $list): array
    {
        return [
            Message::user('plan it'),
            Message::assistant('')->withToolResults([ToolResult::ok(Todo::NAME, Todo::UPDATED . "\n\n" . $list->render(), 'c1')]),
        ];
    }

    private static function list(): TodoList
    {
        return TodoList::new(
            TodoItem::new('Write the parser', TodoStatus::Completed),
            TodoItem::new('Wire the pane', TodoStatus::InProgress),
            TodoItem::new('Update the docs'),
        );
    }

    private static function plain(string $s): string
    {
        return (string) preg_replace('/\x1b\[[0-9;:]*m/', '', $s);
    }
}

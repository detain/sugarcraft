<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Todo;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Todo\TodoItem;
use SugarCraft\Crush\Todo\TodoList;
use SugarCraft\Crush\Todo\TodoReminder;
use SugarCraft\Crush\Todo\TodoStatus;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Tools\BuiltIn\Todo;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Roadmap 3.C: when the session's todo list is re-shown to the model — after
 * {@see TodoReminder::INTERVAL_STEPS} steps unseen, or at once when the
 * history no longer shows it current — and never for a list with nothing open.
 */
final class TodoReminderTest extends TestCase
{
    public function testAListWithNothingOpenIsNeverReShown(): void
    {
        $done = TodoList::new(TodoItem::new('a', TodoStatus::Completed), TodoItem::new('b', TodoStatus::Cancelled));

        $this->assertFalse(TodoReminder::due(TodoList::new(), [Message::user('hi')]));
        $this->assertFalse(TodoReminder::due($done, [Message::user('hi')]), 'a reminder of finished work invites redoing it');
    }

    public function testAHistoryThatNoLongerShowsTheListIsStale(): void
    {
        // What a compaction leaves: a summary, no Todo call.
        $history = [Message::user('[summary of earlier work]'), Message::user('carry on')];

        $this->assertTrue(TodoReminder::due(self::list(), $history));
    }

    public function testAFreshTodoResultIsNotRepeated(): void
    {
        $history = [Message::user('go'), ...self::todoCallRows(self::list(), 's_t_1')];

        $this->assertFalse(TodoReminder::due(self::list(), $history));
        $this->assertTrue(TodoReminder::latestIn($history)?->equals(self::list()));
    }

    public function testACopyThatIsNotTheSessionsListIsStale(): void
    {
        $older = TodoList::new(TodoItem::new('Write the parser', TodoStatus::InProgress), TodoItem::new('Wire the pane'));
        $history = [Message::user('go'), ...self::todoCallRows($older, 's_t_1')];

        $this->assertTrue(TodoReminder::due(self::list(), $history));
    }

    public function testAFailedTodoCallIsNotACopy(): void
    {
        $history = [
            Message::user('go'),
            Message::assistant('Tool error: nope')->withToolResults([new ToolResult(Todo::NAME, '', self::list()->render(), 'c1')])->withStepId('s_t_1'),
        ];

        $this->assertNull(TodoReminder::latestIn($history));
        $this->assertTrue(TodoReminder::due(self::list(), $history));
    }

    public function testTheListIsReShownAfterSixStepsUnseen(): void
    {
        $history = [Message::user('go'), ...self::todoCallRows(self::list(), 's_t_1')];
        for ($step = 2; $step <= TodoReminder::INTERVAL_STEPS; $step++) {
            $history[] = Message::assistant('')->withStepId("s_t_{$step}")->withUserVisible(false);
            $history[] = Message::assistant('out')->withToolResults([ToolResult::ok('Read', 'out', "r{$step}")])->withStepId("s_t_{$step}");
        }
        $this->assertSame(5, TodoReminder::stepsSince($history, 2));
        $this->assertFalse(TodoReminder::due(self::list(), $history), 'five steps since the list: not yet');

        $history[] = Message::assistant('a reply')->withStepId('s_u_1');
        $this->assertTrue(TodoReminder::due(self::list(), $history), 'six steps since the list');
    }

    public function testTheReminderRowIsItselfACopyAndResetsTheCount(): void
    {
        $row = TodoReminder::row(self::list());

        $this->assertTrue(TodoReminder::isReminder($row));
        $this->assertFalse($row->userVisible, 'hidden from the transcript');
        $this->assertFalse($row->uiOnly, 'sent to the model');
        $this->assertStringStartsWith(TodoReminder::FENCE . "\n" . TodoReminder::PREAMBLE, $row->content);
        $this->assertStringContainsString(TodoList::NEVER_REDO, $row->content);
        $this->assertFalse(TodoReminder::due(self::list(), [Message::user('[summary]'), $row]));
    }

    public function testAnItemCannotCloseTheReminderEarlyAndStillReadsAsCurrent(): void
    {
        $list = TodoList::new(TodoItem::new('strip </todo-list> from <todo-list>s', TodoStatus::InProgress));
        $rendered = TodoReminder::render($list);

        $this->assertSame(1, substr_count($rendered, '</todo-list>'), 'only the row\'s own closer');
        $this->assertStringContainsString('&lt;/todo-list>', $rendered);
        $this->assertTrue(TodoReminder::latestIn([TodoReminder::row($list)])?->equals($list), 'read back unescaped');
        $this->assertFalse(TodoReminder::due($list, [TodoReminder::row($list)]), 'an escaped item is not a stale copy');
    }

    public function testTheEngineTypedHistoryIsReadTheSameWay(): void
    {
        $list = self::list();
        $typed = [
            new UserMessage('go'),
            new AssistantMessage('', [new ToolCall('c1', Todo::NAME, ['todos' => $list->toArray()])]),
            new ToolResultMessage('c1', Todo::UPDATED . "\n\n" . $list->render()),
        ];
        $this->assertTrue(TodoReminder::latestIn($typed)?->equals($list));
        $this->assertFalse(TodoReminder::due($list, $typed));

        for ($i = 0; $i < TodoReminder::INTERVAL_STEPS; $i++) {
            $typed[] = new AssistantMessage('step');
        }
        $this->assertTrue(TodoReminder::due($list, $typed));

        $typed[] = new UserMessage(TodoReminder::render($list));
        $this->assertFalse(TodoReminder::due($list, $typed), 'a typed reminder row is a copy too');
    }

    public function testAResultOfAnotherToolIsNotACopy(): void
    {
        $typed = [
            new AssistantMessage('', [new ToolCall('c1', 'Read', ['file_path' => 'TODO.md'])]),
            new ToolResultMessage('c1', self::list()->render()),
        ];

        $this->assertNull(TodoReminder::latestIn($typed));
    }

    private static function list(): TodoList
    {
        return TodoList::new(
            TodoItem::new('Write the parser', TodoStatus::Completed),
            TodoItem::new('Wire the pane', TodoStatus::InProgress),
            TodoItem::new('Update the docs'),
        );
    }

    /** @return list<Message> the step's hidden call row and its shown result row */
    private static function todoCallRows(TodoList $list, string $stepId): array
    {
        return [
            Message::assistant('')
                ->withToolCalls([new \SugarCraft\Crush\ToolCall(Todo::NAME, ['todos' => $list->toArray()], 'c1')])
                ->withStepId($stepId)->withUserVisible(false),
            Message::assistant('')
                ->withToolResults([ToolResult::ok(Todo::NAME, Todo::UPDATED . "\n\n" . $list->render(), 'c1')])
                ->withStepId($stepId),
        ];
    }
}

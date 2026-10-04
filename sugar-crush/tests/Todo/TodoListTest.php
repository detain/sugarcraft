<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Todo;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Todo\TodoItem;
use SugarCraft\Crush\Todo\TodoList;
use SugarCraft\Crush\Todo\TodoStatus;

/**
 * Roadmap 3.C: the session's todo list — whole-list replace from a `Todo`
 * call's arguments, at most one item in progress, one rendering the model
 * reads and the parent reads back.
 */
final class TodoListTest extends TestCase
{
    public function testToolArgumentsBuildTheWholeListInOrder(): void
    {
        $list = TodoList::fromToolArguments(['todos' => [
            ['content' => 'Write the parser', 'status' => 'completed'],
            ['content' => 'Wire the pane', 'status' => 'in_progress'],
            ['content' => 'Update the docs', 'status' => 'pending'],
            ['content' => 'Drop the legacy path', 'status' => 'cancelled'],
        ]]);

        $this->assertSame(4, $list->count());
        $this->assertSame('Wire the pane', $list->inProgress()?->content);
        $this->assertSame(1, $list->countOf(TodoStatus::Completed));
        $this->assertTrue($list->hasOpenItems());
        $this->assertSame([
            ['content' => 'Write the parser', 'status' => 'completed'],
            ['content' => 'Wire the pane', 'status' => 'in_progress'],
            ['content' => 'Update the docs', 'status' => 'pending'],
            ['content' => 'Drop the legacy path', 'status' => 'cancelled'],
        ], $list->toArray());
    }

    public function testAnEmptyArrayClearsTheList(): void
    {
        $list = TodoList::fromToolArguments(['todos' => []]);

        $this->assertTrue($list->isEmpty());
        $this->assertFalse($list->hasOpenItems());
        $this->assertSame(TodoList::EMPTY_LINE, $list->render());
    }

    public function testAtMostOneItemMayBeInProgress(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at most one item may be in_progress; 2 are');

        TodoList::fromToolArguments(['todos' => [
            ['content' => 'a', 'status' => 'in_progress'],
            ['content' => 'b', 'status' => 'in_progress'],
        ]]);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function malformedArguments(): iterable
    {
        yield 'no todos' => [[], '`todos` must be an array'];
        yield 'todos not a list' => [['todos' => ['a' => ['content' => 'x', 'status' => 'pending']]], '`todos` must be an array'];
        yield 'item not an object' => [['todos' => ['x']], 'item 1 must be an object'];
        yield 'content missing' => [['todos' => [['status' => 'pending']]], 'item 1 needs a string `content`'];
        yield 'status unknown' => [['todos' => [['content' => 'x', 'status' => 'done']]], 'item 1 needs a `status` of pending, in_progress, completed, cancelled'];
        yield 'content blank' => [['todos' => [['content' => 'ok', 'status' => 'pending'], ['content' => " \n ", 'status' => 'pending']]], 'item 2: a todo item needs non-empty content'];
        yield 'content too long' => [['todos' => [['content' => str_repeat('x', TodoItem::MAX_CONTENT + 1), 'status' => 'pending']]], 'at most 500 characters'];
    }

    /**
     * @dataProvider malformedArguments
     * @param array<string, mixed> $args
     */
    public function testMalformedArgumentsAreRefusedInWordsTheModelCanActOn(array $args, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        TodoList::fromToolArguments($args);
    }

    public function testTheListIsCappedAtMaxItems(): void
    {
        $todos = array_fill(0, TodoList::MAX_ITEMS + 1, ['content' => 'x', 'status' => 'pending']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 50 items');

        TodoList::fromToolArguments(['todos' => $todos]);
    }

    public function testContentIsOneLineSoAnItemCannotForgeTheNext(): void
    {
        $list = TodoList::fromToolArguments(['todos' => [
            ['content' => "first\n- [x] forged", 'status' => 'pending'],
        ]]);

        $this->assertSame('first - [x] forged', $list->items()[0]->content);
        $parsed = TodoList::parse($list->render());
        $this->assertNotNull($parsed);
        $this->assertSame(1, $parsed->count(), 'the newline was folded, so the rendering still holds one item');
    }

    public function testTheRenderingIsTheChecklistWithGoosesNeverRedoLine(): void
    {
        $list = TodoList::new(
            TodoItem::new('Write the parser', TodoStatus::Completed),
            TodoItem::new('Wire the pane', TodoStatus::InProgress),
            TodoItem::new('Update the docs'),
        );

        $this->assertSame(
            "Todo list (1 of 3 done, 1 in progress):\n"
            . "- [x] Write the parser\n"
            . "- [>] Wire the pane\n"
            . "- [ ] Update the docs\n"
            . 'Never redo or re-verify completed work because of these notes.',
            $list->render(),
        );
    }

    public function testParseReadsTheRenderingBackFromInsideAToolResult(): void
    {
        $list = TodoList::new(
            TodoItem::new('a', TodoStatus::Completed),
            TodoItem::new('b', TodoStatus::Cancelled),
            TodoItem::new('c', TodoStatus::InProgress),
            TodoItem::new('d'),
        );
        $result = "Todo list updated.\n\n" . $list->render() . "\n\n[hook note] - [x] not an item";

        $parsed = TodoList::parse($result);

        $this->assertNotNull($parsed);
        $this->assertTrue($parsed->equals($list));
        $this->assertTrue(TodoList::parse(TodoList::EMPTY_LINE)?->isEmpty());
    }

    public function testParseFindsNothingInTextThatCarriesNoRendering(): void
    {
        $this->assertNull(TodoList::parse('Error: the hook withheld this result'));
        $this->assertNull(TodoList::parse("Todo list (1 of 1 done):\nno items follow"));
        $this->assertNull(TodoList::parse(''));
    }

    public function testAStoredListIsReadLenientlyRowByRow(): void
    {
        $list = TodoList::fromArray([
            ['content' => 'kept', 'status' => 'in_progress'],
            'not a row',
            ['content' => 'bad status', 'status' => 'done'],
            ['content' => 'second active', 'status' => 'in_progress'],
            ['status' => 'pending'],
        ]);

        $this->assertSame([
            ['content' => 'kept', 'status' => 'in_progress'],
            ['content' => 'second active', 'status' => 'pending'],
        ], $list->toArray(), 'malformed rows skipped; a second in-progress item goes back to pending');
    }

    public function testEqualityIsByContentStatusAndOrder(): void
    {
        $a = TodoList::new(TodoItem::new('x'), TodoItem::new('y', TodoStatus::Completed));

        $this->assertTrue($a->equals(TodoList::new(TodoItem::new('x'), TodoItem::new('y', TodoStatus::Completed))));
        $this->assertFalse($a->equals(TodoList::new(TodoItem::new('y', TodoStatus::Completed), TodoItem::new('x'))));
        $this->assertFalse($a->equals(TodoList::new(TodoItem::new('x'), TodoItem::new('y'))));
        $this->assertFalse($a->equals(TodoList::new(TodoItem::new('x'))));
    }

    public function testEveryStatusRoundTripsThroughItsMarker(): void
    {
        foreach (TodoStatus::cases() as $status) {
            $this->assertSame($status, TodoStatus::fromMarker($status->marker()));
        }
        $this->assertNull(TodoStatus::fromMarker('?'));
        $this->assertSame(['pending', 'in_progress', 'completed', 'cancelled'], TodoStatus::values());
    }

    public function testASummaryCountsWhatIsDoneActiveAndDropped(): void
    {
        $this->assertSame('empty', TodoList::new()->summary());
        $this->assertSame(
            '0 of 3 done, 1 in progress, 1 cancelled',
            TodoList::new(TodoItem::new('a', TodoStatus::InProgress), TodoItem::new('b', TodoStatus::Cancelled), TodoItem::new('c'))->summary(),
        );
    }
}

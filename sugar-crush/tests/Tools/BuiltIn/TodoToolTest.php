<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook;
use SugarCraft\Crush\Hooks\HookConfig;
use SugarCraft\Crush\Permissions\PermissionAction;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\Permissions\SafetyClassifier;
use SugarCraft\Crush\Todo\TodoList;
use SugarCraft\Crush\Todo\TodoStatus;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\Tools\BuiltIn\Todo;
use SugarCraft\Crush\Tools\Catalog\ToolCatalog;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;

/**
 * Roadmap 3.C: the `Todo` tool rewrites the session's whole checklist, at
 * most one item in progress, and is no-ask — it writes only the session's own
 * list.
 */
final class TodoToolTest extends TestCase
{
    public function testItIsCatalogedNoAskAfterTheExistingWireOrder(): void
    {
        $tool = new Todo();

        self::assertSame('Todo', $tool->name());
        self::assertSame(ToolPermissionClass::NoAsk, ToolCatalog::permissionOf(Todo::NAME));
        self::assertContains(Todo::NAME, array_map(static fn ($e): string => $e->name, ToolCatalog::built()));
        self::assertNotContains(Todo::NAME, ToolCatalog::namesOf(ToolPermissionClass::Read));
        self::assertNotContains(Todo::NAME, ToolCatalog::namesOf(ToolPermissionClass::Write));
    }

    public function testTheSchemaAsksForTheWholeListOfContentAndStatus(): void
    {
        $schema = (new Todo())->inputSchema();

        self::assertSame(['todos'], $schema['required']);
        $item = $schema['properties']['todos']['items'];
        self::assertSame(['content', 'status'], $item['required']);
        self::assertSame(TodoStatus::values(), $item['properties']['status']['enum']);
    }

    public function testTheDescriptionCarriesTheRulesAndGoosesNeverRedoLine(): void
    {
        $description = (new Todo())->description();

        self::assertStringContainsString('replaces the WHOLE list', $description);
        self::assertStringContainsString('At most one item may be in_progress', $description);
        self::assertStringContainsString(TodoList::NEVER_REDO, $description);
    }

    public function testASuccessfulCallReturnsTheRenderedListTheParentReadsBack(): void
    {
        $result = (new Todo())->execute(['todos' => [
            ['content' => 'Write the parser', 'status' => 'completed'],
            ['content' => 'Wire the pane', 'status' => 'in_progress'],
        ]]);

        self::assertFalse($result->isError());
        self::assertStringStartsWith(Todo::UPDATED . "\n\nTodo list (1 of 2 done, 1 in progress):", $result->content());
        $parsed = TodoList::parse($result->content());
        self::assertNotNull($parsed);
        self::assertSame('Wire the pane', $parsed->inProgress()?->content);
    }

    public function testAnEmptyListClears(): void
    {
        $result = (new Todo())->execute(['todos' => []]);

        self::assertFalse($result->isError());
        self::assertTrue(TodoList::parse($result->content())?->isEmpty());
    }

    public function testAnInvalidListIsAnErrorThatChangesNothing(): void
    {
        $result = (new Todo())->execute(['todos' => [
            ['content' => 'a', 'status' => 'in_progress'],
            ['content' => 'b', 'status' => 'in_progress'],
        ]]);

        self::assertTrue($result->isError());
        self::assertStringContainsString('at most one item may be in_progress', $result->content());
        self::assertStringContainsString('The list was not changed.', $result->content());
        self::assertNull(TodoList::parse($result->content()), 'an error carries no list for the parent to adopt');
    }

    public function testMissingArgumentsAreAnErrorNotAThrow(): void
    {
        $result = (new Todo())->execute([]);

        self::assertTrue($result->isError());
        self::assertStringContainsString('`todos` must be an array', $result->content());
    }

    /** @return iterable<string, array{PermissionMode}> */
    public static function modes(): iterable
    {
        foreach (PermissionMode::cases() as $mode) {
            yield $mode->value => [$mode];
        }
    }

    #[DataProvider('modes')]
    public function testEveryModeAllowsIt(PermissionMode $mode): void
    {
        $gate = new PermissionGate($mode, [], new SafetyClassifier());

        self::assertSame(
            PermissionDecision::Allow,
            $gate->evaluate(new ToolCall(Todo::NAME, ['todos' => [['content' => 'x', 'status' => 'pending']]])),
        );
    }

    public function testADenyRuleStillTurnsItOff(): void
    {
        $gate = new PermissionGate(PermissionMode::BypassPermissions, [new PermissionRule('Todo', PermissionAction::Deny)]);

        self::assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall(Todo::NAME, ['todos' => []])));
    }

    public function testProtectFilesHookIsNotAskedAboutTodoCalls(): void
    {
        // An item's content is prose, not a path.
        self::assertSame(0, preg_match(HookConfig::pattern((new ProtectFilesHook())->matcher()), Todo::NAME));
    }
}

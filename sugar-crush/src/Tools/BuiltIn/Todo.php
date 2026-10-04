<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Todo\TodoItem;
use SugarCraft\Crush\Todo\TodoList;
use SugarCraft\Crush\Todo\TodoStatus;
use SugarCraft\Crush\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Crush\Tools\Catalog\BuiltInTool;
use SugarCraft\Crush\Tools\Catalog\ToolBuildContext;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * The agent's own checklist for a multi-step task (roadmap 3.C): each call
 * REPLACES the session's whole todo list ({@see TodoList}), and at most one
 * item may be `in_progress`.
 *
 * STATELESS ON PURPOSE. The tool runs in the turn's forked child, so anything
 * it kept would die with the process. It validates the list and returns its
 * rendering; that rendering, read back from the call's finished frame by
 * {@see \SugarCraft\Crush\Host\TurnRunner}, is how the session learns the new
 * list — saved to {@see \SugarCraft\Crush\Session\SessionMeta::$tasks}, shown
 * in the dock's Todo pane, and re-shown to the model by
 * {@see \SugarCraft\Crush\Todo\TodoReminder} when it goes stale or after
 * compaction.
 *
 * PERMISSION CLASS: no-ask. It writes only the session's own list, state the
 * harness owns and the user can see in the pane — a prompt would protect
 * nothing, and an Ask would be a deny wherever no approver is attached.
 * `permissionRules` still apply first, so `{"pattern": "Todo", "action":
 * "deny"}` turns it off.
 */
#[BuiltInTool(name: 'Todo', permission: ToolPermissionClass::NoAsk, position: 15, gloss: "the session's own checklist, rewritten whole on each call, at most one item in progress")]
final readonly class Todo implements Tool, BuildsFromCatalog
{
    public const NAME = 'Todo';

    /** The result's lead-in; the rendered list follows it. */
    public const UPDATED = 'Todo list updated.';

    public static function fromCatalog(ToolBuildContext $context): self
    {
        return new self();
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Keep a checklist for multi-step work. Each call replaces the WHOLE list: send every item, '
            . 'each with `content` (one line) and `status` (' . implode(', ', TodoStatus::values()) . '); '
            . 'an empty `todos` clears it. At most one item may be in_progress: mark an item in_progress '
            . 'before you start it and completed as soon as it is done, not in a batch at the end. Use it '
            . 'for tasks of three or more steps; skip it for a single quick change. The list is kept for the '
            . 'session, survives compaction, is shown to the user, and is re-shown to you when it goes stale. '
            . TodoList::NEVER_REDO;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'todos' => [
                    'type' => 'array',
                    'description' => sprintf('The complete list, in order (at most %d items)', TodoList::MAX_ITEMS),
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'content' => [
                                'type' => 'string',
                                'description' => sprintf('What to do, one line (at most %d characters)', TodoItem::MAX_CONTENT),
                            ],
                            'status' => [
                                'type' => 'string',
                                'enum' => TodoStatus::values(),
                                'description' => 'Where the item stands; at most one item in_progress',
                            ],
                        ],
                        'required' => ['content', 'status'],
                    ],
                ],
            ],
            'required' => ['todos'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        // The runtime pairs the result with its call.
        try {
            $list = TodoList::fromToolArguments($args);
        } catch (\InvalidArgumentException $e) {
            return new ToolResult('', 'Error: ' . $e->getMessage() . '. The list was not changed.', true);
        }

        return new ToolResult('', self::UPDATED . "\n\n" . $list->render());
    }
}

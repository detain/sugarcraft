<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Todo;

use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Tools\BuiltIn\Todo;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Re-shows the session's todo list to the model (roadmap 3.C): every
 * {@see INTERVAL_STEPS} steps since the model last saw it, or at once when
 * what it last saw is STALE — compaction summarised the `Todo` call away, the
 * session was resumed from a store that kept the list but not the call, or
 * the history's newest copy is not the list the session holds.
 *
 * A HIDDEN USER ROW AT THE TAIL, in the same voice as the 1.A turn-context
 * row and kept beside it rather than inside it: the turn-context row is
 * re-sent whenever any of its fields moves (every write step moves the git
 * half), so a list carried inside it would be re-paid on every such step, and
 * one carried only "when due" would make the row's bytes flip on the steps
 * between. This row is appended only when due, persisted into the history
 * like every harness row (hidden from the transcript, sent to the model), so
 * the next request's prefix is this request — the cache never re-reads it.
 *
 * WHAT COUNTS AS A COPY the model has seen: this row, and the result of a
 * `Todo` call that succeeded (the tool returns the rendered list). Both
 * shapes of history are read — the root {@see Message} rows a host keeps, and
 * the engine's typed messages a turn's step loop holds — so the same rule can
 * run at a turn's dispatch and between its steps.
 *
 * Never for a list with nothing open: a reminder of finished work is exactly
 * what {@see TodoList::NEVER_REDO} exists to defuse.
 */
final class TodoReminder
{
    /** Steps the model may take after seeing the list before it is re-shown. */
    public const INTERVAL_STEPS = 6;

    /** The opening fence; {@see isReminder()} recognises a row by it. */
    public const FENCE = '<todo-list>';

    /** The reminder's first line: who speaks, and what to do with it. */
    public const PREAMBLE = 'Harness reminder of your todo list as you last wrote it; it supersedes any earlier copy. '
        . 'Keep it current with the Todo tool: mark an item in_progress before you start it and completed as soon as it is done.';

    private function __construct()
    {
    }

    /** The reminder's bytes for $list. */
    public static function render(TodoList $list): string
    {
        return self::FENCE . "\n" . self::PREAMBLE . "\n\n" . self::escapeOwnFence($list->render()) . "\n</todo-list>";
    }

    /** The row to append: user-role, hidden from the transcript, sent to the model. */
    public static function row(TodoList $list): Message
    {
        return Message::user(self::render($list))->withUserVisible(false);
    }

    /**
     * Whether $message is a reminder row — the root row a host stores or the
     * engine's typed one.
     */
    public static function isReminder(mixed $message): bool
    {
        if ($message instanceof UserMessage) {
            return str_starts_with($message->content(), self::FENCE . "\n");
        }

        return $message instanceof Message
            && $message->role === Role::User
            && str_starts_with($message->content, self::FENCE . "\n");
    }

    /**
     * Whether $list should be re-shown before the next request over $history.
     *
     * @param iterable<mixed> $history root {@see Message} rows or typed engine messages
     */
    public static function due(TodoList $list, iterable $history): bool
    {
        if (!$list->hasOpenItems()) {
            return false;
        }

        $rows = self::listOf($history);
        $copy = self::latestCopyAt($rows);
        if ($copy === null) {
            return true;
        }
        [$at, $seen, $text] = $copy;

        // A reminder is compared by its bytes, so an escaped fence inside an
        // item cannot make an unchanged list look stale forever.
        $stale = $text !== null ? $text !== self::render($list) : !$seen->equals($list);

        return $stale || self::stepsSince($rows, $at) >= self::INTERVAL_STEPS;
    }

    /**
     * The newest list $history shows the model, or null when it shows none.
     *
     * @param iterable<mixed> $history
     */
    public static function latestIn(iterable $history): ?TodoList
    {
        return self::latestCopyAt(self::listOf($history))[1] ?? null;
    }

    /**
     * The model's steps in $rows after index $at: one per engine step (rows
     * sharing a step id are one step), one per typed assistant message.
     *
     * @param list<mixed> $rows
     */
    public static function stepsSince(array $rows, int $at): int
    {
        $steps = 0;
        $stepIds = [];
        for ($i = $at + 1, $n = \count($rows); $i < $n; $i++) {
            $row = $rows[$i];
            if ($row instanceof AssistantMessage) {
                $steps++;
            } elseif ($row instanceof Message && $row->role === Role::Assistant && !$row->uiOnly) {
                if ($row->stepId !== null) {
                    $stepIds[$row->stepId] = true;
                } elseif ($row->toolResults === []) {
                    // A reply recorded before steps were: one step each.
                    $steps++;
                }
            }
        }

        return $steps + \count($stepIds);
    }

    /**
     * @param list<mixed> $rows
     * @return array{0: int, 1: TodoList, 2: ?string}|null the copy's index, the
     *         list it shows, and — for a reminder row — its exact bytes
     */
    private static function latestCopyAt(array $rows): ?array
    {
        /** @var array<string, true> $todoCalls typed-history call ids of Todo calls */
        $todoCalls = [];
        foreach ($rows as $row) {
            if ($row instanceof AssistantMessage) {
                foreach ($row->toolCalls() ?? [] as $call) {
                    if ($call instanceof ToolCall && $call->name() === Todo::NAME) {
                        $todoCalls[$call->id()] = true;
                    }
                }
            }
        }

        for ($i = \count($rows) - 1; $i >= 0; $i--) {
            $row = $rows[$i];
            if (self::isReminder($row)) {
                $text = $row instanceof UserMessage ? $row->content() : $row->content;
                $list = TodoList::parse(self::unescapeOwnFence($text));
                if ($list !== null) {
                    return [$i, $list, $text];
                }

                continue;
            }

            $result = self::todoResultText($row, $todoCalls);
            if ($result !== null && ($list = TodoList::parse($result)) !== null) {
                return [$i, $list, null];
            }
        }

        return null;
    }

    /**
     * The text of a successful `Todo` call's result row, or null for any
     * other row.
     *
     * @param array<string, true> $todoCalls
     */
    private static function todoResultText(mixed $row, array $todoCalls): ?string
    {
        if ($row instanceof ToolResultMessage) {
            return !$row->isError() && isset($todoCalls[$row->toolCallId()]) ? $row->content() : null;
        }
        if (!$row instanceof Message || $row->uiOnly) {
            return null;
        }
        foreach ($row->toolResults as $result) {
            if ($result instanceof \SugarCraft\Crush\ToolResult && $result->name === Todo::NAME && !$result->isError()) {
                return $result->result;
            }
        }

        return null;
    }

    /**
     * @param iterable<mixed> $history
     * @return list<mixed>
     */
    private static function listOf(iterable $history): array
    {
        $rows = [];
        foreach ($history as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Rewrite the `<` of a `<todo-list` / `</todo-list` opener inside an item
     * to `&lt;`, so an item cannot close the row early — the rule
     * {@see \SugarCraft\Crush\Context\TurnContextBlock} applies to its own fence.
     */
    private static function escapeOwnFence(string $payload): string
    {
        return (string) preg_replace('~<(?=/?todo-list(?:[\s/>]|\z))~i', '&lt;', $payload);
    }

    private static function unescapeOwnFence(string $payload): string
    {
        return (string) preg_replace('~&lt;(?=/?todo-list(?:[\s/>]|\z))~i', '<', $payload);
    }
}

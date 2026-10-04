<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Todo;

/**
 * The session's todo list (roadmap 3.C): the agent's own checklist for a
 * multi-step task, written whole by the `Todo` tool
 * ({@see \SugarCraft\Crush\Tools\BuiltIn\Todo}), kept per session in
 * {@see \SugarCraft\Crush\Session\SessionMeta::$tasks}, shown in the dock's
 * Todo pane and re-shown to the model by {@see TodoReminder}.
 *
 * WHOLE-LIST REPLACE. Every write carries the complete list, so there is no
 * item id to keep in step and no partial update to reconcile — the model
 * re-states the plan, and the newest statement is the plan. The one rule the
 * list enforces is the one every surveyed agent's todo tool states: at most
 * ONE item is `in_progress`, because "what am I doing now" must have one
 * answer.
 *
 * ONE RENDERING, READ BOTH WAYS. {@see render()} is what the model reads (the
 * tool's result, the reminder row) and {@see parse()} reads it back. The
 * parent process learns a forked turn's new list from the `Todo` call's
 * finished frame — the rendering the tool actually returned, after any hook
 * rewrote its input — so the checklist format is the wire format too.
 */
final readonly class TodoList
{
    /** Most items a list holds. */
    public const MAX_ITEMS = 50;

    /** The first word of every rendering; {@see parse()} anchors on it. */
    public const HEADER = 'Todo list';

    /** The rendering of a list with no items. */
    public const EMPTY_LINE = self::HEADER . ': empty.';

    /**
     * Goose's anti-over-use line (crush_report §08-goose §6): a reminder of
     * the plan must never read as an instruction to do finished work again.
     */
    public const NEVER_REDO = 'Never redo or re-verify completed work because of these notes.';

    /** @param list<TodoItem> $items */
    private function __construct(private array $items)
    {
    }

    /**
     * @throws \InvalidArgumentException over {@see MAX_ITEMS} items, or more
     *                                   than one in progress
     */
    public static function new(TodoItem ...$items): self
    {
        $items = array_values($items);
        if (\count($items) > self::MAX_ITEMS) {
            throw new \InvalidArgumentException(sprintf('a todo list holds at most %d items', self::MAX_ITEMS));
        }
        $active = array_filter($items, static fn (TodoItem $item): bool => $item->status === TodoStatus::InProgress);
        if (\count($active) > 1) {
            throw new \InvalidArgumentException(sprintf(
                'at most one item may be in_progress; %d are — finish or pause one before starting the next',
                \count($active),
            ));
        }

        return new self($items);
    }

    /**
     * The list a `Todo` call's arguments state: `{"todos": [{"content", "status"}, …]}`.
     *
     * @param array<string, mixed> $args
     *
     * @throws \InvalidArgumentException naming the first thing wrong, in words
     *                                   the model can act on
     */
    public static function fromToolArguments(array $args): self
    {
        $todos = $args['todos'] ?? null;
        if (!\is_array($todos) || !array_is_list($todos)) {
            throw new \InvalidArgumentException('`todos` must be an array of {content, status} objects (an empty array clears the list)');
        }

        $items = [];
        foreach ($todos as $i => $todo) {
            $n = $i + 1;
            if (!\is_array($todo)) {
                throw new \InvalidArgumentException("item {$n} must be an object with `content` and `status`");
            }
            $content = $todo['content'] ?? null;
            if (!\is_string($content)) {
                throw new \InvalidArgumentException("item {$n} needs a string `content`");
            }
            $status = \is_string($todo['status'] ?? null) ? TodoStatus::tryFrom($todo['status']) : null;
            if ($status === null) {
                throw new \InvalidArgumentException("item {$n} needs a `status` of " . implode(', ', TodoStatus::values()));
            }

            try {
                $items[] = TodoItem::new($content, $status);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException("item {$n}: " . $e->getMessage(), 0, $e);
            }
        }

        return self::new(...$items);
    }

    /**
     * A stored list ({@see toArray()}), read leniently: a row out of shape is
     * skipped, the list is cut at {@see MAX_ITEMS}, and an in-progress item
     * after the first goes back to pending — what was saved is shown rather
     * than lost over a rule a newer build added.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $items = [];
        $active = false;
        foreach ($data as $row) {
            $item = TodoItem::fromArray($row);
            if ($item === null) {
                continue;
            }
            if ($item->status === TodoStatus::InProgress) {
                $item = $active ? $item->withStatus(TodoStatus::Pending) : $item;
                $active = true;
            }
            $items[] = $item;
            if (\count($items) === self::MAX_ITEMS) {
                break;
            }
        }

        return new self($items);
    }

    /** @return list<array{content: string, status: string}> */
    public function toArray(): array
    {
        return array_map(static fn (TodoItem $item): array => $item->toArray(), $this->items);
    }

    /** @return list<TodoItem> */
    public function items(): array
    {
        return $this->items;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return \count($this->items);
    }

    /** The item being worked on, or null when none is. */
    public function inProgress(): ?TodoItem
    {
        foreach ($this->items as $item) {
            if ($item->status === TodoStatus::InProgress) {
                return $item;
            }
        }

        return null;
    }

    /** Whether any item still asks for work. */
    public function hasOpenItems(): bool
    {
        foreach ($this->items as $item) {
            if ($item->status->isOpen()) {
                return true;
            }
        }

        return false;
    }

    public function countOf(TodoStatus $status): int
    {
        return \count(array_filter($this->items, static fn (TodoItem $item): bool => $item->status === $status));
    }

    public function equals(self $other): bool
    {
        if (\count($this->items) !== \count($other->items)) {
            return false;
        }
        foreach ($this->items as $i => $item) {
            if (!$item->equals($other->items[$i])) {
                return false;
            }
        }

        return true;
    }

    /** "2 of 5 done, 1 in progress" — the counts a header or a pane title shows. */
    public function summary(): string
    {
        if ($this->items === []) {
            return 'empty';
        }
        $parts = [sprintf('%d of %d done', $this->countOf(TodoStatus::Completed), \count($this->items))];
        if (($active = $this->countOf(TodoStatus::InProgress)) > 0) {
            $parts[] = "{$active} in progress";
        }
        if (($cancelled = $this->countOf(TodoStatus::Cancelled)) > 0) {
            $parts[] = "{$cancelled} cancelled";
        }

        return implode(', ', $parts);
    }

    /**
     * The checklist the model reads:
     *
     *     Todo list (1 of 3 done, 1 in progress):
     *     - [x] Write the parser
     *     - [>] Wire the pane
     *     - [ ] Update the docs
     *     Never redo or re-verify completed work because of these notes.
     */
    public function render(): string
    {
        if ($this->items === []) {
            return self::EMPTY_LINE;
        }

        $lines = [self::HEADER . ' (' . $this->summary() . '):'];
        foreach ($this->items as $item) {
            $lines[] = '- [' . $item->status->marker() . '] ' . $item->content;
        }
        $lines[] = self::NEVER_REDO;

        return implode("\n", $lines);
    }

    /**
     * The list a {@see render()}ing states, found anywhere in $text (a tool
     * result may carry a lead-in line, and a PostToolUse hook may append to
     * it), or null when $text holds no rendering. Items are the `- [m] …`
     * lines straight after the header, up to the first line that is not one.
     */
    public static function parse(string $text): ?self
    {
        $lines = preg_split('/\R/', $text) ?: [];
        foreach ($lines as $i => $line) {
            if ($line === self::EMPTY_LINE) {
                return new self([]);
            }
            if (!str_starts_with($line, self::HEADER . ' (') || !str_ends_with($line, '):')) {
                continue;
            }

            $items = [];
            for ($j = $i + 1, $n = \count($lines); $j < $n; $j++) {
                if (preg_match('/^- \[(.)\] (.+)$/u', $lines[$j], $m) !== 1) {
                    break;
                }
                $status = TodoStatus::fromMarker($m[1]);
                if ($status === null) {
                    break;
                }
                try {
                    $items[] = TodoItem::new($m[2], $status);
                } catch (\InvalidArgumentException) {
                    return null;
                }
            }

            try {
                return $items === [] ? null : self::new(...$items);
            } catch (\InvalidArgumentException) {
                return null;
            }
        }

        return null;
    }
}

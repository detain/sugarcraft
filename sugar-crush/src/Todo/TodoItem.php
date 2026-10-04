<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Todo;

/**
 * One line of the session's todo list (roadmap 3.C): what to do, and where it
 * stands.
 *
 * ONE LINE, ALWAYS. The content is normalised on the way in — every run of
 * whitespace, newlines included, collapses to one space — because the list
 * travels as a rendered checklist ({@see TodoList::render()}), one item per
 * line, and is read back from that rendering ({@see TodoList::parse()}). A
 * newline inside an item would let one item forge the next.
 */
final readonly class TodoItem
{
    /** Longest content an item keeps, in characters. */
    public const MAX_CONTENT = 500;

    private function __construct(
        public string $content,
        public TodoStatus $status,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when $content is empty once normalised
     *                                   or longer than {@see MAX_CONTENT}
     */
    public static function new(string $content, TodoStatus $status = TodoStatus::Pending): self
    {
        $content = self::normalise($content);
        if ($content === '') {
            throw new \InvalidArgumentException('a todo item needs non-empty content');
        }
        if (mb_strlen($content) > self::MAX_CONTENT) {
            throw new \InvalidArgumentException(sprintf('a todo item is at most %d characters', self::MAX_CONTENT));
        }

        return new self($content, $status);
    }

    public function withStatus(TodoStatus $status): self
    {
        return $this->mutate(status: $status);
    }

    /** @return array{content: string, status: string} */
    public function toArray(): array
    {
        return ['content' => $this->content, 'status' => $this->status->value];
    }

    /**
     * The item an {@see toArray()} payload describes, or null for one out of
     * shape — a stored list from an older build is read leniently, row by row.
     *
     * @param mixed $data
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!\is_array($data) || !\is_string($data['content'] ?? null) || !\is_string($data['status'] ?? null)) {
            return null;
        }
        $status = TodoStatus::tryFrom($data['status']);
        if ($status === null) {
            return null;
        }

        try {
            return self::new($data['content'], $status);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public function equals(self $other): bool
    {
        return $this->content === $other->content && $this->status === $other->status;
    }

    private static function normalise(string $content): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $content);

        // Invalid UTF-8 defeats the /u pattern; fall back to the bytes with
        // their ASCII whitespace collapsed rather than losing the item.
        return trim($collapsed ?? (string) preg_replace('/\s+/', ' ', $content));
    }

    private function mutate(?string $content = null, ?TodoStatus $status = null): self
    {
        return new self($content ?? $this->content, $status ?? $this->status);
    }
}

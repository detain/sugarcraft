<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\Window;

use InvalidArgumentException;

/**
 * One node of the {@see WindowList} tree: a session entry with its own
 * attention state, an integer badge (unfinished work the shell counts for
 * the user — queued messages, unseen replies), and child agent entries
 * riding beneath it.
 *
 * Agent children are leaves: {@see self::agent()} refuses children on an
 * agent, and {@see self::withChildren()} refuses an agent as a child, so the
 * two-level shape the strip paints is a type fact here rather than a
 * rendering-time hope.
 */
final class WindowEntry
{
    /**
     * @param list<self> $children agent entries, in attach order; empty on an agent
     */
    private function __construct(
        public readonly WindowRef $ref,
        public readonly WindowAttention $attention = WindowAttention::Idle,
        public readonly int $badge = 0,
        public readonly array $children = [],
    ) {}

    /**
     * A session entry.
     *
     * @param list<self> $agents
     */
    public static function session(WindowRef $ref, WindowAttention $attention = WindowAttention::Idle, int $badge = 0, array $agents = []): self
    {
        if ($ref->kind !== WindowKind::Session) {
            throw new InvalidArgumentException('WindowEntry::session() needs a Session WindowRef.');
        }
        foreach ($agents as $agent) {
            if ($agent->ref->kind !== WindowKind::Agent) {
                throw new InvalidArgumentException('a session\'s children must be agent entries.');
            }
        }

        return new self($ref, $attention, max(0, $badge), array_values($agents));
    }

    /** An agent entry — always a leaf. */
    public static function agent(WindowRef $ref, WindowAttention $attention = WindowAttention::Idle, int $badge = 0): self
    {
        if ($ref->kind !== WindowKind::Agent) {
            throw new InvalidArgumentException('WindowEntry::agent() needs an Agent WindowRef.');
        }

        return new self($ref, $attention, max(0, $badge));
    }

    public function isAgent(): bool
    {
        return $this->ref->kind === WindowKind::Agent;
    }

    /** The same entry in $attention. */
    public function withAttention(WindowAttention $attention): self
    {
        return new self($this->ref, $attention, $this->badge, $this->children);
    }

    /** The same entry showing $badge. */
    public function withBadge(int $badge): self
    {
        return new self($this->ref, $this->attention, max(0, $badge), $this->children);
    }

    /** The same entry carrying exactly $agents. */
    public function withChildren(array $agents): self
    {
        return self::session($this->ref, $this->attention, $this->badge, $agents);
    }

    /** The same entry with $agent appended (an existing run id is replaced in place). */
    public function withChild(self $agent): self
    {
        $next = $this->children;
        foreach ($next as $index => $existing) {
            if ($existing->ref->key === $agent->ref->key) {
                $next[$index] = $agent;

                return self::session($this->ref, $this->attention, $this->badge, $next);
            }
        }
        $next[] = $agent;

        return self::session($this->ref, $this->attention, $this->badge, $next);
    }

    /** The same entry with the agent keyed $key taken off. */
    public function withoutChild(string $key): self
    {
        $next = [];
        foreach ($this->children as $agent) {
            if ($agent->ref->key !== $key) {
                $next[] = $agent;
            }
        }

        return self::session($this->ref, $this->attention, $this->badge, $next);
    }

    /** The child agent keyed $key, or null. */
    public function child(string $key): ?self
    {
        foreach ($this->children as $agent) {
            if ($agent->ref->key === $key) {
                return $agent;
            }
        }

        return null;
    }
}

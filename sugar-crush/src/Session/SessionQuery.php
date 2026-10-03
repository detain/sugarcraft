<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Session;

/**
 * The filters {@see EnhancedSessionStore::listSessionsFiltered()} applies,
 * as an immutable value: every `with*()` returns a new instance.
 *
 * The defaults are the default list — the user's own conversations
 * ({@see SessionKind::visibleByDefault()}), archived rows hidden, newest
 * first, 20 rows — so `SessionQuery::new()` asks for exactly what the tab
 * strip shows.
 *
 * `search` is a SQL `LIKE` PREFILTER over name, last prompt preview and id,
 * not a ranking: the picker ranks what comes back with candy-fuzzy, so the
 * store only has to keep obviously-unrelated rows out of the page.
 *
 * Mirrors the shape of opencode's `Session.list({ search, limit })`; there is
 * no charmbracelet/crush equivalent.
 */
final class SessionQuery
{
    /**
     * @param list<SessionKind> $kinds an empty list means every kind
     */
    private function __construct(
        public readonly array $kinds,
        public readonly bool $includeArchived,
        public readonly bool $archivedOnly,
        public readonly ?string $parentId,
        public readonly bool $pinnedFirst,
        public readonly int $limit,
        public readonly int $offset,
        public readonly ?string $search,
    ) {
    }

    public static function new(): self
    {
        return new self(SessionKind::visibleByDefault(), false, false, null, false, 20, 0, null);
    }

    /** Restrict to these kinds; no argument means every kind. */
    public function withKinds(SessionKind ...$kinds): self
    {
        return $this->mutate(['kinds' => array_values(array_unique($kinds, SORT_REGULAR))]);
    }

    /** Include archived rows alongside live ones. */
    public function withIncludeArchived(bool $includeArchived = true): self
    {
        return $this->mutate(['includeArchived' => $includeArchived]);
    }

    /** Only archived rows (the picker's "archived" view). */
    public function withArchivedOnly(bool $archivedOnly = true): self
    {
        return $this->mutate(['archivedOnly' => $archivedOnly]);
    }

    /** Only the direct children of $parentId; null lifts the filter. */
    public function withParentId(?string $parentId): self
    {
        return $this->mutate(['parentId' => $parentId]);
    }

    /** Pinned rows before unpinned ones, each group newest first. */
    public function withPinnedFirst(bool $pinnedFirst = true): self
    {
        return $this->mutate(['pinnedFirst' => $pinnedFirst]);
    }

    /** Page size, clamped to at least 1. */
    public function withLimit(int $limit): self
    {
        return $this->mutate(['limit' => max(1, $limit)]);
    }

    /** Rows to skip, clamped to at least 0. */
    public function withOffset(int $offset): self
    {
        return $this->mutate(['offset' => max(0, $offset)]);
    }

    /** A substring every row must contain in its name, preview or id; blank lifts the filter. */
    public function withSearch(?string $search): self
    {
        $search = $search === null ? null : trim($search);

        return $this->mutate(['search' => $search === '' ? null : $search]);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): self
    {
        $field = fn(string $name): mixed => array_key_exists($name, $changes) ? $changes[$name] : $this->{$name};

        return new self(
            $field('kinds'),
            $field('includeArchived'),
            $field('archivedOnly'),
            $field('parentId'),
            $field('pinnedFirst'),
            $field('limit'),
            $field('offset'),
            $field('search'),
        );
    }
}

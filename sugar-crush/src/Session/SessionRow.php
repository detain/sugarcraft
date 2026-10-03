<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Session;

/**
 * One `sessions` row, typed — what {@see EnhancedSessionStore::listSessionsFiltered()}
 * and {@see EnhancedSessionStore::childrenOf()} return instead of the
 * ad-hoc arrays {@see SessionStore::listSessions()} still hands the tab strip.
 *
 * Not to be confused with {@see \SugarCraft\Crush\Tui\SessionRow}, the
 * picker's `ItemList` item, which adapts already-sanitized display fields.
 * Every string here is raw storage: names and previews can be model output
 * or user typing and must be sanitized before they are painted.
 */
final class SessionRow
{
    public function __construct(
        public readonly string $id,
        public readonly string $provider,
        public readonly string $model,
        public readonly ?string $name,
        public readonly SessionKind $kind,
        public readonly ?string $parentId,
        public readonly ?string $parentCallId,
        public readonly ?string $agent,
        public readonly ?string $status,
        public readonly ?TitleSource $titleSource,
        public readonly bool $pinned,
        public readonly ?string $archivedAt,
        public readonly ?string $cwd,
        public readonly ?string $gitBranch,
        public readonly int $turns,
        public readonly ?string $lastPreview,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    /**
     * Build from a `SELECT * FROM sessions` row. Columns a pre-migration row
     * lacks read as their defaults, so a row array from any schema version
     * maps cleanly.
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $str = static fn(string $key): ?string => isset($row[$key]) && $row[$key] !== '' ? (string) $row[$key] : null;

        return new self(
            id: (string) ($row['id'] ?? ''),
            provider: (string) ($row['provider'] ?? ''),
            model: (string) ($row['model'] ?? ''),
            name: $str('name'),
            kind: SessionKind::fromStored($row['kind'] ?? null),
            parentId: $str('parent_id'),
            parentCallId: $str('parent_call_id'),
            agent: $str('agent'),
            status: $str('status'),
            titleSource: TitleSource::fromStored($row['title_source'] ?? null),
            pinned: (int) ($row['pinned'] ?? 0) !== 0,
            archivedAt: $str('archived_at'),
            cwd: $str('cwd'),
            gitBranch: $str('git_branch'),
            turns: (int) ($row['turns'] ?? 0),
            lastPreview: $str('last_preview'),
            createdAt: (string) ($row['created_at'] ?? ''),
            updatedAt: (string) ($row['updated_at'] ?? ''),
        );
    }

    public function archived(): bool
    {
        return $this->archivedAt !== null;
    }

    /**
     * The storage-shaped array (column names as keys), for the JSON outputs
     * and for callers still written against {@see SessionStore::listSessions()}'s
     * rows.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'provider' => $this->provider,
            'model' => $this->model,
            'name' => $this->name,
            'kind' => $this->kind->value,
            'parent_id' => $this->parentId,
            'parent_call_id' => $this->parentCallId,
            'agent' => $this->agent,
            'status' => $this->status,
            'title_source' => $this->titleSource?->value,
            'pinned' => $this->pinned,
            'archived_at' => $this->archivedAt,
            'cwd' => $this->cwd,
            'git_branch' => $this->gitBranch,
            'turns' => $this->turns,
            'last_preview' => $this->lastPreview,
        ];
    }
}

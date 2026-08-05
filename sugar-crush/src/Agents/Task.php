<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

/**
 * Represents a task assigned to a teammate within a team.
 *
 * Mirrors upstream task structure: each task carries a prompt, optional
 * dependencies, and tracks its lifecycle from pending through claimed to
 * completed or failed.
 */
final class Task
{
    public function __construct(
        public readonly string $id,
        public readonly string $teamId,
        public readonly string $title,
        public readonly string $description,
        public readonly string $prompt,
        public readonly ?string $assignedTo = null,
        public readonly TaskStatus $status = TaskStatus::Pending,
        public readonly ?string $result = null,
        public readonly ?string $error = null,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $claimedAt = null,
        public readonly ?\DateTimeImmutable $completedAt = null,
        /** @var string[] */
        public readonly array $dependsOn = [],
        public readonly bool $isContested = false,
    ) {}
}

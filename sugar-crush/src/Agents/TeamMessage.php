<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

/**
 * Represents a message sent from one teammate to another within a team.
 *
 * Message types: 'task_assigned', 'task_result', 'idle', 'error'.
 * The payload is an unstructured array allowing type-specific data to be
 * carried without the value object needing to know about each message variant.
 */
final class TeamMessage
{
    public function __construct(
        public readonly string $id,
        public readonly string $fromTeammateId,
        public readonly string $toTeammateId,
        public readonly string $type,
        public readonly array $payload,
        public readonly \DateTimeImmutable $sentAt,
        public readonly bool $read = false,
    ) {}
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

/**
 * Represents the memory scope for agent context persistence.
 *
 * Mirrors upstream memory scope classification used in context management.
 */
enum MemoryScope: string
{
    case User = 'user';
    case Project = 'project';
    case Local = 'local';
}

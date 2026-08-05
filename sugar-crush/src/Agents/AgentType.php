<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

/**
 * Represents the type/role of an agent within the team system.
 *
 * Mirrors the agent type categories defined in AgentDefinition,
 * providing a type-safe enum for teammate classification.
 */
enum AgentType: string
{
    case Coder = 'coder';
    case Reviewer = 'reviewer';
    case Debugger = 'debugger';
    case Architect = 'architect';
    case Tester = 'tester';
    case Devops = 'devops';
}

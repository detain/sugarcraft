<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

/**
 * Represents the computational effort/efficiency tier for agent operations.
 *
 * Mirrors upstream effort classification used in task routing.
 */
enum Effort: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case XHigh = 'xhigh';
    case Max = 'max';
}

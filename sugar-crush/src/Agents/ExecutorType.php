<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

/**
 * Specifies the execution strategy for agent workers in the pool.
 *
 * Mirrors upstream execution model options: process-based for true
 * parallelism, async for I/O-bound coordination, or hybrid for both.
 */
enum ExecutorType: string
{
    case Process = 'process';
    case Async = 'async';
    case Hybrid = 'hybrid';
}

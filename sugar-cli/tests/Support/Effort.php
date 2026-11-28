<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Tests\Support;

/** String-backed enum fixture for {@see \SugarCraft\Cli\Option::withEnum()}. */
enum Effort: string
{
    case Low = 'low';
    case High = 'high';
}

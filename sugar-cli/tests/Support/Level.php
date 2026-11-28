<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Tests\Support;

/** Int-backed enum fixture: choices compare against the backing value as a string. */
enum Level: int
{
    case One = 1;
    case Two = 2;
}

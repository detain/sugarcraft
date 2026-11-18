<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests\Support;

use SugarCraft\Plugins\Trust\QuarantineLedger;

/** An in-memory {@see QuarantineLedger} that records every mark it is given. */
final class MemoryLedger implements QuarantineLedger
{
    /** @var list<string> */
    public array $recorded = [];

    /** @var array<string, true> */
    private array $seen = [];

    /** Mark a path as already seen, as an earlier launch would have. */
    public function prime(string $path): void
    {
        $this->seen[$path] = true;
    }

    public function seen(string $path): bool
    {
        return isset($this->seen[$path]);
    }

    public function record(string $path): void
    {
        $this->recorded[] = $path;
        $this->seen[$path] = true;
    }
}

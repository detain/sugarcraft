<?php

declare(strict_types=1);

namespace SugarCraft\Top\Source\Fake;

use SugarCraft\Top\Collect\ProcessControl;

/**
 * `--fake` mode's {@see ProcessControl}: the demo pids are invented, so
 * nothing is ever signalled or reniced. Answers `$errno` for every call
 * (0 = success) so demos and tests can show the failure box too.
 */
final class FakeProcessControl implements ProcessControl
{
    private function __construct(
        public readonly int $errno,
    ) {
    }

    public static function new(int $errno = 0): self
    {
        return new self($errno);
    }

    public function signal(int $pid, int $signal): int
    {
        return $this->errno;
    }

    public function renice(int $pid, int $nice): int
    {
        return $this->errno;
    }
}

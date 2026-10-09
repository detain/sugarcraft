<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Process;

/**
 * What one {@see AsyncCommand} child produced: its outcome, exit code
 * (null unless it exited on its own) and everything it wrote to stdout.
 * stdout is kept on a non-zero exit too — ipmitool, for one, prints the
 * rows it could read before failing on the next.
 */
final class CommandResult
{
    public function __construct(
        public readonly CommandOutcome $outcome,
        public readonly string $stdout = '',
        public readonly ?int $exit = null,
        public readonly float $seconds = 0.0,
    ) {
    }

    public static function absent(): self
    {
        return new self(CommandOutcome::Absent);
    }

    public function ok(): bool
    {
        return $this->outcome === CommandOutcome::Ok;
    }
}

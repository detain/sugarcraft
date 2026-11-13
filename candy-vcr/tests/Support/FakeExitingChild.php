<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Support;

use SugarCraft\Pty\Contract\Child;

/** @internal Test double for {@see \SugarCraft\Vcr\Cli\WindowsRecordLoop} legs. */
final class FakeExitingChild implements Child
{
    /** @var list<int> */
    public array $signals = [];
    public bool $waited = false;
    private int $polls = 0;
    private bool $dead = false;

    public function __construct(
        private readonly int $exitAfterPolls,
        private readonly int $code,
        private readonly bool $exitOnKill = false,
        private readonly ?int $exitOnKillSignal = null,
    ) {
    }

    public function pid(): int
    {
        return 4242;
    }

    public function exited(): bool
    {
        if (!$this->dead && ++$this->polls >= $this->exitAfterPolls) {
            $this->dead = true;
        }

        return $this->dead;
    }

    public function wait(): int
    {
        $this->waited = true;
        $this->dead = true;

        return $this->code;
    }

    public function exitCode(): ?int
    {
        return $this->dead ? $this->code : null;
    }

    public function kill(int $signal): void
    {
        $this->signals[] = $signal;
        if ($this->exitOnKill || $this->exitOnKillSignal === $signal) {
            $this->dead = true;
        }
    }
}

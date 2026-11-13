<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Support;

use SugarCraft\Pty\Contract\MasterPty;

/** @internal Test double for {@see \SugarCraft\Vcr\Cli\WindowsRecordLoop} legs. */
final class FakeWindowsMaster implements MasterPty
{
    /** @var list<string> */
    public array $written = [];

    /** @var list<array{0:int,1:int}> */
    public array $resizes = [];

    /** @param list<?string> $reads */
    public function __construct(private array $reads)
    {
    }

    public function read(int $len = 8192, ?float $timeout = 0.05): ?string
    {
        return \array_shift($this->reads);
    }

    public function write(string $bytes): int
    {
        $this->written[] = $bytes;

        return \strlen($bytes);
    }

    public function resize(int $cols, int $rows): void
    {
        $this->resizes[] = [$cols, $rows];
    }

    public function size(): array
    {
        return [80, 24];
    }

    public function stream(): mixed
    {
        return null;
    }

    public function setBlocking(bool $blocking): void
    {
    }

    public function close(): void
    {
    }

    public function isClosed(): bool
    {
        return false;
    }

    public function fd(): int
    {
        return -1;
    }
}

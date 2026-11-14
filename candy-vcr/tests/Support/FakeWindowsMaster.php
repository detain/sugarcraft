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

    /** @var resource|null */
    private $stream = null;

    private bool $closed = false;

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

    /** @return array{rows: int, cols: int, xpix: int, ypix: int} */
    public function size(): array
    {
        return ['rows' => 24, 'cols' => 80, 'xpix' => 0, 'ypix' => 0];
    }

    /** @return resource */
    public function stream(): mixed
    {
        if (!is_resource($this->stream)) {
            $stream = fopen('php://memory', 'w+b');
            if ($stream === false) {
                throw new \RuntimeException('FakeWindowsMaster: php://memory open failed');
            }
            $this->stream = $stream;
        }

        return $this->stream;
    }

    public function setBlocking(bool $blocking): void
    {
    }

    public function close(): void
    {
        $this->closed = true;
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function fd(): int
    {
        return -1;
    }
}

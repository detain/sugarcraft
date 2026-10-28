<?php declare(strict_types=1);

trait WireA
{
    public const K = 42;
}

trait WireB
{
    public function kb(): int
    {
        return WireA::K;
    }
}

final class HostB
{
    use WireB;
}

echo (new HostB())->kb(), PHP_EOL;

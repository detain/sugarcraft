<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Support;

use SugarCraft\Ansi\Parser\Handler;

/**
 * Discarding handler for parser-churn tests: counts dispatches instead of
 * recording them, so a measured heap reflects the parser's own state rather
 * than a growing fixture log. Lives under the PSR-4 autoload-dev root
 * (`SugarCraft\Vt\Tests\` → `tests/`) as its own file rather than alongside
 * a test class, so `composer dump-autoload -o` resolves it.
 */
final class NullHandler implements Handler
{
    public int $dispatches = 0;

    public function printChar(string $rune): void
    {
        $this->dispatches++;
    }

    public function execute(int $byte): void
    {
        $this->dispatches++;
    }

    public function csiDispatch(int $final, array $params, int $prefix, int $intermediate): void
    {
        $this->dispatches++;
    }

    public function escDispatch(int $final, int $intermediate): void
    {
        $this->dispatches++;
    }

    public function oscDispatch(string $data): void
    {
        $this->dispatches++;
    }

    public function dcsDispatch(int $final, array $params, int $prefix, int $intermediate, string $data): void
    {
        $this->dispatches++;
    }

    public function sosPmApcDispatch(string $kind, string $data): void
    {
        $this->dispatches++;
    }
}

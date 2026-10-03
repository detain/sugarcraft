<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Support;

use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\SubscriptionCapable;

/**
 * Minimal Model for Program runtime tests: logs every Msg it is handed and
 * returns the configured init Cmd, nothing else.
 */
final class LoggingModel implements Model
{
    use SubscriptionCapable;

    /** @var list<Msg> */
    public array $log = [];

    public function __construct(public readonly ?\Closure $initCmd = null)
    {
    }

    public function init(): ?\Closure
    {
        return $this->initCmd;
    }

    public function update(Msg $msg): array
    {
        $next = clone $this;
        $next->log = [...$this->log, $msg];
        return [$next, null];
    }

    public function view(): string
    {
        return 'msgs: ' . count($this->log);
    }
}

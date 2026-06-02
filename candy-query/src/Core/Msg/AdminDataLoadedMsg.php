<?php

declare(strict_types=1);

namespace SugarCraft\Query\Core\Msg;

use SugarCraft\Core\Msg;

/**
 * Dispatched when async admin data fetch completes.
 * Carries status vars, server vars, and fetch timestamp.
 */
final readonly class AdminDataLoadedMsg implements Msg
{
    public function __construct(
        public array $statusVars,
        public array $serverVars,
        public float $fetchedAt,
    ) {}
}

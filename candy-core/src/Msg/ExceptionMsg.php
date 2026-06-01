<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

use SugarCraft\Core\Msg;

/**
 * Dispatched when an async command fails with an exception.
 */
readonly class ExceptionMsg implements Msg
{
    public function __construct(
        public \Throwable $exception,
    ) {
    }
}

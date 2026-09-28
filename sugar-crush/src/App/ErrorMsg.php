<?php

declare(strict_types=1);

namespace SugarCraft\Crush\App;

/**
 * Error message.
 */
final readonly class ErrorMsg implements Msg
{
    public function __construct(public string $message) {}
}

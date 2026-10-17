<?php

declare(strict_types=1);

namespace SugarCraft\Core\Exception;

use SugarCraft\Core\Lang;

/**
 * Base exception for SugarCraft libraries.
 *
 * All library-specific exceptions should extend this class or one of its
 * subclasses to ensure consistent exception handling across the ecosystem.
 */
class Exception extends \Exception
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}

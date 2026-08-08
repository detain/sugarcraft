<?php

declare(strict_types=1);

namespace SugarCraft\Crush\LSP;

/**
 * Thrown when a JSON-RPC error response is received for a request.
 */
final class LspResponseException extends \RuntimeException
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

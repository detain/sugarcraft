<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Discord;

/**
 * A Discord REST failure: the HTTP status (0 when the request never
 * completed) and the message the operator needs in the log.
 */
class RestException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}

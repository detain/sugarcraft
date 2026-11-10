<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\MsTeams;

/**
 * A refused token. The message names the CLAIM or the CRYPTO failure —
 * never the token, never its segments: webhook log lines must not carry
 * bearer material.
 */
final class JwtException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'invalid',
    ) {
        parent::__construct($message);
    }
}

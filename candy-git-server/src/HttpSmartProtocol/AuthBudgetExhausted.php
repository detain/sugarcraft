<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\HttpSmartProtocol;

/**
 * Control-flow signal that the {@see AuthAttemptBudget} refused an
 * authentication attempt before any bcrypt work was paid for. It is
 * caught by Server::handleRequest() and answered 429 + Retry-After; it
 * never denotes a server fault, so it must never reach the 500 path.
 */
final class AuthBudgetExhausted extends \RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct(
            'HTTP auth attempt budget exhausted; next attempt allowed in ' . $retryAfterSeconds . ' s'
        );
    }
}

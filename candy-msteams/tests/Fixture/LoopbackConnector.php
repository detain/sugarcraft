<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\Fixture;

use React\Socket\ConnectorInterface;

/**
 * Routes every dial, whatever host or scheme the client named, to the
 * loopback fake as plain TCP. Lets a test exercise a Bot Framework host
 * (the allowlisted https URL the bearer rides to) without any real network:
 * the request still carries its true URL and headers, only the socket is
 * redirected.
 */
final class LoopbackConnector implements ConnectorInterface
{
    public function __construct(
        private readonly ConnectorInterface $inner,
        private readonly int $port,
    ) {
    }

    public function connect($uri)
    {
        return $this->inner->connect('127.0.0.1:' . $this->port);
    }
}

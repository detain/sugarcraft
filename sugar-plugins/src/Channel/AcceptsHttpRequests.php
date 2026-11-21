<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Channel;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The webhook capability: a channel that takes inbound HTTP requests on its
 * own route.
 *
 * The channel owns its signature verification: a configured secret compared
 * with hash_equals() (or its HMAC variant), an over-cap body refused before
 * any comparison, and rate limiting on the channel's own account. The host
 * only routes a path segment to the named channel and never reads the body.
 *
 * A throw escaping handleRequest() reaches the host's outer error handler. The
 * client gets a generic failure response and the cause goes to the server log,
 * never to the client.
 */
interface AcceptsHttpRequests extends ChannelInterface
{
    public function handleRequest(ServerRequestInterface $request): ResponseInterface;
}

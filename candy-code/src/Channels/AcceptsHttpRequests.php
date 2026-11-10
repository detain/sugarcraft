<?php

declare(strict_types=1);

namespace SugarCraft\Code\Channels;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The webhook capability: an inbound HTTP route under `/channels/<name>`
 * (/channels/, deliberately NOT /api/ — these are not protocol RPC methods).
 *
 * The channel owns its signature verification, following the
 * {@see \SugarCraft\Code\Server\Http\HookWebhooks} doctrine as precedent:
 * a configured secret compared with hash_equals() (or its HMAC variant),
 * an over-cap body refused with 413 BEFORE any compare, and rate limiting
 * on the channel's own dime. The registry only routes path segments to the
 * named channel and never interprets a body itself — that knowledge is the
 * plugin's, exactly as a built-in webhook's is the hook's.
 *
 * A throw escaping handleRequest() reaches the router's outer catch: the
 * wire gets the generic JSON 500 envelope, the cause reaches the server
 * log (ErrorLog's ISS-057 law), never the client.
 */
interface AcceptsHttpRequests extends ChannelInterface
{
    public function handleRequest(ServerRequestInterface $request): ResponseInterface;
}

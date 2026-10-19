<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use SugarCraft\Code\Share\ShareStore;

/**
 * GET `/s/{token}` — one published session snapshot, served PUBLIC (SES-3,
 * COMPARISON §6.5).
 *
 * PUBLIC ON PURPOSE: the token is the capability, and `AuthMiddleware`
 * protects only `/ws` and `/api/*`, so the Router reaches this handler with
 * no cookie and no ticket. That is safe exactly as long as the token is the
 * only secret — {@see ShareStore} renders the page from a SNAPSHOT captured
 * at an explicit user act, never from the live transcript, and enforces
 * expiry and revocation per request. Unknown, expired and revoked answer the
 * identical byte-for-byte 404, so the status line cannot enumerate tokens.
 *
 * `Cache-Control: no-store` despite an immutable snapshot: revocation must
 * land on the NEXT request, and a cache (proxy or browser) holding the page
 * past a revoke would serve a transcript the user has just unpublished. The
 * body is small, local HTML; freshness of the revocation check wins.
 */
final class SharePageController
{
    public function __construct(private readonly ShareStore $shares)
    {
    }

    /**
     * Answer the request, or null for anything that is not a share-page
     * GET/HEAD — the Router treats null as "fall through to the static UI".
     */
    public function __invoke(ServerRequestInterface $request): ?ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (!str_starts_with($path, '/' . ShareStore::URL_PREFIX . '/')) {
            return null;
        }
        $method = $request->getMethod();
        if ($method !== 'GET' && $method !== 'HEAD') {
            return Responses::error(405, 'method_not_allowed', 'share pages are served by GET only', ['Allow' => 'GET, HEAD']);
        }

        $token = substr($path, strlen('/' . ShareStore::URL_PREFIX . '/'));
        if ($token === '' || str_contains($token, '/')) {
            return $this->notFound();
        }

        $record = $this->shares->lookup($token);
        if ($record === null) {
            return $this->notFound();
        }

        return (new Response(200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'], $record->html));
    }

    private function notFound(): ResponseInterface
    {
        // One sentence for all three deaths — unknown, expired, revoked. The
        // grouping is policy, never per-token knowledge.
        return Responses::error(404, 'not_found', 'this share page does not exist, or it has expired or been revoked');
    }
}

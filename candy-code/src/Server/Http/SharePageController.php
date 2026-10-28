<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Http;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use SugarCraft\Code\Share\ShareStore;

/**
 * GET `/s/{token}` — one published session page, served PUBLIC (SES-3,
 * COMPARISON §6.5).
 *
 * PUBLIC ON PURPOSE: the token is the capability, and `AuthMiddleware`
 * protects only `/ws` and `/api/*`, so the Router reaches this handler with
 * no cookie and no ticket. That is safe exactly as long as the token is the
 * only secret — {@see ShareStore} enforces expiry and revocation per
 * request, and unknown, expired and revoked answer the identical
 * byte-for-byte 404, so the status line cannot enumerate tokens.
 *
 * A SNAPSHOT row is rendered from the text captured at its publish act
 * (snapshot-at-explicit-user-act is the security model): later edits to the
 * session cannot reach a page that is already public.
 *
 * A LIVE row (C-SES-3.d) is re-rendered from the CURRENT session transcript
 * on every request through the optional $liveRenderer —
 * `Closure(string $sessionId): ?string`, given the row's session id,
 * answering the page's HTML or null when the session is gone (which answers
 * the same shared 404, never a different one). The renderer is wired only by
 * the serve process ({@see \SugarCraft\Code\Cli\Serve}); a live row on a
 * controller without one falls back to its stored publish-time snapshot, the
 * law the row was published under. The renderer re-serializes through the
 * same ShareSession path a snapshot took, so the row filter and the secret
 * redaction are identical — the difference is WHEN it runs, not HOW. The
 * filter decides WHICH rows share; it is not a size bound, and no row-count
 * cap exists anywhere: every request re-serializes the full filtered
 * transcript (the design's "existing ShareSession row cap" risk-line named a
 * cap that was never there — minting one is an owner ruling, not a lane add).
 *
 * `Cache-Control: no-store` despite an immutable snapshot: revocation must
 * land on the NEXT request, and a cache (proxy or browser) holding the page
 * past a revoke would serve a transcript the user has just unpublished. The
 * body is small, local HTML; freshness of the revocation check wins. For
 * live rows the header turns load-bearing: a cached copy would freeze a
 * page that promises to track its session.
 */
final class SharePageController
{
    public function __construct(
        private readonly ShareStore $shares,
        private readonly ?Closure $liveRenderer = null,
    ) {
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

        $html = $record->html;
        if ($record->live && $this->liveRenderer !== null) {
            $rendered = ($this->liveRenderer)($record->sessionId);
            if (!is_string($rendered)) {
                // The session behind a live page is gone: the same death the
                // token itself would answer, indistinguishable from it.
                return $this->notFound();
            }
            $html = $rendered;
        }

        return (new Response(200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'], $html));
    }

    private function notFound(): ResponseInterface
    {
        // One sentence for all three deaths — unknown, expired, revoked. The
        // grouping is policy, never per-token knowledge.
        return Responses::error(404, 'not_found', 'this share page does not exist, or it has expired or been revoked');
    }
}

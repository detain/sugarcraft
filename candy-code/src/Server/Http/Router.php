<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SugarCraft\Code\Server\ErrorLog;
use SugarCraft\Code\Share\ShareStore;
use SugarCraft\Code\Support\Log;

/**
 * The last handler in the chain: `/ws` to the upgrade, `/api/*` to the API,
 * `/s/{token}` to the share pages when this server was told to have them,
 * `/canvas/{token}` and `POST /api/canvas` to the sandboxed canvas pages
 * when this server was told to have them, everything else to the static
 * UI — with {@see Responses::SECURITY_HEADERS} on every answer except the
 * 101, whose headers belong to RFC 6455, and except the canvas page, whose
 * {@see Responses::CANVAS_HEADERS} survive the merge because an explicit
 * header always wins.
 *
 * A handler that throws is translated here, at the router's own boundary,
 * into the JSON `internal_error` envelope: react/http's fallback responder
 * would otherwise answer with a text/plain page no JSON client can parse,
 * and `/api` would silently break its own always-JSON promise (CWPD-4).
 * The cause rides {@see ErrorLog}'s line into the server log — never the
 * wire, per that file's own ISS-057 law.
 */
final class Router
{
    /**
     * @param \Closure(ServerRequestInterface): ResponseInterface $upgrade
     * @param \Closure(ServerRequestInterface): ResponseInterface $api
     * @param \Closure(ServerRequestInterface): ResponseInterface $static
     * @param (\Closure(ServerRequestInterface): ?ResponseInterface)|null $share
     *        the {@see SharePageController}; null means this server publishes
     *        no share pages, and `/s/...` falls through to the UI untouched.
     * @param (\Closure(ServerRequestInterface): ?ResponseInterface)|null $canvas
     *        the {@see CanvasPageController}; null means this server publishes
     *        no canvas pages, and `/canvas/...` falls through to the UI while
     *        `POST /api/canvas` reaches the API's own unknown-method refusal.
     */
    public function __construct(
        private readonly \Closure $upgrade,
        private readonly \Closure $api,
        private readonly \Closure $static,
        private readonly ?\Closure $share = null,
        private readonly ?\Closure $canvas = null,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return $this->dispatch($request);
        } catch (\Throwable $e) {
            // CWPD-4: react/http's own IoResponder answers an escaping throw
            // with a text/plain "Internal server error" page — wrong for a
            // JSON API in every shape, since the web client parses and a
            // non-JSON body on a 500 is an unparseable failure. The cause
            // rides the blessed error_log funnel; the wire gets the JSON
            // envelope only (ErrorLog's ISS-057 law at the router's edge).
            Log::channel(Log::APP, ErrorLog::line($e, 'router'));

            return Responses::secured(Responses::error(500, 'internal_error', 'the request failed inside the server; the cause is in the server log'));
        }
    }

    private function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if ($path === '/ws') {
            $response = HostAndOriginGuard::isUpgrade($request)
                ? ($this->upgrade)($request)
                : Responses::error(426, 'upgrade_required', 'this endpoint speaks WebSocket only', ['Upgrade' => 'websocket']);

            return $response->getStatusCode() === 101 ? $response : Responses::secured($response);
        }

        // Ahead of BOTH the /api fall-through (the mint lives under /api/ but
        // is not an RPC method — ApiController would answer it as an unknown
        // method) and the static fall-through (the UI is a SPA and would
        // answer /canvas/... with index.html, masking the page and its
        // single-use token check behind a client-side 404). Same shape and
        // same reason as the share branch below; the prefixes are disjoint.
        if ($this->canvas !== null
            && (\str_starts_with($path, '/canvas/') || $path === '/api/canvas')) {
            $response = ($this->canvas)($request);
            if ($response !== null) {
                return Responses::secured($response);
            }
        }

        // Ahead of the static fall-through (SES-3): the UI is a SPA and would
        // happily answer /s/... with index.html, masking the share page (and
        // its per-request expiry check) behind a client-side 404.
        if ($this->share !== null && str_starts_with($path, '/' . ShareStore::URL_PREFIX . '/')) {
            $response = ($this->share)($request);
            if ($response !== null) {
                return Responses::secured($response);
            }
        }

        return Responses::secured(\str_starts_with($path, '/api/') ? ($this->api)($request) : ($this->static)($request));
    }
}

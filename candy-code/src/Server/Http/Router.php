<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SugarCraft\Code\Share\ShareStore;

/**
 * The last handler in the chain: `/ws` to the upgrade, `/api/*` to the API,
 * `/s/{token}` to the share pages when this server was told to have them,
 * `/canvas/{token}` and `POST /api/canvas` to the sandboxed canvas pages
 * when this server was told to have them, everything else to the static
 * UI — with {@see Responses::SECURITY_HEADERS} on every answer except the
 * 101, whose headers belong to RFC 6455, and except the canvas page, whose
 * {@see Responses::CANVAS_HEADERS} survive the merge because an explicit
 * header always wins.
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

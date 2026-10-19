<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SugarCraft\Code\Share\ShareStore;

/**
 * The last handler in the chain: `/ws` to the upgrade, `/api/*` to the API,
 * `/s/{token}` to the share pages when this server was told to have them,
 * everything else to the static UI — with {@see Responses::SECURITY_HEADERS}
 * on every answer except the 101, whose headers belong to RFC 6455.
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
     */
    public function __construct(
        private readonly \Closure $upgrade,
        private readonly \Closure $api,
        private readonly \Closure $static,
        private readonly ?\Closure $share = null,
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

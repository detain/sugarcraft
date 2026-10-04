<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The last handler in the chain: `/ws` to the upgrade, `/api/*` to the API,
 * everything else to the static UI — with {@see Responses::SECURITY_HEADERS}
 * on every answer except the 101, whose headers belong to RFC 6455.
 */
final class Router
{
    /**
     * @param \Closure(ServerRequestInterface): ResponseInterface $upgrade
     * @param \Closure(ServerRequestInterface): ResponseInterface $api
     * @param \Closure(ServerRequestInterface): ResponseInterface $static
     */
    public function __construct(
        private readonly \Closure $upgrade,
        private readonly \Closure $api,
        private readonly \Closure $static,
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

        return Responses::secured(\str_starts_with($path, '/api/') ? ($this->api)($request) : ($this->static)($request));
    }
}

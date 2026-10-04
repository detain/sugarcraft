<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol;

use SugarCraft\Crush\Host\SessionHost;

/**
 * What one method call runs with: who asked ({@see Client}), what they asked
 * ({@see Request}), and the server they asked ({@see ServerContext}).
 */
final class CallContext
{
    public function __construct(
        public readonly Client $client,
        public readonly Request $request,
        public readonly ServerContext $server,
        public readonly MethodRegistry $methods,
    ) {
    }

    /**
     * The open host of $sessionId (opened on demand), with its feed attached.
     *
     * @throws RpcError not_found / session_locked
     */
    public function host(string $sessionId): SessionHost
    {
        $host = $this->server->host($sessionId);
        $this->server->feedFor($host);

        return $host;
    }

    public function feed(SessionHost $host): SessionFeed
    {
        return $this->server->feedFor($host);
    }

    /** The calling client must hold $scope. */
    public function requireScope(Scope $scope): void
    {
        if (!$this->client->hasScope($scope)) {
            throw RpcError::of(ErrorCode::Forbidden, \sprintf('this needs the %s scope', $scope->value));
        }
    }
}

<?php

declare(strict_types=1);

namespace ChannelBridge\Teams;

use ChannelBridge\Log;
use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;
use React\Promise\PromiseInterface;

/**
 * The bot's own bearer token for the Bot Connector REST (§8.2): OAuth2
 * client-credentials against the tenant authority, cached until the expiry
 * margin. Microsoft's v2 endpoint returns `expires_in` seconds; we refresh
 * a minute early so a reply never rides a token that dies in flight.
 */
final class BotToken
{
    private ?string $token = null;
    private int $expiresAt = 0;

    public function __construct(
        private readonly string $appId,
        private readonly string $appPassword,
        private readonly string $tenantId,
        private readonly Log $log,
        private readonly ?string $authorityBase = null,
    ) {
    }

    /** @return PromiseInterface<string> */
    public function token(): PromiseInterface
    {
        if ($this->token !== null && $this->expiresAt - 60 > \time()) {
            return \React\Promise\resolve($this->token);
        }

        $base = $this->authorityBase ?? 'https://login.microsoftonline.com';
        $url = "{$base}/{$this->tenantId}/oauth2/v2.0/token";
        $body = \http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $this->appId,
            'client_secret' => $this->appPassword,
            'scope' => 'https://api.botframework.com/.default',
        ], '', '&');

        return (new Browser())->withBase(null)->post($url, [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], $body)->then(
            function (ResponseInterface $response): string {
                $status = $response->getStatusCode();
                $raw = (string) $response->getBody();
                if ($status >= 400) {
                    // The message carries the OAuth error name, never the secret.
                    throw new \RuntimeException('teams token endpoint answered ' . $status);
                }
                $decoded = \json_decode($raw, true);
                $token = \is_array($decoded) ? (string) ($decoded['access_token'] ?? '') : '';
                if ($token === '') {
                    throw new \RuntimeException('teams token endpoint returned no access_token');
                }
                $this->token = $token;
                $expiresIn = (int) (\is_array($decoded) ? $decoded['expires_in'] ?? 3600 : 3600);
                $this->expiresAt = \time() + $expiresIn;
                $this->log->info('teams_token_minted', ['expiresIn' => $expiresIn]);

                return $token;
            },
            function (\Throwable $e): never {
                $this->log->warn('teams_token_failed', ['error' => $e->getMessage()]);
                throw new \RuntimeException('teams token fetch failed: ' . $e->getMessage(), 0, $e);
            },
        );
    }
}

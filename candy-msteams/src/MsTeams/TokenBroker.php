<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\MsTeams;

use CandyCode\Bundles\MsTeams\Support\Endpoint;
use CandyCode\Bundles\MsTeams\Support\Log;
use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;

/**
 * The bot token: an AAD v2 client_credentials exchange against
 * `{authBase}/{tenantId}/oauth2/v2.0/token` for scope
 * `api.botframework.com/.default`, cached until 60 s before its expiry and
 * de-duplicated while in flight (a webhook burst must not mint a token per
 * activity).
 *
 * The app password rides the POST body to the configured auth base only —
 * Endpoint::authBase() already refused any plain-HTTP non-loopback override
 * before this class was ever constructed. Failure prose names the exchange,
 * never the secret or the response body (a token endpoint error can echo
 * request fragments).
 */
final class TokenBroker
{
    public const SCOPE = 'api.botframework.com/.default';

    private ?string $token = null;

    private float $expiresAt = 0.0;

    private ?\React\Promise\PromiseInterface $pending = null;

    private Browser $http;

    /**
     * @param array{appId:string, appPassword:string, tenantId:string} $credentials
     */
    public function __construct(
        private readonly array $credentials,
        private readonly Log $log,
        private readonly string $authBase = Endpoint::DEFAULT_AUTH,
    ) {
        $this->http = (new Browser())
            ->withBase(null)
            ->withTimeout(20.0)
            ->withFollowRedirects(false)
            // See the note in Rest: 4xx/5xx must reach OUR status arms —
            // a refused exchange names its status, not a wrapper exception.
            ->withRejectErrorResponse(false);
    }

    /** @return \React\Promise\PromiseInterface<string> a valid bot access token */
    public function token(): \React\Promise\PromiseInterface
    {
        if ($this->token !== null && \microtime(true) < $this->expiresAt) {
            return \React\Promise\resolve($this->token);
        }
        if ($this->pending !== null) {
            return $this->pending;
        }

        $url = \rtrim($this->authBase, '/') . '/' . $this->credentials['tenantId'] . '/oauth2/v2.0/token';
        $body = \http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $this->credentials['appId'],
            'client_secret' => $this->credentials['appPassword'],
            'scope' => self::SCOPE,
        ]);

        /** @var \React\Promise\PromiseInterface<string> $pending */
        $pending = $this->http->post($url, [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'User-Agent' => 'candy-code-msteams-bundle (https://github.com/detain/sugarcraft, 0.1)',
        ], $body)->then(
            function (ResponseInterface $response) use ($url): string {
                $status = $response->getStatusCode();
                if ($status >= 400) {
                    $this->log->warn('msteams_token_error', ['status' => $status]);
                    throw new RestException("msteams token exchange refused (HTTP {$status}) by " . \parse_url($url, \PHP_URL_HOST), $status);
                }
                $decoded = \json_decode((string) $response->getBody(), true);
                $token = \is_array($decoded) ? (string) ($decoded['access_token'] ?? '') : '';
                if ($token === '') {
                    throw new RestException('msteams token exchange returned no access_token', 0);
                }
                $expiresIn = \is_array($decoded) ? (int) ($decoded['expires_in'] ?? 3600) : 3600;

                $this->token = $token;
                $this->expiresAt = \microtime(true) + \max(30, $expiresIn - 60);
                $this->log->debug('msteams_token_minted', ['expiresIn' => $expiresIn]);

                return $token;
            },
            function (\Throwable $e): never {
                $this->log->warn('msteams_token_failed', ['error' => $e->getMessage()]);
                throw new RestException('msteams token exchange failed: ' . $e->getMessage(), 0, '', $e);
            },
        )->finally(function (): void {
            $this->pending = null;
        });

        $this->pending = $pending;

        return $pending;
    }

    /** Force the next token() call to re-exchange (a 401 from serviceUrl means staleness). */
    public function invalidate(): void
    {
        $this->token = null;
        $this->expiresAt = 0.0;
    }

    /** Whether a token is currently held and unexpired (test/telemetry seam). */
    public function hasFreshToken(): bool
    {
        return $this->token !== null && \microtime(true) < $this->expiresAt;
    }
}

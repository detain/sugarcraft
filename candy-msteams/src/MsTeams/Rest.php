<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\MsTeams;

use CandyCode\Bundles\MsTeams\Support\Endpoint;
use CandyCode\Bundles\MsTeams\Support\Log;
use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;

/**
 * Bot Framework REST over the activity's serviceUrl (§8.6): every outbound
 * is an activity post to {serviceUrl}/v3/conversations/{conversationId}/
 * activities, authenticated with the broker-minted bot token. The reply
 * window expires — ConversationRouter never calls back into this class
 * expecting old activity ids to still answer; the SessionMap
 * last_conversation row is the durable re-key (§9.1).
 *
 * serviceUrl is ACTIVITY data, so it passes one floor before any dial:
 * https-only unless it is a loopback host AND the operator opted into
 * channels.msteams.transport.insecureLoopbackProxy (the test-fake and
 * local-proxy case). A refusal never sends a byte — no token leaves for a
 * stranger's host.
 *
 * Rate limits: Bot Framework 429s carry Retry-After; we obey ONCE only by
 * surfacing the failure (the same posture as the discord bundle — a bridge
 * that silently retries forever turns a flood guard into a queue). Nothing
 * here loops or retries.
 */
final class Rest
{
    private Browser $http;

    public function __construct(
        private readonly TokenBroker $tokens,
        private readonly Log $log,
        private readonly bool $insecureLoopbackProxy = false,
    ) {
        $this->http = (new Browser())
            ->withBase(null)
            ->withTimeout(20.0)
            ->withFollowRedirects(false)
            // react/http defaults to REJECTING 4xx/5xx before a handler ever
            // sees the Response — that would kill the 401-staleness signal
            // and turn Retry-After headers into opaque exceptions. Status is
            // OUR decision to make, so the wire stays honest down here.
            ->withRejectErrorResponse(false);
    }

    /**
     * POST an activity into a conversation. Returns the created-activity
     * body (its `id` is what retireApproval later replaces).
     *
     * @param array<string,mixed> $activity
     *
     * @return \React\Promise\PromiseInterface<array<string,mixed>>
     */
    public function sendActivity(string $serviceUrl, string $conversationId, array $activity): \React\Promise\PromiseInterface
    {
        $path = '/v3/conversations/' . \rawurlencode($conversationId) . '/activities';
        try {
            $target = $this->authorized($serviceUrl, $path);
        } catch (RestException $e) {
            return \React\Promise\reject($e);
        }

        return $this->request('POST', $target, $activity);
    }

    /**
     * REPLACE a previously sent activity (adaptive-card retirement: the
     * same PUT the Bot Framework spec answers with the updated record).
     *
     * @param array<string,mixed> $activity
     *
     * @return \React\Promise\PromiseInterface<array<string,mixed>>
     */
    public function updateActivity(string $serviceUrl, string $conversationId, string $activityId, array $activity): \React\Promise\PromiseInterface
    {
        $path = '/v3/conversations/' . \rawurlencode($conversationId) . '/activities/' . \rawurlencode($activityId);
        try {
            $target = $this->authorized($serviceUrl, $path);
        } catch (RestException $e) {
            return \React\Promise\reject($e);
        }

        return $this->request('PUT', $target, $activity);
    }

    /**
     * Raw attachment bytes (§8.7 v1 stance): https + the Bot Framework
     * media hosts ONLY. A Graph/SharePoint content url is NOT fetchable
     * without delegated consent — that is P3.5's file-consent leg — and
     * dialing it here would hand our bot token to a stranger, so the
     * allowlist refuses before any request exists. The signed query of a
     * legit url is bearer material: error prose carries the PATH only.
     *
     * @return \React\Promise\PromiseInterface<string>
     */
    public function fetchBinary(string $url): \React\Promise\PromiseInterface
    {
        $scheme = \strtolower((string) (\parse_url($url, \PHP_URL_SCHEME) ?: ''));
        $host = \strtolower((string) (\parse_url($url, \PHP_URL_HOST) ?: ''));
        $platformMedia = ($scheme === 'https' && self::hostIsBotFrameworkMedia($host));
        $testLoopback = ($this->insecureLoopbackProxy && Endpoint::isLoopback($host) && ($scheme === 'https' || $scheme === 'http'));
        if (!$platformMedia && !$testLoopback) {
            return \React\Promise\reject(new RestException(
                'msteams media url refused (https + Bot Framework media host required; Graph-hosted content is deferred to P3.5): ' . $host));
        }
        $safeLabel = (string) (\parse_url($url, \PHP_URL_PATH) ?: '/');

        return $this->http->get($url)->then(
            function (ResponseInterface $response) use ($safeLabel): string {
                $status = $response->getStatusCode();
                if ($status >= 400) {
                    throw new RestException("msteams media fetch {$status} for {$safeLabel}", $status);
                }

                return (string) $response->getBody();
            },
            function (\Throwable $e) use ($safeLabel): never {
                throw new RestException("msteams media fetch failed for {$safeLabel}: {$e->getMessage()}", 0, '', $e);
            },
        );
    }

    private static function hostIsBotFrameworkMedia(string $host): bool
    {
        foreach (['botframework.com', 'smba.trafficmanager.net'] as $domain) {
            if ($host === $domain || \str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{url: string, path: string} */
    private function authorized(string $serviceUrl, string $path): array
    {
        $scheme = \strtolower((string) (\parse_url($serviceUrl, \PHP_URL_SCHEME) ?: ''));
        $host = \strtolower((string) (\parse_url($serviceUrl, \PHP_URL_HOST) ?: ''));
        if ($host === '' || ($scheme !== 'https' && !($scheme === 'http' && $this->insecureLoopbackProxy && Endpoint::isLoopback($host)))) {
            throw new RestException(
                'msteams serviceUrl refused (https required'
                . ($scheme === 'http' ? '; plain http only with channels.msteams.transport.insecureLoopbackProxy on a loopback host' : '')
                . '): ' . ($host === '' ? $serviceUrl : $host),
                0,
                $path,
            );
        }

        return ['url' => \rtrim($serviceUrl, '/') . $path, 'path' => $path];
    }

    /**
     * @param array<string,mixed> $body
     *
     * @return \React\Promise\PromiseInterface<array<string,mixed>>
     */
    private function request(string $method, array $target, array $body = []): \React\Promise\PromiseInterface
    {
        return $this->tokens->token()->then(function (string $token) use ($method, $target, $body): \React\Promise\PromiseInterface {
            $headers = [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'User-Agent' => 'candy-code-msteams-bundle (https://github.com/detain/sugarcraft, 0.1)',
            ];
            $payload = $body === [] ? '' : (string) \json_encode($body, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR);

            return $this->http->request($method, $target['url'], $headers, $payload)->then(
                function (ResponseInterface $response) use ($method, $target): array {
                    $status = $response->getStatusCode();
                    $raw = (string) $response->getBody();
                    if ($status === 401) {
                        // The token went stale mid-flight (tenant rotation):
                        // drop the cache so the NEXT call re-mints, and
                        // surface THIS one — no silent retry loop.
                        $this->tokens->invalidate();
                    }
                    if ($status === 429) {
                        $retryAfter = (float) ($response->getHeaderLine('Retry-After') ?: '1');
                        $this->log->warn('msteams_429', ['path' => $target['path'], 'retryAfter' => $retryAfter]);
                        throw new RestException("msteams 429 rate limited on {$method} {$target['path']}", 429, $target['path']);
                    }
                    if ($status >= 400) {
                        $this->log->warn('msteams_rest_error', ['path' => $target['path'], 'status' => $status, 'body' => \substr($raw, 0, 200)]);
                        throw new RestException("msteams {$status} on {$method} {$target['path']}", $status, $target['path']);
                    }
                    if ($raw === '' || $status === 204) {
                        return [];
                    }
                    $decoded = \json_decode($raw, true);

                    return \is_array($decoded) ? $decoded : [];
                },
                function (\Throwable $e) use ($method, $target): never {
                    if ($e instanceof RestException) {
                        throw $e;
                    }
                    $this->log->warn('msteams_rest_failed', ['path' => $target['path'], 'error' => $e->getMessage()]);
                    throw new RestException("msteams {$method} {$target['path']} failed: {$e->getMessage()}", 0, $target['path'], $e);
                },
            );
        });
    }
}

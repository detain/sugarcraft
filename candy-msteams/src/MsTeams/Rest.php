<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\MsTeams;

use CandyCode\Bundles\MsTeams\Support\Endpoint;
use CandyCode\Bundles\MsTeams\Support\Log;
use CandyCode\Bundles\MsTeams\Support\TextSafety;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Http\Browser;
use React\Promise\Promise;
use React\Promise\PromiseInterface;

/**
 * Bot Framework REST over the activity's serviceUrl (§8.6): every outbound
 * is an activity post to {serviceUrl}/v3/conversations/{conversationId}/
 * activities, authenticated with the broker-minted bot token. The reply
 * window expires — ConversationRouter never calls back into this class
 * expecting old activity ids to still answer; the SessionMap
 * last_conversation row is the durable re-key (§9.1).
 *
 * serviceUrl is ACTIVITY data, so it passes two floors before any dial:
 * https-only unless it is a loopback host AND the operator opted into
 * channels.msteams.transport.insecureLoopbackProxy (the test-fake and
 * local-proxy case), and — for https — a Bot Framework service host. The
 * bearer rides only to those hosts: a refusal never sends a byte, so no
 * token leaves for a stranger's host.
 *
 * Rate limits: a 429 is retried at most twice, honouring Retry-After (capped
 * at 30 s) — a Teams flood guard is a pause, not a verdict. After the last
 * retry the failure surfaces once, logged with the body redacted.
 */
final class Rest
{
    /** Bot Framework service hosts (suffix match): the only https hosts that receive the bearer for activities. */
    private const SERVICE_DOMAINS = ['botframework.com', 'smba.trafficmanager.net', 'msg.teams.microsoft.com'];

    /** Bot Framework media hosts: attachment bytes are fetched with the bearer from these only. */
    private const MEDIA_DOMAINS = ['botframework.com', 'smba.trafficmanager.net'];

    public const MAX_POST_RETRIES = 2;

    public const RETRY_AFTER_CAP_SECONDS = 30.0;

    private const ERROR_BODY_LOG_BYTES = 200;

    private Browser $http;

    private LoopInterface $loop;

    private TextSafety $safety;

    /** Activity posts still awaiting their final settle (drain + dispose law). */
    private int $inFlight = 0;

    private int $flightSeq = 0;

    /** @var array<int, PromiseInterface<array<string,mixed>>> */
    private array $flights = [];

    /** @var list<TimerInterface> */
    private array $retryTimers = [];

    public function __construct(
        private readonly TokenBroker $tokens,
        private readonly Log $log,
        private readonly bool $insecureLoopbackProxy = false,
        ?LoopInterface $loop = null,
        ?TextSafety $safety = null,
        ?Browser $http = null,
    ) {
        $this->loop = $loop ?? Loop::get();
        $this->safety = $safety ?? new TextSafety();
        $this->http = $http ?? (new Browser())
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
     * @return PromiseInterface<array<string,mixed>>
     */
    public function sendActivity(string $serviceUrl, string $conversationId, array $activity): PromiseInterface
    {
        $path = '/v3/conversations/' . \rawurlencode($conversationId) . '/activities';
        try {
            $target = $this->authorized($serviceUrl, $path);
        } catch (RestException $e) {
            return \React\Promise\reject($e);
        }

        $id = ++$this->flightSeq;
        $this->inFlight++;
        $flight = $this->request('POST', $target, $activity, self::MAX_POST_RETRIES)->finally(function () use ($id): void {
            $this->inFlight--;
            unset($this->flights[$id]);
        });
        $this->flights[$id] = $flight;

        return $flight;
    }

    /**
     * Wait (bounded) for in-flight activity posts to settle; whatever is
     * still open at the deadline is cancelled. The reactor runs only while
     * waiting — callers are shutdown paths, not request handlers.
     */
    public function drain(float $seconds): void
    {
        if ($this->inFlight === 0) {
            return;
        }
        $deadline = \microtime(true) + $seconds;
        $check = $this->loop->addPeriodicTimer(0.05, function () use ($deadline): void {
            if ($this->inFlight === 0 || \microtime(true) >= $deadline) {
                $this->loop->stop();
            }
        });
        $this->loop->run();
        $this->loop->cancelTimer($check);

        if ($this->inFlight === 0) {
            return;
        }
        $this->log->warn('msteams_drain_cancelled', ['open' => $this->inFlight]);
        foreach ($this->retryTimers as $timer) {
            $this->loop->cancelTimer($timer);
        }
        $this->retryTimers = [];
        foreach ($this->flights as $flight) {
            $flight->cancel();
        }
    }

    /**
     * REPLACE a previously sent activity (adaptive-card retirement: the
     * same PUT the Bot Framework spec answers with the updated record).
     *
     * @param array<string,mixed> $activity
     *
     * @return PromiseInterface<array<string,mixed>>
     */
    public function updateActivity(string $serviceUrl, string $conversationId, string $activityId, array $activity): PromiseInterface
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
     * allowlist refuses before any request exists. The bearer rides only
     * the allowlisted platform hosts. The signed query of a legit url is
     * bearer material: error prose carries the PATH only.
     *
     * @return PromiseInterface<string>
     */
    public function fetchBinary(string $url): PromiseInterface
    {
        $scheme = \strtolower((string) (\parse_url($url, \PHP_URL_SCHEME) ?: ''));
        $host = \strtolower((string) (\parse_url($url, \PHP_URL_HOST) ?: ''));
        $platformMedia = ($scheme === 'https' && self::hostIn($host, self::MEDIA_DOMAINS));
        $testLoopback = ($this->insecureLoopbackProxy && Endpoint::isLoopback($host) && ($scheme === 'https' || $scheme === 'http'));
        if (!$platformMedia && !$testLoopback) {
            return \React\Promise\reject(new RestException(
                'msteams media url refused (https + Bot Framework media host required; Graph-hosted content is deferred to P3.5): ' . $host));
        }
        $safeLabel = (string) (\parse_url($url, \PHP_URL_PATH) ?: '/');

        $response = $platformMedia
            ? $this->tokens->token()->then(fn (string $token): PromiseInterface => $this->http->request('GET', $url, ['Authorization' => 'Bearer ' . $token]))
            : $this->http->get($url);

        return $response->then(
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

    /**
     * Seconds to wait before a 429 retry: the Retry-After header as sent,
     * clamped to [0, RETRY_AFTER_CAP_SECONDS]; absent or unparseable means
     * the one-second default.
     */
    public static function retryAfterSeconds(string $header): float
    {
        $header = \trim($header);
        if ($header === '' || !\is_numeric($header)) {
            return 1.0;
        }

        return \max(0.0, \min(self::RETRY_AFTER_CAP_SECONDS, (float) $header));
    }

    /** @param list<string> $domains */
    private static function hostIn(string $host, array $domains): bool
    {
        foreach ($domains as $domain) {
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
        $loopback = $this->insecureLoopbackProxy && Endpoint::isLoopback($host) && ($scheme === 'http' || $scheme === 'https');
        if ($host === '' || ($scheme !== 'https' && !($scheme === 'http' && $loopback))) {
            throw new RestException(
                'msteams serviceUrl refused (https required'
                . ($scheme === 'http' ? '; plain http only with channels.msteams.transport.insecureLoopbackProxy on a loopback host' : '')
                . '): ' . ($host === '' ? $serviceUrl : $host),
                0,
                $path,
            );
        }
        if (!$loopback && !self::hostIn($host, self::SERVICE_DOMAINS)) {
            throw new RestException('msteams serviceUrl refused (not a Bot Framework service host): ' . $host, 0, $path);
        }

        return ['url' => \rtrim($serviceUrl, '/') . $path, 'path' => $path];
    }

    /**
     * @param array<string,mixed> $body
     *
     * @return PromiseInterface<array<string,mixed>>
     */
    private function request(string $method, array $target, array $body, int $retriesLeft = 0): PromiseInterface
    {
        return $this->tokens->token()->then(function (string $token) use ($method, $target, $body, $retriesLeft): PromiseInterface {
            $headers = [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'User-Agent' => 'candy-code-msteams-bundle (https://github.com/detain/sugarcraft, 0.1)',
            ];
            $payload = $body === [] ? '' : (string) \json_encode($body, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR);

            return $this->http->request($method, $target['url'], $headers, $payload)->then(
                function (ResponseInterface $response) use ($method, $target, $body, $retriesLeft): array|PromiseInterface {
                    $status = $response->getStatusCode();
                    $raw = (string) $response->getBody();
                    if ($status === 401) {
                        // The token went stale mid-flight (tenant rotation):
                        // drop the cache so the NEXT call re-mints, and
                        // surface THIS one — no silent retry loop.
                        $this->tokens->invalidate();
                    }
                    if ($status === 429) {
                        $retryAfter = self::retryAfterSeconds($response->getHeaderLine('Retry-After'));
                        $this->log->warn('msteams_429', ['path' => $target['path'], 'retryAfter' => $retryAfter, 'retriesLeft' => $retriesLeft]);
                        if ($retriesLeft > 0) {
                            return $this->pause($retryAfter)->then(
                                fn (): PromiseInterface => $this->request($method, $target, $body, $retriesLeft - 1),
                            );
                        }
                        throw new RestException("msteams 429 rate limited on {$method} {$target['path']}", 429, $target['path']);
                    }
                    if ($status >= 400) {
                        $this->log->warn('msteams_rest_error', [
                            'path' => $target['path'],
                            'status' => $status,
                            'body' => $this->safety->outbound(\substr($raw, 0, self::ERROR_BODY_LOG_BYTES)),
                        ]);
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

    /** @return PromiseInterface<null> */
    private function pause(float $seconds): PromiseInterface
    {
        return new Promise(function (callable $resolve) use ($seconds): void {
            $this->retryTimers[] = $this->loop->addTimer($seconds, static function () use ($resolve): void {
                $resolve(null);
            });
        });
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Push;

use React\Http\Browser;
use React\Http\Message\Response;
use SugarCraft\Code\Support\PublicUrlGuard;
use SugarCraft\Code\Lang;

/**
 * Delivers one encrypted push message to one subscription's endpoint —
 * asynchronously, through react/http's Browser, on the server's loop.
 *
 * THE ENDPOINT IS HOSTILE DATA. A subscription arrives over the wire from a
 * browser the server has no reason to trust, and the endpoint half of it is
 * a URL this process will pay to POST to. Left unchecked, registering one
 * endpoint is an SSRF primitive that outlives the registering client. Two
 * independent doors answer it, and both are walked per message:
 *
 *  1. the NAME must belong to a known push service — https, default port,
 *     no credentials (all shape-checked by {@see PushSubscription}) and a
 *     host from {@see PUSH_SERVICE_HOSTS}/{@see PUSH_SERVICE_DOMAIN_SUFFIXES},
 *     FCM, Mozilla autopush, WNS and Apple's three documented families.
 *  2. the ADDRESS then goes through {@see PublicUrlGuard::guardedDialAddress()}
 *     — the same resolve-once deny-wins rule every other egress in this
 *     process obeys. A name on the list still must not resolve to
 *     loopback, link-local or private space (a poisoned /etc/hosts or a
 *     captive DNS must not turn the allowlist into a dial-home shortcut).
 *
 * What the class dials is the literal the guard returned, never the name:
 * the TLS connection pins `ssl.peer_name` to the vetted hostname so SNI and
 * certificate verification stay bound to the name that passed, and the
 * `Host:` header carries it too. Redirects are never followed —
 * `withFollowRedirects(false)` is a class contract, so a 3xx is delivered
 * to the caller as a status, not obeyed — and no error response is rejected
 * in transport, because a push service's 404/410 is a ANSWER, not a
 * failure: {@see subscriptionIsGone()} names it so the caller can retire
 * the subscription instead of retrying it.
 *
 * Timing is the connect deadline only ({@see CONNECT_TIMEOUT_SECONDS}, the
 * same figure the webhook dispatcher uses) — there is no blanket
 * total-request timeout, per the standing E646 rule that a slow-but-live
 * peer must stay the caller's decision. `TTL:` (RFC 8030 §5.3) rides every
 * request. NOTHING THROWS AT THE CALLER: refusals and transport failures
 * reject the promise; any HTTP status, including 4xx and 5xx, resolves it.
 */
final class WebPushSender
{
    /** Connect-phase deadline, seconds. The only timeout this class imposes. */
    public const CONNECT_TIMEOUT_SECONDS = 2.0;

    /** RFC 8030 §5.3 header required on every message; one hour absent instruction. */
    public const DEFAULT_TTL_SECONDS = 3600;

    /**
     * Statuses whose answer is "that subscription is dead": RFC 8030 §5.4
     * defines 404 as not-found-or-no-longer-valid and 410 as gone (the UA
     * must have already re-subscribed past the service's retention).
     */
    public const GONE_STATUSES = [404, 410];

    /**
     * Exact hostnames of the documented push services. FCM's own endpoint
     * host, Mozilla autopush (production + the legacy name), and Apple's
     * production, sandbox and legacy gateway hosts.
     */
    public const PUSH_SERVICE_HOSTS = [
        'fcm.googleapis.com',
        'updates.push.services.mozilla.com',
        'push.services.mozilla.com',
        'api.push.apple.com',
        'api.sandbox.push.apple.com',
        'api.push-sandbox.apple.com',
        'gateway.push.apple.com',
    ];

    /**
     * Domain suffixes whose subdomains are themselves push endpoints:
     * WNS issues per-node hosts under these (Microsoft documents the
     * notify.live.net era and the notify.windows.com/notify.microsoft.com
     * successors). Suffix matches are strict — the host must be LONGER than
     * the suffix, so the bare suffix itself never qualifies and neither can
     * a name merely ending in the text of one.
     */
    public const PUSH_SERVICE_DOMAIN_SUFFIXES = [
        '.notify.microsoft.com',
        '.notify.windows.com',
        '.notify.live.net',
    ];

    /** @var (callable(string): Browser)|null */
    private $browserFactory;

    /** @var (callable(string): array)|null */
    private $resolveAddresses;

    /** @var (callable(string): bool)|null */
    private $isBlockedAddress;

    private function __construct(
        private readonly VapidKeys $keys,
        private readonly PushPayloadCipher $cipher,
    ) {
    }

    /**
     * @param null|callable(string): Browser $browserFactory answers the Browser
     *        pinned for one vetted host — production pins TLS to the hostname
     *        it dials by address; tests hand in recorders.
     * @param null|callable(string): array $resolveAddresses DNS seam, forwarded
     *        untouched to {@see PublicUrlGuard::guardedDialAddress()}.
     * @param null|callable(string): bool $isBlockedAddress block rule, same.
     */
    public static function new(
        VapidKeys $keys,
        ?PushPayloadCipher $cipher = null,
        ?callable $browserFactory = null,
        ?callable $resolveAddresses = null,
        ?callable $isBlockedAddress = null,
    ): self {
        $sender = new self($keys, $cipher ?? PushPayloadCipher::new());
        $sender->browserFactory = $browserFactory;
        $sender->resolveAddresses = $resolveAddresses;
        $sender->isBlockedAddress = $isBlockedAddress;

        return $sender;
    }

    /** The endpoint policy in one question, asked the same way for caller and tests. */
    public static function endpointAllowed(string $host): bool
    {
        $host = strtolower($host);
        if (in_array($host, self::PUSH_SERVICE_HOSTS, true)) {
            return true;
        }
        foreach (self::PUSH_SERVICE_DOMAIN_SUFFIXES as $suffix) {
            if (strlen($host) > strlen($suffix) && str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    public static function subscriptionIsGone(int $status): bool
    {
        return in_array($status, self::GONE_STATUSES, true);
    }

    public static function deliveryAccepted(int $status): bool
    {
        return $status >= 200 && $status < 300;
    }

    /**
     * Encrypt $plaintext for $subscription and POST it, resolving with the
     * push service's HTTP status code. Rejections carry a finished sentence:
     * a refused endpoint never reaches a socket, and a transport failure is
     * named apart from any answer the peer actually sent.
     *
     * @return \React\Promise\PromiseInterface<int>
     */
    public function send(PushSubscription $subscription, string $plaintext, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): \React\Promise\PromiseInterface
    {
        if (!$this->endpointAllowed($subscription->host())) {
            return \React\Promise\reject(new \RuntimeException(Lang::t('serve.push.endpoint_denied', [
                'host' => $subscription->host(),
            ])));
        }
        if ($ttlSeconds < 0 || $ttlSeconds > 0x7FFFFFFF) {
            return \React\Promise\reject(new \InvalidArgumentException(Lang::t('serve.push.ttl_invalid', [
                'ttl' => (string) $ttlSeconds,
            ])));
        }

        [$refusal, $dial] = PublicUrlGuard::guardedDialAddress(
            $subscription->host(),
            false,
            $this->resolveAddresses,
            $this->isBlockedAddress,
        );
        if ($refusal !== null || $dial === null) {
            return \React\Promise\reject(new \RuntimeException(Lang::t('serve.push.endpoint_blocked', [
                'host' => $subscription->host(),
                'reason' => $refusal ?? 'could not resolve host',
            ])));
        }

        try {
            $body = $this->cipher->encrypt($plaintext, $subscription);
            $authorization = $this->keys->authorizationFor($subscription->origin());
        } catch (\Throwable $problem) {
            return \React\Promise\reject($problem instanceof \RuntimeException || $problem instanceof \InvalidArgumentException
                ? $problem
                : new \RuntimeException($problem->getMessage(), 0, $problem));
        }

        $port = 443;
        $authority = (str_contains($dial, ':') ? '[' . $dial . ']' : $dial) . ':' . $port;
        $headers = [
            'Host' => $subscription->host(),
            'TTL' => (string) $ttlSeconds,
            'Content-Encoding' => 'aes128gcm',
            'Content-Type' => 'application/octet-stream',
            'Authorization' => $authorization,
        ];

        try {
            $pending = ($this->browserFactory ?? self::pinnedBrowserFactory())($subscription->host())
                ->request('POST', 'https://' . $authority . (string) $subscription->requestTarget(), $headers, $body);
        } catch (\Throwable $problem) {
            return \React\Promise\reject(new \RuntimeException(Lang::t('serve.push.transport_failed', [
                'host' => $subscription->host(),
                'reason' => $problem->getMessage(),
            ])));
        }

        return $pending->then(
            static fn (Response $response): int => $response->getStatusCode(),
            static function (\Throwable $problem) use ($subscription): never {
                throw new \RuntimeException(Lang::t('serve.push.transport_failed', [
                    'host' => $subscription->host(),
                    'reason' => $problem->getMessage(),
                ]));
            },
        );
    }

    /**
     * The production transport: a Browser whose connector carries the
     * connect deadline (and nothing else) and whose TLS context pins
     * `peer_name` to the vetted name, while the request URL names only the
     * guard-returned literal — the same division the guarded fetch surfaces
     * use. `withFollowRedirects(false)` and `withRejectErrorResponse(false)`
     * are class contracts: every status arrives as data.
     *
     * @return (callable(string): Browser)
     */
    private static function pinnedBrowserFactory(): callable
    {
        return static function (string $host): Browser {
            $connector = new \React\Socket\Connector([
                'timeout' => self::CONNECT_TIMEOUT_SECONDS,
                'tls' => ['peer_name' => $host],
            ]);

            return (new Browser($connector))
                ->withFollowRedirects(false)
                ->withRejectErrorResponse(false);
        };
    }
}

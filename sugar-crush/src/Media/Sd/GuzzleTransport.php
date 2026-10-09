<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media\Sd;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use SugarCraft\Crush\Providers\Concerns\HttpClientDefaults;

/**
 * The production SdTransport (plan W1.3): Guzzle behind the house
 * connect-bound policy, every URL composed and pinned by {@see UrlGuard}.
 *
 * BOUNDING DOCTRINE (E646 blanket-timeout ban, crush_media §10.1-2, and the
 * HttpClientDefaults docblock law this class inherits by `use`): the connect
 * phase is bounded (DNS+TCP+TLS, env/settings overridable); a generation POST
 * is NEVER given a wall-clock total timeout — an SD render on a loaded card
 * legitimately runs minutes past any flat ceiling, so killing by total time
 * would abort real work rather than hangs. Liveness during such a render is
 * the caller loop's job: W2.1's tool beats EngineBackend's IDLE ceiling
 * through W1.5's ProgressLoop, not through a Guzzle option.
 *
 * Redirects are not followed by Guzzle (`allow_redirects => false`) because an
 * automatic follow could land off the configured origin before anyone looked;
 * this class follows same-origin redirects itself, re-checking every Location
 * through the guard, bounded by a hop count (a cycle, not a wall clock).
 */
final class GuzzleTransport implements HeaderAwareSdTransport
{
    use HttpClientDefaults;

    /** Redirect hops followed before declaring the server cyclic (bounded fail-fast, not a timer). */
    public const MAX_REDIRECT_HOPS = 3;

    /** Statuses whose re-issue keeps the method+body; the rest flatten to GET. */
    private const METHOD_PRESERVING_REDIRECTS = [307, 308];

    private GuzzleClient $client;

    /**
     * @param array<string, string> $defaultHeaders  e.g. an Authorization header; see Client::authHeaders()
     * @param array<string, mixed>  $clientOptions   verbatim Guzzle client options (a 'handler' key is the test seam)
     */
    public function __construct(
        private readonly UrlGuard $guard,
        private readonly array $defaultHeaders = [],
        array $clientOptions = [],
    ) {
        // http_errors off: non-2xx is a VALUE (SdTransportResult), the Client
        // decides what is an error; allow_redirects off per class docblock.
        $this->client = self::guzzleClient($clientOptions + [
            'http_errors' => false,
            'allow_redirects' => false,
            'headers' => ['Accept' => 'application/json'] + $this->defaultHeaders,
        ]);
    }

    public function request(string $method, string $path, array $json = [], array $query = []): SdTransportResult
    {
        return $this->requestWithHeaders($method, $path, $json, $query);
    }

    public function requestWithHeaders(
        string $method,
        string $path,
        array $json = [],
        array $query = [],
        array $headers = [],
    ): SdTransportResult {
        $url = $this->guard->absolute($path);
        $method = strtoupper($method);
        $hops = 0;

        while (true) {
            $options = [];

            if ($query !== []) {
                $options['query'] = $query;
            }

            if ($json !== [] && !\in_array($method, ['GET', 'HEAD', 'DELETE'], true)) {
                $options['json'] = $json;
            }

            if ($headers !== []) {
                $options['headers'] = $headers;
            }

            try {
                $response = $this->client->request($method, $url, $options);
            } catch (GuzzleException $e) {
                throw SdException::transportFailure('sd transport ' . $method . ' ' . $path, $e);
            }

            $status = $response->getStatusCode();

            if (!\in_array($status, [301, 302, 303, 307, 308], true) || !$response->hasHeader('Location')) {
                return SdTransportResult::new(
                    $status,
                    (string) $response->getBody(),
                    $response->getHeaderLine('Content-Type'),
                );
            }

            if ($hops >= self::MAX_REDIRECT_HOPS) {
                throw SdException::protocol(
                    'sd transport',
                    $method . ' ' . $path . ': redirect after ' . self::MAX_REDIRECT_HOPS . ' hops — refusing a redirect loop',
                );
            }

            $hops++;
            $url = $this->guard->redirectTarget($response->getHeaderLine('Location'));

            if ($status === 303 || !\in_array($status, self::METHOD_PRESERVING_REDIRECTS, true)) {
                // 301/302/303 re-issue as a bodyless GET (RFC 9110 §15.4
                // normalisation — the same shape every client applies).
                $method = 'GET';
                $json = [];
            }
        }
    }
}

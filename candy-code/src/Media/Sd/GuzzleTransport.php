<?php

declare(strict_types=1);

namespace SugarCraft\Code\Media\Sd;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use SugarCraft\Code\Providers\Concerns\HttpClientDefaults;

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
 * through W1.5's ProgressLoop, not through a Guzzle option. The one carve-out
 * is caller-SUPPLIED: request()/requestWithHeaders() accept an explicit
 * per-request total budget, used solely by the discovery GET ladder (R1
 * MAJOR-2 — wiring DISCOVERY_TIMEOUT_SECONDS honestly, never self-imposed).
 *
 * Redirects are not followed by Guzzle (`allow_redirects => false`) because an
 * automatic follow could land off the configured origin before anyone looked;
 * this class follows same-origin redirects itself, re-checking every Location
 * through the guard, bounded by a hop count (a cycle, not a wall clock).
 */
final class GuzzleTransport implements MultipartSdTransport
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

    public function request(string $method, string $path, array $json = [], array $query = [], ?float $totalTimeoutSeconds = null): SdTransportResult
    {
        return $this->requestWithHeaders($method, $path, $json, $query, [], $totalTimeoutSeconds);
    }

    public function requestWithHeaders(
        string $method,
        string $path,
        array $json = [],
        array $query = [],
        array $headers = [],
        ?float $totalTimeoutSeconds = null,
    ): SdTransportResult {
        return $this->walk(strtoupper($method), $path, $this->guard->absolute($path), $query, $json, 'json', $headers, $totalTimeoutSeconds);
    }

    public function requestMultipart(
        string $method,
        string $path,
        array $fields = [],
        array $files = [],
        ?float $totalTimeoutSeconds = null,
    ): SdTransportResult {
        return $this->walk(
            strtoupper($method),
            $path,
            $this->guard->absolute($path),
            [],
            self::multipartParts($fields, $files),
            'multipart',
            [],
            $totalTimeoutSeconds,
        );
    }

    /**
     * Build Guzzle's `multipart` option from flat field/file maps. A form part
     * is a string or a file — anything else is a caller bug, refused rather
     * than silently JSON-embedded (the FastAPI contract treats a field value
     * as text; nesting belongs to the caller, not the transport).
     *
     * @param array<string, mixed> $fields
     * @param list<array<string, mixed>> $files
     * @return list<array<string, mixed>>
     */
    private static function multipartParts(array $fields, array $files): array
    {
        $parts = [];
        foreach ($fields as $name => $value) {
            $parts[] = ['name' => (string) $name, 'contents' => self::fieldContents((string) $name, $value)];
        }
        foreach ($files as $file) {
            if (!isset($file['name'], $file['filename'], $file['contents'])
                || !\is_string($file['name']) || !\is_string($file['filename']) || !\is_string($file['contents'])) {
                throw SdException::protocol(
                    'sd transport multipart',
                    'every file part needs string name, filename and contents — got ' . get_debug_type($file),
                );
            }
            $parts[] = $file;
        }

        return $parts;
    }

    private static function fieldContents(string $name, mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_int($value) || \is_float($value) || \is_string($value)) {
            return (string) $value;
        }
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        throw SdException::protocol(
            'sd transport multipart',
            "form field {$name} must be scalar, got " . get_debug_type($value) . ' — strip it or flatten it at the request builder',
        );
    }

    /**
     * The shared redirect walk both request shapes drive: same-origin hop
     * re-checking, the single total-timeout door, and the RFC-9110 flatten of
     * a non-method-preserving redirect to a bodyless GET (which is why $body
     * is nulled there rather than left to Guzzle).
     *
     * @param array<string, scalar>|null $query
     * @param array<array-key, mixed>|null $body
     * @param array<string, string> $headers
     */
    private function walk(string $method, string $path, string $url, ?array $query, ?array $body, string $bodyKey, array $headers, ?float $totalTimeoutSeconds): SdTransportResult
    {
        $hops = 0;

        while (true) {
            $options = [];

            if ($query !== [] && $query !== null) {
                $options['query'] = $query;
            }

            if ($body !== null && $body !== [] && !\in_array($method, ['GET', 'HEAD', 'DELETE'], true)) {
                $options[$bodyKey] = $body;
            }

            if ($headers !== []) {
                $options['headers'] = $headers;
            }

            // The ONLY total-timeout door in this class, and it opens on an
            // explicit caller-supplied budget: discovery GETs pass the 3.0 s
            // ladder ceiling (SglangServerInfo-sanctioned E646 exception —
            // a probe must never hang startup on a deaf LAN host). Callers
            // that pass null — every generation POST — keep the connect-bound
            // only shape above; nothing here sets a total on its own initiative.
            if ($totalTimeoutSeconds !== null) {
                $options['timeout'] = $totalTimeoutSeconds;
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
                $body = null;
            }
        }
    }
}

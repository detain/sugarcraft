<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers\Concerns;

use GuzzleHttp\Client;

/**
 * The one hand-shape for "GET a URL, take the body as text, decode it as a
 * JSON object/array" that the discovery readers each spelled out separately
 * ({@see \SugarCraft\Code\Providers\ModelMetadata},
 * {@see \SugarCraft\Code\Providers\OpenAiModelsEndpoint},
 * {@see \SugarCraft\Code\Providers\SglangServerInfo}).
 *
 * Both helpers THROW on failure (transport error, refused status when the
 * client has `http_errors`, malformed JSON, a scalar document). The failure
 * POLICY stays with each caller: every one of them wraps the call in its own
 * catch and turns it into its own "unknown" (null / empty table / keep the
 * stale cache), so those contracts are unchanged by sharing the mechanics.
 *
 * App-internal on purpose; lift into a library only when a second directory
 * needs it.
 */
trait JsonFetch
{
    /** The response body of `GET $uri` as a string; throws on transport/status failure. */
    private static function fetchBody(Client $client, string $uri): string
    {
        return (string) $client->get($uri)->getBody();
    }

    /**
     * `$body` decoded as a JSON array/object; throws on malformed JSON or a
     * scalar/null document.
     *
     * @return array<array-key, mixed>
     *
     * @throws \JsonException
     */
    private static function decodeJsonArray(string $body): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            throw new \JsonException('JSON document is not an object or array');
        }

        return $decoded;
    }

    /**
     * `GET $uri` and decode it; throws on any failure.
     *
     * @return array<array-key, mixed>
     */
    private static function fetchJson(Client $client, string $uri): array
    {
        return self::decodeJsonArray(self::fetchBody($client, $uri));
    }
}

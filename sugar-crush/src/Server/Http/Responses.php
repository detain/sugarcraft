<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server\Http;

use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;

/**
 * The server's response shapes in one place: JSON bodies, the refusal shape
 * every guard returns, and the headers every response carries.
 *
 * REFUSALS CARRY A `kind`, NEVER A STORY. A client branches on
 * `error.kind` (Appendix O §6.2); the message is for a human and says what was
 * refused, not which check fired or what the server holds — an auth failure
 * reads the same whether the token was missing, wrong or expired.
 */
final class Responses
{
    /**
     * Headers on every response. A same-origin app with nothing to embed and
     * nothing to frame: `connect-src 'self'` covers the WebSocket under CSP3.
     */
    public const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer',
        'X-Frame-Options' => 'DENY',
        'Content-Security-Policy' => "default-src 'self'; connect-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'",
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin',
    ];

    private function __construct()
    {
    }

    /** @param array<string, mixed> $body */
    public static function json(int $status, array $body, array $headers = []): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'] + $headers,
            (string) \json_encode($body, \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE),
        );
    }

    /** A refusal: `{"error":{"kind":…,"message":…}}`. */
    public static function error(int $status, string $kind, string $message, array $headers = []): ResponseInterface
    {
        // Built apart from its envelope: this is the HTTP API's refusal shape,
        // keyed by `kind`, and not the CLI's `--output-format json` error
        // document (whose `error.type` README pins).
        $refusal = ['kind' => $kind, 'message' => $message];

        return self::json($status, ['error' => $refusal], $headers);
    }

    /** $response with {@see SECURITY_HEADERS} added (an explicit header wins). */
    public static function secured(ResponseInterface $response): ResponseInterface
    {
        foreach (self::SECURITY_HEADERS as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }
}

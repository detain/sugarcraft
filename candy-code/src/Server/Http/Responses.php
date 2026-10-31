<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Http;

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
     * Headers on every response. A same-origin app with nothing to embed
     * from OTHER origins and nothing to frame: `connect-src 'self'` covers
     * the WebSocket under CSP3; `img-src`/`media-src` additionally admit
     * `blob:` because MediaCard renders `media.fetch` bytes through
     * same-origin object URLs — without the carve-out Chromium refuses to
     * decode them (the fetch succeeds; the paint errors).
     */
    public const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer',
        'X-Frame-Options' => 'DENY',
        'Content-Security-Policy' => "default-src 'self'; connect-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; style-src 'self' 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'",
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin',
    ];

    /**
     * Headers on the sandboxed `/canvas/{token}` page. These REPLACE
     * {@see SECURITY_HEADERS} where the two must differ — {@see secured()}
     * only fills a header that is absent, so every entry here wins — because
     * the document under this policy is MODEL-AUTHORED HTML: the containment
     * story is the frame itself, directive by directive.
     *
     * - `sandbox allow-scripts` (CSP): the browsing-context sandbox applies
     *   even to same-origin frames — an opaque origin, no access to this
     *   server, no top-navigation, no popups, no forms, no downloads. Only
     *   scripts are admitted, because a canvas page that cannot run its own
     *   drawing code is not the feature. There is deliberately NO
     *   `allow-same-origin` to go with it: scripts in an opaque origin are
     *   harmless, and that pairing is exactly what the iframe attribute
     *   repeats client-side.
     * - `default-src 'none'` with per-source carve-outs: the page may load
     *   NOTHING except what is inlined or generated locally — `script-src`
     *   and `style-src` admit `'unsafe-inline'` because the artifact is one
     *   self-contained document (that is the tool's contract) and its
     *   `<script>`/`<style>` blocks are the artifact; there is no
     *   script-src `https:` for the model to exfiltrate to or be exploited
     *   through. `img-src`/`font-src`/`media-src` take `data:`/`blob:` for
     *   the same reason — inline payloads only, never a network URL.
     * - `connect-src 'none'`: no fetch/XHR/WebSocket/callback of any kind.
     *   The page cannot dial this server's API with the browser's
     *   credentials, and it cannot phone home.
     * - `form-action 'none'`, `base-uri 'none'`: no form submissions, and a
     *   forged `<base>` cannot repoint the document's relative URLs.
     * - `frame-ancestors 'self'`: only this client frames the page (the app
     *   embeds it in a same-origin iframe); the wider web cannot put the
     *   artifact in a clickjacking frame of its own.
     * - `X-Frame-Options: SAMEORIGIN` — the legacy mirror of
     *   `frame-ancestors 'self'`. The default policy says DENY; a canvas
     *   page MUST be framable by its own client or the card shows nothing,
     *   so the override is explicit and no looser.
     * - `Cache-Control: no-store` — the token is a single-use capability,
     *   and the model may re-save the artifact under the same id; a cached
     *   body would serve a dead artifact's bytes or leak a spent page.
     * - `Content-Type: text/html; charset=utf-8` — charset pinned so no
     *   sniffing heuristic gets to reinterpret the bytes.
     */
    public const CANVAS_HEADERS = [
        'Content-Type' => 'text/html; charset=utf-8',
        'Cache-Control' => 'no-store',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Content-Security-Policy' => "sandbox allow-scripts; default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data: blob:; font-src data:; media-src data: blob:; connect-src 'none'; form-action 'none'; base-uri 'none'; frame-ancestors 'self'",
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

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use SugarCraft\Code\Host\TranscriptProjector;

/**
 * `POST /api/canvas` and GET `/canvas/{token}` — the sandboxed page for one
 * model-authored HTML artifact (C-TOOL-6.g).
 *
 * THE TOKEN IS THE CONTAINMENT. The artifact path is never a URL the client
 * assembles: the card posts the (session, artifact) pair it was handed by
 * the projection, and gets back a random token bound to exactly that pair,
 * alive for {@see CanvasLinks::TTL_SECONDS} and consumable once. Because
 * the mint rides `/api/`, `AuthMiddleware` gates it ahead of the Router —
 * an unauthenticated request never reaches this class at all, so minting
 * requires the same login the rest of the API does. The page itself is
 * served PUBLIC like a share page: the Router branch keeps `/canvas/...`
 * out of the API and out of the SPA fall-through, and the spent-or-never-
 * existing token is what answers the visitor.
 *
 * EVERY DEATH ANSWERS ALIKE. Unknown, expired, spent, a session or artifact
 * id that fails the store door's own refusal, an artifact that is not a
 * `.html` document, and an artifact retired between mint and click all
 * return the identical byte-for-byte 404 — the status line cannot
 * enumerate tokens or probe which artifacts exist. The mint validates the
 * pair it binds (a link minted against a non-existent artifact would be a
 * promise the page could not keep), and the page RE-RESOLVES the pair at
 * consume time through the same two doors — `MediaStore::pathFor()` refuses
 * separators and control characters, and only a `.html` extension is
 * served — so a token can never name a path, only an artifact that is
 * there, now, under the session's own store.
 *
 * THE BYTES ARE HOSTILE BY CONSTRUCTION and are handled accordingly: they
 * are read from the store door (mode `0600`, written only by the Canvas
 * tool through its store under a 512 KiB refusal cap — the tool is the
 * only `.html` writer, so the page read rides that cap and mints no second
 * one), never parsed, never executed server-side, and emitted with
 * {@see Responses::CANVAS_HEADERS}: an opaque-origin sandbox whose CSP
 * forbids every outbound channel. The server itself never dials anywhere to
 * render a page — there is nothing to SSRF: serving is a file read and a
 * header set.
 */
final class CanvasPageController
{
    public function __construct(private readonly CanvasLinks $links)
    {
    }

    /**
     * Answer the request, or null for anything that is not a canvas route —
     * the Router treats null as "fall through", exactly as the share
     * controller's null does.
     */
    public function __invoke(ServerRequestInterface $request): ?ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($path === '/api/canvas') {
            return $this->mint($request);
        }
        if (!\str_starts_with($path, '/canvas/')) {
            return null;
        }

        return $this->page($request, \substr($path, \strlen('/canvas/')));
    }

    /**
     * Mint a single-use link for one artifact. The (session, artifact) pair
     * must address a real HTML document in the session store right now;
     * anything else dies with the page's own 404 so the mint door leaks no
     * more than the page door does.
     */
    private function mint(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return Responses::error(405, 'method_not_allowed', 'canvas links are minted by POST only', ['Allow' => 'POST']);
        }

        $body = \json_decode((string) $request->getBody(), true);
        if (!\is_array($body)) {
            return Responses::error(400, 'bad_request', 'the canvas mint needs a JSON body');
        }
        $sessionId = $body['sessionId'] ?? null;
        $artifactId = $body['artifactId'] ?? null;
        if (!\is_string($sessionId) || $sessionId === '' || !\is_string($artifactId) || $artifactId === '') {
            return Responses::error(400, 'bad_request', 'the canvas mint names a sessionId and an artifactId');
        }

        if ($this->resolveDocument($sessionId, $artifactId) === null) {
            return $this->notFound();
        }

        $token = $this->links->mint($sessionId, $artifactId);

        return Responses::json(200, [
            'url' => '/canvas/' . $token,
            'expiresInSeconds' => (int) CanvasLinks::TTL_SECONDS,
        ]);
    }

    /**
     * Serve the page the token was minted for — once. The token is spent
     * before anything else touches it, so a concurrent replay of the same
     * URL loses the race to the second load rather than doubling the
     * capability.
     */
    private function page(ServerRequestInterface $request, string $token): ResponseInterface
    {
        $method = $request->getMethod();
        if ($method !== 'GET' && $method !== 'HEAD') {
            return Responses::error(405, 'method_not_allowed', 'canvas pages are served by GET only', ['Allow' => 'GET, HEAD']);
        }
        if ($token === '' || \str_contains($token, '/')) {
            return $this->notFound();
        }

        $binding = $this->links->consume($token);
        if ($binding === null) {
            return $this->notFound();
        }

        $path = $this->resolveDocument($binding['sessionId'], $binding['artifactId']);
        if ($path === null) {
            return $this->notFound();
        }

        $html = @\file_get_contents($path);
        if ($html === false) {
            // Retired between the resolve and the read, or unreadable: the
            // same shared death, never a different one.
            return $this->notFound();
        }

        return new Response(200, Responses::CANVAS_HEADERS, $html);
    }

    /**
     * The one store-door re-validation both doors run: the session's media
     * store exists, the artifact id resolves to a real file, and that file
     * is an HTML document. null for any refusal. The page re-runs this at
     * consume time on purpose — a model may re-save under the same id and
     * the sweep may retire the folder, and a link must not outlive or
     * repoint what the mint verified.
     */
    private function resolveDocument(string $sessionId, string $artifactId): ?string
    {
        $store = TranscriptProjector::mediaStoreForSession($sessionId);
        if ($store === null) {
            return null;
        }

        try {
            $path = $store->pathFor($artifactId);
        } catch (\RuntimeException) {
            // pathFor refuses separators and control characters: an id that
            // could not have come from a projection. Same death as absent.
            return null;
        }
        if ($path === null || \pathinfo($path, \PATHINFO_EXTENSION) !== 'html') {
            return null;
        }

        return $path;
    }

    private function notFound(): ResponseInterface
    {
        // One sentence for every death on both doors, mint included. The
        // body shape is Responses::error's JSON envelope — deliberate: the
        // mint's failures are an API answer, and the page's 404 reaches the
        // card's error display, not a browser navigation.
        return Responses::error(404, 'not_found', 'this canvas page does not exist, or its link has been used or has expired');
    }
}

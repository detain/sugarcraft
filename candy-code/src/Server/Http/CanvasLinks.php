<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Http;

use SugarCraft\Code\Server\Auth\ExpiringSecrets;

/**
 * Single-use canvas page links (C-TOOL-6.g).
 *
 * A Canvas artifact is model-authored HTML the web client must display.
 * Serving it from a path that names the session and artifact would mint a
 * permanent enumerable URL for hostile pages, so the card asks
 * `POST /api/canvas` (authenticated) for a link and receives a random token
 * good once, for {@see TTL_SECONDS}, bound to exactly one
 * (session, artifact) pair — the same shape `/api/ticket` uses for
 * WebSocket auth ({@see \SugarCraft\Code\Server\Auth\Tickets}), reused
 * through {@see ExpiringSecrets} rather than copied.
 *
 * The binding value is `sessionId . "\n" . artifactId`. No newline can ride
 * either half: session ids reach the store through
 * {@see \SugarCraft\Code\Support\MediaStore::forSession()} which sanitizes
 * them, and artifact ids come back from the store's own listing or from
 * {@see \SugarCraft\Code\Support\MediaStore::pathFor()}, which refuses
 * separators and control characters outright. The mint door re-validates
 * before calling here, so the pair the link carries was a real store entry
 * at mint time — and the page door re-resolves it at consume time, because
 * a link must not outlive the artifact it names.
 */
final class CanvasLinks
{
    /** A link is a click, not a bookmark: one page load, sixty seconds. */
    public const TTL_SECONDS = 60.0;

    private function __construct(private readonly ExpiringSecrets $links)
    {
    }

    /** @param (\Closure(): float)|null $clock */
    public static function new(?\Closure $clock = null): self
    {
        return new self(ExpiringSecrets::new(self::TTL_SECONDS, $clock, 256));
    }

    /** A fresh token bound to this (session, artifact) pair. */
    public function mint(string $sessionId, string $artifactId): string
    {
        return $this->links->mint($sessionId . "\n" . $artifactId);
    }

    /**
     * The pair $token was bound to, spending it; null when the token is
     * unknown, spent or older than {@see TTL_SECONDS} — the three deaths
     * share one answer so the status line cannot enumerate tokens.
     *
     * @return array{sessionId: string, artifactId: string}|null
     */
    public function consume(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $value = $this->links->consume($token);
        if ($value === null) {
            return null;
        }
        [$sessionId, $artifactId] = \explode("\n", $value, 2);

        return ['sessionId' => $sessionId, 'artifactId' => $artifactId];
    }

    /** Links minted and still unconsumed. */
    public function pending(): int
    {
        return $this->links->count();
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Share;

/**
 * One live row of the `shares` table — the snapshot AS IT WAS PUBLISHED.
 *
 * The HTML is frozen text captured at the publish act ({@see ShareStore}
 * docblock: snapshot-at-explicit-user-act is the security model), never a
 * projection of the live transcript; later edits to the session can therefore
 * not reach a page that is already public.
 */
final class ShareRecord
{
    public function __construct(
        public readonly string $token,
        public readonly string $sessionId,
        public readonly string $format,
        public readonly string $html,
        public readonly int $createdAt,
        public readonly int $expiresAt,
    ) {
    }

    /** The path the page is served under, relative to any serve URL. */
    public function path(): string
    {
        return '/' . ShareStore::URL_PREFIX . '/' . $this->token;
    }
}

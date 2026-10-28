<?php

declare(strict_types=1);

namespace SugarCraft\Code\Share;

/**
 * One row of the `shares` table.
 *
 * A SNAPSHOT row (`$live === false`) is the HTML AS IT WAS PUBLISHED: frozen
 * text captured at the publish act ({@see ShareStore} docblock:
 * snapshot-at-explicit-user-act is the security model), never a projection of
 * the live transcript, so later edits to the session cannot reach a page that
 * is already public.
 *
 * A LIVE row (C-SES-3.d, `$live === true`) carries the same publish-time
 * snapshot as its stored `$html` — the fallback when no renderer is wired,
 * and the audit trail of what was published — but {@see
 * \SugarCraft\Code\Server\Http\SharePageController} re-renders it from the
 * current session transcript on every request instead of serving that text.
 * The token, expiry and revocation law is identical for the two kinds.
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
        public readonly bool $live = false,
    ) {
    }

    /** The path the page is served under, relative to any serve URL. */
    public function path(): string
    {
        return '/' . ShareStore::URL_PREFIX . '/' . $this->token;
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server\Auth;

/**
 * The browser's authenticated sessions (Appendix O §8.2): an opaque id in an
 * HttpOnly, SameSite=Strict cookie, held server-side in memory only, so a
 * restart signs every browser out and the token file is the only durable
 * credential.
 *
 * Sliding expiry: every authenticated use pushes the session
 * {@see LIFETIME_SECONDS} from now, so an open tab stays signed in and an
 * abandoned one lapses.
 */
final class CookieSessions
{
    public const COOKIE = 'sugarcrush_session';

    public const LIFETIME_SECONDS = 604_800;

    private function __construct(private readonly ExpiringSecrets $sessions)
    {
    }

    /** @param (\Closure(): float)|null $clock */
    public static function new(?\Closure $clock = null): self
    {
        return new self(ExpiringSecrets::new(self::LIFETIME_SECONDS, $clock, 256));
    }

    /** A new session id — the cookie value. */
    public function open(): string
    {
        return $this->sessions->mint('session');
    }

    /** Whether $id is a live session; a live one slides. */
    public function validate(string $id): bool
    {
        return $id !== '' && $this->sessions->peek($id, slide: true) !== null;
    }

    public function revoke(string $id): void
    {
        $this->sessions->revoke($id);
    }

    public function count(): int
    {
        return $this->sessions->count();
    }
}

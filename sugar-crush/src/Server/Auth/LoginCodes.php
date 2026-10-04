<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server\Auth;

/**
 * One-time login codes (Appendix O §8.2): what the URL `serve` prints carries
 * in its FRAGMENT, so the code never reaches a server log, a proxy or a
 * `Referer`. The page posts it to `/api/login` and gets the HttpOnly cookie.
 *
 * Single use and short lived ({@see TTL_SECONDS}): a code copied out of a
 * terminal scrollback later is already spent or expired.
 */
final class LoginCodes
{
    public const TTL_SECONDS = 120;

    private function __construct(private readonly ExpiringSecrets $codes)
    {
    }

    /** @param (\Closure(): float)|null $clock */
    public static function new(?\Closure $clock = null): self
    {
        return new self(ExpiringSecrets::new(self::TTL_SECONDS, $clock, 64));
    }

    public function mint(): string
    {
        return $this->codes->mint('login', 16);
    }

    /** Whether $code was live; it is spent either way. */
    public function consume(string $code): bool
    {
        return $code !== '' && $this->codes->consume($code) !== null;
    }

    public function revokeAll(): void
    {
        $this->codes->revokeAll();
    }

    public function pending(): int
    {
        return $this->codes->count();
    }
}

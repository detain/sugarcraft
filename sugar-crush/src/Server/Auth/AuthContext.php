<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server\Auth;

/**
 * The server's credential state, in one place: the owner token, the one-time
 * login codes, the cookie sessions, the WebSocket tickets and the failure
 * throttle. Shared by the auth middleware (which checks) and the API (which
 * mints), so the two can never hold different views of who is signed in.
 */
final class AuthContext
{
    private function __construct(
        public readonly TokenStore $tokens,
        public readonly LoginCodes $loginCodes,
        public readonly CookieSessions $sessions,
        public readonly Tickets $tickets,
        public readonly RateLimiter $limiter,
    ) {
    }

    /**
     * Re-read the owner token; when it changed, revoke everything minted
     * under the old one — every cookie session, every unspent ticket and
     * login code — so a rotation signs every client out at once rather than
     * at the next restart. Answers whether it changed.
     */
    public function reloadToken(): bool
    {
        if (!$this->tokens->reload()) {
            return false;
        }
        $this->sessions->revokeAll();
        $this->tickets->revokeAll();
        $this->loginCodes->revokeAll();

        return true;
    }

    /** @param (\Closure(): float)|null $clock one clock for every expiry */
    public static function new(TokenStore $tokens, ?\Closure $clock = null): self
    {
        return new self(
            $tokens,
            LoginCodes::new($clock),
            CookieSessions::new($clock),
            Tickets::new($clock),
            RateLimiter::new($clock),
        );
    }
}

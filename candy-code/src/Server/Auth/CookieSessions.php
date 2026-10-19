<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Auth;

/**
 * The browser's authenticated sessions (Appendix O §8.2): an opaque id in an
 * HttpOnly, SameSite=Strict cookie, server-side only — the cookie carries the
 * secret, the store keeps nothing but its hash ({@see ExpiringSecrets}).
 *
 * Sliding expiry: every authenticated use pushes the session
 * {@see LIFETIME_SECONDS} from now, so an open tab stays signed in and an
 * abandoned one lapses.
 *
 * DURABLE ACROSS RESTARTS (WEB-6): a store built by {@see persisted()} keeps
 * its rows in the state dir through {@see SessionFileStore} — hash-only,
 * token-fingerprint-bound, written through on open/revoke and throttled to
 * once per {@see SLIDE_FLUSH_SECONDS} on sliding validates, pruned on boot.
 * {@see new()} stays the memory-only shape the pre-WEB-6 docblock described.
 * The residual risk that the file is still authentication state (a written row
 * authenticates) is disclosed where the file is defined, {@see SessionFileStore}.
 */
final class CookieSessions
{
    public const COOKIE = 'candy_code_session';

    public const LIFETIME_SECONDS = 604_800;

    /** How often a stream of sliding validates may rewrite the disk copy. */
    public const SLIDE_FLUSH_SECONDS = 300;

    /** The value every cookie session binds itself to; nothing else reads it. */
    private const VALUE = 'session';

    private bool $booted = false;

    private float $lastFlush;

    /** @param (\Closure(): float) $clock */
    private function __construct(
        private readonly ExpiringSecrets $sessions,
        private readonly ?SessionFileStore $file,
        private readonly \Closure $clock,
    ) {
        $this->lastFlush = 0.0;
    }

    /** @param (\Closure(): float)|null $clock */
    public static function new(?\Closure $clock = null): self
    {
        $clock ??= static fn (): float => \microtime(true);

        return new self(ExpiringSecrets::new(self::LIFETIME_SECONDS, $clock, 256), null, $clock);
    }

    /**
     * A store that survives a server restart: its hash-only rows live in
     * $tokens' state directory, valid only while that token is.
     *
     * @param (\Closure(): float)|null $clock
     */
    public static function persisted(TokenStore $tokens, ?\Closure $clock = null): self
    {
        $clock ??= static fn (): float => \microtime(true);

        return new self(
            ExpiringSecrets::new(self::LIFETIME_SECONDS, $clock, 256),
            SessionFileStore::forTokenStore($tokens, $clock),
            $clock,
        );
    }

    /** A new session id — the cookie value. */
    public function open(): string
    {
        $this->boot();
        $id = $this->sessions->mint(self::VALUE);
        $this->flush();

        return $id;
    }

    /** Whether $id is a live session; a live one slides. */
    public function validate(string $id): bool
    {
        $this->boot();
        $live = $id !== '' && $this->sessions->peek($id, slide: true) !== null;
        if ($live && ($this->clock)() - $this->lastFlush >= self::SLIDE_FLUSH_SECONDS) {
            $this->flush();
        }

        return $live;
    }

    public function revoke(string $id): void
    {
        $this->boot();
        $this->sessions->revoke($id);
        $this->flush();
    }

    /** Sign every browser out: the token their sign-in was checked against is gone. */
    public function revokeAll(): void
    {
        $this->boot();
        $this->sessions->revokeAll();
        $this->flush();
    }

    public function count(): int
    {
        $this->boot();

        return $this->sessions->count();
    }

    /**
     * Once per process: hand the persisted rows to the in-memory store.
     * Expired rows never get here ({@see SessionFileStore::load()} prunes
     * them, and writes the pruned file back — pruning lands on disk at boot).
     */
    private function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        if ($this->file === null) {
            return;
        }
        $this->sessions->import($this->file->load(), self::VALUE);
        $this->lastFlush = ($this->clock)();
    }

    /** Push the live rows to disk and re-arm the slide throttle. */
    private function flush(): void
    {
        if ($this->file === null) {
            return;
        }
        $this->file->persist($this->sessions->export());
        $this->lastFlush = ($this->clock)();
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Auth;

use SugarCraft\Code\Support\PrivateRetainedDir;

/**
 * The server's long-lived owner token (Appendix O §8.2).
 *
 * MANDATORY EVEN ON LOOPBACK. Anything that can send a prompt can run code as
 * this user, and 127.0.0.1 is reachable by every other local account and — by
 * DNS rebinding or a cross-site WebSocket — by any web page the user opens. So
 * there is no unauthenticated mode at all.
 *
 * 32 random bytes as 64 hex characters, created on first use in
 * `<state dir>/token` (0600 inside a 0700 directory this uid owns, refused if
 * the directory is a link, foreign or loose — {@see PrivateRetainedDir}).
 * `CANDY_CODE_SERVER_TOKEN` overrides the file for containers, and must itself
 * be at least {@see MIN_OVERRIDE_LENGTH} characters so an override cannot be
 * the weak link. Every comparison is `hash_equals`.
 *
 * The `\RuntimeException` texts here (unreadable token file, override-blocks-rotate)
 * are operator-only diagnostics, untranslated by the G-13 ruling (S-V07;
 * CALIBER_LEARNINGS 'G-13 CLASS RULING'): they surface in the serve startup
 * output and log for the person running the server, never on a chat or
 * browser surface, and name file paths and env vars verbatim.
 */
final class TokenStore
{
    public const FILE = 'token';

    public const MIN_OVERRIDE_LENGTH = 32;

    private const LABEL = 'server state';

    private ?string $cached = null;

    private function __construct(
        private readonly string $dir,
        private readonly ?string $override,
    ) {
    }

    public static function new(string $dir): self
    {
        return new self($dir, null);
    }

    /**
     * A store whose token is $token rather than the file's (null keeps the
     * file). Refuses a token too short to stand in for 256 random bits.
     */
    public function withOverride(?string $token): self
    {
        $token = $token === null ? null : \trim($token);
        if ($token === '') {
            $token = null;
        }
        if ($token !== null && \strlen($token) < self::MIN_OVERRIDE_LENGTH) {
            throw new \InvalidArgumentException(\sprintf(
                'the token override is %d characters; it must be at least %d',
                \strlen($token),
                self::MIN_OVERRIDE_LENGTH,
            ));
        }

        return new self($this->dir, $token);
    }

    /** The private directory the token lives in (created and verified). */
    public function dir(): string
    {
        return PrivateRetainedDir::verified($this->dir, self::LABEL);
    }

    /**
     * The owner token: the override, else the file's, minted on first use.
     *
     * @throws \RuntimeException when the state directory is unsafe or unwritable,
     *                           or the token file exists but cannot be read
     */
    public function token(): string
    {
        if ($this->override !== null) {
            return $this->override;
        }
        if ($this->cached !== null) {
            return $this->cached;
        }

        $path = $this->dir() . '/' . self::FILE;
        $stored = '';
        if (@\lstat($path) !== false && \is_file($path) && !\is_link($path)) {
            // Fail CLOSED: a present file that cannot be read right now (a
            // transient EACCES/EIO) is not a malformed token, and rotating
            // would destroy a good one and sign every client out.
            $raw = @\file_get_contents($path);
            if ($raw === false) {
                throw new \RuntimeException(\sprintf('the server token file %s exists but cannot be read; refusing to replace it', $path));
            }
            $stored = \trim($raw);
        }
        if (\preg_match('/^[0-9a-f]{64}$/', $stored) === 1) {
            return $this->cached = $stored;
        }

        return $this->rotate();
    }

    /**
     * Replace the token with a fresh one and return it. Every bearer client
     * and every cookie minted from the old one stops authenticating once the
     * server reloads it.
     */
    public function rotate(): string
    {
        if ($this->override !== null) {
            throw new \RuntimeException('the server token comes from CANDY_CODE_SERVER_TOKEN; unset it to rotate the stored one');
        }

        $token = \bin2hex(\random_bytes(32));
        PrivateRetainedDir::write($this->dir(), self::FILE, $token . "\n", self::LABEL);

        return $this->cached = $token;
    }

    /**
     * Forget the cached token and read the file again (a `serve token
     * --rotate` in another process replaced it). Answers whether the token
     * changed. An override has no file behind it and never changes.
     *
     * A store that has never read the file has nothing to have changed from:
     * its first reload reports false and records the boot token as the
     * baseline. (WEB-6 makes the distinction load-bearing: a false "changed"
     * at boot would revokeAll the sessions a restart has just restored, and
     * the control socket asks this question before any client has
     * authenticated.)
     *
     * @throws \RuntimeException when the state directory is unsafe or unwritable
     */
    public function reload(): bool
    {
        if ($this->override !== null) {
            return false;
        }
        $before = $this->cached;
        $this->cached = null;

        return $before !== null && $this->token() !== $before;
    }

    public function matches(string $candidate): bool
    {
        return $candidate !== '' && \hash_equals($this->token(), $candidate);
    }
}

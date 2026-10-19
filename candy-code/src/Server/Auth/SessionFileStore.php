<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Auth;

use SugarCraft\Code\Support\PrivateRetainedDir;

/**
 * The disk side of the cookie-session store (WEB-6): `sessions.json` in the
 * server's state directory, holding HASH-ONLY rows — the same `sha256(id)`
 * keys {@see ExpiringSecrets} holds in memory, each mapped to its expiry —
 * written through the house AtomicJsonFile discipline (`PrivateRetainedDir::
 * write`: lstat the `.partial`, umask 077 so the mode settles 0600 BEFORE the
 * rename publishes, rename over the target).
 *
 * THE FILE IS HASHES, NOT SECRETS — AND STILL AUTHENTICATION STATE. A 256-bit
 * minted id is not invertible from its hash, so a stolen copy of this file
 * reveals no usable cookie. The honest residual: these rows ARE what
 * `validate` answers from, so an attacker who can WRITE the file authenticates
 * by planting `sha256(theirOwnChosenId) => future`, and one replaying a stolen
 * file onto a fresh server resurrects those sessions. That is the accepted
 * trade — the same owner-only boundary (0700 dir, 0600 file) already guards
 * the `token` file, and whoever crosses it holds that credential outright.
 *
 * BOUND TO THE OWNER TOKEN. Each write records the sha256 of the current
 * token; a load under a different token is a load of nothing — sessions
 * minted under a rotated token die at the fingerprint check, so the promise
 * in {@see TokenStore::rotate()} ("every cookie minted from the old one stops
 * authenticating") survives a restart, not just a live reload.
 *
 * BEST EFFORT BY DESIGN. A state directory that cannot be verified, a file
 * that cannot be parsed, a write that fails: each costs only durability,
 * never the request. The store degrades to its pre-WEB-6 shape — memory-only,
 * restart signs out — rather than failing authentication open OR closed on an
 * I/O accident.
 */
final class SessionFileStore
{
    public const FILE = 'sessions.json';

    private const KEY_ROWS = 'sessions';

    private const KEY_FINGERPRINT = 'tokenFingerprint';

    private const LABEL = 'server state';

    /** @param (\Closure(): string) $pathResolver the file path, throwing RuntimeException when the dir is unusable @param (\Closure(): string) $fingerprintProvider @param (\Closure(): float) $clock */
    private function __construct(
        private readonly \Closure $pathResolver,
        private readonly \Closure $fingerprintProvider,
        private readonly \Closure $clock,
    ) {
    }

    public static function forTokenStore(TokenStore $tokens, ?\Closure $clock = null): self
    {
        return new self(
            static fn (): string => $tokens->dir() . '/' . self::FILE,
            static fn (): string => \hash('sha256', $tokens->token()),
            $clock ?? static fn (): float => \microtime(true),
        );
    }

    /**
     * The live rows for this boot: parsed, fingerprint-checked, and pruned of
     * anything expired. A file that disagrees with any of that is treated as
     * absent — and, when it existed but merely carried dead or foreign rows,
     * rewritten at once so pruning lands on disk, not just in memory.
     *
     * @return array<string, float> hash => expiresAt
     */
    public function load(): array
    {
        $raw = $this->read();
        if ($raw === null) {
            return [];
        }

        /** @var array<string, mixed>|false $decoded */
        $decoded = \json_decode($raw, true, 8);
        if (!\is_array($decoded) || !\is_array($decoded[self::KEY_ROWS] ?? null)) {
            return [];
        }
        $fingerprint = $decoded[self::KEY_FINGERPRINT] ?? null;
        if (!\is_string($fingerprint) || !\hash_equals(($this->fingerprintProvider)(), $fingerprint)) {
            $this->persist([]);

            return [];
        }

        $now = ($this->clock)();
        $live = [];
        $dropped = 0;
        foreach ($decoded[self::KEY_ROWS] as $hash => $expires) {
            if (\is_string($hash) && \preg_match('/^[0-9a-f]{64}$/', $hash) === 1
                && (\is_float($expires) || \is_int($expires)) && (float) $expires > $now) {
                $live[$hash] = (float) $expires;
            } else {
                $dropped++;
            }
        }
        if ($dropped > 0) {
            $this->persist($live);
        }

        return $live;
    }

    /**
     * Publish $rows (in {@see ExpiringSecrets::export()} shape). Swallows the
     * RuntimeExceptions of an unusable state directory: durability is a
     * courtesy, and the server has been authenticating out of memory alone
     * until today.
     *
     * @param array<string, float> $rows
     */
    public function persist(array $rows): void
    {
        try {
            $path = ($this->pathResolver)();
            $encoded = \json_encode([
                self::KEY_ROWS => $rows,
                self::KEY_FINGERPRINT => ($this->fingerprintProvider)(),
            ], \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
            if ($encoded === false) {
                return;
            }
            PrivateRetainedDir::write(\dirname($path), self::FILE, $encoded . "\n", self::LABEL);
        } catch (\RuntimeException) {
            // Fail open toward memory-only: see the class docblock.
        }
    }

    /** The file's bytes, or null when there is nothing safe to read. */
    private function read(): ?string
    {
        try {
            $path = ($this->pathResolver)();
        } catch (\RuntimeException) {
            return null;
        }
        if (@\lstat($path) === false || !\is_file($path) || \is_link($path)) {
            return null;
        }
        $raw = @\file_get_contents($path);

        return $raw === false ? null : $raw;
    }
}

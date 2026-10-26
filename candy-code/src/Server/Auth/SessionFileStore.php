<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Auth;

use SugarCraft\Code\Server\ErrorLog;
use SugarCraft\Code\Support\Log;
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
 * BEST EFFORT BY DESIGN — WITH ONE FAIL-CLOSED EXCEPTION. A state directory
 * that cannot be verified, a file that cannot be parsed, a write that fails:
 * each costs only durability, never the request. The store degrades to its
 * pre-WEB-6 shape — memory-only, restart signs out — rather than failing
 * authentication open OR closed on an I/O accident. The exception is
 * revocation: {@see CookieSessions} may not let a revoked row ride over a
 * restart because the write that should have dropped it was swallowed, so the
 * revocation path escalates through {@see discard()} (and, failing THAT,
 * loudly). Durability lost is a courtesy; durability lying is a defect.
 *
 * STRANDED PARTIALS ARE CLEARED, NOT TOLERATED FOREVER. A hard kill between
 * {@see PrivateRetainedDir::write()}'s lstat and its rename leaves
 * `sessions.json.partial`, and the shared write discipline refuses to
 * overwrite it — which, with the swallow above, would silently kill durability
 * for every later boot. So a write that fails tries once to clear a partial
 * that is provably a leftover (regular file, our uid, older than
 * {@see PARTIAL_STRANDED_MIN_AGE_SECONDS}) and retries; anything else at that
 * name — a fresh writer's in-flight file, a link, a plant — stays, and the
 * failure degrades as before.
 */
final class SessionFileStore
{
    public const FILE = 'sessions.json';

    /**
     * How old `sessions.json.partial` must be before a failed write removes it
     * as a stranding. The hold time of a LIVE write is milliseconds (a few KB
     * through `file_put_contents` and a `rename`), so a minute is already
     * orders of magnitude past any in-flight file — while the alternative
     * (AtomicFileWriter's hour) would strand durability for an hour after
     * every crash-restart, which for a file that silently gates ALL future
     * writes is not a stray dotfile but a dead feature.
     */
    public const PARTIAL_STRANDED_MIN_AGE_SECONDS = 60;

    private const KEY_ROWS = 'sessions';

    private const KEY_FINGERPRINT = 'tokenFingerprint';

    private const LABEL = 'server state';

    /**
     * @param (\Closure(): string) $pathResolver the file path, throwing RuntimeException when the dir is unusable
     * @param (\Closure(): string) $fingerprintProvider
     * @param (\Closure(): float) $clock
     * @param (\Closure(string): void) $log one-line sink for repairs that the request never sees
     */
    private function __construct(
        private readonly \Closure $pathResolver,
        private readonly \Closure $fingerprintProvider,
        private readonly \Closure $clock,
        private readonly \Closure $log,
    ) {
    }

    /** @param (\Closure(): float)|null $clock @param (\Closure(string): void)|null $log */
    public static function forTokenStore(TokenStore $tokens, ?\Closure $clock = null, ?\Closure $log = null): self
    {
        return new self(
            static fn (): string => $tokens->dir() . '/' . self::FILE,
            static fn (): string => \hash('sha256', $tokens->token()),
            $clock ?? static fn (): float => \microtime(true),
            $log ?? static function (string $line): void { Log::raw($line); },
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
     * Publish $rows (in {@see ExpiringSecrets::export()} shape) and answer
     * whether the disk now actually holds them. Swallows the RuntimeExceptions
     * of an unusable state directory: durability is a courtesy, and the server
     * has been authenticating out of memory alone until today. WHAT THE ANSWER
     * IS FOR: a caller whose write carries a revocation may not treat "false"
     * as courtesy — see {@see discard()}.
     *
     * @param array<string, float> $rows
     */
    public function persist(array $rows): bool
    {
        try {
            $dir = \dirname(($this->pathResolver)());
            $encoded = \json_encode([
                self::KEY_ROWS => $rows,
                self::KEY_FINGERPRINT => ($this->fingerprintProvider)(),
            ], \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
            if ($encoded === false) {
                return false;
            }
        } catch (\RuntimeException) {
            return false;
        }

        $text = $encoded . "\n";

        try {
            PrivateRetainedDir::write($dir, self::FILE, $text, self::LABEL);

            return true;
        } catch (\RuntimeException $failure) {
            if (!$this->clearStrandedPartial($dir, $failure)) {
                return false;
            }
        }

        try {
            PrivateRetainedDir::write($dir, self::FILE, $text, self::LABEL);

            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * Make the durable copy UNIMPORTABLE: delete it. A revocation that could
     * not be written must still stand after a restart, and with no readable
     * file {@see load()} imports nothing — every live session then rides over
     * the restart unsigned-out, which is the conservative direction (an
     * availability cost, never a revoked row resurrected). True when nothing
     * the loader could import remains: unlinked now, never there, or behind a
     * directory the reader could not even resolve a path inside.
     */
    public function discard(): bool
    {
        try {
            $path = ($this->pathResolver)();
        } catch (\RuntimeException) {
            return true;
        }

        if (@\unlink($path)) {
            $this->logLine('session durability discarded: the durable copy was removed so a revocation that failed to persist cannot resurrect rows on restart');

            return true;
        }

        clearstatcache(true, $path);

        return @\lstat($path) === false;
    }

    /**
     * Whether a refused write was caused by a stranded partial this store may
     * lawfully remove — and if so, remove it and let the caller retry. The
     * gates are AtomicFileWriter's orphan-rule (regular file, our uid,
     * a real age; a future mtime reads as too young, never as ancient)
     * narrowed to ONE name: this store's own intermediate. Anything else that
     * stands at that name — a link, a plant, a foreign owner, a live writer's
     * in-flight file — is refused the way {@see PrivateRetainedDir::write()}
     * refused it.
     */
    private function clearStrandedPartial(string $dir, \RuntimeException $failure): bool
    {
        $partial = $dir . '/' . self::FILE . PrivateRetainedDir::PARTIAL_SUFFIX;
        clearstatcache(true, $partial);
        $stat = @\lstat($partial);

        if ($stat === false) {
            return true;
        }

        if (((int) $stat['mode'] & 0o170000) !== 0o100000) {
            return false;
        }

        $uid = \function_exists('posix_geteuid') ? \posix_geteuid() : null;

        if ($uid !== null && (int) $stat['uid'] !== $uid) {
            return false;
        }

        if (time() - (int) $stat['mtime'] < self::PARTIAL_STRANDED_MIN_AGE_SECONDS) {
            return false;
        }

        if (!@\unlink($partial)) {
            return false;
        }

        $this->logLine('stranded session partial cleared (the write is retried): '
            . ErrorLog::line($failure, 'session durability'));

        return true;
    }

    /** One line to the log seam; a seam that throws may not eat the write path. */
    private function logLine(string $line): void
    {
        try {
            ($this->log)($line);
        } catch (\Throwable) {
            // the log is the record of the repair, not the repair itself
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

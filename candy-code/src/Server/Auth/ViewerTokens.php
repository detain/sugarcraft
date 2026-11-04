<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Auth;

use SugarCraft\Code\Support\PrivateRetainedDir;

/**
 * The viewer-token store (X-DESIGN1 D-1, reviews-1103 G4-G): shareable,
 * revocable, READ-ONLY credentials for a second human watching sessions.
 *
 * A token is `cv1_` plus 40 hex characters (20 random bytes); what lands on
 * disk is only its SHA-256, in the same 0700 state directory the owner token
 * uses, written through {@see PrivateRetainedDir} so the file is 0600 and a
 * planted link or foreign copy is refused. The raw token is never persisted
 * and never logged — the value is handed to its owner exactly once at grant
 * time and, being shareable, must appear nowhere else.
 *
 * The store is capped: {@see MAX_GRANTS} live grants, and the cap REFUSES a
 * further grant loudly rather than evicting (unlike the FIFO trim of
 * {@see ExpiringSecrets}) — silently dropping someone's working token would
 * be a revocation nobody chose. Grants survive a restart (the file is the
 * truth), expire on an absolute clock (no sliding), and die on every one of
 * the four revocation paths: explicit revoke, expiry sweep, session pinning
 * lives in the row, and rotation — {@see AuthContext::reloadToken()} calls
 * {@see revokeAll()} so rotating the owner token nukes every viewer grant.
 *
 * Grants are deliberately NOT bound to the owner-token fingerprint the way
 * {@see CookieSessions} rows are: the live rotation path revokes them all,
 * and the offline edge (rotate while the server is down, then boot) sits
 * inside the same 0700-directory trust boundary that already protects the
 * store — a writer who can replace the file can mint grants for any binding.
 */
final class ViewerTokens
{
    /** Every token this store mints starts with this; the arm discriminator. */
    public const PREFIX = 'cv1_';

    /** Live grants the store will hold; a further {@see grant()} is refused. */
    public const MAX_GRANTS = 64;

    public const FILE = 'viewer-tokens.json';

    public const DEFAULT_DAYS = 30;

    public const MAX_DAYS = 365;

    public const LABEL_MAX = 64;

    /** Throttle for re-writing the file to stamp `lastUsedAt`; a soft signal. */
    public const SLIDE_FLUSH_SECONDS = 300;

    /** The v1 grant scope set: read-only, no exceptions, per owner ruling. */
    public const SCOPES = ['read'];

    private const LABEL = 'server state';

    private bool $booted = false;

    /**
     * Live rows keyed by the SHA-256 (hex) of the raw token. The key IS the
     * secret's whole durable footprint; the raw value exists nowhere.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $entries = [];

    private float $lastFlush = 0.0;

    /**
     * @param \Closure(): float           $clock one clock for every expiry
     * @param (\Closure(string): void)|null $log  boot-refusal lines, never tokens
     */
    private function __construct(
        private readonly TokenStore $tokens,
        private readonly \Closure $clock,
        private readonly ?\Closure $log,
    ) {
    }

    /**
     * A store backed by `viewer-tokens.json` beside the owner token. Nothing
     * touches disk at construction: the state directory is only resolved at
     * first use and the grants file is only ever written when there is a
     * grant to add or a revocation to record — a cold, untouched store
     * performs no write at all, so fixtures that never exercise viewer auth
     * stay exactly as inert as they were.
     */
    public static function persisted(TokenStore $tokens, ?\Closure $clock = null, ?\Closure $log = null): self
    {
        return new self(
            $tokens,
            $clock ?? (static fn (): float => \microtime(true)),
            $log,
        );
    }

    /**
     * Mint a grant and return its row WITH the raw token attached — the one
     * and only time the token exists outside the caller's hands.
     *
     * @param list<string>|null $sessionIds sessions the grant is pinned to;
     *                                      null reaches every session (D-13)
     * @return array{token: string, id: string, label: string, scopes: list<string>, sessionIds: list<string>|null, createdAt: float, expiresAt: float, lastUsedAt: float|null}
     * @throws \InvalidArgumentException on a blank/oversize label, a day count
     *                                  outside 1..{@see MAX_DAYS}, or a
     *                                  malformed session list
     * @throws \RuntimeException        at the {@see MAX_GRANTS} cap, or when
     *                                  the grant could not be persisted
     */
    public function grant(string $label, ?array $sessionIds = null, int $days = self::DEFAULT_DAYS): array
    {
        $label = \trim($label);
        if ($label === '' || \strlen($label) > self::LABEL_MAX) {
            throw new \InvalidArgumentException(\sprintf(
                'a viewer token label must be between 1 and %d characters; got %d',
                self::LABEL_MAX,
                \strlen($label),
            ));
        }
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new \InvalidArgumentException(\sprintf(
                'a viewer token must live between 1 and %d days; got %d',
                self::MAX_DAYS,
                $days,
            ));
        }
        if ($sessionIds !== null) {
            foreach ($sessionIds as $sessionId) {
                if (!\is_string($sessionId) || \trim($sessionId) === '') {
                    throw new \InvalidArgumentException('a viewer grant session list must hold non-empty strings, or be null for all sessions');
                }
            }
        }

        $this->boot();
        $this->purge();
        if (\count($this->entries) >= self::MAX_GRANTS) {
            // Refuse, never evict: dropping an existing grant to admit a new
            // one would be a revocation nobody asked for.
            throw new \RuntimeException(\sprintf(
                'the viewer token store holds its cap of %d grants; revoke one before minting another',
                self::MAX_GRANTS,
            ));
        }

        $now = ($this->clock)();
        $token = self::PREFIX . \bin2hex(\random_bytes(20));
        $id = $this->freshId();

        $hash = \hash('sha256', $token);
        $this->entries[$hash] = [
            'id' => $id,
            'label' => $label,
            'scopes' => self::SCOPES,
            'sessionIds' => $sessionIds,
            'createdAt' => $now,
            'expiresAt' => $now + $days * 86400.0,
            'lastUsedAt' => null,
        ];

        if (!$this->persist()) {
            // A grant the server cannot remember across a restart must not
            // walk out the door as a real token either.
            unset($this->entries[$hash]);

            throw new \RuntimeException('the viewer token could not be persisted; nothing was minted');
        }

        return ['token' => $token] + $this->entries[$hash];
    }

    /**
     * Resolve a presented credential: the grant row when it is one of ours,
     * still live, unknown-or-expired-or-revoked otherwise. A non-`cv1_`
     * candidate answers null without touching disk, so this stays cheap on
     * the owner-token path that dominates every request.
     *
     * Every miss answers identically — no oracle distinguishing a forgery
     * from an expiry from a revocation.
     *
     * @return array<string, mixed>|null
     */
    public function match(string $candidate): ?array
    {
        if (!\str_starts_with($candidate, self::PREFIX)) {
            return null;
        }

        $this->boot();
        $this->purge();
        $hash = \hash('sha256', $candidate);
        $row = $this->entries[$hash] ?? null;
        if ($row === null) {
            return null;
        }

        $row['lastUsedAt'] = ($this->clock)();
        $this->entries[$hash] = $row;
        $this->touchFlush();

        return $row;
    }

    /**
     * The live grants, newest first, for the admin listing: id, label,
     * scopes, pinning and the clock stamps. The hash key and — by
     * construction — the raw token are never present.
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $this->boot();
        $this->purge();
        $rows = \array_values($this->entries);
        \usort($rows, static fn (array $a, array $b): int => $b['createdAt'] <=> $a['createdAt']);

        return $rows;
    }

    /** How many live grants the store holds. */
    public function count(): int
    {
        $this->boot();
        $this->purge();

        return \count($this->entries);
    }

    /**
     * Drop the grant with $id; answers whether one existed (the revoke RPC's
     * only consumer-visible bit, and deliberately no richer answer that
     * could probe which ids ever were).
     */
    public function revoke(string $id): bool
    {
        $this->boot();
        foreach ($this->entries as $hash => $row) {
            if ($row['id'] === $id) {
                unset($this->entries[$hash]);
                $this->revocationStands();

                return true;
            }
        }

        return false;
    }

    /**
     * Every grant dies — the rotation arm of
     * {@see AuthContext::reloadToken()}. On a store with no file and nothing
     * in memory this is a true no-op: it creates no directory, writes no
     * file, and so stays safe for fixtures and overrides that never minted a
     * viewer token.
     */
    public function revokeAll(): void
    {
        $this->boot();
        if ($this->entries === [] && $this->filePath() === null) {
            return;
        }
        $this->entries = [];
        $this->revocationStands();
    }

    /** Read the durable copy once, at first use. */
    private function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        $this->lastFlush = ($this->clock)();

        $path = $this->filePath();
        if ($path === null) {
            return;
        }

        $raw = @\file_get_contents($path);
        $decoded = $raw === false ? null : \json_decode($raw, true, 8);
        if (!\is_array($decoded) || !isset($decoded['grants']) || !\is_array($decoded['grants'])) {
            $this->note('the viewer token store file is unreadable as JSON and imports nothing');

            return;
        }

        foreach ($decoded['grants'] as $grant) {
            $row = $this->parseable($grant);
            if ($row === null) {
                // Fail closed row by row: a malformed row is not a grant.
                $named = \is_array($grant) && isset($grant['id']) && \is_string($grant['id']) ? $grant['id'] : '?';
                $this->note('the viewer token store dropped a malformed row (id ' . $named . ')');

                continue;
            }
            $this->entries[$row['hash']] = $row['row'];
        }
        $this->purge();
    }

    /**
     * Validate one durable row; the hash key rides in with it on the way out.
     *
     * @return array{hash: string, row: array<string, mixed>}|null
     */
    private function parseable(mixed $grant): ?array
    {
        if (!\is_array($grant)) {
            return null;
        }
        $hash = $grant['hash'] ?? null;
        $id = $grant['id'] ?? null;
        $label = $grant['label'] ?? null;
        $scopes = $grant['scopes'] ?? null;
        $sessionIds = $grant['sessionIds'] ?? null;
        $createdAt = $grant['createdAt'] ?? null;
        $expiresAt = $grant['expiresAt'] ?? null;
        $lastUsedAt = $grant['lastUsedAt'] ?? null;

        if (
            !\is_string($hash) || \preg_match('/^[0-9a-f]{64}$/', $hash) !== 1
            || !\is_string($id) || \preg_match('/^[0-9a-f]{8}$/', $id) !== 1
            || !\is_string($label) || $label === '' || \strlen($label) > self::LABEL_MAX
            || !\is_array($scopes) || $scopes !== self::SCOPES
            || !\is_numeric($createdAt) || !\is_numeric($expiresAt)
            || ($lastUsedAt !== null && !\is_numeric($lastUsedAt))
        ) {
            return null;
        }

        if ($sessionIds !== null) {
            if (!\is_array($sessionIds)) {
                return null;
            }
            foreach ($sessionIds as $sessionId) {
                if (!\is_string($sessionId) || $sessionId === '') {
                    return null;
                }
            }
        }

        return [
            'hash' => $hash,
            'row' => [
                'id' => $id,
                'label' => $label,
                'scopes' => $scopes,
                'sessionIds' => $sessionIds,
                'createdAt' => (float) $createdAt,
                'expiresAt' => (float) $expiresAt,
                'lastUsedAt' => $lastUsedAt === null ? null : (float) $lastUsedAt,
            ],
        ];
    }

    /**
     * The state file when there is a readable regular one, null when there
     * is none (absent, a link, a directory-verification refusal) — reading
     * never creates the directory, only writing does.
     */
    private function filePath(): ?string
    {
        try {
            $path = $this->tokens->dir() . '/' . self::FILE;
        } catch (\Throwable) {
            return null;
        }

        return @\lstat($path) !== false && \is_file($path) && !\is_link($path) ? $path : null;
    }

    /** Stamp `lastUsedAt` to disk at most once per {@see SLIDE_FLUSH_SECONDS}. */
    private function touchFlush(): void
    {
        if ($this->filePath() === null) {
            return;
        }
        if (($this->clock)() - $this->lastFlush < self::SLIDE_FLUSH_SECONDS) {
            return;
        }
        // Best effort: the audit recency stamp must never fail a validation.
        $this->persist();
    }

    /** @throws nothing — every failure answers false for the caller's law */
    private function persist(): bool
    {
        $grants = [];
        foreach ($this->entries as $hash => $row) {
            $grants[] = ['hash' => $hash] + $row;
        }

        try {
            PrivateRetainedDir::write(
                $this->tokens->dir(),
                self::FILE,
                (string) \json_encode(['grants' => $grants], \JSON_THROW_ON_ERROR),
                self::LABEL,
            );
        } catch (\Throwable $failure) {
            $this->note('the viewer token store could not be written: ' . $failure->getMessage());

            return false;
        }

        $this->lastFlush = ($this->clock)();

        return true;
    }

    /** Delete the durable copy; answers whether nothing readable remains. */
    private function discard(): bool
    {
        $path = $this->filePath();
        if ($path === null) {
            return true;
        }
        @\unlink($path);

        return \lstat($path) === false;
    }

    /**
     * The {@see CookieSessions::revocationStands()} law, same three steps:
     * write the revocation; failing that, discard the whole copy so nothing
     * revoked can resurrect on the next boot (the conservative side: every
     * viewer signs out at restart, never a silent un-revocation); failing
     * even that, fail loud.
     */
    private function revocationStands(): void
    {
        if ($this->persist()) {
            return;
        }
        if ($this->discard()) {
            return;
        }

        throw new \RuntimeException(
            'a viewer token revocation could not be written to the durable copy, and that copy could be neither'
            . ' discarded nor unreadable; leaving it would resurrect the revoked grants on the next restart,'
            . ' so revocation fails loudly instead'
        );
    }

    /**
     * Drop every expired row from memory (the disk copy catches up on the
     * next write; expiry is absolute, no sliding — a grant dies when its
     * `expiresAt` passes, whatever the traffic in between).
     */
    private function purge(): void
    {
        $now = ($this->clock)();
        foreach ($this->entries as $hash => $row) {
            if ($row['expiresAt'] <= $now) {
                unset($this->entries[$hash]);
            }
        }
    }

    /** One line to the server log; the log closure is optional by design. */
    private function note(string $line): void
    {
        $log = $this->log;
        if ($log !== null) {
            $log($line);
        }
    }

    /**
     * A 4-byte grant id that names no live row (ids are the revoke handle and
     * the log's audit token; collision is 1 in 2^32, retryed out).
     */
    private function freshId(): string
    {
        $live = [];
        foreach ($this->entries as $row) {
            $live[$row['id']] = true;
        }
        for ($try = 0; $try < 16; ++$try) {
            $id = \bin2hex(\random_bytes(4));
            if (!isset($live[$id])) {
                return $id;
            }
        }

        throw new \RuntimeException('sixteen viewer grant ids in a row all collided; the store is not trusted');
    }
}

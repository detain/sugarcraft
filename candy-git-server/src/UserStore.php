<?php

declare(strict_types=1);

namespace SugarCraft\GitServer;

/**
 * Persistent user accounts store — JSON file, one per data directory.
 *
 * Before this class existed, `user add` / `user key` / `user list` printed
 * success lines while storing nothing at all (the control plane
 * was theater — every claim evaporated on process exit). The store fixes the
 * honesty contract from the write side: an account exists exactly when its
 * bytes are on disk, and the CLI refuses (non-zero exit, stderr) whenever it
 * cannot say that.
 *
 * Whole-file transactions (finding 010): {@see transaction()} takes an
 * exclusive lock on a sidecar `.lock` file, re-reads the bytes under it,
 * runs the mutator, and publishes — so two concurrent `user add` /
 * `user key` processes serialize instead of silently erasing each other
 * (the old load-mutate-save shape lost the earlier writer on every
 * interleaving, both claiming exit-0 success). The lock path is a
 * sidecar, never the payload: save() publishes by rename, and a rename
 * swaps the target inode out from under any flock held on it.
 *
 * The payload carries a `version` stamp (finding 010's forward guard):
 * absent reads as v0-era legacy (fine), anything NEWER than this build
 * understands refuses loudly rather than round-tripping rows a future
 * format added fields to, and a map key whose row carries a different
 * username is corruption, not a quirk (finding 106) — hand-edited stores
 * cannot smuggle one identity under another's name.
 *
 * Port shape: charmbracelet/soft-serve persists accounts in a database; this
 * scaffold deliberately uses a single JSON file (no PDO/SQLite exists in this
 * package — see README "Known gaps"). Writes are atomic: payload goes to a
 * 0600 temp file in the same directory, flushed, fsync'd, then renamed over
 * the target, so a crash (not merely a process exit) can never leave a
 * half-written accounts file behind.
 */
final class UserStore
{
    /** Relative file name inside the data directory. */
    public const FILE_NAME = 'users.json';

    /** Payload format stamp (finding 010's forward guard). */
    public const VERSION = 1;

    private string $filePath;

    /** @var array<string, User> keyed by username, insertion-ordered */
    private array $users = [];

    /** True between a marking add()/put() and the next publish. */
    private bool $dirty = false;

    /** Re-entry guard for transaction(). */
    private bool $inTransaction = false;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
        $this->load();
    }

    public static function forDataPath(string $dataPath): self
    {
        return new self(\rtrim($dataPath, '/') . '/' . self::FILE_NAME);
    }

    public function path(): string
    {
        return $this->filePath;
    }

    /**
     * @return list<User>
     */
    public function all(): array
    {
        return \array_values($this->users);
    }

    public function find(string $username): ?User
    {
        return $this->users[$username] ?? null;
    }

    /**
     * Register a new account. Returns false (and changes nothing) when the
     * username is already taken — callers must surface a refusal, never a
     * silent overwrite. (Finding 010's other half: the CLI USED to discard
     * exactly this bool while printing success.)
     */
    public function add(User $user): bool
    {
        if (isset($this->users[$user->username])) {
            return false;
        }
        $this->users[$user->username] = $user;
        $this->dirty = true;

        return true;
    }

    /** Upsert an existing account (for key edits / deactivation). */
    public function put(User $user): void
    {
        $this->users[$user->username] = $user;
        $this->dirty = true;
    }

    /**
     * Remove an account outright. Returns false (and changes nothing)
     * when no such user exists — the same checked-answer contract
     * add() honors. S23 (finding 040): this is the revocation door the
     * enforced isActive machinery always needed but never had; without
     * it, deactivating an account meant hand-editing users.json (and
     * walking straight into the strict-vocabulary refusals).
     */
    public function remove(string $username): bool
    {
        if (!isset($this->users[$username])) {
            return false;
        }
        unset($this->users[$username]);
        $this->dirty = true;

        return true;
    }

    /**
     * Flip an account inactive, keeping every credential intact (an
     * inactive account can be reactivated by editing nothing but this
     * row). Returns the UPDATED immutable VO — the echo's truth rides
     * the object the store just committed — or null when the account
     * does not exist. Callers wrap this in transaction() like every
     * other mutation.
     */
    public function deactivate(string $username): ?User
    {
        $user = $this->users[$username] ?? null;
        if ($user === null) {
            return null;
        }
        $off = $user->withActive(false);
        $this->users[$username] = $off;
        $this->dirty = true;

        return $off;
    }

    /**
     * Run one whole-file transaction under an exclusive sidecar lock:
     * re-read the on-disk bytes, hand this instance to $mutator, and
     * publish iff the mutator changed anything. Concurrent control-plane
     * processes therefore serialize on the lock — each one's mutator
     * sees the OTHER's committed rows, which is what kills the
     * lost-update window. The mutator's value is returned; an exception
     * aborts without publishing and the lock still releases.
     *
     * Keep mutators cheap and offline: password hashing belongs BEFORE
     * this call (under the lock is the worst place to burn bcrypt).
     *
     * @template T
     * @param callable(self): T $mutator
     * @return T
     */
    public function transaction(callable $mutator): mixed
    {
        if ($this->inTransaction) {
            throw new \LogicException('nested user store transaction');
        }

        $lock = $this->acquireLock();
        $this->inTransaction = true;
        try {
            // Fresh bytes under the lock — the constructor's read is a
            // snapshot of a world that may have moved on since.
            $this->load();
            $result = $mutator($this);
            if ($this->dirty) {
                $this->publish();
            }

            return $result;
        } finally {
            $this->inTransaction = false;
            @\flock($lock, \LOCK_UN);
            @\fclose($lock);
        }
    }

    /**
     * Persist the whole store atomically with tight permissions: the file
     * holds authentication material, so it is created 0600 and the
     * directory 0700 (umask-independent — the mode is set on the temp
     * inode BEFORE the payload lands, and rename carries it onto the
     * target). Raw write of the current snapshot — concurrency-safe
     * mutation goes through {@see transaction()} instead.
     */
    public function save(): void
    {
        $this->publish();
    }

    /** Encode + atomic rename publish of the current snapshot. */
    private function publish(): void
    {
        $dir = \dirname($this->filePath);
        if (!\is_dir($dir) && !@\mkdir($dir, 0700, true) && !\is_dir($dir)) {
            throw new \RuntimeException("cannot create user store directory: {$dir}");
        }

        $payload = \json_encode(
            [
                'version' => self::VERSION,
                'users' => \array_map(
                    static fn (User $u): array => $u->toArray(),
                    $this->users
                ),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
        if ($payload === false) {
            throw new \RuntimeException('cannot encode user store: ' . \json_last_error_msg());
        }

        $tmp = $dir . '/users.json.' . \bin2hex(\random_bytes(6)) . '.tmp';
        $handle = @\fopen($tmp, 'xb');
        if ($handle === false) {
            throw new \RuntimeException("cannot open temp user store: {$tmp}");
        }
        try {
            \chmod($tmp, 0600);
            if (\fwrite($handle, $payload) === false) {
                throw new \RuntimeException('cannot write user store payload');
            }
            \fflush($handle);
            if (\fsync($handle) === false) {
                throw new \RuntimeException('cannot sync user store payload to disk');
            }
        } finally {
            \fclose($handle);
        }

        if (!@\rename($tmp, $this->filePath)) {
            @\unlink($tmp);
            throw new \RuntimeException("cannot publish user store: {$this->filePath}");
        }
        $this->dirty = false;
    }

    /**
     * @return resource the locked sidecar handle (caller releases)
     */
    private function acquireLock()
    {
        $path = $this->filePath . '.lock';
        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0700, true) && !\is_dir($dir)) {
            throw new \RuntimeException("cannot create user store directory: {$dir}");
        }
        $handle = @\fopen($path, 'ab');
        if ($handle === false) {
            throw new \RuntimeException("cannot open user store lock: {$path}");
        }
        if (!@\flock($handle, \LOCK_EX)) {
            @\fclose($handle);
            throw new \RuntimeException("cannot lock user store: {$path}");
        }
        @\chmod($path, 0600); // the sidecar is auth-adjacent state, keep it tight

        return $handle;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function load(): void
    {
        $this->users = [];
        if (!\is_file($this->filePath)) {
            return;
        }

        $raw = @\file_get_contents($this->filePath);
        if ($raw === false) {
            throw new \RuntimeException("cannot read user store: {$this->filePath}");
        }

        $decoded = \json_decode($raw, true);
        if (!\is_array($decoded) || !isset($decoded['users']) || !\is_array($decoded['users'])) {
            // Fail LOUD: a corrupt accounts file must never read as "no users"
            // while the next save() would silently erase the old one.
            throw new \RuntimeException("corrupt user store: {$this->filePath}");
        }

        // Forward guard (finding 010): absent stamp = legacy v0-era file,
        // fine. Anything newer = this build must refuse to re-publish rows
        // whose future fields it would silently drop.
        $version = $decoded['version'] ?? null;
        if ($version !== null && $version !== self::VERSION) {
            throw new \RuntimeException(
                'user store format ' . \var_export($version, true)
                . ' is newer than this server understands (v' . self::VERSION . "): {$this->filePath}"
            );
        }

        foreach ($decoded['users'] as $username => $row) {
            if (!\is_array($row)) {
                throw new \RuntimeException("corrupt user store entry: {$username}");
            }
            $user = User::fromArray($row);
            // Integrity (finding 106): the map key and the row's own
            // username must agree, or one identity is registered under
            // another's name — a hand-edit (or a v0 writer that trusted
            // keys over payloads) that decides access by the wrong label.
            if ($user->username !== (string) $username) {
                throw new \RuntimeException(
                    "corrupt user store: map key '{$username}' carries username '{$user->username}': {$this->filePath}"
                );
            }
            $this->users[$user->username] = $user;
        }
    }
}

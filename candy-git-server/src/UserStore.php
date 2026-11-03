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
 * Port shape: charmbracelet/soft-serve persists accounts in a database; this
 * scaffold deliberately uses a single JSON file (no PDO/SQLite exists in this
 * package — see README "Known gaps"). Writes are atomic: payload goes to a
 * 0600 temp file in the same directory, fsync'd, then renamed over the target,
 * so a crash can never leave a half-written accounts file behind.
 */
final class UserStore
{
    /** Relative file name inside the data directory. */
    public const FILE_NAME = 'users.json';

    private string $filePath;

    /** @var array<string, User> keyed by username, insertion-ordered */
    private array $users = [];

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
     * silent overwrite.
     */
    public function add(User $user): bool
    {
        if (isset($this->users[$user->username])) {
            return false;
        }
        $this->users[$user->username] = $user;

        return true;
    }

    /** Upsert an existing account (for key edits / deactivation). */
    public function put(User $user): void
    {
        $this->users[$user->username] = $user;
    }

    /**
     * Persist the whole store atomically with tight permissions: the file
     * holds authentication material, so it is created 0600 and the directory
     * 0700 (umask-independent — the mode is set on the temp inode BEFORE the
     * payload lands, and rename carries it onto the target).
     */
    public function save(): void
    {
        $dir = \dirname($this->filePath);
        if (!\is_dir($dir) && !@\mkdir($dir, 0700, true) && !\is_dir($dir)) {
            throw new \RuntimeException("cannot create user store directory: {$dir}");
        }

        $payload = \json_encode(
            ['users' => \array_map(
                static fn (User $u): array => $u->toArray(),
                $this->users
            )],
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
        } finally {
            \fclose($handle);
        }

        if (!@\rename($tmp, $this->filePath)) {
            @\unlink($tmp);
            throw new \RuntimeException("cannot publish user store: {$this->filePath}");
        }
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

        foreach ($decoded['users'] as $username => $row) {
            if (!\is_array($row)) {
                throw new \RuntimeException("corrupt user store entry: {$username}");
            }
            $user = User::fromArray($row);
            $this->users[$user->username] = $user;
        }
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Db;

/**
 * On-disk registry of named connections (SQL-O02, candy-sql-once-002).
 *
 * Mirrors the jorgerojas26/lazysql flagship the research doc names: a JSON
 * file under the
 * user's config home that lets `candy-sql --connect=<name>` dial without
 * retyping the full DSN/flag set, and lets a no-arg launch present a picker.
 *
 * Secrets doctrine: the file NEVER holds a password. An entry that needs one
 * names an environment variable (`pass-env`) that is resolved at dial time,
 * so the on-disk form is safe to sync, screenshot and back up. The loader
 * refuses any entry carrying a `pass` key outright — quietly accepting a
 * plaintext secret would train users that the file is a wallet.
 *
 * Schema (parsed once here; everything downstream trusts the parsed shape):
 *
 *     {"connections": {"<name>": {"driver": "mysql", "host": "...",
 *      "port": "3306", "user": "...", "db": "...", "ssl-mode": "require",
 *      "pass-env": "PROD_DB_PASS"}}}
 *
 * Every refusal names the offending entry and the fix; see each method.
 */
final class ConnectionStore
{
    /** Field keys an entry may carry. Anything else is a typo that must not silently drop a host. */
    private const ALLOWED_FIELDS = ['driver', 'host', 'port', 'user', 'db', 'ssl-mode', 'pass-env'];

    /** Driver names accepted here — same vocabulary as ConnectionFactory::SUPPORTED_DRIVERS. */
    private const SUPPORTED_DRIVERS = ['sqlite', 'mysql', 'pgsql'];

    public function __construct(
        private readonly string $path,
    ) {}

    /**
     * The canonical config path: $XDG_CONFIG_HOME/candy-sql/connections.json,
     * falling back to $HOME/.config/... — null when neither home resolves
     * (a container without HOME has no well-known location to read or write).
     */
    public static function defaultPath(): ?string
    {
        $xdg = getenv('XDG_CONFIG_HOME');
        if (is_string($xdg) && $xdg !== '') {
            return rtrim($xdg, '/') . '/candy-sql/connections.json';
        }
        $home = getenv('HOME');
        if (!is_string($home) || $home === '') {
            return null;
        }
        return rtrim($home, '/') . '/.config/candy-sql/connections.json';
    }

    /**
     * Open the store at the default location, or null when no location
     * resolves. A missing file is an empty store, not an error — first-run
     * is the common case this path serves.
     */
    public static function open(): ?self
    {
        $path = self::defaultPath();
        return $path === null ? null : new self($path);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * All entries keyed by connection name, sorted by name for stable
     * picker and refusal output.
     *
     * @return array<string, array<string, string>>
     */
    public function all(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        // The file may name hosts and users; the same private-mode doctrine as
        // the history store applies (tighten a readable pre-existing file,
        // refuse only when securing it fails).
        self::forcePrivateMode($this->path);

        $raw = file_get_contents($this->path);
        if ($raw === false) {
            throw new \RuntimeException(
                "Cannot read the connections file: {$this->path} (delete it or fix its permissions to recover)",
            );
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(
                "The connections file is not valid JSON ({$e->getMessage()}): {$this->path}",
            );
        }

        if (!is_array($decoded) || !array_key_exists('connections', $decoded)) {
            throw new \RuntimeException(
                "The connections file must be a JSON object with a \"connections\" map: {$this->path}",
            );
        }
        if (!is_array($decoded['connections'])) {
            throw new \RuntimeException(
                "The \"connections\" value must be a JSON object of name -> settings: {$this->path}",
            );
        }

        $entries = [];
        foreach ($decoded['connections'] as $name => $fields) {
            $entries[self::validateName((string) $name)] = self::validateEntry((string) $name, $fields);
        }
        ksort($entries);

        return $entries;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->all());
    }

    /**
     * Expand one saved entry into the flag list ConnectionFactory::fromArgv
     * accepts, resolving the entry's pass-env reference against the live
     * environment at dial time.
     *
     * @return list<string>
     * @throws \RuntimeException On an unknown name or a missing password env var
     */
    public function argv(string $name): array
    {
        $entries = $this->all();
        if (!isset($entries[$name])) {
            $saved = array_keys($entries);
            throw new \RuntimeException(
                "Unknown connection '{$name}' — saved connections: "
                . ($saved === [] ? '(none)' : implode(', ', $saved)),
            );
        }
        $entry = $entries[$name];

        $args = ['--driver=' . $entry['driver']];
        foreach (['host' => '--host=', 'port' => '--port=', 'user' => '--user=', 'db' => '--db=', 'ssl-mode' => '--ssl-mode='] as $field => $flag) {
            if (isset($entry[$field])) {
                $args[] = $flag . $entry[$field];
            }
        }

        if (isset($entry['pass-env'])) {
            $secret = getenv($entry['pass-env']);
            if (!is_string($secret) || $secret === '') {
                throw new \RuntimeException(
                    "Connection '{$name}' reads its password from \${$entry['pass-env']} — export it before dialing",
                );
            }
            $args[] = '--pass=' . $secret;
        }

        return $args;
    }

    /**
     * Write (or replace) one named entry and persist atomically. Fields keep
     * the same grammar the reader enforces, so a save can never mint a file
     * its own loader would refuse.
     *
     * @param array<string, string> $fields
     */
    public function save(string $name, array $fields): void
    {
        $name = self::validateName($name);
        $entry = self::validateEntry($name, $fields);

        $entries = $this->all();
        $entries[$name] = $entry;
        ksort($entries);

        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create the connections directory: {$dir}");
        }
        self::forcePrivateMode($dir);

        $json = json_encode(
            ['connections' => $entries],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $temp = $this->path . '.tmp';
        if (file_put_contents($temp, $json . "\n") === false || !chmod($temp, 0600)) {
            @unlink($temp);
            throw new \RuntimeException("Cannot write the connections file: {$this->path}");
        }
        if (!rename($temp, $this->path)) {
            @unlink($temp);
            throw new \RuntimeException("Cannot replace the connections file: {$this->path}");
        }
    }

    /**
     * @return array<string, string> the validated (string-string) entry
     */
    private static function validateEntry(string $name, mixed $fields): array
    {
        if (!is_array($fields)) {
            throw new \RuntimeException("Connection '{$name}' must map to a JSON object of settings");
        }
        if (array_key_exists('pass', $fields)) {
            throw new \RuntimeException(
                "Connection '{$name}' stores a plaintext 'pass' — connections.json never holds"
                . " secrets: replace it with \"pass-env\": \"VAR_NAME\"",
            );
        }

        $clean = [];
        foreach ($fields as $field => $value) {
            if (!in_array((string) $field, self::ALLOWED_FIELDS, true)) {
                throw new \RuntimeException(
                    "Connection '{$name}' has an unknown setting '{$field}' — allowed: "
                    . implode(', ', self::ALLOWED_FIELDS),
                );
            }
            if (!is_string($value) && !is_int($value)) {
                throw new \RuntimeException("Setting '{$field}' of connection '{$name}' must be a string or number");
            }
            $value = (string) $value;
            if ($value === '') {
                throw new \RuntimeException("Setting '{$field}' of connection '{$name}' must not be empty");
            }
            $clean[(string) $field] = $value;
        }

        if (!isset($clean['driver'])) {
            throw new \RuntimeException("Connection '{$name}' needs a 'driver'");
        }
        if (!in_array($clean['driver'], self::SUPPORTED_DRIVERS, true)) {
            throw new \RuntimeException(
                "Connection '{$name}' uses an unsupported driver '{$clean['driver']}' — choose one of: "
                . implode(', ', self::SUPPORTED_DRIVERS),
            );
        }
        if (isset($clean['port']) && !ctype_digit($clean['port'])) {
            throw new \RuntimeException("Setting 'port' of connection '{$name}' must be a number");
        }
        if (isset($clean['pass-env']) && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $clean['pass-env'])) {
            throw new \RuntimeException(
                "Setting 'pass-env' of connection '{$name}' must be an environment variable name",
            );
        }

        return $clean;
    }

    private static function validateName(string $name): string
    {
        if ($name === '' || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $name)) {
            throw new \RuntimeException(
                "Invalid connection name '{$name}' — use letters, digits, dot, dash or underscore (1-64 chars)",
            );
        }
        return $name;
    }

    /**
     * Same doctrine as SqliteHistoryStore::setPrivateMode(): a path this
     * feature owns ends up private. Group/world bits are cleared; a fresh
     * save already landed 0600/0700 so this is a no-op there. Pre-existing
     * files are only TIGHTENED (a stranger's 0400 stays 0400), never loosened
     * and never re-owned. chmod failure is a named refusal, not a warning: a
     * silently world-readable config file is the bug this prevents.
     */
    private static function forcePrivateMode(string $path): void
    {
        clearstatcache(true, $path);
        $perms = fileperms($path);
        if ($perms === false) {
            return;
        }
        $current = $perms & 0777;
        $target = $current & ~0077;
        if ($target === $current) {
            return; // already private — leave exactly as found
        }
        if (!chmod($path, $target)) {
            throw new \RuntimeException(
                "Cannot secure {$path} (mode " . substr(sprintf('%o', $target), -4) . ')',
            );
        }
    }
}

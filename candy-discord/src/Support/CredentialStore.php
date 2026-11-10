<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Support;

/**
 * `~/.candy-code/channels.json` — the dedicated 0600 channel-credential
 * store (owner ruling D4, following the McpAuthStore `mcp-auth.json`
 * precedent: a separate store file for credentials, settings carry only
 * enablement and policy).
 *
 * Shape (v1 — P1's core reuses this byte-for-byte):
 *
 *     {
 *       "version": 1,
 *       "platforms": {
 *         "discord": { "accounts": { "default": { "token": "<bot token>" } } },
 *         "msteams": { "accounts": { "default": {
 *             "appId": "<guid>", "appPassword": "<client secret>", "tenantId": "<guid>"
 *         } } }
 *       }
 *     }
 *
 * Read law, mirrored from the product's private-file discipline
 * (`Server/StateDir.php` refuses group/world bits through `PrivateDir`;
 * `Support/PrivateRetainedDir` writes 0600 through `.partial`+rename):
 *  - `lstat()` the file: it must be a regular file this euid owns;
 *  - any group/world bit on it is a refusal, not a warning;
 *  - writes go temp-unwritten-under-umask(077) → chmod 0600 → rename, so a
 *    reader never sees a torn store and a planted link is never truncated.
 *
 * The store NEVER holds the candy-code server token (that stays in the
 * serve state dir / CANDY_CODE_SERVER_TOKEN); it holds only platform creds.
 */
final class CredentialStore
{
    public const CURRENT_VERSION = 1;

    /**
     * Environment fallback for the default account's token only (§7.2): a
     * bundle-side read, deliberately outside the candy-code src env roster —
     * the settings tree carries policy, the store carries credentials.
     */
    public const TOKEN_ENV = 'DISCORD_BOT_TOKEN';

    public function __construct(
        private readonly string $path,
    ) {
    }

    /**
     * Same file the P1 core's ChannelsStore owns (one store, two writers):
     * the trusted config dir so a hostile project-tier config redirect can
     * never aim credentials at an attacker path.
     */
    public static function defaultPath(): string
    {
        return \SugarCraft\Code\Cli\Bootstrap::trustedConfigDirPath() . '/channels.json';
    }

    /**
     * Fresh-read, mutate, whole-record write. Pairing and allowlist growth
     * go through here so the P1 seen-entrypoint ledger and every unknown key
     * survive a bundle write (read law + 0600 write law enforced on both
     * halves). The P1 core documents the same last-writer-wins race for this
     * file; a lost pairing row shows up as a loud re-pair, never a silent
     * widening of access.
     *
     * @param \Closure(array<string, mixed>): array<string, mixed> $mutate
     *
     * @return array<string, mixed> the written record
     */
    public function mutate(\Closure $mutate): array
    {
        $next = $mutate($this->read());
        if (!\is_array($next)) {
            throw new \RuntimeException('channels store mutation must return the whole record');
        }
        if (($next['version'] ?? self::CURRENT_VERSION) !== self::CURRENT_VERSION) {
            throw new \RuntimeException('channels store mutation must keep version ' . self::CURRENT_VERSION);
        }
        // Stamp on the way out, not just assume on the way in: a store written
        // from absent must self-describe its schema for the read guard's sake.
        $next['version'] = self::CURRENT_VERSION;
        $this->write($next);

        return $next;
    }

    /** One Discord account's bot token, or null when it has none. */
    public function discordToken(string $account): ?string
    {
        $row = $this->account($this->read(), 'discord', $account);
        $token = $row['token'] ?? null;
        if (!\is_string($token)) {
            return null;
        }
        // Trim at the boundary: a stray space around a pasted token would
        // otherwise fail Discord auth with a message that names nothing here.
        $token = \trim($token);

        return $token !== '' ? $token : null;
    }

    /**
     * Every Discord account the store names (multi-account §7.11 discovery —
     * a row with only pairing data still counts: its owner configured it).
     *
     * @return list<string>
     */
    public function discordAccounts(): array
    {
        $accounts = $this->read()['platforms']['discord']['accounts'] ?? [];
        if (!\is_array($accounts)) {
            return [];
        }
        $names = [];
        foreach (\array_keys($accounts) as $name) {
            if (\is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }
        \sort($names);

        return $names;
    }

    /**
     * Peer ids paired (approved) for one account — the pairing tier of
     * §7.3's dmPolicy, stored in the user-tier file, never in config.json.
     *
     * @return list<string>
     */
    public function discordAllowUsers(string $account): array
    {
        $row = $this->account($this->read(), 'discord', $account);
        $list = $row['allowUsers'] ?? [];
        if (!\is_array($list)) {
            return [];
        }
        $clean = [];
        foreach ($list as $id) {
            if (\is_string($id) && $id !== '' && !\in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }

        return $clean;
    }

    /**
     * Persist the pairing sidecar rows for one account: the pending-code
     * table and the freshly approved peer, in one atomic write. Pending and
     * allowlist writes share this seam so a code can never be consumed
     * without its allowlist row landing in the same record.
     *
     * @param array<string, mixed>                                     $pending    code => row table (whole replacement)
     * @param list<string>                                             $allowUsers full list to store
     * @param array<string, mixed>                                     $extra       merged into the account row (token kept)
     */
    public function writeDiscordPairing(string $account, array $pending, array $allowUsers, array $extra = []): void
    {
        $this->mutate(static function (array $data) use ($account, $pending, $allowUsers, $extra): array {
            $row = $data['platforms']['discord']['accounts'][$account] ?? [];
            if (!\is_array($row)) {
                $row = [];
            }
            $row['pairingPending'] = $pending;
            $row['allowUsers'] = \array_values(\array_map(\strval(...), $allowUsers));
            foreach ($extra as $key => $value) {
                $row[$key] = $value;
            }
            $data['platforms']['discord']['accounts'][$account] = $row;

            return $data;
        });
    }

    /**
     * The store's decoded contents.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException on any security or shape refusal (fail loud:
     *         a half-believed credential file is worse than none)
     */
    public function read(): array
    {
        \clearstatcache(true, $this->path);
        $stat = @lstat($this->path);
        if ($stat === false) {
            return [];
        }
        if (((int) $stat['mode'] & 0o170000) !== 0o100000) {
            throw new \RuntimeException("channels store {$this->path} is not a regular file");
        }
        if (self::euidMismatch((int) $stat['uid'])) {
            throw new \RuntimeException("channels store {$this->path} is owned by another user");
        }
        if (((int) $stat['mode'] & 0o077) !== 0) {
            throw new \RuntimeException(
                "channels store {$this->path} is group/world accessible (mode "
                . \decoct((int) $stat['mode'] & 0o777) . '); chmod 600 it',
            );
        }

        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            throw new \RuntimeException("channels store {$this->path} is not readable");
        }
        try {
            $data = \json_decode($raw, true, 12, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("channels store {$this->path} is not valid JSON: {$e->getMessage()}", 0, $e);
        }
        if (!\is_array($data)) {
            throw new \RuntimeException("channels store {$this->path} must hold a JSON object");
        }
        $version = $data['version'] ?? self::CURRENT_VERSION;
        if ((int) $version !== self::CURRENT_VERSION) {
            throw new \RuntimeException("channels store {$this->path}: unsupported version " . \var_export($version, true));
        }

        return $data;
    }

    /** The store file path this object reads and writes. */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * One account's credential row, or null when the operator has not
     * configured it. Policy decisions belong to the caller; this only reads.
     *
     * @param array<string, mixed> $contents
     *
     * @return array<string, mixed>|null
     */
    public function account(array $contents, string $platform, string $account): ?array
    {
        $row = $contents['platforms'][$platform]['accounts'][$account] ?? null;

        return \is_array($row) ? $row : null;
    }

    /**
     * Write the store atomically at 0600 (the law above). Pairing writes go
     * through `mutate()` so unknown sections (the P1 seen-entrypoint ledger)
     * survive; the file write itself is always whole-record.
     *
     * @param array<string, mixed> $contents
     */
    public function write(array $contents): void
    {
        $dir = \dirname($this->path);
        if (!\is_dir($dir) && !@mkdir($dir, 0o700, true) && !\is_dir($dir)) {
            throw new \RuntimeException("channels store directory not creatable: {$dir}");
        }
        $json = \json_encode($contents, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n";

        $previous = \umask(0o077);
        $partial = $this->path . '.partial';
        \clearstatcache(true, $partial);
        if (@lstat($partial) !== false) {
            @unlink($partial);
        }
        \set_error_handler(static fn (): bool => true);
        try {
            if (@file_put_contents($partial, $json) === false) {
                throw new \RuntimeException("channels store partial not writable: {$partial}");
            }
            @chmod($partial, 0o600);
            if (!@rename($partial, $this->path)) {
                @unlink($partial);
                throw new \RuntimeException("channels store rename failed: {$this->path}");
            }
        } finally {
            \umask($previous);
            \restore_error_handler();
        }
    }

    private static function euidMismatch(int $fileUid): bool
    {
        if (!\function_exists('posix_geteuid')) {
            return false;
        }

        return $fileUid !== \posix_geteuid();
    }
}

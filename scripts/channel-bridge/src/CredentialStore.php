<?php

declare(strict_types=1);

namespace ChannelBridge;

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

    public function __construct(
        private readonly string $path,
    ) {
    }

    public static function defaultPath(): string
    {
        return \rtrim((string) (\getenv('HOME') ?: \sys_get_temp_dir()), '/') . '/.candy-code/channels.json';
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

    /**
     * One account's credential row, or null when the operator has not
     * configured it. Policy decisions belong to the caller; this only reads.
     *
     * @return array<string, mixed>|null
     */
    public function account(array $contents, string $platform, string $account): ?array
    {
        $row = $contents['platforms'][$platform]['accounts'][$account] ?? null;

        return \is_array($row) ? $row : null;
    }

    /**
     * Write the store atomically at 0600 (the law above). Used by
     * `bridge.php creds` and by tests; the daemon itself only reads.
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

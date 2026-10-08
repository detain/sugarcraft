<?php

declare(strict_types=1);

namespace SugarCraft\Top\State;

use SugarCraft\Core\Util\AtomicJsonFile;
use SugarCraft\Top\Lang;

/**
 * Where the proc-tree collapse choices live between runs:
 * `$XDG_STATE_HOME/candy-top/tree-state.json`, else
 * `$HOME/.local/state/candy-top/tree-state.json`.
 *
 * btop PR #1791 keeps this in an internal `proc_tree_state` key of
 * config.conf. candy-top deliberately does not: the blob would pollute a
 * file that stays btop-compatible, and every collapse would rewrite the
 * user's config. Runtime state belongs under XDG_STATE_HOME.
 *
 * The state is a convenience, never worth a failed start: a missing,
 * unreadable, oversized or corrupt file loads as the empty state. Writes
 * go through candy-core's {@see AtomicJsonFile} (temp + fsync + rename,
 * 0700 directory, 0600 file), the same publish contract as
 * {@see \SugarCraft\Top\Config\ConfigFile::write()}.
 */
final class TreeStateFile
{
    public const FILE_NAME = 'tree-state.json';

    public const DIR_NAME = 'candy-top';

    /** A larger file is not ours (512 entries x 32 names fit well under it). */
    public const MAX_BYTES = 1048576;

    private function __construct(
        private readonly string $path,
    ) {
    }

    public static function new(string $path): self
    {
        return new self($path);
    }

    /**
     * The XDG state path, or null when neither XDG_STATE_HOME nor HOME is
     * usable. A relative XDG_STATE_HOME is invalid per the base-directory
     * spec and ignored.
     *
     * @param array<string, string>|null $env Environment override (tests); null reads the process env.
     */
    public static function defaultPath(?array $env = null): ?string
    {
        $get = static function (string $name) use ($env): string {
            if ($env !== null) {
                return (string) ($env[$name] ?? '');
            }
            $value = getenv($name);

            return $value === false ? '' : $value;
        };

        $xdg = $get('XDG_STATE_HOME');
        if ($xdg !== '' && str_starts_with($xdg, '/')) {
            return rtrim($xdg, '/') . '/' . self::DIR_NAME . '/' . self::FILE_NAME;
        }
        $home = $get('HOME');
        if ($home !== '') {
            return rtrim($home, '/') . '/.local/state/' . self::DIR_NAME . '/' . self::FILE_NAME;
        }

        return null;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** The stored state as of `$now`; empty on any problem with the file. */
    public function load(int $now): TreeState
    {
        if (!is_file($this->path)) {
            return TreeState::empty();
        }
        $size = @filesize($this->path);
        if ($size === false || $size > self::MAX_BYTES) {
            return TreeState::empty();
        }
        try {
            return TreeState::fromArray(AtomicJsonFile::new($this->path)->read(), $now);
        } catch (\Throwable) {
            return TreeState::empty();
        }
    }

    /**
     * Atomically store `$state`, stamping entries touched this session with `$now`.
     *
     * @throws \RuntimeException On any filesystem or encoding failure; the old file is left intact.
     */
    public function write(TreeState $state, int $now): void
    {
        try {
            AtomicJsonFile::new($this->path)->withPermissions(0600)->write($state->toArray($now));
        } catch (\Throwable $e) {
            throw new \RuntimeException(Lang::t('state.warn.write_failed', ['path' => $this->path, 'reason' => $e->getMessage()]), 0, $e);
        }
    }
}

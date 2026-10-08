<?php

declare(strict_types=1);

namespace SugarCraft\Top\Config;

use SugarCraft\Top\Lang;

/**
 * The on-disk config.conf: path resolution, load and atomic save.
 *
 * Mirrors aristocratos/btop Config::get_config_dir / load / write, with the
 * directory renamed `candy-top` so a btop install's own file is never
 * clobbered (the format is identical — a btop config.conf copied over loads
 * as-is).
 *
 * Saves are atomic (temp file in the same directory + rename): btop's
 * `ofstream(trunc)` leaves a truncated file behind if it dies mid-write,
 * which then loads as all-defaults. candy-core's AtomicJsonFile carries the
 * same contract but is JSON-only, so the text variant lives here.
 */
final class ConfigFile
{
    /** File name inside the config directory. */
    public const FILE_NAME = 'config.conf';

    /** Directory name under the XDG config home. */
    public const DIR_NAME = 'candy-top';

    private function __construct(
        private readonly string $path,
    ) {
    }

    /**
     * Store at $path, or at {@see defaultPath()} when null.
     *
     * @param array<string, string>|null $env Environment override (tests); null reads the process env.
     *
     * @throws \RuntimeException When no path is given and neither XDG_CONFIG_HOME nor HOME is usable.
     */
    public static function new(?string $path = null, ?array $env = null): self
    {
        $path ??= self::defaultPath($env)
            ?? throw new \RuntimeException(Lang::t('config.warn.no_config_dir'));

        return new self($path);
    }

    /**
     * `$XDG_CONFIG_HOME/candy-top/config.conf`, else
     * `$HOME/.config/candy-top/config.conf`, else null.
     *
     * Per the XDG base-directory spec a relative XDG_CONFIG_HOME is invalid
     * and ignored (btop only checks that it exists).
     *
     * @param array<string, string>|null $env
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

        $xdg = $get('XDG_CONFIG_HOME');
        if ($xdg !== '' && str_starts_with($xdg, '/')) {
            return rtrim($xdg, '/') . '/' . self::DIR_NAME . '/' . self::FILE_NAME;
        }
        $home = $get('HOME');
        if ($home !== '') {
            return rtrim($home, '/') . '/.config/' . self::DIR_NAME . '/' . self::FILE_NAME;
        }

        return null;
    }

    /** Absolute path of the config file. */
    public function path(): string
    {
        return $this->path;
    }

    /** Whether the file exists. */
    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * Read the file over $base (defaults when null).
     *
     * A missing file is not an error — defaults with `needsRewrite` set, as
     * btop flags `write_new`. An unreadable one yields defaults plus a
     * warning rather than an exception: a monitor should still start.
     */
    public function load(?Config $base = null): LoadResult
    {
        $base ??= Config::new();
        if (!is_file($this->path)) {
            return new LoadResult($base, [], true);
        }

        $contents = @file_get_contents($this->path);
        if ($contents === false) {
            return new LoadResult($base, [Lang::t('config.warn.read_failed', ['path' => $this->path])], false);
        }

        return ConfigReader::parse($contents, $base);
    }

    /**
     * Atomically write $config in btop format.
     *
     * The temp file lives beside the REAL target (rename is only atomic
     * within a filesystem): a symlinked config.conf — the dotfiles-repo
     * setup — is followed and its target replaced, so the link survives
     * instead of being clobbered by a regular file. The temp inherits an
     * existing file's permission bits, so a chmod'ed config keeps its mode.
     * A read-only existing file is a deliberate "do not touch" and is
     * refused rather than replaced behind the user's back (rename would
     * succeed, since only the directory's write bit matters to it).
     * A directory this call creates is 0700, like btop's own state dirs.
     *
     * @throws \RuntimeException On any filesystem failure, or a read-only
     *                           target; the old file is left intact.
     */
    public function write(Config $config): void
    {
        $target = $this->resolveTarget();
        if (is_file($target) && !is_writable($target)) {
            throw new \RuntimeException(Lang::t('config.warn.read_only', ['path' => $target]));
        }

        $dir = \dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw $this->writeFailed('cannot create directory ' . $dir);
        }

        $tmp = $dir . '/.' . basename($target) . '.tmp.' . bin2hex(random_bytes(6));
        $handle = @fopen($tmp, 'xb');
        if ($handle === false) {
            throw $this->writeFailed('cannot create temp file');
        }

        try {
            if (is_file($target)) {
                $mode = @fileperms($target);
                if ($mode !== false) {
                    @chmod($tmp, $mode & 0777);
                }
            }
            $payload = ConfigWriter::render($config);
            if (@fwrite($handle, $payload) !== \strlen($payload) || !fflush($handle)) {
                throw $this->writeFailed('short write');
            }
            // Payload must be on disk before the rename publishes it, or a
            // crash can leave a truncated file under the real name.
            if (!@fsync($handle)) {
                throw $this->writeFailed('fsync failed');
            }
            $closed = fclose($handle);
            $handle = null;
            if (!$closed) {
                throw $this->writeFailed('close failed');
            }

            if (!@rename($tmp, $target)) {
                throw $this->writeFailed('rename failed');
            }
        } catch (\Throwable $e) {
            if (\is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($tmp)) {
                @unlink($tmp);
            }

            throw $e;
        }

        $this->syncDirectory($dir);
    }

    /**
     * The file a write must replace: $path itself, or — when $path is a
     * symlink, possibly chained or dangling — the final link target,
     * resolved hop by hop (realpath() cannot follow a dangling link).
     *
     * @throws \RuntimeException On a symlink loop.
     */
    private function resolveTarget(): string
    {
        $path = $this->path;
        for ($hops = 0; is_link($path); $hops++) {
            if ($hops >= 40) {
                throw $this->writeFailed('too many levels of symbolic links');
            }
            $link = readlink($path);
            if ($link === false) {
                throw $this->writeFailed('cannot read symbolic link ' . $path);
            }
            $path = str_starts_with($link, '/') ? $link : \dirname($path) . '/' . $link;
        }

        return $path;
    }

    /**
     * fsync the directory so the rename's new entry itself is durable.
     * Best-effort: some filesystems refuse to open or sync a directory, and
     * the write is already atomic without it.
     */
    private function syncDirectory(string $dir): void
    {
        $handle = @fopen($dir, 'rb');
        if ($handle === false) {
            return;
        }
        @fsync($handle);
        fclose($handle);
    }

    private function writeFailed(string $reason): \RuntimeException
    {
        return new \RuntimeException(Lang::t('config.warn.write_failed', ['path' => $this->path, 'reason' => $reason]));
    }
}

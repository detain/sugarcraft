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
 * which then loads as all-defaults. sugar-core's AtomicJsonFile carries the
 * same contract but is JSON-only, so the text variant lives here.
 */
final class ConfigFile
{
    /** File name inside the config directory. */
    public const FILE_NAME = 'config.conf';

    /** Directory name under the XDG config home. */
    public const DIR_NAME = 'candy-top';

    /**
     * Pre-parse read ceiling, mirroring the sibling {@see \SugarCraft\Top\State\TreeStateFile::MAX_BYTES}
     * (finding 155): a pathological config — appended to death by a broken
     * hook, or a device node wearing the file's name — must never be read
     * whole and parsed at launch. A real btop/candy-top config is a few
     * hundred lines, orders of magnitude below this ceiling.
     */
    public const MAX_BYTES = 1048576;

    /**
     * Digest of the file bytes this instance last saw — at load or at a
     * successful write — or null when it never read the file. The stale-save
     * refusal in {@see write()} compares the current disk digest against it
     * (finding 156).
     */
    private ?string $seenDigest = null;

    private function __construct(
        private readonly string $path,
    ) {
    }

    /**
     * Store at $path, or at {@see defaultPath()} when null.
     *
     * @param array<string, string>|null $env Environment override (tests); null reads the process env.
     *
     * @throws \RuntimeException When no path is given and no config location can be
     *                           derived from the environment (see {@see defaultPath()}).
     */
    public static function new(?string $path = null, ?array $env = null): self
    {
        $path ??= self::defaultPath($env)
            ?? throw new \RuntimeException(Lang::t('config.warn.no_config_dir'));

        return new self($path);
    }

    /**
     * `$XDG_CONFIG_HOME/candy-top/config.conf`, else
     * `$HOME/.config/candy-top/config.conf`, else the Windows-native tail
     * `$APPDATA/candy-top/config.conf`, else
     * `$USERPROFILE/.config/candy-top/config.conf`, else null.
     *
     * Per the XDG base-directory spec a relative XDG_CONFIG_HOME is invalid
     * and ignored (btop only checks that it exists).
     *
     * The Windows tail closes the native-cmd/PowerShell boot refusal (candy-top-128):
     * those hosts carry neither HOME nor XDG_CONFIG_HOME, so the old chain
     * threw and bin exited 2 before the TUI opened, while the sibling state
     * and theme chains merely degraded. APPDATA is the platform's config
     * home (the XDG equivalent, like Go's os.UserConfigDir) and USERPROFILE
     * the home equivalent; the lookup is env-driven, not OS-gated, so a
     * Git-Bash $HOME keeps its existing path untouched.
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
        // Windows-shaped roots mix both separators freely ('C:\Users\x');
        // PHP and the Win32 file APIs accept '/' in every position, so only
        // a trailing native separator is trimmed before joining.
        $appData = $get('APPDATA');
        if ($appData !== '') {
            return rtrim($appData, '/\\') . '/' . self::DIR_NAME . '/' . self::FILE_NAME;
        }
        $userProfile = $get('USERPROFILE');
        if ($userProfile !== '') {
            return rtrim($userProfile, '/\\') . '/.config/' . self::DIR_NAME . '/' . self::FILE_NAME;
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

        // Finding 155: the pre-parse size gate the load path never inherited
        // from its sibling state file. filesize() cannot answer (the file
        // vanished or the stat is refused between the is_file() above and
        // here) takes the same refusal arm — nothing is read either way, so
        // the warning is the honest umbrella, matching TreeStateFile's own
        // false-or-over branch shape.
        $size = @filesize($this->path);
        if ($size === false || $size > self::MAX_BYTES) {
            return new LoadResult($base, [Lang::t('config.warn.too_large', ['path' => $this->path])], false);
        }

        $contents = @file_get_contents($this->path);
        if ($contents === false) {
            return new LoadResult($base, [Lang::t('config.warn.read_failed', ['path' => $this->path])], false);
        }
        $this->seenDigest = hash('xxh128', $contents);

        return ConfigReader::parse($contents, $base);
    }

    /**
     * Atomically write $config in btop format.
     *
     * The temp file lives beside the REAL target (rename is only atomic
     * within a filesystem): a symlinked config.conf — the dotfiles-repo
     * setup — is followed and its target replaced, so the link survives
     * instead of being clobbered by a regular file. The temp inherits an
     * existing file's permission bits, so a chmod'ed config keeps its mode;
     * a FRESH config is forced 0600 (the private-state rule its 0700
     * directory and the sibling TreeStateFile already keep). A read-only
     * existing file is a deliberate "do not touch" and is
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

        // Finding 156: this file is last-writer-wins — btop itself assumes a
        // single writer, and two sessions clobbering each other's whole file
        // is invisible to both. This instance remembers the bytes it read
        // (or wrote); if the disk no longer answers that digest, another
        // candy-top saved first and this save is refused honestly, riding
        // the same failure channel any other write refusal takes (the App
        // queues it and shows it). The window left between this check and
        // the rename is the documented single-writer assumption: two saves
        // microseconds apart land on one complete file or the other, never
        // a torn one, and closing it fully would mean a lock file beside
        // the user's config, which this write refuses to litter.
        if ($this->seenDigest !== null && is_file($target)) {
            $current = @file_get_contents($target);
            if ($current !== false && hash('xxh128', $current) !== $this->seenDigest) {
                throw new \RuntimeException(Lang::t('config.warn.stale_save', ['path' => $target]));
            }
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
            } else {
                // Finding 144: a fresh config used to carry the process umask
                // (0644 on a normal host) while its directories are 0700 and
                // the sibling TreeStateFile publishes 0600 through
                // AtomicJsonFile. Same private-state rule: chmod the temp
                // inode before the rename so the published file never flashes
                // world-readable (best-effort, like the inheritance arm).
                @chmod($tmp, 0600);
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

            // The rename is the publish point, and it is also the failure
            // that says the most: on Windows a target held open by a
            // running process refuses to be moved over, which lands here
            // as a loud refusal with the previous file byte-intact. That
            // posture is deliberate — the alternative (unlink, then
            // rename) would delete a readable config before knowing the
            // new bytes could take its place.
            if (!@rename($tmp, $target)) {
                throw $this->writeFailed('rename failed');
            }
            // The bytes we just published are what this instance now saw —
            // a save after a save must not flag its own earlier write.
            $this->seenDigest = hash('xxh128', $payload);
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

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support\Directories;

use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Support\ContainedPath;
use SugarCraft\Crush\Support\HomeDirectory;

/**
 * "Which directories are under this one?" — the one directory listing behind
 * both the server's `fs.listDirs` (the web UI's new-session picker) and the
 * TUI's `/new` folder picker, so the two cannot drift on what they show or on
 * where they stop.
 *
 * DIRECTORIES ONLY. A listing names child directories and nothing else: no
 * file name, no size, no content. Hidden (dot) directories are left out unless
 * asked for; the list is sorted (natural, case-insensitive) and cut at
 * {@see $maxEntries}, saying so. A child that is a link is followed and then
 * re-checked, so a link pointing out of the browse root is not listed; one
 * this process cannot read is listed but marked unreadable, and listing an
 * unreadable directory reports it rather than failing.
 *
 * ROOTED OR NOT. With a root (the server's `--browse-root`) every path is
 * resolved and must stay inside it: `..` is collapsed first, a path outside
 * the root is refused with one answer whether or not it exists, and the real
 * path (links followed) is checked again with
 * {@see ContainedPath::within()}. Without one (the local TUI — the user's own
 * machine) any directory this process can reach may be listed.
 */
final class DirectoryBrowser
{
    /** Child directories one listing names at most. */
    public const MAX_ENTRIES = 1000;

    /** Names read from one directory at most, before the listing is cut. */
    public const MAX_SCANNED = 20_000;

    /** A child holding one of these looks like a project root. */
    public const PROJECT_MARKERS = ['.git', 'composer.json', 'package.json', '.sugar-crush'];

    private function __construct(
        private readonly ?string $root,
        private readonly int $maxEntries,
    ) {
    }

    /**
     * A browser confined to $root (resolved now), or unconfined when null.
     *
     * @throws DirectoryBrowserException when $root is not a directory
     */
    public static function new(?string $root = null, int $maxEntries = self::MAX_ENTRIES): self
    {
        if ($root === null) {
            return new self(null, \max(1, $maxEntries));
        }
        $real = self::realDirectory(self::expandHome($root));

        return new self($real, \max(1, $maxEntries));
    }

    /** The resolved browse root, or null when unconfined. */
    public function root(): ?string
    {
        return $this->root;
    }

    /**
     * $path as the real directory it names: `~` expanded, relative to $base
     * (default the root, else the process directory), `.`/`..` collapsed,
     * links followed, and — with a root — inside it.
     *
     * @throws DirectoryBrowserException
     */
    public function resolve(string $path, ?string $base = null): string
    {
        $path = \trim($path);
        if ($path === '') {
            $path = $base ?? $this->root ?? (\getcwd() ?: '/');
        }
        $path = self::expandHome($path);
        if (!\str_starts_with($path, '/')) {
            $path = \rtrim($base ?? $this->root ?? (\getcwd() ?: '/'), '/') . '/' . $path;
        }
        $lexical = self::collapse($path);
        if ($this->root !== null && !self::lexicallyWithin($lexical, $this->root)) {
            // Outside as spelled — but a link spelling may still land inside
            // (a home reached through a symlink). Either way a refusal reads
            // the same, so it says nothing about what exists out there.
            $real = \realpath($lexical);
            if ($real === false || !\is_dir($real) || !ContainedPath::within($real, $this->root)) {
                throw $this->outside($lexical);
            }

            return $real;
        }

        $real = self::realDirectory($lexical);
        if ($this->root !== null && !ContainedPath::within($real, $this->root)) {
            throw $this->outside($lexical);
        }

        return $real;
    }

    /**
     * The child directories of $path ({@see resolve()}d first).
     *
     * @throws DirectoryBrowserException when $path does not resolve
     */
    public function list(string $path, bool $showHidden = false, ?string $base = null): DirectoryListing
    {
        $real = $this->resolve($path, $base);
        $parent = $this->parentOf($real);
        $project = self::isProjectRoot($real);

        $handle = \is_readable($real) && \is_executable($real) ? @\opendir($real) : false;
        if ($handle === false) {
            return new DirectoryListing($real, $parent, [], false, false, $project);
        }

        $names = [];
        $scanned = 0;
        $cut = false;
        while (($name = \readdir($handle)) !== false) {
            if (++$scanned > self::MAX_SCANNED) {
                $cut = true;
                break;
            }
            if ($name === '.' || $name === '..' || (!$showHidden && \str_starts_with($name, '.'))) {
                continue;
            }
            $full = ($real === '/' ? '' : $real) . '/' . $name;
            if (!\is_dir($full)) {
                continue;
            }
            $names[] = $name;
        }
        \closedir($handle);
        \usort($names, static fn (string $a, string $b): int => \strnatcasecmp($a, $b) ?: \strcmp($a, $b));

        $entries = [];
        foreach ($names as $name) {
            if (\count($entries) >= $this->maxEntries) {
                $cut = true;
                break;
            }
            $full = ($real === '/' ? '' : $real) . '/' . $name;
            $target = \realpath($full);
            if ($target === false || ($this->root !== null && !ContainedPath::within($target, $this->root))) {
                // A dangling link, or one that leads out of the browse root.
                continue;
            }
            $readable = \is_readable($target) && \is_executable($target);
            $entries[] = new DirectoryEntry($name, $target, $readable && self::isProjectRoot($target), $readable);
        }

        return new DirectoryListing($real, $parent, $entries, $cut, true, $project);
    }

    /** $real's parent; null at the browse root and at `/`. */
    public function parentOf(string $real): ?string
    {
        if ($real === '/' || $real === $this->root) {
            return null;
        }

        return \dirname($real);
    }

    /** Whether $dir holds one of {@see PROJECT_MARKERS}. */
    public static function isProjectRoot(string $dir): bool
    {
        foreach (self::PROJECT_MARKERS as $marker) {
            if (\file_exists(\rtrim($dir, '/') . '/' . $marker)) {
                return true;
            }
        }

        return false;
    }

    /** `~` and `~/…` as this user's home; anything else unchanged. */
    private static function expandHome(string $path): string
    {
        if ($path !== '~' && !\str_starts_with($path, '~/')) {
            return $path;
        }
        $home = HomeDirectory::resolved();

        return $home === null ? $path : \rtrim($home, '/') . \substr($path, 1);
    }

    /** An absolute path with `.`, `..` and repeated `/` collapsed, without touching the disk. */
    private static function collapse(string $path): string
    {
        $parts = [];
        foreach (\explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                \array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return '/' . \implode('/', $parts);
    }

    private static function lexicallyWithin(string $path, string $root): bool
    {
        return $path === $root || \str_starts_with($path . '/', \rtrim($root, '/') . '/');
    }

    /** @throws DirectoryBrowserException */
    private static function realDirectory(string $path): string
    {
        $real = \realpath($path);
        if ($real === false) {
            throw new DirectoryBrowserException(DirectoryBrowserException::REASON_NOT_FOUND, Lang::t('dirs.not_found', ['path' => $path]));
        }
        if (!\is_dir($real)) {
            throw new DirectoryBrowserException(DirectoryBrowserException::REASON_NOT_DIRECTORY, Lang::t('dirs.not_directory', ['path' => $path]));
        }

        return $real;
    }

    private function outside(string $path): DirectoryBrowserException
    {
        return new DirectoryBrowserException(
            DirectoryBrowserException::REASON_OUTSIDE_ROOT,
            Lang::t('dirs.outside_root', ['path' => $path, 'root' => (string) $this->root]),
        );
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Hermit\History;

use SugarCraft\Hermit\Item;

/**
 * File-backed persistent history for Hermit items.
 *
 * Stores items as JSON-encoded lines in a flat file, one item per line.
 * Each line encodes the item's number and value.
 */
final class FileHistory
{
    /** @var string */
    private string $path;

    /**
     * @param string      $path    Path to the history file (created lazily on first append()).
     * @param string|null $baseDir When non-null, $path is confined beneath this
     *                             directory: any path that resolves outside it
     *                             (via `..` traversal, an absolute path elsewhere,
     *                             or a symlinked parent) is rejected. Pass null to
     *                             keep the legacy unconfined behaviour.
     *
     * @throws \InvalidArgumentException when $baseDir is set and $path escapes it
     */
    public function __construct(string $path, ?string $baseDir = null)
    {
        $this->path = $baseDir === null ? $path : self::confine($path, $baseDir);
    }

    /**
     * Confine $path beneath $baseDir, rejecting any path that escapes it.
     *
     * The base directory must already exist so it can be resolved with realpath()
     * (collapsing symlinks + `..`). The history file itself need not exist yet —
     * append() creates it — so confinement is enforced against the file's PARENT
     * directory, which must resolve to $baseDir or a directory beneath it. A
     * non-existent parent is rejected: append()'s fopen('a') never creates
     * intermediate directories, so there is no legitimate writable target there.
     *
     * @throws \InvalidArgumentException when $baseDir is missing or $path escapes it
     */
    private static function confine(string $path, string $baseDir): string
    {
        $realBase = \realpath($baseDir);
        if ($realBase === false || !\is_dir($realBase)) {
            throw new \InvalidArgumentException(
                \sprintf('Hermit history base directory does not exist: %s', $baseDir),
            );
        }

        $absolute = self::isAbsolute($path)
            ? $path
            : $realBase . \DIRECTORY_SEPARATOR . $path;

        $realDir = \realpath(\dirname($absolute));
        $prefix  = $realBase . \DIRECTORY_SEPARATOR;
        if ($realDir === false
            || ($realDir !== $realBase && \strncmp($realDir . \DIRECTORY_SEPARATOR, $prefix, \strlen($prefix)) !== 0)) {
            throw new \InvalidArgumentException(
                \sprintf('Hermit history path escapes its base directory %s: %s', $baseDir, $path),
            );
        }

        return $realDir . \DIRECTORY_SEPARATOR . \basename($absolute);
    }

    private static function isAbsolute(string $path): bool
    {
        return $path !== '' && (
            $path[0] === '/'
            || $path[0] === '\\'
            || (\strlen($path) > 1 && \ctype_alpha($path[0]) && $path[1] === ':')
        );
    }

    /**
     * Append an item to the history file.
     */
    public function append(Item $item): void
    {
        $line = \json_encode(['n' => $item->number(), 'v' => $item->value()], \JSON_THROW_ON_ERROR) . "\n";
        $handle = \fopen($this->path, 'a');
        if ($handle === false) {
            return;
        }
        try {
            \flock($handle, \LOCK_EX);
            \fwrite($handle, $line);
            \flock($handle, \LOCK_UN);
        } finally {
            \fclose($handle);
        }
    }

    /**
     * Read all items from the history file.
     *
     * Malformed lines are skipped silently. The file handle is always closed,
     * even if an exception escapes during JSON decoding.
     *
     * @return list<Item>
     */
    public function all(): array
    {
        if (!\is_file($this->path)) {
            return [];
        }

        $handle = \fopen($this->path, 'r');
        if ($handle === false) {
            return [];
        }

        $items = [];
        try {
            \flock($handle, \LOCK_SH);
            while (($line = \fgets($handle)) !== false) {
                $line = \trim($line);
                if ($line === '') {
                    continue;
                }
                try {
                    $decoded = \json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue; // skip malformed line
                }
                $items[] = new \SugarCraft\Hermit\FilteredItem(
                    (int) ($decoded['n'] ?? 0),
                    (string) ($decoded['v'] ?? ''),
                );
            }
            \flock($handle, \LOCK_UN);
        } finally {
            \fclose($handle);
        }

        return $items;
    }

    /**
     * Clear the history file.
     */
    public function clear(): void
    {
        if (\is_file($this->path)) {
            \unlink($this->path);
        }
    }

    /**
     * Get the path to the history file.
     */
    public function path(): string
    {
        return $this->path;
    }
}

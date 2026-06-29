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

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * Append an item to the history file.
     */
    public function append(Item $item): void
    {
        $line = \json_encode(['n' => $item->number(), 'v' => $item->value()], \JSON_THROW_ON_ERROR);
        \file_put_contents($this->path, $line . "\n", \FILE_APPEND | \LOCK_EX);
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

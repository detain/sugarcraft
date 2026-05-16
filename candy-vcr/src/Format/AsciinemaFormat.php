<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Format;

use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;

/**
 * Import asciinema v3 cast files as candy-vcr Cassette objects.
 *
 * Supports the asciinema v3 format which uses:
 * - Header with version, terminal dimensions, timestamp
 * - Event lines: [relative_time, event_type, data]
 * - Event types: 'o' (stdout), 'i' (stdin), 'x' (exit)
 *
 * Usage:
 * ```php
 * $cassette = (new AsciinemaFormat())->read('/path/to/session.cast');
 * $player = new Player($cassette);
 * // replay with candy-vcr
 * ```
 *
 * Note: asciinema stdin events ('i') are stored as raw byte input events.
 * The asciinema format does not preserve message type information.
 */
final class AsciinemaFormat
{
    /**
     * Read an asciinema v3 cast file and return a Cassette.
     *
     * @param string $path Path to .cast file (optionally gzipped)
     * @return Cassette
     * @throws \InvalidArgumentException If the file is not valid asciinema v3
     */
    public function read(string $path): Cassette
    {
        $handle = $this->openFile($path);
        $this->assertFileReadable($path, $handle);

        // Read header line
        $headerLine = fgets($handle);
        if ($headerLine === false) {
            fclose($handle);
            throw new \InvalidArgumentException("asciinema cast file is empty: {$path}");
        }

        $headerData = json_decode($headerLine, true);
        if (!is_array($headerData)) {
            fclose($handle);
            throw new \InvalidArgumentException("asciinema cast file has invalid header: {$path}");
        }

        $version = $headerData['version'] ?? null;
        if ($version !== 3) {
            fclose($handle);
            throw new \InvalidArgumentException(
                "asciinema format version {$version} not supported (only v3): {$path}",
            );
        }

        $cols = $headerData['term']['width'] ?? 80;
        $rows = $headerData['term']['height'] ?? 24;
        $createdAt = isset($headerData['timestamp'])
            ? gmdate('Y-m-d\TH:i:s\Z', (int) $headerData['timestamp'])
            : gmdate('Y-m-d\TH:i:s\Z');

        // Read events
        $events = [];
        $relativeTime = 0.0;

        while (($line = fgets($handle)) !== false) {
            $event = $this->parseLine($line, $relativeTime);
            if ($event !== null) {
                $events[] = $event;
                $relativeTime = $event->t;
            }
        }

        fclose($handle);

        return new Cassette(
            new CassetteHeader(
                version: CassetteHeader::CURRENT_VERSION,
                createdAt: $createdAt,
                cols: (int) $cols,
                rows: (int) $rows,
                runtime: 'asciinema/v3',
            ),
            $events,
        );
    }

    /**
     * Open a file handle, detecting gzip compression by .gz extension.
     *
     * @param string $path
     * @return resource
     */
    private function openFile(string $path)
    {
        if (str_ends_with($path, '.gz')) {
            $handle = @gzopen($path, 'rb');
        } else {
            $handle = @fopen($path, 'rb');
        }
        return $handle;
    }

    /**
     * @param resource $handle
     */
    private function assertFileReadable(string $path, $handle): void
    {
        if ($handle === false) {
            throw new \RuntimeException("Cannot open asciinema cast file: {$path}");
        }
    }

    /**
     * Parse a single asciinema event line.
     *
     * @param string $line JSON array: [relative_time, event_type, data]
     * @param float $previousTime Cumulative time from previous events
     * @return Event|null
     */
    private function parseLine(string $line, float $previousTime): ?Event
    {
        $decoded = json_decode($line, true);
        if (!is_array($decoded) || count($decoded) < 2) {
            return null;
        }

        [$timeOffset, $type, $data] = $decoded;

        // Convert relative time offset to absolute time from cassette start
        $t = $previousTime + (float) $timeOffset;

        return match ($type) {
            'o' => new Event(t: $t, kind: EventKind::Output, payload: ['b' => (string) $data]),
            'i' => new Event(t: $t, kind: EventKind::Input, payload: ['b' => (string) $data]),
            'x' => new Event(t: $t, kind: EventKind::Quit, payload: []),
            default => null, // Unknown event type, skip
        };
    }
}

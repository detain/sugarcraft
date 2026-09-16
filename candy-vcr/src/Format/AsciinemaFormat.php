<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Format;

use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Support\Scalars;

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
            // Non-scalar versions cannot interpolate to text — name their type instead.
            $label = is_scalar($version) || $version === null ? (string) $version : get_debug_type($version);
            throw new \InvalidArgumentException(
                "asciinema format version {$label} not supported (only v3): {$path}",
            );
        }

        // Malformed `term` shapes degrade to defaults exactly as nested `?? 80`/`?? 24` did.
        $term = is_array($headerData['term'] ?? null) ? $headerData['term'] : [];
        $cols = Scalars::int($term['width'] ?? 80, 'asciinema term.width');
        $rows = Scalars::int($term['height'] ?? 24, 'asciinema term.height');
        $createdAt = isset($headerData['timestamp'])
            ? gmdate('Y-m-d\TH:i:s\Z', Scalars::int($headerData['timestamp'], 'asciinema timestamp'))
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
                cols: $cols,
                rows: $rows,
                runtime: 'asciinema/v3',
            ),
            $events,
        );
    }

    /**
     * Open a file handle, detecting gzip compression by .gz extension.
     *
     * Fails loud here so every caller receives a guaranteed `resource` and
     * never has to re-check for `false` (parse, don't validate).
     *
     * @param string $path
     * @return resource
     * @throws \RuntimeException If the file cannot be opened
     */
    private function openFile(string $path)
    {
        if (str_ends_with($path, '.gz')) {
            $handle = @gzopen($path, 'rb');
        } else {
            $handle = @fopen($path, 'rb');
        }
        if ($handle === false) {
            throw new \RuntimeException("Cannot open asciinema cast file: {$path}");
        }
        return $handle;
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

        // Use null coalescing to handle 2-element events (e.g. ["o"] with no data)
        // and 3-element events (e.g. ["o", "data"]) gracefully.
        $timeOffset = $decoded[0] ?? 0.0;
        $type = $decoded[1] ?? '';
        $data = $decoded[2] ?? '';

        // Convert relative time offset to absolute time from cassette start
        $t = $previousTime + Scalars::float($timeOffset, 'asciinema event time');

        return match ($type) {
            'o' => new Event(t: $t, kind: EventKind::Output, payload: ['b' => Scalars::string($data, 'asciinema event data')]),
            'i' => new Event(t: $t, kind: EventKind::Input, payload: ['b' => Scalars::string($data, 'asciinema event data')]),
            'x' => new Event(t: $t, kind: EventKind::Quit, payload: []),
            default => null, // Unknown event type, skip
        };
    }
}

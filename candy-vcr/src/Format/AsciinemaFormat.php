<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Format;

use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Support\Scalars;

/**
 * Import asciinema cast files (`.cast`) as candy-vcr Cassette objects.
 *
 * Both cast shapes on the wire are accepted:
 * - **v2** — what asciinema itself writes: `version: 2` with the terminal
 *   size at the top level (`width` / `height`), plus optional `idle_time_limit`.
 * - **v3** — the nested `term: {width, height}` header variant.
 *
 * Event lines are `[relative_time, event_type, data]` in both versions;
 * `o` (stdout) becomes an Output event, `i` (stdin) an Input event and `x`
 * a Quit. Any other event type emits no event — but its time offset still
 * advances the clock, so a cast carrying resize (`r`) or marker (`m`) lines
 * imports the session bytes with every following event keeping its true
 * place on the tape.
 *
 * Import is one-way: candy-vcr never writes `.cast`, and the Msg envelopes
 * a cassette can carry (`@type`-tagged key/mouse/paste events) have no
 * asciinema counterpart — an imported cast replays as raw bytes only.
 *
 * Usage:
 * ```php
 * $cassette = (new AsciinemaFormat())->read('/path/to/session.cast');
 * $player = new Player($cassette);
 * // replay with candy-vcr
 * ```
 */
final class AsciinemaFormat
{
    /**
     * Read an asciinema cast file and return a Cassette.
     *
     * @param string $path Path to .cast file (optionally gzipped)
     * @return Cassette
     * @throws \InvalidArgumentException If the file is not a valid asciinema v2/v3 cast
     */
    public function read(string $path): Cassette
    {
        $handle = $this->openFile($path);
        try {
            // Read header line
            $headerLine = fgets($handle);
            if ($headerLine === false) {
                throw new \InvalidArgumentException("asciinema cast file is empty: {$path}");
            }

            $headerData = json_decode($headerLine, true);
            if (!is_array($headerData)) {
                throw new \InvalidArgumentException("asciinema cast file has invalid header: {$path}");
            }

            $version = $headerData['version'] ?? null;
            // Cast v2 is what asciinema actually ships; v3 nests the size under
            // `term`. Accepting only v3 rejected every real .cast file.
            if ($version !== 2 && $version !== 3) {
                // Non-scalar versions cannot interpolate to text — name their type instead.
                $label = is_scalar($version) || $version === null ? (string) $version : get_debug_type($version);
                throw new \InvalidArgumentException(
                    "asciinema format version {$label} not supported (only v2 and v3): {$path}",
                );
            }

            // v2 carries width/height at the top level; v3 nests them under `term`.
            // Malformed shapes in either arm degrade to the 80x24 defaults exactly
            // as the previous nested `?? 80`/`?? 24` did.
            $term = is_array($headerData['term'] ?? null) ? $headerData['term'] : [];
            $cols = Scalars::int($headerData['width'] ?? $term['width'] ?? 80, 'asciinema terminal width');
            $rows = Scalars::int($headerData['height'] ?? $term['height'] ?? 24, 'asciinema terminal height');
            $createdAt = isset($headerData['timestamp'])
                ? gmdate('Y-m-d\TH:i:s\Z', Scalars::int($headerData['timestamp'], 'asciinema timestamp'))
                : gmdate('Y-m-d\TH:i:s\Z');

            // Read events
            $events = [];
            $relativeTime = 0.0;

            while (($line = fgets($handle)) !== false) {
                $decoded = json_decode($line, true);
                if (!is_array($decoded) || count($decoded) < 2) {
                    continue;
                }
                // Parse -> add offset -> THEN decide emit: a timestamped row
                // consumed wall time even when its type is one this reader
                // skips ('r', 'm', unknown). Advancing the clock only on
                // emitted events erased each skipped row's delta and reset
                // the tape for everything after it (finding 063).
                $relativeTime += Scalars::float($decoded[0], 'asciinema event time');
                $event = $this->parseEvent($decoded, $relativeTime);
                if ($event !== null) {
                    $events[] = $event;
                }
            }

            return new Cassette(
                new CassetteHeader(
                    version: CassetteHeader::CURRENT_VERSION,
                    createdAt: $createdAt,
                    cols: $cols,
                    rows: $rows,
                    runtime: 'asciinema/v' . (string) $version,
                ),
                $events,
            );
        } finally {
            // A mid-file throw (bad header field, unparseable time offset,
            // over-ceiling event) used to leak the handle: repeated failed
            // loads stacked descriptors (finding 036).
            fclose($handle);
        }
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
            throw new \RuntimeException("cannot open asciinema cast file: {$path}");
        }
        return $handle;
    }

    /**
     * Build the cassette event for one decoded cast line, or null when the
     * type is skipped. The caller has already folded the line's offset into
     * the clock — skipped rows keep their wall time.
     *
     * @param array<array-key, mixed> $decoded JSON array: [relative_time, event_type, data]
     */
    private function parseEvent(array $decoded, float $t): ?Event
    {
        // Use null coalescing to handle 2-element events (e.g. ["x"] with no data)
        // and 3-element events (e.g. ["o", "data"]) gracefully.
        $type = $decoded[1];
        $data = $decoded[2] ?? '';

        return match ($type) {
            'o' => new Event(t: $t, kind: EventKind::Output, payload: ['b' => Scalars::string($data, 'asciinema event data')]),
            'i' => new Event(t: $t, kind: EventKind::Input, payload: ['b' => Scalars::string($data, 'asciinema event data')]),
            'x' => new Event(t: $t, kind: EventKind::Quit, payload: []),
            default => null, // 'r'/'m'/unknown types: their time counts, no event is emitted
        };
    }
}

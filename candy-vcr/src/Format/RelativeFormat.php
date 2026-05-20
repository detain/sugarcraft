<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Format;

use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;

/**
 * JSONL cassette serializer that uses relative/delta timestamps (dt) instead
 * of absolute timestamps (t). Each event's dt value is the interval in seconds
 * since the previous event; the first event always has dt=0.
 *
 * This format is useful for:
 * - Deterministic replay (recordings independent of when they were made)
 * - Easier manual editing of cassettes (intervals are more intuitive than absolute times)
 * - Similar to asciinema v3 format which also uses relative timestamps
 *
 * Mirrors charmbracelet/x/vcr Format/RelativeFormat.
 */
final class RelativeFormat implements Format
{
    private const T_PRECISION = 3;

    public function write(Cassette $cassette, string $path): void
    {
        $contents = $this->encode($cassette);
        if (@file_put_contents($path, $contents) === false) {
            throw new \RuntimeException("candy-vcr: cannot write cassette to {$path}");
        }
    }

    public function read(string $path): Cassette
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("candy-vcr: cannot read cassette from {$path}");
        }
        return $this->decode($raw);
    }

    public function encode(Cassette $cassette): string
    {
        $lines = [$this->encodeHeader($cassette->header)];
        foreach ($this->encodeEvents($cassette->events) as $eventLine) {
            $lines[] = $eventLine;
        }
        return implode("\n", $lines) . "\n";
    }

    public function decode(string $contents): Cassette
    {
        $lines = preg_split('/\r?\n/', $contents) ?: [];
        $header = null;
        $eventsText = [];

        foreach ($lines as $i => $line) {
            if ($line === '') {
                continue;
            }
            $data = json_decode($line, true);
            if (!is_array($data)) {
                $lineNo = $i + 1;
                throw new \RuntimeException("candy-vcr: invalid JSON on line {$lineNo}");
            }
            if ($header === null) {
                $header = $this->decodeHeader($data, $i + 1);
                continue;
            }
            $eventsText[] = $data;
        }

        if ($header === null) {
            throw new \RuntimeException('candy-vcr: cassette is empty (no header line)');
        }

        $events = $this->decodeEvents($eventsText);

        return new Cassette($header, $events);
    }

    /**
     * Encode events as delta-time (dt) lines.
     *
     * @param list<Event> $events
     * @return list<string>
     */
    private function encodeEvents(array $events): array
    {
        if (empty($events)) {
            return [];
        }

        $lines = [];
        $prevT = 0.0;

        foreach ($events as $event) {
            $dt = round($event->t - $prevT, self::T_PRECISION);
            $line = [
                'dt' => $dt,
                'k' => $event->kind->value,
                ...$event->payload,
            ];
            $lines[] = $this->jsonEncode($line);
            $prevT = $event->t;
        }

        return $lines;
    }

    /**
     * Decode dt lines back to absolute timestamps.
     *
     * @param list<array<string, mixed>> $eventsData
     * @return list<Event>
     */
    private function decodeEvents(array $eventsData): array
    {
        if (empty($eventsData)) {
            return [];
        }

        $events = [];
        $cumulative = 0.0;

        foreach ($eventsData as $i => $data) {
            $lineNo = $i + 2; // +2: header is line 1, first event is line 2
            $event = $this->decodeEvent($data, $lineNo, $cumulative);
            $events[] = $event;
            $cumulative = $event->t;
        }

        return $events;
    }

    private function encodeHeader(CassetteHeader $h): string
    {
        $data = [
            'v' => $h->version,
            'created' => $h->createdAt,
            'cols' => $h->cols,
            'rows' => $h->rows,
            'runtime' => $h->runtime,
            'timestampMode' => CassetteHeader::TIMESTAMP_MODE_RELATIVE,
        ];
        if ($h->env !== []) {
            $data['env'] = $h->env;
        }
        return $this->jsonEncode($data);
    }

    /** @param array<string, mixed> $data */
    private function decodeHeader(array $data, int $lineNo): CassetteHeader
    {
        if (!isset($data['v'])) {
            throw new \RuntimeException("candy-vcr: header on line {$lineNo} missing 'v'");
        }
        foreach (['created', 'cols', 'rows', 'runtime'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new \RuntimeException("candy-vcr: header on line {$lineNo} missing '{$key}'");
            }
        }
        $env = [];
        if (isset($data['env']) && \is_array($data['env'])) {
            foreach ($data['env'] as $k => $v) {
                if (\is_string($k) && \is_string($v)) {
                    $env[$k] = $v;
                }
            }
        }
        return new CassetteHeader(
            version: (int) $data['v'],
            createdAt: (string) $data['created'],
            cols: (int) $data['cols'],
            rows: (int) $data['rows'],
            runtime: (string) $data['runtime'],
            timestampMode: CassetteHeader::TIMESTAMP_MODE_RELATIVE,
            env: $env,
        );
    }

    /**
     * Decode one event line, converting dt to absolute t.
     *
     * @param array<string, mixed> $data
     */
    private function decodeEvent(array $data, int $lineNo, float $cumulativeBase): Event
    {
        if (!array_key_exists('dt', $data) || !array_key_exists('k', $data)) {
            throw new \RuntimeException("candy-vcr: event on line {$lineNo} missing 'dt' or 'k'");
        }
        $kind = EventKind::tryFrom((string) $data['k']);
        if ($kind === null) {
            $bad = (string) $data['k'];
            throw new \RuntimeException("candy-vcr: event on line {$lineNo} has unknown kind '{$bad}'");
        }
        $dt = (float) $data['dt'];
        $absoluteT = round($cumulativeBase + $dt, self::T_PRECISION);
        unset($data['dt'], $data['k']);
        return new Event(t: $absoluteT, kind: $kind, payload: $data);
    }

    /** @param array<string, mixed> $data */
    private function jsonEncode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('candy-vcr: json_encode failed: ' . json_last_error_msg());
        }
        return $json;
    }
}

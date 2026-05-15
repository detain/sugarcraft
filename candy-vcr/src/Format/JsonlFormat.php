<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Format;

use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;

/**
 * JSONL cassette serializer — one JSON document per line. The first line is
 * the header (`{"v":1,"created":...,"cols":...,"rows":...,"runtime":...}`);
 * subsequent lines are events whose shape depends on `k`.
 *
 * Output bytes are passed through JSON's string encoder, which escapes
 * non-printable bytes as `\u00xx`. Reading reverses this faithfully so
 * arbitrary 8-bit output payloads round-trip.
 *
 * Supports two timestamp modes:
 * - `absolute` (default): timestamps are seconds since cassette start.
 * - `relative`: timestamps are intervals since the previous event, making
 *   cassettes easier to edit manually (like asciinema v3 format).
 *
 * Mirrors charmbracelet/x/vcr Format/Jsonl.
 */
final class JsonlFormat implements Format
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
        $events = $cassette->events;
        if ($cassette->header->timestampMode === CassetteHeader::TIMESTAMP_MODE_RELATIVE) {
            $events = $this->toRelativeTimestamps($events);
        }
        foreach ($events as $event) {
            $lines[] = $this->encodeEvent($event);
        }
        return implode("\n", $lines) . "\n";
    }

    public function decode(string $contents): Cassette
    {
        $lines = preg_split('/\r?\n/', $contents) ?: [];
        $header = null;
        $events = [];

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
            $events[] = $this->decodeEvent($data, $i + 1);
        }

        if ($header === null) {
            throw new \RuntimeException('candy-vcr: cassette is empty (no header line)');
        }

        if ($header->timestampMode === CassetteHeader::TIMESTAMP_MODE_RELATIVE) {
            $events = $this->fromRelativeTimestamps($events);
        }

        return new Cassette($header, $events);
    }

    /**
     * Convert absolute timestamps to relative (interval since last event).
     * First event gets interval 0.0 since there's no prior event.
     *
     * @param list<Event> $events
     * @return list<Event>
     */
    private function toRelativeTimestamps(array $events): array
    {
        if (empty($events)) {
            return $events;
        }
        $result = [];
        $prevT = 0.0;
        foreach ($events as $event) {
            $interval = round($event->t - $prevT, self::T_PRECISION);
            $result[] = new Event(t: $interval, kind: $event->kind, payload: $event->payload);
            $prevT = $event->t;
        }
        return $result;
    }

    /**
     * Convert relative timestamps back to absolute (cumulative sum).
     *
     * @param list<Event> $events
     * @return list<Event>
     */
    private function fromRelativeTimestamps(array $events): array
    {
        if (empty($events)) {
            return $events;
        }
        $result = [];
        $cumulative = 0.0;
        foreach ($events as $event) {
            $cumulative += $event->t;
            $result[] = new Event(t: round($cumulative, self::T_PRECISION), kind: $event->kind, payload: $event->payload);
        }
        return $result;
    }

    private function encodeHeader(CassetteHeader $h): string
    {
        $data = [
            'v' => $h->version,
            'created' => $h->createdAt,
            'cols' => $h->cols,
            'rows' => $h->rows,
            'runtime' => $h->runtime,
        ];
        if ($h->timestampMode !== CassetteHeader::TIMESTAMP_MODE_ABSOLUTE) {
            $data['timestampMode'] = $h->timestampMode;
        }
        return $this->jsonEncode($data);
    }

    private function encodeEvent(Event $event): string
    {
        $payload = $this->jsonEncode([
            't' => round($event->t, self::T_PRECISION),
            'k' => $event->kind->value,
            ...$event->payload,
        ]);
        return $payload;
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
        return new CassetteHeader(
            version: (int) $data['v'],
            createdAt: (string) $data['created'],
            cols: (int) $data['cols'],
            rows: (int) $data['rows'],
            runtime: (string) $data['runtime'],
            timestampMode: isset($data['timestampMode']) ? (string) $data['timestampMode'] : CassetteHeader::TIMESTAMP_MODE_ABSOLUTE,
        );
    }

    /** @param array<string, mixed> $data */
    private function decodeEvent(array $data, int $lineNo): Event
    {
        if (!array_key_exists('t', $data) || !array_key_exists('k', $data)) {
            throw new \RuntimeException("candy-vcr: event on line {$lineNo} missing 't' or 'k'");
        }
        $kind = EventKind::tryFrom((string) $data['k']);
        if ($kind === null) {
            $bad = (string) $data['k'];
            throw new \RuntimeException("candy-vcr: event on line {$lineNo} has unknown kind '{$bad}'");
        }
        $t = (float) $data['t'];
        unset($data['t'], $data['k']);
        return new Event(t: $t, kind: $kind, payload: $data);
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

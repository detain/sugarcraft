<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Format;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;

/**
 * Human-readable YAML cassette format. JSONL is the primary on-disk
 * format (`Recorder` writes JSONL); YAML is intended for hand-written
 * test fixtures and edits where readability matters more than
 * line-streaming.
 *
 * Layout:
 * ```yaml
 * header:
 *     v: 1
 *     created: '2026-05-08T12:00:00Z'
 *     cols: 80
 *     rows: 24
 *     runtime: 'sugarcraft/candy-vcr@dev'
 * events:
 *     - { t: 0.001, k: output, b: "[?2027h" }
 *     - { t: 0.002, k: resize, cols: 80, rows: 24 }
 *     - { t: 1.500, k: quit }
 * ```
 *
 * Round-trips with {@see JsonlFormat} via the shared `Cassette` value
 * object — modulo `t` rounding (both formats round to ms precision).
 *
 * Requires `symfony/yaml`. If it's not installed, fall back to
 * {@see JsonlFormat}.
 */
final class YamlFormat implements Format
{
    private const T_PRECISION = 3;

    public function __construct()
    {
        if (!class_exists(Yaml::class)) {
            throw new \RuntimeException(
                'YamlFormat requires symfony/yaml — install it or use JsonlFormat.',
            );
        }
    }

    public function write(Cassette $cassette, string $path): void
    {
        $yaml = $this->encode($cassette);
        if (@file_put_contents($path, $yaml) === false) {
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
        $eventsArray = [];
        foreach ($cassette->events as $event) {
            $row = ['t' => round($event->t, self::T_PRECISION), 'k' => $event->kind->value];
            foreach ($event->payload as $key => $value) {
                $row[$key] = $value;
            }
            $eventsArray[] = $row;
        }
        $tree = [
            'header' => [
                'v' => $cassette->header->version,
                'created' => $cassette->header->createdAt,
                'cols' => $cassette->header->cols,
                'rows' => $cassette->header->rows,
                'runtime' => $cassette->header->runtime,
            ],
            'events' => $eventsArray,
        ];
        // Inline level 3 keeps the per-event maps on one line each
        // (compact, diff-friendly) while the top-level header / events
        // keys stay on their own lines.
        return Yaml::dump($tree, inline: 3, indent: 4);
    }

    public function decode(string $contents): Cassette
    {
        try {
            $tree = Yaml::parse($contents);
        } catch (ParseException $e) {
            throw new \RuntimeException('candy-vcr: invalid YAML — ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($tree) || !isset($tree['header']) || !is_array($tree['header'])) {
            throw new \RuntimeException("candy-vcr: YAML cassette is missing 'header'");
        }
        $header = $this->decodeHeader($tree['header']);

        $eventsRaw = $tree['events'] ?? [];
        if (!is_array($eventsRaw)) {
            throw new \RuntimeException("candy-vcr: YAML cassette 'events' must be a list");
        }
        $events = [];
        foreach ($eventsRaw as $i => $row) {
            if (!is_array($row)) {
                $line = $i + 1;
                throw new \RuntimeException("candy-vcr: YAML cassette event #{$line} is not a map");
            }
            $events[] = $this->decodeEvent($row, $i + 1);
        }

        return new Cassette($header, $events);
    }

    /** @param array<string, mixed> $data */
    private function decodeHeader(array $data): CassetteHeader
    {
        if (!isset($data['v'])) {
            throw new \RuntimeException("candy-vcr: YAML header missing 'v'");
        }
        foreach (['created', 'cols', 'rows', 'runtime'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new \RuntimeException("candy-vcr: YAML header missing '{$key}'");
            }
        }
        return new CassetteHeader(
            version: (int) $data['v'],
            createdAt: (string) $data['created'],
            cols: (int) $data['cols'],
            rows: (int) $data['rows'],
            runtime: (string) $data['runtime'],
        );
    }

    /** @param array<string, mixed> $data */
    private function decodeEvent(array $data, int $eventNo): Event
    {
        if (!array_key_exists('t', $data) || !array_key_exists('k', $data)) {
            throw new \RuntimeException("candy-vcr: YAML event #{$eventNo} missing 't' or 'k'");
        }
        $kind = EventKind::tryFrom((string) $data['k']);
        if ($kind === null) {
            $bad = (string) $data['k'];
            throw new \RuntimeException("candy-vcr: YAML event #{$eventNo} has unknown kind '{$bad}'");
        }
        $t = (float) $data['t'];
        unset($data['t'], $data['k']);
        return new Event(t: $t, kind: $kind, payload: $data);
    }
}

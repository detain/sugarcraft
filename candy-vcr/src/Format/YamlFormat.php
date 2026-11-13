<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Format;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Support\ObjectMap;
use SugarCraft\Vcr\Support\Scalars;

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
        try {
            // Atomic placement + 0600 before content (finding 018).
            AtomicFileWriter::write($path, $yaml, 'cassette');
        } catch (\RuntimeException $e) {
            throw new \RuntimeException("cannot write cassette to {$path}: {$e->getMessage()}", previous: $e);
        }
    }

    public function read(string $path): Cassette
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("cannot read cassette from {$path}");
        }
        return $this->decode($raw);
    }

    public function encode(Cassette $cassette): string
    {
        $eventsArray = [];
        foreach ($cassette->events as $event) {
            // Reserved keys win and stay first (JsonlFormat contract): a
            // payload carrying literal `t`/`k` entries is shadowed.
            $row = ['t' => round($event->t, self::T_PRECISION), 'k' => $event->kind->value] + $event->payload;
            $eventsArray[] = $row;
        }

        $tree = [
            'header' => $cassette->header->toWireArray(),
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
            throw new \RuntimeException('invalid YAML — ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($tree) || !isset($tree['header']) || !is_array($tree['header'])) {
            throw new \RuntimeException("YAML cassette is missing 'header'");
        }
        $header = $this->decodeHeader($tree['header']);

        $eventsRaw = $tree['events'] ?? [];
        if (!is_array($eventsRaw)) {
            throw new \RuntimeException("YAML cassette 'events' must be a list");
        }
        $events = [];
        foreach ($eventsRaw as $i => $row) {
            if (!is_array($row)) {
                $line = $i + 1;
                throw new \RuntimeException("YAML cassette event #{$line} is not a map");
            }
            $events[] = $this->decodeEvent($row, $i + 1);
        }

        return new Cassette($header, $events);
    }

    /** @param array<array-key, mixed> $data */
    private function decodeHeader(array $data): CassetteHeader
    {
        if (!isset($data['v'])) {
            throw new \RuntimeException("YAML header missing 'v'");
        }
        foreach (['created', 'cols', 'rows', 'runtime'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new \RuntimeException("YAML header missing '{$key}'");
            }
        }
        $envRaw = $data['env'] ?? [];
        if (!\is_array($envRaw)) {
            throw new \RuntimeException("YAML header 'env' must be a map");
        }
        $env = [];
        foreach ($envRaw as $k => $v) {
            if (!\is_string($k) || !\is_string($v)) {
                throw new \InvalidArgumentException('YAML header env must map strings to strings');
            }
            $env[$k] = $v;
        }

        return new CassetteHeader(
            version: Scalars::int($data['v'], 'YAML header v'),
            createdAt: Scalars::string($data['created'], 'YAML header created'),
            cols: Scalars::int($data['cols'], 'YAML header cols'),
            rows: Scalars::int($data['rows'], 'YAML header rows'),
            runtime: Scalars::string($data['runtime'], 'YAML header runtime'),
            timestampMode: self::timestampModeOf($data['timestampMode'] ?? CassetteHeader::TIMESTAMP_MODE_ABSOLUTE),
            env: $env,
            typingSpeed: isset($data['typingSpeed']) ? Scalars::float($data['typingSpeed'], 'YAML header typingSpeed') : null,
            theme: isset($data['theme']) ? Scalars::string($data['theme'], 'YAML header theme') : null,
            playbackSpeed: isset($data['playbackSpeed']) ? Scalars::float($data['playbackSpeed'], 'YAML header playbackSpeed') : null,
            fontSize: isset($data['fontSize']) ? Scalars::int($data['fontSize'], 'YAML header fontSize') : null,
            fontFamily: isset($data['fontFamily']) ? Scalars::string($data['fontFamily'], 'YAML header fontFamily') : null,
            widthPx: isset($data['widthPx']) ? Scalars::int($data['widthPx'], 'YAML header widthPx') : null,
            heightPx: isset($data['heightPx']) ? Scalars::int($data['heightPx'], 'YAML header heightPx') : null,
        );
    }

    /**
     * Parse the timestampMode discriminator at the trust boundary so the
     * literal-union type reaches CassetteHeader intact; the header ctor
     * re-validates independently.
     *
     * @return 'absolute'|'relative'
     */
    private static function timestampModeOf(mixed $value): string
    {
        $mode = Scalars::string($value, 'YAML header timestampMode');
        if ($mode === CassetteHeader::TIMESTAMP_MODE_ABSOLUTE || $mode === CassetteHeader::TIMESTAMP_MODE_RELATIVE) {
            return $mode;
        }
        throw new \InvalidArgumentException(
            "CassetteHeader timestampMode must be 'absolute' or 'relative', got '{$mode}'",
        );
    }

    /** @param array<array-key, mixed> $data */
    private function decodeEvent(array $data, int $eventNo): Event
    {
        if (!array_key_exists('t', $data) || !array_key_exists('k', $data)) {
            throw new \RuntimeException("YAML event #{$eventNo} missing 't' or 'k'");
        }
        $kind = EventKind::tryFrom(Scalars::string($data['k'], 'YAML event k'));
        if ($kind === null) {
            $bad = Scalars::string($data['k'], 'YAML event k');
            throw new \RuntimeException("YAML event #{$eventNo} has unknown kind '{$bad}'");
        }
        $t = Scalars::seconds($data['t'], "YAML event t #{$eventNo}");
        unset($data['t'], $data['k']);

        return new Event(t: $t, kind: $kind, payload: ObjectMap::of($data, "YAML event #{$eventNo} payload"));
    }
}

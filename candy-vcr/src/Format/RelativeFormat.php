<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Format;

use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Support\LineIo;
use SugarCraft\Vcr\Support\ObjectMap;
use SugarCraft\Vcr\Support\Scalars;

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
        try {
            // Atomic placement + 0600 before content (finding 018), same as
            // JsonlFormat; see AtomicFileWriter for the full contract.
            // Lines stream one at a time (finding 081).
            AtomicFileWriter::writeStream($path, function ($handle) use ($cassette, $path): void {
                foreach ($this->encodeLines($cassette) as $line) {
                    if (@fwrite($handle, $line . "\n") === false) {
                        throw new \RuntimeException("cannot write cassette bytes to temporary file for {$path}");
                    }
                }
            }, 'cassette');
        } catch (\RuntimeException $e) {
            throw new \RuntimeException("cannot write cassette to {$path}: {$e->getMessage()}", previous: $e);
        }
    }

    public function read(string $path): Cassette
    {
        return $this->decodeTuples(LineIo::lines($path, "cannot read cassette from {$path}"));
    }

    public function encode(Cassette $cassette): string
    {
        $contents = '';
        foreach ($this->encodeLines($cassette) as $line) {
            $contents .= $line . "\n";
        }
        return $contents;
    }

    /**
     * One dt line per frame (header first), newline NOT included.
     *
     * @return \Generator<int, string>
     */
    public function encodeLines(Cassette $cassette): \Generator
    {
        yield $this->encodeHeader($cassette->header);
        yield from $this->encodeEvents($cassette->events);
    }

    public function decode(string $contents): Cassette
    {
        return $this->decodeTuples(self::tuplesFromString($contents));
    }

    /**
     * Assemble a Cassette from `[lineNumber, text, isTornLast]` tuples.
     *
     * Torn-tail contract identical to JsonlFormat: a final line cut by
     * a kill -9 (file has no trailing newline) is dropped, everything
     * before it decodes; malformed anywhere else still throws.
     *
     * @param \Traversable<int, array{0: int, 1: string, 2: bool}> $lines
     */
    public function decodeTuples(\Traversable $lines): Cassette
    {
        $header = null;
        $eventsText = [];

        foreach ($lines as [$lineNo, $line, $isTornLast]) {
            if ($line === '') {
                continue;
            }
            $data = json_decode($line, true);
            if (!is_array($data)) {
                if ($isTornLast && $header !== null) {
                    continue;
                }
                throw new \RuntimeException("invalid JSON on line {$lineNo}");
            }
            if ($header === null) {
                $header = $this->decodeHeader($data, $lineNo);
                continue;
            }
            $eventsText[] = $data;
        }

        if ($header === null) {
            throw new \RuntimeException('cassette is empty (no header line)');
        }

        $events = $this->decodeEvents($eventsText);

        return new Cassette($header, $events);
    }

    /**
     * @return \Generator<int, array{0: int, 1: string, 2: bool}>
     */
    private static function tuplesFromString(string $contents): \Generator
    {
        $lines = preg_split('/\r?\n/', $contents) ?: [];
        $lastLineIsTorn = $contents !== '' && !str_ends_with($contents, "\n");
        $lastIndex = count($lines) - 1;
        foreach ($lines as $i => $line) {
            yield [$i + 1, $line, $lastLineIsTorn && $i === $lastIndex];
        }
    }

    /**
     * Encode events as delta-time (dt) lines.
     *
     * @param list<Event> $events
     * @return \Generator<int, string>
     */
    private function encodeEvents(array $events): \Generator
    {
        if (empty($events)) {
            return;
        }

        $prevT = 0.0;

        foreach ($events as $event) {
            $dt = round($event->t - $prevT, self::T_PRECISION);
            // Reserved keys win and stay first (see JsonlFormat's contract);
            // a payload carrying literal `dt`/`k` entries is shadowed.
            yield $this->jsonEncode(['dt' => $dt, 'k' => $event->kind->value] + $event->payload);
            $prevT = $event->t;
        }
    }

    /**
     * Decode dt lines back to absolute timestamps.
     *
     * @param list<array<array-key, mixed>> $eventsData
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
        $data = $h->toWireArray();
        // The relative encoder always stamps relative mode; the wire shape
        // otherwise mirrors CassetteHeader's full field set.
        $data['timestampMode'] = CassetteHeader::TIMESTAMP_MODE_RELATIVE;
        return $this->jsonEncode($data);
    }

    /** @param array<array-key, mixed> $data */
    private function decodeHeader(array $data, int $lineNo): CassetteHeader
    {
        if (!isset($data['v'])) {
            throw new \RuntimeException("header on line {$lineNo} missing 'v'");
        }
        foreach (['created', 'cols', 'rows', 'runtime'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new \RuntimeException("header on line {$lineNo} missing '{$key}'");
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
        $typingSpeed = null;
        if (isset($data['typingSpeed']) && (is_int($data['typingSpeed']) || is_float($data['typingSpeed']))) {
            $typingSpeed = (float) $data['typingSpeed'];
        }
        $theme = null;
        if (isset($data['theme']) && \is_string($data['theme']) && $data['theme'] !== '') {
            $theme = $data['theme'];
        }
        $playbackSpeed = null;
        if (isset($data['playbackSpeed']) && (is_int($data['playbackSpeed']) || is_float($data['playbackSpeed']))) {
            $playbackSpeed = (float) $data['playbackSpeed'];
        }
        $fontSize = null;
        if (isset($data['fontSize']) && (is_int($data['fontSize']) || is_float($data['fontSize']))) {
            $fontSize = (int) $data['fontSize'];
        }
        $fontFamily = null;
        if (isset($data['fontFamily']) && \is_string($data['fontFamily']) && $data['fontFamily'] !== '') {
            $fontFamily = $data['fontFamily'];
        }
        $widthPx = null;
        if (isset($data['widthPx']) && (is_int($data['widthPx']) || is_float($data['widthPx']))) {
            $widthPx = (int) $data['widthPx'];
        }
        $heightPx = null;
        if (isset($data['heightPx']) && (is_int($data['heightPx']) || is_float($data['heightPx']))) {
            $heightPx = (int) $data['heightPx'];
        }
        return new CassetteHeader(
            version: Scalars::int($data['v'], 'header v'),
            createdAt: Scalars::string($data['created'], 'header created'),
            cols: Scalars::int($data['cols'], 'header cols'),
            rows: Scalars::int($data['rows'], 'header rows'),
            runtime: Scalars::string($data['runtime'], 'header runtime'),
            // This reader IS the relative mode: whatever the header says,
            // events are decoded dt-cumulative, so the model must match.
            timestampMode: CassetteHeader::TIMESTAMP_MODE_RELATIVE,
            env: $env,
            typingSpeed: $typingSpeed,
            theme: $theme,
            playbackSpeed: $playbackSpeed,
            fontSize: $fontSize,
            fontFamily: $fontFamily,
            widthPx: $widthPx,
            heightPx: $heightPx,
        );
    }

    /**
     * Decode one event line, converting dt to absolute t.
     *
     * @param array<array-key, mixed> $data
     */
    private function decodeEvent(array $data, int $lineNo, float $cumulativeBase): Event
    {
        if (!array_key_exists('dt', $data) || !array_key_exists('k', $data)) {
            throw new \RuntimeException("event on line {$lineNo} missing 'dt' or 'k'");
        }
        $kind = EventKind::tryFrom(Scalars::string($data['k'], 'event k'));
        if ($kind === null) {
            $bad = Scalars::string($data['k'], 'event k');
            throw new \RuntimeException("event on line {$lineNo} has unknown kind '{$bad}'");
        }
        $dt = Scalars::seconds($data['dt'], "event dt on line {$lineNo}");
        $absoluteT = round($cumulativeBase + $dt, self::T_PRECISION);
        // The cumulative sum is the real blast radius of a stack of legal-
        // looking deltas: no single dt breaches the door, yet the running
        // total slides off the edge of the tape (finding 001 door leg).
        if ($absoluteT > Event::MAX_TIMESTAMP_SECONDS) {
            throw new \RuntimeException(
                "event dt on line {$lineNo} pushes the tape past the 24h ceiling ({$absoluteT}s)"
            );
        }
        unset($data['dt'], $data['k']);

        return new Event(t: $absoluteT, kind: $kind, payload: ObjectMap::of($data, "event on line {$lineNo} payload"));
    }

    /**
     * Encode one JSONL line. The substitute flag is load-bearing: a
     * cassette payload can carry bytes a strict encoder would reject, and a
     * throw here would abort a rewrite of an already-recorded session.
     *
     * @param array<array-key, mixed> $data
     */
    private function jsonEncode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new \RuntimeException('json_encode failed: ' . json_last_error_msg());
        }
        return $json;
    }
}

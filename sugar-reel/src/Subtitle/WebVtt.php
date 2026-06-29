<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Subtitle;

/**
 * A parsed WebVTT subtitle track — a time-sorted list of {@see Cue}s with an
 * O(log n) `cueAt()` lookup for the caption showing at a given playback time.
 *
 * Parsing is lenient: it skips the `WEBVTT` header and any `NOTE`/`STYLE`/
 * `REGION` blocks, ignores per-cue identifiers and cue settings, strips inline
 * tags (`<i>`, `<c.foo>`, …) and decodes basic entities. It also tolerates
 * SRT-style input (numeric index lines and `,` millisecond separators), so the
 * same parser covers both formats a media server might hand back.
 */
final class WebVtt
{
    /**
     * @param list<Cue> $cues
     */
    private function __construct(
        private readonly array $cues,
    ) {
    }

    /**
     * @return list<Cue>
     */
    public function cues(): array
    {
        return $this->cues;
    }

    public function isEmpty(): bool
    {
        return $this->cues === [];
    }

    /**
     * Parse WebVTT (or SRT) text into a track.
     */
    public static function parse(string $text): self
    {
        // Strip a UTF-8 BOM and normalise line endings.
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $cues = [];
        foreach (preg_split('/\n[ \t]*\n/', $text) ?: [] as $block) {
            $cue = self::parseBlock($block);
            if ($cue !== null) {
                $cues[] = $cue;
            }
        }

        // Sort by start time ascending so binary search is valid and the
        // "last matching cue wins" semantics are well-defined for overlapping cues.
        usort($cues, static fn(Cue $a, Cue $b): int => $a->start <=> $b->start);

        return new self($cues);
    }

    /**
     * The caption text showing at $seconds, or null when nothing is.
     * Uses binary search over the sorted cue list (O(log n)).
     * When multiple overlapping cues contain $seconds, the one with the
     * latest start time is returned (first matching cue from the end).
     */
    public function cueAt(float $seconds): ?string
    {
        $cues = $this->cues;
        $lo = 0;
        $hi = count($cues) - 1;
        $result = null;

        // Binary search for the rightmost cue with start <= $seconds.
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($cues[$mid]->start <= $seconds) {
                $result = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        // Walk back to find the first cue that actually contains $seconds.
        // This handles gaps between cues and overlapping cue tie-breaking.
        while ($result !== null && $result >= 0) {
            if ($cues[$result]->contains($seconds)) {
                return $cues[$result]->text;
            }
            $result--;
        }

        return null;
    }

    /**
     * Parse one block into a Cue, or null if it isn't a cue (header / NOTE /
     * STYLE / REGION / malformed).
     */
    private static function parseBlock(string $block): ?Cue
    {
        $lines = explode("\n", trim($block));
        if ($lines[0] === '') {
            return null;
        }

        // A NOTE comment may contain "-->" in its free text, so it must be
        // skipped BEFORE the timing search. WEBVTT headers and STYLE/REGION
        // blocks have no timing line and are dropped by the no-timing check
        // below — so a real cue whose *identifier* merely starts with one of
        // those words (e.g. "REGION 5", "WEBVTT-ish", "STYLE note") still parses.
        $first = strtoupper(trim($lines[0]));
        if ($first === 'NOTE' || str_starts_with($first, 'NOTE ') || str_starts_with($first, "NOTE\t")) {
            return null;
        }

        // Find the timing line ("start --> end [settings]"); text follows it.
        $timingIndex = null;
        foreach ($lines as $i => $line) {
            if (str_contains($line, '-->')) {
                $timingIndex = $i;
                break;
            }
        }
        if ($timingIndex === null) {
            return null;
        }

        [$start, $end] = self::parseTiming($lines[$timingIndex]);
        if ($start === null || $end === null) {
            return null;
        }

        $textLines = array_slice($lines, $timingIndex + 1);
        $text = self::cleanText(implode("\n", $textLines));
        if ($text === '') {
            return null;
        }

        return new Cue($start, $end, $text);
    }

    /**
     * @return array{0: float|null, 1: float|null} [start, end] in seconds
     */
    private static function parseTiming(string $line): array
    {
        $arrow = strpos($line, '-->');
        if ($arrow === false) {
            return [null, null];
        }

        $start = self::parseTimestamp(substr($line, 0, $arrow));
        // The end timestamp is the first token after the arrow; cue settings
        // (align:, position:, …) trail it and are ignored.
        $rest = trim(substr($line, $arrow + 3));
        $endToken = strtok($rest, " \t");
        $end = $endToken === false ? null : self::parseTimestamp($endToken);

        return [$start, $end];
    }

    /** Parse "HH:MM:SS.mmm" / "MM:SS.mmm" (or SRT "," separators) to seconds. */
    private static function parseTimestamp(string $ts): ?float
    {
        $ts = trim(str_replace(',', '.', $ts));
        if ($ts === '') {
            return null;
        }
        $parts = explode(':', $ts);
        $count = count($parts);
        if ($count === 3) {
            [$h, $m, $s] = $parts;
        } elseif ($count === 2) {
            $h = '0';
            [$m, $s] = $parts;
        } else {
            return null;
        }
        if (!is_numeric($h) || !is_numeric($m) || !is_numeric($s)) {
            return null;
        }

        return (float) $h * 3600.0 + (float) $m * 60.0 + (float) $s;
    }

    /** Strip inline tags, decode basic entities, and collapse to single-line text. */
    private static function cleanText(string $text): string
    {
        $text = preg_replace('/<[^>]*>/', '', $text) ?? $text; // <i>, <c.classname>, <00:00:01.000>
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($text);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Video\Subtitle;

use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Video\Lang;

/**
 * A parsed WebVTT subtitle track — a time-sorted list of {@see Cue}s with a
 * `cueAt()` lookup for the caption showing at a given playback time: a binary
 * search for the rightmost start at or before the position, then a walk back
 * over earlier cues until one contains it — O(log n) plus worst-case O(k)
 * over the cues walked back, bounded by MAX_CUES for a parsed track.
 *
 * Parsing is lenient: it skips the `WEBVTT` header and any `NOTE`/`STYLE`/
 * `REGION` blocks, ignores per-cue identifiers and cue settings, strips inline
 * tags (`<i>`, `<c.foo>`, …) and decodes entities. It also tolerates SRT-style
 * input (numeric index lines and `,` millisecond separators), so the same
 * parser covers both formats a media server might hand back.
 *
 * Trust boundary: caption text is UNTRUSTED (subtitle files arrive with the
 * media, from servers and downloads the user did not vet). {@see cleanText()}
 * is the single ingress every cue passes through, and it guarantees each
 * {@see Cue::$text} is single-line, ESC-free (no CSI/OSC/DCS/APC introducer
 * survives), free of C0/DEL/C1 control bytes, free of the sugar-mouse zone
 * sentinels U+E000/U+E001, and valid UTF-8 — so a host can write the composed
 * frame straight to the terminal. This closes the strip-then-decode ordering
 * hole where `&lt;`/`&#10;`/`&#27;` re-admitted markup, newlines, and raw
 * escape bytes into {@see \SugarCraft\Video\Player::view()}'s ANSI stream.
 */
final class WebVtt
{
    /**
     * Upper bound on the tag-strip/entity-decode fixpoint loop. Two passes
     * suffice for any real double-encoding (`&amp;lt;` needs one extra decode
     * than `&lt;`); the cap keeps a pathological `&amp;amp;…` chain O(1).
     */
    private const DECODE_FIXPOINT_PASSES = 4;
    /**
     * Upper bound on the number of cues parsed from a single track.
     *
     * A hostile (or accidentally huge) VTT/SRT payload could carry millions of
     * cue blocks; parsing them all would sort and hold an unbounded array in
     * memory (a cheap DoS against a media client that renders subtitles handed
     * back by a server). Parsing stops once this many cues are collected — the
     * excess is silently truncated so playback still starts. The ceiling is far
     * above real-world tracks: one caption per second for ~2.8 hours is ~10k
     * cues, so a legitimate subtitle file never hits it.
     */
    public const MAX_CUES = 10000;

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
        $truncated = false;
        foreach (preg_split('/\n[ \t]*\n/', $text) ?: [] as $block) {
            // DoS guard: stop once the cue cap is reached; the rest is
            // truncated — and the truncation is ANNOUNCED, never silent (M25).
            if (count($cues) >= self::MAX_CUES) {
                $truncated = true;
                break;
            }
            $cue = self::parseBlock($block);
            if ($cue !== null) {
                $cues[] = $cue;
            }
        }

        if ($truncated) {
            error_log(Lang::t('subtitle.cue_cap', ['max' => (string) self::MAX_CUES]));
        }

        // Sort by start time ascending so binary search is valid and the
        // "last matching cue wins" semantics are well-defined for overlapping cues.
        usort($cues, static fn(Cue $a, Cue $b): int => $a->start <=> $b->start);

        return new self($cues);
    }

    /**
     * The caption text showing at $seconds, or null when nothing is.
     * Binary-searches the sorted cue list for the rightmost cue starting at
     * or before $seconds (O(log n)), then walks back over earlier cues until
     * one actually contains the position or the list runs out — worst case
     * O(k) cues walked back, bounded by MAX_CUES for a parsed track.
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
        // cleanText() both sanitises AND collapses to a single line, so the
        // line structure of the cue body is irrelevant by the time a Cue is
        // built — a two-line subtitle becomes one row, honouring Player's
        // one-row-per-pane invariant instead of silently shifting it.
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

    /**
     * Strip inline tags, decode entities, and collapse to single-line text
     * that is safe to write to a terminal verbatim.
     *
     * Ordering is the load-bearing part. Stripping `<…>` spans BEFORE entity
     * decoding re-admits angle brackets (`&lt;i&gt;x&lt;/i&gt;` decoded after
     * the strip yields markup that was never stripped), and decoding is itself
     * re-entrant (`&amp;lt;` needs a second pass to surface its `<`). So the
     * strip+decode pair runs to a fixpoint under a bounded cap; only when the
     * text has settled does the terminal-safety sweep run, guaranteeing it
     * sees every byte any decode pass could produce:
     *
     *  1. fixpoint: strip `<[^>]*>` spans, then `html_entity_decode`
     *     (ENT_HTML5) — repeat until stable, at most
     *     {@see DECODE_FIXPOINT_PASSES} passes;
     *  2. newline/tab normalisation to spaces (single-line contract);
     *  3. {@see Sanitize::untrustedForMarkedFrames()} — removes every
     *     ESC-introduced sequence (CSI/OSC/SGR), the 8-bit C1 spellings, and
     *     forged sugar-mouse zone sentinels U+E000/U+E001;
     *  4. {@see Sanitize::cellValue()} — repairs invalid UTF-8 to U+FFFD and
     *     sweeps any remaining C0/DEL byte to the visible · stand-in.
     *
     * {@see Player::view()} additionally passes every caption through the
     * public {@see sanitizeForTerminal()} re-assert of steps 2-4 before
     * compositing, so the egress contract holds even if a future refactor
     * ever lets Cue text bypass this parser.
     */
    private static function cleanText(string $text): string
    {
        for ($pass = 0; $pass < self::DECODE_FIXPOINT_PASSES; $pass++) {
            $stripped = preg_replace('/<[^>]*>/', '', $text) ?? $text; // <i>, <c.classname>, <00:00:01.000>
            $decoded = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $text) {
                break;
            }
            $text = $decoded;
        }

        return self::sanitizeForTerminal($text);
    }

    /**
     * The terminal-safety half of {@see cleanText()} on already tag-stripped
     * text: newline/tab collapse, escape-sequence and zone-sentinel sweep,
     * C0/DEL/C1 removal, and UTF-8 repair. Idempotent — running it over its
     * own output changes nothing.
     *
     * Public so {@see \SugarCraft\Video\Player::view()} can re-assert the
     * contract at the egress without re-parsing the track.
     */
    public static function sanitizeForTerminal(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $text);
        $text = Sanitize::untrustedForMarkedFrames($text);

        return trim(Sanitize::cellValue($text));
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Video\Subtitle;

use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Video\Lang;

/**
 * A parsed WebVTT subtitle track — a time-sorted list of {@see Cue}s with a
 * `cueAt()` lookup for the caption showing at a given playback time: two binary
 * searches — rightmost start at or before the position, and the leftmost cue
 * whose (prefix-maximum) end reaches it — so cues that provably cannot contain
 * the position are never even looked at (SV-VTT/084). The walk that remains is
 * bounded by real overlap, not by caption-free gaps, and the look runs on
 * EVERY painted frame.
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
     * Upper bound on the RAW text handed to {@see parse()} (SV-VTT/078).
     *
     * MAX_CUES counts CUES, not blocks: a payload of a million comment blocks
     * materialises them all in preg_split before the cue cap engages. Reel
     * caps its own file read at MAX_SUBTITLE_BYTES (4 MiB) before parsing,
     * but parse() is public API and may be handed anything a host fetched.
     * The ceiling sits deliberately ABOVE that read cap so a legitimate file
     * Reel accepted is never refused here — it is the second door, sized for
     * hosts that read their own bytes.
     */
    public const MAX_VTT_BYTES = 8_388_608;

    /**
     * Upper bound on one cue's cleaned text in bytes (SV-VTT/081). A single
     * block survives four regex passes and lands verbatim on a Cue otherwise
     * — a 4 MiB blob between two timestamps would be carried (and re-clipped
     * by Player per frame) forever. Clamped at COLLECT time with a visible
     * ellipsis, so the oversized payload costs its 4 KiB and moves on.
     */
    public const MAX_CUE_TEXT_BYTES = 4096;

    /**
     * @var list<float> prefix maximum of cue ends: $maxEnds[$i] =
     *                max(end of cues[0..i]), non-decreasing by construction.
     *                Backs cueAt()'s walk-back bound (SV-VTT/084).
     */
    private readonly array $maxEnds;

    /**
     * @param list<Cue> $cues
     */
    private function __construct(
        private readonly array $cues,
    ) {
        $maxEnds = [];
        $best = -INF;
        foreach ($cues as $cue) {
            if ($cue->end > $best) {
                $best = $cue->end;
            }
            $maxEnds[] = $best;
        }
        // Construction assert: the array cueAt() binary-searches MUST be
        // non-decreasing — a violated premise here would silently shrink the
        // candidate window, so it is a contract break, not user input.
        for ($i = 1, $n = count($maxEnds); $i < $n; $i++) {
            if ($maxEnds[$i] < $maxEnds[$i - 1]) {
                throw new \LogicException('subtitle cue end prefix is not monotonic');
            }
        }
        $this->maxEnds = $maxEnds;
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
        // SV-VTT/078: own input ceiling, typed and loud — parse() is public API
        // and MAX_CUES does not bound block materialisation.
        if (strlen($text) > self::MAX_VTT_BYTES) {
            throw new \InvalidArgumentException(Lang::t('subtitle.parse_too_large', [
                'len' => (string) strlen($text),
                'max' => (string) self::MAX_VTT_BYTES,
            ]));
        }

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
     *
     * SV-VTT/084: two binary searches bracket the ONLY cues that can answer.
     * The upper bound is the rightmost cue starting at or before $seconds
     * (unchanged); the lower bound comes from the prefix-max-of-end array —
     * every cue at or left of the first index whose running maximum end
     * reaches $seconds provably ENDED before it, so a gap of a thousand
     * silent cues costs zero `contains()` calls instead of a thousand. The
     * walk that remains spans genuine overlap only. Tie-break is untouched:
     * among containing cues the latest start wins (first match walking back).
     * This runs from view() on every painted frame — the old version charged
     * O(N) per frame inside long caption-free stretches.
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

        if ($result === null) {
            return null;
        }

        // Lowest index whose cue could still reach $seconds: everything left of
        // it has max(end of cues[0..i]) < $seconds — none can contain it.
        $lo = 0;
        $hi = $result;
        $floor = -1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($this->maxEnds[$mid] >= $seconds) {
                $floor = $mid;
                $hi = $mid - 1;
            } else {
                $lo = $mid + 1;
            }
        }
        if ($floor < 0) {
            return null;
        }

        // Walk back over the bounded candidate window [floor, result]; only
        // genuinely overlapping cues are visited.
        for ($j = $result; $j >= $floor; $j--) {
            if ($cues[$j]->contains($seconds)) {
                return $cues[$j]->text;
            }
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

        // SV-VTT/081: per-cue byte ceiling with a visible seam. cleanText
        // guarantees valid UTF-8, and mb_strcut never splits a codepoint, so
        // the ellipsis (3 bytes) rides inside the ceiling, not past it.
        if (strlen($text) > self::MAX_CUE_TEXT_BYTES) {
            $text = mb_strcut($text, 0, self::MAX_CUE_TEXT_BYTES - 3) . '…';
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

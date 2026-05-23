<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape;

use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;

/**
 * Reverse of {@see Compiler::compile()} — walks a Cassette's events back into
 * tape source text that re-parses to an equivalent Cassette.
 *
 * Mirrors charmbracelet/vhs Decompiler (not present upstream; SugarCraft
 * round-trip aid for the Tape compiler).
 *
 * ## Heuristics
 *
 * - **Sleep threshold**: a gap of more than {@see self::SLEEP_THRESHOLD_SECONDS}
 *   seconds between two Input events is emitted as `Sleep <delta>ms`. Smaller
 *   gaps (the implicit `TypingSpeed` cadence between adjacent `Type` chars) are
 *   absorbed by the next `Type` group.
 * - **Space heuristic**: a lone ` ` byte sandwiched between printable bytes
 *   collapses into a single `Type "abc def"` line. A solitary space — no
 *   adjacent printable input — emits a standalone `Space` directive.
 * - **Type grouping**: consecutive printable input events become one `Type "..."`
 *   line. The group breaks on any non-printable byte, on a non-input event, or
 *   on a Sleep-worthy gap.
 *
 * ## Limitations
 *
 * - `Hide`, `Show`, `Wait`, `Screenshot`, and `Output` directives leave no
 *   trace in the compiled Cassette (Compiler treats them as no-ops or stores
 *   them in the header without a backref), so the round-trip drops them.
 * - Multi-byte UTF-8 sequences are reassembled into the original code point
 *   when the bytes are contiguous in a single Input event payload (the
 *   Compiler emits them that way per char). Split across events they survive
 *   the round-trip as a byte-level Type group but may not normalise.
 * - Non-printable single bytes that don't match a known directive are dropped
 *   with a `# unprintable byte 0x..` comment — these can't be expressed in
 *   tape source.
 * - `Ctrl+letter` is reconstructed from raw control bytes 1..26 → A..Z. The
 *   case picked (upper) is normalised, so `Ctrl+c` in the source round-trips
 *   as `Ctrl+C`.
 */
final class Decompiler
{
    /**
     * Gaps below this threshold (in seconds) between adjacent Input events are
     * treated as implicit typing cadence and absorbed into the next Type group
     * rather than emitting an explicit `Sleep` directive.
     */
    public const SLEEP_THRESHOLD_SECONDS = 0.1;

    private const DEFAULT_THEME = 'TokyoNight';
    private const DEFAULT_COLS = 80;
    private const DEFAULT_ROWS = 24;
    private const DEFAULT_TYPING_SPEED_MS = 50.0;

    public function decompile(Cassette $cassette): string
    {
        $lines = [];

        foreach ($this->headerLines($cassette->header) as $line) {
            $lines[] = $line;
        }

        foreach ($this->bodyLines($cassette->events, $cassette->header->typingSpeed) as $line) {
            $lines[] = $line;
        }

        return $lines === [] ? "\n" : implode("\n", $lines) . "\n";
    }

    /**
     * @return list<string>
     */
    private function headerLines(CassetteHeader $header): array
    {
        $lines = [];

        $theme = $header->theme;
        if ($theme !== null && $theme !== self::DEFAULT_THEME) {
            $lines[] = 'Set Theme "' . $this->escapeQuoted($theme) . '"';
        }

        if ($header->cols !== self::DEFAULT_COLS) {
            $lines[] = 'Set Width ' . $header->cols;
        }

        if ($header->rows !== self::DEFAULT_ROWS) {
            $lines[] = 'Set Height ' . $header->rows;
        }

        $typingSpeed = $header->typingSpeed;
        if ($typingSpeed !== null && abs($typingSpeed - self::DEFAULT_TYPING_SPEED_MS) > 1e-6) {
            $lines[] = 'Set TypingSpeed ' . $this->formatNumber($typingSpeed) . 'ms';
        }

        foreach ($header->env as $key => $value) {
            $lines[] = 'Env ' . $key . ' "' . $this->escapeQuoted($value) . '"';
        }

        return $lines;
    }

    /**
     * @param list<Event> $events
     * @return list<string>
     */
    private function bodyLines(array $events, ?float $typingSpeedMs = null): array
    {
        $lines = [];
        /** @var list<string> $typeGroup */
        $typeGroup = [];
        $previousTime = 0.0;
        $previousWasTypingChar = false;
        $haveEmittedEvent = false;
        $typingSpeedSeconds = ($typingSpeedMs ?? self::DEFAULT_TYPING_SPEED_MS) / 1000.0;

        $flushType = function () use (&$typeGroup, &$lines): void {
            if ($typeGroup === []) {
                return;
            }
            $payload = implode('', $typeGroup);
            $lines[] = 'Type "' . $this->escapeQuoted($payload) . '"';
            $typeGroup = [];
        };

        foreach ($events as $event) {
            if ($event->kind !== EventKind::Input) {
                continue;
            }

            $payload = $event->payload['b'] ?? '';
            if (!is_string($payload) || $payload === '') {
                continue;
            }

            $gap = $haveEmittedEvent ? ($event->t - $previousTime) : 0.0;
            $sleepGap = $previousWasTypingChar ? max(0.0, $gap - $typingSpeedSeconds) : $gap;
            $haveEmittedEvent = true;
            $previousTime = $event->t;

            if ($sleepGap > self::SLEEP_THRESHOLD_SECONDS) {
                $flushType();
                $lines[] = 'Sleep ' . $this->formatDuration($sleepGap);
            }

            $directive = $this->classifyPayload($payload);
            if ($directive instanceof DecompilerTypeChunk) {
                $typeGroup[] = $directive->text;
                $previousWasTypingChar = true;
                continue;
            }

            $flushType();
            foreach ($directive as $line) {
                $lines[] = $line;
            }
            $previousWasTypingChar = false;
        }

        $flushType();

        return $lines;
    }

    /**
     * Classify a single Input event payload.
     *
     * Returns a {@see DecompilerTypeChunk} when the payload should be appended
     * to the current Type group, or a list of standalone directive lines
     * otherwise (e.g. ["Enter"], ["Ctrl+C"], ["# unprintable byte 0x00 dropped"]).
     *
     * @return DecompilerTypeChunk|list<string>
     */
    private function classifyPayload(string $bytes): DecompilerTypeChunk|array
    {
        if ($bytes === "\r" || $bytes === "\n") {
            return ['Enter'];
        }
        if ($bytes === "\t") {
            return ['Tab'];
        }
        if ($bytes === "\x7f" || $bytes === "\x08") {
            return ['Backspace'];
        }
        if ($bytes === "\x1b") {
            return ['Escape'];
        }
        if ($bytes === "\x1b[A") {
            return ['Up'];
        }
        if ($bytes === "\x1b[B") {
            return ['Down'];
        }
        if ($bytes === "\x1b[C") {
            return ['Right'];
        }
        if ($bytes === "\x1b[D") {
            return ['Left'];
        }

        if (strlen($bytes) === 1) {
            $byte = ord($bytes);
            if ($byte >= 1 && $byte <= 26) {
                $letter = chr($byte + 64);
                return ['Ctrl+' . $letter];
            }
            if ($byte === 0x20 || ($byte >= 0x21 && $byte < 0x7f)) {
                return new DecompilerTypeChunk($bytes);
            }
            return ['# unprintable byte 0x' . sprintf('%02x', $byte) . ' dropped'];
        }

        if ($this->isPrintableRun($bytes)) {
            return new DecompilerTypeChunk($bytes);
        }

        $hex = '';
        foreach (str_split($bytes) as $b) {
            $hex .= sprintf('%02x ', ord($b));
        }
        return ['# unprintable bytes ' . rtrim($hex) . ' dropped'];
    }

    /**
     * True when the byte string is composed entirely of printable ASCII or
     * non-ASCII UTF-8 continuation bytes (i.e., safe to embed in a `Type "..."`).
     */
    private function isPrintableRun(string $bytes): bool
    {
        if ($bytes === '') {
            return false;
        }
        for ($i = 0, $len = strlen($bytes); $i < $len; $i++) {
            $b = ord($bytes[$i]);
            if ($b === 0x20) {
                continue;
            }
            if ($b >= 0x21 && $b < 0x7f) {
                continue;
            }
            if ($b >= 0x80) {
                continue;
            }
            return false;
        }
        return true;
    }

    private function escapeQuoted(string $s): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $s);
    }

    /**
     * Format a sub-second gap as `<n>ms`, otherwise `<n>s`.
     */
    private function formatDuration(float $seconds): string
    {
        if ($seconds < 1.0) {
            $ms = $seconds * 1000.0;
            return $this->formatNumber($ms) . 'ms';
        }
        return $this->formatNumber($seconds) . 's';
    }

    /**
     * Drop trailing `.0` so integers stay integral but keep precision when present.
     */
    private function formatNumber(float $value): string
    {
        if (abs($value - round($value)) < 1e-9) {
            return (string) (int) round($value);
        }
        return rtrim(rtrim(sprintf('%.6f', $value), '0'), '.');
    }
}

/**
 * Marker for a payload that should fold into the current Type group rather
 * than emitting its own directive line. Internal to {@see Decompiler}.
 *
 * @internal
 */
final readonly class DecompilerTypeChunk
{
    public function __construct(public string $text)
    {
    }
}

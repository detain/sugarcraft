<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents\Live;

use SugarCraft\Code\Host\TranscriptProjector;
use SugarCraft\Code\Message;
use SugarCraft\Code\ToolCall;
use SugarCraft\Code\ToolResult;
use SugarCraft\Core\Util\Ansi;

/**
 * The reader of a {@see SubAgentTranscriptLog}: an offset tail, and the
 * projection of what it read into transcript rows (roadmap P-C1, Appendix P
 * §5.2).
 *
 * TAILED, NOT RE-READ. A value object holding a byte offset: {@see next()}
 * reads at most {@see MAX_BYTES_PER_READ} past it and returns the items of
 * the COMPLETE lines it found plus the tail advanced past exactly those — a
 * line the writer has not finished yet is read again next time, never parsed
 * half-written. Called on the existing 0.1 s tick while an Agent View is open
 * (P-C2), and in a loop by {@see readAll()} when a finished run becomes a child
 * session.
 *
 * UNTRUSTED BYTES. Everything in a log came from a model or a tool: a line
 * that does not decode, or decodes to anything but a known item, is dropped;
 * text is stripped of terminal escapes, control bytes and Private-Use
 * codepoints (the range the mouse-zone sentinels and image markers live in)
 * before it becomes a row, and printable role-marker imitations
 * ({@see ReportSanitizer}) are folded into their inert visible spelling — the
 * rows are re-ingested by a parent session, not just painted for a human.
 */
final class AgentTranscriptTail
{
    /** Bytes one {@see next()} reads at most — the per-tick budget. */
    public const MAX_BYTES_PER_READ = 65536;

    private function __construct(
        private readonly string $path,
        private readonly int $offset,
    ) {}

    /** A tail of $path from its first byte. */
    public static function of(string $path): self
    {
        return new self($path, 0);
    }

    public function path(): string
    {
        return $this->path;
    }

    /** Bytes of the log already read — the start of the next {@see next()}. */
    public function offset(): int
    {
        return $this->offset;
    }

    /**
     * The items of every complete line past the offset (within $maxBytes),
     * and the tail advanced past them. An unreadable or missing log yields
     * nothing and the same tail.
     *
     * @return array{0: list<array<string, mixed>>, 1: self}
     */
    public function next(int $maxBytes = self::MAX_BYTES_PER_READ): array
    {
        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            return [[], $this];
        }

        try {
            if (fseek($handle, $this->offset) !== 0) {
                return [[], $this];
            }
            $chunk = fread($handle, max(1, $maxBytes));
        } finally {
            fclose($handle);
        }

        if (!\is_string($chunk) || $chunk === '') {
            return [[], $this];
        }

        $end = strrpos($chunk, "\n");
        if ($end === false) {
            // No whole line yet. A window this size cannot hold a line the
            // writer would have produced (SubAgentTranscriptLog::MAX_LINE_BYTES),
            // so a full window without a newline is garbage: skip it rather
            // than stall on it forever.
            return \strlen($chunk) >= $maxBytes && $maxBytes > SubAgentTranscriptLog::MAX_LINE_BYTES
                ? [[], new self($this->path, $this->offset + \strlen($chunk))]
                : [[], $this];
        }

        $items = [];
        foreach (explode("\n", substr($chunk, 0, $end)) as $line) {
            $item = self::decode($line);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return [$items, new self($this->path, $this->offset + $end + 1)];
    }

    /**
     * Every item in the log at $path, start to end.
     *
     * @return list<array<string, mixed>>
     */
    public static function readAll(string $path): array
    {
        $tail = self::of($path);
        $items = [];
        while (true) {
            [$batch, $next] = $tail->next();
            array_push($items, ...$batch);
            if ($next->offset() === $tail->offset()) {
                return $items;
            }
            $tail = $next;
        }
    }

    /**
     * Transcript rows for $items, in the shapes the existing renderer draws:
     * user and assistant prose, a tool call as its running placeholder that
     * its result replaces by call id ({@see TranscriptProjector::resultRow()},
     * the finished-row shape both live pipelines write), a thought as the
     * collapsible reasoning of the row that follows it, and the run's end as a
     * notice.
     *
     * @param list<array<string, mixed>> $items
     * @return list<Message>
     */
    public static function messages(array $items): array
    {
        $rows = [];
        $open = [];
        $thought = '';
        foreach ($items as $item) {
            $now = \is_float($item['ts'] ?? null) || \is_int($item['ts'] ?? null) ? (int) $item['ts'] : null;
            $text = self::clean((string) ($item['text'] ?? ''));

            switch ($item['t']) {
                case SubAgentTranscriptLog::T_THINKING:
                    $thought .= ($thought === '' ? '' : "\n") . $text;
                    break;
                case SubAgentTranscriptLog::T_USER:
                case SubAgentTranscriptLog::T_INBOX:
                    if ($text !== '') {
                        $rows[] = Message::user($text, $now);
                    }
                    break;
                case SubAgentTranscriptLog::T_ASSISTANT:
                    if ($text !== '' || $thought !== '') {
                        $rows[] = Message::assistant($text, $now, $thought === '' ? null : $thought);
                        $thought = '';
                    }
                    break;
                case SubAgentTranscriptLog::T_TOOL_CALL:
                    $callId = self::clean((string) ($item['callId'] ?? ''));
                    $args = \is_array($item['args'] ?? null) ? self::cleanArgs($item['args']) : [];
                    $placeholder = Message::toolRunning(new ToolCall(self::clean((string) ($item['tool'] ?? '?')), $args, $callId === '' ? null : $callId), $now);
                    if ($thought !== '') {
                        $placeholder = $placeholder->withReasoning($thought);
                        $thought = '';
                    }
                    $open[$placeholder->pendingToolCallId] = \count($rows);
                    $rows[] = $placeholder;
                    break;
                case SubAgentTranscriptLog::T_TOOL_RESULT:
                    $callId = self::clean((string) ($item['callId'] ?? ''));
                    $tool = self::clean((string) ($item['tool'] ?? '?'));
                    $content = self::clean((string) ($item['content'] ?? ''));
                    if (($item['truncated'] ?? false) === true) {
                        $content .= "\n[… truncated]";
                    }
                    $ok = ($item['ok'] ?? false) === true;
                    $result = new ToolResult($tool, $ok ? $content : '', $ok ? null : $content, $callId === '' ? null : $callId);
                    $at = $open[$callId === '' ? $tool : $callId] ?? null;
                    if ($at === null) {
                        $rows[] = TranscriptProjector::resultRow($result);
                        break;
                    }
                    $placeholder = $rows[$at];
                    $rows[$at] = TranscriptProjector::resultRow(
                        $result->withDescription($placeholder->content)->withArguments($placeholder->pendingToolArguments),
                        $placeholder->reasoning,
                    );
                    unset($open[$callId === '' ? $tool : $callId]);
                    break;
                case SubAgentTranscriptLog::T_STATUS:
                    $error = self::clean((string) ($item['error'] ?? ''));
                    $rows[] = Message::notice(
                        'Sub-agent ' . self::clean((string) ($item['outcome'] ?? $item['status'] ?? 'finished'))
                        . ($error === '' ? '' : ': ' . $error),
                        $now,
                    );
                    break;
            }
        }

        if ($thought !== '') {
            $rows[] = Message::assistant('', null, $thought);
        }

        return $rows;
    }

    /**
     * One decoded item, or null for a line that is not one: malformed JSON,
     * not an object, or an unknown `t`.
     *
     * @return array<string, mixed>|null
     */
    private static function decode(string $line): ?array
    {
        if (trim($line) === '') {
            return null;
        }

        try {
            $item = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($item) && \in_array($item['t'] ?? null, SubAgentTranscriptLog::TYPES, true) ? $item : null;
    }

    /**
     * $args with every string key and value {@see clean()}ed, recursively —
     * the placeholder row renders them into its one-line description.
     *
     * @param array<array-key, mixed> $args
     * @return array<array-key, mixed>
     */
    private static function cleanArgs(array $args): array
    {
        $clean = [];
        foreach ($args as $key => $value) {
            $clean[\is_string($key) ? self::clean($key) : $key] = match (true) {
                \is_string($value) => self::clean($value),
                \is_array($value) => self::cleanArgs($value),
                default => $value,
            };
        }

        return $clean;
    }

    /**
     * $text with terminal escapes ({@see Ansi::strip()} — the full ECMA-48
     * sweep, DCS/SOS/PM/APC included), control bytes (tab and newline kept),
     * DEL, C1 and Private-Use codepoints removed, invalid UTF-8 scrubbed — and
     * the survivor's imitation-control heads (fake role tags, ChatML-style
     * special tokens) folded into their inert visible spelling by
     * {@see ReportSanitizer::sanitize()}. Runs last: the escape sweep must
     * not be able to uncover a header the anchor never saw, and the
     * sanitizer's own output (fullwidth forms, the marker) is already inside
     * what the sweeps keep.
     */
    private static function clean(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        // Line-break-shaped bytes become REAL newlines before the control
        // sweep deletes them — otherwise a CR/VT/FF/NEL-smuggled head lands
        // mid-line, dodges the head anchors of ReportSanitizer, and (for CR)
        // still repaints to column 0 for a human reader. The set is exactly
        // what Python str.splitlines() treats as a boundary, the realistic
        // re-ingest tokenizer for anything downstream of this transcript.
        $text = strtr($text, [
            "\r\n" => "\n", "\r" => "\n", "\v" => "\n", "\f" => "\n",
            \chr(0x1C) => "\n", \chr(0x1D) => "\n", \chr(0x1E) => "\n",
            "\u{0085}" => "\n", "\u{2028}" => "\n", "\u{2029}" => "\n",
        ]);
        // The canonical ECMA-48 sweep: CSI, OSC AND the DCS/SOS/PM/APC string
        // classes, in both 7-bit and 8-bit form. A hand-rolled CSI+OSC-only
        // regex (this line's predecessor) left a DCS/APC payload's body on the
        // page as junk — see {@see Ansi::strip()}.
        $text = Ansi::strip($text);

        return ReportSanitizer::sanitize(
            preg_replace('/[\x00-\x08\x0b-\x1f\x7f]|[\x{80}-\x{9f}]|[\x{E000}-\x{F8FF}]/u', '', $text) ?? '',
        );
    }
}

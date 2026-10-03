<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents\Live;

/**
 * One thing a delegated run did, as carried in a v2
 * {@see \SugarCraft\Crush\Events\SubAgentActivity} frame's `items` list: a
 * tool call starting or finishing, a thinking burst, a fragment of prose, or
 * a direct message delivered into the run.
 *
 * Items are what the live agent line reads its "latest item" from
 * (Appendix P §4.1), and the same array shape is what the server's
 * `agent.activity` event carries, so {@see toArray()} is the wire format and
 * {@see fromArray()} the one validator: the frame crossed a process
 * boundary, and an item out of shape is dropped, never half-trusted.
 *
 * Reasoning stays local: a thinking item is a marker with no text.
 */
final readonly class ActivityItem
{
    public const TOOL_STARTED = 'tool_started';
    public const TOOL_FINISHED = 'tool_finished';
    public const THINKING = 'thinking';
    public const TEXT = 'text';
    public const INBOX = 'inbox';

    /** Every item type a frame may carry. */
    public const TYPES = [self::TOOL_STARTED, self::TOOL_FINISHED, self::THINKING, self::TEXT, self::INBOX];

    /** Byte ceiling on one text delta; a longer one keeps its newest bytes. */
    public const MAX_TEXT_BYTES = 512;

    /** Byte ceiling on a tool call's id or name as carried here. */
    public const MAX_ID_BYTES = 256;

    private function __construct(
        public string $type,
        public string $callId = '',
        public string $tool = '',
        public string $summary = '',
        public bool $ok = true,
        public int $ms = 0,
        public string $delta = '',
        public int $delivered = 0,
    ) {}

    public static function toolStarted(string $callId, string $tool, string $summary): self
    {
        return new self(
            self::TOOL_STARTED,
            callId: self::head($callId, self::MAX_ID_BYTES),
            tool: self::head($tool, self::MAX_ID_BYTES),
            summary: self::head($summary, ToolSummary::MAX_BYTES),
        );
    }

    public static function toolFinished(string $callId, string $tool, bool $ok, int $ms): self
    {
        return new self(
            self::TOOL_FINISHED,
            callId: self::head($callId, self::MAX_ID_BYTES),
            tool: self::head($tool, self::MAX_ID_BYTES),
            ok: $ok,
            ms: max(0, $ms),
        );
    }

    public static function thinking(): self
    {
        return new self(self::THINKING);
    }

    public static function text(string $delta): self
    {
        return new self(self::TEXT, delta: self::tail($delta, self::MAX_TEXT_BYTES));
    }

    public static function inbox(int $delivered): self
    {
        return new self(self::INBOX, delivered: max(0, $delivered));
    }

    /**
     * This text item with $more appended, still bounded to its newest
     * {@see MAX_TEXT_BYTES} — how a run of prose deltas coalesces into one
     * item instead of one per token.
     */
    public function withMoreText(string $more): self
    {
        return self::text($this->delta . $more);
    }

    /**
     * The wire shape: `t` plus only the keys that type carries.
     *
     * @return array<string, string|int|bool>
     */
    public function toArray(): array
    {
        return match ($this->type) {
            self::TOOL_STARTED => ['t' => $this->type, 'callId' => $this->callId, 'tool' => $this->tool, 'summary' => $this->summary],
            self::TOOL_FINISHED => ['t' => $this->type, 'callId' => $this->callId, 'tool' => $this->tool, 'ok' => $this->ok, 'ms' => $this->ms],
            self::TEXT => ['t' => $this->type, 'delta' => $this->delta],
            self::INBOX => ['t' => $this->type, 'delivered' => $this->delivered],
            default => ['t' => $this->type],
        };
    }

    /**
     * Rebuild an item from its wire shape, or null when it is not one this
     * version writes. Strings are re-bounded on the way in: the reader does
     * not trust the writer's caps.
     */
    public static function fromArray(mixed $item): ?self
    {
        if (!is_array($item)) {
            return null;
        }

        $type = $item['t'] ?? null;
        $string = static fn (string $key): ?string => is_string($item[$key] ?? null) ? $item[$key] : null;

        switch ($type) {
            case self::TOOL_STARTED:
                $callId = $string('callId');
                $tool = $string('tool');
                $summary = $string('summary');
                if ($callId === null || $tool === null || $tool === '' || $summary === null) {
                    return null;
                }

                return self::toolStarted($callId, $tool, $summary);
            case self::TOOL_FINISHED:
                $callId = $string('callId');
                $tool = $string('tool');
                $ok = $item['ok'] ?? null;
                $ms = $item['ms'] ?? null;
                if ($callId === null || $tool === null || $tool === '' || !is_bool($ok) || !is_int($ms)) {
                    return null;
                }

                return self::toolFinished($callId, $tool, $ok, $ms);
            case self::THINKING:
                return self::thinking();
            case self::TEXT:
                $delta = $string('delta');

                return $delta === null ? null : self::text($delta);
            case self::INBOX:
                $delivered = $item['delivered'] ?? null;

                return is_int($delivered) ? self::inbox($delivered) : null;
            default:
                return null;
        }
    }

    /**
     * The first $bytes of $text, never split inside a UTF-8 codepoint.
     */
    private static function head(string $text, int $bytes): string
    {
        if (strlen($text) <= $bytes) {
            return $text;
        }

        $cut = substr($text, 0, $bytes);
        while ($cut !== '' && preg_match('//u', $cut) !== 1) {
            $cut = substr($cut, 0, -1);
        }

        return $cut;
    }

    /**
     * The last $bytes of $text, never split inside a UTF-8 codepoint.
     */
    private static function tail(string $text, int $bytes): string
    {
        if (strlen($text) <= $bytes) {
            return $text;
        }

        $cut = substr($text, -$bytes);
        while ($cut !== '' && preg_match('//u', $cut) !== 1) {
            $cut = substr($cut, 1);
        }

        return $cut;
    }
}

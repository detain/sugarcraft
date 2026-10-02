<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers\ToolCallParser;

use SugarCraft\Crush\Tools\ToolCall;

/**
 * What an {@see EnvelopeAware} parser recovered from one assistant message:
 * the calls, and the byte spans of the content they were recovered from.
 *
 * The spans are what let the provider cut the envelope out of the text it
 * keeps (audit 15a A8) - see {@see contentWithoutEnvelopes()}.
 */
final readonly class TextualRecovery
{
    /**
     * Whitespace an envelope takes with it when it is cut. DeepSeek-V4's start
     * token is literally `"\n\n<｜DSML｜tool_calls"` (`enc.py:726`), so the blank
     * line in front of the markup is part of the markup, not of the prose.
     */
    private const WHITESPACE = " \t\r\n";

    /**
     * @param list<ToolCall>|null $calls Null means "no tool call", exactly as
     *        {@see ToolCallParserInterface::parse()} returns it.
     * @param list<array{0: int, 1: int}> $spans `[start, end)` byte ranges of
     *        the content, in document order: the envelopes at least one call
     *        was recovered from. An envelope whose every invoke was refused is
     *        NOT listed - its markup stays visible, next to the notice saying
     *        the call was dropped, so neither the user nor the model is left
     *        guessing what was attempted.
     */
    public function __construct(
        private ?array $calls,
        private array $spans = [],
    ) {}

    /**
     * Default root factory, per repo convention.
     *
     * @param list<ToolCall>|null $calls
     * @param list<array{0: int, 1: int}> $spans
     */
    public static function new(?array $calls, array $spans = []): self
    {
        return new self($calls, $spans);
    }

    /**
     * @return list<ToolCall>|null
     */
    public function calls(): ?array
    {
        return $this->calls;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    public function spans(): array
    {
        return $this->spans;
    }

    /**
     * `$content` from byte `$from` onward with every recovered envelope cut
     * out.
     *
     * `$from` exists for the streaming path: the bytes before it were already
     * painted and can no longer be changed, so nothing before it is touched -
     * a span (or the whitespace run in front of one) reaching back past it is
     * clipped there. {@see EnvelopeHoldBack} guarantees in practice that no
     * envelope starts before `$from`; the clip makes that a property of this
     * method rather than an assumption about its caller.
     *
     * An envelope takes the whitespace run in front of it along (see
     * {@see WHITESPACE}), and a remainder after the last envelope that is
     * nothing but whitespace goes too - the turn then ends where the prose
     * did, instead of on a dangling blank line.
     */
    public function contentWithoutEnvelopes(string $content, int $from = 0): string
    {
        $cursor = max(0, $from);
        $kept = '';
        $cut = false;

        foreach ($this->spans as [$start, $end]) {
            if ($end <= $cursor) {
                continue;
            }

            $kept .= rtrim(substr($content, $cursor, max(0, $start - $cursor)), self::WHITESPACE);
            $cursor = $end;
            $cut = true;
        }

        $rest = substr($content, $cursor);

        return $kept . ($cut && trim($rest, self::WHITESPACE) === '' ? '' : $rest);
    }
}

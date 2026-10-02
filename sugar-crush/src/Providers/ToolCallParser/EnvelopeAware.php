<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers\ToolCallParser;

/**
 * Optional capability of a {@see ToolCallParserInterface} that recovers tool
 * calls from markup written INTO the assistant's text - DeepSeek-V4's DSML
 * envelope, MiniMax's XML one - rather than from a structured `tool_calls`
 * array.
 *
 * WHY IT EXISTS (audit 15a A8). Recovering the call is only half of the job.
 * The envelope it came from is still sitting in the content, so it used to be
 * painted to the user verbatim and stored as the assistant message's text
 * next to the recovered `tool_calls` - and the next request then sent the
 * call twice, once as that text and once as the structured call the chat
 * template renders back into the same markup. The provider needs two more
 * facts than {@see ToolCallParserInterface::parse()} gives it:
 *
 * - which opening markers can start an envelope, so the streaming path can
 *   hold text back from the screen from the moment one might be starting
 *   ({@see EnvelopeHoldBack}), instead of painting markup it cannot retract;
 * - where in the content each envelope that yielded a call sits, so that
 *   span - and only that span - can be cut out ({@see TextualRecovery}).
 *
 * A SEPARATE INTERFACE, for the reason {@see ToolSchemaAware} gives: widening
 * the shipped seam would force a no-op onto {@see OpenAiArrayToolCallParser},
 * which never reads the text. A decorator forwards both questions to its
 * delegate when the delegate is envelope-aware too, so a stacked chain such as
 * `DsmlToolCallParser::new(MinimaxXmlFallbackToolCallParser::new())` reports
 * both protocols' markers and strips whichever envelope actually produced the
 * calls.
 */
interface EnvelopeAware
{
    /**
     * Every literal that can open an envelope this parser (or its delegate
     * chain) recovers from, e.g. `<｜DSML｜tool_calls`. Text that merely
     * QUOTES one is still a match here - deciding action versus quotation
     * needs the whole content, which the stream does not have yet.
     *
     * @return list<string>
     */
    public function envelopeMarkers(): array;

    /**
     * {@see ToolCallParserInterface::parse()}, plus the byte spans of
     * `$message['content']` that the returned calls were recovered from.
     *
     * Same input and same calls as `parse()` - `parse()` is implemented on
     * top of this, so the two cannot drift - and the spans are empty whenever
     * the calls did not come from the text (a structured `tool_calls` array,
     * or no call at all).
     *
     * @param array<string, mixed> $message
     */
    public function recover(array $message): TextualRecovery;
}

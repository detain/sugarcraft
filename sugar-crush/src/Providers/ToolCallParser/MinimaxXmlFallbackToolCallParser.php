<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers\ToolCallParser;

use SugarCraft\Crush\Tools\ToolCall;

/**
 * Last-resort parser for a MiniMax-M2.x deployment whose server was launched
 * *without* `--tool-call-parser minimax-m2` (crush_feat.md §12 D6).
 *
 * With the flag missing the server never decodes the model's native syntax, so
 * the tool call arrives as literal XML inside `message.content` and
 * `message.tool_calls` is absent - today that means the call is lost entirely.
 * The regexes below are the ones MiniMax's own `tool_calling_guide.md`
 * recommends for exactly this situation.
 *
 * This is defence-in-depth for a *misconfigured future* deployment, not a fix
 * for a current failure: the confirmed live deployment does pass the flag, so
 * in practice {@see parse()} takes its delegated fast path every time.
 */
final readonly class MinimaxXmlFallbackToolCallParser implements ToolCallParserInterface
{
    /**
     * The marker whose presence in `content` is the only trigger for the
     * regex path. Anything else - prose that merely mentions tools, a normal
     * assistant turn - must be left alone.
     */
    private const ENVELOPE_MARKER = '<minimax:tool_call>';

    private const ENVELOPE_PATTERN = '#<minimax:tool_call>(.*?)</minimax:tool_call>#s';

    /**
     * The trailing alternation tolerates a *missing* `</invoke>`, which is the
     * expected shape when the §12 D5 `</parameter>` truncation bug cut the
     * envelope short: recovering the calls that did arrive beats discarding
     * the whole turn.
     */
    private const INVOKE_PATTERN = '#<invoke\s+name="(.*?)"\s*>(.*?)(?:</invoke>|(?=<invoke\b)|$)#s';

    private const PARAMETER_PATTERN = '#<parameter\s+name="(.*?)"\s*>(.*?)</parameter>#s';

    public function __construct(
        private ToolCallParserInterface $delegate,
    ) {}

    /**
     * Default root factory, per repo convention.
     *
     * @param ToolCallParserInterface|null $delegate Handles the ordinary
     *        server-parsed case; defaults to the OpenAI array parser so this
     *        class is a drop-in replacement rather than an either/or choice.
     */
    public static function new(?ToolCallParserInterface $delegate = null): self
    {
        return new self($delegate ?? OpenAiArrayToolCallParser::new());
    }

    /**
     * @param array<string, mixed> $message
     * @return array<ToolCall>|null
     */
    public function parse(array $message): ?array
    {
        // A server-parsed `tool_calls` array is always authoritative; the XML
        // scan is only ever reached when that array is absent.
        if (isset($message['tool_calls'])) {
            return $this->delegate->parse($message);
        }

        $content = $message['content'] ?? null;

        if (!is_string($content) || !str_contains($content, self::ENVELOPE_MARKER)) {
            return $this->delegate->parse($message);
        }

        return $this->parseXml($content);
    }

    /**
     * @return array<ToolCall>|null
     */
    private function parseXml(string $content): ?array
    {
        $calls = [];

        foreach ($this->envelopeBodies($content) as $envelope) {
            preg_match_all(self::INVOKE_PATTERN, $envelope, $invokes, PREG_SET_ORDER);

            foreach ($invokes as $invoke) {
                $calls[] = new ToolCall(
                    // The XML form carries no call id, but downstream code
                    // matches a ToolResult back to its call by id, so one is
                    // synthesised - positional, hence stable and assertable.
                    id: 'minimax_xml_call_' . count($calls),
                    name: $invoke[1],
                    arguments: $this->parseParameters($invoke[2]),
                );
            }
        }

        return $calls === [] ? null : $calls;
    }

    /**
     * Splits `content` into envelope bodies, recovering a trailing envelope
     * whose closing tag never arrived.
     *
     * The recovery has to run even when complete envelopes *were* found: a turn
     * that emits one well-formed envelope and then hits the §12 D5
     * `</parameter>` truncation on the next one still yields a match count of
     * 1, and skipping recovery there silently drops exactly the call this
     * branch exists to save. The trailing marker only counts as unclosed when
     * it starts after the end of the last complete match, so a marker sitting
     * inside an already-consumed body is not re-parsed into duplicate calls.
     *
     * @return array<string>
     */
    private function envelopeBodies(string $content): array
    {
        $matched = preg_match_all(
            self::ENVELOPE_PATTERN,
            $content,
            $envelopes,
            PREG_PATTERN_ORDER | PREG_OFFSET_CAPTURE,
        );

        if ($matched === false) {
            // A PCRE backtrack/recursion limit blown on a very large content is
            // a failure, not an absence of tool calls. D5's whole point is that
            // this mode be observable, so it is reported rather than collapsed
            // into an indistinguishable null.
            error_log(sprintf(
                'MinimaxXmlFallbackToolCallParser: possible MiniMax XML-delimiter truncation - '
                . 'the PCRE scan for "%s" envelopes failed (%s) over %d bytes of content; '
                . 'any tool call it carried is being dropped.',
                self::ENVELOPE_MARKER,
                preg_last_error_msg(),
                strlen($content),
            ));

            $envelopes = [];
        }

        $bodies = [];
        $consumedTo = 0;

        /** @var array<int, array{0: string, 1: int}> $wholeMatches */
        $wholeMatches = $envelopes[0] ?? [];

        foreach ($wholeMatches as $index => [$whole, $offset]) {
            $bodies[] = $envelopes[1][$index][0];
            $consumedTo = $offset + strlen($whole);
        }

        $trailingMarker = strrpos($content, self::ENVELOPE_MARKER);

        if ($trailingMarker !== false && $trailingMarker >= $consumedTo) {
            $bodies[] = substr($content, $trailingMarker + strlen(self::ENVELOPE_MARKER));

            error_log(sprintf(
                'MinimaxXmlFallbackToolCallParser: possible MiniMax XML-delimiter truncation - '
                . 'a "%s" envelope opened at byte %d is never closed (%d complete envelope(s) '
                . 'precede it); recovering whatever <invoke> it had already emitted.',
                self::ENVELOPE_MARKER,
                $trailingMarker,
                count($bodies) - 1,
            ));
        }

        return $bodies;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseParameters(string $body): array
    {
        preg_match_all(self::PARAMETER_PATTERN, $body, $params, PREG_SET_ORDER);

        $arguments = [];

        foreach ($params as $param) {
            $arguments[$param[1]] = $this->coerceValue($param[2]);
        }

        return $arguments;
    }

    /**
     * Parameter values arrive as raw text with no type information, yet tools
     * declare typed JSON-Schema inputs.
     *
     * Only a JSON-decodable *array* (object or list) is decoded, because that
     * is the one shape the raw text cannot otherwise represent. Scalars are
     * deliberately left as their original text: `<parameter name="old_string">1
     * </parameter>` is an ordinary string argument that merely looks like JSON,
     * and decoding it to int 1 hands a string-typed tool parameter the wrong
     * PHP type. Under `declare(strict_types=1)` that is not a soft failure -
     * {@see \SugarCraft\Crush\Tools\Edit} calls `substr_count($content,
     * $oldString)`, which raises an uncaught TypeError and takes down the tool
     * loop instead of returning an isError ToolResult. int/float/bool/null are
     * exactly the ambiguous cases, so they lose the coin toss.
     *
     * KNOWN GAP: the correct disambiguation needs the invoked tool's declared
     * JSON-Schema type, which this class cannot see - {@see parse()} is handed
     * only the response message. Threading the active tool list through is
     * W1.A6's job (§12 D6, `ProviderFactory::createSglang()`); until that lands
     * a genuinely number/boolean-typed parameter reaches the tool as its string
     * form. That same step is what first makes this parser reachable at all,
     * and the confirmed live deployment passes `--tool-call-parser minimax-m2`,
     * so nothing in production reads these values today.
     */
    private function coerceValue(string $raw): mixed
    {
        // The model emits the value on its own line for multi-line payloads;
        // only that framing newline is shed, so indentation inside file
        // content written by an Edit/Write tool call is preserved exactly.
        $value = preg_replace('/\A\r?\n|\r?\n\z/', '', $raw) ?? $raw;

        $trimmed = trim($value);

        if ($trimmed === '') {
            return $value;
        }

        $decoded = json_decode($trimmed, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : $value;
    }
}

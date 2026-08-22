<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers\ToolCallParser;

use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Last-resort parser for a MiniMax-M2.x deployment whose server was launched
 * *without* `--tool-call-parser minimax-m2` (crush_feat.md §12 D6).
 *
 * With the flag missing the server never decodes the model's native syntax, so
 * the tool call arrives as literal XML inside `message.content` and
 * `message.tool_calls` is absent - today that means the call is lost entirely.
 *
 * This is defence-in-depth for a *misconfigured future* deployment, not a fix
 * for a current failure: the confirmed live deployment does pass the flag, so
 * in practice {@see parse()} takes its delegated fast path every time.
 *
 * EXPOSURE, STATED BECAUSE IT IS NOT EQUAL TO ITS SIBLING'S. This parser is
 * reachable ONLY when `minimax-xml-fallback` is named explicitly in the
 * `toolCallParser` config key; nothing derives it from a model id.
 * {@see DsmlToolCallParser} is the derived DEFAULT for the DeepSeek-V4 family.
 * A defect shared by the two - and there was one, see below - is opt-in here
 * and armed by default there.
 *
 * THE SHARED DEFECT, RECORDED BECAUSE THIS CLASS IS WHERE IT STARTED. Until
 * this revision, detection was `str_contains($content, '<minimax:tool_call>')`
 * - the marker appearing ANYWHERE meant "this turn calls a tool". Measured on
 * that code, a message reading "to call a tool you emit markup like this:
 * ```<minimax:tool_call>…name="rm_rf"…</minimax:tool_call>``` … I have not
 * actually called anything" returned ONE REAL CALL, `rm_rf` with `path=/`. The
 * parser whose entire purpose is that a tool call is never silently MISSED was,
 * for that prompt shape, silently INVENTING one. {@see DsmlToolCallParser}
 * inherited the flaw by copying this class's shape and is where it was caught;
 * this is where it shipped. Both now scan positionally via
 * {@see MarkupScanner}, whose docblock carries the reproduction and the guard.
 */
final readonly class MinimaxXmlFallbackToolCallParser implements ToolCallParserInterface
{
    private const ENVELOPE_TAG = 'minimax:tool_call';

    private const INVOKE_TAG = 'invoke';

    private const PARAMETER_TAG = 'parameter';

    /**
     * A CHEAP REJECT, NOT A DECISION - it decides only whether the positional
     * scan is worth running, so an ordinary assistant turn costs one substring
     * search. Whether an envelope is an ACTION rather than a QUOTATION of the
     * protocol is {@see MarkupScanner::envelopes()}'s judgement; this string
     * used to be the whole gate, which is the defect recorded in the class
     * docblock.
     */
    private const ENVELOPE_PREFILTER = '<' . self::ENVELOPE_TAG;

    private const PARAMETER_CLOSE = '</' . self::PARAMETER_TAG . '>';

    public function __construct(
        private ToolCallParserInterface $delegate,
        private MarkupScanner $scanner,
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
        return new self(
            $delegate ?? OpenAiArrayToolCallParser::new(),
            // `requireStartToken: false`: MiniMax's XML has no documented
            // positional convention the way DeepSeek-V4's `\n\n<｜DSML｜…`
            // start token does, and MarkupScanner::qualifies() explains why
            // this file will not invent one. The fence guard applies to both.
            MarkupScanner::new('', false),
        );
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

        if (!is_string($content) || !str_contains($content, self::ENVELOPE_PREFILTER)) {
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
        $envelopes = $this->scanner->envelopes($content, self::ENVELOPE_TAG);

        if ($envelopes === []) {
            // See DsmlToolCallParser::parseDsml() for why a guard that can drop
            // a genuine call has to say so. This parser gets the fence guard
            // only, so the one shape that reaches here is a quoted envelope.
            RuntimeNoticeSink::warn(sprintf(
                'MinimaxXmlFallbackToolCallParser: content carries "%s>" but every occurrence sits '
                . 'inside a ``` code fence, which reads as a quotation of the protocol rather than '
                . 'a tool call. No tool call recovered.',
                self::ENVELOPE_PREFILTER,
            ));
        }

        foreach ($envelopes as $envelope) {
            if (!$envelope['closed']) {
                error_log(sprintf(
                    'MinimaxXmlFallbackToolCallParser: possible MiniMax XML-delimiter truncation - '
                    . 'a "%s>" envelope opened at byte %d is never closed; recovering whatever '
                    . '<invoke> it had already emitted.',
                    self::ENVELOPE_PREFILTER,
                    $envelope['offset'],
                ));
            }

            foreach ($this->scanner->elements($envelope['body'], self::INVOKE_TAG) as $invoke) {
                $name = $invoke['attributes']['name'] ?? null;

                if ($name === null || $name === '') {
                    RuntimeNoticeSink::warn(sprintf(
                        'MinimaxXmlFallbackToolCallParser: an <invoke> element at byte %d carries no '
                        . 'parseable name="..." attribute and is being dropped; that tool call is lost.',
                        $invoke['offset'],
                    ));

                    continue;
                }

                $arguments = $this->parseParameters($invoke['body'], $name);

                // Same dividing line {@see DsmlToolCallParser::parseDsml()}
                // draws, and for the same reason: a truncated WRAPPER still
                // carries complete arguments, but a truncated VALUE is short by
                // an unknown amount. §12 D5's `</parameter>` bug is exactly the
                // latter, and the old behaviour there was the worst available -
                // the parameter failed to match, so the call fired with the
                // argument MISSING and nothing was logged.
                if ($arguments === null) {
                    RuntimeNoticeSink::warn(sprintf(
                        'MinimaxXmlFallbackToolCallParser: possible MiniMax XML-delimiter truncation - '
                        . 'the invoke of "%s" is being refused whole because one of its parameters '
                        . 'could not be read; firing it would run the tool with an argument the model '
                        . 'supplied and this parser dropped.',
                        $name,
                    ));

                    continue;
                }

                if ($invoke['terminator'] !== 'close' && $arguments === []) {
                    RuntimeNoticeSink::warn(sprintf(
                        'MinimaxXmlFallbackToolCallParser: possible MiniMax XML-delimiter truncation - '
                        . 'an <invoke> of "%s" is never closed AND carries no readable parameter; '
                        . 'dropping it rather than firing a zero-argument call.',
                        $name,
                    ));

                    continue;
                }

                $calls[] = new ToolCall(
                    // The XML form carries no call id, but downstream code
                    // matches a ToolResult back to its call by id, so one is
                    // synthesised - positional, hence stable and assertable.
                    id: 'minimax_xml_call_' . count($calls),
                    name: $name,
                    arguments: $arguments,
                );
            }
        }

        return $calls === [] ? null : $calls;
    }

    /**
     * @return array<string, mixed>|null Null refuses the whole invoke.
     */
    private function parseParameters(string $body, string $toolName): ?array
    {
        $arguments = [];

        foreach ($this->scanner->elements($body, self::PARAMETER_TAG) as $parameter) {
            $name = $parameter['attributes']['name'] ?? null;

            if ($name === null || $name === '') {
                error_log(sprintf(
                    'MinimaxXmlFallbackToolCallParser: a <parameter> element on tool "%s" has no '
                    . 'readable name="..." attribute, so its value cannot be assigned to an argument.',
                    $toolName,
                ));

                return null;
            }

            if ($parameter['terminator'] !== 'close') {
                error_log(sprintf(
                    'MinimaxXmlFallbackToolCallParser: possible MiniMax XML-delimiter truncation - '
                    . 'parameter "%s" on tool "%s" is never closed with "%s", so its value is '
                    . 'truncated by an unknown amount.',
                    $name,
                    $toolName,
                    self::PARAMETER_CLOSE,
                ));

                return null;
            }

            $arguments[$name] = $this->coerceValue($parameter['body']);
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
     * KNOWN GAP, still open: the correct disambiguation needs the invoked
     * tool's declared JSON-Schema type, which this class cannot see -
     * {@see parse()} is handed only the response message - so a genuinely
     * number/boolean-typed parameter reaches the tool as its string form.
     * W1.A6 (§12 D6, `ProviderFactory::createSglang()`) has since made this
     * parser reachable, selectable via the `toolCallParser` config key, but D6
     * scopes that step to parser SELECTION only; threading the active tool
     * list through remains unscheduled. It stays latent in practice because
     * the confirmed live deployment passes `--tool-call-parser minimax-m2`, so
     * this parser's XML branch is never entered there.
     */
    private function coerceValue(string $raw): mixed
    {
        // The model emits the value on its own line for multi-line payloads;
        // only that framing newline is shed, so indentation inside file
        // content written by an Edit/Write tool call is preserved exactly.
        // Done with substr rather than preg_replace so that NOTHING on this
        // class's parse path can hit `pcre.backtrack_limit` - see
        // MarkupScanner's docblock for the measured cliff that motivated it.
        $value = $this->stripFramingNewlines($raw);

        $trimmed = trim($value);

        if ($trimmed === '') {
            return $value;
        }

        $decoded = json_decode($trimmed, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : $value;
    }

    /**
     * Sheds at most one `\r?\n` from each end - the exact behaviour of the
     * `/\A\r?\n|\r?\n\z/` this replaces.
     */
    private function stripFramingNewlines(string $value): string
    {
        if (str_starts_with($value, "\r\n")) {
            $value = substr($value, 2);
        } elseif (str_starts_with($value, "\n")) {
            $value = substr($value, 1);
        }

        if (str_ends_with($value, "\r\n")) {
            return substr($value, 0, -2);
        }

        if (str_ends_with($value, "\n")) {
            return substr($value, 0, -1);
        }

        return $value;
    }
}

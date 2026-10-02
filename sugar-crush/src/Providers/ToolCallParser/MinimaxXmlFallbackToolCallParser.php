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
final readonly class MinimaxXmlFallbackToolCallParser implements ToolCallParserInterface, ToolSchemaAware
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

    /**
     * @param ToolParameterTypes|null $parameterTypes The offered tools'
     *        declared parameter types, or null when none were supplied - in
     *        which case every recovered value stays its raw text
     *        ({@see coerceValue()}).
     */
    public function __construct(
        private ToolCallParserInterface $delegate,
        private MarkupScanner $scanner,
        private ?ToolParameterTypes $parameterTypes = null,
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
     * Audit 15a A9: the provider hands over the request's tool schemas so
     * {@see coerceValue()} can type each value by what the tool DECLARED
     * instead of by what the text happens to look like. Forwarded to the
     * delegate when it is schema-aware too, so a stacked chain stays typed.
     */
    public function withParameterTypes(ToolParameterTypes $types): static
    {
        return new self(
            $this->delegate instanceof ToolSchemaAware
                ? $this->delegate->withParameterTypes($types)
                : $this->delegate,
            $this->scanner,
            $types,
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
     * WHERE EACH DIAGNOSTIC GOES, AND THE RULE IS THE SIBLING'S (E170/E171).
     * {@see DsmlToolCallParser::parseDsml()} states it in full: a notice goes
     * on the mid-session transcript seam,
     * {@see \SugarCraft\Crush\Diagnostics\RuntimeNoticeSink::warn()}, if and
     * only if the parser did not produce the call the model asked for; a
     * recovery stays on `error_log()` alone, because a seam row is a
     * `Role::System` message re-sent to the model on every subsequent turn.
     *
     * FOUR OF THIS CLASS'S EIGHT ARE ON THE SEAM: every envelope fenced, an
     * `<invoke>` with no readable name, an invoke refused whole, and an
     * unclosed invoke with no parameter recovered. The other four describe a
     * call that still fired (including {@see coerceValue()}'s audit 15a A9
     * type-mismatch fallback), or a parameter-level refusal whose consequence
     * the invoke-level notice already reports. Both sides are counted from the
     * token stream by
     * {@see \SugarCraft\Crush\Tests\Cli\StderrEmitterCensusTest}'s channels
     * 3 and 6 rather than written down here as a pair of integers.
     *
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
                    'sugarcrush: MinimaxXmlFallbackToolCallParser: possible MiniMax XML-delimiter truncation - '
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
                    'sugarcrush: MinimaxXmlFallbackToolCallParser: a <parameter> element on tool "%s" has no '
                    . 'readable name="..." attribute, so its value cannot be assigned to an argument.',
                    $toolName,
                ));

                return null;
            }

            if ($parameter['terminator'] !== 'close') {
                error_log(sprintf(
                    'sugarcrush: MinimaxXmlFallbackToolCallParser: possible MiniMax XML-delimiter truncation - '
                    . 'parameter "%s" on tool "%s" is never closed with "%s", so its value is '
                    . 'truncated by an unknown amount.',
                    $name,
                    $toolName,
                    self::PARAMETER_CLOSE,
                ));

                return null;
            }

            $arguments[$name] = $this->coerceValue($parameter['body'], $toolName, $name);
        }

        return $arguments;
    }

    /**
     * Parameter values arrive as raw text with no type information, yet tools
     * declare typed JSON-Schema inputs - so the type comes from the TOOL, not
     * from the text (audit 15a A9).
     *
     * THE DEFECT THIS REPLACES. Until A9 any value that json-decoded to an
     * array was decoded, whatever the tool declared. `<invoke name="Write">`
     * with `<parameter name="content">{"name": "acme/x", "require": {}}`
     * therefore handed `Write` a PHP ARRAY for a string-typed `content`, so
     * writing or editing any JSON file (composer.json, package.json, a .json
     * fixture) through this parser failed or wrote the wrong value. Text
     * that LOOKS like JSON is not evidence that the tool wants JSON.
     *
     * THE RULE NOW, keyed on the declared `type` of that parameter
     * ({@see ToolParameterTypes::declared()}):
     *
     * - UNKNOWN (no schema handed over, tool or parameter not offered, or no
     *   `type` declared): the raw text, unchanged. This is a deliberate
     *   behaviour change from "decode arrays when unknown" - the raw text is
     *   the identity transform, so a tool's own validation can report a real
     *   problem, whereas a wrong guess changes the PHP type under the tool.
     * - declares `string` (alone or in a list such as `["string","null"]`):
     *   the raw text. String wins any list it is part of for the same reason.
     * - declares `object` or `array` (e.g. `["object","null"]`): JSON-decoded
     *   when the text decodes to an array.
     * - declares `integer`, `number`, `boolean` or `null`: converted ONLY when
     *   the trimmed text parses cleanly as that scalar - `FILTER_VALIDATE_INT`
     *   (no overflow, no leading zeros), `is_numeric`, the exact literals
     *   `true`/`false`, the exact literal `null`.
     *
     * A declared non-string type the text does not parse as falls back to the
     * raw text with an `error_log()` line naming the tool and parameter - the
     * call still fires, so per the routing rule in {@see parseXml()} it is not
     * transcript-seam material. Scalars are never guessed: before A9 the class
     * had to leave `<parameter name="old_string">1</parameter>` as text because
     * decoding it to int 1 is an uncaught TypeError inside
     * {@see \SugarCraft\Crush\Tools\BuiltIn\Edit} under
     * `declare(strict_types=1)`; with the schema the same `1` is a string for
     * `old_string` and an int for an integer-typed `limit`, both correct.
     *
     * WHERE THE TYPES COME FROM - the KNOWN GAP this docblock used to record
     * ("the correct disambiguation needs the invoked tool's declared
     * JSON-Schema type, which this class cannot see"), closed by A9.
     * {@see parse()} is handed only the response
     * message, so the provider builds a {@see ToolParameterTypes} from
     * `CompleteRequest::$tools` once per request and hands it over through
     * {@see withParameterTypes()} on both its batch and streaming paths
     * ({@see \SugarCraft\Crush\Providers\SglangProvider}). A parser built
     * with {@see new()} and never given types keeps every value as text.
     */
    private function coerceValue(string $raw, string $toolName, string $paramName): mixed
    {
        // The model emits the value on its own line for multi-line payloads;
        // only that framing newline is shed, so indentation inside file
        // content written by an Edit/Write tool call is preserved exactly.
        // Done with substr rather than preg_replace so that NOTHING on this
        // class's parse path can hit `pcre.backtrack_limit` - see
        // MarkupScanner's docblock for the measured cliff that motivated it.
        $value = $this->stripFramingNewlines($raw);

        $declared = $this->parameterTypes?->declared($toolName, $paramName);

        if ($declared === null || in_array('string', $declared, true)) {
            return $value;
        }

        $trimmed = trim($value);

        if (in_array('object', $declared, true) || in_array('array', $declared, true)) {
            $decoded = json_decode($trimmed, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        if (in_array('integer', $declared, true)) {
            $int = filter_var($trimmed, FILTER_VALIDATE_INT);

            if (is_int($int)) {
                return $int;
            }
        }

        if (in_array('number', $declared, true) && is_numeric($trimmed)) {
            $number = $trimmed + 0;

            // `1e999` is numeric but overflows to INF, which no JSON number
            // can be - that is text the tool should see, not a value.
            if (is_int($number) || is_finite($number)) {
                return $number;
            }
        }

        if (in_array('boolean', $declared, true) && ($trimmed === 'true' || $trimmed === 'false')) {
            return $trimmed === 'true';
        }

        if (in_array('null', $declared, true) && $trimmed === 'null') {
            return null;
        }

        error_log(sprintf(
            'sugarcrush: MinimaxXmlFallbackToolCallParser: parameter "%s" on tool "%s" is declared %s '
            . 'but its value does not parse as that type; passing the raw text through untyped.',
            $paramName,
            $toolName,
            implode('|', $declared),
        ));

        return $value;
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

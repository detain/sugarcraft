<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers\ToolCallParser;

use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Last-resort parser for a DeepSeek-V4 deployment whose server was launched
 * *without* a `--tool-call-parser` flag.
 *
 * DeepSeek-V4's NATIVE tool-call emission is DSML markup - neither the OpenAI
 * JSON shape nor MiniMax's XML, so neither existing parser can see it:
 *
 * ```
 * <｜DSML｜tool_calls>
 * <｜DSML｜invoke name="read">
 * <｜DSML｜parameter name="path" string="true">/etc/hosts</｜DSML｜parameter>
 * <｜DSML｜parameter name="limit" string="false">3</｜DSML｜parameter>
 * </｜DSML｜invoke>
 * </｜DSML｜tool_calls>
 * ```
 *
 * Transcribed from `encoding/README.md` and the reference implementation
 * `encoding/encoding_dsv4.py` in `deepseek-ai/DeepSeek-V4-Flash-0731`, both
 * read on 2026-08-20. `encoding_dsv4.py:21` defines the markup token as
 * `dsml_token = "｜DSML｜"`.
 *
 * WHY THIS EXISTS. The live skynet2 deployment returns properly structured
 * `tool_calls` - confirmed on both the batch and the streaming route - but it
 * does so ONLY because someone passed `--tool-call-parser`, which the model
 * card's own documented launch command omits (it shows only
 * `--speculative-algorithm DSPARK --trust-remote-code`). A restart without
 * that flag turns every tool call into raw text in `message.content`, which
 * {@see OpenAiArrayToolCallParser} cannot see and
 * {@see MinimaxXmlFallbackToolCallParser} cannot match - so the agent would
 * silently do nothing on every tool call. That is the failure
 * {@see ToolCallParserInterface}'s docblock says this seam exists to turn into
 * a degradation.
 *
 * EXPOSURE, STATED BECAUSE IT IS NOT EQUAL TO ITS SIBLING'S. This parser is
 * the DERIVED DEFAULT for the DeepSeek-V4 family
 * ({@see \SugarCraft\Crush\Providers\ProviderFactory::defaultToolCallParserFor()}),
 * so it is armed on the default model without anyone configuring it.
 * {@see MinimaxXmlFallbackToolCallParser} is reachable only when explicitly
 * named in the `toolCallParser` config key. Any defect shared between the two
 * is therefore reachable by default HERE and opt-in THERE.
 *
 * ADDITIVE, NOT A REPLACEMENT: the MiniMax fallback stays reachable, because
 * one server may be redeployed onto either model family.
 *
 * DOMAIN OF EVERY CLAIM IN THIS FILE: the DeepSeek-V4 series' DSML markup as
 * documented on that model card. Nothing here is a statement about MiniMax's
 * XML, whose envelope, attribute set and value framing all differ - see
 * {@see coerceValue()} for the one difference most likely to be assumed away.
 */
final readonly class DsmlToolCallParser implements ToolCallParserInterface
{
    /**
     * The markup token, spelled as an explicit codepoint escape ON PURPOSE.
     *
     * Those are FULLWIDTH VERTICAL LINEs (U+FF5C, `EF BD 9C`), not ASCII `|`.
     * A pattern written with ASCII pipes matches nothing, silently - and a
     * test written with the same wrong bytes passes anyway, so the mistake is
     * self-concealing. Writing `\u{FF5C}` rather than pasting the glyph means
     * the intended codepoint survives an editor, a diff tool or a copy/paste
     * that re-encodes the file.
     *
     * Note it does NOT contain U+2581 LOWER ONE EIGHTH BLOCK. That codepoint
     * appears in DeepSeek's SENTENCE tokens (`<｜begin▁of▁sentence｜>`,
     * `<｜end▁of▁sentence｜>`) and in no DSML markup tag. Verified by hex dump
     * of the model card: the DSML tags are `3c ef bd 9c 44 53 4d 4c ef bd 9c`
     * with no `e2 96 81` anywhere.
     */
    private const DSML_TOKEN = "\u{FF5C}DSML\u{FF5C}";

    private const ENVELOPE_TAG = 'tool_calls';

    private const INVOKE_TAG = 'invoke';

    private const PARAMETER_TAG = 'parameter';

    /**
     * A CHEAP REJECT, NOT A DECISION.
     *
     * `str_contains` on this string decides only whether the positional scan is
     * worth running at all, so an ordinary assistant turn costs one substring
     * search. It deliberately does NOT decide that a tool call is present -
     * that used to be the whole gate, and it is the bug documented at length in
     * {@see MarkupScanner}: a message that QUOTES the markup contains the
     * marker just as surely as one that emits it, and the old gate fabricated a
     * real `rm_rf` call out of prose explaining the format. Whether an envelope
     * is an action is now {@see MarkupScanner::envelopes()}'s judgement.
     */
    private const ENVELOPE_PREFILTER = '<' . self::DSML_TOKEN . self::ENVELOPE_TAG;

    private const INVOKE_OPEN = '<' . self::DSML_TOKEN . self::INVOKE_TAG;

    private const PARAMETER_OPEN = '<' . self::DSML_TOKEN . self::PARAMETER_TAG;

    private const PARAMETER_CLOSE = '</' . self::DSML_TOKEN . self::PARAMETER_TAG . '>';

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
            // `requireStartToken: true` because DeepSeek-V4 documents one -
            // see MarkupScanner::qualifies(), which explains why its MiniMax
            // sibling deliberately does not get the same guard.
            MarkupScanner::new(self::DSML_TOKEN, true),
        );
    }

    /**
     * @param array<string, mixed> $message
     * @return array<ToolCall>|null
     */
    public function parse(array $message): ?array
    {
        // A server-parsed `tool_calls` array is always authoritative; the DSML
        // scan is only ever reached when that array is absent. This delegation
        // is what makes arming this parser nearly free on a CORRECTLY
        // configured deployment.
        if (isset($message['tool_calls'])) {
            return $this->delegate->parse($message);
        }

        $content = $message['content'] ?? null;

        if (!is_string($content) || !str_contains($content, self::ENVELOPE_PREFILTER)) {
            return $this->delegate->parse($message);
        }

        // Falling through to the delegate when the scan recovers nothing keeps
        // a stacked fallback composable: "this content only TALKS about DSML"
        // and "there is no DSML here" must reach the next parser identically.
        return $this->parseDsml($content) ?? $this->delegate->parse($message);
    }

    /**
     * MALFORMED INPUT POLICY, stated once because it reconciles two rules that
     * pull opposite ways.
     *
     * CONTRIBUTING.md forbids silent failures; {@see ToolCallParserInterface}
     * contracts a `?array` return and this runs inside a live provider parse,
     * where throwing would abort the whole turn. The reference implementation
     * `encoding_dsv4.py` raises `ValueError` on every anomaly, but it is
     * explicitly documented as parsing only well-formed text - it is an
     * encoder's round-trip check, not a recovery path, and this class is
     * reached ONLY when something is already wrong.
     *
     * So: RECOVER WHAT IS UNAMBIGUOUS, REFUSE WHAT IS NOT, AND LOG BOTH. The
     * dividing line is whether an ARGUMENT'S VALUE is intact:
     *
     * - A truncated WRAPPER is recoverable. An invoke that never closed, but
     *   whose parameters all closed, carries complete arguments; dropping it
     *   would lose a call for a missing delimiter.
     * - A truncated VALUE is not. When a parameter never closes, its value is
     *   short by an unknown amount, and there is no safe way to hand a
     *   half-written payload to `write()`. The whole invoke is refused.
     * - A parameter whose NAME cannot be read is the same case: the argument
     *   exists and its key is unknown, so the call cannot be reconstructed.
     *
     * That refusal is a deliberate reversal of the "recover as much as
     * possible" instinct, and the reason is the same asymmetry
     * {@see MarkupScanner::qualifies()} argues from: a call that runs with an
     * argument the model supplied and the parser dropped is UNRECOVERABLE -
     * `read()` with no `path`, `write()` with no `content` - while a refused
     * call is logged, visible, and something the model can retry. Refusing is
     * not silent; dropping the argument was.
     *
     * WHERE EACH DIAGNOSTIC GOES, AND THE RULE IS ONE SENTENCE (E170/E171).
     * A notice goes on the mid-session transcript seam,
     * {@see \SugarCraft\Crush\Diagnostics\RuntimeNoticeSink::warn()}, IF AND
     * ONLY IF this parser did not produce the call the model asked for.
     * Everything else - a recovery, a coercion - stays on `error_log()` alone.
     *
     * WHY THAT LINE AND NOT "EVERYTHING THE USER MIGHT LIKE TO KNOW". A seam
     * row is a `Role::System` message in the CONVERSATION, so it is re-sent to
     * the model on every subsequent turn for the rest of the session. That
     * makes it worth paying for when the model needs to know its call did not
     * run - it can then retry - and not worth paying for when the call DID run
     * and something was merely repaired on the way. `error_log()` keeps the
     * complete record either way; it is unclipped and it costs no tokens.
     *
     * FOUR OF THIS CLASS'S ELEVEN DIAGNOSTICS ARE ON THE SEAM: no envelope was
     * positioned like an action, an invoke with no readable name, an invoke
     * refused whole for an unreadable parameter, and a truncated invoke with
     * nothing recovered. The other seven describe a call that still fired - an
     * unclosed envelope whose invokes were recovered, a truncated invoke whose
     * parameters were complete, a duplicate parameter where the first was kept,
     * a `string=` flag that was neither value, a `string="false"` whose payload
     * was not JSON - plus the two parameter-level refusals whose consequence is
     * already reported by the invoke-level one. THAT SPLIT IS NOT WRITTEN DOWN
     * AS A COUNT ANYWHERE THAT MATTERS: it is derived by
     * {@see \SugarCraft\Crush\Tests\Cli\StderrEmitterCensusTest}'s channels
     * 3 and 6, which count both sides from the token stream.
     *
     * @return array<ToolCall>|null
     */
    private function parseDsml(string $content): ?array
    {
        $calls = [];
        $envelopes = $this->scanner->envelopes($content, self::ENVELOPE_TAG);

        if ($envelopes === []) {
            // THE COST OF LEANING STRICT, MADE OBSERVABLE. The prefilter found
            // the marker and the positional scan judged no occurrence of it an
            // action. Usually that is the point - prose quoting the protocol -
            // but it is also what a FALSE NEGATIVE looks like, and a guard that
            // can drop a genuine call must not do it silently. This line is the
            // difference between "the fallback is armed and chose not to fire"
            // and the symptom this class exists to eliminate, which is
            // indistinguishable from it without a log.
            RuntimeNoticeSink::warn(sprintf(
                'DsmlToolCallParser: content carries "%s" but no occurrence of it is positioned like '
                . 'an action - each is inside a ``` code fence, or run on from prose with no blank '
                . 'line before it (DeepSeek-V4 documents "\n\n" as part of the start token, '
                . 'enc.py:726). No tool call recovered. If the model really did request a tool here, '
                . 'the fix is upstream of this parser: relaunch the server with --tool-call-parser so '
                . 'the call arrives as a structured tool_calls[] array instead of as text.',
                self::ENVELOPE_PREFILTER,
            ));
        }

        foreach ($envelopes as $envelope) {
            if (!$envelope['closed']) {
                error_log(sprintf(
                    'DsmlToolCallParser: a "%s>" envelope opened at byte %d is never closed; '
                    . 'recovering whatever invoke(s) it had already emitted.',
                    self::ENVELOPE_PREFILTER,
                    $envelope['offset'],
                ));
            }

            foreach ($this->scanner->elements($envelope['body'], self::INVOKE_TAG) as $invoke) {
                $call = $this->toolCall($invoke, count($calls));

                if ($call !== null) {
                    $calls[] = $call;
                }
            }
        }

        return $calls === [] ? null : $calls;
    }

    /**
     * One `<｜DSML｜invoke>` element, or null when it is not a call this parser
     * is willing to fire.
     *
     * @param array{attributes: array<string, string>, body: string, offset: int, terminator: string} $invoke
     */
    private function toolCall(array $invoke, int $index): ?ToolCall
    {
        $name = $invoke['attributes']['name'] ?? null;

        // No `name=`, or an empty one. A tool call with no tool name is not a
        // call and cannot be dispatched - but "the model tried to call a tool
        // and nothing happened" is exactly the symptom this class exists to
        // eliminate, so it must not vanish without a trace.
        if ($name === null || $name === '') {
            RuntimeNoticeSink::warn(sprintf(
                'DsmlToolCallParser: an "%s" element at byte %d carries no parseable name="..." '
                . 'attribute and is being dropped; that tool call is lost.',
                self::INVOKE_OPEN,
                $invoke['offset'],
            ));

            return null;
        }

        $truncated = $invoke['terminator'] !== 'close';
        $arguments = $this->parseParameters($invoke['body'], $name);

        if ($arguments === null) {
            RuntimeNoticeSink::warn(sprintf(
                'DsmlToolCallParser: the invoke of "%s" is being refused whole because one of its '
                . 'parameters could not be read; firing it would run the tool with an argument the '
                . 'model supplied and this parser dropped.',
                $name,
            ));

            return null;
        }

        if ($truncated) {
            // A truncated invoke that recovered NOTHING is the shape a nested
            // `<｜DSML｜invoke>` produces for its outer element, and the shape a
            // generation cut off mid-open-tag produces. Either way there is no
            // evidence the model meant a zero-argument call, so firing one
            // would be fabricating it. A CLOSED invoke with no parameters is a
            // different thing entirely - the model asserted it - and is kept.
            if ($arguments === []) {
                RuntimeNoticeSink::warn(sprintf(
                    'DsmlToolCallParser: an invoke of "%s" is never closed with "</%s>" AND carries '
                    . 'no readable parameter - it is either nested inside another invoke or was cut '
                    . 'off mid-tag; dropping it rather than firing a zero-argument call.',
                    $name,
                    self::DSML_TOKEN . self::INVOKE_TAG,
                ));

                return null;
            }

            error_log(sprintf(
                'DsmlToolCallParser: an invoke of "%s" is never closed with "</%s>" - the '
                . 'generation looks truncated; recovering the %d parameter(s) it had already '
                . 'emitted.',
                $name,
                self::DSML_TOKEN . self::INVOKE_TAG,
                count($arguments),
            ));
        }

        return new ToolCall(
            // DSML carries no call id - the reference implementation's
            // `tool_calls_to_openai_format()` emits `type` and `function` only.
            // Downstream matches a ToolResult back to its call by id, so one is
            // synthesised: positional, hence stable and assertable.
            id: 'dsml_call_' . $index,
            name: $name,
            arguments: $arguments,
        );
    }

    /**
     * @return array<string, mixed>|null Null refuses the whole invoke; see
     *         {@see parseDsml()} for why that is the right answer for an
     *         unreadable argument and the wrong one for a truncated wrapper.
     */
    private function parseParameters(string $body, string $toolName): ?array
    {
        $arguments = [];

        foreach ($this->scanner->elements($body, self::PARAMETER_TAG) as $parameter) {
            $name = $parameter['attributes']['name'] ?? null;

            if ($name === null || $name === '') {
                error_log(sprintf(
                    'DsmlToolCallParser: a "%s" element on tool "%s" has no readable name="..." '
                    . 'attribute, so its value cannot be assigned to an argument.',
                    self::PARAMETER_OPEN,
                    $toolName,
                ));

                return null;
            }

            if ($parameter['terminator'] !== 'close') {
                error_log(sprintf(
                    'DsmlToolCallParser: parameter "%s" on tool "%s" is never closed with "%s", so '
                    . 'its value is truncated by an unknown amount.',
                    $name,
                    $toolName,
                    self::PARAMETER_CLOSE,
                ));

                return null;
            }

            // FIRST WINS on a duplicate. The reference implementation treats a
            // repeat as fatal (`Duplicate parameter name`), i.e. upstream
            // considers it impossible, so there is no documented intent to
            // honour - which means either choice is arbitrary and the one that
            // matters is that it be DETERMINISTIC and logged rather than
            // quietly last-write-wins. First also matches the truncation story
            // the rest of this class is built around: a repeated emission is
            // likelier a restart artefact than a correction.
            if (array_key_exists($name, $arguments)) {
                error_log(sprintf(
                    'DsmlToolCallParser: duplicate parameter "%s" on tool "%s"; keeping the '
                    . 'first occurrence and discarding the later one.',
                    $name,
                    $toolName,
                ));

                continue;
            }

            $arguments[$name] = $this->coerceValue(
                $parameter['body'],
                $parameter['attributes']['string'] ?? null,
                $toolName,
                $name,
            );
        }

        return $arguments;
    }

    /**
     * Applies the `string=` flag, which is LOAD-BEARING and is the whole
     * reason this parser can be typed correctly where the MiniMax one cannot.
     *
     * Per the model card: `string="true"` means the value is a raw string;
     * `string="false"` means it is JSON (number, boolean, array, object). So
     * the model states the type of every argument it emits, and a parser that
     * ignores the flag hands a tool the string `"3"` where it declared an
     * integer.
     *
     * THE FLAG IS READ TOLERANTLY AND ITS VALUE IS OBSERVED RATHER THAN
     * MATCHED. {@see MarkupScanner::scanAttributes()} accepts `string="true"`,
     * `string='true'` and `string=true` alike, and a MISSING flag arrives here
     * as null. The reference implementation's pattern hard-required one
     * spelling; this class's docblock used to claim it did better, and for a
     * while that claim was false - the pattern still required `string="…"`, so
     * every one of those variants made the parameter fail to match and vanish,
     * and the call fired with the argument missing and nothing logged. The
     * claim is now true because the attribute reader, not a regex, decides.
     *
     * THIS IS THE ONE PLACE DSML AND MINIMAX XML DIVERGE MOST, and assuming
     * otherwise is the likely future bug. {@see
     * MinimaxXmlFallbackToolCallParser::coerceValue()} must GUESS - its markup
     * carries no type information, so it deliberately decodes only JSON arrays
     * and leaves every scalar as text to avoid handing a string-typed
     * parameter an int. That reasoning is correct THERE and wrong HERE: the
     * ambiguity it works around does not exist in DSML, so guessing would
     * discard information the model actually sent. Its KNOWN GAP - that
     * correct disambiguation needs the tool's declared JSON-Schema type, which
     * the parser cannot see - is therefore NOT a gap in this class.
     *
     * KNOWN LIMITATION, DOCUMENTED RATHER THAN FIXED: `json_decode` maps an
     * integer literal too large for a PHP int to a float, so
     * `string="false"` with `12345678901234567890123` reaches the tool as
     * `1.2345678901234568e+22`. Decoding with `JSON_BIGINT_AS_STRING` would
     * trade that for handing a number-typed parameter a string, which under
     * `declare(strict_types=1)` is a TypeError inside the tool rather than a
     * rounding error. Neither is right without the tool's declared schema, and
     * this class is not handed one - the same gap the MiniMax parser records.
     *
     * VALUE FRAMING ALSO DIFFERS. MiniMax's parser strips one framing newline
     * from each end. DSML values are taken VERBATIM between `>` and
     * `</｜DSML｜parameter>`: the reference implementation's parameter regex
     * (`encoding_dsv4.py:684`) is `^ name="(.*?)" string="(true|false)">(.*?)<$`
     * over a span read up to `/｜DSML｜parameter`, which captures the value
     * with no trimming at all. Stripping here would corrupt file content
     * written by an Edit/Write tool call whose payload legitimately starts or
     * ends with a newline.
     */
    private function coerceValue(string $raw, ?string $stringFlag, string $toolName, string $paramName): mixed
    {
        if ($stringFlag === 'true') {
            return $raw;
        }

        if ($stringFlag !== 'false') {
            // Missing, or neither documented value. Treated as a raw string
            // because that is the IDENTITY transform - it preserves the exact
            // payload the model sent, so a tool's own validation can report the
            // real problem. Dropping the parameter would instead hand the tool
            // a silently-missing argument.
            error_log(sprintf(
                'DsmlToolCallParser: parameter "%s" on tool "%s" has string=%s, which is '
                . 'neither "true" nor "false"; treating the value as a raw string.',
                $paramName,
                $toolName,
                $stringFlag === null ? '(absent)' : sprintf('"%s"', $stringFlag),
            ));

            return $raw;
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // `string="false"` promised JSON and did not deliver it. The raw
            // text is kept for the same reason as above: it is the only
            // information available, and losing the argument entirely is
            // strictly worse than passing it on in the wrong type with a log
            // line naming the tool and the parameter.
            error_log(sprintf(
                'DsmlToolCallParser: parameter "%s" on tool "%s" declares string="false" but its '
                . 'value is not valid JSON (%s); passing the raw text through untyped.',
                $paramName,
                $toolName,
                json_last_error_msg(),
            ));

            return $raw;
        }

        // NOT routed through SglangProvider::decodeToolArguments(). That
        // decoder reports the MiniMax `</parameter>` truncation bug on a JSON
        // `function.arguments` BLOB - one string holding every argument. A
        // DSML parameter is not that shape: each one is its own element with
        // its own `string=` flag, so there is no blob to decode, no
        // all-or-nothing failure to diagnose, and its truncation warning would
        // name a bug from a different model family.
        return $decoded;
    }
}

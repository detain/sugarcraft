<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers\ToolCallParser;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Providers\ToolCallParser\DsmlToolCallParser;
use SugarCraft\Crush\Providers\ToolCallParser\MinimaxXmlFallbackToolCallParser;
use SugarCraft\Crush\Providers\ToolCallParser\OpenAiArrayToolCallParser;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * DeepSeek-V4 native DSML tool-call recovery.
 *
 * EVERY payload in this file is assembled from {@see DSML} below, which spells
 * the markup token as RAW BYTES (`\xEF\xBD\x9C` = U+FF5C FULLWIDTH VERTICAL
 * LINE). That is deliberate and is the point of
 * {@see testAnAsciiPipePayloadIsNotMistakenForDsml}: if the parser were
 * written with ASCII `|`, a test that built its fixtures the same wrong way
 * would pass anyway. Spelling the bytes here, independently of the
 * implementation's `\u{FF5C}` escape, means the two have to agree on the wire
 * format rather than merely agreeing with each other.
 */
final class DsmlToolCallParserTest extends TestCase
{
    /**
     * The markup token, byte-for-byte as it appears in the model card. Hex
     * dumped from `deepseek-ai/DeepSeek-V4-Flash-0731`'s `encoding/README.md`:
     * `3c ef bd 9c 44 53 4d 4c ef bd 9c`. Note there is NO `e2 96 81`
     * (U+2581) in a DSML tag - that codepoint belongs to the sentence tokens
     * `<｜begin▁of▁sentence｜>` / `<｜end▁of▁sentence｜>` only.
     */
    private const DSML = "\xEF\xBD\x9CDSML\xEF\xBD\x9C";

    private string $logFile;

    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        parent::setUp();

        // Same error_log capture pattern the MiniMax fallback's tests use:
        // this parser reports every malformed-input recovery through
        // error_log, and those lines must not spray across the suite's output.
        $this->logFile = tempnam(sys_get_temp_dir(), 'dsml-parser-log');
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);

        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    private function loggedOutput(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    /**
     * Builds a well-formed DSML envelope from raw bytes.
     *
     * @param array<int, array{0: string, 1: string}> $parameters name => [flag, value] triples
     */
    private static function invoke(string $tool, array $parameters): string
    {
        $d = self::DSML;
        $body = '';

        foreach ($parameters as [$name, $flag, $value]) {
            $body .= "<{$d}parameter name=\"{$name}\" string=\"{$flag}\">{$value}</{$d}parameter>\n";
        }

        return "<{$d}invoke name=\"{$tool}\">\n{$body}</{$d}invoke>\n";
    }

    private static function envelope(string ...$invokes): string
    {
        $d = self::DSML;

        return "<{$d}tool_calls>\n" . implode('', $invokes) . "</{$d}tool_calls>";
    }

    // -------------------------------------------------------------------------
    // THE DELIVERABLE: a DSML payload arriving as `content`, with no
    // `tool_calls` key, becomes correctly TYPED ToolCall objects.
    // -------------------------------------------------------------------------

    /**
     * The whole point of the class, and it fails against every parser that
     * existed before it - see
     * {@see testNeitherPreexistingParserCanSeeADsmlPayload}, which measures
     * that rather than asserting it.
     *
     * TYPES, not just values. `string="false"` means the value is JSON, so
     * `3` must arrive as PHP int 3. A parser that ignored the flag would hand
     * a tool that declared an integer the string `"3"`, and under
     * `declare(strict_types=1)` that is a TypeError inside the tool, not a
     * soft failure.
     */
    public function testADsmlPayloadInContentYieldsCorrectlyTypedToolCalls(): void
    {
        $content = self::envelope(self::invoke('read', [
            ['path', 'true', '/etc/hosts'],
            ['limit', 'false', '3'],
            ['recursive', 'false', 'true'],
            ['ratio', 'false', '0.5'],
            ['tags', 'false', '["a","b"]'],
            ['opts', 'false', '{"deep":true}'],
        ]));

        $calls = DsmlToolCallParser::new()->parse(['content' => $content]);

        $this->assertIsArray($calls);
        $this->assertCount(1, $calls);
        $this->assertInstanceOf(ToolCall::class, $calls[0]);
        $this->assertSame('read', $calls[0]->name());

        $arguments = $calls[0]->arguments();

        $this->assertSame('/etc/hosts', $arguments['path']);
        $this->assertSame(3, $arguments['limit'], 'string="false" means JSON, so this is an int');
        $this->assertTrue($arguments['recursive']);
        $this->assertSame(0.5, $arguments['ratio']);
        $this->assertSame(['a', 'b'], $arguments['tags']);
        $this->assertSame(['deep' => true], $arguments['opts']);
    }

    /**
     * The OTHER direction of the same flag, and the half a "decode everything
     * that looks like JSON" parser gets wrong.
     *
     * `string="true"` means RAW STRING even when the text is a perfectly good
     * JSON scalar. A tool whose `old_string` parameter is the literal text `3`
     * must receive `"3"`.
     */
    public function testTheStringTrueFlagKeepsAJsonLookingValueAsAString(): void
    {
        $content = self::envelope(self::invoke('edit', [
            ['old_string', 'true', '3'],
            ['new_string', 'true', 'true'],
            ['note', 'true', '{"not":"json"}'],
        ]));

        $calls = DsmlToolCallParser::new()->parse(['content' => $content]);

        $this->assertIsArray($calls);
        $arguments = $calls[0]->arguments();

        $this->assertSame('3', $arguments['old_string']);
        $this->assertSame('true', $arguments['new_string']);
        $this->assertSame('{"not":"json"}', $arguments['note']);

        // AND `string="true"` is a DOCUMENTED flag, so handling it must be
        // silent. Without this, the `=== 'true'` branch can be deleted
        // outright and every test still passes: the value falls through to the
        // `!== 'false'` arm, which also returns the raw string and differs
        // only by an error_log nobody was asserting on. That is precisely a
        // test pinning a clause's PRESENCE rather than its TRUTH.
        $this->assertSame(
            '',
            $this->loggedOutput(),
            'a well-formed string="true" parameter is not an anomaly and must not be reported',
        );
    }

    /**
     * MEASURES the "and it yields nothing today" half of the deliverable
     * rather than asserting it in prose.
     *
     * Both pre-existing parsers return null on a real DSML payload, which is
     * the silent total failure this class exists to end: the model asks for a
     * tool, and the agent does nothing at all.
     */
    public function testNeitherPreexistingParserCanSeeADsmlPayload(): void
    {
        $message = ['content' => self::envelope(self::invoke('read', [['path', 'true', '/etc/hosts']]))];

        $this->assertNull(
            OpenAiArrayToolCallParser::new()->parse($message),
            'the OpenAI-array parser reads only tool_calls[], so DSML is invisible to it',
        );
        $this->assertNull(
            MinimaxXmlFallbackToolCallParser::new()->parse($message),
            'the MiniMax fallback scans for <minimax:tool_call>, which DSML never emits',
        );
        $this->assertNotNull(
            DsmlToolCallParser::new()->parse($message),
            'and this is the whole delta',
        );
    }

    /**
     * The bytes themselves, tested through behaviour.
     *
     * An otherwise byte-identical payload written with ASCII `|` instead of
     * U+FF5C must NOT parse. If the implementation were written with ASCII
     * pipes this assertion inverts, which is the only way to catch a mistake
     * that is otherwise completely silent.
     */
    public function testAnAsciiPipePayloadIsNotMistakenForDsml(): void
    {
        $real = self::envelope(self::invoke('read', [['path', 'true', '/etc/hosts']]));
        $ascii = str_replace(self::DSML, '|DSML|', $real);

        $this->assertStringNotContainsString(self::DSML, $ascii, 'fixture sanity: the token was replaced');

        $this->assertNull(
            DsmlToolCallParser::new()->parse(['content' => $ascii]),
            'ASCII pipes are a different byte sequence and must not trigger the scan',
        );
        $this->assertNotNull(DsmlToolCallParser::new()->parse(['content' => $real]));
    }

    /**
     * U+2581 belongs to the SENTENCE tokens, not to DSML markup. A tag
     * carrying it is not a DSML tag, and treating it as one would mean the
     * pattern had been written from the wrong half of the model card.
     */
    public function testATagCarryingTheSentenceTokenBlockCharacterIsNotDsml(): void
    {
        $withBlock = str_replace(self::DSML, "\xEF\xBD\x9CDS\xE2\x96\x81ML\xEF\xBD\x9C", self::envelope(
            self::invoke('read', [['path', 'true', '/etc/hosts']]),
        ));

        $this->assertNull(DsmlToolCallParser::new()->parse(['content' => $withBlock]));
    }

    // -------------------------------------------------------------------------
    // Delegation - what makes arming this parser nearly free.
    // -------------------------------------------------------------------------

    public function testAServerParsedToolCallsArrayIsAlwaysAuthoritative(): void
    {
        // Both present: the structured array must win outright, and the DSML
        // in content must not add a second, duplicate call.
        $calls = DsmlToolCallParser::new()->parse([
            'tool_calls' => [
                ['id' => 'call_real', 'function' => ['name' => 'grep', 'arguments' => '{"q":"x"}']],
            ],
            'content' => self::envelope(self::invoke('read', [['path', 'true', '/etc/hosts']])),
        ]);

        $this->assertIsArray($calls);
        $this->assertCount(1, $calls, 'the DSML in content must not be parsed as a second call');
        $this->assertSame('call_real', $calls[0]->id());
        $this->assertSame('grep', $calls[0]->name());
    }

    public function testOrdinaryProseDelegatesAndYieldsNull(): void
    {
        $this->assertNull(DsmlToolCallParser::new()->parse([
            'content' => 'I could call a tool here, and DSML is a markup language.',
        ]));
    }

    /**
     * An envelope that opens and closes with NOTHING inside it must still
     * answer null, not `[]`.
     *
     * This is the one route into the scan that can produce an empty result,
     * so it is the only place the null-vs-empty contract is actually
     * exercised - {@see testAMessageWithNoToolsReturnsNullRatherThanAnEmptyArray}
     * never enters the scan at all, because there is no marker to trigger it.
     */
    public function testAnEmptyEnvelopeYieldsNullRatherThanAnEmptyArray(): void
    {
        $result = DsmlToolCallParser::new()->parse(['content' => self::envelope()]);

        $this->assertNull($result);
        $this->assertNotSame([], $result, 'CompleteResponse::$toolCalls distinguishes the two');
    }

    public function testAMessageWithNoToolsReturnsNullRatherThanAnEmptyArray(): void
    {
        // CompleteResponse::$toolCalls distinguishes null from [], and the
        // providers propagate that verbatim - see ToolCallParserInterface.
        $result = DsmlToolCallParser::new()->parse(['content' => '']);

        $this->assertNull($result);
        $this->assertNotSame([], $result);
    }

    // -------------------------------------------------------------------------
    // Multiple calls.
    // -------------------------------------------------------------------------

    public function testTwoParallelInvokesBecomeTwoCallsWithDistinctIds(): void
    {
        $content = self::envelope(
            self::invoke('read', [['path', 'true', '/a']]),
            self::invoke('write', [['path', 'true', '/b'], ['bytes', 'false', '12']]),
        );

        $calls = DsmlToolCallParser::new()->parse(['content' => $content]);

        $this->assertCount(2, $calls);
        $this->assertSame('read', $calls[0]->name());
        $this->assertSame('write', $calls[1]->name());
        $this->assertSame(12, $calls[1]->arguments()['bytes']);
        $this->assertNotSame(
            $calls[0]->id(),
            $calls[1]->id(),
            'downstream matches a ToolResult back to its call by id, so ids must be distinct',
        );
    }

    // -------------------------------------------------------------------------
    // Malformed input: RECOVER AS MUCH AS POSSIBLE, and log every anomaly.
    // -------------------------------------------------------------------------

    public function testAnUnclosedInvokeStillRecoversTheParametersItEmitted(): void
    {
        $d = self::DSML;
        $content = "<{$d}tool_calls>\n<{$d}invoke name=\"read\">\n"
            . "<{$d}parameter name=\"path\" string=\"true\">/etc/hosts</{$d}parameter>\n";

        $calls = DsmlToolCallParser::new()->parse(['content' => $content]);

        $this->assertCount(1, $calls, 'a truncated call is still a call, and dropping it is the silent mode');
        $this->assertSame('read', $calls[0]->name());
        $this->assertSame('/etc/hosts', $calls[0]->arguments()['path']);
        $this->assertStringContainsString('never closed', $this->loggedOutput());
    }

    public function testAnInvokeWithNoNameAttributeIsDroppedButReported(): void
    {
        $d = self::DSML;
        $content = self::envelope(
            "<{$d}invoke>\n<{$d}parameter name=\"path\" string=\"true\">/a</{$d}parameter>\n</{$d}invoke>\n",
            self::invoke('read', [['path', 'true', '/b']]),
        );

        $calls = DsmlToolCallParser::new()->parse(['content' => $content]);

        $this->assertCount(1, $calls, 'a call with no tool name is not recoverable');
        $this->assertSame('read', $calls[0]->name(), 'but the well-formed sibling still survives');
        $this->assertStringContainsString(
            'no parseable name',
            $this->loggedOutput(),
            'and the dropped call must not vanish without a trace',
        );
    }

    public function testAValueDeclaredJsonThatIsNotJsonFallsBackToRawTextAndIsReported(): void
    {
        $content = self::envelope(self::invoke('read', [
            ['limit', 'false', 'not json at all'],
            ['path', 'true', '/etc/hosts'],
        ]));

        $calls = DsmlToolCallParser::new()->parse(['content' => $content]);

        $this->assertCount(1, $calls);
        $this->assertSame(
            'not json at all',
            $calls[0]->arguments()['limit'],
            'the raw text is kept: losing the argument entirely is strictly worse',
        );
        $this->assertArrayHasKey('path', $calls[0]->arguments(), 'and the sibling parameter is unaffected');

        $log = $this->loggedOutput();
        $this->assertStringContainsString('not valid JSON', $log);
        $this->assertStringContainsString('limit', $log, 'the log must name the parameter');
        $this->assertStringContainsString('read', $log, 'and the tool');
    }

    public function testAStringFlagThatIsNeitherTrueNorFalseIsTreatedAsARawStringAndReported(): void
    {
        $content = self::envelope(self::invoke('read', [['path', 'maybe', '/etc/hosts']]));

        $calls = DsmlToolCallParser::new()->parse(['content' => $content]);

        $this->assertCount(1, $calls);
        $this->assertSame(
            '/etc/hosts',
            $calls[0]->arguments()['path'],
            'the identity transform preserves the payload the model actually sent',
        );
        $this->assertStringContainsString('neither "true" nor "false"', $this->loggedOutput());
    }

    public function testADuplicateParameterKeepsTheFirstOccurrenceAndReportsIt(): void
    {
        $content = self::envelope(self::invoke('read', [
            ['path', 'true', '/first'],
            ['path', 'true', '/second'],
        ]));

        $calls = DsmlToolCallParser::new()->parse(['content' => $content]);

        $this->assertSame('/first', $calls[0]->arguments()['path']);
        $this->assertStringContainsString('duplicate parameter', strtolower($this->loggedOutput()));
    }

    public function testAnUnclosedEnvelopeStillRecoversTheInvokeItContained(): void
    {
        $d = self::DSML;
        $content = self::envelope(self::invoke('read', [['path', 'true', '/a']]))
            . "\n<{$d}tool_calls>\n" . self::invoke('write', [['path', 'true', '/b']]);

        $calls = DsmlToolCallParser::new()->parse(['content' => $content]);

        $this->assertCount(2, $calls, 'the complete envelope AND the truncated one');
        $this->assertSame('read', $calls[0]->name());
        $this->assertSame('write', $calls[1]->name());
    }

    public function testACompleteEnvelopeIsNotReparsedIntoDuplicateCalls(): void
    {
        $content = self::envelope(self::invoke('read', [['path', 'true', '/a']]));

        $calls = DsmlToolCallParser::new()->parse(['content' => $content]);

        $this->assertCount(1, $calls, 'the unclosed-envelope recovery must not re-consume a closed one');
    }

    // -------------------------------------------------------------------------
    // Value framing - where DSML and MiniMax XML deliberately differ.
    // -------------------------------------------------------------------------

    /**
     * DSML values are taken VERBATIM. The MiniMax parser strips one framing
     * newline from each end because its markup puts multi-line payloads on
     * their own line; DSML's reference implementation captures everything
     * between `>` and `</｜DSML｜parameter>` with no trimming at all.
     *
     * This matters for a Write/Edit tool call whose file content legitimately
     * begins or ends with a newline - trimming would silently corrupt it.
     */
    public function testAMultilineValueIsTakenVerbatimWithoutTrimming(): void
    {
        $payload = "\n    indented\n\nblank line above\n";
        $content = self::envelope(self::invoke('write', [['content', 'true', $payload]]));

        $calls = DsmlToolCallParser::new()->parse(['content' => $content]);

        $this->assertSame(
            $payload,
            $calls[0]->arguments()['content'],
            'leading/trailing newlines and interior indentation must survive byte-for-byte',
        );
    }

    // -------------------------------------------------------------------------
    // Envelope embedded in a real turn.
    // -------------------------------------------------------------------------

    /**
     * The realistic shape: reasoning, then prose, then the envelope, then the
     * end-of-sentence token - all in one `content` string.
     */
    public function testTheEnvelopeIsFoundInsideASurroundingAssistantTurn(): void
    {
        $content = "<think>I should read it.</think>Let me read that file.\n\n"
            . self::envelope(self::invoke('read', [['path', 'true', '/etc/hosts'], ['limit', 'false', '10']]))
            . "\xEF\xBD\x9Cend\xE2\x96\x81of\xE2\x96\x81sentence\xEF\xBD\x9C";

        $calls = DsmlToolCallParser::new()->parse(['content' => $content]);

        $this->assertCount(1, $calls);
        $this->assertSame('read', $calls[0]->name());
        $this->assertSame(10, $calls[0]->arguments()['limit']);
    }

    // -------------------------------------------------------------------------
    // Composition.
    // -------------------------------------------------------------------------

    public function testANonDsmlMessageIsHandedToTheInjectedDelegate(): void
    {
        $parser = DsmlToolCallParser::new(MinimaxXmlFallbackToolCallParser::new());

        $calls = $parser->parse([
            'content' => '<minimax:tool_call><invoke name="read">'
                . '<parameter name="path">/etc/hosts</parameter></invoke></minimax:tool_call>',
        ]);

        $this->assertIsArray($calls, 'a stacked fallback must still reach its delegate');
        $this->assertSame('read', $calls[0]->name());
    }

    // -------------------------------------------------------------------------
    // B1: a QUOTATION of the protocol is not an ACTION.
    // -------------------------------------------------------------------------

    /**
     * THE REGRESSION THAT MATTERS MOST IN THIS FILE.
     *
     * Detection used to be `str_contains($content, '<｜DSML｜tool_calls>')`,
     * which cannot distinguish a model EMITTING the markup from a model
     * QUOTING it. Against that code this exact message returned one real call,
     * `rm_rf` with `path=/`.
     *
     * It is not a contrived prompt. DeepSeek-V4's `render_tools`
     * (`encoding_dsv4.py:84-94`) puts a worked DSML example into the SYSTEM
     * PROMPT, so a model asked how tool calling works answers by quoting its
     * own instructions back. A fabricated call that then EXECUTES is the exact
     * inversion of the failure this class exists to prevent.
     */
    public function testProseThatQuotesTheMarkupInsideACodeFenceFabricatesNoCall(): void
    {
        $d = self::DSML;
        $content = "Sure! To call a tool you emit markup like this:\n\n```\n"
            . self::envelope(self::invoke('rm_rf', [['path', 'true', '/']]))
            . "\n```\n\nThat's the DSML format. I have not actually called anything.";

        $this->assertNull(
            DsmlToolCallParser::new()->parse(['content' => $content]),
            'a message explaining the format must not delete the filesystem',
        );
        $this->assertStringContainsString(
            'no occurrence of it is positioned like an action',
            $this->loggedOutput(),
            'declining to fire is a decision, and it is reported like one',
        );
    }

    /**
     * The fence is not the only signal. DeepSeek-V4's start token literally
     * embeds two newlines - `f"\n\n<{dsml_token}{tool_calls_block_name}"`
     * (`enc.py:726`) - so a marker glued to the end of a prose sentence is not
     * where a real call starts.
     */
    public function testAMarkerRunOnFromProseWithNoBlankLineIsNotAnAction(): void
    {
        $content = 'inline mention of ' . self::envelope(self::invoke('rm_rf', [['path', 'true', '/']]));

        $this->assertNull(DsmlToolCallParser::new()->parse(['content' => $content]));
    }

    /**
     * The other half of the guard: it must not cost a genuine call. The
     * realistic emission is prose, a blank line, then the envelope.
     */
    public function testAGenuineEnvelopeAfterABlankLineIsStillAnAction(): void
    {
        $content = "I'll read that file for you.\n\n"
            . self::envelope(self::invoke('read', [['path', 'true', '/etc/hosts']]));

        $calls = DsmlToolCallParser::new()->parse(['content' => $content]);

        $this->assertCount(1, $calls);
        $this->assertSame('read', $calls[0]->name());
    }

    /**
     * A `write` whose payload is a Markdown document containing an ODD number
     * of code fences must not poison the fence toggle for anything after it -
     * an accepted envelope's body is consumed wholesale precisely so a tool
     * call's arguments can never be read as the document's own markup.
     */
    public function testAFenceInsideAnAcceptedPayloadDoesNotSuppressTheNextCall(): void
    {
        $content = self::envelope(self::invoke('write', [['content', 'true', "here is a fence: ```php"]]))
            . "\n\n"
            . self::envelope(self::invoke('read', [['path', 'true', '/etc/hosts']]));

        $calls = DsmlToolCallParser::new()->parse(['content' => $content]);

        $this->assertCount(2, $calls, 'the second envelope must survive the first payload\'s stray fence');
        $this->assertSame(['write', 'read'], array_map(static fn (ToolCall $c): string => $c->name(), $calls));
    }

    // -------------------------------------------------------------------------
    // B2: a parameter must never vanish and leave the call firing without it.
    // -------------------------------------------------------------------------

    /**
     * `PARAMETER_PATTERN` hard-required `string="…"` with DOUBLE quotes, so
     * every spelling below made the parameter fail to match and DISAPPEAR
     * while the call still fired: measured `1 call, args=[]` for all three,
     * with zero `error_log`. `read()` with no `path` is a call that runs with
     * an argument the model supplied and the parser dropped.
     *
     * @dataProvider tolerableFlagSpellings
     */
    public function testAQuotingVariantOnTheStringFlagKeepsTheArgument(string $parameter, mixed $expected): void
    {
        $d = self::DSML;
        $content = "<{$d}tool_calls><{$d}invoke name=\"read\">{$parameter}</{$d}invoke></{$d}tool_calls>";

        $calls = DsmlToolCallParser::new()->parse(['content' => $content]);

        $this->assertCount(1, $calls);
        $this->assertArrayHasKey('p', $calls[0]->arguments(), 'the argument must not vanish');
        $this->assertSame($expected, $calls[0]->arguments()['p']);
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function tolerableFlagSpellings(): array
    {
        $d = self::DSML;

        return [
            'single-quoted flag' => ["<{$d}parameter name=\"p\" string='true'>3</{$d}parameter>", '3'],
            'unquoted flag' => ["<{$d}parameter name=\"p\" string=true>3</{$d}parameter>", '3'],
            'absent flag' => ["<{$d}parameter name=\"p\">3</{$d}parameter>", '3'],
            'attributes reordered' => ["<{$d}parameter string=\"false\" name=\"p\">3</{$d}parameter>", 3],
        ];
    }

    public function testAnAbsentStringFlagIsReportedRatherThanAssumed(): void
    {
        $d = self::DSML;
        DsmlToolCallParser::new()->parse([
            'content' => "<{$d}tool_calls><{$d}invoke name=\"read\">"
                . "<{$d}parameter name=\"p\">3</{$d}parameter></{$d}invoke></{$d}tool_calls>",
        ]);

        $this->assertStringContainsString('string=(absent)', $this->loggedOutput());
    }

    /**
     * The one case that is NOT recoverable: an unclosed parameter's value is
     * short by an unknown amount. Firing `write()` with a half-written payload
     * is worse than not firing it, so the whole invoke is refused - loudly.
     * The old code emitted `1 call, args=[]` and logged nothing at all.
     */
    public function testAnUnclosedParameterRefusesTheWholeInvokeRatherThanDroppingTheArgument(): void
    {
        $d = self::DSML;
        $content = "<{$d}tool_calls><{$d}invoke name=\"write\">"
            . "<{$d}parameter name=\"content\" string=\"true\">half a fi";

        $this->assertNull(DsmlToolCallParser::new()->parse(['content' => $content]));
        $this->assertStringContainsString('truncated by an unknown amount', $this->loggedOutput());
        $this->assertStringContainsString('refused whole', $this->loggedOutput());
    }

    // -------------------------------------------------------------------------
    // B3: no cliff at pcre.backtrack_limit, because there is no PCRE.
    // -------------------------------------------------------------------------

    /**
     * Measured on the previous implementation with the DEFAULT
     * `pcre.backtrack_limit` of 1,000,000: a 900,000-byte value returned the
     * call, a 1,000,000-byte value returned NULL and the call was lost. A
     * `Write` of a 1 MB file against a 1,048,570-token window is ordinary
     * agent traffic.
     *
     * The limit is pinned at 2 here rather than left at its default, so the
     * test measures the ABSENCE of a PCRE scan rather than one input size.
     */
    public function testAOneMegabyteValueSurvivesABacktrackLimitNoPcreCouldClear(): void
    {
        $payload = str_repeat('a', 1_000_000);
        $previous = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '2');

        try {
            $calls = DsmlToolCallParser::new()->parse([
                'content' => self::envelope(self::invoke('write', [['content', 'true', $payload]])),
            ]);
        } finally {
            ini_set('pcre.backtrack_limit', $previous === false ? '1000000' : $previous);
        }

        $this->assertCount(1, $calls);
        $this->assertSame($payload, $calls[0]->arguments()['content']);
        $this->assertSame('', $this->loggedOutput(), 'a well-formed 1 MB call is not a degradation');
    }

    // -------------------------------------------------------------------------
    // S5: shapes upstream raises on. Documented, not silent.
    // -------------------------------------------------------------------------

    /**
     * A nested invoke used to yield a SPURIOUS `outer` call with `args=[]`
     * beside the real `inner` one. The outer's body is cut at the nested open
     * tag, so there is no evidence the model meant a zero-argument call -
     * firing one is fabricating it.
     */
    public function testANestedInvokeYieldsOnlyTheInnerCallAndReportsTheOuter(): void
    {
        $d = self::DSML;
        $content = "<{$d}tool_calls><{$d}invoke name=\"outer\"><{$d}invoke name=\"inner\">"
            . "<{$d}parameter name=\"a\" string=\"true\">1</{$d}parameter>"
            . "</{$d}invoke></{$d}invoke></{$d}tool_calls>";

        $calls = DsmlToolCallParser::new()->parse(['content' => $content]);

        $this->assertCount(1, $calls);
        $this->assertSame('inner', $calls[0]->name());
        $this->assertStringContainsString('nested inside another invoke', $this->loggedOutput());
    }

    public function testAnEmptyToolNameIsDroppedAndReportedRatherThanDispatched(): void
    {
        $d = self::DSML;

        $this->assertNull(DsmlToolCallParser::new()->parse([
            'content' => "<{$d}tool_calls><{$d}invoke name=\"\"></{$d}invoke></{$d}tool_calls>",
        ]));
        $this->assertStringContainsString('no parseable name', $this->loggedOutput());
    }

    /**
     * A documented limitation rather than a silent one: `json_decode` maps an
     * integer literal too large for a PHP int to a float. Correcting it needs
     * the tool's declared JSON-Schema type, which this parser is not handed.
     */
    public function testAnOversizedIntegerBecomesAFloatAsJsonDecodeDefines(): void
    {
        $calls = DsmlToolCallParser::new()->parse([
            'content' => self::envelope(self::invoke('n', [['x', 'false', '12345678901234567890123']])),
        ]);

        $this->assertIsFloat($calls[0]->arguments()['x']);
        $this->assertSame(1.2345678901234568e+22, $calls[0]->arguments()['x']);
    }

    /**
     * THE FALSE-NEGATIVE EDGE OF THE B1 GUARD, pinned so it is discoverable
     * rather than surprising.
     *
     * The guard admits only the separator DeepSeek-V4 documents - `enc.py:726`
     * makes `\n\n` part of the start token itself - so an envelope that
     * follows a `</think>` block with a single newline, or none, is NOT
     * recovered. Loosening to "starts a line" would be inventing a rule the
     * protocol does not state, which is the exact move this bundle declined to
     * make for MiniMax; applying that consistency HERE is the cost of it.
     *
     * It is bounded and it is loud: the parser reports a marker it declined to
     * act on, naming the real fix (relaunch with `--tool-call-parser`), so this
     * degrades to a diagnosable miss rather than the silent one the class
     * exists to eliminate.
     *
     * @dataProvider separatorsTheProtocolDoesNotDocument
     */
    public function testAnEnvelopeSeparatedByLessThanTheDocumentedStartTokenIsDeclinedLoudly(string $separator): void
    {
        $content = '<think>hmm</think>' . $separator
            . self::envelope(self::invoke('read', [['path', 'true', '/a']]));

        $this->assertNull(DsmlToolCallParser::new()->parse(['content' => $content]));
        $this->assertStringContainsString('relaunch the server with --tool-call-parser', $this->loggedOutput());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function separatorsTheProtocolDoesNotDocument(): array
    {
        return ['a single newline' => ["\n"], 'nothing at all' => ['']];
    }

    /**
     * And the documented one, which is what a trained emission actually looks
     * like, is recovered - including after reasoning markup.
     */
    public function testTheDocumentedStartTokenAfterAThinkBlockIsRecovered(): void
    {
        $calls = DsmlToolCallParser::new()->parse([
            'content' => "<think>hmm</think>\n\n" . self::envelope(self::invoke('read', [['path', 'true', '/a']])),
        ]);

        $this->assertCount(1, $calls);
        $this->assertSame('read', $calls[0]->name());
        $this->assertSame('', $this->loggedOutput());
    }
}

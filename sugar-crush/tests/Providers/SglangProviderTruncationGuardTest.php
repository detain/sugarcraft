<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\SglangProvider;

/**
 * W1.A4 (crush_feat.md §12 D5): the MiniMax-M2.x `</parameter>` tool-call
 * truncation bug is server-side and unfixable from here, but it is detectable.
 *
 * Every assertion on a logged warning below fails against the old code, which
 * ran `json_decode($args, true) ?? []` and therefore turned a payload
 * truncated mid-value into an indistinguishable zero-argument tool call with
 * no trace anywhere.
 */
final class SglangProviderTruncationGuardTest extends TestCase
{
    private string $logFile = '';

    /** @var string|false */
    private $previousErrorLog = false;

    protected function setUp(): void
    {
        // error_log() output is captured by redirecting the ini setting to a
        // temp file, matching AgentWorkerPoolTest's established pattern.
        $this->logFile = sys_get_temp_dir() . '/sglang-truncation-' . uniqid('', true) . '.log';
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        @unlink($this->logFile);
    }

    private function capturedLog(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    /**
     * @return array{0: SglangProvider, 1: CompleteRequest}
     */
    private function providerReturningToolCall(mixed $arguments, string $name = 'write_file'): array
    {
        $httpClient = $this->createMock(Client::class);
        $httpClient->method('post')->willReturn(new Response(200, [], (string) json_encode([
            'choices' => [[
                'message' => [
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_trunc',
                        'function' => ['name' => $name, 'arguments' => $arguments],
                    ]],
                ],
            ]],
            'usage' => ['total_tokens' => 7],
        ])));

        return [
            new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $httpClient),
            new CompleteRequest(model: 'MiniMax-M2.7', messages: [new UserMessage('write it')]),
        ];
    }

    // -------------------------------------------------------------------------
    // parseResponse(): arguments truncated mid-value are flagged, not swallowed
    // -------------------------------------------------------------------------

    public function testArgumentsTruncatedAtTheParameterCloseTagAreFlagged(): void
    {
        // Exactly the bug's signature: the value stops the instant the model
        // emitted a literal '</parameter>' inside it.
        [$provider, $request] = $this->providerReturningToolCall(
            '{"path":"demo.tape","content":"<parameter name=\"x\">1</parameter>'
        );

        $result = $provider->complete($request);

        $log = $this->capturedLog();
        $this->assertStringContainsString('possible MiniMax XML-delimiter truncation', $log);
        $this->assertStringContainsString('write_file', $log);
        $this->assertStringContainsString('</parameter>', $log);
        $this->assertNotNull($result->toolCalls);
        $this->assertSame([], $result->toolCalls[0]->arguments());
    }

    public function testArgumentsTruncatedWithoutTheDelimiterAreStillFlaggedAsTruncation(): void
    {
        // Same failure shape (no closing structure), no marker in the payload -
        // the warning still fires, but without claiming the delimiter was seen.
        [$provider, $request] = $this->providerReturningToolCall('{"path":"a.php","content":"<?php');

        $provider->complete($request);

        $log = $this->capturedLog();
        $this->assertStringContainsString('possible MiniMax XML-delimiter truncation', $log);
        $this->assertStringNotContainsString('and contain the literal', $log);
    }

    public function testStructurallyClosedButMalformedArgumentsGetThePlainInvalidJsonWarning(): void
    {
        // Closed braces means it did not simply run out - a different bug, and
        // it must not pollute a search for real truncations.
        [$provider, $request] = $this->providerReturningToolCall('{"path": }');

        $provider->complete($request);

        $log = $this->capturedLog();
        $this->assertStringContainsString('are not valid JSON', $log);
        $this->assertStringNotContainsString('possible MiniMax XML-delimiter truncation', $log);
    }

    public function testValidArgumentsAreDecodedAndLogNothing(): void
    {
        [$provider, $request] = $this->providerReturningToolCall('{"path":"a.php","content":"ok"}');

        $result = $provider->complete($request);

        $this->assertSame(['path' => 'a.php', 'content' => 'ok'], $result->toolCalls[0]->arguments());
        $this->assertSame('', $this->capturedLog());
    }

    public function testEmptyArgumentsPayloadIsTreatedAsAZeroArgumentCallAndLogsNothing(): void
    {
        [$provider, $request] = $this->providerReturningToolCall('');

        $result = $provider->complete($request);

        $this->assertSame([], $result->toolCalls[0]->arguments());
        $this->assertSame('', $this->capturedLog());
    }

    public function testPreDecodedArrayArgumentsArePassedThroughUnflagged(): void
    {
        [$provider, $request] = $this->providerReturningToolCall(['path' => 'a.php']);

        $result = $provider->complete($request);

        $this->assertSame(['path' => 'a.php'], $result->toolCalls[0]->arguments());
        $this->assertSame('', $this->capturedLog());
    }

    public function testNonObjectJsonArgumentsDecodeToAnEmptyArgumentListAndAreLogged(): void
    {
        // ToolCall::fromArray() types arguments `array`, so a bare literal used
        // to be a loud TypeError. Degrading to [] must not make it silent.
        [$provider, $request] = $this->providerReturningToolCall('"just a string"');

        $result = $provider->complete($request);

        $log = $this->capturedLog();
        $this->assertSame([], $result->toolCalls[0]->arguments());
        $this->assertStringContainsString('decoded to string, not an object', $log);
        $this->assertStringContainsString('write_file', $log);
        // Not a truncation - a complete, well-formed, simply wrong payload.
        $this->assertStringNotContainsString('possible MiniMax XML-delimiter truncation', $log);
    }

    public function testJsonNullArgumentsAreLoggedRatherThanSilentlyEmptied(): void
    {
        [$provider, $request] = $this->providerReturningToolCall('null');

        $result = $provider->complete($request);

        $this->assertSame([], $result->toolCalls[0]->arguments());
        $this->assertStringContainsString('decoded to null, not an object', $this->capturedLog());
    }

    public function testToolCallMissingItsFunctionNameDegradesInsteadOfFataling(): void
    {
        $httpClient = $this->createMock(Client::class);
        $httpClient->method('post')->willReturn(new Response(200, [], (string) json_encode([
            'choices' => [[
                'message' => [
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_nameless',
                        'function' => ['arguments' => '{"path":"a.php"}'],
                    ]],
                ],
            ]],
            'usage' => ['total_tokens' => 4],
        ])));
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $httpClient);

        $result = $provider->complete(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('go')],
        ));

        $this->assertSame('', $result->toolCalls[0]->name());
        $this->assertSame(['path' => 'a.php'], $result->toolCalls[0]->arguments());
    }

    // -------------------------------------------------------------------------
    // parseChunk(): the streamed path shares the same guard
    // -------------------------------------------------------------------------

    public function testStreamedToolCallWithTruncatedArgumentsIsFlagged(): void
    {
        $provider = new SglangProvider(
            'https://api.example.com',
            'MiniMax-M2.7',
            null,
            $this->createMock(Client::class),
        );

        $parseChunk = new \ReflectionMethod($provider, 'parseChunk');
        $parseChunk->setAccessible(true);
        $buffer = [];

        $fragment = [
            'choices' => [[
                'delta' => ['tool_calls' => [[
                    'index' => 0,
                    'id' => 'call_stream',
                    'function' => ['name' => 'edit_file', 'arguments' => '{"body":"a</parameter>'],
                ]]],
            ]],
        ];
        $parseChunk->invokeArgs($provider, [$fragment, &$buffer]);

        $final = ['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]];
        $result = $parseChunk->invokeArgs($provider, [$final, &$buffer]);

        $log = $this->capturedLog();
        $this->assertStringContainsString('possible MiniMax XML-delimiter truncation', $log);
        $this->assertStringContainsString('edit_file', $log);
        $this->assertSame([], $result->toolCalls[0]->arguments());
    }

    public function testStreamedToolCallWithNoArgumentFragmentsLogsNothing(): void
    {
        $provider = new SglangProvider(
            'https://api.example.com',
            'MiniMax-M2.7',
            null,
            $this->createMock(Client::class),
        );

        $parseChunk = new \ReflectionMethod($provider, 'parseChunk');
        $parseChunk->setAccessible(true);
        $buffer = [];

        $fragment = [
            'choices' => [[
                'delta' => ['tool_calls' => [[
                    'index' => 0,
                    'id' => 'call_stream',
                    'function' => ['name' => 'list_dir', 'arguments' => ''],
                ]]],
            ]],
        ];
        $parseChunk->invokeArgs($provider, [$fragment, &$buffer]);

        $final = ['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]];
        $result = $parseChunk->invokeArgs($provider, [$final, &$buffer]);

        $this->assertSame([], $result->toolCalls[0]->arguments());
        $this->assertSame('', $this->capturedLog());
    }

    // -------------------------------------------------------------------------
    // Part (1): tool results carrying the substring are flagged as higher-risk
    // -------------------------------------------------------------------------

    /**
     * @return array{0: SglangProvider, 1: Client&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function providerWithEmptyCompletion(string $model = 'MiniMax-M2.7'): array
    {
        $httpClient = $this->createMock(Client::class);
        $httpClient->method('post')->willReturn(new Response(200, [], (string) json_encode([
            'choices' => [['message' => ['content' => 'ok']]],
            'usage' => ['total_tokens' => 3],
        ])));

        return [
            new SglangProvider('https://api.example.com', $model, null, $httpClient),
            $httpClient,
        ];
    }

    public function testLatestToolResultContainingTheDelimiterIsFlaggedAsHigherRisk(): void
    {
        [$provider] = $this->providerWithEmptyCompletion();

        $provider->complete(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [
                new UserMessage('read demo.tape'),
                new ToolResultMessage('call_read', "<parameter name=\"a\">1</parameter>\n"),
            ],
        ));

        $log = $this->capturedLog();
        $this->assertStringContainsString('call_read', $log);
        $this->assertStringContainsString('elevated risk of silent truncation', $log);
        $this->assertStringContainsString('(1 occurrence(s))', $log);
    }

    public function testEveryToolResultInAMultiToolBatchIsScannedNotJustTheLast(): void
    {
        // EngineBackend::complete() splats all of a turn's tool results onto
        // the history at once, so the risky one is routinely not the last.
        [$provider] = $this->providerWithEmptyCompletion();

        $provider->complete(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [
                new UserMessage('read both files'),
                new ToolResultMessage('call_risky', '<parameter name="a">1</parameter>'),
                new ToolResultMessage('call_clean', 'nothing risky here'),
            ],
        ));

        $log = $this->capturedLog();
        $this->assertStringContainsString('call_risky', $log);
        $this->assertStringNotContainsString('call_clean', $log);
    }

    public function testEveryRiskyResultInABatchIsFlaggedIndividually(): void
    {
        [$provider] = $this->providerWithEmptyCompletion();

        $provider->complete(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [
                new UserMessage('read them'),
                new ToolResultMessage('call_one', '</parameter>'),
                new ToolResultMessage('call_two', '</parameter></parameter>'),
            ],
        ));

        $log = $this->capturedLog();
        $this->assertStringContainsString('call_one', $log);
        $this->assertStringContainsString('(1 occurrence(s))', $log);
        $this->assertStringContainsString('call_two', $log);
        $this->assertStringContainsString('(2 occurrence(s))', $log);
    }

    public function testOnlyTheTrailingRunOfToolResultsIsScanned(): void
    {
        // A risky result from an EARLIER turn sits behind an assistant/user
        // message; re-flagging it every turn would spam the log all session.
        [$provider] = $this->providerWithEmptyCompletion();

        $provider->complete(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [
                new ToolResultMessage('call_old', '</parameter>'),
                new UserMessage('now edit it'),
                new ToolResultMessage('call_new', 'clean'),
            ],
        ));

        $this->assertSame('', $this->capturedLog());
    }

    public function testRiskyToolResultIsNotReFlaggedOnceItIsNoLongerTheLatestMessage(): void
    {
        // The full history is re-serialized every turn; re-flagging would spam
        // the log with the same result for the rest of the session.
        [$provider] = $this->providerWithEmptyCompletion();

        $provider->complete(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [
                new ToolResultMessage('call_read', '</parameter>'),
                new UserMessage('now edit it'),
            ],
        ));

        $this->assertSame('', $this->capturedLog());
    }

    public function testCleanToolResultIsNotFlagged(): void
    {
        [$provider] = $this->providerWithEmptyCompletion();

        $provider->complete(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new ToolResultMessage('call_read', 'plain text, nothing risky')],
        ));

        $this->assertSame('', $this->capturedLog());
    }

    public function testEmptyMessageListIsNotFlagged(): void
    {
        [$provider] = $this->providerWithEmptyCompletion();

        $provider->complete(new CompleteRequest(model: 'MiniMax-M2.7', messages: []));

        $this->assertSame('', $this->capturedLog());
    }

    // -------------------------------------------------------------------------
    // The risk PREDICTION is model-gated; the decode DIAGNOSIS is not
    // -------------------------------------------------------------------------

    /**
     * The regression this whole block exists for: DeepSeek-V4-Flash became the
     * default model, and the risk warning was firing for it with text that
     * asserts a MiniMax bug.
     *
     * The gate is not a guess. Measured live against skynet2 on 2026-08-20, a
     * `write_file` call whose `body` was
     * `<invoke name="x"><parameter name="y">z</parameter></invoke> DONE`
     * returned all 64 bytes byte-identical through structured `tool_calls`,
     * `</parameter>` intact. The model does not have the bug, so predicting it
     * would be a MiniMax measurement written next to a different model.
     */
    public function testTheDeepSeekV4DefaultIsNeverWarnedAboutTheMiniMaxTruncationBug(): void
    {
        [$provider] = $this->providerWithEmptyCompletion();

        $provider->complete(new CompleteRequest(
            model: SglangProvider::DEFAULT_MODEL,
            messages: [
                new UserMessage('read demo.tape'),
                new ToolResultMessage('call_read', '<parameter name="a">1</parameter>'),
            ],
        ));

        // Asserted as a totally empty log rather than as the absence of one
        // phrase: a substring check would still pass if the warning came back
        // reworded.
        $this->assertSame('', $this->capturedLog());
    }

    /**
     * The gate is on the REQUEST's model, matching where `buildParams()` reads
     * every other sampling default from - so a provider configured for one
     * model and completing against another follows the request. Both halves
     * are asserted in one test because the pair is the claim; either alone
     * would pass against a gate keyed on the wrong field.
     */
    public function testTheRiskWarningFollowsTheRequestModelNotTheConfiguredOne(): void
    {
        $messages = [new ToolResultMessage('call_read', '</parameter>')];

        // Configured DeepSeek, request MiniMax -> warned.
        [$deepSeekConfigured] = $this->providerWithEmptyCompletion(SglangProvider::DEFAULT_MODEL);
        $deepSeekConfigured->complete(new CompleteRequest(model: 'MiniMax-M2.7', messages: $messages));
        $this->assertStringContainsString('elevated risk of silent truncation', $this->capturedLog());

        @unlink($this->logFile);

        // Configured MiniMax, request DeepSeek -> silent.
        [$miniMaxConfigured] = $this->providerWithEmptyCompletion('MiniMax-M2.7');
        $miniMaxConfigured->complete(new CompleteRequest(
            model: SglangProvider::DEFAULT_MODEL,
            messages: $messages,
        ));
        $this->assertSame('', $this->capturedLog());
    }

    /**
     * An UNMEASURED model still gets the warning - not being measured is not
     * being known safe - but the text has to name the id it fired for, or the
     * log reads as though the request went to MiniMax.
     */
    public function testAnUnmeasuredModelIsStillWarnedAndTheWarningNamesIt(): void
    {
        [$provider] = $this->providerWithEmptyCompletion('local-model');

        $provider->complete(new CompleteRequest(
            model: 'local-model',
            messages: [new ToolResultMessage('call_read', '</parameter>')],
        ));

        $log = $this->capturedLog();
        $this->assertStringContainsString('elevated risk of silent truncation', $log);
        // The domain, not just the claim: the model the request is addressed to
        // AND the model the bug was measured on, distinguishable in the line.
        $this->assertStringContainsString('addressed to model "local-model"', $log);
        $this->assertStringContainsString('measured on MiniMax-M2.x', $log);
    }

    /**
     * The DIAGNOSIS is deliberately NOT gated - a payload that already failed
     * to decode is worth reporting whatever produced it. What the gate would
     * have cost is exactly this case: DeepSeek-V4 truncating for some other
     * reason and nothing being logged at all.
     */
    public function testTruncatedArgumentsAreStillDiagnosedOnTheDeepSeekV4Default(): void
    {
        $httpClient = $this->createMock(Client::class);
        $httpClient->method('post')->willReturn(new Response(200, [], (string) json_encode([
            'choices' => [[
                'message' => [
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_trunc',
                        'function' => [
                            'name' => 'write_file',
                            'arguments' => '{"path":"a.php","content":"<?php',
                        ],
                    ]],
                ],
            ]],
            'usage' => ['total_tokens' => 7],
        ])));
        $provider = new SglangProvider(
            'https://api.example.com',
            SglangProvider::DEFAULT_MODEL,
            null,
            $httpClient,
        );

        $provider->complete(new CompleteRequest(
            model: SglangProvider::DEFAULT_MODEL,
            messages: [new UserMessage('write it')],
        ));

        $log = $this->capturedLog();
        $this->assertStringContainsString('possible MiniMax XML-delimiter truncation', $log);
        // ...but attributed as a SHAPE match, not as a fact about this model.
        $this->assertStringContainsString('matches the signature of', $log);
        $this->assertStringNotContainsString('This is the known MiniMax', $log);
    }

    // -------------------------------------------------------------------------
    // Warning payload excerpting
    // -------------------------------------------------------------------------

    public function testLongTruncatedPayloadIsElidedButKeepsItsTail(): void
    {
        // The tail is the diagnostic signal - it is where the payload stops.
        $body = str_repeat('x', 400);
        [$provider, $request] = $this->providerReturningToolCall(
            '{"content":"' . $body . 'TAIL_MARKER</parameter>'
        );

        $provider->complete($request);

        $log = $this->capturedLog();
        $this->assertStringContainsString('[...]', $log);
        $this->assertStringContainsString('TAIL_MARKER', $log);
        $this->assertStringNotContainsString($body, $log);
    }
}

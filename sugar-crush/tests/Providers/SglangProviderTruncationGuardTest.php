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
    private function providerWithEmptyCompletion(): array
    {
        $httpClient = $this->createMock(Client::class);
        $httpClient->method('post')->willReturn(new Response(200, [], (string) json_encode([
            'choices' => [['message' => ['content' => 'ok']]],
            'usage' => ['total_tokens' => 3],
        ])));

        return [
            new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $httpClient),
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

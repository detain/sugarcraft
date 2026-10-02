<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Providers\ToolCallParser\OpenAiArrayToolCallParser;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Audit 15a A11: a tool call whose `function.arguments` did not decode was
 * emitted with `[]` and nothing on it said so - CustomProvider silently
 * (`json_decode(...) ?? []`), SglangProvider with a UI warning only - so
 * Runtime ran the tool with no arguments and the model read a misleading
 * "missing parameter" error. Every provider path that EMITS such a call now
 * stamps {@see ToolCall::argumentsError()} on it; Runtime's refusal of it is
 * pinned in {@see \SugarCraft\Crush\Tests\RuntimeMalformedToolArgumentsTest}.
 *
 * The §Q7/A4 flush still DROPS undecodable calls on an undeclared end; that
 * is not this file's subject and stays pinned in StreamedToolCallFlushTest.
 */
final class MalformedToolArgumentsTest extends TestCase
{
    /** The audit's payload: a JSON object cut off mid-string. */
    private const TRUNCATED = '{"path": "a';

    private string $logFile = '';

    /** @var string|false */
    private $previousErrorLog = false;

    protected function setUp(): void
    {
        // SglangProvider's RuntimeNoticeSink warnings land in error_log();
        // keep them out of the runner's stderr and readable here.
        $this->logFile = sys_get_temp_dir() . '/a11-malformed-' . uniqid('', true) . '.log';
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        @unlink($this->logFile);
    }

    /** @return array<string, array{string}> */
    public static function providers(): array
    {
        return ['sglang' => ['sglang'], 'custom' => ['custom']];
    }

    #[DataProvider('providers')]
    public function testAStreamedCallWithTruncatedArgumentsCarriesTheJsonError(string $kind): void
    {
        $body = self::frame(['tool_calls' => [[
            'index' => 0,
            'id' => 'c1',
            'type' => 'function',
            'function' => ['name' => 'Read', 'arguments' => self::TRUNCATED],
        ]]]) . self::frame([], 'tool_calls') . "data: [DONE]\n\n";

        $call = self::onlyCall($this->streamed($this->provider($kind, $body)));

        $this->assertSame('Read', $call->name());
        $this->assertSame([], $call->arguments());
        $this->assertNotNull($call->argumentsError(), 'the decode failure must ride on the call');
        $this->assertStringContainsString('not valid JSON', (string) $call->argumentsError());
        $this->assertStringContainsString(self::TRUNCATED, (string) $call->argumentsError());
    }

    #[DataProvider('providers')]
    public function testABatchCallWithTruncatedArgumentsCarriesTheJsonError(string $kind): void
    {
        $call = self::onlyCall([$this->provider($kind, self::batchBody(self::TRUNCATED))->complete(self::request())]);

        $this->assertSame([], $call->arguments());
        $this->assertStringContainsString('not valid JSON', (string) $call->argumentsError());
        $this->assertStringContainsString(self::TRUNCATED, (string) $call->argumentsError());
    }

    #[DataProvider('providers')]
    public function testABatchCallWithScalarArgumentsCarriesAnErrorInsteadOfRunningWithNone(string $kind): void
    {
        // Before A11 CustomProvider passed the decoded `12` straight into
        // ToolCall's `array` argument and threw a TypeError.
        $call = self::onlyCall([$this->provider($kind, self::batchBody('12'))->complete(self::request())]);

        $this->assertSame([], $call->arguments());
        $this->assertStringContainsString('decoded to int, not a JSON object', (string) $call->argumentsError());
    }

    #[DataProvider('providers')]
    public function testWellFormedAndZeroArgumentCallsCarryNoError(string $kind): void
    {
        foreach (['{"path":"a.php"}' => ['path' => 'a.php'], '' => [], '{}' => []] as $raw => $expected) {
            $call = self::onlyCall([$this->provider($kind, self::batchBody((string) $raw))->complete(self::request())]);

            $this->assertSame($expected, $call->arguments(), "payload '{$raw}'");
            $this->assertNull($call->argumentsError(), "payload '{$raw}' is a usable call");
        }
    }

    public function testSglangsWarningNoLongerSaysTheCallRunsWithNoArguments(): void
    {
        $this->provider('sglang', self::batchBody(self::TRUNCATED))->complete(self::request());

        $log = is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
        $this->assertStringContainsString('the call is NOT executed', $log);
        $this->assertStringNotContainsString('executed with no arguments', $log);
    }

    public function testTheStandaloneOpenAiParserMarksTheCallWithOrWithoutAnInjectedDecoder(): void
    {
        $message = ['tool_calls' => [[
            'id' => 'c1',
            'function' => ['name' => 'Read', 'arguments' => self::TRUNCATED],
        ]]];

        foreach ([OpenAiArrayToolCallParser::new(), OpenAiArrayToolCallParser::new(SglangProvider::argumentDecoder())] as $parser) {
            $calls = $parser->parse($message);
            $this->assertNotNull($calls);
            $this->assertStringContainsString('not valid JSON', (string) $calls[0]->argumentsError());
        }
    }

    private static function frame(mixed $delta, ?string $finishReason = null): string
    {
        return 'data: ' . json_encode([
            'model' => 'some-model',
            'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finishReason]],
        ]) . "\n\n";
    }

    private static function batchBody(string $arguments): string
    {
        return (string) json_encode([
            'choices' => [[
                'message' => [
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'c1',
                        'type' => 'function',
                        'function' => ['name' => 'Read', 'arguments' => $arguments],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['total_tokens' => 7],
        ]);
    }

    /**
     * StreamedToolCallFlushTest's helper, kept token-identical (the `$parser`
     * slot included) so the two copies cannot drift unseen.
     */
    private function provider(string $kind, string $body, mixed $parser = null): ProviderInterface
    {
        $client = $this->createMock(Client::class);
        $client->method('post')->willReturn(new Response(200, [], $body));

        return $kind === 'sglang'
            ? new SglangProvider('https://api.example.com', 'some-model', null, $client, $parser)
            : new CustomProvider('custom', 'https://api.example.com', 'some-model', null, $client, true, true);
    }

    private static function request(): CompleteRequest
    {
        return new CompleteRequest(model: 'some-model', messages: [new UserMessage('read a')]);
    }

    /**
     * @return list<CompleteResponse>
     */
    private function streamed(ProviderInterface $provider): array
    {
        return iterator_to_array($provider->completeStream(self::request()), false);
    }

    /**
     * @param list<CompleteResponse> $responses
     */
    private static function onlyCall(array $responses): ToolCall
    {
        $calls = [];
        foreach ($responses as $response) {
            foreach ($response->toolCalls ?? [] as $call) {
                $calls[] = $call;
            }
        }

        self::assertCount(1, $calls, 'exactly one call is emitted - A11 refuses it later, it is never dropped here');
        self::assertInstanceOf(ToolCall::class, $calls[0]);

        return $calls[0];
    }
}

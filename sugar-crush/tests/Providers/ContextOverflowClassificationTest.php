<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Exception\EventStreamDataException;
use Aws\MockHandler as AwsMockHandler;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OpenAI\Exceptions\ErrorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\BedrockProvider;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\ContextOverflow;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\ProviderResponseException;
use SugarCraft\Crush\Providers\ProviderStreamException;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Providers\VertexProvider;

/**
 * Roadmap 2.7-1a: a context-window overflow is recognised as one, whichever
 * provider reported it and whichever channel it arrived through — a thrown
 * HTTP 400/413, an SSE error frame inside a 200, or an `isError` response —
 * and nothing that merely mentions tokens (a rate limit, an output cap) is.
 */
final class ContextOverflowClassificationTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function overflowWordings(): iterable
    {
        yield 'openai' => ["This model's maximum context length is 8192 tokens. However, your messages resulted in 9001 tokens."];
        yield 'openai code' => ['context_length_exceeded'];
        yield 'sglang tokenizer' => ["The input (135000 tokens) is longer than the model's context length (131072 tokens)."];
        yield 'sglang scheduler' => ['Input length (140000 tokens) exceeds the maximum allowed length (131072 tokens).'];
        yield 'sglang requested' => ["Requested token count exceeds the model's maximum context length of 131072 tokens."];
        yield 'vllm decoder' => ['The decoder prompt (length 40000) is longer than the maximum model length of 32768.'];
        yield 'vllm input' => ['Input prompt (40000 tokens) is too long and exceeds limit of 32768'];
        yield 'anthropic' => ['prompt is too long: 210000 tokens > 200000 maximum'];
        yield 'anthropic 413 type' => ['request_too_large'];
        yield 'gemini' => ['The input token count (1200000) exceeds the maximum number of tokens allowed (1048576).'];
        yield 'bedrock' => ['Input is too long for requested model.'];
        yield 'ollama' => ['the input length exceeds the context length'];
        yield 'llama.cpp' => ['the request exceeds the available context size, try increasing it'];
        yield 'litellm' => ['litellm.ContextWindowExceededError: litellm.BadRequestError: OpenAIException'];
        yield 'own prefix' => [ContextOverflow::describe('anything')];
    }

    #[DataProvider('overflowWordings')]
    public function testEachProviderWordingIsAnOverflow(string $text): void
    {
        self::assertTrue(ContextOverflow::describesOverflow($text));
        self::assertTrue(ContextOverflow::describesOverflow($text, 400));
    }

    /** @return iterable<string, array{string}> */
    public static function lookalikes(): iterable
    {
        yield 'tpm rate limit' => ['Rate limit reached for gpt-4 on tokens per min (TPM): Limit 10000, Requested 12000.'];
        yield 'bedrock throttle' => ['Too many tokens, please wait before trying again.'];
        yield 'openai output cap' => ['max_tokens is too large: 100000. This model supports at most 4096 completion tokens'];
        yield 'anthropic output cap' => ['max_tokens: 100000 > 64000, which is the maximum allowed number of output tokens'];
        yield 'auth' => ['Incorrect API key provided'];
        yield 'empty' => [''];
    }

    #[DataProvider('lookalikes')]
    public function testWordingThatOnlyMentionsTokensIsNotAnOverflow(string $text): void
    {
        self::assertFalse(ContextOverflow::describesOverflow($text));
    }

    public function testTheStatusDecidesBeforeTheWording(): void
    {
        $overflow = "This model's maximum context length is 8192 tokens.";

        self::assertTrue(ContextOverflow::describesOverflow('anything at all', 413));
        self::assertTrue(ContextOverflow::describesOverflow($overflow, 422));
        self::assertFalse(ContextOverflow::describesOverflow($overflow, 500));
        self::assertFalse(ContextOverflow::describesOverflow($overflow, 401));
    }

    // -- thrown failures -------------------------------------------------------

    public function testAGuzzle400WithAnOverflowBodyIsAnOverflowAndA401IsNot(): void
    {
        $body = '{"error":{"message":"This model\'s maximum context length is 8192 tokens.","code":"context_length_exceeded"}}';

        self::assertTrue(ContextOverflow::matches(self::guzzleFailure(400, $body)));
        self::assertFalse(ContextOverflow::matches(self::guzzleFailure(401, $body)));
        self::assertFalse(ContextOverflow::matches(self::guzzleFailure(429, '{"error":{"message":"Rate limit reached"}}')));
        self::assertFalse(ContextOverflow::matches(self::guzzleFailure(400, '{"error":{"message":"messages[0].role is invalid"}}')));
        self::assertTrue(ContextOverflow::matches(self::guzzleFailure(413, 'Payload Too Large')));
    }

    /**
     * Guzzle's own exception message clips the body at 120 bytes; a proxy
     * prefix pushes the wording past that, so the whole body is read.
     */
    public function testTheWholeBodyIsReadNotOnlyGuzzlesClippedSummary(): void
    {
        $body = json_encode(['error' => ['message' => str_repeat('upstream proxy said: ', 10)
            . "The input (135000 tokens) is longer than the model's context length (131072 tokens)."]]);
        $e = self::guzzleFailure(400, (string) $body);

        self::assertStringNotContainsString('context length', $e->getMessage());
        self::assertTrue(ContextOverflow::matches($e));
    }

    public function testSglangsWrappedHttpFailureIsClassifiedThroughTheChain(): void
    {
        $provider = new SglangProvider('http://provider.invalid', 'm', null, self::client(new Response(
            400,
            [],
            '{"object":"error","message":"The input (135000 tokens) is longer than the model\'s context length (131072 tokens).","type":"BadRequestError","code":400}',
        )));

        $caught = null;
        try {
            $provider->complete(self::bareRequest());
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'a 400 must throw');
        self::assertStringStartsWith('SGLANG request failed: The input', $caught->getMessage());
        self::assertTrue(ContextOverflow::matches($caught));
    }

    public function testOpenAiErrorExceptionIsClassifiedOnStatusAndCode(): void
    {
        $overflow = new ErrorException(
            ['message' => 'Please reduce the length of the messages.', 'type' => 'invalid_request_error', 'code' => 'context_length_exceeded'],
            400,
        );
        $badKey = new ErrorException(['message' => 'invalid api key', 'type' => 'invalid_request_error', 'code' => null], 401);

        self::assertTrue(ContextOverflow::matches($overflow));
        self::assertFalse(ContextOverflow::matches($badKey));
    }

    public function testBedrockValidationExceptionIsAnOverflowAndItsThrottleIsNot(): void
    {
        $tooLong = new AwsException('Input is too long for requested model.', new Command('Converse'), [
            'response' => new Response(400),
            'code' => 'ValidationException',
            'message' => 'Input is too long for requested model.',
        ]);
        $throttle = new AwsException('Too many tokens, please wait before trying again.', new Command('Converse'), [
            'response' => new Response(429),
            'code' => 'ThrottlingException',
        ]);

        $mock = new AwsMockHandler();
        $mock->append($tooLong);
        $provider = new BedrockProvider(
            new BedrockRuntimeClient([
                'region' => 'us-east-1',
                'version' => 'latest',
                'credentials' => ['key' => 'k', 'secret' => 's'],
                'handler' => $mock,
            ]),
            'us-east-1',
            'us.anthropic.claude-sonnet-4-6',
        );

        $caught = null;
        try {
            $provider->complete(self::bareRequest());
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'a ValidationException must throw');
        self::assertTrue(ContextOverflow::matches($caught));

        self::assertFalse(ContextOverflow::matches(new \RuntimeException('bedrock failed', 0, $throttle)));
        self::assertTrue(ContextOverflow::matches(
            new EventStreamDataException('validationException', 'Input is too long for requested model.'),
        ));
    }

    public function testATransportFailureOrAnUnknownExceptionIsNot(): void
    {
        self::assertFalse(ContextOverflow::matches(new ConnectException('refused', new Request('POST', 'http://x'))));
        self::assertFalse(ContextOverflow::matches(new \LogicException('boom')));
        self::assertFalse(ContextOverflow::matches(ProviderStreamException::prematureEnd()));
    }

    // -- in-stream frames ------------------------------------------------------

    public function testAnSseErrorFrameCarriesItsOwnVerdict(): void
    {
        $frame = ['error' => ['message' => 'Please shorten your prompt.', 'type' => 'invalid_request_error', 'code' => 'context_length_exceeded']];
        $e = ProviderStreamException::fromErrorEvent($frame);

        self::assertNotNull($e);
        self::assertTrue($e->contextOverflow);
        self::assertFalse($e->transient);
        self::assertTrue(ContextOverflow::matches(new \RuntimeException('wrapped', 0, $e)));

        $busy = ProviderStreamException::fromErrorEvent(['object' => 'error', 'message' => 'maximum context length', 'code' => 503]);
        self::assertNotNull($busy);
        self::assertFalse($busy->contextOverflow, 'a 5xx is the server failing, not the prompt');
    }

    // -- error responses -------------------------------------------------------

    public function testCustomReportsAnHttpOverflowWithTheServersWordsAndTheVerdict(): void
    {
        $provider = new CustomProvider('custom', 'http://provider.invalid', 'm', null, self::client(new Response(
            400,
            [],
            '{"error":{"message":"This model\'s maximum context length is 8192 tokens.","type":"invalid_request_error","code":"context_length_exceeded"}}',
        )), true, true);

        $response = $provider->complete(self::bareRequest());

        self::assertTrue($response->isError);
        self::assertSame(
            ContextOverflow::describe("This model's maximum context length is 8192 tokens."),
            $response->errorMessage,
        );
        self::assertTrue(ContextOverflow::matches($response));
        self::assertTrue(ProviderResponseException::fromResponse($response)->contextOverflow);
        self::assertTrue(ContextOverflow::matches(ProviderResponseException::fromResponse($response)));
    }

    public function testCustomKeepsGuzzlesWordingForEveryOtherFailure(): void
    {
        $provider = new CustomProvider('custom', 'http://provider.invalid', 'm', null, self::client(new Response(
            401,
            [],
            '{"error":{"message":"Incorrect API key provided"}}',
        )), true, true);

        $response = $provider->complete(self::bareRequest());

        self::assertTrue($response->isError);
        self::assertStringContainsString('401 Unauthorized', (string) $response->errorMessage);
        self::assertFalse(ContextOverflow::matches($response));
        self::assertFalse(ProviderResponseException::fromResponse($response)->contextOverflow);
    }

    public function testAnErrorResponseIsJudgedOnlyWhenItIsAPermanentError(): void
    {
        $text = ContextOverflow::describe('prompt is too long');

        self::assertTrue(ContextOverflow::matches(new CompleteResponse(content: '', isError: true, errorMessage: $text)));
        self::assertFalse(ContextOverflow::matches(new CompleteResponse(content: '', isError: true, errorMessage: $text, errorTransient: true)));
        self::assertFalse(ContextOverflow::matches(new CompleteResponse(content: $text)));
    }

    /**
     * Roadmap 2.7-1b (carried from 2.7-1a): an error RESPONSE carries its own
     * verdict, like the two exceptions do, and the verdict outranks the text —
     * the wording is only the fallback for a provider that never sets it.
     */
    public function testAnErrorResponsesExplicitVerdictOutranksItsWording(): void
    {
        self::assertTrue(ContextOverflow::matches(new CompleteResponse(content: '', isError: true, errorMessage: 'request rejected', errorContextOverflow: true)));
        self::assertFalse(ContextOverflow::matches(new CompleteResponse(content: '', isError: true, errorMessage: ContextOverflow::describe('prompt is too long'), errorContextOverflow: false)));
        self::assertFalse(
            ContextOverflow::matches(new CompleteResponse(content: '', isError: true, errorMessage: 'x', errorTransient: true, errorContextOverflow: true)),
            'a transient failure is never an overflow, whatever the flag says',
        );
        self::assertFalse(ContextOverflow::matches(new CompleteResponse(content: 'ok', errorContextOverflow: true)), 'not an error');
        self::assertTrue(ProviderResponseException::fromResponse(new CompleteResponse(content: '', isError: true, errorMessage: 'too big', errorContextOverflow: true))->contextOverflow);
    }

    public function testVertexAnthropicErrorObjectsAndThrownFailuresCarryTheVerdict(): void
    {
        $tooLong = new VertexProvider('p', 'us-east5', 'claude-sonnet-4-6@20250514', static fn (): array => [
            'type' => 'error',
            'error' => ['type' => 'invalid_request_error', 'message' => 'prompt is too long: 210000 tokens > 200000 maximum'],
        ]);
        $response = $tooLong->complete(self::bareRequest());
        self::assertSame(ContextOverflow::describe('prompt is too long: 210000 tokens > 200000 maximum'), $response->errorMessage);
        self::assertTrue($response->errorContextOverflow, 'the verdict rides as a field, not only in the text');
        self::assertTrue(ContextOverflow::matches($response));

        $overloaded = new VertexProvider('p', 'us-east5', 'claude-sonnet-4-6@20250514', static fn (): array => [
            'type' => 'error',
            'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded'],
        ]);
        $busy = $overloaded->complete(self::bareRequest());
        self::assertFalse($busy->errorContextOverflow);
        self::assertFalse(ContextOverflow::matches($busy));

        $thrown = new VertexProvider('p', 'us-central1', 'gemini-2.5-pro', static function (): array {
            throw new \RuntimeException('The input token count (1200000) exceeds the maximum number of tokens allowed (1048576).');
        });
        $response = $thrown->complete(self::bareRequest());
        self::assertStringStartsWith(ContextOverflow::MESSAGE_PREFIX, (string) $response->errorMessage);
        self::assertTrue($response->errorContextOverflow);
        self::assertTrue(ContextOverflow::matches($response));
    }

    public function testErrorObjectsAreJudgedOnTypeCodeAndMessage(): void
    {
        self::assertTrue(ContextOverflow::errorObjectOverflows(['type' => 'request_too_large', 'message' => 'Request exceeds the maximum allowed number of bytes.']));
        self::assertFalse(ContextOverflow::errorObjectOverflows(['type' => 'rate_limit_error', 'message' => 'slow down']));
        self::assertFalse(ContextOverflow::errorObjectOverflows('prompt is too long'));
    }

    public function testDescribeIsIdempotent(): void
    {
        $once = ContextOverflow::describe('x');

        self::assertSame($once, ContextOverflow::describe($once));
        self::assertSame(ContextOverflow::MESSAGE_PREFIX . 'x', $once);
    }

    private static function bareRequest(): CompleteRequest
    {
        return new CompleteRequest(model: '', messages: [new UserMessage('hi')]);
    }

    private static function client(Response $response): Client
    {
        return new Client([
            'handler' => HandlerStack::create(new MockHandler([$response])),
            'base_uri' => 'http://provider.invalid/',
        ]);
    }

    private static function guzzleFailure(int $status, string $body): \GuzzleHttp\Exception\RequestException
    {
        return \GuzzleHttp\Exception\RequestException::create(
            new Request('POST', 'http://provider.invalid/chat/completions'),
            new Response($status, [], $body),
        );
    }
}

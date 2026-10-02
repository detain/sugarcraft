<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Providers\ToolCallParser\MinimaxXmlFallbackToolCallParser;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Audit 15a A9 at the provider seam: `SglangProvider` must hand the REQUEST's
 * tool schemas to a schema-aware parser on both the batch (`complete()`) and
 * the streaming (`completeStream()` → recoverTextualToolCalls()) path, so a
 * MiniMax XML `Write` of a JSON file reaches the tool with `content` as the
 * string the tool declared - not the PHP array the old guess produced.
 */
final class SglangProviderToolSchemaCoercionTest extends TestCase
{
    private const MODEL = 'MiniMax-M2.7';

    private const JSON_TEXT = '{"name": "acme/x", "require": {}}';

    private static function minimaxWrite(): string
    {
        return "I'll write it.\n<minimax:tool_call>\n<invoke name=\"Write\">\n"
            . "<parameter name=\"file_path\">composer.json</parameter>\n"
            . '<parameter name="content">' . self::JSON_TEXT . "</parameter>\n"
            . "<parameter name=\"overwrite\">true</parameter>\n"
            . "</invoke>\n</minimax:tool_call>";
    }

    /** A Write-like tool: `content` is declared a string, `overwrite` a boolean. */
    private static function writeTool(): Tool
    {
        return new class () implements Tool {
            public function name(): string
            {
                return 'Write';
            }

            public function description(): string
            {
                return 'stub write';
            }

            public function inputSchema(): array
            {
                return [
                    'type' => 'object',
                    'properties' => [
                        'file_path' => ['type' => 'string'],
                        'content' => ['type' => 'string'],
                        'overwrite' => ['type' => 'boolean'],
                    ],
                    'required' => ['file_path', 'content'],
                ];
            }

            public function execute(array $args): ToolResult
            {
                throw new \LogicException('never executed');
            }
        };
    }

    /**
     * @param list<Response> $responses
     */
    private static function provider(array $responses): SglangProvider
    {
        $client = new Client([
            'handler' => HandlerStack::create(new MockHandler($responses)),
            'base_uri' => 'http://sglang.test/v1/',
        ]);

        return new SglangProvider(
            'http://sglang.test',
            self::MODEL,
            null,
            $client,
            MinimaxXmlFallbackToolCallParser::new(),
        );
    }

    private static function request(?array $tools): CompleteRequest
    {
        return new CompleteRequest(
            model: self::MODEL,
            messages: [new UserMessage('write composer.json')],
            tools: $tools,
        );
    }

    private static function batchResponse(): Response
    {
        return new Response(200, [], (string) json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => self::minimaxWrite()], 'finish_reason' => 'stop']],
        ]));
    }

    private static function streamResponse(): Response
    {
        $body = '';

        // Cut into 7-byte deltas so the envelope straddles many chunks, as it
        // does on the wire; only the reassembled content can be parsed.
        foreach (str_split(self::minimaxWrite(), 7) as $piece) {
            $body .= 'data: ' . json_encode([
                'choices' => [['index' => 0, 'delta' => ['content' => $piece], 'finish_reason' => null]],
            ]) . "\n\n";
        }

        $body .= 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]])
            . "\n\ndata: [DONE]\n\n";

        return new Response(200, [], $body);
    }

    /**
     * @return list<ToolCall>
     */
    private static function streamedCalls(SglangProvider $provider, CompleteRequest $request): array
    {
        $calls = [];

        foreach ($provider->completeStream($request) as $chunk) {
            /** @var CompleteResponse $chunk */
            foreach ($chunk->toolCalls ?? [] as $call) {
                $calls[] = $call;
            }
        }

        return $calls;
    }

    public function testBatchPathTypesTheRecoveredCallByTheRequestToolSchema(): void
    {
        $result = self::provider([self::batchResponse()])->complete(self::request([self::writeTool()]));

        $this->assertIsArray($result->toolCalls);
        $this->assertCount(1, $result->toolCalls);
        $arguments = $result->toolCalls[0]->arguments();
        $this->assertIsString($arguments['content'], 'A9: a JSON file body must reach Write as a string');
        $this->assertSame(self::JSON_TEXT, $arguments['content']);
        $this->assertTrue($arguments['overwrite'], 'the boolean-typed flag is typed by the schema too');
    }

    public function testStreamingPathTypesTheRecoveredCallByTheRequestToolSchema(): void
    {
        $calls = self::streamedCalls(self::provider([self::streamResponse()]), self::request([self::writeTool()]));

        $this->assertCount(1, $calls);
        $this->assertSame('Write', $calls[0]->name());
        $this->assertIsString($calls[0]->arguments()['content']);
        $this->assertSame(self::JSON_TEXT, $calls[0]->arguments()['content']);
        $this->assertTrue($calls[0]->arguments()['overwrite']);
    }

    /**
     * The types are attached per REQUEST to a copy: a later request on the
     * same provider that offers no tools must not inherit the earlier schema,
     * and with no schema every value stays its raw text.
     */
    public function testASchemaFromOneRequestDoesNotLeakIntoTheNext(): void
    {
        $provider = self::provider([self::batchResponse(), self::streamResponse()]);

        $typed = $provider->complete(self::request([self::writeTool()]));
        $this->assertTrue($typed->toolCalls[0]->arguments()['overwrite']);

        $untyped = self::streamedCalls($provider, self::request(null));
        $this->assertCount(1, $untyped);
        $this->assertSame(self::JSON_TEXT, $untyped[0]->arguments()['content']);
        $this->assertSame('true', $untyped[0]->arguments()['overwrite'], 'no schema on this request: raw text');
    }
}

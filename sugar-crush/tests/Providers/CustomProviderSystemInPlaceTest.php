<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\SglangProvider;

/**
 * Step 1.A-1 on CustomProvider: system content is placed by the same rule as
 * SglangProvider ({@see SglangProvider::placeSystemRows()}) on BOTH wire
 * paths. Before 1.A-1 complete()/completeStream() prepended the prompt as its
 * own row and every history SystemMessage stayed a `system` row where it sat,
 * so a cancellation marker produced `system` at index > 0 — HTTP 400 "System
 * message must be at the beginning." on the Qwen-family templates this
 * provider is pointed at (E-10) — and a launch notice produced a second
 * leading system row.
 */
final class CustomProviderSystemInPlaceTest extends TestCase
{
    private const OK_BODY = '{"choices":[{"message":{"content":"ok"}}],"usage":{"total_tokens":1}}';

    private const SSE_BODY = "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n\ndata: [DONE]\n\n";

    /** @var list<array<string, mixed>> */
    private array $history = [];

    private function provider(string $body, bool $streaming): CustomProvider
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], $body)]));
        $stack->push(Middleware::history($this->history));

        return new CustomProvider(
            'custom',
            'https://api.example.com',
            'qwen',
            null,
            new Client(['handler' => $stack, 'base_uri' => 'https://api.example.com/']),
            $streaming,
            true,
        );
    }

    /** @return list<array<string, mixed>> */
    private function sentMessages(): array
    {
        return json_decode((string) $this->history[0]['request']->getBody(), true)['messages'];
    }

    /**
     * @param list<object> $messages
     * @return list<array<string, mixed>>
     */
    private function send(bool $stream, ?string $prompt, array $messages): array
    {
        $request = new CompleteRequest(model: 'qwen', messages: $messages, systemPrompt: $prompt);

        if ($stream) {
            iterator_to_array($this->provider(self::SSE_BODY, true)->completeStream($request));
        } else {
            $this->provider(self::OK_BODY, false)->complete($request);
        }

        return $this->sentMessages();
    }

    /** @return iterable<string, array{bool}> */
    public static function wirePaths(): iterable
    {
        yield 'complete' => [false];
        yield 'completeStream' => [true];
    }

    /** @dataProvider wirePaths */
    public function testAMidHistorySystemRowStaysInPlaceAsAUserRoleNotice(bool $stream): void
    {
        $sent = $this->send($stream, 'PROMPT', [
            new SystemMessage('Launch notice'),
            new UserMessage('long task please'),
            new AssistantMessage('working on…'),
            new SystemMessage('_Request cancelled._'),
            new UserMessage('try again'),
        ]);

        $this->assertSame([
            ['role' => 'system', 'content' => "PROMPT\n\nLaunch notice"],
            ['role' => 'user', 'content' => 'long task please'],
            ['role' => 'assistant', 'content' => 'working on…'],
            ['role' => 'user', 'content' => SglangProvider::systemNoticeContent('_Request cancelled._')],
            ['role' => 'user', 'content' => 'try again'],
        ], $sent);
        $this->assertSame(1, \count(array_filter($sent, static fn (array $row): bool => $row['role'] === 'system')));
    }

    /** @dataProvider wirePaths */
    public function testAnEmptyPromptEarnsNoSystemRowAndEmptyHistoryRowsAreDropped(bool $stream): void
    {
        $sent = $this->send($stream, '', [
            new SystemMessage(''),
            new UserMessage('hi'),
            new SystemMessage(''),
        ]);

        $this->assertSame([['role' => 'user', 'content' => 'hi']], $sent);
    }

    /** @dataProvider wirePaths */
    public function testANewNoticeLeavesEveryEarlierWireRowByteIdentical(bool $stream): void
    {
        $history = [new UserMessage('fix the bug'), new AssistantMessage('On it.')];

        $before = $this->send($stream, 'PROMPT', $history);
        $after = $this->send($stream, 'PROMPT', [...$history, new SystemMessage('Context is 70% full.')]);

        $this->assertSame(json_encode($before), json_encode(\array_slice($after, 0, \count($before))));
        $this->assertSame('user', $after[\count($before)]['role']);
    }

    public function testBothWirePathsPlaceSystemContentIdentically(): void
    {
        $messages = [
            new SystemMessage('lead'),
            new UserMessage('u'),
            new SystemMessage('later'),
        ];

        $this->assertSame($this->send(false, 'P', $messages), $this->send(true, 'P', $messages));
    }
}

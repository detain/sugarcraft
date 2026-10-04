<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\AcceptsAssistantPrefill;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Providers\ProviderStreamException;
use SugarCraft\Crush\Providers\ReplyContinuation;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Usage;

/**
 * Roadmap 2.7-3: a stream that drops after its text reached the screen is
 * continued — the partial reply goes back as a prefill or with a "Continue
 * where you left off" row — and the reply is the parts joined, painted once.
 */
final class DroppedStreamContinueTest extends TestCase
{
    public function testADroppedStreamIsContinuedWithAContinueRow(): void
    {
        $provider = self::provider(false, [
            [['Hel', 'lo'], ProviderStreamException::prematureEnd()],
            [[' world'], null],
        ]);

        [$reply, $seen] = self::drive($provider);

        $this->assertSame('Hello world', $reply->content());
        $this->assertSame(['Hel', 'lo', ' world'], $seen);
        $rows = $provider->script->requests[1]->messages;
        $partial = $rows[array_key_last($rows) - 1];
        $ask = $rows[array_key_last($rows)];
        $this->assertInstanceOf(AssistantMessage::class, $partial);
        $this->assertSame('Hello', $partial->content());
        $this->assertInstanceOf(UserMessage::class, $ask);
        $this->assertStringStartsWith(ReplyContinuation::RESUME_PROMPT, $ask->content());
        $this->assertStringContainsString('<already_delivered_tail>Hello</already_delivered_tail>', $ask->content());
        $this->assertCount(count($provider->script->requests[0]->messages) + 2, $rows, 'the first request plus the two continuation rows, nothing else');
    }

    public function testAPrefillProviderIsAskedForTheRestOfTheSameMessage(): void
    {
        $provider = self::provider(true, [
            [['The answer is '], ProviderStreamException::prematureEnd()],
            [[' forty-two.'], null],
        ]);

        [$reply] = self::drive($provider);

        $this->assertSame('The answer is forty-two.', $reply->content());
        $request = $provider->script->requests[1];
        $this->assertTrue($request->continuesFinalMessage());
        $this->assertSame('The answer is', $request->messages[array_key_last($request->messages)]->content());
    }

    public function testAStreamThatDropsTwiceIsContinuedTwice(): void
    {
        $provider = self::provider(false, [
            [['A', 'B'], ProviderStreamException::prematureEnd()],
            [[' C'], ProviderStreamException::prematureEnd()],
            [[' D'], null],
        ]);

        [$reply, $seen] = self::drive($provider);

        $this->assertSame('AB C D', $reply->content());
        $this->assertSame(['A', 'B', ' C', ' D'], $seen);
        $rows = $provider->script->requests[2]->messages;
        $this->assertSame('AB C', $rows[array_key_last($rows) - 1]->content(), 'the second continuation carries everything said so far');
    }

    public function testTheDroppedAttemptsUsageIsKeptWithItsText(): void
    {
        $provider = self::provider(false, [
            [[new CompleteResponse(content: 'Hi', usage: Usage::new(5, 0.01, 4, 1, 0))], ProviderStreamException::prematureEnd()],
            [[new CompleteResponse(content: ' there', usage: Usage::new(7, 0.02, 5, 2, 0))], null],
        ]);

        [$reply] = self::drive($provider);

        $this->assertSame('Hi there', $reply->content());
        $this->assertSame(12, $reply->usage()?->totalTokens);
    }

    public function testARefusedPrefillIsAskedAgainWithTheContinueRow(): void
    {
        $provider = self::provider(true, [
            [['Hi'], ProviderStreamException::prematureEnd()],
            [[], new \RuntimeException('This model does not support assistant message prefill.')],
            [[' there'], null],
        ]);

        [$reply] = self::drive($provider);

        $this->assertSame('Hi there', $reply->content());
        $last = $provider->script->requests[2]->messages[array_key_last($provider->script->requests[2]->messages)];
        $this->assertInstanceOf(UserMessage::class, $last);
        $this->assertStringStartsWith(ReplyContinuation::RESUME_PROMPT, $last->content());
    }

    public function testADropAfterAStreamedToolCallStillSurfaces(): void
    {
        $provider = self::provider(false, [
            [[new CompleteResponse(content: 'Let me look', toolCalls: [new ToolCall('c1', 'Read', ['file_path' => 'a'])])], ProviderStreamException::prematureEnd()],
            [['unused'], null],
        ]);

        $caught = null;
        try {
            self::drive($provider);
        } catch (ProviderStreamException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'a half-delivered tool call cannot be continued');
        $this->assertCount(1, $provider->script->requests);
    }

    public function testAPermanentFailureAfterEmissionStillSurfaces(): void
    {
        $provider = self::provider(false, [
            [['Hel'], new \RuntimeException('401 Unauthorized')],
            [['unused'], null],
        ]);

        $caught = null;
        try {
            self::drive($provider);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertSame('401 Unauthorized', $caught?->getMessage(), 'a permanent failure is not continued');

        $this->assertCount(1, $provider->script->requests);
    }

    /** @return array{0: AssistantMessage, 1: list<string>} */
    private static function drive(ProviderInterface $provider): array
    {
        $seen = [];
        $messages = iterator_to_array(
            (new Runtime($provider, new HookManager(new HookRegistry())))->run(
                App::new($provider, 'm')->withMessages([new UserMessage('go')]),
                null,
                null,
                static function (string $delta) use (&$seen): void {
                    $seen[] = $delta;
                },
            ),
            false,
        );

        return [$messages[0], $seen];
    }

    /**
     * A streaming provider that plays one attempt per call: its chunks, then
     * its failure (null: a clean end).
     *
     * @param list<array{0: list<string|CompleteResponse>, 1: ?\Throwable}> $attempts
     * @return DroppedStreamProvider
     */
    private static function provider(bool $prefill, array $attempts): DroppedStreamProvider
    {
        $base = new class ($attempts) {
            /** @var list<CompleteRequest> */
            public array $requests = [];

            public function __construct(private readonly array $attempts)
            {
            }

            public function stream(CompleteRequest $request): \Generator
            {
                [$chunks, $failure] = $this->attempts[count($this->requests)] ?? [[], new \LogicException('out of script')];
                $this->requests[] = $request;
                foreach ($chunks as $chunk) {
                    yield $chunk instanceof CompleteResponse ? $chunk : new CompleteResponse(content: $chunk);
                }
                if ($failure !== null) {
                    throw $failure;
                }
            }
        };

        return $prefill
            ? new class ($base) extends DroppedStreamProvider implements AcceptsAssistantPrefill {
                public function acceptsAssistantPrefill(string $model): bool
                {
                    return true;
                }
            }
            : new DroppedStreamProvider($base);
    }
}

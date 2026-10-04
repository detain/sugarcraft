<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\AcceptsAssistantPrefill;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Providers\ReplyContinuation;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Usage;

/**
 * Roadmap 2.7-2: a reply the provider cuts at its output ceiling, with no tool
 * call, is continued where it stopped — as a prefill where the provider takes
 * one, with a "continue" row elsewhere — up to three times, and reads as one
 * reply.
 */
final class LengthStopContinuationTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            @rmdir($this->root);
        }
    }

    public function testACutReplyIsContinuedWithAContinueRowAndJoined(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'The plan has two parts. First, ', truncated: true, usage: Usage::new(10, 0.01, 8, 2, 0)),
            new CompleteResponse(content: 'rename the class.', usage: Usage::new(12, 0.02, 9, 3, 0)),
        ]);
        $tokens = '';

        $reply = $this->engine($provider)->complete([Message::user('plan it')], static function (string $delta) use (&$tokens): void {
            $tokens .= $delta;
        });

        $this->assertSame('The plan has two parts. First, rename the class.', $reply->content);
        $this->assertFalse($reply->lengthStopped, 'the continuation finished the reply, so no notice is owed');
        $this->assertSame($reply->content, $tokens, 'the continuation streams onto the same reply');
        $this->assertSame(22, $reply->usage?->totalTokens, 'both calls are billed');

        $this->assertCount(2, $provider->requests);
        $rows = $provider->requests[1]->messages;
        $ask = $rows[array_key_last($rows)];
        $partial = $rows[array_key_last($rows) - 1];
        $this->assertInstanceOf(AssistantMessage::class, $partial);
        $this->assertSame('The plan has two parts. First, ', $partial->content());
        $this->assertInstanceOf(UserMessage::class, $ask);
        $this->assertStringStartsWith(ReplyContinuation::LENGTH_PROMPT, $ask->content());
        $this->assertStringEndsWith('<already_delivered_tail>The plan has two parts. First, </already_delivered_tail>', $ask->content());
        $this->assertFalse($provider->requests[1]->continuesFinalMessage());
    }

    public function testAPrefillProviderIsSentThePartialReplyAsTheLastMessage(): void
    {
        $inner = new ScriptedProvider([
            new CompleteResponse(content: 'Step one: ', truncated: true),
            new CompleteResponse(content: ' open the file.'),
        ]);

        $reply = $this->engine(self::prefilling($inner))->complete([Message::user('how?')]);

        $this->assertSame('Step one: open the file.', $reply->content, 'joined on the trimmed prefill the model continued');
        $request = $inner->requests[1];
        $last = $request->messages[array_key_last($request->messages)];
        $this->assertInstanceOf(AssistantMessage::class, $last);
        $this->assertSame('Step one:', $last->content(), 'right-trimmed: Anthropic refuses a prefill ending in whitespace');
        $this->assertTrue($request->continuesFinalMessage());
    }

    public function testAtMostThreeContinuationsThenTheNoticeStands(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'more ', truncated: true)]);

        $reply = $this->engine($provider)->complete([Message::user('go on')]);

        $this->assertCount(1 + ReplyContinuation::MAX_LENGTH_CONTINUATIONS, $provider->requests);
        $this->assertSame(str_repeat('more ', 4), $reply->content);
        $this->assertTrue($reply->lengthStopped);
    }

    public function testAReplyWithoutTextIsNotContinued(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', reasoning: 'thinking...', truncated: true),
            new CompleteResponse(content: 'answer'),
        ]);

        $reply = $this->engine($provider)->complete([Message::user('go')]);

        // The reasoning-only nudge (step 0.10) handles it, not a continuation.
        $this->assertSame('answer', $reply->content);
        $last = $provider->requests[1]->messages[array_key_last($provider->requests[1]->messages)];
        $this->assertInstanceOf(UserMessage::class, $last);
        $this->assertStringNotContainsString(ReplyContinuation::LENGTH_PROMPT, $last->content());
    }

    public function testARefusedPrefillIsAskedAgainWithAContinueRow(): void
    {
        $inner = new ScriptedProvider([
            new CompleteResponse(content: 'Half', truncated: true),
            new \RuntimeException('This model does not support assistant message prefill. The conversation must end with a user message.'),
            new CompleteResponse(content: ' and the rest.'),
        ]);

        $reply = $this->engine(self::prefilling($inner))->complete([Message::user('go')]);

        $this->assertSame('Half and the rest.', $reply->content);
        $this->assertCount(3, $inner->requests);
        $this->assertTrue($inner->requests[1]->continuesFinalMessage());
        $last = $inner->requests[2]->messages[array_key_last($inner->requests[2]->messages)];
        $this->assertInstanceOf(UserMessage::class, $last);
        $this->assertStringStartsWith(ReplyContinuation::LENGTH_PROMPT, $last->content());
    }

    public function testAFailedContinuationKeepsTheReplyItWasContinuing(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'What was said', truncated: true),
            new \RuntimeException('503 Service Unavailable'),
        ]);

        $reply = $this->engine($provider)->complete([Message::user('go')]);

        $this->assertSame('What was said', $reply->content);
        $this->assertTrue($reply->lengthStopped, 'the notice says the reply was cut short');
    }

    public function testMergeJoinsTextReasoningAndUsageAndKeepsTheLastStop(): void
    {
        $merged = ReplyContinuation::merge(
            new AssistantMessage('a ', null, 'r1', Usage::new(5, 0.1, 4, 1, 0), true),
            new AssistantMessage('b', null, 'r2', Usage::new(7, 0.2, 5, 2, 0), false),
            false,
        );

        $this->assertSame('a b', $merged->content());
        $this->assertSame('r1r2', $merged->reasoning());
        $this->assertSame(12, $merged->usage()?->totalTokens);
        $this->assertFalse($merged->lengthStopped());
        $this->assertSame('ab', ReplyContinuation::merge(new AssistantMessage('a '), new AssistantMessage('b'), true)->content());
    }

    private function engine(ProviderInterface $provider): EngineBackend
    {
        $this->root ??= sys_get_temp_dir() . '/crush-lengthcont-' . bin2hex(random_bytes(6));
        if (!is_dir($this->root)) {
            mkdir($this->root, 0o700, true);
        }

        return EngineBackend::new($provider, 'm')->withoutHooks()->withRoot($this->root);
    }

    /** $inner, declaring that it takes an assistant prefill. */
    private static function prefilling(ScriptedProvider $inner): ProviderInterface
    {
        return new class ($inner) implements ProviderInterface, AcceptsAssistantPrefill {
            public function __construct(private readonly ScriptedProvider $inner)
            {
            }

            public function acceptsAssistantPrefill(string $model): bool
            {
                return true;
            }

            public function name(): string
            {
                return 'prefilling';
            }

            public function supportsStreaming(): bool
            {
                return $this->inner->supportsStreaming();
            }

            public function supportsFunctionCalling(): bool
            {
                return true;
            }

            public function supportsVision(): bool
            {
                return false;
            }

            public function supportsJsonSchema(): bool
            {
                return false;
            }

            public function contextWindow(): int
            {
                return $this->inner->contextWindow();
            }

            public function costPer1kTokens(string $model, string $direction): float
            {
                return 0.0;
            }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                return $this->inner->complete($request);
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                return $this->inner->completeStream($request);
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return $this->inner->embeddings($request);
            }
        };
    }
}

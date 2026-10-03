<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;

/**
 * Step 0.10: a reply with no tool calls and no text no longer ends the turn
 * silently. A reasoning-only reply gets ONE nudge (a user row asking for an
 * answer or a tool call); a fully empty reply is re-requested up to TWICE
 * with nothing appended. Each extra call needs a step left and a spend cap
 * not yet reached; once the recoveries run out the turn ends as it always
 * did, with an empty reply.
 */
final class EngineBackendEmptyReplyTest extends TestCase
{
    public function testAReasoningOnlyReplyIsNudgedOnceAndTheAnswerThatFollowsWins(): void
    {
        $provider = $this->replayingProvider([['', 'thinking about it'], ['the answer', null]]);

        $reply = EngineBackend::new($provider, 'm')->withoutHooks()->complete([Message::user('question')]);

        $this->assertSame('the answer', $reply->content);
        $this->assertCount(2, $provider->requests);
        $last = $provider->requests[1]->messages[array_key_last($provider->requests[1]->messages)];
        $this->assertInstanceOf(UserMessage::class, $last, 'the nudge is the newest row of the second request');
        $this->assertStringContainsString('only reasoning', $last->content());
    }

    public function testAReasoningOnlyReplyIsNudgedAtMostOnce(): void
    {
        $provider = $this->replayingProvider([['', 'hmm'], ['', 'still hmm'], ['never reached', null]]);

        $reply = EngineBackend::new($provider, 'm')->withoutHooks()->complete([Message::user('question')]);

        $this->assertSame('', $reply->content, 'the recoveries ran out, so the turn ends as it always did');
        $this->assertCount(2, $provider->requests);
    }

    public function testAFullyEmptyReplyIsReRequestedUpToTwiceWithNothingAppended(): void
    {
        $provider = $this->replayingProvider([['', null], ['  ', null], ['', null], ['never reached', null]]);

        $reply = EngineBackend::new($provider, 'm')->withoutHooks()->complete([Message::user('question')]);

        $this->assertSame('', $reply->content);
        $this->assertCount(3, $provider->requests, 'one call plus two re-requests');
        $this->assertSame(
            $this->messageShapes($provider->requests[0]),
            $this->messageShapes($provider->requests[2]),
            'a re-request resends the same conversation — the empty reply is not appended',
        );
    }

    public function testAnEmptyReplyFollowedByAnAnswerEndsWithTheAnswer(): void
    {
        $provider = $this->replayingProvider([['', null], ['hello', null]]);

        $reply = EngineBackend::new($provider, 'm')->withoutHooks()->complete([Message::user('hi')]);

        $this->assertSame('hello', $reply->content);
        $this->assertCount(2, $provider->requests);
        $this->assertFalse($reply->stepsTruncated);
    }

    public function testNoRecoveryCallIsMadeOnceTheSpendCapIsReached(): void
    {
        $provider = $this->replayingProvider([['', null, 1.0], ['never reached', null]]);

        $reply = EngineBackend::new($provider, 'm')->withoutHooks()->withSpendCap(0.5)->complete([Message::user('hi')]);

        $this->assertSame('', $reply->content);
        $this->assertCount(1, $provider->requests, 'a re-request is a paid call, and the cap refuses it');
    }

    public function testNoRecoveryCallIsMadeWithoutAStepLeft(): void
    {
        $provider = $this->replayingProvider([['', 'thinking'], ['never reached', null]]);

        $reply = EngineBackend::new($provider, 'm')->withoutHooks()->withMaxSteps(1)->complete([Message::user('hi')]);

        $this->assertSame('', $reply->content);
        $this->assertCount(1, $provider->requests);
        $this->assertFalse($reply->stepsTruncated, 'an unrecovered empty reply is still an answer-without-tools exit');
    }

    public function testAnOrdinaryAnswerIsNeverReRequested(): void
    {
        $provider = $this->replayingProvider([['fine', 'brief thought'], ['never reached', null]]);

        $reply = EngineBackend::new($provider, 'm')->withoutHooks()->complete([Message::user('hi')]);

        $this->assertSame('fine', $reply->content);
        $this->assertCount(1, $provider->requests);
    }

    /** @return list<string> */
    private function messageShapes(CompleteRequest $request): array
    {
        return array_map(
            static fn(object $m): string => $m::class . ':' . ($m instanceof AssistantMessage || $m instanceof UserMessage ? $m->content() : ''),
            $request->messages,
        );
    }

    /**
     * @param list<array{0: string, 1: ?string, 2?: float}> $replies content, reasoning, cost
     */
    private function replayingProvider(array $replies): ProviderInterface
    {
        return new class ($replies) implements ProviderInterface {
            /** @var list<CompleteRequest> */
            public array $requests = [];

            /** @param list<array{0: string, 1: ?string, 2?: float}> $replies */
            public function __construct(private array $replies) {}

            public function name(): string { return 'replaying'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return true; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 100_000; }
            public function costPer1kTokens(string $model, string $direction): float { return 0.0; }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                $reply = $this->replies[count($this->requests)] ?? ['', null];
                $this->requests[] = $request;

                return new CompleteResponse(
                    content: $reply[0],
                    reasoning: $reply[1],
                    tokensUsed: 1,
                    costUsd: $reply[2] ?? 0.0,
                );
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                yield new CompleteResponse(content: '');
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return new EmbeddingsResponse([]);
            }
        };
    }
}

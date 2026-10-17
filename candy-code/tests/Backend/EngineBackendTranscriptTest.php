<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Backend\TranscriptTurn;
use SugarCraft\Code\Backend\TurnInterrupted;
use SugarCraft\Code\Context\TurnContextBlock;
use SugarCraft\Code\Message;
use SugarCraft\Code\Messages\AssistantMessage;
use SugarCraft\Code\Messages\ToolResultMessage;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolCall;
use SugarCraft\Code\Tools\ToolResult;

/**
 * {@see EngineBackend::completeTranscript()}: the same bounded loop as
 * complete(), answering with the whole typed conversation so a delegated run
 * can be resumed — and, on a failure, with the conversation up to its last
 * completed step.
 */
final class EngineBackendTranscriptTest extends TestCase
{
    public function testTheTranscriptCarriesEveryStepAndTheFinalAnswer(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'looking', toolCalls: [new ToolCall('call_1', 'echo', [])]),
            new CompleteResponse(content: 'answer'),
        ]);

        $turn = EngineBackend::new($provider, 'm')
            ->withTools([self::echoTool()])
            ->completeTranscript([new UserMessage('go')]);

        $this->assertInstanceOf(TranscriptTurn::class, $turn);
        $this->assertSame('answer', $turn->reply->content);
        // The conversation itself: the `<turn-context>` rows the loop
        // persists (step 1.A-2) ride along and are resumed with it.
        $conversation = TurnContextBlock::strip($turn->transcript);
        $this->assertSame(['user', 'assistant', 'tool', 'assistant'], array_map(static fn ($m): string => $m->role(), $conversation));
        $this->assertInstanceOf(AssistantMessage::class, $conversation[1]);
        $this->assertSame('call_1', $conversation[1]->toolCalls()[0]->id());
        $this->assertInstanceOf(ToolResultMessage::class, $conversation[2]);
    }

    public function testAFailureCarriesTheTranscriptUpToTheLastCompletedStep(): void
    {
        $boom = new \RuntimeException('provider went away');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'echo', [])]),
            $boom,
        ]);

        $interrupted = null;
        try {
            EngineBackend::new($provider, 'm')->withTools([self::echoTool()])->completeTranscript([new UserMessage('go')]);
        } catch (TurnInterrupted $caught) {
            $interrupted = $caught;
        }

        $this->assertNotNull($interrupted, 'the failure must surface as TurnInterrupted');
        $this->assertSame('provider went away', $interrupted->getMessage());
        $this->assertSame($boom, $interrupted->getPrevious());
        $this->assertSame(['user', 'assistant', 'tool'], array_map(static fn ($m): string => $m->role(), TurnContextBlock::strip($interrupted->transcript)));
    }

    public function testCompleteStillThrowsTheOriginalFailure(): void
    {
        $boom = new \RuntimeException('provider went away');

        $thrown = null;
        try {
            EngineBackend::new(new ScriptedProvider([$boom]), 'm')->complete([Message::user('go')]);
        } catch (\RuntimeException $caught) {
            $thrown = $caught;
        }

        $this->assertSame($boom, $thrown, 'complete() callers see exactly what they saw before');
    }

    private static function echoTool(): Tool
    {
        return new class () implements Tool {
            public function name(): string
            {
                return 'echo';
            }

            public function description(): string
            {
                return 'answers ok';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: 'ok');
            }
        };
    }
}

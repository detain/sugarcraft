<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Backend\TranscriptTurn;
use SugarCraft\Crush\Backend\TurnInterrupted;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

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
        $this->assertSame(['user', 'assistant', 'tool', 'assistant'], array_map(static fn ($m): string => $m->role(), $turn->transcript));
        $this->assertInstanceOf(AssistantMessage::class, $turn->transcript[1]);
        $this->assertSame('call_1', $turn->transcript[1]->toolCalls()[0]->id());
        $this->assertInstanceOf(ToolResultMessage::class, $turn->transcript[2]);
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
        $this->assertSame(['user', 'assistant', 'tool'], array_map(static fn ($m): string => $m->role(), $interrupted->transcript));
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

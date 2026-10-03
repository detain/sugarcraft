<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall as EngineToolCall;
use SugarCraft\Crush\Tools\ToolResult as EngineToolResult;

/**
 * Roadmap 1.B-2 at Chat's settle arm: the narration a model writes between
 * tool calls ("let me check the config first") used to be streamed, wiped at
 * the next ToolStarted, and gone - only the last step's words came back, so
 * the next turn's model had no idea why it had called anything. Now the
 * settled turn keeps each step's narration in the history, hidden from the
 * transcript the user reads (which is unchanged) and replayed to the model
 * with the calls it introduced.
 */
final class InterimNarrationKeptTest extends TestCase
{
    public function testTheNarrationIsKeptForTheModelAndOffTheScreen(): void
    {
        $chat = $this->settled($this->engineReply());

        $hidden = array_values(array_filter($chat->history, static fn(Message $m): bool => !$m->userVisible));
        $this->assertCount(1, $hidden);
        $this->assertSame('NARRATION-checking the config', $hidden[0]->content);
        $this->assertSame('call_1', $hidden[0]->toolCalls[0]->id);

        $frame = Renderer::render($chat);
        $this->assertStringNotContainsString('NARRATION', $frame, 'the transcript the user reads does not change');
        $this->assertStringContainsString('FINAL-all done', $frame);
    }

    public function testTheNextRequestCarriesTheNarrationWithItsCall(): void
    {
        $chat = $this->settled($this->engineReply());

        $backend = EngineBackend::new(new ScriptedProvider([new CompleteResponse(content: 'x')]), 'm');
        $typed = (new \ReflectionMethod($backend, 'toTypedMessages'))
            ->invoke($backend, [...Message::agentVisible($chat->history), Message::user('next')]);

        $assistants = array_values(array_filter($typed, static fn($m): bool => $m instanceof AssistantMessage));
        $this->assertSame('NARRATION-checking the config', $assistants[0]->content());
        $this->assertSame('call_1', $assistants[0]->toolCalls()[0]->id());
        $this->assertSame('FINAL-all done', $assistants[1]->content());
        $this->assertSame(['user', 'assistant', 'tool', 'assistant', 'user'], array_map(static fn($m): string => $m->role(), $typed));
    }

    public function testTheToolRowOnScreenIsTheOneThatIsReplayed(): void
    {
        $chat = $this->settled($this->engineReply());

        $toolRows = array_values(array_filter($chat->history, static fn(Message $m): bool => $m->toolResults !== []));
        $this->assertCount(1, $toolRows, 'no second copy of the result lands in the history');
        $this->assertNotNull($toolRows[0]->stepId);
        $this->assertSame('cat config(…)', $toolRows[0]->toolResults[0]->description, 'the row the live event drew, stamped');
    }

    public function testAPlainReplySettlesExactlyAsBefore(): void
    {
        $chat = $this->settled(Message::assistant('FINAL-plain'));

        $this->assertSame(['go', 'cfg', 'FINAL-plain'], array_map(static fn(Message $m): string => $m->content, $chat->history));
        $this->assertTrue($chat->history[2]->userVisible);
        $this->assertFalse($chat->history[2]->uiOnly);
    }

    // ── harness ─────────────────────────────────────────────────────────

    /** The reply a real two-step engine turn returns. */
    private function engineReply(): Message
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'NARRATION-checking the config', toolCalls: [new EngineToolCall('call_1', 'cat', [])]),
            new CompleteResponse(content: 'FINAL-all done'),
        ]);

        return EngineBackend::new($provider, 'm')
            ->withTools([self::catTool()])
            ->complete([Message::user('go')]);
    }

    /** Settle $reply over the history the live events left: the prompt and the finished tool row. */
    private function settled(Message $reply): Chat
    {
        $chat = new Chat(history: [
            Message::user('go'),
            Message::assistant('cfg')->withToolResults([
                (new ToolResult('cat', 'cfg', null, 'call_1'))->withDescription('cat config(…)'),
            ]),
        ], backend: new EchoBackend());

        [$next] = $chat->update(new AssistantMsg($reply));
        $this->assertInstanceOf(Chat::class, $next);

        return $next;
    }

    private static function catTool(): Tool
    {
        return new class () implements Tool {
            public function name(): string
            {
                return 'cat';
            }

            public function description(): string
            {
                return 'reads';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): EngineToolResult
            {
                return new EngineToolResult(toolCallId: '', content: 'cfg');
            }
        };
    }
}

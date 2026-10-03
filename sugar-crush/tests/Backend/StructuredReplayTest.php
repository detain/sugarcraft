<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall as EngineToolCall;
use SugarCraft\Crush\Tools\ToolResult as EngineToolResult;

/**
 * Roadmap 1.B-2: a finished turn is replayed to the next one as it happened -
 * each step's assistant row with its tool calls, narration and reasoning, each
 * result paired to its call - instead of every tool result read back as prose
 * the assistant had said.
 *
 * Driven through the real loop ({@see EngineBackend::complete()} over a
 * {@see ScriptedProvider}), the real fold Chat applies
 * ({@see Message::settleTurnTranscript()}) and the real request the next turn
 * builds (the typed messages the provider receives).
 */
final class StructuredReplayTest extends TestCase
{
    public function testTheTurnComesBackStepByStep(): void
    {
        $reply = $this->toolTurn()->complete([Message::user('go')]);

        $this->assertSame('answer', $reply->content);
        $this->assertNotNull($reply->stepId, 'a tool-free final step travels as the reply itself');
        $this->assertCount(2, $reply->turnTranscript);

        [$step, $result] = $reply->turnTranscript;
        $this->assertSame('looking', $step->content, 'the interim narration is kept');
        $this->assertSame('pondering', $step->reasoning);
        $this->assertFalse($step->userVisible, 'the step record is the model\'s, not the transcript\'s');
        $this->assertSame('call_1', $step->toolCalls[0]->id);
        $this->assertSame('ok', $result->toolResults[0]->result);
        $this->assertSame('call_1', $result->toolResults[0]->id);
        $this->assertSame('echo', $result->toolResults[0]->name);
        $this->assertSame($step->stepId, $result->stepId, 'a step\'s call and its result share the step');
        $this->assertNotSame($step->stepId, $reply->stepId);
    }

    public function testTheNextTurnReplaysCallsAndResultsAsPairs(): void
    {
        $provider = $this->script(
            new CompleteResponse(content: 'looking', toolCalls: [new EngineToolCall('call_1', 'echo', ['q' => 1])], reasoning: 'pondering'),
            new CompleteResponse(content: 'answer'),
            new CompleteResponse(content: 'second answer'),
        );
        $backend = EngineBackend::new($provider, 'm')->withTools([self::echoTool()]);

        $reply = $backend->complete([Message::user('go')]);
        [$history, $reply] = Message::settleTurnTranscript($this->shown('go', 'call_1', 'echo', 'ok'), $reply);
        $backend->complete([...$history, $reply, Message::user('next')]);

        $replayed = self::conversation($provider->requests[2]->messages);
        $this->assertSame([
            ['user', 'go'],
            ['assistant', 'looking', ['call_1'], 'pondering'],
            ['tool', 'ok', 'call_1'],
            ['assistant', 'answer'],
            ['user', 'next'],
        ], $replayed);

        // The step the second request of turn one sent is the prefix the next
        // turn sends again - same rows, same order, same ids.
        $this->assertSame(
            self::conversation($provider->requests[1]->messages),
            \array_slice($replayed, 0, 3),
        );
    }

    public function testAnEmptyResultAndAnUnansweredCallFollowZedsRules(): void
    {
        $history = [
            Message::user('go'),
            Message::assistant('two calls')
                ->withToolCalls([new ToolCall('echo', [], 'a'), new ToolCall('echo', [], 'b')])
                ->withStepId('s_t_1')
                ->withUserVisible(false),
            Message::assistant('')->withToolResults([new ToolResult('echo', '', null, 'a')])->withStepId('s_t_1'),
        ];

        $typed = $this->typed($history);

        $this->assertSame([
            ['user', 'go'],
            ['assistant', 'two calls', ['a', 'b']],
            ['tool', '<Tool returned an empty string>', 'a'],
            ['tool', 'Tool canceled by user', 'b', 'error'],
        ], self::conversation($typed));
    }

    public function testAStepIsRebuiltWholeWhereverItsRowsSit(): void
    {
        $call = new ToolCall('echo', [], 'a');
        $history = [
            Message::user('go'),
            Message::assistant('ok')->withToolResults([new ToolResult('echo', 'ok', null, 'a')])->withStepId('s_t_1'),
            Message::notice('a notice between them'),
            Message::assistant('calling')->withToolCalls([$call])->withStepId('s_t_1')->withUserVisible(false),
        ];

        $this->assertSame([
            ['user', 'go'],
            ['assistant', 'calling', ['a']],
            ['tool', 'ok', 'a'],
        ], self::conversation($this->typed($history)));
    }

    public function testAResultWithNoCallLeftIsReadBackAsProse(): void
    {
        $history = [
            Message::user('go'),
            Message::assistant('Tool error: boom')
                ->withToolResults([new ToolResult('echo', '', 'boom', 'a')])
                ->withStepId('s_t_1'),
        ];

        $this->assertSame([
            ['user', 'go'],
            ['assistant', 'Tool error: boom'],
        ], self::conversation($this->typed($history)));
    }

    public function testRowsWithoutAStepReplayAsTheyAlwaysDid(): void
    {
        $history = [
            Message::user('go'),
            Message::assistant('legacy output')->withToolResults([new ToolResult('echo', 'legacy output', null, 'a')]),
            Message::assistant('HIDDEN')->withUiOnly(),
            Message::assistant('done'),
        ];

        $this->assertSame([
            ['user', 'go'],
            ['assistant', 'legacy output'],
            ['assistant', 'done'],
        ], self::conversation($this->typed($history)));
    }

    public function testShownToolRowsAreStampedAndTheRecordGoesInBeforeThem(): void
    {
        $reply = $this->toolTurn()->complete([Message::user('go')]);
        $shown = $this->shown('go', 'call_1', 'echo', 'ok');

        [$history, $settled] = Message::settleTurnTranscript($shown, $reply);

        $this->assertCount(3, $history);
        $this->assertSame($shown[0], $history[0], 'the prompt is untouched');
        $this->assertFalse($history[1]->userVisible, 'the step record goes in hidden');
        $this->assertSame('looking', $history[1]->content);
        $this->assertSame('call_1', $history[2]->toolResults[0]->id);
        $this->assertTrue($history[2]->userVisible, 'the row on screen stays on screen');
        $this->assertSame('echo(…)', $history[2]->toolResults[0]->description, 'it is the SAME row, only stamped');
        $this->assertSame($history[1]->stepId, $history[2]->stepId);
        $this->assertSame([], $settled->turnTranscript, 'the transport field ends at the fold');
        $this->assertFalse($settled->uiOnly, 'a reply that is the last step stays the model\'s');
    }

    public function testAToolResultWithNoRowOnScreenGoesInShown(): void
    {
        $reply = $this->toolTurn()->complete([Message::user('go')]);

        [$history] = Message::settleTurnTranscript([Message::user('go')], $reply);

        $this->assertCount(3, $history);
        $this->assertSame('ok', $history[2]->toolResults[0]->result);
        $this->assertTrue($history[2]->userVisible);
    }

    public function testAReplyThatIsNotTheLastStepIsShownButNotSentTwice(): void
    {
        $reply = Message::assistant('looking')->withTurnTranscript([
            Message::assistant('looking')
                ->withToolCalls([new ToolCall('echo', [], 'a')])
                ->withStepId('s_t_1')
                ->withUserVisible(false),
            Message::assistant('ok')->withToolResults([new ToolResult('echo', 'ok', null, 'a')])->withStepId('s_t_1'),
        ]);

        [$history, $settled] = Message::settleTurnTranscript([Message::user('go')], $reply);

        $this->assertTrue($settled->uiOnly);
        $this->assertSame([
            ['user', 'go'],
            ['assistant', 'looking', ['a']],
            ['tool', 'ok', 'a'],
        ], self::conversation($this->typed([...$history, $settled])));
    }

    public function testAReplyWithoutATranscriptPassesThrough(): void
    {
        $history = [Message::user('go')];
        $reply = Message::assistant('plain');

        [$out, $settled] = Message::settleTurnTranscript($history, $reply);

        $this->assertSame($history, $out);
        $this->assertSame($reply, $settled);
    }

    public function testOnlyThisTurnsRowsAreMatched(): void
    {
        // An older turn's row under the same id is outside the window.
        $older = Message::assistant('old')->withToolResults([new ToolResult('echo', 'old', null, 'call_1')]);
        $reply = $this->toolTurn()->complete([Message::user('go')]);

        [$history] = Message::settleTurnTranscript([$older, ...$this->shown('go', 'call_1', 'echo', 'ok')], $reply);

        $this->assertNull($history[0]->stepId);
        $this->assertNotNull($history[3]->stepId);
    }

    // ── harness ─────────────────────────────────────────────────────────

    private function toolTurn(): EngineBackend
    {
        return EngineBackend::new($this->script(
            new CompleteResponse(content: 'looking', toolCalls: [new EngineToolCall('call_1', 'echo', ['q' => 1])], reasoning: 'pondering'),
            new CompleteResponse(content: 'answer'),
        ), 'm')->withTools([self::echoTool()]);
    }

    private function script(CompleteResponse ...$answers): ScriptedProvider
    {
        return new ScriptedProvider($answers);
    }

    /**
     * The history as Chat holds it when the turn settles: the prompt, then the
     * finished tool row its live events drew.
     *
     * @return list<Message>
     */
    private function shown(string $prompt, string $id, string $name, string $output): array
    {
        return [
            Message::user($prompt),
            Message::assistant($output)->withToolResults([
                (new ToolResult($name, $output, null, $id))->withDescription('echo(…)'),
            ]),
        ];
    }

    /**
     * @param list<Message> $history
     * @return list<TypedMessage>
     */
    private function typed(array $history): array
    {
        $backend = EngineBackend::new($this->script(new CompleteResponse(content: 'x')), 'm');

        return (new \ReflectionMethod($backend, 'toTypedMessages'))->invoke($backend, $history);
    }

    /**
     * @param array<array-key, TypedMessage> $messages
     * @return list<list<mixed>>
     */
    private static function conversation(array $messages): array
    {
        $out = [];
        // The conversation only: the `<turn-context>` row (step 1.A-1) that
        // trails a request run inside a git work tree is harness metadata.
        foreach (\SugarCraft\Crush\Context\TurnContextBlock::strip($messages) as $message) {
            if ($message instanceof UserMessage) {
                $out[] = ['user', $message->content()];
            } elseif ($message instanceof AssistantMessage) {
                $row = ['assistant', $message->content()];
                $calls = array_map(static fn(EngineToolCall $c): string => $c->id(), $message->toolCalls() ?? []);
                if ($calls !== []) {
                    $row[] = $calls;
                }
                if ($message->reasoning() !== null) {
                    $row[] = $message->reasoning();
                }
                $out[] = $row;
            } elseif ($message instanceof ToolResultMessage) {
                $out[] = ['tool', $message->content(), $message->toolCallId(), ...($message->isError() ? ['error'] : [])];
            }
        }

        return $out;
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

            public function execute(array $args): EngineToolResult
            {
                return new EngineToolResult(toolCallId: '', content: 'ok');
            }
        };
    }
}

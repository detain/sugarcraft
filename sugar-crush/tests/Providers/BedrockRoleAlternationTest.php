<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\MockHandler;
use Aws\Result;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\BedrockProvider;
use SugarCraft\Crush\Providers\CompleteRequest;

/**
 * Audit A16: Converse requires alternating user/assistant turns and rejects a
 * blank text block, but the provider sent `[User, User, Assistant(''), User]`
 * unchanged as `user, user, assistant{text:""}, user`. Both request paths now
 * merge same-role neighbours and drop blank blocks; asserted on the params
 * the real runtime client serialises, for converse() AND converseStream().
 */
final class BedrockRoleAlternationTest extends TestCase
{
    /**
     * @return iterable<string, array{bool}>
     */
    public static function paths(): iterable
    {
        yield 'converse' => [false];
        yield 'converseStream' => [true];
    }

    #[DataProvider('paths')]
    public function testABlankAssistantReplyBetweenTwoUserTurnsCollapsesToOneUserTurn(bool $stream): void
    {
        $messages = $this->sentMessages($stream, [
            new UserMessage('a'),
            new AssistantMessage(''),
            new UserMessage('b'),
        ]);

        $this->assertSame([
            ['role' => 'user', 'content' => [['text' => 'a'], ['text' => 'b']]],
        ], $messages);
    }

    #[DataProvider('paths')]
    public function testTheAuditReproHistoryAlternatesWithNoBlankText(bool $stream): void
    {
        // repro_bedrock_roles.php: a failed turn (no assistant reply), then an
        // empty assistant reply.
        $messages = $this->sentMessages($stream, [
            new UserMessage('first (turn failed)'),
            new UserMessage('second'),
            new AssistantMessage(''),
            new UserMessage('third'),
        ]);

        $this->assertSame([
            ['role' => 'user', 'content' => [
                ['text' => 'first (turn failed)'],
                ['text' => 'second'],
                ['text' => 'third'],
            ]],
        ], $messages);
        $this->assertStrictlyAlternatingWithNoBlankText($messages);
    }

    #[DataProvider('paths')]
    public function testConsecutiveUserTurnsMergeAroundARealAssistantReply(bool $stream): void
    {
        $messages = $this->sentMessages($stream, [
            new UserMessage('u1'),
            new UserMessage('u2'),
            new AssistantMessage('a1'),
            new UserMessage('u3'),
        ]);

        $this->assertSame([
            ['role' => 'user', 'content' => [['text' => 'u1'], ['text' => 'u2']]],
            ['role' => 'assistant', 'content' => [['text' => 'a1']]],
            ['role' => 'user', 'content' => [['text' => 'u3']]],
        ], $messages);
    }

    #[DataProvider('paths')]
    public function testAToolResultMergesIntoTheUserTurnBesideIt(bool $stream): void
    {
        $messages = $this->sentMessages($stream, [
            new UserMessage('run it'),
            new AssistantMessage('running'),
            new ToolResultMessage('call_1', 'exit 0'),
            new UserMessage('and now?'),
            new AssistantMessage("  \n"),
            new AssistantMessage('done'),
        ]);

        $this->assertSame([
            ['role' => 'user', 'content' => [['text' => 'run it']]],
            ['role' => 'assistant', 'content' => [['text' => 'running']]],
            ['role' => 'user', 'content' => [['text' => 'exit 0'], ['text' => 'and now?']]],
            ['role' => 'assistant', 'content' => [['text' => 'done']]],
        ], $messages);
        $this->assertStrictlyAlternatingWithNoBlankText($messages);
    }

    #[DataProvider('paths')]
    public function testHistorySystemMessagesAreStillHoistedNotMergedIntoATurn(bool $stream): void
    {
        $mock = new MockHandler();
        $mock->append($this->reply($stream));
        $provider = new BedrockProvider($this->client($mock));

        $this->send($provider, $stream, [
            new UserMessage('u1'),
            new SystemMessage('be brief'),
            new UserMessage('u2'),
        ], 'prompt');

        $params = $mock->getLastCommand()->toArray();
        $this->assertSame([['text' => 'prompt'], ['text' => 'be brief']], $params['system']);
        $this->assertSame([
            ['role' => 'user', 'content' => [['text' => 'u1'], ['text' => 'u2']]],
        ], $params['messages']);
    }

    #[DataProvider('paths')]
    public function testAnAlreadyAlternatingHistoryIsSentUnchanged(bool $stream): void
    {
        $messages = $this->sentMessages($stream, [
            new UserMessage('u1'),
            new AssistantMessage('a1'),
            new UserMessage('u2'),
        ]);

        $this->assertSame([
            ['role' => 'user', 'content' => [['text' => 'u1']]],
            ['role' => 'assistant', 'content' => [['text' => 'a1']]],
            ['role' => 'user', 'content' => [['text' => 'u2']]],
        ], $messages);
    }

    /**
     * @param list<array{role: string, content: list<array{text: string}>}> $messages
     */
    private function assertStrictlyAlternatingWithNoBlankText(array $messages): void
    {
        $previous = null;
        foreach ($messages as $i => $turn) {
            $this->assertNotSame($previous, $turn['role'], "turn {$i} repeats the previous role");
            $previous = $turn['role'];
            foreach ($turn['content'] as $block) {
                $this->assertNotSame('', trim($block['text']), "turn {$i} carries a blank text block");
            }
        }
    }

    /**
     * @param array<Message> $history
     * @return list<array{role: string, content: list<array{text: string}>}>
     */
    private function sentMessages(bool $stream, array $history): array
    {
        $mock = new MockHandler();
        $mock->append($this->reply($stream));

        $this->send(new BedrockProvider($this->client($mock)), $stream, $history, null);

        $this->assertSame($stream ? 'ConverseStream' : 'Converse', $mock->getLastCommand()->getName());

        return $mock->getLastCommand()->toArray()['messages'];
    }

    /**
     * @param array<Message> $history
     */
    private function send(BedrockProvider $provider, bool $stream, array $history, ?string $systemPrompt): void
    {
        $request = new CompleteRequest(
            model: 'us.anthropic.claude-sonnet-4-6',
            messages: $history,
            systemPrompt: $systemPrompt,
        );

        if ($stream) {
            iterator_to_array($provider->completeStream($request));

            return;
        }

        $provider->complete($request);
    }

    private function reply(bool $stream): Result
    {
        return $stream
            ? new Result(['stream' => new \ArrayIterator([
                ['contentBlockDelta' => ['delta' => ['text' => 'ok'], 'contentBlockIndex' => 0]],
                ['messageStop' => ['stopReason' => 'end_turn']],
            ])])
            : new Result([
                'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'ok']]]],
                'stopReason' => 'end_turn',
                'usage' => ['inputTokens' => 1, 'outputTokens' => 1],
            ]);
    }

    private function client(MockHandler $handler): BedrockRuntimeClient
    {
        return new BedrockRuntimeClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
            'handler' => $handler,
        ]);
    }
}

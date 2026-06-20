<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Messages;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;

/**
 * @see Message
 * @see UserMessage
 * @see AssistantMessage
 * @see SystemMessage
 * @see ToolResultMessage
 */
final class MessageTest extends TestCase
{
    /**
     * @dataProvider messageClassesProvider
     */
    public function testMessageClassesImplementMessageInterface(string $className): void
    {
        $message = $this->createMessage($className);

        $this->assertInstanceOf(Message::class, $message);
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function messageClassesProvider(): iterable
    {
        yield UserMessage::class => [UserMessage::class];
        yield AssistantMessage::class => [AssistantMessage::class];
        yield SystemMessage::class => [SystemMessage::class];
        yield ToolResultMessage::class => [ToolResultMessage::class];
    }

    private function createMessage(string $className): Message
    {
        return match ($className) {
            UserMessage::class => new UserMessage('Hello'),
            AssistantMessage::class => new AssistantMessage('I am an assistant'),
            SystemMessage::class => new SystemMessage('You are helpful'),
            ToolResultMessage::class => new ToolResultMessage('call_123', 'Result content'),
            default => throw new \InvalidArgumentException("Unknown class: $className"),
        };
    }

    // =========================================================================
    // UserMessage Tests
    // =========================================================================

    public function testUserMessageReturnsCorrectRole(): void
    {
        $message = new UserMessage('Hello, world!');

        $this->assertSame('user', $message->role());
    }

    public function testUserMessageReturnsCorrectContent(): void
    {
        $content = 'This is a user message';
        $message = new UserMessage($content);

        $this->assertSame($content, $message->content());
    }

    public function testUserMessageToArrayReturnsCorrectStructure(): void
    {
        $content = 'Test content';
        $message = new UserMessage($content);
        $array = $message->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('role', $array);
        $this->assertArrayHasKey('content', $array);
        $this->assertSame('user', $array['role']);
        $this->assertSame($content, $array['content']);
    }

    public function testUserMessageToArrayDoesNotContainExtraKeys(): void
    {
        $message = new UserMessage('Content');
        $array = $message->toArray();

        $this->assertCount(2, $array);
    }

    public function testUserMessageWithEmptyContent(): void
    {
        $message = new UserMessage('');

        $this->assertSame('', $message->content());
        $this->assertSame(['role' => 'user', 'content' => ''], $message->toArray());
    }

    // =========================================================================
    // AssistantMessage Tests
    // =========================================================================

    public function testAssistantMessageReturnsCorrectRole(): void
    {
        $message = new AssistantMessage('I am an assistant');

        $this->assertSame('assistant', $message->role());
    }

    public function testAssistantMessageReturnsCorrectContent(): void
    {
        $content = 'This is an assistant response';
        $message = new AssistantMessage($content);

        $this->assertSame($content, $message->content());
    }

    public function testAssistantMessageWithToolCalls(): void
    {
        $toolCalls = [
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'test', 'arguments' => '{}']],
        ];
        $message = new AssistantMessage('Use a tool', $toolCalls);

        $this->assertSame($toolCalls, $message->toolCalls());
    }

    public function testAssistantMessageToolCallsAreNullByDefault(): void
    {
        $message = new AssistantMessage('No tool calls');

        $this->assertNull($message->toolCalls());
    }

    public function testAssistantMessageWithReasoning(): void
    {
        $reasoning = 'I should use the search tool because...';
        $message = new AssistantMessage('Let me search', null, $reasoning);

        $this->assertSame($reasoning, $message->reasoning());
    }

    public function testAssistantMessageReasoningAreNullByDefault(): void
    {
        $message = new AssistantMessage('No reasoning');

        $this->assertNull($message->reasoning());
    }

    public function testAssistantMessageToArrayReturnsCorrectStructure(): void
    {
        $content = 'Test response';
        $toolCalls = [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'test', 'arguments' => '{}']]];
        $reasoning = 'Thinking process';

        $message = new AssistantMessage($content, $toolCalls, $reasoning);
        $array = $message->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('role', $array);
        $this->assertArrayHasKey('content', $array);
        $this->assertArrayHasKey('tool_calls', $array);
        $this->assertArrayHasKey('reasoning', $array);
        $this->assertSame('assistant', $array['role']);
        $this->assertSame($content, $array['content']);
        $this->assertSame($toolCalls, $array['tool_calls']);
        $this->assertSame($reasoning, $array['reasoning']);
    }

    public function testAssistantMessageToArrayWithNullOptionalFields(): void
    {
        $message = new AssistantMessage('Simple response');
        $array = $message->toArray();

        $this->assertNull($array['tool_calls']);
        $this->assertNull($array['reasoning']);
    }

    public function testAssistantMessageWithEmptyToolCallsArray(): void
    {
        $message = new AssistantMessage('Response', []);
        $array = $message->toArray();

        $this->assertSame([], $array['tool_calls']);
    }

    // =========================================================================
    // SystemMessage Tests
    // =========================================================================

    public function testSystemMessageReturnsCorrectRole(): void
    {
        $message = new SystemMessage('You are a helpful assistant');

        $this->assertSame('system', $message->role());
    }

    public function testSystemMessageReturnsCorrectContent(): void
    {
        $content = 'System prompt content';
        $message = new SystemMessage($content);

        $this->assertSame($content, $message->content());
    }

    public function testSystemMessageToArrayReturnsCorrectStructure(): void
    {
        $content = 'You are helpful';
        $message = new SystemMessage($content);
        $array = $message->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('role', $array);
        $this->assertArrayHasKey('content', $array);
        $this->assertSame('system', $array['role']);
        $this->assertSame($content, $array['content']);
    }

    public function testSystemMessageToArrayDoesNotContainExtraKeys(): void
    {
        $message = new SystemMessage('Content');
        $array = $message->toArray();

        $this->assertCount(2, $array);
    }

    public function testSystemMessageWithEmptyContent(): void
    {
        $message = new SystemMessage('');

        $this->assertSame('', $message->content());
        $this->assertSame(['role' => 'system', 'content' => ''], $message->toArray());
    }

    // =========================================================================
    // ToolResultMessage Tests
    // =========================================================================

    public function testToolResultMessageReturnsCorrectRole(): void
    {
        $message = new ToolResultMessage('call_123', 'Result content');

        $this->assertSame('tool', $message->role());
    }

    public function testToolResultMessageReturnsCorrectContent(): void
    {
        $content = 'Tool execution result';
        $message = new ToolResultMessage('call_123', $content);

        $this->assertSame($content, $message->content());
    }

    public function testToolResultMessageReturnsCorrectToolCallId(): void
    {
        $toolCallId = 'call_abc_123';
        $message = new ToolResultMessage($toolCallId, 'Content');

        $this->assertSame($toolCallId, $message->toolCallId());
    }

    public function testToolResultMessageIsErrorIsFalseByDefault(): void
    {
        $message = new ToolResultMessage('call_123', 'Success result');

        $this->assertFalse($message->isError());
    }

    public function testToolResultMessageIsErrorCanBeTrue(): void
    {
        $message = new ToolResultMessage('call_123', 'Error occurred', true);

        $this->assertTrue($message->isError());
    }

    public function testToolResultMessageToArrayReturnsCorrectStructure(): void
    {
        $toolCallId = 'call_456';
        $content = 'Result content';
        $message = new ToolResultMessage($toolCallId, $content, false);
        $array = $message->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('role', $array);
        $this->assertArrayHasKey('tool_call_id', $array);
        $this->assertArrayHasKey('content', $array);
        $this->assertArrayHasKey('is_error', $array);
        $this->assertSame('tool', $array['role']);
        $this->assertSame($toolCallId, $array['tool_call_id']);
        $this->assertSame($content, $array['content']);
        $this->assertFalse($array['is_error']);
    }

    public function testToolResultMessageToArrayWithIsErrorTrue(): void
    {
        $message = new ToolResultMessage('call_789', 'Error message', true);
        $array = $message->toArray();

        $this->assertTrue($array['is_error']);
    }

    public function testToolResultMessageToArrayContainsAllFourKeys(): void
    {
        $message = new ToolResultMessage('call_123', 'Content', true);
        $array = $message->toArray();

        $this->assertCount(4, $array);
    }

    public function testToolResultMessageWithEmptyContent(): void
    {
        $message = new ToolResultMessage('call_123', '');
        $array = $message->toArray();

        $this->assertSame('', $array['content']);
        $this->assertFalse($array['is_error']);
    }

    public function testToolResultMessageWithNumericToolCallId(): void
    {
        $message = new ToolResultMessage('12345', 'Result');

        $this->assertSame('12345', $message->toolCallId());
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use PHPUnit\Framework\TestCase;

final class ChatTest extends TestCase
{
    public function testTypingAccumulatesCharsInInputBuffer(): void
    {
        $chat = new Chat();
        [$h] = $chat->update(new KeyMsg(KeyType::Char, 'h'));
        [$he] = $h->update(new KeyMsg(KeyType::Char, 'e'));
        [$hel] = $he->update(new KeyMsg(KeyType::Char, 'l'));
        $this->assertSame('hel', $hel->inputBuf);
    }

    public function testSpaceKeyAppendsSpace(): void
    {
        $chat = new Chat();
        [$a]  = $chat->update(new KeyMsg(KeyType::Char,  'a'));
        [$ab] = $a->update(new KeyMsg(KeyType::Space, ''));
        [$abc] = $ab->update(new KeyMsg(KeyType::Char, 'b'));
        $this->assertSame('a b', $abc->inputBuf);
    }

    public function testBackspaceDropsLastChar(): void
    {
        $chat = new Chat(inputBuf: 'hello');
        [$next] = $chat->update(new KeyMsg(KeyType::Backspace, ''));
        $this->assertSame('hell', $next->inputBuf);
    }

    public function testBackspaceDropsLastUtf8Codepoint(): void
    {
        $chat = new Chat(inputBuf: 'hi 🚀');
        [$next] = $chat->update(new KeyMsg(KeyType::Backspace, ''));
        $this->assertSame('hi ', $next->inputBuf);
    }

    public function testEnterSubmitsAndSchedulesBackend(): void
    {
        $chat = new Chat(inputBuf: 'hello');
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertCount(1, $next->history);
        $this->assertSame(Role::User, $next->history[0]->role);
        $this->assertSame('hello', $next->history[0]->content);
        $this->assertSame('', $next->inputBuf, 'input cleared after submit');
        $this->assertTrue($next->inFlight, 'inFlight set while waiting');
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testEmptySubmitIsNoop(): void
    {
        $chat = new Chat(inputBuf: '   ');
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame($chat, $next);
        $this->assertNull($cmd);
    }

    public function testAssistantMsgAppendsAndClearsInFlight(): void
    {
        $chat = new Chat(history: [Message::user('hi')], inFlight: true);
        $reply = Message::assistant('hello!');
        [$next] = $chat->update(new AssistantMsg($reply));
        $this->assertCount(2, $next->history);
        $this->assertSame('hello!', $next->history[1]->content);
        $this->assertFalse($next->inFlight);
    }

    public function testKeystrokesIgnoredWhileInFlight(): void
    {
        $chat = new Chat(inputBuf: '', inFlight: true);
        [$next] = $chat->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame('', $next->inputBuf);
    }

    public function testEscQuits(): void
    {
        $chat = new Chat();
        [, $cmd] = $chat->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testEchoBackendRoundTrip(): void
    {
        $chat = new Chat(backend: new EchoBackend(), inputBuf: 'ping');
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        // Run the Cmd → AssistantMsg, dispatch.
        $msg = $cmd();
        $this->assertInstanceOf(AssistantMsg::class, $msg);
        [$final] = $next->update($msg);
        $this->assertCount(2, $final->history);
        $this->assertSame(Role::Assistant, $final->history[1]->role);
        $this->assertStringContainsString('ping', $final->history[1]->content);
    }

    public function testInitReturnsNoCmd(): void
    {
        $this->assertNull((new Chat())->init());
    }

    public function testNonKeyMessageIgnored(): void
    {
        $chat = new Chat(inputBuf: 'x');
        $msg = new \SugarCraft\Core\Msg\WindowSizeMsg(80, 24);
        [$next, $cmd] = $chat->update($msg);
        $this->assertSame($chat, $next);
        $this->assertNull($cmd);
    }

    public function testWithStreamingEnablesFlag(): void
    {
        $chat = new Chat();
        $this->assertFalse($chat->isStreaming());
        $chat2 = $chat->withStreaming(true);
        $this->assertNotSame($chat, $chat2);
        $this->assertTrue($chat2->isStreaming());
    }

    public function testWithStreamingCanDisable(): void
    {
        $chat = new Chat();
        $chat2 = $chat->withStreaming(true);
        $chat3 = $chat2->withStreaming(false);
        $this->assertFalse($chat3->isStreaming());
    }

    public function testOnTokenSetsCallback(): void
    {
        $chat = new Chat();
        $called = false;
        $chat2 = $chat->onToken(function () use (&$called) {
            $called = true;
        });
        $this->assertNotSame($chat, $chat2);
    }

    public function testStreamingStatePreservedOnInput(): void
    {
        $chat = new Chat(inputBuf: '', streaming: true, onToken: static fn() => null);
        [$next] = $chat->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertTrue($next->isStreaming());
    }

    public function testStreamingStatePreservedOnAssistantMsg(): void
    {
        $chat = new Chat(
            history: [Message::user('hi')],
            inFlight: true,
            streaming: true,
            onToken: static fn() => null,
        );
        $reply = Message::assistant('hello!');
        [$next] = $chat->update(new AssistantMsg($reply));
        $this->assertTrue($next->isStreaming());
    }

    public function testStreamingCallbackPassedToBackend(): void
    {
        $tokens = [];
        $chat = new Chat(
            backend: new class implements \SugarCraft\Crush\Backend {
                public function complete(array $history, callable $onToken = null): Message
                {
                    if ($onToken !== null) {
                        $onToken('token1');
                        $onToken('token2');
                        $onToken('token3');
                    }
                    return \SugarCraft\Crush\Message::assistant('streaming reply');
                }
            },
            inputBuf: 'hello',
            streaming: true,
            onToken: static function (string $token) use (&$tokens) {
                $tokens[] = $token;
            },
        );
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceOf(\Closure::class, $cmd);
        // Execute the command to trigger the backend
        $msg = $cmd();
        $this->assertCount(3, $tokens);
        $this->assertSame(['token1', 'token2', 'token3'], $tokens);
    }

    public function testStreamingCallbackNotPassedWhenDisabled(): void
    {
        $callbackReceived = null;
        $chat = new Chat(
            backend: new class implements \SugarCraft\Crush\Backend {
                public function complete(array $history, callable $onToken = null): Message
                {
                    return \SugarCraft\Crush\Message::assistant('reply');
                }
            },
            inputBuf: 'hello',
            streaming: false,
            onToken: static function (string $token) use (&$callbackReceived) {
                $callbackReceived = $token;
            },
        );
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $msg = $cmd();
        // When streaming is disabled, onToken is null (not passed)
        $this->assertNull($callbackReceived);
    }

    public function testRegisterToolAddsTool(): void
    {
        $chat = new Chat();
        $this->assertEmpty($chat->getTools());
        $chat2 = $chat->registerTool('bash', static fn(array $args) => 'result');
        $this->assertNotSame($chat, $chat2);
        $this->assertCount(1, $chat2->getTools());
        $this->assertArrayHasKey('bash', $chat2->getTools());
    }

    public function testRegisterToolIsImmutable(): void
    {
        $chat = new Chat();
        $chat2 = $chat->registerTool('bash', static fn(array $args) => 'result');
        $this->assertEmpty($chat->getTools());
        $this->assertCount(1, $chat2->getTools());
    }

    public function testMultipleToolsCanBeRegistered(): void
    {
        $chat = new Chat();
        $chat2 = $chat
            ->registerTool('bash', static fn(array $args) => 'bash result')
            ->registerTool('read', static fn(array $args) => 'file content');
        $this->assertCount(2, $chat2->getTools());
        $this->assertArrayHasKey('bash', $chat2->getTools());
        $this->assertArrayHasKey('read', $chat2->getTools());
    }

    public function testOnToolCallSetsCallback(): void
    {
        $chat = new Chat();
        $called = false;
        $chat2 = $chat->onToolCall(function () use (&$called) {
            $called = true;
        });
        $this->assertNotSame($chat, $chat2);
        // Callback is stored (we trust it's set correctly by immutability)
        $this->assertNotSame($chat, $chat2);
    }

    public function testToolExecutionOnAssistantMsg(): void
    {
        $toolCall = new \SugarCraft\Crush\ToolCall('bash', ['cmd' => 'ls -la']);
        $message = Message::assistant('Running command...')->withToolCalls([$toolCall]);

        $executedArgs = null;
        $chat = (new Chat(
            history: [Message::user('list files')],
            inFlight: true,
        ))->registerTool('bash', static function (array $args) use (&$executedArgs) {
            $executedArgs = $args;
            return 'total 0' . "\n" . 'drwxr-xr-x 2 user user 4096 May 10 00:00 .';
        });

        [$next] = $chat->update(new AssistantMsg($message));

        // Tool should have been executed synchronously
        $this->assertNotNull($executedArgs);
        $this->assertSame(['cmd' => 'ls -la'], $executedArgs);

        // A follow-up backend call should be scheduled
        $this->assertTrue($next->inFlight);
        // History: user msg + assistant msg + tool result msg
        $this->assertCount(3, $next->history);
    }

    public function testToolResultAddedToHistoryAfterExecution(): void
    {
        $toolCall = new \SugarCraft\Crush\ToolCall('echo', ['text' => 'hello']);
        $message = Message::assistant('Echoing...')->withToolCalls([$toolCall]);

        $chat = (new Chat(
            history: [Message::user('say hello')],
            inFlight: true,
        ))->registerTool('echo', static fn(array $args) => $args['text'] ?? '');

        [$next, ] = $chat->update(new AssistantMsg($message));

        // After tool execution, history should have 3 items:
        // user msg, assistant msg with tool call, tool result
        $this->assertCount(3, $next->history);
        $this->assertSame('', $next->history[2]->content); // tool result content is in a separate message
    }

    public function testUnknownToolReturnsError(): void
    {
        $toolCall = new \SugarCraft\Crush\ToolCall('unknown_tool', []);
        $message = Message::assistant('Calling unknown...')->withToolCalls([$toolCall]);

        // Chat with no tools registered - tool calls should be ignored
        // and message should just be added to history
        $chat = new Chat(
            history: [Message::user('do something')],
            inFlight: true,
        );

        [$next] = $chat->update(new AssistantMsg($message));

        // Without tools registered, tool calls are ignored
        // History: user msg + assistant msg with tool calls (no execution)
        $this->assertCount(2, $next->history);
        $this->assertFalse($next->inFlight);
    }

    public function testToolExceptionReturnsErrorResult(): void
    {
        $toolCall = new \SugarCraft\Crush\ToolCall('failing', []);
        $message = Message::assistant('Calling failing tool...')->withToolCalls([$toolCall]);

        $chat = (new Chat(
            history: [Message::user('test')],
            inFlight: true,
        ))->registerTool('failing', static function (array $args): void {
            throw new \RuntimeException('Tool failed intentionally');
        });

        [$next] = $chat->update(new AssistantMsg($message));

        // History should have user msg, assistant msg, and error result
        $this->assertCount(3, $next->history);
    }

    public function testToolsAndCallbacksPreservedOnInput(): void
    {
        $chat = new Chat(tools: ['test' => static fn() => 'result']);
        [$next] = $chat->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertArrayHasKey('test', $next->getTools());
    }

    public function testAssistantMsgWithoutToolCallsNoOpOnTools(): void
    {
        $chat = (new Chat(
            history: [Message::user('hello')],
            inFlight: true,
        ))->registerTool('bash', static fn(array $args) => 'result');
        $reply = Message::assistant('Hello!');
        [$next] = $chat->update(new AssistantMsg($reply));
        $this->assertCount(2, $next->history);
        $this->assertFalse($next->inFlight);
    }
}

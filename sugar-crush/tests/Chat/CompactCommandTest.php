<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;

final class CompactCommandTest extends TestCase
{
    public function testCompactCommandReducesHistory(): void
    {
        // Create a chat with many messages that will be compacted
        $messages = [];
        for ($i = 0; $i < 15; $i++) {
            $messages[] = Message::user("User message number {$i}");
            $messages[] = Message::assistant("Assistant response number {$i} with some longer content to ensure compaction works properly");
        }

        $chat = new Chat(
            history: $messages,
            inputBuf: '/compact',
            backend: new EchoBackend(),
        );

        // Simulate Enter key to submit
        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        // Verify that compaction happened - history should have the compacted messages plus user cmd and assistant response
        $this->assertGreaterThan(2, count($next->history));

        // The last two messages should be the /compact command and the response
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('compacted', $lastMsg->content);
    }

    public function testCompactCommandShowsSavingsMessage(): void
    {
        // Create a chat with enough messages to trigger compaction
        $messages = [];
        for ($i = 0; $i < 15; $i++) {
            $messages[] = Message::user("User message number {$i}");
            $messages[] = Message::assistant("Assistant response number {$i} with some longer content to ensure compaction works properly");
        }

        $chat = new Chat(
            history: $messages,
            inputBuf: '/compact',
            backend: new EchoBackend(),
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        // The assistant response should mention savings
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertStringContainsString('saved', $lastMsg->content);
        $this->assertStringContainsString('% tokens', $lastMsg->content);
    }

    public function testCompactCommandWithEmptyHistory(): void
    {
        // Create a chat with empty history
        $chat = new Chat(
            history: [],
            inputBuf: '/compact',
            backend: new EchoBackend(),
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        // Should handle gracefully with a message about empty history
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('empty', $lastMsg->content);
    }

    public function testCompactCommandInputBufClearedAfterCompact(): void
    {
        $chat = new Chat(
            history: [Message::user('hello')],
            inputBuf: '/compact',
            backend: new EchoBackend(),
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        // inputBuf should be cleared after /compact command
        $this->assertSame('', $next->inputBuf);
    }

    public function testCompactCommandNotInFlight(): void
    {
        $chat = new Chat(
            history: [Message::user('hello')],
            inputBuf: '/compact',
            backend: new EchoBackend(),
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        // /compact is handled locally, not sent to backend, so inFlight should be false
        $this->assertFalse($next->inFlight);
    }

    public function testCompactPreservesRecentMessages(): void
    {
        // Create a chat with fewer messages than the preserve count (default 10 pairs = 20 messages)
        $messages = [];
        for ($i = 0; $i < 5; $i++) {
            $messages[] = Message::user("User message {$i}");
            $messages[] = Message::assistant("Assistant response {$i}");
        }

        $chat = new Chat(
            history: $messages,
            inputBuf: '/compact',
            backend: new EchoBackend(),
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        // With only 5 pairs (10 messages), no compaction should occur (below preserve threshold)
        // The response should indicate 0% savings
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertStringContainsString('saved 0% tokens', $lastMsg->content);
    }
}

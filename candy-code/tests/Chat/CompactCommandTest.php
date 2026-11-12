<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;

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

    /** @return list<string> the contents the model reads, in order */
    private static function modelReadContents(Chat $chat): array
    {
        return array_map(static fn (Message $m): string => $m->content, Message::agentVisible($chat->history));
    }

    /** Type a draft one key at a time and press Enter, as a user would. */
    private static function typeAndSubmitDraft(Chat $chat, string $draft): Chat
    {
        foreach (mb_str_split($draft) as $char) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $char));
        }
        [$chat] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        return $chat;
    }

    /** A history with a prompt appended after the compaction, as a sent turn leaves it. */
    private static function withPromptSentAfter(Chat $chat): Chat
    {
        return (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($chat, [
            'history' => [...$chat->history, Message::user('a prompt sent after the compaction')],
        ]);
    }

    private static function fifteenExchanges(): array
    {
        $messages = [];
        for ($i = 0; $i < 15; $i++) {
            $messages[] = Message::user("User message number {$i}");
            $messages[] = Message::assistant("Assistant response number {$i} with some longer content to ensure compaction works properly");
        }

        return $messages;
    }

    public function testCompactUndoRestoresTheRowsTheMostRecentCompactionHid(): void
    {
        $original = self::fifteenExchanges();
        $compacted = self::typeAndSubmitDraft(new Chat(history: $original, backend: new EchoBackend()), '/compact');
        $this->assertNotSame(
            array_map(static fn (Message $m): string => $m->content, $original),
            self::modelReadContents($compacted),
            'the compaction changed what the model reads',
        );

        $undone = self::typeAndSubmitDraft($compacted, '/compact --undo');

        $this->assertSame(
            array_map(static fn (Message $m): string => $m->content, $original),
            self::modelReadContents($undone),
            'undo puts back exactly the rows the model read before /compact',
        );
    }

    public function testCompactUndoIsRefusedOnceAPromptHasBeenSentSinceTheCompaction(): void
    {
        $compacted = self::typeAndSubmitDraft(new Chat(history: self::fifteenExchanges(), backend: new EchoBackend()), '/compact');
        $later = self::withPromptSentAfter($compacted);

        $refused = self::typeAndSubmitDraft($later, '/compact --undo');

        $this->assertSame(
            self::modelReadContents($later),
            self::modelReadContents($refused),
            'a refused undo leaves the transcript the model reads untouched',
        );
    }

    public function testCompactUndoWithNoPriorCompactionChangesNothingTheModelReads(): void
    {
        $original = self::fifteenExchanges();
        $chat = self::typeAndSubmitDraft(new Chat(history: $original, backend: new EchoBackend()), '/compact --undo');

        $this->assertSame(
            array_map(static fn (Message $m): string => $m->content, $original),
            self::modelReadContents($chat),
        );
    }

    public function testASecondCompactUndoAfterASuccessfulOneHasNothingToUndo(): void
    {
        $compacted = self::typeAndSubmitDraft(new Chat(history: self::fifteenExchanges(), backend: new EchoBackend()), '/compact');
        $undone = self::typeAndSubmitDraft($compacted, '/compact --undo');

        $again = self::typeAndSubmitDraft($undone, '/compact --undo');

        $this->assertSame(self::modelReadContents($undone), self::modelReadContents($again));
    }
}

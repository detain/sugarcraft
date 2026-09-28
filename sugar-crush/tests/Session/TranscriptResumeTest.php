<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Session;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Attachment;
use SugarCraft\Crush\AttachmentType;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Usage;

/**
 * A session's conversation is saved as it changes and comes back whole when
 * the session is resumed — the model sees the earlier exchange, tool calls and
 * all, not just an id.
 */
final class TranscriptResumeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush_transcript_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function store(): EnhancedSessionStore
    {
        return new EnhancedSessionStore($this->dir . '/session.db');
    }

    /** A second connection, the way another client would touch the file. */
    private function rawPdo(): \PDO
    {
        return new \PDO('sqlite:' . $this->dir . '/session.db');
    }

    private function richAssistantTurn(): Message
    {
        return Message::assistant('Ran it.', 1_700_000_000, 'thinking about ls')
            ->withToolCalls([new ToolCall('bash', ['command' => 'ls'], 'call_1')])
            ->withToolResults([
                ToolResult::ok('bash', "a.txt\nb.txt", 'call_1')->withDescription('bash(command: "ls")'),
                ToolResult::okWithImage('shot', 'captured', "\x89PNG\x00\xff binary", 'call_2'),
            ])
            ->withUsage(Usage::reported(120, 0.25, 100, 20));
    }

    public function testAMessageRoundTripsThroughItsPersistedShape(): void
    {
        $original = new Message(
            Role::User,
            'see attached',
            1_700_000_001,
            attachments: [new Attachment('/tmp/a.png', AttachmentType::Image), new Attachment('/tmp/b.txt', AttachmentType::File)],
        );

        $revived = Message::fromArray(json_decode((string) json_encode($original), true));

        $this->assertSame(Role::User, $revived->role);
        $this->assertSame('see attached', $revived->content);
        $this->assertSame(1_700_000_001, $revived->createdAt);
        $this->assertEquals($original->attachments, $revived->attachments);

        $turn = $this->richAssistantTurn();
        $back = Message::fromArray(json_decode((string) json_encode($turn), true));

        $this->assertSame(Role::Assistant, $back->role);
        $this->assertSame('thinking about ls', $back->reasoning);
        $this->assertEquals($turn->toolCalls, $back->toolCalls);
        $this->assertEquals($turn->toolResults, $back->toolResults, 'binary image bytes survive via base64');
        $this->assertEquals($turn->usage, $back->usage);
    }

    public function testFromArrayToleratesJunkFieldByField(): void
    {
        $message = Message::fromArray([
            'role' => 'wizard',
            'content' => 42,
            'toolCalls' => [['arguments' => []], 'nope', ['name' => 'read', 'id' => 7]],
            'attachments' => [['path' => '/x', 'type' => 'Hologram']],
            'usage' => ['totalTokens' => 'lots'],
        ]);

        $this->assertSame(Role::User, $message->role);
        $this->assertSame('', $message->content);
        $this->assertCount(1, $message->toolCalls);
        $this->assertNull($message->toolCalls[0]->id);
        $this->assertSame([], $message->attachments);
        $this->assertNull($message->usage);
    }

    public function testTheStoreSavesAndLoadsTheCurrentTranscript(): void
    {
        $store = $this->store();
        $store->createSession('s1', 'p', 'm');

        $this->assertNull($store->loadTranscript('s1'), 'nothing saved, no checkpoint: nothing to resume');

        $store->saveTranscript('s1', [Message::user('hi'), $this->richAssistantTurn()]);
        $store->saveTranscript('s1', [Message::user('hi'), $this->richAssistantTurn(), Message::user('more')]);

        $rows = $store->loadTranscript('s1');
        $this->assertIsArray($rows);
        $this->assertCount(3, $rows, 'the latest save replaces the one before');
        $this->assertSame('more', $rows[2]['content']);
    }

    public function testASessionSavedBeforeTranscriptsResumesFromItsNewestCheckpoint(): void
    {
        $store = $this->store();
        $store->createSession('legacy', 'p', 'm');
        $store->saveCheckpoint('legacy', ['messages' => [Message::user('old turn')], 'inputBuf' => '']);

        $rows = $store->loadTranscript('legacy');

        $this->assertSame(['old turn'], array_column((array) $rows, 'content'));
    }

    public function testARewindDoesNotCollectTheBlobsTheTranscriptStillNames(): void
    {
        $store = $this->store();
        $store->createSession('s', 'p', 'm');
        $history = [];
        for ($turn = 1; $turn <= 3; $turn++) {
            $history[] = Message::user("turn {$turn}");
            $store->saveCheckpoint('s', ['messages' => $history, 'inputBuf' => '']);
        }
        $store->saveTranscript('s', [...$history, Message::assistant('the last reply')]);

        $this->assertNotNull($store->restoreCheckpoint('s', 0));

        $rows = $store->loadTranscript('s');
        $this->assertSame(
            ['turn 1', 'turn 2', 'turn 3', 'the last reply'],
            array_column((array) $rows, 'content'),
        );
    }

    public function testSavingRecreatesASessionRowAnotherClientDeleted(): void
    {
        $store = $this->store();

        $store->saveTranscript('ghost', [Message::user('still talking')]);

        $this->assertNotNull($store->getSession('ghost'));
        $this->assertSame(['still talking'], array_column((array) $store->loadTranscript('ghost'), 'content'));
    }

    public function testOnlyOldEmptyUnnamedSessionsArePruned(): void
    {
        $store = $this->store();
        $store->createSession('empty-old', 'p', 'm');
        $store->createSession('empty-new', 'p', 'm');
        $store->createSession('named-old', 'p', 'm', null, 'Keep me');
        $store->createSession('talked-old', 'p', 'm');
        $store->saveTranscript('talked-old', [Message::user('hello')]);
        $store->createSession('current', 'p', 'm');

        $pdo = $this->rawPdo();
        $pdo->exec("UPDATE sessions SET updated_at = '2020-01-01 00:00:00' WHERE id != 'empty-new'");

        $this->assertSame(1, $store->pruneEmptySessions('current'));

        $this->assertNull($store->getSession('empty-old'));
        foreach (['empty-new', 'named-old', 'talked-old', 'current'] as $kept) {
            $this->assertNotNull($store->getSession($kept), "{$kept} must survive");
        }
    }

    public function testChatSavesTheTranscriptWheneverItChanges(): void
    {
        $store = $this->store();
        $store->createSession('live', 'p', 'm');
        $chat = new Chat(sessionStore: $store, currentSessionId: 'live', inputBuf: '/keys');

        // Keystrokes that change nothing in the transcript write nothing.
        [$typed] = $chat->update(new KeyMsg(KeyType::Left));
        $typed->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertNull($store->loadTranscript('live'));

        [$sent] = (new Chat(sessionStore: $store, currentSessionId: 'live', inputBuf: 'hello'))
            ->update(new KeyMsg(KeyType::Enter));
        \assert($sent instanceof Chat);

        $this->assertSame(
            array_map(static fn(Message $m): string => $m->content, $sent->history),
            array_column((array) $store->loadTranscript('live'), 'content'),
        );
    }

    public function testResumingFromThePickerPutsTheConversationBack(): void
    {
        $store = $this->store();
        $store->createSession('earlier', 'p', 'm', null, 'Earlier work');
        $store->saveTranscript('earlier', [Message::user('fix the parser'), $this->richAssistantTurn()]);
        $store->createSession('now', 'p', 'm');
        $this->rawPdo()->exec("UPDATE sessions SET updated_at = '2020-01-01 00:00:00' WHERE id = 'now'");

        $chat = (new Chat(history: [Message::user('unrelated')], sessionStore: $store, currentSessionId: 'now'))
            ->withSessionPickerOpen();
        $this->assertNotNull($chat->sessionPicker());

        [$resumed] = $chat->update(new KeyMsg(KeyType::Enter));
        \assert($resumed instanceof Chat);

        $this->assertSame('earlier', $resumed->currentSessionId());
        $this->assertSame('Earlier work', $resumed->currentSessionName());
        $this->assertNull($resumed->sessionPicker());
        $this->assertSame('fix the parser', $resumed->history[0]->content);
        $this->assertCount(2, $resumed->history[1]->toolResults, 'tool results come back, not a flattened row');
        $this->assertStringContainsString('Resumed session Earlier work', $resumed->history[\count($resumed->history) - 1]->content);
        $this->assertNotContains(
            'unrelated',
            array_map(static fn(Message $m): string => $m->content, $resumed->history),
            'the previous session\'s transcript must not follow the user into the resumed one',
        );
    }

    public function testAToolCallStillRunningWhenSavedComesBackInterrupted(): void
    {
        $running = Message::toolRunning(new ToolCall('bash', ['command' => 'sleep 99'], 'call_9'));

        $revived = Chat::reviveTranscriptMessage(json_decode((string) json_encode($running), true));

        $this->assertNull($revived->pendingToolCallId);
        $this->assertCount(1, $revived->toolResults);
        $this->assertTrue($revived->toolResults[0]->isError());
        $this->assertSame('call_9', $revived->toolResults[0]->id);
    }

    public function testLoadTranscriptAnswersEmptyForAStoreThatCannotSay(): void
    {
        $this->assertSame([], Chat::loadTranscript(null, 'anything'));

        $store = $this->store();
        $store->createSession('x', 'p', 'm');
        $this->assertSame([], Chat::loadTranscript($store, 'x'));
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Mailbox;
use SugarCraft\Crush\Agents\TeamMessage;

/**
 * Tests for Mailbox — append-only JSON-lines inter-teammate messaging.
 */
final class MailboxTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/mailbox_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->basePath);
    }

    // -------------------------------------------------------------------------
    // send
    // -------------------------------------------------------------------------

    public function testSendMessageAppearsInRecipientInbox(): void
    {
        $mailbox = new Mailbox($this->basePath);

        $message = new TeamMessage(
            id: 'msg-1',
            fromTeammateId: 'teammate-a',
            toTeammateId: 'teammate-b',
            type: 'task_result',
            payload: ['task_id' => 'task-42', 'result' => 'done'],
            sentAt: new \DateTimeImmutable('2026-01-01T10:00:00Z'),
        );

        $mailbox->send('teammate-a', 'teammate-b', $message);

        // Verify wake marker was touched
        $wakePath = $this->basePath . '/teammate-b/.wake';
        $this->assertFileExists($wakePath);

        // Verify inbox file was created
        $inboxPath = $this->basePath . '/teammate-b/inbox.jsonl';
        $this->assertFileExists($inboxPath);

        // Verify content
        $content = file_get_contents($inboxPath);
        $data = json_decode(trim($content), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('msg-1', $data['id']);
        $this->assertSame('teammate-a', $data['fromTeammateId']);
        $this->assertSame('teammate-b', $data['toTeammateId']);
        $this->assertSame('task_result', $data['type']);
        $this->assertSame(['task_id' => 'task-42', 'result' => 'done'], $data['payload']);
    }

    public function testSendMessageCreatesDirectoryForNewRecipient(): void
    {
        $mailbox = new Mailbox($this->basePath);

        $message = new TeamMessage(
            id: 'msg-new',
            fromTeammateId: 'lead',
            toTeammateId: 'newcomer',
            type: 'task_assigned',
            payload: ['task_id' => 'task-1'],
            sentAt: new \DateTimeImmutable(),
        );

        $mailbox->send('lead', 'newcomer', $message);

        $inboxPath = $this->basePath . '/newcomer/inbox.jsonl';
        $this->assertFileExists($inboxPath);

        $content = file_get_contents($inboxPath);
        $data = json_decode(trim($content), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('msg-new', $data['id']);
    }

    // -------------------------------------------------------------------------
    // receive
    // -------------------------------------------------------------------------

    public function testReceiveMessageYieldsFromInbox(): void
    {
        $mailbox = new Mailbox($this->basePath);

        // Pre-populate inbox directly to test receive in isolation
        $inboxPath = $this->basePath . '/teammate-b/inbox.jsonl';
        mkdir(dirname($inboxPath), 0755, true);
        file_put_contents($inboxPath, json_encode([
            'id' => 'msg-1',
            'fromTeammateId' => 'teammate-a',
            'toTeammateId' => 'teammate-b',
            'type' => 'task_result',
            'payload' => ['task_id' => 'task-42'],
            'sentAt' => '2026-01-01T10:00:00+00:00',
            'read' => false,
        ]) . "\n");
        file_put_contents($inboxPath, json_encode([
            'id' => 'msg-2',
            'fromTeammateId' => 'teammate-a',
            'toTeammateId' => 'teammate-b',
            'type' => 'task_result',
            'payload' => ['task_id' => 'task-43'],
            'sentAt' => '2026-01-01T11:00:00+00:00',
            'read' => false,
        ]) . "\n", FILE_APPEND);

        $messages = iterator_to_array($mailbox->receive('teammate-b'));

        $this->assertCount(2, $messages);
        $this->assertSame('msg-1', $messages[0]->id);
        $this->assertSame('msg-2', $messages[1]->id);
        $this->assertSame('teammate-a', $messages[0]->fromTeammateId);
        $this->assertSame('task_result', $messages[0]->type);
        $this->assertFalse($messages[0]->read);
    }

    public function testReceiveMessageYieldsEmptyWhenInboxDoesNotExist(): void
    {
        $mailbox = new Mailbox($this->basePath);

        $messages = iterator_to_array($mailbox->receive('nonexistent'));

        $this->assertCount(0, $messages);
    }

    public function testReceiveMessageSkipsMalformedLines(): void
    {
        $mailbox = new Mailbox($this->basePath);

        // Pre-populate inbox with a malformed line in the middle
        $inboxPath = $this->basePath . '/teammate-c/inbox.jsonl';
        mkdir(dirname($inboxPath), 0755, true);
        file_put_contents($inboxPath, json_encode([
            'id' => 'msg-good-1',
            'fromTeammateId' => 'teammate-a',
            'toTeammateId' => 'teammate-c',
            'type' => 'idle',
            'payload' => [],
            'sentAt' => '2026-01-01T10:00:00+00:00',
            'read' => false,
        ]) . "\n");
        file_put_contents($inboxPath, "NOT VALID JSON\n", FILE_APPEND);
        file_put_contents($inboxPath, json_encode([
            'id' => 'msg-good-2',
            'fromTeammateId' => 'teammate-b',
            'toTeammateId' => 'teammate-c',
            'type' => 'error',
            'payload' => ['error' => 'boom'],
            'sentAt' => '2026-01-01T12:00:00+00:00',
            'read' => false,
        ]) . "\n", FILE_APPEND);

        $messages = iterator_to_array($mailbox->receive('teammate-c'));

        $this->assertCount(2, $messages);
        $this->assertSame('msg-good-1', $messages[0]->id);
        $this->assertSame('msg-good-2', $messages[1]->id);
    }

    // -------------------------------------------------------------------------
    // peek
    // -------------------------------------------------------------------------

    public function testPeekUnreadReturnsMessagesWithoutMarkingRead(): void
    {
        $mailbox = new Mailbox($this->basePath);

        // Pre-populate inbox
        $inboxPath = $this->basePath . '/teammate-d/inbox.jsonl';
        mkdir(dirname($inboxPath), 0755, true);
        file_put_contents($inboxPath, json_encode([
            'id' => 'msg-peek-1',
            'fromTeammateId' => 'teammate-a',
            'toTeammateId' => 'teammate-d',
            'type' => 'task_assigned',
            'payload' => [],
            'sentAt' => '2026-01-01T10:00:00+00:00',
            'read' => false,
        ]) . "\n");
        file_put_contents($inboxPath, json_encode([
            'id' => 'msg-peek-2',
            'fromTeammateId' => 'teammate-b',
            'toTeammateId' => 'teammate-d',
            'type' => 'task_assigned',
            'payload' => [],
            'sentAt' => '2026-01-01T11:00:00+00:00',
            'read' => false,
        ]) . "\n", FILE_APPEND);

        $messages = $mailbox->peek('teammate-d');

        $this->assertCount(2, $messages);
        $this->assertFalse($messages[0]->read);
        $this->assertFalse($messages[1]->read);

        // Verify messages are still unread in the file
        $stillUnread = $mailbox->getUnreadCount('teammate-d');
        $this->assertSame(2, $stillUnread);
    }

    // -------------------------------------------------------------------------
    // markRead
    // -------------------------------------------------------------------------

    public function testMarkReadMessageMarkedAsRead(): void
    {
        $mailbox = new Mailbox($this->basePath);

        // Pre-populate inbox
        $inboxPath = $this->basePath . '/teammate-e/inbox.jsonl';
        mkdir(dirname($inboxPath), 0755, true);
        file_put_contents($inboxPath, json_encode([
            'id' => 'msg-read-1',
            'fromTeammateId' => 'teammate-a',
            'toTeammateId' => 'teammate-e',
            'type' => 'task_result',
            'payload' => [],
            'sentAt' => '2026-01-01T10:00:00+00:00',
            'read' => false,
        ]) . "\n");
        file_put_contents($inboxPath, json_encode([
            'id' => 'msg-read-2',
            'fromTeammateId' => 'teammate-b',
            'toTeammateId' => 'teammate-e',
            'type' => 'task_result',
            'payload' => [],
            'sentAt' => '2026-01-01T11:00:00+00:00',
            'read' => false,
        ]) . "\n", FILE_APPEND);

        $mailbox->markRead('teammate-e', 'msg-read-1');

        // Verify unread count decreased
        $this->assertSame(1, $mailbox->getUnreadCount('teammate-e'));

        // Verify correct message is marked read
        $messages = $mailbox->peek('teammate-e');
        $this->assertTrue($messages[0]->read);
        $this->assertFalse($messages[1]->read);
    }

    public function testMarkReadNoOpWhenMessageNotFound(): void
    {
        $mailbox = new Mailbox($this->basePath);

        // Pre-populate with one message
        $inboxPath = $this->basePath . '/teammate-f/inbox.jsonl';
        mkdir(dirname($inboxPath), 0755, true);
        file_put_contents($inboxPath, json_encode([
            'id' => 'msg-actual',
            'fromTeammateId' => 'teammate-a',
            'toTeammateId' => 'teammate-f',
            'type' => 'idle',
            'payload' => [],
            'sentAt' => '2026-01-01T10:00:00+00:00',
            'read' => false,
        ]) . "\n");

        // Try to mark a non-existent message as read
        $mailbox->markRead('teammate-f', 'msg-does-not-exist');

        // Original message should still be unread
        $this->assertSame(1, $mailbox->getUnreadCount('teammate-f'));
    }

    // -------------------------------------------------------------------------
    // getUnreadCount
    // -------------------------------------------------------------------------

    public function testGetUnreadCountReturnsCorrectCount(): void
    {
        $mailbox = new Mailbox($this->basePath);

        // Pre-populate with 3 messages, 1 already read
        $inboxPath = $this->basePath . '/teammate-g/inbox.jsonl';
        mkdir(dirname($inboxPath), 0755, true);
        file_put_contents($inboxPath, json_encode([
            'id' => 'msg-unread-1',
            'fromTeammateId' => 'teammate-a',
            'toTeammateId' => 'teammate-g',
            'type' => 'idle',
            'payload' => [],
            'sentAt' => '2026-01-01T10:00:00+00:00',
            'read' => false,
        ]) . "\n");
        file_put_contents($inboxPath, json_encode([
            'id' => 'msg-read',
            'fromTeammateId' => 'teammate-a',
            'toTeammateId' => 'teammate-g',
            'type' => 'idle',
            'payload' => [],
            'sentAt' => '2026-01-01T11:00:00+00:00',
            'read' => true,
        ]) . "\n", FILE_APPEND);
        file_put_contents($inboxPath, json_encode([
            'id' => 'msg-unread-2',
            'fromTeammateId' => 'teammate-b',
            'toTeammateId' => 'teammate-g',
            'type' => 'error',
            'payload' => [],
            'sentAt' => '2026-01-01T12:00:00+00:00',
            'read' => false,
        ]) . "\n", FILE_APPEND);

        $this->assertSame(2, $mailbox->getUnreadCount('teammate-g'));
    }

    public function testGetUnreadCountReturnsZeroWhenInboxEmpty(): void
    {
        $mailbox = new Mailbox($this->basePath);

        $this->assertSame(0, $mailbox->getUnreadCount('empty-inbox'));
    }

    // -------------------------------------------------------------------------
    // waitForMessage
    // -------------------------------------------------------------------------

    public function testWaitForMessageReturnsImmediatelyWhenMessageAlreadyUnread(): void
    {
        $mailbox = new Mailbox($this->basePath);

        $mailbox->send('teammate-a', 'teammate-h', new TeamMessage(
            id: 'msg-already-there',
            fromTeammateId: 'teammate-a',
            toTeammateId: 'teammate-h',
            type: 'idle',
            payload: [],
            sentAt: new \DateTimeImmutable(),
        ));

        $start = microtime(true);
        $message = $mailbox->waitForMessage('teammate-h', 2.0);
        $elapsed = microtime(true) - $start;

        $this->assertNotNull($message);
        $this->assertSame('msg-already-there', $message->id);
        // Already-unread fast path — must not fall through to the poll loop.
        $this->assertLessThan(0.5, $elapsed);
    }

    public function testWaitForMessageReturnsNullAfterBoundedTimeoutWithNoMessage(): void
    {
        $mailbox = new Mailbox($this->basePath);

        $start = microtime(true);
        $message = $mailbox->waitForMessage('teammate-nobody', 0.2);
        $elapsed = microtime(true) - $start;

        $this->assertNull($message);
        // Genuinely polled for (roughly) the requested timeout rather than
        // returning instantly or hanging indefinitely.
        $this->assertGreaterThanOrEqual(0.15, $elapsed);
        $this->assertLessThan(1.0, $elapsed);
    }

    /**
     * Reproduces the real "wait for the next message" scenario: a message
     * is sent from a genuinely separate OS process (via pcntl_fork) after a
     * short delay, and a caller blocked in waitForMessage() must notice and
     * return the message well before its timeout expires. This is the
     * closest deterministic proxy to a live cross-process wake available in
     * CI — skipped when pcntl is unavailable.
     */
    public function testWaitForMessageReturnsPromptlyWhenSentFromForkedProcess(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl required for a genuine cross-process wake test');
        }

        $basePath = $this->basePath;
        $mailbox = new Mailbox($basePath);

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            // Child process: wait a beat, then send() from an independent
            // process so the parent has to observe the wake marker written
            // by someone else, not merely re-read its own memory state.
            usleep(150_000);
            $childMailbox = new Mailbox($basePath);
            $childMailbox->send('teammate-a', 'teammate-i', new TeamMessage(
                id: 'fork-msg-1',
                fromTeammateId: 'teammate-a',
                toTeammateId: 'teammate-i',
                type: 'task_result',
                payload: ['ok' => true],
                sentAt: new \DateTimeImmutable(),
            ));
            exit(0);
        }

        $start = microtime(true);
        $message = $mailbox->waitForMessage('teammate-i', 3.0);
        $elapsed = microtime(true) - $start;

        pcntl_waitpid($pid, $status);

        $this->assertNotNull($message, 'waitForMessage should have received the forked send()');
        $this->assertSame('fork-msg-1', $message->id);
        // Comfortably below the 3s deadline — proves it woke on the
        // child's send() rather than exhausting the timeout.
        $this->assertLessThan(2.0, $elapsed);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Recursively delete a directory and its contents.
     */
    private function recursiveDelete(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_dir($path)) {
            $items = array_diff(scandir($path), ['.', '..']);
            foreach ($items as $item) {
                $this->recursiveDelete($path . '/' . $item);
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    }
}

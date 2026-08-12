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
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionStore;

/**
 * @see Chat::handleRewindCommand()
 */
final class RewindCommandTest extends TestCase
{
    private string $tempDir;
    private EnhancedSessionStore $sessionStore;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/rewind_cmd_test_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
        $this->sessionStore = new EnhancedSessionStore($this->tempDir . '/sessions.db');
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    // =========================================================================
    // /rewind with no session store configured
    // =========================================================================

    public function testRewindNotConfiguredWhenStoreIsNull(): void
    {
        $chat = new Chat(
            history: [],
            inputBuf: '/rewind',
            backend: new EchoBackend(),
            sessionStore: null,
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('not configured', $lastMsg->content);
    }

    public function testRewindNotConfiguredWhenStoreLacksCheckpointSupport(): void
    {
        // Use plain SessionStore which doesn't have restoreCheckpoint method
        $plainStore = new SessionStore($this->tempDir . '/plain_sessions.db');

        $chat = new Chat(
            history: [],
            inputBuf: '/rewind',
            backend: new EchoBackend(),
            sessionStore: $plainStore,
            currentSessionId: 'some-session',
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('does not support checkpoints', $lastMsg->content);
    }

    // =========================================================================
    // /rewind with no active session
    // =========================================================================

    public function testRewindNoActiveSession(): void
    {
        $this->sessionStore->createSession('test-session', 'openai', 'gpt-4');

        $chat = new Chat(
            history: [],
            inputBuf: '/rewind',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: null, // no active session
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('No active session', $lastMsg->content);
    }

    // =========================================================================
    // /rewind with no checkpoints
    // =========================================================================

    public function testRewindNoCheckpointsAvailable(): void
    {
        $this->sessionStore->createSession('test-session', 'openai', 'gpt-4');

        $chat = new Chat(
            history: [],
            inputBuf: '/rewind',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: 'test-session',
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('No checkpoints available', $lastMsg->content);
    }

    // =========================================================================
    // /rewind restores state correctly
    // =========================================================================

    public function testRewindRestoresCheckpointState(): void
    {
        $this->sessionStore->createSession('test-session', 'openai', 'gpt-4');

        // Save a checkpoint with specific state
        $originalState = [
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
                ['role' => 'assistant', 'content' => 'Hi there!'],
            ],
            'inputBuf' => 'original input',
            'agentContext' => ['currentSessionId' => 'test-session'],
        ];
        $this->sessionStore->saveCheckpoint('test-session', $originalState);

        // Add more checkpoints to simulate more conversation
        $this->sessionStore->saveCheckpoint('test-session', [
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
                ['role' => 'assistant', 'content' => 'Hi there!'],
                ['role' => 'user', 'content' => 'Tell me more'],
            ],
            'inputBuf' => '',
            'agentContext' => ['currentSessionId' => 'test-session'],
        ]);

        $chat = new Chat(
            history: [
                Message::user('Hello'),
                Message::assistant('Hi there!'),
                Message::user('Tell me more'),
                Message::assistant('More info'),
            ],
            inputBuf: '/rewind',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: 'test-session',
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('Rewound', $lastMsg->content);

        // State should be restored to original checkpoint
        $this->assertCount(5, $next->history);
        $this->assertSame('Hello', $next->history[0]->content);
        $this->assertSame('Hi there!', $next->history[1]->content);
        $this->assertSame('', $next->inputBuf);
    }

    /**
     * crush_feat.md §1 E7, end to end: a checkpoint taken while a tool call
     * was still running (the auto-save in {@see Chat::update()} fires per
     * prompt, so this is the ordinary case for a crash mid-call) used to
     * restore its `Message::toolRunning()` placeholder as a plain bubble -
     * losing the dead call's id entirely. Rewinding now yields a real
     * interrupted result under that id.
     */
    public function testRewindHealsACheckpointTakenMidToolCall(): void
    {
        $this->sessionStore->createSession('test-session', 'openai', 'gpt-4');

        $this->sessionStore->saveCheckpoint('test-session', [
            'messages' => [
                ['role' => 'user', 'content' => 'run it'],
                ['role' => 'system', 'content' => 'bash(cmd: "sleep 900")', 'pendingToolCallId' => 'call_9'],
            ],
            'inputBuf' => '',
            'agentContext' => ['currentSessionId' => 'test-session'],
        ]);
        $chat = new Chat(
            history: [Message::user('run it')],
            inputBuf: '/rewind',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: 'test-session',
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $healed = $next->history[1];
        $this->assertNull($healed->pendingToolCallId, 'a restored placeholder kept spinning');
        $this->assertCount(1, $healed->toolResults);
        $this->assertSame('call_9', $healed->toolResults[0]->id);
        $this->assertTrue(Chat::isInterruptedResult($healed->toolResults[0]));
    }

    public function testRewindMultipleStepsBack(): void
    {
        $this->sessionStore->createSession('test-session', 'openai', 'gpt-4');

        // Save 5 checkpoints
        for ($i = 0; $i < 5; $i++) {
            $this->sessionStore->saveCheckpoint('test-session', [
                'messages' => array_fill(0, $i + 1, ['role' => 'user', 'content' => "Message $i"]),
                'inputBuf' => "input $i",
                'agentContext' => ['currentSessionId' => 'test-session'],
            ]);
        }

        $chat = new Chat(
            history: [
                Message::user('Message 0'),
                Message::user('Message 1'),
                Message::user('Message 2'),
                Message::user('Message 3'),
                Message::user('Message 4'),
            ],
            inputBuf: '/rewind',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: 'test-session',
        );

        // Rewind 3 steps back
        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('Rewound', $lastMsg->content);

        // Should have 2 messages (checkpoint at index 1, which is 3 steps back: 4-3+1=2)
        $this->assertCount(7, $next->history);
    }

    // =========================================================================
    // /rewind with invalid step count
    // =========================================================================

    public function testRewindWithZeroStepsDefaultsToOne(): void
    {
        $this->sessionStore->createSession('test-session', 'openai', 'gpt-4');

        $this->sessionStore->saveCheckpoint('test-session', [
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'inputBuf' => '',
            'agentContext' => ['currentSessionId' => 'test-session'],
        ]);

        $this->sessionStore->saveCheckpoint('test-session', [
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
                ['role' => 'user', 'content' => 'World'],
            ],
            'inputBuf' => '',
            'agentContext' => ['currentSessionId' => 'test-session'],
        ]);

        $chat = new Chat(
            history: [
                Message::user('Hello'),
                Message::user('World'),
                Message::user('Extra'),
            ],
            inputBuf: '/rewind 0',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: 'test-session',
        );

        // /rewind 0 should default to 1 step back
        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        // Should have restored to checkpoint with 2 messages (1 step back from checkpoint index 2)
        $this->assertCount(4, $next->history);
    }

    public function testRewindWithNegativeStepsDefaultsToOne(): void
    {
        $this->sessionStore->createSession('test-session', 'openai', 'gpt-4');

        $this->sessionStore->saveCheckpoint('test-session', [
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'inputBuf' => '',
            'agentContext' => ['currentSessionId' => 'test-session'],
        ]);

        $this->sessionStore->saveCheckpoint('test-session', [
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
                ['role' => 'user', 'content' => 'World'],
            ],
            'inputBuf' => '',
            'agentContext' => ['currentSessionId' => 'test-session'],
        ]);

        $chat = new Chat(
            history: [
                Message::user('Hello'),
                Message::user('World'),
                Message::user('Extra'),
            ],
            inputBuf: '/rewind -5',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: 'test-session',
        );

        // /rewind -5 should default to 1 step back
        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $this->assertCount(4, $next->history);
    }
}

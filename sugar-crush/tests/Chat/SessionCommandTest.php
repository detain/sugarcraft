<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Session\SessionStore;

final class SessionCommandTest extends TestCase
{
    private string $tempDir;
    private SessionStore $sessionStore;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/session_cmd_test_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
        $this->sessionStore = new SessionStore($this->tempDir . '/sessions.db');
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

    public function testBranchCreatesForkedSession(): void
    {
        // Arrange: create a session and set it as current
        $this->sessionStore->createSession('test-session', 'openai', 'gpt-4', null, 'Original');
        $initialSessions = $this->sessionStore->listSessions(10);
        $this->assertCount(1, $initialSessions);

        $chat = new Chat(
            history: [],
            inputBuf: '/branch',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: 'test-session',
        );

        // Act
        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        // Assert: command completed without error
        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringStartsWith('Branch created:', $lastMsg->content);

        // Assert: new session was actually created
        $sessions = $this->sessionStore->listSessions(10);
        $this->assertCount(2, $sessions);

        // Assert: new Chat carries the new currentSessionId
        $this->assertNotNull($next->currentSessionId());
        $this->assertNotSame('test-session', $next->currentSessionId());
    }

    public function testBranchRequiresNoArgs(): void
    {
        $this->sessionStore->createSession('test-session', 'openai', 'gpt-4');

        $chat = new Chat(
            history: [],
            inputBuf: '/branch extra-arg',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: 'test-session',
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('Usage:', $lastMsg->content);
        $this->assertStringContainsString('/branch', $lastMsg->content);

        // Session count should be unchanged (no fork happened)
        $sessions = $this->sessionStore->listSessions(10);
        $this->assertCount(1, $sessions);
    }

    public function testRenameRenamesSession(): void
    {
        $this->sessionStore->createSession('test-session', 'openai', 'gpt-4', null, 'Original');

        $chat = new Chat(
            history: [],
            inputBuf: '/rename TestSession',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: 'test-session',
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString("Session renamed to 'TestSession'", $lastMsg->content);

        // Verify the name was actually changed in the store
        $session = $this->sessionStore->getSession('test-session');
        $this->assertNotNull($session);
        $this->assertSame('TestSession', $session['name']);
    }

    public function testRenameRequiresArgs(): void
    {
        $this->sessionStore->createSession('test-session', 'openai', 'gpt-4');

        $chat = new Chat(
            history: [],
            inputBuf: '/rename',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: 'test-session',
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('Usage:', $lastMsg->content);
        $this->assertStringContainsString('/rename', $lastMsg->content);

        // Session name should be unchanged
        $session = $this->sessionStore->getSession('test-session');
        $this->assertNotNull($session);
        $this->assertNull($session['name']);
    }

    public function testSessionCommandNotConfigured(): void
    {
        // No sessionStore set
        $chat = new Chat(
            history: [],
            inputBuf: '/branch',
            backend: new EchoBackend(),
            sessionStore: null,
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('not configured', $lastMsg->content);

        // Also test /rename
        $chat2 = new Chat(
            history: [],
            inputBuf: '/rename SomeName',
            backend: new EchoBackend(),
            sessionStore: null,
        );

        [$next2, ] = $chat2->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next2->inFlight);
        $lastMsg2 = $next2->history[count($next2->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg2->role);
        $this->assertStringContainsString('not configured', $lastMsg2->content);
    }

    // =========================================================================
    // /sessions command (R20): constructs + renders the real SessionPicker
    // against SessionStore::listSessions().
    // =========================================================================

    public function testSessionsCommandRendersRealSessionPicker(): void
    {
        $this->sessionStore->createSession('session-a', 'openai', 'gpt-4', null, 'Alpha');
        $this->sessionStore->createSession('session-b', 'openai', 'gpt-4', null, 'Beta');

        $chat = new Chat(
            history: [],
            inputBuf: '/sessions',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: 'session-a',
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);

        // Distinctive SessionPicker markup (its own title/controls text),
        // not a hand-rolled list — proves SessionPicker::new()->render()
        // actually ran against the real SessionStore rows.
        $this->assertStringContainsString('session picker', $lastMsg->content);
        $this->assertStringContainsString('Alpha', $lastMsg->content);
        $this->assertStringContainsString('Beta', $lastMsg->content);
        $this->assertStringContainsString('resume', $lastMsg->content);
    }

    public function testSessionsCommandNotConfigured(): void
    {
        $chat = new Chat(
            history: [],
            inputBuf: '/sessions',
            backend: new EchoBackend(),
            sessionStore: null,
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('not configured', $lastMsg->content);
    }

    // =========================================================================
    // Ctrl+Tab / Ctrl+Shift+Tab session cycling (R20)
    // =========================================================================

    public function testCtrlTabCyclesToNextSessionForward(): void
    {
        $this->sessionStore->createSession('session-a', 'openai', 'gpt-4', null, 'Alpha');
        $this->sessionStore->createSession('session-b', 'openai', 'gpt-4', null, 'Beta');
        $ids = array_column($this->sessionStore->listSessions(), 'id');

        $chat = new Chat(
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: $ids[0],
        );

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Tab, ctrl: true));

        $this->assertNull($cmd);
        $this->assertSame($ids[1], $next->currentSessionId());
    }

    public function testCtrlShiftTabCyclesToPreviousSessionBackward(): void
    {
        $this->sessionStore->createSession('session-a', 'openai', 'gpt-4', null, 'Alpha');
        $this->sessionStore->createSession('session-b', 'openai', 'gpt-4', null, 'Beta');
        $ids = array_column($this->sessionStore->listSessions(), 'id');

        $chat = new Chat(
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: $ids[0],
        );

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Tab, ctrl: true, shift: true));

        $this->assertNull($cmd);
        // Wraps backward from the first session to the last.
        $this->assertSame($ids[count($ids) - 1], $next->currentSessionId());
    }

    public function testCtrlTabIsNoopWithFewerThanTwoSessions(): void
    {
        $this->sessionStore->createSession('session-a', 'openai', 'gpt-4', null, 'Alpha');

        $chat = new Chat(
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: 'session-a',
        );

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Tab, ctrl: true));

        $this->assertNull($cmd);
        $this->assertSame('session-a', $next->currentSessionId());
    }

    public function testCtrlTabIsNoopWithoutSessionStore(): void
    {
        $chat = new Chat(backend: new EchoBackend());

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Tab, ctrl: true));

        $this->assertNull($cmd);
        $this->assertSame($chat, $next);
    }

    /**
     * Regression test for the R20 review finding: reproduces the exact
     * shape {@see \SugarCraft\Crush\Cli\Bootstrap::chat()} constructs a
     * live Chat with — a real sessionStore, but `currentSessionId` left at
     * its null default (Bootstrap never passes one, and {@see Chat::init()}
     * returns null so nothing selects one at startup either). Feeding a
     * `KeyMsg(KeyType::Tab, ctrl: true)` here bypasses the disclosed
     * InputReader `CSI 1;5I` decoder gap entirely (this is already a raw
     * KeyMsg), yet the update is still a complete no-op — proving Ctrl+Tab
     * session cycling doesn't work for a real `bin/sugarcrush` user today
     * for a second, independent reason beyond the decoder gap. See
     * {@see \SugarCraft\Crush\Chat::cycleSessionTab()}'s "Reachability
     * note" docblock.
     */
    public function testCtrlTabIsNoopForBootstrapShapedChatWithNoInitialSession(): void
    {
        $this->sessionStore->createSession('session-a', 'openai', 'gpt-4', null, 'Alpha');
        $this->sessionStore->createSession('session-b', 'openai', 'gpt-4', null, 'Beta');

        // Mirrors Bootstrap::chat(): sessionStore wired, currentSessionId omitted.
        $chat = new Chat(
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
        );
        $this->assertNull($chat->init());
        $this->assertNull($chat->currentSessionId());

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Tab, ctrl: true));

        $this->assertNull($cmd);
        $this->assertSame($chat, $next);
        $this->assertNull($next->currentSessionId());
    }

    public function testSessionCommandNoActiveSession(): void
    {
        // sessionStore is set but currentSessionId is null
        $this->sessionStore->createSession('test-session', 'openai', 'gpt-4');

        $chat = new Chat(
            history: [],
            inputBuf: '/branch',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: null, // no active session
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('No active session', $lastMsg->content);

        // /rename should behave the same
        $chat2 = new Chat(
            history: [],
            inputBuf: '/rename SomeName',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: null,
        );

        [$next2, ] = $chat2->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next2->inFlight);
        $lastMsg2 = $next2->history[count($next2->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg2->role);
        $this->assertStringContainsString('No active session', $lastMsg2->content);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Session\SessionStore;

final class BranchCommandTest extends TestCase
{
    private string $tempDir;
    private SessionStore $sessionStore;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/branch_test_' . uniqid('', true);
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

    // =========================================================================
    // /branch Command Tests
    // =========================================================================

    public function testBranchCommandRequiresSessionStore(): void
    {
        $chat = new Chat(
            history: [],
            inputBuf: '/branch',
            backend: new EchoBackend(),
            sessionStore: null,
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(\SugarCraft\Crush\Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('not configured', $lastMsg->content);
    }

    public function testBranchCommandRequiresActiveSession(): void
    {
        $chat = new Chat(
            history: [],
            inputBuf: '/branch',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertStringContainsString('No active session', $lastMsg->content);
    }

    public function testBranchCommandWithActiveSession(): void
    {
        // Create an active session
        $sessionId = 'active-session';
        $this->sessionStore->createSession($sessionId, 'openai', 'gpt-4', 'You are helpful', 'Test Session');

        $chat = new Chat(
            history: [],
            inputBuf: '/branch',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
        );
        $chat = $chat->withCurrentSessionId($sessionId);

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        // Branch should succeed: session is active, input is /branch (fork current)
        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(\SugarCraft\Crush\Role::Assistant, $lastMsg->role);
        // A successful fork creates a new session and responds with its ID
        $this->assertStringContainsString('branch created:', strtolower($lastMsg->content));
        // The new chat should have a different (forked) session ID
        $this->assertNotSame($sessionId, $next->currentSessionId());
    }

    // =========================================================================
    // /rename Command Tests
    // =========================================================================

    public function testRenameCommandRequiresSessionStore(): void
    {
        $chat = new Chat(
            history: [],
            inputBuf: '/rename MySession',
            backend: new EchoBackend(),
            sessionStore: null,
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(\SugarCraft\Crush\Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('not configured', $lastMsg->content);
    }

    public function testRenameCommandRequiresActiveSession(): void
    {
        $chat = new Chat(
            history: [],
            inputBuf: '/rename MySession',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertStringContainsString('No active session', $lastMsg->content);
    }

    public function testRenameWithNoNameArgumentShowsUsageHelp(): void
    {
        // /rename with no name argument should show usage help,
        // not "No active session" (name argument is checked before session existence)
        $chat = new Chat(
            history: [],
            inputBuf: '/rename',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertStringContainsString('Usage: /rename', $lastMsg->content);
    }

    public function testRenameCommandWithValidName(): void
    {
        // Create an active session
        $sessionId = 'active-session';
        $this->sessionStore->createSession($sessionId, 'openai', 'gpt-4', null, 'Original');

        $chat = new Chat(
            history: [],
            inputBuf: '/rename NewName',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
        );
        $chat = $chat->withCurrentSessionId($sessionId);

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(\SugarCraft\Crush\Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString("Session renamed to 'NewName'", $lastMsg->content);
    }

    // =========================================================================
    // Input Buffer Tests
    // =========================================================================

    public function testBranchInputBufClearedAfterCommand(): void
    {
        $chat = new Chat(
            history: [],
            inputBuf: '/branch',
            backend: new EchoBackend(),
            sessionStore: null,
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame('', $next->inputBuf);
    }

    public function testRenameInputBufClearedAfterCommand(): void
    {
        $chat = new Chat(
            history: [],
            inputBuf: '/rename SomeName',
            backend: new EchoBackend(),
            sessionStore: null,
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame('', $next->inputBuf);
    }

    // =========================================================================
    // WithSessionStore Fluent Tests
    // =========================================================================

    public function testWithSessionStoreFluent(): void
    {
        $chat = new Chat(
            history: [],
            inputBuf: '',
            backend: new EchoBackend(),
        );

        $chatWithSession = $chat->withSessionStore($this->sessionStore);

        $this->assertNotSame($chat, $chatWithSession);
        $this->assertFalse($chatWithSession->inFlight);
        $this->assertNotNull($chatWithSession->sessionStore());
    }

    public function testSessionStoreGetter(): void
    {
        $chat = new Chat(
            history: [],
            inputBuf: '',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
        );

        $this->assertSame($this->sessionStore, $chat->sessionStore());
    }

    public function testSessionStoreGetterReturnsNullWhenNotSet(): void
    {
        $chat = new Chat(
            history: [],
            inputBuf: '',
            backend: new EchoBackend(),
        );

        $this->assertNull($chat->sessionStore());
    }
}

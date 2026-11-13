<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Session;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Message;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Support\OpensSessionTabs;

/**
 * candy-code-server-017 (S-V11): a TUI conversation leaves ZERO rows in the
 * `messages` table by design. The transcript's owner is `session_transcripts`
 * (state_data, messages interned into checkpoint_blobs); `messages` only
 * fills on fork copies (see {@see \SugarCraft\Code\Session\SessionStore}
 * docblock on the table). This pins both halves so the QA lane finds the
 * answer and a regression in the real owner goes red: a turn followed by the
 * exit-time flush lands in session_transcripts, and the messages table stays
 * empty.
 */
final class SessionFlushOnExitTest extends TestCase
{
    use OpensSessionTabs;

    private string $dir;

    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-flush-exit-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->store = new EnhancedSessionStore($this->dir . '/session.db');
        $this->store->createSession('live', 'p', 'm');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testATurnFlushedAtExitLandsInSessionTranscriptsNotMessages(): void
    {
        $chat = new Chat(sessionStore: $this->store, currentSessionId: 'live');
        $history = [Message::user('hello'), Message::assistant('hi there')];
        $next = (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($chat, ['history' => $history]);
        (new \ReflectionMethod(Chat::class, 'persistTranscript'))->invoke($next, $chat);
        $chat = $next;
        $chat->flushTranscript();

        $pdo = new \PDO('sqlite:' . $this->dir . '/session.db');
        $transcripts = (int) $pdo->query("SELECT COUNT(*) FROM session_transcripts WHERE session_id = 'live'")->fetchColumn();
        $messages = (int) $pdo->query("SELECT COUNT(*) FROM messages WHERE session_id = 'live'")->fetchColumn();

        self::assertSame(1, $transcripts, 'the transcript owner holds the conversation');
        self::assertSame(0, $messages, 'the legacy messages table fills only on fork copies');

        $loaded = $this->store->loadTranscript('live');
        self::assertNotNull($loaded);
        self::assertCount(2, $loaded);
    }
}

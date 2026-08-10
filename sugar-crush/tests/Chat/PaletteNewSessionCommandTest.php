<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Session\SessionStore;

final class PaletteNewSessionCommandTest extends TestCase
{
    private string $tempDir;
    private SessionStore $sessionStore;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/palette_new_session_test_' . uniqid('', true);
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

    private function openPaletteOnNewSession(Chat $chat): Chat
    {
        [$current] = $chat->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        foreach (str_split('new session') as $ch) {
            [$current] = $current->update(new KeyMsg(KeyType::Char, $ch));
        }

        return $current;
    }

    public function testNewSessionCreatesARealRowAndSetsCurrentSessionId(): void
    {
        $chat = new Chat(sessionStore: $this->sessionStore);
        $current = $this->openPaletteOnNewSession($chat);
        $this->assertSame('New session', $current->paletteMatches()[0]);

        [$next] = $current->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($next->palette());
        $this->assertNotNull($next->currentSessionId());
        $this->assertNotNull($this->sessionStore->getSession($next->currentSessionId()));
    }

    public function testNewSessionWithoutStoreDegradesGracefully(): void
    {
        $chat = new Chat();
        $current = $this->openPaletteOnNewSession($chat);

        [$next] = $current->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($next->palette());
        $this->assertNull($next->currentSessionId());
        $this->assertStringContainsString('not configured', $next->history[0]->content);
    }
}

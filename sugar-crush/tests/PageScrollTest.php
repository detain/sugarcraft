<?php
declare(strict_types=1);
namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;

/**
 * Page Up/Down scroll the transcript. The mouse wheel was the only way to
 * move through history; these are its keyboard equivalent.
 */
final class PageScrollTest extends TestCase
{
    private function longChat(): Chat
    {
        $history = [];
        for ($i = 0; $i < 200; $i++) {
            $history[] = Message::user('line ' . $i);
        }

        return (new Chat(history: $history))->withSize(100, 30);
    }

    public function testPageUpScrollsBackThroughHistory(): void
    {
        $chat = $this->longChat();
        $chat->view(); // establish the frame so maxScrollOffset() is known

        [$next] = $chat->update(new KeyMsg(KeyType::PageUp, ''));

        $this->assertGreaterThan(0, $next->scrollOffset(), 'PageUp must scroll back');
    }

    public function testPageDownReturnsTowardTheBottom(): void
    {
        $chat = $this->longChat();
        $chat->view();

        [$up] = $chat->update(new KeyMsg(KeyType::PageUp, ''));
        $up->view();
        [$down] = $up->update(new KeyMsg(KeyType::PageDown, ''));

        $this->assertLessThan($up->scrollOffset(), $down->scrollOffset(), 'PageDown must scroll forward');
    }

    public function testPageDownAtTheBottomIsANoOp(): void
    {
        $chat = $this->longChat();
        $chat->view();

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::PageDown, ''));

        $this->assertSame($chat, $next, 'no new instance for a scroll that cannot happen');
        $this->assertNull($cmd);
    }
}

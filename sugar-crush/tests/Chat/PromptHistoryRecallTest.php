<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Session\PromptHistory;

/**
 * ↑/↓ walk every prompt the user has sent — this session's and, through the
 * {@see PromptHistory} file, the previous sessions' — shell-style.
 */
final class PromptHistoryRecallTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush_recall_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    /** @param list<string> $prompts */
    private function file(array $prompts = []): PromptHistory
    {
        $history = new PromptHistory($this->dir . '/prompt_history.jsonl');
        foreach ($prompts as $prompt) {
            $history->append($prompt);
        }

        return $history;
    }

    private static function up(): KeyMsg
    {
        return new KeyMsg(KeyType::Up);
    }

    private static function down(): KeyMsg
    {
        return new KeyMsg(KeyType::Down);
    }

    private static function press(Chat $chat, KeyMsg ...$keys): Chat
    {
        foreach ($keys as $key) {
            [$chat] = $chat->update($key);
            \assert($chat instanceof Chat);
        }

        return $chat;
    }

    public function testAFreshClientsFirstUpIsThePreviousSessionsLastPrompt(): void
    {
        // An empty transcript: nothing from the earlier session is on screen.
        $chat = new Chat(promptHistory: $this->file(['older', 'last one']));

        $this->assertSame('last one', self::press($chat, self::up())->inputBuf);
        $this->assertSame('older', self::press($chat, self::up(), self::up())->inputBuf);
    }

    public function testUpStopsAtTheOldestPrompt(): void
    {
        $chat = new Chat(promptHistory: $this->file(['only']));

        $this->assertSame('only', self::press($chat, self::up(), self::up(), self::up())->inputBuf);
    }

    public function testDownWalksForwardAndThenGivesTheDraftBack(): void
    {
        $chat = new Chat(promptHistory: $this->file(['a', 'b', 'c']));
        $walked = self::press($chat, self::up(), self::up(), self::up());
        $this->assertSame('a', $walked->inputBuf);

        $this->assertSame('b', self::press($walked, self::down())->inputBuf);
        $this->assertSame('c', self::press($walked, self::down(), self::down())->inputBuf);
        $this->assertSame('', self::press($walked, self::down(), self::down(), self::down())->inputBuf);
    }

    public function testEditingARecalledPromptEndsTheWalk(): void
    {
        $chat = new Chat(promptHistory: $this->file(['a', 'b']));
        $recalled = self::press($chat, self::up(), new KeyMsg(KeyType::Char, '!'));
        $this->assertSame('b!', $recalled->inputBuf);

        // A one-line edited draft: ↑ is the editor's no-op again, not recall.
        $this->assertSame('b!', self::press($recalled, self::up())->inputBuf);
    }

    public function testARecalledSlashCommandDoesNotHandUpToTheSlashPopup(): void
    {
        $chat = new Chat(promptHistory: $this->file(['/sessions', 'hello']));
        $atHello = self::press($chat, self::up());
        $atSlash = self::press($atHello, self::up());
        $this->assertSame('/sessions', $atSlash->inputBuf);
        $this->assertNotSame([], $atSlash->slashMenuMatches(), 'fixture: the popup is showing for the recalled draft');

        $this->assertSame('hello', self::press($atSlash, self::down())->inputBuf);
    }

    public function testEnterRecordsTheTypedPromptInMemoryAndOnDisk(): void
    {
        $file = $this->file(['from before']);
        $chat = new Chat(inputBuf: '  hello there ', promptHistory: $file);

        [$sent] = $chat->update(new KeyMsg(KeyType::Enter));
        \assert($sent instanceof Chat);
        $this->assertSame('', $sent->inputBuf, 'fixture: Enter consumed the draft');

        // Trimmed, as Enter sends it.
        $this->assertSame(['from before', 'hello there'], $file->entries());
        $this->assertSame('hello there', self::press($sent, self::up())->inputBuf);
        $this->assertSame('from before', self::press($sent, self::up(), self::up())->inputBuf);
    }

    public function testRecallingAndResendingAPromptDoesNotDuplicateIt(): void
    {
        $file = $this->file(['same']);
        $chat = self::press(new Chat(promptHistory: $file), self::up());

        [$sent] = $chat->update(new KeyMsg(KeyType::Enter));
        \assert($sent instanceof Chat);

        $this->assertSame(['same'], $file->entries());
    }

    public function testWithoutAFileRecallWalksTheTranscriptsUserRows(): void
    {
        $chat = new Chat(history: [
            Message::user('one'),
            Message::assistant('reply'),
            Message::user('two'),
        ]);

        $this->assertSame('two', self::press($chat, self::up())->inputBuf);
        $this->assertSame('one', self::press($chat, self::up(), self::up())->inputBuf);
    }

    /**
     * The persisted `<turn-context>` row (1.A-2) and nudge rows are user-role
     * but hidden from the user: they were never typed, so ↑ skips them.
     */
    public function testWithoutAFileRecallSkipsRowsHiddenFromTheUser(): void
    {
        $chat = new Chat(history: [
            Message::user('one'),
            Message::user("<turn-context>\nbranch: main\n</turn-context>")->withUserVisible(false),
            Message::assistant('reply'),
        ]);

        $this->assertSame('one', self::press($chat, self::up())->inputBuf);
        $this->assertSame('one', self::press($chat, self::up(), self::up())->inputBuf);
    }

    public function testEnterOnAnEmptyBoxStillHandsBackTheReceiver(): void
    {
        $chat = new Chat(promptHistory: $this->file());

        [$next] = $chat->update(new KeyMsg(KeyType::Enter));

        $this->assertSame($chat, $next);
    }
}

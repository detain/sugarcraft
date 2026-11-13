<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Session\DraftJournal;
use SugarCraft\Code\Session\PromptHistory;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * G-14: Ctrl+C interrupts / clears, Ctrl+C Ctrl+C quits, and a dirty draft is
 * journaled so a ^C never silently eats it.
 */
final class CtrlCDraftSurvivesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ctrlc-draft-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function ctrlC(): KeyMsg
    {
        return new KeyMsg(KeyType::Char, 'c', ctrl: true);
    }

    private function chat(string $draft): Chat
    {
        return new Chat(inputBuf: $draft, promptHistory: new PromptHistory($this->dir . '/history.jsonl'));
    }

    public function testFirstCtrlCClearsAndJournalsTheDraftWithoutQuitting(): void
    {
        [$next, $cmd] = $this->chat('half written thought')->update($this->ctrlC());

        $this->assertNull($cmd, 'first press does not quit');
        $this->assertSame('', $next->inputBuf);
        $this->assertSame(['half written thought'], (new PromptHistory($this->dir . '/history.jsonl'))->entries());
        $this->assertSame(20, (new DraftJournal($this->dir . '/unsent_draft.json'))->take());
    }

    public function testSecondCtrlCWithinTheWindowQuits(): void
    {
        [$next] = $this->chat('draft')->update($this->ctrlC());
        [, $cmd] = $next->update($this->ctrlC());

        $this->assertNotNull($cmd, 'the double press quits');
    }

    public function testAnExpiredArmDoesNotQuit(): void
    {
        $chat = new Chat(lastCtrlCAt: microtime(true) - Chat::CTRL_C_QUIT_WINDOW_SECONDS - 5);

        [, $cmd] = $chat->update($this->ctrlC());

        $this->assertNull($cmd, 'a stale first press is just a new first press');
    }

    public function testEmptyIdleBoxArmsWithANotice(): void
    {
        [$next, $cmd] = (new Chat())->update($this->ctrlC());

        $this->assertNull($cmd);
        $this->assertNotSame([], $next->history);
    }

    public function testRawSyntheticCtrlCStillQuitsAtOnceAndJournalsTheDraft(): void
    {
        [, $cmd] = $this->chat('unsent')->update(new KeyMsg(KeyType::Char, "\x03"));

        $this->assertNotNull($cmd);
        $this->assertSame(6, (new DraftJournal($this->dir . '/unsent_draft.json'))->take());
        $this->assertSame(['unsent'], (new PromptHistory($this->dir . '/history.jsonl'))->entries());
    }

    public function testJournalTakeConsumesTheMarker(): void
    {
        $j = new DraftJournal($this->dir . '/unsent_draft.json');
        $this->assertNull($j->take());
        $j->mark(12);
        $this->assertSame(12, $j->take());
        $this->assertNull($j->take(), 'shown once');
    }
}

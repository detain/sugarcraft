<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Session;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Session\PromptHistory;

final class PromptHistoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush_prompt_history_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function history(int $limit = PromptHistory::DEFAULT_LIMIT): PromptHistory
    {
        return new PromptHistory($this->dir . '/prompt_history.jsonl', $limit);
    }

    public function testAMissingFileIsAnEmptyHistory(): void
    {
        $history = $this->history();

        $this->assertSame([], $history->entries());
        $this->assertSame($this->dir . '/prompt_history.jsonl', $history->path());
    }

    public function testAppendedPromptsComeBackOldestFirstAcrossInstances(): void
    {
        $this->history()->append('first');
        $this->history()->append('second');

        // A new instance is a new client: it reads what the last one wrote.
        $this->assertSame(['first', 'second'], $this->history()->entries());
    }

    public function testAMultiLinePromptRoundTripsIntact(): void
    {
        $this->history()->append("line one\nline two");

        $this->assertSame(["line one\nline two"], $this->history()->entries());
    }

    public function testBlankPromptsAndImmediateRepeatsAreNotStored(): void
    {
        $history = $this->history();
        $history->append('   ');
        $history->append('again');
        $history->append('again');
        $history->append('other');
        $history->append('again');

        $this->assertSame(['again', 'other', 'again'], $history->entries());
    }

    public function testEntriesAreCappedAtTheLimitAndTheFileIsCompacted(): void
    {
        $history = $this->history(3);
        foreach (range(1, 7) as $n) {
            $history->append("p{$n}");
        }

        $this->assertSame(['p5', 'p6', 'p7'], $history->entries());
        // Compaction ran once the file passed twice the limit (7 > 6), so the
        // disk copy holds the newest `limit` plus whatever arrived after.
        $lines = file($this->dir . '/prompt_history.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertLessThanOrEqual(6, \count((array) $lines));
    }

    public function testTornOrForeignLinesAreSkippedNotFatal(): void
    {
        mkdir($this->dir, 0700, true);
        file_put_contents($this->dir . '/prompt_history.jsonl', "\"ok\"\n{not json\n42\n\"\"\n\"also ok\"\n");

        $this->assertSame(['ok', 'also ok'], $this->history()->entries());
    }

    public function testTheFileIsPrivateToTheUser(): void
    {
        $this->history()->append('a secret pasted by mistake');

        $this->assertSame(0600, fileperms($this->dir . '/prompt_history.jsonl') & 0777);
    }

    public function testALimitBelowOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PromptHistory($this->dir . '/x.jsonl', 0);
    }
}

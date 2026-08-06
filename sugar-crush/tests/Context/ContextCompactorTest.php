<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Context\ContextCompactor;

final class ContextCompactorTest extends TestCase
{
    private function cfg(
        int $reminderThreshold = 70,
        int $backgroundCompactionThreshold = 85,
        int $foregroundBlockingThreshold = 95,
        int $recentPreserveCount = 10,
    ): CompactorConfig {
        return new CompactorConfig(
            reminderThreshold: $reminderThreshold,
            backgroundCompactionThreshold: $backgroundCompactionThreshold,
            foregroundBlockingThreshold: $foregroundBlockingThreshold,
            recentPreserveCount: $recentPreserveCount,
        );
    }

    private function msg(string $role, string $content): array
    {
        return ['role' => $role, 'content' => $content];
    }

    // ─── shouldCompact() ─────────────────────────────────────────

    public function testShouldCompactReturnsFalseWhenEmpty(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $this->assertFalse($compactor->shouldCompact([], 1000));
    }

    public function testShouldCompactReturnsFalseWhenBelowThreshold(): void
    {
        $compactor = new ContextCompactor($this->cfg(backgroundCompactionThreshold: 85));
        $messages = array_fill(0, 10, $this->msg('user', '01234567890123456789'));
        $this->assertFalse($compactor->shouldCompact($messages, 1000));
    }

    public function testShouldCompactReturnsTrueWhenAboveThreshold(): void
    {
        $compactor = new ContextCompactor($this->cfg(backgroundCompactionThreshold: 85));
        // Each ~400-char message ≈ 110 tokens (10 + 400/4). 10 msgs ≈ 1100 tokens.
        // 85% of 1200 = 1020 → 1100 >= 1020 → should compact
        $messages = array_fill(0, 10, $this->msg('user', str_repeat('x', 400)));
        $this->assertTrue($compactor->shouldCompact($messages, 1200));
    }

    public function testShouldCompactReturnsFalseWhenTokenLimitIsZero(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $this->assertFalse($compactor->shouldCompact([$this->msg('user', 'hello')], 0));
    }

    public function testShouldCompactReturnsFalseWhenTokenLimitIsNegative(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $this->assertFalse($compactor->shouldCompact([$this->msg('user', 'hello')], -100));
    }

    public function testShouldCompactRespectsCustomThreshold(): void
    {
        $compactor = new ContextCompactor($this->cfg(backgroundCompactionThreshold: 50));
        // 10 msgs × ~60 tokens (10 + 200/4) = 600. 50% of 1000 = 500.
        $messages = array_fill(0, 10, $this->msg('user', str_repeat('x', 200)));
        $this->assertTrue($compactor->shouldCompact($messages, 1000));
    }

    // ─── compact() ────────────────────────────────────────────────

    public function testCompactReturnsEmptyWhenGivenEmpty(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $result = $compactor->compact([]);
        $this->assertSame([], $result);
    }

    public function testCompactReturnsUnchangedWhenBelowPreserveCount(): void
    {
        $compactor = new ContextCompactor($this->cfg(recentPreserveCount: 10));
        $messages = [
            $this->msg('user', 'hello'),
            $this->msg('assistant', 'hi there'),
        ];
        $result = $compactor->compact($messages);
        $this->assertCount(2, $result);
        $this->assertSame($messages, $result);
    }

    public function testCompactReturnsUnchangedWhenFewerThanPreserveCount(): void
    {
        // With 3 messages and recentPreserveCount=10: count (3) <= preserveCount (10)
        // → no compaction, all 3 messages returned unchanged
        $compactor = new ContextCompactor($this->cfg(recentPreserveCount: 10));
        $messages = [
            $this->msg('user', 'first'),
            $this->msg('assistant', 'second'),
            $this->msg('user', 'third'),
        ];
        $result = $compactor->compact($messages);
        $this->assertCount(3, $result);
        $this->assertSame($messages, $result);
    }

    public function testCompactPreservesRecentMessagesVerbatim(): void
    {
        // With 30 messages (15 pairs) and recentPreserveCount=10:
        // → first 5 pairs summarized into 5 summary messages
        // → last 10 pairs preserved verbatim (20 messages)
        // → Total: 25 messages
        $compactor = new ContextCompactor($this->cfg(recentPreserveCount: 10));
        $messages = [];
        for ($i = 0; $i < 15; $i++) {
            $messages[] = $this->msg('user', "question {$i}");
            $messages[] = $this->msg('assistant', "answer {$i}");
        }
        $result = $compactor->compact($messages);
        // Last 10 pairs (20 messages) should be preserved verbatim
        $this->assertCount(25, $result);
        // The last 20 messages should be the last 10 preserved pairs
        $lastTwenty = array_slice($result, -20);
        $expected = array_slice($messages, 10, 20); // Messages 10-29 (last 10 pairs)
        $this->assertSame($expected, $lastTwenty);
        // First 5 should be summaries with [summary] prefix
        for ($i = 0; $i < 5; $i++) {
            $this->assertStringStartsWith('[summary]', $result[$i]['content']);
        }
    }

    public function testCompactSummarizesOlderMessages(): void
    {
        // With 6 messages (3 pairs) and recentPreserveCount=1:
        // → first 2 pairs summarized into 2 summary messages
        // → last 1 pair preserved (2 messages)
        // → Total: 4 messages
        $compactor = new ContextCompactor($this->cfg(recentPreserveCount: 1));
        $messages = [
            $this->msg('user', 'first question'),
            $this->msg('assistant', 'first answer'),
            $this->msg('user', 'second question'),
            $this->msg('assistant', 'second answer'),
            $this->msg('user', 'third question'),
            $this->msg('assistant', 'third answer'),
        ];
        $result = $compactor->compact($messages);
        // 2 older pairs summarized → 2 summaries, last 1 pair preserved → 2 messages
        $this->assertCount(4, $result);
        // First 2 should be summaries
        $this->assertStringStartsWith('[summary]', $result[0]['content']);
        $this->assertStringStartsWith('[summary]', $result[1]['content']);
        // Last 2 should be the preserved pair
        $this->assertSame('third question', $result[2]['content']);
        $this->assertSame('third answer', $result[3]['content']);
    }

    public function testCompactAddsSummaryPrefixToSummarizedMessages(): void
    {
        // With 4 messages (2 pairs) and recentPreserveCount=1:
        // → first 1 pair summarized, last 1 pair preserved
        $compactor = new ContextCompactor($this->cfg(recentPreserveCount: 1));
        $messages = [
            $this->msg('user', 'first question that is quite long'),
            $this->msg('assistant', 'first answer'),
            $this->msg('user', 'recent short message'),
            $this->msg('assistant', 'recent short response'),
        ];
        $result = $compactor->compact($messages);
        $hasSummary = false;
        foreach ($result as $msg) {
            $content = $msg['content'];
            if (strpos($content, '[summary]') !== false) {
                $hasSummary = true;
                break;
            }
        }
        $this->assertTrue($hasSummary, 'Expected at least one summary message with [summary] prefix');
    }

    // ─── savingsPercentage() ─────────────────────────────────────

    public function testSavingsPercentageReturnsZeroWhenEmpty(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $compactor->compact([]);
        $this->assertSame(0, $compactor->savingsPercentage());
    }

    public function testSavingsPercentageReturnsZeroWhenNothingCompacted(): void
    {
        $compactor = new ContextCompactor($this->cfg(recentPreserveCount: 10));
        $messages = [
            $this->msg('user', 'hello'),
            $this->msg('assistant', 'hi'),
        ];
        $compactor->compact($messages);
        $this->assertSame(0, $compactor->savingsPercentage());
    }

    public function testSavingsPercentageReturnsPositiveAfterCompaction(): void
    {
        $compactor = new ContextCompactor($this->cfg(recentPreserveCount: 2));
        $messages = [];
        for ($i = 0; $i < 10; $i++) {
            $messages[] = $this->msg('user', "question number {$i}");
            $messages[] = $this->msg('assistant', "answer number {$i}");
        }
        $compactor->compact($messages);
        $savings = $compactor->savingsPercentage();
        $this->assertGreaterThan(0, $savings);
        $this->assertLessThanOrEqual(100, $savings);
    }

}

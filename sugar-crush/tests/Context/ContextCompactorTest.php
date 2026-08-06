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

    // ─── groupSimilarExchanges() ─────────────────────────────────

    public function testGroupSimilarExchangesReturnsEmptyWhenEmpty(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $result = $compactor->groupSimilarExchanges([]);
        $this->assertSame([], $result);
    }

    public function testGroupSimilarExchangesReturnsUnchangedWhenNoDuplicates(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $messages = [
            $this->msg('user', 'first'),
            $this->msg('assistant', 'second'),
            $this->msg('user', 'third'),
        ];
        $result = $compactor->groupSimilarExchanges($messages);
        $this->assertCount(3, $result);
        $this->assertSame($messages, $result);
    }

    public function testGroupSimilarExchangesGroupsConsecutiveIdentical(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $messages = [
            $this->msg('assistant', 'file not found'),
            $this->msg('assistant', 'file not found'),
            $this->msg('assistant', 'file not found'),
        ];
        $result = $compactor->groupSimilarExchanges($messages);
        $this->assertCount(1, $result);
        $this->assertSame('[3x] file not found', $result[0]['content']);
        $this->assertSame('assistant', $result[0]['role']);
    }

    public function testGroupSimilarExchangesHandlesMixedDuplicates(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $messages = [
            $this->msg('assistant', 'error'),
            $this->msg('assistant', 'error'),
            $this->msg('user', 'different'),
            $this->msg('assistant', 'error'),
        ];
        $result = $compactor->groupSimilarExchanges($messages);
        $this->assertCount(3, $result);
        $this->assertSame('[2x] error', $result[0]['content']);
        $this->assertSame('different', $result[1]['content']);
        $this->assertSame('error', $result[2]['content']); // single, not grouped
    }

    public function testGroupSimilarExchangesPreservesRoleSeparation(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $messages = [
            $this->msg('user', 'same content'),
            $this->msg('assistant', 'same content'),
            $this->msg('user', 'same content'),
        ];
        $result = $compactor->groupSimilarExchanges($messages);
        // user and assistant with same content are NOT grouped (different roles)
        $this->assertCount(3, $result);
    }

    // ─── compactFileReferences() ─────────────────────────────────

    public function testCompactFileReferencesReturnsEmptyWhenEmpty(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $result = $compactor->compactFileReferences([]);
        $this->assertSame([], $result);
    }

    public function testCompactFileReferencesReturnsUnchangedWhenNoFileContent(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $messages = [
            $this->msg('assistant', 'Hello, how can I help you?'),
            $this->msg('user', 'What is the weather?'),
        ];
        $result = $compactor->compactFileReferences($messages);
        $this->assertCount(2, $result);
        $this->assertSame('Hello, how can I help you?', $result[0]['content']);
        $this->assertSame('What is the weather?', $result[1]['content']);
    }

    public function testCompactFileReferencesDetectsPhpFileContent(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $phpContent = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Test;\n\nclass Foo {}\n";
        $messages = [
            $this->msg('assistant', $phpContent),
        ];
        $result = $compactor->compactFileReferences($messages);
        $this->assertCount(1, $result);
        $this->assertStringStartsWith('[file:', $result[0]['content']);
        $this->assertStringContainsString('lines]', $result[0]['content']);
    }

    public function testCompactFileReferencesDetectsFileWithExtension(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $content = "src/Context/Compactor.php\n<?php\ndeclare(strict_types=1);\nclass Foo {}\n";
        $messages = [
            $this->msg('assistant', $content),
        ];
        $result = $compactor->compactFileReferences($messages);
        $this->assertCount(1, $result);
        $this->assertStringStartsWith('[file: src/Context/Compactor.php', $result[0]['content']);
    }

    // ─── removeNavigationSteps() ──────────────────────────────────

    public function testRemoveNavigationStepsReturnsEmptyWhenEmpty(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $result = $compactor->removeNavigationSteps([]);
        $this->assertSame([], $result);
    }

    public function testRemoveNavigationStepsReturnsUnchangedWhenNoNavCommands(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $messages = [
            $this->msg('user', 'Hello there'),
            $this->msg('assistant', 'Hi! How can I help?'),
        ];
        $result = $compactor->removeNavigationSteps($messages);
        $this->assertCount(2, $result);
        $this->assertSame($messages, $result);
    }

    public function testRemoveNavigationStepsRemovesCdCommand(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $messages = [
            $this->msg('assistant', 'cd /home/sites/sugarcraft'),
            $this->msg('assistant', 'Working in the right directory now'),
        ];
        $result = $compactor->removeNavigationSteps($messages);
        $this->assertCount(1, $result);
        $this->assertSame('Working in the right directory now', $result[0]['content']);
    }

    public function testRemoveNavigationStepsRemovesLsCommand(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $messages = [
            $this->msg('assistant', 'ls -la'),
            $this->msg('assistant', 'Here are the files...'),
        ];
        $result = $compactor->removeNavigationSteps($messages);
        $this->assertCount(1, $result);
        $this->assertStringNotContainsString('ls', $result[0]['content']);
    }

    public function testRemoveNavigationStepsRemovesPwdCommand(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $messages = [
            $this->msg('assistant', 'pwd'),
            $this->msg('assistant', '/home/sites/sugarcraft'),
        ];
        $result = $compactor->removeNavigationSteps($messages);
        $this->assertCount(1, $result);
        $this->assertSame('/home/sites/sugarcraft', $result[0]['content']);
    }

    public function testRemoveNavigationStepsPreservesNonNavMessages(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $messages = [
            $this->msg('assistant', 'cd /tmp'),
            $this->msg('user', 'Tell me about files'),
            $this->msg('assistant', 'I can help you with that'),
            $this->msg('assistant', 'mkdir newproject'),
            $this->msg('assistant', 'Created the directory'),
        ];
        $result = $compactor->removeNavigationSteps($messages);
        // cd and mkdir removed, but user message and assistant responses preserved
        $this->assertCount(3, $result);
        $this->assertSame('Tell me about files', $result[0]['content']);
        $this->assertSame('I can help you with that', $result[1]['content']);
        $this->assertSame('Created the directory', $result[2]['content']);
    }

}

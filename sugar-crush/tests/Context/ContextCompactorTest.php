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

    // ─── shouldSendReminder() ────────────────────────────────────

    public function testShouldSendReminderReturnsFalseWhenEmpty(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $this->assertFalse($compactor->shouldSendReminder([], 1000));
    }

    public function testShouldSendReminderReturnsFalseWhenBelowThreshold(): void
    {
        $compactor = new ContextCompactor($this->cfg(reminderThreshold: 70));
        $messages = array_fill(0, 10, $this->msg('user', '01234567890123456789'));
        $this->assertFalse($compactor->shouldSendReminder($messages, 1000));
    }

    public function testShouldSendReminderReturnsTrueAtOrAboveThreshold(): void
    {
        $compactor = new ContextCompactor($this->cfg(reminderThreshold: 70));
        // Each ~400-char message ≈ 110 tokens (10 + 400/4). 10 msgs ≈ 1100 tokens.
        // 70% of 1500 = 1050 → 1100 >= 1050 → should send reminder.
        $messages = array_fill(0, 10, $this->msg('user', str_repeat('x', 400)));
        $this->assertTrue($compactor->shouldSendReminder($messages, 1500));
    }

    public function testShouldSendReminderReturnsFalseWhenTokenLimitIsZero(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $this->assertFalse($compactor->shouldSendReminder([$this->msg('user', 'hello')], 0));
    }

    public function testShouldSendReminderReturnsFalseWhenTokenLimitIsNegative(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $this->assertFalse($compactor->shouldSendReminder([$this->msg('user', 'hello')], -100));
    }

    public function testShouldSendReminderRespectsCustomThreshold(): void
    {
        $compactor = new ContextCompactor($this->cfg(reminderThreshold: 50));
        // 10 msgs × ~60 tokens (10 + 200/4) = 600. 50% of 1000 = 500.
        $messages = array_fill(0, 10, $this->msg('user', str_repeat('x', 200)));
        $this->assertTrue($compactor->shouldSendReminder($messages, 1000));
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

    public function testCompactRunsFileAndNavStagesBeforeSummarizationRegression(): void
    {
        // R21 regression: stages 4 (file-to-metadata) and 5 (remove-nav) must run
        // against the RAW pre-summarization pairs. Under the original bug, stage 2
        // ran first and (a) collapsed the file content into "[exchanged information]"
        // before stage 4 ever saw it (so the "[file:" marker never appeared), and
        // (b) folded the raw "cd ..." command into the summary text as
        // "cd /var/www/project → Now working..." where stage 5's start-of-line nav
        // pattern no longer matched (so the raw command survived verbatim).
        $compactor = new ContextCompactor($this->cfg(recentPreserveCount: 2));

        $fileContent = "config.php\n<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nclass Config\n{\n    public string \$host = 'localhost';\n}\n";

        $messages = [
            // Old nav exchange — beyond the preserve window.
            $this->msg('user', 'cd /var/www/project'),
            $this->msg('assistant', 'Now working in /var/www/project'),
            // Old file-read exchange — beyond the preserve window.
            $this->msg('user', 'Read the config file'),
            $this->msg('assistant', $fileContent),
            // Recent exchanges — within the preserve window (recentPreserveCount = 2).
            $this->msg('user', 'third question'),
            $this->msg('assistant', 'third answer'),
            $this->msg('user', 'fourth question'),
            $this->msg('assistant', 'fourth answer'),
        ];

        $result = $compactor->compact($messages);
        $allContent = implode(' ', array_column($result, 'content'));

        // Stage 4 must have converted the raw file content into a metadata marker.
        $this->assertStringContainsString('[file:', $allContent);
        $this->assertStringNotContainsString($fileContent, $allContent);

        // Stage 5 must have stripped the raw nav command while keeping its outcome.
        $this->assertStringNotContainsString('cd /var/www/project', $allContent);
        $this->assertStringContainsString('Now working in /var/www/project', $allContent);

        // Recent pairs remain preserved verbatim.
        $this->assertStringContainsString('third question', $allContent);
        $this->assertStringContainsString('fourth answer', $allContent);
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

    // ─── shouldCompactForeground() ───────────────────────────────

    public function testShouldCompactAt95Percent(): void
    {
        $compactor = new ContextCompactor($this->cfg(foregroundBlockingThreshold: 95));
        // Each ~460-char message ≈ 125 tokens (10 + 460/4). 20 msgs ≈ 2500 tokens.
        // 95% of 2600 = 2470 → 2500 >= 2470 → should compact foreground
        $messages = array_fill(0, 20, $this->msg('user', str_repeat('x', 460)));
        $this->assertTrue($compactor->shouldCompactForeground($messages, 2600));
    }

    public function testShouldCompactForegroundReturnsFalseBelow95Percent(): void
    {
        $compactor = new ContextCompactor($this->cfg(foregroundBlockingThreshold: 95));
        // 15 msgs × ~110 tokens = 1650. 95% of 2000 = 1900. 1650 < 1900 → false
        $messages = array_fill(0, 15, $this->msg('user', str_repeat('x', 400)));
        $this->assertFalse($compactor->shouldCompactForeground($messages, 2000));
    }

    public function testShouldCompactForegroundReturnsFalseWhenEmpty(): void
    {
        $compactor = new ContextCompactor($this->cfg(foregroundBlockingThreshold: 95));
        $this->assertFalse($compactor->shouldCompactForeground([], 2000));
    }

    // ─── filterSkills() ─────────────────────────────────────────

    public function testFilterSkillsTruncatesLargeSkill(): void
    {
        // skillBudgetPerSkill = 5000 tokens → 5000 * 4 = 20000 chars max per skill
        // Content longer than 20000 chars should be truncated with "..."
        $compactor = new ContextCompactor($this->cfg());
        $largeContent = str_repeat('x', 25000); // 25000 chars > 20000 limit
        $skills = [
            ['name' => 'large_skill', 'content' => $largeContent, 'lastInvokedAt' => 1000],
        ];
        $result = $compactor->filterSkills($skills);
        $this->assertCount(1, $result);
        $this->assertSame(20000, mb_strlen($result[0]['content']));
        $this->assertStringEndsWith('...', $result[0]['content']);
    }

    public function testFilterSkillsDropsLruWhenOverCombinedBudget(): void
    {
        // skillBudgetCombined = 25000 tokens = 100000 chars combined max
        // 11 skills at 12000 chars each = 132000 chars > 100000 limit
        // After truncation (12000 each, under 20000 limit): combined = 132000 > 100000
        // The LRU skill (lowest lastInvokedAt) is dropped first until under budget
        $compactor = new ContextCompactor($this->cfg());
        $skills = [
            ['name' => 'skill_lru',     'content' => str_repeat('A', 12000), 'lastInvokedAt' => 1000],
            ['name' => 'skill_2',       'content' => str_repeat('B', 12000), 'lastInvokedAt' => 2000],
            ['name' => 'skill_3',       'content' => str_repeat('C', 12000), 'lastInvokedAt' => 3000],
            ['name' => 'skill_4',       'content' => str_repeat('D', 12000), 'lastInvokedAt' => 4000],
            ['name' => 'skill_5',       'content' => str_repeat('E', 12000), 'lastInvokedAt' => 5000],
            ['name' => 'skill_6',       'content' => str_repeat('F', 12000), 'lastInvokedAt' => 6000],
            ['name' => 'skill_7',       'content' => str_repeat('G', 12000), 'lastInvokedAt' => 7000],
            ['name' => 'skill_8',       'content' => str_repeat('H', 12000), 'lastInvokedAt' => 8000],
            ['name' => 'skill_9',       'content' => str_repeat('I', 12000), 'lastInvokedAt' => 9000],
            ['name' => 'skill_10',      'content' => str_repeat('J', 12000), 'lastInvokedAt' => 10000],
            ['name' => 'skill_mru',     'content' => str_repeat('K', 12000), 'lastInvokedAt' => 11000],
        ];
        $result = $compactor->filterSkills($skills);
        // 11 skills at 12000 = 132000 > 100000, LRU removed → 10 at 120000 > 100000, LRU removed → 9 at 108000 > 100000, LRU removed → 8 at 96000 < 100000
        $this->assertCount(8, $result);
        $names = array_column($result, 'name');
        $this->assertNotContains('skill_lru', $names);
        $this->assertContains('skill_mru', $names);
    }

    public function testFilterSkillsUnchangedWhenUnderBudget(): void
    {
        // 2 skills each under 20000 chars, combined under 100000 → both unchanged
        $compactor = new ContextCompactor($this->cfg());
        $skills = [
            ['name' => 'skill_one', 'content' => str_repeat('x', 15000), 'lastInvokedAt' => 1000],
            ['name' => 'skill_two', 'content' => str_repeat('y', 15000), 'lastInvokedAt' => 2000],
        ];
        $result = $compactor->filterSkills($skills);
        $this->assertCount(2, $result);
        $this->assertSame('skill_one', $result[0]['name']);
        $this->assertSame('skill_two', $result[1]['name']);
        $this->assertStringEndsNotWith('...', $result[0]['content']);
        $this->assertStringEndsNotWith('...', $result[1]['content']);
    }

    // ─── removeToolResults() ────────────────────────────────────

    public function testRemoveToolResultsFiltersSystemToolMessages(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $messages = [
            $this->msg('user', 'Show me the file'),
            ['role' => 'system', 'content' => 'tool output', 'tool_results' => ['foo' => 'bar']],
            $this->msg('assistant', 'Here is the content'),
        ];
        $result = $compactor->removeToolResults($messages);
        $this->assertCount(2, $result);
        $this->assertSame('Show me the file', $result[0]['content']);
        $this->assertSame('Here is the content', $result[1]['content']);
    }

    public function testStage1RemovesToolResults(): void
    {
        // Stage 1 (stage 0 in code) removes tool result messages to free context space.
        // Successful Read tool results are voluminous intermediate outputs that are
        // summarized by stage 2 anyway, so they are safe to remove early.
        $compactor = new ContextCompactor($this->cfg());
        $messages = [
            $this->msg('user', 'Read the configuration file'),
            ['role' => 'system', 'content' => 'File contents', 'tool_results' => [
                'name' => 'Read',
                'output' => "<?php\ndeclare(strict_types=1);\nnamespace App;\nclass Config {\n  public string \$host = 'localhost';\n}\n",
            ]],
            $this->msg('assistant', 'Here is the configuration: localhost'),
            $this->msg('user', 'Show me the database file'),
            ['role' => 'system', 'content' => 'DB file contents', 'tool_results' => [
                'name' => 'Read',
                'output' => "<?php\nclass Database {\n  private string \$dsn = 'mysql:host=127.0.0.1';\n}\n",
            ]],
            $this->msg('assistant', 'The database file shows MySQL connection'),
        ];
        $result = $compactor->removeToolResults($messages);
        // Both tool_result messages should be removed, leaving only user/assistant pairs
        $this->assertCount(4, $result);
        $this->assertSame('Read the configuration file', $result[0]['content']);
        $this->assertSame('Here is the configuration: localhost', $result[1]['content']);
        $this->assertSame('Show me the database file', $result[2]['content']);
        $this->assertSame('The database file shows MySQL connection', $result[3]['content']);
    }

    public function testRemoveToolResultsPreservesNonSystemMessages(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $messages = [
            ['role' => 'system', 'content' => 'regular system message'],
            $this->msg('user', 'Hello'),
            $this->msg('assistant', 'Hi there'),
        ];
        $result = $compactor->removeToolResults($messages);
        // System message without tool_results is preserved
        $this->assertCount(3, $result);
    }

    public function testRemoveToolResultsReturnsEmptyWhenEmpty(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $result = $compactor->removeToolResults([]);
        $this->assertSame([], $result);
    }

    public function testRemoveToolResultsStripsSystemToolResults(): void
    {
        // A message with role=system and tool_results content should be stripped,
        // while non-tool messages are preserved
        $compactor = new ContextCompactor($this->cfg());
        $messages = [
            ['role' => 'system', 'content' => 'file output', 'tool_results' => ['name' => 'Read', 'output' => '<?php\nclass Foo {}\n']],
            $this->msg('user', 'Show me the file'),
            $this->msg('assistant', 'Here is the content'),
        ];
        $result = $compactor->removeToolResults($messages);
        $this->assertCount(2, $result);
        $this->assertSame('Show me the file', $result[0]['content']);
        $this->assertSame('Here is the content', $result[1]['content']);
    }

    // ─── compactSkills() ────────────────────────────────────────

    public function testCompactSkillsPreservesNonSkillMessages(): void
    {
        // compactSkills should pass non-skill messages through unchanged
        $compactor = new ContextCompactor($this->cfg());
        $messages = [
            $this->msg('user', 'Show me the file'),
            $this->msg('assistant', '<?php\ndeclare(strict_types=1);\nclass Foo {}\n'),
        ];
        $result = $compactor->compactSkills($messages);
        // Non-skill messages should pass through unchanged
        $this->assertCount(2, $result);
        $this->assertSame('Show me the file', $result[0]['content']);
        $this->assertSame('<?php\ndeclare(strict_types=1);\nclass Foo {}\n', $result[1]['content']);
    }

    public function testCompactSkillsEvictsLruSkillWhenOverCombinedBudget(): void
    {
        // skillBudgetPerSkill = 5000 tokens = 20000 chars per skill after truncation
        // skillBudgetCombined = 8000 tokens = 32000 chars combined budget
        // 4 skills × 20000 chars = 80000 chars > 32000 budget
        // Loop removes LRU until combined fits budget and count > 1
        // After removing 2 LRU skills: 2 × 20000 = 40000 chars > 32000 → remove another
        // After removing 3 LRU skills: 1 × 20000 = 20000 chars ≤ 32000 → exit
        // Result: most recent skill (skill_mru) survives
        $cfg = new CompactorConfig(
            skillBudgetPerSkill: 5000,
            skillBudgetCombined: 8000, // 8000 tokens = 32000 chars budget
        );
        $compactor = new ContextCompactor($cfg);

        // Each skill content > 20000 chars, so truncated to 20000 chars each
        // lastInvokedAt: skill_lru=1000 (oldest), skill_middle2=2000, skill_middle1=3000, skill_mru=4000 (newest)
        $messages = [
            ['role' => 'skill', 'name' => 'skill_lru',     'content' => str_repeat('A', 25000), 'lastInvokedAt' => 1000],
            ['role' => 'skill', 'name' => 'skill_middle2', 'content' => str_repeat('B', 25000), 'lastInvokedAt' => 2000],
            ['role' => 'skill', 'name' => 'skill_middle1', 'content' => str_repeat('C', 25000), 'lastInvokedAt' => 3000],
            ['role' => 'skill', 'name' => 'skill_mru',     'content' => str_repeat('D', 25000), 'lastInvokedAt' => 4000],
            $this->msg('user', 'regular message'),
        ];

        $result = $compactor->compactSkills($messages);

        // Only the most recent skill (skill_mru) should survive
        $skillNames = array_column(array_filter($result, fn($m) => ($m['role'] ?? '') === 'skill'), 'name');
        $this->assertCount(1, $skillNames);
        $this->assertContains('skill_mru', $skillNames);
    }

    public function testStage5PreservesDecisions(): void
    {
        // Stage 5 removes navigation steps but preserves final destinations/results
        // Test that decision messages (non-nav) are preserved through compact()
        $compactor = new ContextCompactor($this->cfg(recentPreserveCount: 1));
        $messages = [
            $this->msg('user', 'first question'),
            $this->msg('assistant', 'first answer'),
            $this->msg('user', 'What architecture should we use?'),
            $this->msg('assistant', 'We will use MVC architecture with a service layer.'),
        ];
        $result = $compactor->compact($messages);
        // Last pair is preserved, first pair summarized but architectural decision in last pair kept
        $this->assertGreaterThan(0, count($result));
        // Check that the preserved messages contain the architectural decision
        $lastMessages = array_slice($result, -2);
        $foundDecision = false;
        foreach ($lastMessages as $msg) {
            if (strpos($msg['content'], 'MVC architecture') !== false) {
                $foundDecision = true;
            }
        }
        $this->assertTrue($foundDecision, 'Architectural decision should be preserved in recent messages');
    }

    public function testCompactionReducesTokens(): void
    {
        $compactor = new ContextCompactor($this->cfg(recentPreserveCount: 2));
        // Create messages where many older pairs will be summarized
        $messages = [];
        for ($i = 0; $i < 20; $i++) {
            $messages[] = $this->msg('user', str_repeat("question number {$i} with some extra content ", 5));
            $messages[] = $this->msg('assistant', str_repeat("answer number {$i} with additional detail ", 5));
        }

        $originalTokens = $this->countTokens($messages);
        $result = $compactor->compact($messages);
        $compactedTokens = $this->countTokens($result);

        $this->assertLessThan($originalTokens, $compactedTokens, 'Compacted messages should have fewer tokens');
    }

    /**
     * Helper to count tokens using same approximation as ContextCompactor.
     */
    private function countTokens(array $messages): int
    {
        $total = 0;
        foreach ($messages as $msg) {
            $content = is_array($msg) ? ($msg['content'] ?? '') : (string) $msg;
            $total += (int) ceil(mb_strlen($content) / 4);
            $total += 10; // role overhead
        }
        return $total;
    }

}

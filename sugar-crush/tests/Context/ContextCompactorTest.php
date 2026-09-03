<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\ReportsContextWindow;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Context\ContextCompactor;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;

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

    // ─── nothing handed to a compaction is silently dropped ──────

    /**
     * A history whose Nth exchange also carries a standalone message, plus enough
     * other exchanges that the compaction really runs.
     *
     * @return list<array{role:string,content:string}>
     */
    private function historyWithMarkerAt(string $position, string $marker, int $turns = 12): array
    {
        $messages = [];
        for ($i = 0; $i < $turns; $i++) {
            if ($i === 0 && $position === 'before-user') {
                $messages[] = $this->msg('system', $marker);
            }
            $messages[] = $this->msg('user', "question {$i} " . str_repeat('q', 200));
            if ($i === 0 && $position === 'after-user') {
                $messages[] = $this->msg('system', $marker);
            }
            $messages[] = $this->msg('assistant', "answer {$i} " . str_repeat('a', 400));
            if ($i === 0 && $position === 'after-assistant') {
                $messages[] = $this->msg('system', $marker);
            }
        }

        return $messages;
    }

    /**
     * A standalone (non-user, non-assistant) message survives a compaction from
     * EVERY position it can occupy — and the middle one is the case that did not.
     *
     * `after-user` is a message directly following a user turn whose reply has not
     * arrived yet, and {@see ContextCompactor::groupIntoPairs()} used to drop it
     * outright: the standalone was pushed only when no pair was open, and a user
     * turn leaves one open. The other two positions always worked, and they are
     * here so a fix that traded one position for another cannot pass.
     *
     * Asserted on the CONTENT surviving rather than on the pair count, because the
     * content is the property — a compaction may re-shape and truncate, it may not
     * erase.
     */
    public function testAStandaloneMessageSurvivesCompactionFromEveryPosition(): void
    {
        foreach (['before-user', 'after-user', 'after-assistant'] as $position) {
            $compactor = new ContextCompactor($this->cfg(recentPreserveCount: 2));
            $result = $compactor->compact($this->historyWithMarkerAt($position, 'MARKER-KEEP-ME'));

            $text = implode("\n", array_column($result, 'content'));
            $this->assertStringContainsString(
                'MARKER-KEEP-ME',
                $text,
                "a standalone message {$position} must not be erased by a compaction",
            );
        }
    }

    /**
     * The same message survives when it lands in the PRESERVED tail rather than in
     * the summarized block, which is a different code path — {@see
     * ContextCompactor::flattenPairs()} rather than the summarizer — and the one
     * that has to hand the message back verbatim rather than truncated.
     */
    public function testAStandaloneMessageAfterAUserTurnSurvivesVerbatimInThePreservedTail(): void
    {
        $compactor = new ContextCompactor($this->cfg(recentPreserveCount: 10));
        $messages = [];
        for ($i = 0; $i < 12; $i++) {
            $messages[] = $this->msg('user', "question {$i} " . str_repeat('q', 200));
            $messages[] = $this->msg('assistant', "answer {$i} " . str_repeat('a', 400));
        }
        // The newest turn, i.e. inside the preserved tail.
        $messages[] = $this->msg('user', 'the last thing asked');
        $messages[] = $this->msg('system', 'MARKER-KEEP-ME verbatim');

        $result = $compactor->compact($messages);

        $this->assertContains(
            ['role' => 'system', 'content' => 'MARKER-KEEP-ME verbatim'],
            $result,
            'a preserved standalone is handed back untouched, not summarized',
        );
        $this->assertSame(
            ['user', 'system'],
            array_column(array_slice($result, -2), 'role'),
            'and in its original position, after the user turn it followed',
        );
    }

    /**
     * `_Request cancelled._` is the ONLY record that a turn was aborted, and it
     * lands in exactly the position the grouping used to drop: directly after the
     * user prompt whose reply never came.
     *
     * Erasing it did not just lose a line of scrollback. The compacted history is
     * fed straight back to the model, so what the provider saw was a user prompt
     * with no answer and no explanation — an unanswered turn. Reachable with no
     * provider, no tier and no summary backend involved: cancel a turn, keep
     * working, wait for a compaction.
     */
    public function testTheRequestCancelledMarkerSurvivesACompaction(): void
    {
        $compactor = new ContextCompactor($this->cfg(recentPreserveCount: 2));
        $messages = [];
        $messages[] = $this->msg('user', 'do the risky thing');
        $messages[] = $this->msg('system', '_Request cancelled._');
        for ($i = 0; $i < 12; $i++) {
            $messages[] = $this->msg('user', "question {$i} " . str_repeat('q', 200));
            $messages[] = $this->msg('assistant', "answer {$i} " . str_repeat('a', 400));
        }

        $result = $compactor->compact($messages);
        $text = implode("\n", array_column($result, 'content'));

        $this->assertStringContainsString('_Request cancelled._', $text);
        $this->assertStringContainsString(
            'do the risky thing',
            $text,
            'fixture: the prompt it belongs to is condensed, not dropped, so the pair is the real shape',
        );
    }

    /**
     * Two consecutive assistant turns both survive. The pair grouping used to
     * OVERWRITE the first with the second, and that shape is produced by the app
     * itself: every notice appended as `Message::assistant()` after a history that
     * already ends in an assistant reply — the `/compact` landing report, the
     * spend-cap refusal, the 95% blocking refusal.
     */
    public function testTwoConsecutiveAssistantTurnsBothSurviveACompaction(): void
    {
        $compactor = new ContextCompactor($this->cfg(recentPreserveCount: 2));
        $messages = [];
        for ($i = 0; $i < 5; $i++) {
            $messages[] = $this->msg('user', "question {$i}");
            $messages[] = $this->msg('assistant', "REPLY-{$i}");
        }
        $messages[] = $this->msg('assistant', 'REPORT-APPENDED-AFTERWARDS');

        $result = $compactor->compact($messages);
        $text = implode("\n", array_column($result, 'content'));

        $this->assertStringContainsString('REPLY-4', $text, 'the real reply must not be overwritten by the notice');
        $this->assertStringContainsString('REPORT-APPENDED-AFTERWARDS', $text);
    }

    /**
     * The fix keeps the PAIR COUNT intact, and the pair count is what decides how
     * much a compaction can do: {@see ContextCompactor::stagePairs()} preserves the
     * last `recentPreserveCount` PAIRS, and
     * {@see ContextCompactor::exchangesToSummarize()} offers a model only pairs
     * holding both halves.
     *
     * Pinned on a history with a reminder after every prompt: the WORST case, not
     * the typical one. It is the state of every session written before
     * {@see \SugarCraft\Crush\Chat::withoutContextReminders()} deduplicated
     * the reminder — the 70% tier fires first, its predicate is stateless, and
     * every answer was appended — so it is what pre-dedup sessions and the
     * checkpoints they wrote still hold. A deduped history carries at most one.
     *
     * The obvious alternative fix — close the open pair and push the standalone
     * as its own entry — preserves the message and takes this number from 10 to
     * 0, i.e. silently disables model-written summaries on that history. On a
     * deduped one it takes it from 10 to 12 instead, which is the same harm with
     * the opposite sign: the extra entries slide the
     * last-`recentPreserveCount`-ENTRIES window off two pairs that should have
     * been preserved verbatim. See
     * {@see ContextCompactor::groupIntoPairs()}'s docblock for the measured
     * table and for the two victims that are never deduplicated at all.
     */
    public function testAReminderAfterEveryPromptDoesNotDestroyTheOfferedExchangeSet(): void
    {
        $compactor = new ContextCompactor($this->cfg(recentPreserveCount: 10));
        $messages = [];
        for ($i = 0; $i < 20; $i++) {
            $messages[] = $this->msg('user', "question {$i} " . str_repeat('q', 200));
            $messages[] = $this->msg('system', 'Heads up: this conversation has grown to ~1234 estimated tokens.');
            $messages[] = $this->msg('assistant', "answer {$i} " . str_repeat('a', 400));
        }

        $this->assertCount(
            10,
            $compactor->exchangesToSummarize($messages),
            '20 pairs less the 10 preserved: a reminder inside an exchange must not split it',
        );

        $text = implode("\n", array_column($compactor->compact($messages), 'content'));
        $this->assertStringContainsString('Heads up: this conversation has grown', $text);
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

    // ─── truncateOversizedExchange() — intra-exchange E18 ─────────
    //
    // Backlog §12.2 E18: one exchange LARGER THAN THE TIER is a permanent
    // refusal, because every other tier on this class frees space BETWEEN whole
    // exchanges and cannot shrink one that is itself oversized. These tests pin
    // the intra-exchange rescue and, just as loudly, the boundary that keeps it
    // OUT of the between-exchanges case the tests above already pin.

    /**
     * The E18 fixture: a single 800,000-char exchange is 200,010 estimated
     * tokens, over the 95,000 blocking tier of a 100,000-token window all by
     * itself, so whole-exchange compaction can never free enough.
     *
     * The two properties are asserted together because either alone is
     * satisfiable by a broken fix. Under the tier is the point of truncating at
     * all; still large is what proves the tier was cleared by REMOVING TEXT
     * rather than by under-counting it. A result near zero would clear the tier
     * just as well and would be the exact lie §12.2 forbids.
     */
    public function testASingleExchangeLargerThanTheTierIsTruncatedUnderIt(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $messages = [
            $this->msg('user', str_repeat('x', 800_000)),
            $this->msg('assistant', str_repeat('y', 2_000)),
        ];
        $this->assertSame(200_520, $this->countTokens($messages), 'fixture: 200,520 is 111% of the 95,000 tier');

        $truncated = $compactor->truncateOversizedExchange($messages, 100_000);

        $tokens = $this->countTokens($truncated);
        $this->assertLessThan(95_000, $tokens, 'the truncated history must clear the blocking tier, or the turn is still refused');
        $this->assertGreaterThan(90_000, $tokens, 'and it must still weigh most of the window - it was shortened, not emptied');
        $this->assertSame(92_977, $tokens, 'the exact figure this truncation produces');

        // The oversized message really was rewritten...
        $this->assertSame(369_825, mb_strlen($truncated[0]['content']), '800,000 chars became this');
        // ...and the message that was never oversized was not touched at all.
        $this->assertSame($messages[1], $truncated[1], 'a message under the tier must survive byte-for-byte');
        $this->assertSame('assistant', $truncated[1]['role']);
    }

    /**
     * The marker is an ACCOUNTING claim, so it is checked as arithmetic rather
     * than as prose: head kept plus characters reported dropped must equal the
     * original length exactly. An off-by-anywhere marker would let the
     * "estimate fell because the bytes fell" argument pass while the bytes had
     * not in fact fallen by that much.
     */
    public function testTheTruncationMarkerNamesTheExactNumberOfCharactersDropped(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $content = str_repeat('x', 800_000);
        $messages = [$this->msg('user', $content)];

        $truncated = $compactor->truncateOversizedExchange($messages, 100_000);
        $newContent = $truncated[0]['content'];

        $this->assertSame(1, preg_match('/\n\n\[\.\.\. (\d+) characters truncated to fit the context window \.\.\.\]$/', $newContent, $m), 'exactly one marker, at the very end');
        $dropped = (int) $m[1];
        // Everything before the marker's two-newline separator is the kept head.
        $head = mb_substr($newContent, 0, mb_strlen($newContent) - (mb_strlen($m[0])));

        $this->assertSame(800_000, mb_strlen($head) + $dropped, 'kept + reported-dropped must equal the original exactly');
        $this->assertSame(str_repeat('x', mb_strlen($head)), $head, 'and what it claims to have kept really is the unmodified head');
        $this->assertGreaterThan(0, $dropped, 'the marker must never report a zero drop next to a real truncation');
    }

    /**
     * The other polarity, and the one that keeps this step inside its lane: a
     * history over the tier only IN AGGREGATE - 13 exchanges of 50,003 chars,
     * 325,286 estimated tokens, largest single message 12,511 - must be returned
     * UNCHANGED. That is the between-exchanges case, which Chat refuses and
     * {@see self::testCompactSummarizesOlderMessages()} and ChatTest already pin.
     *
     * Asserted with `assertSame` on the whole array, not a length or a
     * "nothing got bigger" check: a truncation pass that quietly shortened one
     * of these would still satisfy any bound assertion while rewriting exchanges
     * the inter-exchange tiers chose to preserve.
     */
    public function testAHistoryOversizedOnlyInAggregateIsReturnedUnchanged(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $messages = [];
        for ($i = 0; $i < 13; $i++) {
            $messages[] = $this->msg('user', "u{$i} " . str_repeat('x', 50_000));
            $messages[] = $this->msg('assistant', "a{$i} " . str_repeat('y', 50_000));
        }
        $this->assertSame(325_286, $this->countTokens($messages), 'fixture: far over the tier in total');
        $this->assertSame(12_511, max(array_map(
            fn(array $m): int => $this->countTokens([$m]),
            $messages,
        )), 'fixture: and no single message reaches it');

        $this->assertSame(
            $messages,
            $compactor->truncateOversizedExchange($messages, 100_000),
            'not one byte of an aggregate-only overflow may be rewritten',
        );
    }

    /**
     * The threshold comparison is `>=`, so a message worth EXACTLY the blocking
     * tier is oversized and one character-shorter of a budget is not. Pinning
     * both sides of `>=` is what stops a later `<` flip from silently turning
     * the rescue off at the boundary.
     *
     * 379,960 chars is exactly 95,000 estimated tokens; 379,956 is 94,999.
     */
    public function testTheBlockingThresholdBoundaryIsInclusiveOnOneSideAndNotTheOther(): void
    {
        $compactor = new ContextCompactor($this->cfg());

        $at = [$this->msg('user', str_repeat('x', 379_960))];
        $this->assertSame(95_000, $this->countTokens($at), 'fixture: exactly at the 95,000 tier');
        $this->assertNotSame($at, $compactor->truncateOversizedExchange($at, 100_000), 'at the tier is oversized');

        $under = [$this->msg('user', str_repeat('x', 379_956))];
        $this->assertSame(94_999, $this->countTokens($under), 'fixture: one token below it');
        $this->assertSame($under, $compactor->truncateOversizedExchange($under, 100_000), 'below it is not - and is not rewritten');
    }

    /**
     * Two oversized exchanges split the budget equally rather than the first
     * taking everything and the second staying oversized - which would leave the
     * history over the tier and the turn refused anyway.
     */
    public function testEveryOversizedExchangeSharesTheRemainingBudget(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $messages = [
            $this->msg('user', str_repeat('x', 800_000)),
            $this->msg('assistant', str_repeat('z', 600_000)),
        ];
        $this->assertSame(350_020, $this->countTokens($messages));

        $truncated = $compactor->truncateOversizedExchange($messages, 100_000);

        $this->assertSame(92_954, $this->countTokens($truncated), 'the exact two-giant figure');
        $this->assertLessThan(95_000, $this->countTokens($truncated));
        $this->assertSame(
            mb_strlen($truncated[0]['content']),
            mb_strlen($truncated[1]['content']),
            'an equal share of the space left under the tier',
        );
        foreach ($truncated as $i => $message) {
            $this->assertLessThan(
                95_000,
                $this->countTokens([$message]),
                "neither message may still be over the tier on its own (message {$i})",
            );
        }
    }

    /**
     * Determinism, because the truncated text reaches a prompt and goldens pin
     * bytes. Two calls on the same input must be byte-identical; a clock, a
     * random salt or an mb_str_split difference would show up here first.
     */
    public function testTheTruncationIsByteIdenticalAcrossRepeatedCalls(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $messages = [
            $this->msg('user', str_repeat('x', 800_000)),
            $this->msg('assistant', str_repeat('y', 2_000)),
        ];

        $first = $compactor->truncateOversizedExchange($messages, 100_000);
        $second = $compactor->truncateOversizedExchange($messages, 100_000);

        $this->assertSame($first, $second, 'the same oversized exchange must truncate to the same bytes every time');
        $this->assertSame($first, $compactor->truncateOversizedExchange($first, 100_000), 'and re-truncating an already-truncated history must be a no-op');
    }

    /**
     * Truncating multibyte text must not cut a codepoint in half: a split UTF-8
     * sequence reaches the provider as invalid bytes and the whole request can
     * be rejected for it. 'x' repeated cannot demonstrate this at all - only a
     * multibyte payload can.
     */
    public function testTruncatingMultibyteContentLeavesValidUtf8(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $messages = [$this->msg('user', str_repeat('é', 800_000))];

        $truncated = $compactor->truncateOversizedExchange($messages, 100_000);

        $this->assertTrue(
            mb_check_encoding($truncated[0]['content'], 'UTF-8'),
            'a head cut mid-codepoint would produce invalid UTF-8 on the wire',
        );
        $this->assertLessThan(95_000, $this->countTokens($truncated));
        $this->assertStringEndsWith(
            'characters truncated to fit the context window ...]',
            $truncated[0]['content'],
            'and the marker must still close the string',
        );
    }

    /**
     * {@see \SugarCraft\Crush\Message::toWire()} carries attachments and tool
     * calls beside the content. Truncation rewrites `content` only - dropping a
     * sibling key would silently delete a tool call the exchange still needs,
     * and content size has nothing to do with either.
     */
    public function testTruncationLeavesTheNonContentWireKeysAlone(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $messages = [[
            'role' => 'assistant',
            'content' => str_repeat('x', 800_000),
            'attachments' => [['type' => 'IMAGE', 'path' => '/tmp/a.png']],
            'tool_calls' => [['id' => 'tc1', 'name' => 'bash', 'arguments' => ['command' => 'ls']]],
        ]];

        $truncated = $compactor->truncateOversizedExchange($messages, 100_000);

        $this->assertSame($messages[0]['attachments'], $truncated[0]['attachments'], 'attachments ride through');
        $this->assertSame($messages[0]['tool_calls'], $truncated[0]['tool_calls'], 'and so do tool calls');
        $this->assertSame('assistant', $truncated[0]['role'], 'and the role');
        $this->assertNotSame($messages[0]['content'], $truncated[0]['content'], 'while the content does change');
    }

    /**
     * The guards on the three sibling tier predicates, re-pinned here: a
     * non-positive window disables every tier, and a history already under the
     * tier must be returned untouched rather than shortened "while we're here".
     */
    public function testANonPositiveWindowAndAnUnderTierHistoryAreBothReturnedUntouched(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $huge = [$this->msg('user', str_repeat('x', 800_000))];

        $this->assertSame($huge, $compactor->truncateOversizedExchange($huge, 0), 'a zero window disables the tier, as it does the other three');
        $this->assertSame($huge, $compactor->truncateOversizedExchange($huge, -100), 'and so does a negative one');

        $small = [$this->msg('user', 'hello')];
        $this->assertSame($small, $compactor->truncateOversizedExchange($small, 100_000), 'under the tier: nothing to rescue, nothing rewritten');
        $this->assertSame([], $compactor->truncateOversizedExchange([], 100_000), 'an empty history stays empty');
    }

    /**
     * The truncation is bounded, not omnipotent. One oversized exchange plus ten
     * exchanges of 379,956 chars (94,999 estimated tokens each, every one just
     * under the 95,000 threshold so none is individually oversized) is 1,150,000
     * estimated tokens: truncating the giant to nothing still leaves 950,000, so
     * the rescue CANNOT clear the tier here.
     *
     * This is the pathological shape, and the answer must be "no change is
     * offered", not "half a rescue sent". Chat's blocking sites both re-check
     * {@see ContextCompactor::shouldCompactForeground()} on the truncated wire
     * and fall back to the ordinary refusal when it is still over — without that
     * re-check a turn the provider is entitled to reject would be sent on the
     * strength of a truncation that truncated nowhere near enough.
     */
    public function testTruncationDoesNotClaimToRescueAnOverflowItCannotClear(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $messages = [];
        for ($i = 0; $i < 10; $i++) {
            $messages[] = $this->msg('user', str_repeat('p', 379_956));
        }
        $messages[] = $this->msg('user', str_repeat('x', 800_000));

        $this->assertSame(1_150_000, $this->countTokens($messages), 'fixture: far over the tier');
        $this->assertSame(
            94_999,
            $this->countTokens([$messages[0]]),
            'fixture: each preserved exchange is one token SHORT of being individually oversized',
        );

        $truncated = $compactor->truncateOversizedExchange($messages, 100_000);

        // The oversized message is still truncated (the method reports what it
        // did honestly) — it is the CALLER's re-check that must refuse to send.
        $this->assertNotSame($messages, $truncated, 'the one oversized message is still shortened');
        $this->assertSame(
            950_000,
            $this->countTokens($truncated),
            'and the history is still enormously over the tier afterwards',
        );
        $this->assertTrue(
            $compactor->shouldCompactForeground($truncated, 100_000),
            'so the blocking tier must still fire on the truncated wire — this is what Chat re-tests',
        );
        $this->assertSame(
            $messages[0],
            $truncated[0],
            'and the ten not-individually-oversized exchanges were still left alone',
        );
    }

    /**
     * The guard's own deletion experiment lives here: with only a giant and
     * nothing else, the same call DOES clear the tier. Without this second half
     * the assertion above could be satisfied by a truncator that simply never
     * worked, and the "cannot clear" case would prove nothing.
     */
    public function testTheSameTruncatorDoesClearTheTierWhenTheOverflowIsGenuinelyIntraExchange(): void
    {
        $compactor = new ContextCompactor($this->cfg());
        $messages = [
            $this->msg('user', str_repeat('x', 800_000)),
            $this->msg('assistant', str_repeat('y', 2_000)),
        ];

        $truncated = $compactor->truncateOversizedExchange($messages, 100_000);

        $this->assertFalse(
            $compactor->shouldCompactForeground($truncated, 100_000),
            'a genuinely intra-exchange overflow IS cleared, so the failure above is about the aggregate, not about a truncator that does nothing',
        );
    }

    // ─── E18 through the real Chat tier path ──────────────────────
    //
    // The assertions above prove the truncation in isolation; these drive the
    // exact reproduction the step names — one oversized exchange, repeated
    // attempts — through Chat::submit()'s blocking tier and through the parked
    // landing in applyModelCompaction(), because the runaway was a property of
    // the refusal LOOP, not of the truncator, and only the loop can show it is
    // gone. Both routes are covered: a real session with a summary backend takes
    // the parked one, and fixing only the synchronous tier would have left E18
    // live in production.

    /**
     * The reproduction, before the fix, measured on this branch by reverting
     * `src/` to its base state and driving five attempts
     * (`/home/sites/prompt-scratch/P4.S4/lead/BEFORE_sync.txt`):
     *
     *     estimate sequence: [200520, 200648, 200776, 200904, 201032]
     *     refusal sequence:  [R, R, R, R, R]      backend calls: 0
     *
     * Strictly rising, and every attempt refused: that is §12.2 E18.
     *
     * After the fix the same drive must (a) dispatch every attempt, and (b) never
     * read an estimate ABOVE the first attempt's. (b) is the literal non-rising
     * claim, and it is the shape that discriminates: the bug's maximum estimate is
     * its LAST reading, the fix's is its FIRST, because the oversized exchange is
     * truncated once and then stays truncated. It deliberately does NOT claim the
     * estimate is flat forever — a turn that proceeds appends a real user line and
     * a real reply, and honest conversation growth of ~23 tokens a turn is not the
     * defect and must not be suppressed to make an assertion read prettily.
     *
     * The third claim is the one that makes (b) unfakeable: what the provider
     * ACTUALLY RECEIVED is asserted to be under the tier and to carry the
     * truncated bytes. An undercounting "fix" — report fewer tokens, send the same
     * 800,000 characters — satisfies (a) and (b) and dies here.
     */
    public function testAnOversizedExchangeStopsBeingRefusedAndTheWireReallyGetsShorter(): void
    {
        $backend = new IntraExchangeTurnBackend(100_000);
        $chat = new Chat(
            history: [
                Message::user(str_repeat('x', 800_000)),
                Message::assistant(str_repeat('y', 2_000)),
            ],
            backend: $backend,
        );

        $estimates = [];
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $estimates[] = $chat->contextTokens();
            $chat = $this->withDraft($chat, "retry{$attempt}");

            [$chat, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

            $this->assertNotNull(
                $cmd,
                "attempt {$attempt} was refused: an exchange that cannot fit must be truncated, not re-refused (E18)",
            );
            $this->assertStringNotContainsString(
                'This turn was NOT sent',
                $chat->history[count($chat->history) - 1]->content,
                "and attempt {$attempt} must not have written a blocking-tier refusal",
            );

            $chat = $this->settle($chat, $cmd);

            // Positive controls against a VACUOUS harness: settle() feeds back
            // whatever resolve() got, and resolve() returns null for a Cmd that
            // is not an AsyncCmd — a drive loop whose turns never actually
            // settled would still pass every estimate assert below. So each
            // attempt must PROVE it dispatched AND that its reply landed.
            $this->assertSame(
                $attempt,
                $backend->calls(),
                "positive control: attempt {$attempt} actually reached the backend — a skipped settle could not",
            );
            $this->assertSame(
                'ok',
                $chat->history[count($chat->history) - 1]->content,
                "positive control: attempt {$attempt}'s reply was applied to history, so the turn SETTLED and the next estimate reads a finished exchange, not a half-run one",
            );
        }

        // The per-attempt figures are PINNED, not merely bounded: the +23/turn
        // growth of attempts 2-5 is one user line + one two-character reply per
        // settled turn (honest conversation growth, disclosed in the docblock),
        // and only pinning the exact sequence keeps "bounded above by the first
        // reading" from quietly absorbing a regression that adds less per turn.
        $this->assertSame(
            [200_520, 93_126, 93_149, 93_172, 93_195],
            $estimates,
            'the whole measured sequence: attempt 1 reads the untruncated giant, every later attempt the once-truncated exchange plus one settled turn more',
        );

        $this->assertSame(200_520, $estimates[0], 'fixture: the first attempt reads the untruncated giant, the same figure the bug started from');
        $this->assertSame(
            $estimates[0],
            max($estimates),
            'the estimate must never rise above its first reading — the bug peaked on its LAST attempt, 201,032',
        );
        $this->assertLessThan($estimates[0], $estimates[4], 'and the last attempt must read strictly lower: the exchange was truncated once and stays truncated');
        $this->assertSame(5, $backend->calls(), 'every one of the five attempts reached the provider');

        // The unfakeable half: the estimate is of the bytes actually handed over.
        $dispatched = $backend->historyAt(0);
        $this->assertNotNull($dispatched, 'fixture: the backend must have recorded the first dispatch');
        $longest = max(array_map(static fn(Message $m): int => mb_strlen($m->content), $dispatched));
        $this->assertSame(
            369_825,
            $longest,
            'the oversized exchange reached the provider at its TRUNCATED length - fewer tokens counted because fewer characters sent',
        );
        $this->assertLessThan(95_000, $this->countTokens(array_map(
            static fn(Message $m): array => $m->toWire(),
            $dispatched,
        )), 'the wire the provider was handed is itself under the blocking tier');
    }

    /**
     * The rescue must be silent and history must be untouched when nothing is
     * individually oversized. This is the same history
     * ChatTest::testSubmitRefusesTheTurnAtTheBlockingTierWhenActiveAndPastTheWindow
     * asserts is REFUSED — 13 exchanges of 50,003 chars, over the tier only in
     * aggregate — driven through the tier Chat itself enforces.
     *
     * Without this the truncation would be un-bounded in the other direction: a
     * rescue that also shortened ordinary exchanges would quietly delete the
     * between-exchanges refusal (and this user's history) while keeping every
     * E18 assertion above green.
     */
    public function testAnAggregateOverflowIsStillRefusedAndNotRewrittenByTheRescue(): void
    {
        $history = [];
        for ($i = 0; $i < 13; $i++) {
            $history[] = Message::user("u{$i} " . str_repeat('x', 50_000));
            $history[] = Message::assistant("a{$i} " . str_repeat('y', 50_000));
        }

        $backend = new IntraExchangeTurnBackend(100_000);
        $chat = new Chat(history: $history, backend: $backend);
        $chat = $this->withDraft($chat, 'hello');

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd, 'an aggregate overflow is still the between-exchanges refusal');
        $this->assertSame(0, $backend->calls(), 'and nothing is sent to the provider');
        $this->assertStringContainsString(
            'This turn was NOT sent',
            $next->history[count($next->history) - 1]->content,
            'with the refusal message intact',
        );

        // Whole-exchange compaction condensed the three OLDEST pairs — six
        // messages — into summaries; the twenty messages after them are the
        // preserved ten exchanges, and they must still be the very same Message
        // objects handed in. That is `messagesFromWire()`'s tail match doing its
        // job, and it is the rescue proven absent where it has nothing to do: a
        // truncator that also shortened ordinary exchanges would satisfy every
        // E18 assertion above while silently rewriting a history nobody asked it
        // to touch.
        $preserved = array_slice($next->history, 3, 20);
        $this->assertSame(
            array_slice($history, 6, 20),
            $preserved,
            'every exchange whole-exchange compaction chose to preserve must survive the rescue untouched',
        );
        $this->assertSame(
            50_004,
            max(array_map(static fn(Message $m): int => mb_strlen($m->content), $preserved)),
            'including their full two-digit-prefix bodies (u10..a12 are 50,004 chars)',
        );
    }

    /**
     * The shape NO test covered until review cycle 4: the SYNCHRONOUS route
     * where whole-exchange compaction freed something AND a giant still blocked
     * the 95% tier, so the rescue dispatched. The committed history then carries
     * `[summary]` lines — a rewrite under the user's feet — yet submit() held
     * one notice slot and the rescue OVERWROTE the compaction notice with the
     * truncation one, announcing only the second rewrite. The parked route never
     * had this defect: compactionChanges() writes that rewrite report into
     * history when it lands, and a parked probe on this very fixture shows both
     * notices riding. This is the sync sibling of
     * ChatTest::testTheBlockingTierReportsTheRewriteItCommitted, which enforces
     * the same doctrine on the REFUSING arm of the same tier.
     */
    public function testARescuedSyncDispatchAnnouncesBothTheRewriteAndTheTruncation(): void
    {
        $history = [];
        for ($i = 0; $i < 3; $i++) {
            $history[] = Message::user("u{$i} " . str_repeat('x', 60_000));
            $history[] = Message::assistant("a{$i} " . str_repeat('y', 60_000));
        }
        $history[] = Message::user('GIANT ' . str_repeat('z', 800_000));
        $history[] = Message::assistant('gianttail');
        for ($i = 0; $i < 8; $i++) {
            $history[] = Message::user("q{$i}");
            $history[] = Message::assistant("r{$i}");
        }

        $backend = new IntraExchangeTurnBackend(100_000);
        $chat = new Chat(history: $history, backend: $backend);
        $chat = $this->withDraft($chat, 'go');

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNotNull($cmd, 'fixture: twelve exchanges — compaction condenses the two oldest, the giant still blocks the tier');

        $summaries = array_values(array_filter(
            $next->history,
            static fn(Message $m): bool => str_starts_with($m->content, '[summary]'),
        ));
        $this->assertCount(2, $summaries, 'fixture: the dispatched history really carries the between-exchanges rewrite');

        $compactionHits = array_values(array_filter(
            $next->history,
            static fn(Message $m): bool => $m->role === Role::System
                && str_contains($m->content, 'older exchanges were summarized'),
        ));
        $this->assertCount(1, $compactionHits, 'the rescued dispatch announces the rewrite exactly once — zero times was the cycle-4 defect');
        $compaction = $compactionHits[0];
        $truncation = $this->truncationNotice($next);

        $this->assertSame(Role::System, $truncation->role, 'both notices are the app reporting on itself');

        $rewriteIndex = array_search($compaction, $next->history, true);
        $truncationIndex = array_search($truncation, $next->history, true);
        $this->assertIsInt($rewriteIndex);
        $this->assertIsInt($truncationIndex);
        $this->assertSame($rewriteIndex + 1, $truncationIndex, 'the rewrite report rides immediately before the truncation report — both in commit order');
        $this->assertSame(
            'go',
            $next->history[$truncationIndex + 1]->content,
            'both ride BEFORE the user prompt, the ordering contextCompactedMessage() established for every rewrite report',
        );

        $this->assertStringContainsString(
            '24 messages -> 22 messages',
            $compaction->content,
            'the rewrite reports its OWN counts: 24 in, the two oldest exchanges condensed to two summary lines, 22 out',
        );
        $this->assertMatchesRegularExpression(
            '/~[1-9]\d*% of the estimated token count freed/',
            $compaction->content,
            'and a strictly non-zero saving — this notice exists precisely because compaction freed something',
        );
        $this->assertStringStartsWith(
            '1 message reached the 95% blocking tier on its own',
            $truncation->content,
            'the truncation notice still counts its own unit truthfully beside it',
        );
    }

    /**
     * THE LOSING PLACEMENT, which every earlier rescue test hid: the giant as
     * the NEWEST message. Until review cycle 4 this call site rebuilt history
     * with messagesFromWire(), which preserves original objects only for a
     * matching SUFFIX — with the changed entry AT the tail, preserved was 0 and
     * every untouched earlier exchange came back as a bare
     * `Message::user`/`Message::assistant($content)`: object identity lost,
     * createdAt re-stamped to now, toolCalls, toolResults and reasoning dropped,
     * and `toWire()` no longer emitting the tool_calls that history was built
     * with — so every SUBSEQUENT turn sent a structurally different history
     * than the one already sent. The passing placements are the ones that hid
     * it: testAnOversizedExchangeStopsBeingRefusedAndTheWireReallyGetsShorter
     * and all three verbatim-notice tests put the giant FIRST, where the tail
     * match preserved everything behind it.
     */
    public function testTheRescueKeepsEveryUntouchedMessageTheSameObjectWhenTheGiantIsNewest(): void
    {
        $first = Message::user('question number 3');
        $rich = new Message(
            Role::Assistant,
            'earlier rich answer',
            1_234_567_890,
            toolCalls: [new ToolCall('bash', ['command' => 'ls'], 'tc1')],
            toolResults: [new ToolResult('bash', 'listing', null, 'tc1')],
            reasoning: 'I thought hard',
        );
        $giant = Message::user(str_repeat('x', 800_000));

        $backend = new IntraExchangeTurnBackend(100_000);
        $chat = new Chat(history: [$first, $rich, $giant], backend: $backend);
        $chat = $this->withDraft($chat, 'go');

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNotNull($cmd, 'fixture: an over-tier newest message reaches the rescue on the sync route');

        // The giant itself MUST show the truncation — otherwise "everything else
        // survived" would prove nothing about a rescue that never fired.
        $this->assertStringContainsString(
            'characters truncated to fit the context window',
            $next->history[2]->content,
            'positive control: the giant really was truncated in place',
        );
        $this->assertNotSame($giant, $next->history[2], 'the truncated entry is a copy — the only entry the splice may replace');

        // And the exchanges the truncator NEVER touched: the very same objects,
        // every field, whatever their placement.
        $this->assertSame($first, $next->history[0], 'an untouched user turn survives as the object it came in as');
        $this->assertSame($rich, $next->history[1], 'an untouched rich assistant turn survives as the object it came in as — a splice guarantee, not a suffix-match accident');
        $this->assertSame(1_234_567_890, $next->history[1]->createdAt, 'createdAt survives (the rebuild re-stamped it to now)');
        $this->assertCount(1, $next->history[1]->toolCalls, 'toolCalls survive (the rebuild dropped them)');
        $this->assertCount(1, $next->history[1]->toolResults, 'toolResults survive (the rebuild dropped them)');
        $this->assertSame('I thought hard', $next->history[1]->reasoning, 'reasoning survives (the rebuild dropped it)');
        $this->assertArrayHasKey(
            'tool_calls',
            $next->history[1]->toWire(),
            'and the wire the NEXT turn builds from this history still carries the tool_calls this history was built with',
        );
    }

    /**
     * THE STATE THE ALIGNMENT RE-DERIVATION EXISTS FOR, pinned through the real
     * path (review cycle 5, finding 1). When compaction SHORTENS the wire but its
     * savings round to zero — eleven tiny exchanges and one dominant 800,000-char
     * giant: 24 wire entries compact to 22, and condensing two ~15-char exchanges
     * into summaries is 0% of 200,520 estimated tokens — submit() does NOT adopt
     * the rewrite, so $baseHistory is still $this->history: 24 entries, off by the
     * two dropped summaries against the 22-entry $compactedWire the rescue
     * truncates and splices. The re-derivation at Chat.php:5982-5984
     * (messagesFromWire on the else arm of the ternary) is the ONLY thing that
     * re-aligns them, and until now NOTHING covered it: replacing the whole
     * ternary with `$rescueBase = $baseHistory;` left ContextCompactorTest
     * (78/254), the five-file set (282/1185) and the compact-neighbours (130/26421)
     * ALL green (measured, review cycle 5, mutant M5b). What the mutant does in
     * THIS state is silently corrupt the dispatched history: the giant's truncated
     * content lands on q10's message, the real giant and tail vanish, and the two
     * [summary] lines are replaced by the q0/a0 they were condensed from
     * (observed: dispatched [20]=truncated-giant, [21]=a10, tail absent). The
     * assertions below are exactly those casualties: summaries survive at the
     * positions the compacted wire puts them, every untouched exchange is THE
     * ORIGINAL OBJECT at its aligned index, and the TAILMARK keeps its value.
     */
    public function testTheRescueReAlignsWithTheCompactedWireWhenCompactionSavedNothing(): void
    {
        $history = [];
        for ($i = 0; $i < 11; $i++) {
            $history[] = Message::user('q' . $i);
            $history[] = Message::assistant('a' . $i);
        }
        $history[] = Message::user(str_repeat('x', 800_000));
        $history[] = Message::assistant('tail');

        $backend = new IntraExchangeTurnBackend(100_000);
        $chat = new Chat(history: $history, backend: $backend);
        $chat = $this->withDraft($chat, 'go');

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNotNull($cmd, 'fixture: the giant still blocks the tier after the round-to-zero compaction, so the rescue runs');
        $cmd();
        $this->assertStringNotContainsString(
            'older exchanges were summarized',
            implode("\n", array_map(static fn(Message $m): string => $m->content, $next->history)),
            'fixture: savings rounded to 0, so the rewrite was NOT adopted and $baseHistory stayed the 24-message original — this is the else arm of the alignment ternary, not the adopted path',
        );

        $dispatched = $backend->historyAt(0);
        $this->assertIsArray($dispatched, 'the turn actually reached the provider');

        // The compacted wire rode the dispatch: stage 2 condensed the two oldest
        // exchanges into [summary] lines, and the re-derived list puts them where
        // THE WIRE has them, not where $this->history has q0/a0 (the mutant's
        // dispatched [0]/[1] — zero summaries anywhere).
        $this->assertSame(
            '[summary] q0 → a0',
            $dispatched[0]->content,
            'dispatched entry 0 is the FIRST exchange summary from the compacted wire',
        );
        $this->assertSame(
            '[summary] q1 → a1',
            $dispatched[1]->content,
            'and entry 1 the second — the mutant lands q0 and a0 here and carries zero summaries',
        );
        $this->assertSame(2, count(array_filter(
            $dispatched,
            static fn(Message $m): bool => str_starts_with($m->content, '[summary]'),
        )), 'exactly the two summaries the 24-to-22 compaction wrote, no more, no fewer');

        // Alignment below the summaries: entry i of the 22-entry wire is entry i of
        // the re-derived list, so the preserved exchanges are THE SAME OBJECTS at
        // the shifted indices. The mutant's off-by-two splice hands dispatched[2]
        // the q1 object and dispatched[19] the a9.
        $this->assertSame($history[4], $dispatched[2], 'the first preserved exchange is the original q2 object, at the compacted wire’s index — not q1');
        $this->assertSame($history[21], $dispatched[19], 'and the last tiny exchange the original a10 object at wire index 19 — not a9');

        // The giant itself: truncated in place (role and position intact) and the
        // TAILMARK retaining its value. The mutant overwrites [21] with a10 and
        // drops the real tail and the real giant entirely.
        $this->assertStringContainsString(
            'characters truncated to fit the context window',
            $dispatched[20]->content,
            'the giant sits at wire index 20 and really was truncated there',
        );
        $this->assertSame(Role::User, $dispatched[20]->role, 'the truncated giant keeps the user role — the copy inherits it from the aligned original, here the giant itself');
        $this->assertNotSame($history[22], $dispatched[20], 'the truncated giant is a copy — the splice never mutates the original');
        $this->assertSame($history[23], $dispatched[21], 'the TAILMARK survives as the very same object at the last base position — the mutant lands a10 here');
        $this->assertSame('tail', $dispatched[21]->content, 'and keeps its value — under the mutant this message is absent from the wire entirely');

        // The turn’s own messages ride behind the aligned history, in the order
        // submit() commits them: truncation notice, then the user’s line.
        $this->assertStringStartsWith(
            '1 message reached the 95% blocking tier on its own',
            $dispatched[22]->content,
            'the rescue notice rides at index 22, immediately after the 22-entry aligned history',
        );
        $this->assertSame('go', $dispatched[23]->content, 'and the user prompt follows it — every one of these positions is index 2 lower than the un-aligned 24-message history would put it');
    }

    /**
     * The parked landing — the route a session WITH a summary backend takes,
     * which is what production actually does. Measured on this branch before
     * `applyModelCompaction()` was wired
     * (`/home/sites/prompt-scratch/P4.S4/lead/parked_probe.php`): estimate
     * 200,287 -> 200,518 -> 200,771, three refusals, the summariser called three
     * times and the conversation backend never once.
     *
     * The oversized exchange here is the NEWEST pair, so the model summarisation
     * condenses the twelve older ones and preserves this one verbatim — which is
     * exactly why the parked route needs its own rescue rather than inheriting
     * submit()'s.
     */
    public function testTheParkedLandingRescuesAnOversizedNewestExchangeToo(): void
    {
        $history = [];
        for ($i = 0; $i < 12; $i++) {
            $history[] = Message::user("q{$i}");
            $history[] = Message::assistant("r{$i}");
        }
        $history[] = Message::user('BIGGEST ' . str_repeat('x', 800_000));
        $history[] = Message::assistant('tail');

        $backend = new IntraExchangeTurnBackend(100_000);
        $summariser = new IntraExchangeSummariser();
        $chat = new Chat(
            history: $history,
            inputBuf: 'go',
            backend: $backend,
            summaryBackend: $summariser,
        );

        $firstEstimate = $chat->contextTokens();
        $this->assertGreaterThan(95_000, $firstEstimate, 'fixture: this history starts over the blocking tier');

        [$parked, $summaryCmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNotNull($summaryCmd, 'fixture: the 85% tier parks this behind a summarisation round-trip');

        [$landed, $turnCmd] = $parked->update($this->resolve($summaryCmd));

        $this->assertNotNull(
            $turnCmd,
            'the parked landing must truncate the oversized exchange, not refuse it (E18 on the parked route)',
        );
        $this->assertStringNotContainsString(
            'This turn was NOT sent',
            $landed->history[count($landed->history) - 1]->content,
        );

        $turnCmd();
        $this->assertSame(1, $backend->calls(), 'and the turn the user pressed Enter for actually reaches the provider');

        $dispatched = $backend->historyAt(0);
        $this->assertLessThan(
            95_000,
            $this->countTokens(array_map(static fn(Message $m): array => $m->toWire(), $dispatched)),
            'the parked dispatch is under the tier on the wire, not merely in the number quoted',
        );
        $this->assertStringContainsString(
            'characters truncated to fit the context window',
            implode("\n", array_map(static fn(Message $m): string => $m->content, $landed->history)),
            'and the truncation is marked inline where the text used to be',
        );
    }

    /**
     * The rescue declines, and the refusal stands. 1.15 million estimated tokens
     * — ten exchanges of 94,999 tokens each (one token short of being
     * individually oversized, so the truncator correctly leaves them alone) plus
     * one 200,010-token giant. Truncating the giant to nothing still leaves the
     * aggregate over the tier, so both blocking sites must re-check and refuse.
     *
     * This is the test that makes Chat's `shouldCompactForeground()` re-check on
     * the truncated wire load-bearing rather than decorative: with the re-check
     * removed, this turn is DISPATCHED at over 950,000 estimated tokens into a
     * 100,000-token window — a provider rejection paid for with the user's
     * context — and every E18 assertion above stays green, because none of them
     * feeds a history the rescue cannot clear.
     */
    public function testTheRescueDeclinesAndTheRefusalStandsWhenTruncationCannotClearTheTier(): void
    {
        $history = [];
        for ($i = 0; $i < 10; $i++) {
            $history[] = Message::user(str_repeat('p', 379_956));
            $history[] = Message::assistant('r');
        }
        $history[] = Message::user(str_repeat('x', 800_000));
        $history[] = Message::assistant('bigtail');

        $backend = new IntraExchangeTurnBackend(100_000);
        $chat = new Chat(history: $history, backend: $backend);
        $this->assertSame(1_150_122, $chat->contextTokens(), 'fixture: 1,150% of a 100,000-token window');

        $chat = $this->withDraft($chat, 'go');
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd, 'a rescue that cannot clear the tier must not send the turn');
        $this->assertSame(0, $backend->calls(), 'and the provider must never be handed an over-window request');
        $this->assertStringContainsString(
            'This turn was NOT sent',
            $next->history[count($next->history) - 1]->content,
            'with the ordinary blocking-tier refusal, not a silent dispatch',
        );
    }

    /**
     * The FIRST guard of intraExchangeTruncation() — the `$truncated === $wire`
     * null return — is the helper's own contract: an echo from the truncator
     * must never become a rescue, never a rebuilt history plus a notice
     * claiming "0 messages reached the 95% blocking tier" off bytes nobody
     * trimmed.
     *
     * It cannot be exercised through the turn pipeline: both Chat blocking
     * sites call the helper only from inside shouldCompactForeground($wire)
     * === true, so for every input THEY hand it the tier re-check directly
     * below the guard answers the echo identically — measured (fix-4): deleting
     * the guard left the call-site-driven set green — `vendor/bin/phpunit
     * tests/ChatTest.php tests/Chat/AutomaticCompactionModelSummaryTest.php
     * tests/Chat/ContextReminderDedupTest.php
     * tests/Integration/ContextWindowWiringTest.php
     * tests/Context/ContextWindowTest.php` → OK (282 tests, 1185 assertions).
     * So the private helper is driven DIRECTLY via reflection,
     * the established route in this repo (ChatTest does the same for
     * executionFailure and applyRewrite), with the one input for which the
     * guard is the ONLY thing returning null: an under-tier $wire, which the
     * truncator echoes byte-for-byte (first fixture assertion) and which the
     * tier re-check therefore lets through (second fixture assertion). Delete
     * the guard and the helper returns a rescue built on an untouched wire
     * instead of null, and the assertNull reddens.
     *
     * The lower half drives the SAME reflection route on the genuine E18
     * giant — over-tier through one oversized message — so the null above is
     * proven to be the contract, not a dead harness.
     */
    public function testTheRescueDeclinesAnUnderTierWireTheTruncatorEchoes(): void
    {
        $backend = new IntraExchangeTurnBackend(100_000);
        $history = [
            Message::user('hello'),
            Message::assistant('hi'),
        ];
        $chat = new Chat(history: $history, backend: $backend);
        $wire = array_map(static fn (Message $m): array => $m->toWire(), $history);

        $compactor = (new \ReflectionProperty(Chat::class, 'compactor'))->getValue($chat);
        $this->assertSame(
            $wire,
            $compactor->truncateOversizedExchange($wire, 100_000),
            'fixture: the truncator echoes this wire byte-identically, so only the first guard can decline it',
        );
        $this->assertFalse(
            $compactor->shouldCompactForeground($wire, 100_000),
            'fixture: the tier re-check below that guard would NOT stop this input either',
        );

        $truncation = new \ReflectionMethod(Chat::class, 'intraExchangeTruncation');

        $this->assertNull(
            $truncation->invoke($chat, $wire, $history, 100_000),
            'a truncation that changed nothing must decline outright — never a rescue whose notice says 0 messages were truncated',
        );

        $giant = [
            Message::user(str_repeat('x', 800_000)),
            Message::assistant(str_repeat('y', 2_000)),
        ];
        $rescued = $truncation->invoke(
            $chat,
            array_map(static fn (Message $m): array => $m->toWire(), $giant),
            $giant,
            100_000,
        );

        $this->assertIsArray(
            $rescued,
            'the same direct route must still RESCUE the oversized case — otherwise the null above proves nothing',
        );
        $this->assertSame(
            ['history', 'notice'],
            array_keys($rescued),
            'and a rescue is exactly the pair the blocking sites consume: a rebuilt history plus a notice',
        );
        $this->assertStringStartsWith(
            '1 message reached the 95% blocking tier on its own, so it was truncated',
            $rescued['notice']->content,
            'the notice counts 1 message, never 0 — the figure the guard above exists to keep honest',
        );
        $this->assertStringContainsString(
            'characters truncated to fit the context window',
            $rescued['history'][0]->content,
            'the giant really was shortened inline in the rebuilt history',
        );
        $this->assertLessThan(
            95_000,
            $this->countTokens(array_map(static fn (Message $m): array => $m->toWire(), $rescued['history'])),
            'and the rescue hands back a wire under the blocking tier — the rescue is real, not nominal',
        );
    }

    // ─── The E18 truncation NOTICE, pinned verbatim ───────────────
    //
    // The rescue notice is committed into history, so the user reads it AND the
    // next provider prompt replays it. §16.8 rule 25: a guard path's message is
    // the one part of a green suite that never runs unless a test pins it, and
    // until now no test asserted this sentence at all - only the inline marker
    // substring. The wording defect it now pins: the old sentence counted changed
    // wire ENTRIES (messages) but labelled them "exchanges" behind a "A single
    // exchange" lead-in, so the one-exchange-both-halves-oversized fixture read
    // "A single exchange ... so 2 exchanges were truncated" - self-contradictory
    // in its first eight words. Each test below asserts the WHOLE sentence
    // verbatim plus the number of messages physically carrying the inline marker,
    // so the quoted count, its noun, and the bytes all have to agree.

    /**
     * The rescue notice for the classic E18 shape - one oversized user message,
     * the assistant half small enough to survive - in full, singular agreement
     * throughout, Role::System, riding immediately before the user's line.
     */
    public function testTheTruncationNoticeForOneOversizedMessageReadsExactly(): void
    {
        $backend = new IntraExchangeTurnBackend(100_000);
        $chat = new Chat(
            history: [
                Message::user(str_repeat('x', 800_000)),
                Message::assistant(str_repeat('y', 2_000)),
            ],
            backend: $backend,
        );
        $chat = $this->withDraft($chat, 'go');

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNotNull($cmd, 'fixture: this history must reach the rescue');

        $notice = $this->truncationNotice($next);
        $this->assertSame(Role::System, $notice->role, 'the app reporting on its own action stays a system line');
        $this->assertSame(
            '1 message reached the 95% blocking tier on its own, so it was truncated to fit '
            . 'the context window rather than the turn being refused: ~92977 estimated tokens '
            . 'now, against a 100000-token context window. The dropped text is marked inline '
            . 'in that message.',
            $notice->content,
            'the whole sentence verbatim: the 92,977 chars/4 ESTIMATE rides beside "estimated '
            . 'tokens" and the 100,000 advertised window beside "context window", never swapped',
        );
        $this->assertStringNotContainsString(
            'exchange',
            $notice->content,
            'the rescue counts messages - naming exchanges it never counted was the defect',
        );
        $this->assertSame(
            1,
            $this->markedTruncations($next),
            'the figure in the sentence equals the number of messages physically carrying the inline marker',
        );

        $index = array_search($notice, $next->history, true);
        $this->assertIsInt($index);
        $this->assertSame(
            'go',
            $next->history[$index + 1]->content,
            'the notice still rides immediately before the user line on submit()\'s synchronous route',
        );
    }

    /**
     * The case that exposed the contradiction: ONE exchange whose user AND
     * assistant halves each reach the blocking tier, so the truncator shortens
     * TWO messages. The old notice paired "A single exchange" with "2 exchanges
     * were truncated"; the sentence now counts messages, which is the domain the
     * code actually measures - ContextCompactor::truncateOversizedExchange()
     * decides per message and exposes no exchange-pair arithmetic.
     */
    public function testTheTruncationNoticeStaysTruthfulForTwoOversizedMessagesInOneExchange(): void
    {
        $backend = new IntraExchangeTurnBackend(100_000);
        $chat = new Chat(
            history: [
                Message::user(str_repeat('x', 800_000)),
                Message::assistant(str_repeat('z', 600_000)),
            ],
            backend: $backend,
        );
        $chat = $this->withDraft($chat, 'go');

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNotNull($cmd, 'fixture: this history must reach the rescue');

        $notice = $this->truncationNotice($next);
        $this->assertSame(
            '2 messages reached the 95% blocking tier on their own, so they were truncated to '
            . 'fit the context window rather than the turn being refused: ~92954 estimated '
            . 'tokens now, against a 100000-token context window. The dropped text is marked '
            . 'inline in those messages.',
            $notice->content,
            'and 92,954 is the same two-giant figure testEveryOversizedExchangeSharesTheRemainingBudget '
            . 'pins at the truncator level - notice and wire agree',
        );
        $this->assertSame(
            2,
            $this->markedTruncations($next),
            'two MESSAGES were shortened - which is ONE exchange; the count must track the former',
        );
    }

    /**
     * Count and noun must agree at every size: here THREE messages carry the
     * marker while only TWO exchanges exist at all, so any exchange-labelled
     * count - "2 exchanges" - would contradict the bytes, and "3 exchanges"
     * would contradict the conversation. Only "3 messages" is true of both.
     */
    public function testTheTruncationNoticeTracksMessagesEvenWhenTheySpanTwoExchanges(): void
    {
        $backend = new IntraExchangeTurnBackend(100_000);
        $chat = new Chat(
            history: [
                Message::user(str_repeat('x', 800_000)),
                Message::assistant(str_repeat('z', 600_000)),
                Message::user(str_repeat('w', 700_000)),
                Message::assistant(str_repeat('t', 2_000)),
            ],
            backend: $backend,
        );
        $chat = $this->withDraft($chat, 'go');

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNotNull($cmd, 'fixture: this history must reach the rescue');

        $notice = $this->truncationNotice($next);
        $this->assertSame(
            '3 messages reached the 95% blocking tier on their own, so they were truncated to '
            . 'fit the context window rather than the turn being refused: ~92931 estimated '
            . 'tokens now, against a 100000-token context window. The dropped text is marked '
            . 'inline in those messages.',
            $notice->content,
        );
        $this->assertSame(
            3,
            $this->markedTruncations($next),
            'three marker-carrying messages, two exchanges - the sentence quotes the messages',
        );
    }

    /**
     * The single truncation notice a rescued history carries. The blocking-tier
     * refusal and the compaction notice use different wording, so this filter
     * cannot capture them by accident.
     */
    private function truncationNotice(Chat $chat): Message
    {
        $hits = array_values(array_filter(
            $chat->history,
            static fn (Message $m): bool => $m->role === Role::System
                && str_contains($m->content, 'reached the 95% blocking tier'),
        ));
        $this->assertCount(1, $hits, 'the rescue writes exactly one truncation notice into history');

        return $hits[0];
    }

    /** How many messages in the rescued history carry the inline truncation marker. */
    private function markedTruncations(Chat $chat): int
    {
        return count(array_filter(
            $chat->history,
            static fn (Message $m): bool => str_contains(
                $m->content,
                'characters truncated to fit the context window',
            ),
        ));
    }

    /**
     * Load a draft without typing 800,000 characters one keystroke at a time.
     * `inputBuf` is a promoted readonly property, so `mutate()` is the only
     * route — the same one AutomaticCompactionModelSummaryTest uses.
     */
    private function withDraft(Chat $chat, string $draft): Chat
    {
        return (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($chat, ['inputBuf' => $draft]);
    }

    /** Run a dispatch Cmd and feed the reply back so the turn actually settles. */
    private function settle(Chat $chat, \Closure $cmd): Chat
    {
        $reply = $this->resolve($cmd);
        if ($reply === null) {
            return $chat;
        }
        // Chat is immutable: the settled state is the RETURNED chat, and dropping
        // it would leave every attempt in the loop starting from a turn that never
        // ended, which is not the sequence the bug produced.
        [$settled] = $chat->update($reply);

        return $settled;
    }

    /** Drive a Cmd built by Cmd::promise() and hand back the Msg it resolves to. */
    private function resolve(\Closure $cmd): mixed
    {
        $async = $cmd();
        if (!$async instanceof AsyncCmd) {
            return null;
        }
        $resolved = null;
        $async->promise->then(function ($msg) use (&$resolved): void {
            $resolved = $msg;
        });

        return $resolved;
    }
}

/**
 * A conversation backend for the E18 Chat tests: reports a fixed window, answers
 * two characters, and records every history it was handed so the test can assert
 * on the BYTES THAT WERE ACTUALLY SENT rather than on a figure the app quotes.
 *
 * The reply is two characters on purpose — echoing the last user message the way
 * `EchoBackend` does would re-add 800,000 characters every turn and walk the
 * drive loop straight back over the tier.
 *
 * Named distinctly from `ReminderWireRecorder` and `RecordingTurnBackend`, which
 * live in the `Tests\Chat` namespace; a second declaration in a full-suite run is
 * a fatal error.
 */
final class IntraExchangeTurnBackend implements Backend, ReportsContextWindow
{
    /** @var list<list<Message>> */
    private array $seen = [];

    public function __construct(private readonly int $window) {}

    public function contextWindow(): int
    {
        return $this->window;
    }

    public function calls(): int
    {
        return count($this->seen);
    }

    /** @return list<Message>|null */
    public function historyAt(int $index): ?array
    {
        return $this->seen[$index] ?? null;
    }

    public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
    {
        $this->seen[] = $history;

        return Message::assistant('ok');
    }

    public function completeAsync(
        array $history,
        ?callable $onToken = null,
        ?CancellationToken $cancellation = null,
        ?callable $onEvent = null,
    ): PromiseInterface {
        $this->seen[] = $history;

        return \React\Promise\resolve(Message::assistant('ok'));
    }
}

/**
 * A summarisation backend answering with enough numbered lines that every
 * offered exchange gets one, so the parked route lands instead of failing.
 */
final class IntraExchangeSummariser implements Backend
{
    public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
    {
        $lines = [];
        for ($i = 1; $i <= 12; $i++) {
            $lines[] = "{$i}. condensed exchange {$i}";
        }

        return Message::assistant(implode("\n", $lines));
    }

    public function completeAsync(
        array $history,
        ?callable $onToken = null,
        ?CancellationToken $cancellation = null,
        ?callable $onEvent = null,
    ): PromiseInterface {
        return \React\Promise\resolve($this->complete($history));
    }
}

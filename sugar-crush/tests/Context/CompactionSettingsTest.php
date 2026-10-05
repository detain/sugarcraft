<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Config\Settings\SettingsSchema;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Context\ContextCompactor;
use SugarCraft\Crush\Context\Pruning\NudgePolicy;

/**
 * Roadmap N-P4b: the compaction constants as settings, and the user decision
 * that ships two absolute caps ON by default — the reminder at 100k tokens,
 * automatic compaction at 150k, the blocking tier still a percentage only —
 * with the hard rule that an absolute tier never makes a prompt futile to
 * send (see {@see \SugarCraft\Crush\Tests\Chat\AbsoluteCapBreakerTest} for the
 * breaker half, driven through Chat).
 *
 * Histories are ASCII wire arrays: four characters a token, plus ten per
 * message ({@see ContextCompactor}'s estimate).
 */
final class CompactionSettingsTest extends TestCase
{
    private const MILLION = 1_000_000;

    // =====================================================================
    // Defaults
    // =====================================================================

    public function testTheReminderAndAutoTiersAreCappedByDefaultAndTheBlockingTierIsNot(): void
    {
        $config = CompactorConfig::new();

        $this->assertSame(CompactorConfig::DEFAULT_REMINDER_TOKENS, $config->reminderTokens);
        $this->assertSame(100_000, $config->reminderTokens);
        $this->assertSame(CompactorConfig::DEFAULT_COMPACTION_TOKENS, $config->backgroundCompactionTokens);
        $this->assertSame(150_000, $config->backgroundCompactionTokens);
        $this->assertNull($config->foregroundBlockingTokens, 'the one tier that refuses stays a percentage');
    }

    public function testAMillionTokenWindowFiresTheReminderAt100kAndCompactsAt150k(): void
    {
        $config = CompactorConfig::new();

        $this->assertSame(100_000, $config->reminderTokenThreshold(self::MILLION));
        $this->assertSame(150_000, $config->backgroundCompactionTokenThreshold(self::MILLION));
        $this->assertSame(950_000, $config->foregroundBlockingTokenThreshold(self::MILLION));
    }

    public function testA128kWindowGetsExactlyThePercentageThresholds(): void
    {
        $capped = CompactorConfig::new();
        $uncapped = new CompactorConfig(reminderTokens: null, backgroundCompactionTokens: null);

        foreach ([128_000, 131_072, 100_000, 32_000, 142_857] as $window) {
            $this->assertSame($uncapped->reminderTokenThreshold($window), $capped->reminderTokenThreshold($window), "reminder on {$window}");
            $this->assertSame($uncapped->backgroundCompactionTokenThreshold($window), $capped->backgroundCompactionTokenThreshold($window), "auto on {$window}");
            $this->assertSame($uncapped->foregroundBlockingTokenThreshold($window), $capped->foregroundBlockingTokenThreshold($window), "block on {$window}");
        }
        $this->assertSame(89_600, $capped->reminderTokenThreshold(128_000));
        $this->assertSame(108_800, $capped->backgroundCompactionTokenThreshold(128_000));
    }

    /**
     * Every verdict a 128k session's compactor gives is the verdict the
     * percentage-only config gave, across the whole range up to the window.
     */
    public function testA128kModelsCompactorBehavesExactlyAsBefore(): void
    {
        $capped = new ContextCompactor(CompactorConfig::new());
        $uncapped = new ContextCompactor(new CompactorConfig(reminderTokens: null, backgroundCompactionTokens: null));

        for ($tokens = 40_000; $tokens <= 130_000; $tokens += 2_500) {
            $history = self::compactable($tokens);
            $this->assertSame($uncapped->shouldSendReminder($history, 128_000), $capped->shouldSendReminder($history, 128_000), "reminder at ~{$tokens}");
            $this->assertSame($uncapped->shouldCompact($history, 128_000), $capped->shouldCompact($history, 128_000), "auto at ~{$tokens}");
            $this->assertSame($uncapped->shouldCompactForeground($history, 128_000), $capped->shouldCompactForeground($history, 128_000), "block at ~{$tokens}");
        }
    }

    public function testOnAMillionTokenWindowTheTiersFireAtTheCaps(): void
    {
        $compactor = ContextCompactor::new();

        $this->assertFalse($compactor->shouldSendReminder(self::compactable(95_000), self::MILLION));
        $this->assertTrue($compactor->shouldSendReminder(self::compactable(102_000), self::MILLION), 'the reminder fires at 100k, not 700k');
        $this->assertFalse($compactor->shouldCompact(self::compactable(140_000), self::MILLION));
        $this->assertTrue($compactor->shouldCompact(self::compactable(155_000), self::MILLION), 'compaction fires at 150k, not 850k');
        $this->assertFalse($compactor->shouldCompactForeground(self::compactable(155_000), self::MILLION), 'no refusal anywhere near the caps');
    }

    // =====================================================================
    // An absolute tier stands down when compacting cannot get under it
    // =====================================================================

    /**
     * Ten preserved exchanges of 20k tokens each: compaction keeps all of them,
     * so it could never bring a 1M session under 150k. The tier stands down
     * instead of re-compacting on every prompt — and so does the reminder.
     */
    public function testAKeptTailOverTheCapStandsTheAbsoluteTiersDown(): void
    {
        $compactor = ContextCompactor::new();
        $history = self::heavyTail(20_000);

        $this->assertGreaterThan(200_000, self::estimate($history));
        $this->assertFalse($compactor->shouldCompact($history, self::MILLION), 'compacting cannot get under 150k');
        $this->assertFalse($compactor->shouldSendReminder($history, self::MILLION), 'nor under 100k');
    }

    public function testAKeptTailBetweenTheCapsStandsOnlyTheReminderDown(): void
    {
        $compactor = ContextCompactor::new();
        // ~120k kept tail plus ~40k of older, condensable exchanges.
        $history = [...self::pairs(2, 20_000), ...self::heavyTail(12_000)];

        $this->assertGreaterThan(150_000, self::estimate($history));
        $this->assertFalse($compactor->shouldSendReminder($history, self::MILLION), 'the tail alone is over 100k');
        $this->assertTrue($compactor->shouldCompact($history, self::MILLION), 'but compacting does get under 150k');
    }

    public function testThePercentageTierNeverStandsDown(): void
    {
        $compactor = ContextCompactor::new();
        // Kept tail over the 85% of a 200k window: a full window, not a cap.
        $history = self::heavyTail(18_000);

        $this->assertTrue($compactor->shouldCompact($history, 200_000), 'over the percentage the tier fires as it always did');
    }

    public function testStandingDownLeavesTheSavingsFigureAlone(): void
    {
        $compactor = ContextCompactor::new();
        $compactor->compact(self::compactable(60_000));
        $before = $compactor->savingsPercentage();

        $compactor->shouldCompact(self::heavyTail(20_000), self::MILLION);

        $this->assertSame($before, $compactor->savingsPercentage(), 'the futility probe compacts a copy');
    }

    // =====================================================================
    // fromSettings()
    // =====================================================================

    public function testNoSettingsIsTheDefaults(): void
    {
        $this->assertEquals(CompactorConfig::new(), CompactorConfig::fromSettings([]));
        $this->assertEquals(CompactorConfig::new(), CompactorConfig::fromSettings(['theme' => 'dark']));
    }

    public function testEveryKeyReachesItsField(): void
    {
        $config = CompactorConfig::fromSettings([
            'compaction.reminderPercent' => 60,
            'compaction.autoPercent' => 75,
            'compaction.blockPercent' => 90,
            'compaction.keepRecent' => 6,
            'compaction.summaryUserChars' => 120,
            'compaction.summaryAssistantChars' => 140,
            'compaction.toolOutputChars' => 3_000,
            'compaction.reminderTokens' => 80_000,
            'compaction.autoTokens' => 120_000,
            'compaction.blockTokens' => 500_000,
            'contextPruning.minContextTokens' => 40_000,
            'contextPruning.maxContextTokens' => 90_000,
            'contextPruning.nudgeFrequency' => 3,
            'contextPruning.iterationNudgeThreshold' => 7,
            'contextPruning.compress' => 'auto',
        ]);

        $this->assertSame([60, 75, 90], [$config->reminderThreshold, $config->backgroundCompactionThreshold, $config->foregroundBlockingThreshold]);
        $this->assertSame(6, $config->recentPreserveCount);
        $this->assertSame([120, 140, 3_000], [$config->summaryUserMaxChars, $config->summaryAssistantMaxChars, $config->toolOutputMaxChars]);
        $this->assertSame([80_000, 120_000, 500_000], [$config->reminderTokens, $config->backgroundCompactionTokens, $config->foregroundBlockingTokens]);
        $this->assertTrue($config->reminderTokensSet && $config->backgroundCompactionTokensSet && $config->foregroundBlockingTokensSet);
        $this->assertTrue($config->offersCompressUnprompted());

        $nudges = $config->nudgePolicy();
        $this->assertSame([40_000, 90_000, 3, 7], [$nudges->minContextTokens, $nudges->maxContextTokens, $nudges->nudgeFrequency, $nudges->iterationThreshold]);
    }

    public function testTheNudgeDefaultsAreTheNudgePolicysOwn(): void
    {
        $this->assertEquals(NudgePolicy::new(), CompactorConfig::new()->nudgePolicy());
        $this->assertFalse(CompactorConfig::new()->offersCompressUnprompted(), 'Compress stays manual by default');
    }

    public function testZeroTurnsACapOff(): void
    {
        $config = CompactorConfig::fromSettings(['compaction.reminderTokens' => 0, 'compaction.autoTokens' => 0]);

        $this->assertNull($config->reminderTokens);
        $this->assertNull($config->backgroundCompactionTokens);
        $this->assertSame(700_000, $config->reminderTokenThreshold(self::MILLION), 'the percentage alone, as before 2.9');
        $this->assertSame(850_000, $config->backgroundCompactionTokenThreshold(self::MILLION));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function nonsense(): iterable
    {
        yield 'a string' => [['compaction.keepRecent' => '6', 'compaction.autoTokens' => '9']];
        yield 'below range' => [['compaction.keepRecent' => 0, 'compaction.autoTokens' => -1]];
        yield 'a fraction' => [['compaction.keepRecent' => 2.5]];
        yield 'a percentage of 100' => [['compaction.blockPercent' => 100]];
        yield 'an unknown compress mode' => [['contextPruning.compress' => 'always']];
        yield 'a min over the max' => [['contextPruning.minContextTokens' => 200_000]];
        yield 'a list for the per-model map' => [['compaction.modelTokenCaps' => [1, 2]]];
    }

    /**
     * @dataProvider nonsense
     *
     * @param array<string, mixed> $settings
     */
    public function testAValueOfTheWrongShapeKeepsTheDefault(array $settings): void
    {
        $this->assertEquals(CompactorConfig::new(), CompactorConfig::fromSettings($settings));
    }

    public function testAnIntegralFloatCounts(): void
    {
        $this->assertSame(8, CompactorConfig::fromSettings(['compaction.keepRecent' => 8.0])->recentPreserveCount);
    }

    public function testPercentagesOutOfOrderAreIgnoredAsASet(): void
    {
        $config = CompactorConfig::fromSettings([
            'compaction.reminderPercent' => 90,
            'compaction.autoPercent' => 80,
            'compaction.keepRecent' => 4,
        ]);

        $this->assertSame([70, 85, 95], [$config->reminderThreshold, $config->backgroundCompactionThreshold, $config->foregroundBlockingThreshold]);
        $this->assertSame(4, $config->recentPreserveCount, 'the other keys still apply');
    }

    public function testThePerModelMapOverridesTheCapsForThatModel(): void
    {
        $config = CompactorConfig::fromSettings([
            'compaction.modelTokenCaps' => [
                'qwen3' => ['autoTokens' => 300_000],
                'sglang/qwen3' => ['reminderTokens' => 0],
                'broken' => ['autoTokens' => 'lots'],
                'unknown-key' => ['tierTokens' => 5],
            ],
        ]);

        $this->assertSame(['qwen3', 'sglang/qwen3'], array_keys($config->modelTokenOverrides), 'a malformed entry is dropped whole');

        $qwen = $config->forModel('qwen3', 'openai');
        $this->assertSame(300_000, $qwen->backgroundCompactionTokens);
        $this->assertSame(100_000, $qwen->reminderTokens, 'a name left out inherits the top-level cap');

        $pinned = $config->forModel('qwen3', 'sglang');
        $this->assertNull($pinned->reminderTokens, '0 clears the cap for that deployment');
        $this->assertSame(150_000, $pinned->backgroundCompactionTokens, 'only the more specific entry applies');
    }

    // =====================================================================
    // The schema
    // =====================================================================

    public function testTheSchemaDefaultsAreTheConfigDefaults(): void
    {
        $defaults = CompactorConfig::new();
        $expected = [
            'compaction.reminderPercent' => $defaults->reminderThreshold,
            'compaction.autoPercent' => $defaults->backgroundCompactionThreshold,
            'compaction.blockPercent' => $defaults->foregroundBlockingThreshold,
            'compaction.keepRecent' => $defaults->recentPreserveCount,
            'compaction.summaryUserChars' => $defaults->summaryUserMaxChars,
            'compaction.summaryAssistantChars' => $defaults->summaryAssistantMaxChars,
            'compaction.toolOutputChars' => $defaults->toolOutputMaxChars,
            'compaction.reminderTokens' => 100_000,
            'compaction.autoTokens' => 150_000,
            'compaction.blockTokens' => null,
            'compaction.modelTokenCaps' => [],
        ];

        foreach ($expected as $key => $default) {
            $definition = SettingsSchema::byKey($key);
            $this->assertNotNull($definition, "{$key} has a schema row");
            $this->assertSame($default, $definition->default, "{$key}'s default");
            $this->assertSame(CompactorConfig::class . '::fromSettings', $definition->readerSymbol);
        }
    }

    public function testEveryCompactionKeyIsTuningAProjectMaySetAndAppliesAtRestart(): void
    {
        foreach (SettingsSchema::all() as $definition) {
            if (!str_starts_with($definition->key, 'compaction.')) {
                continue;
            }
            $this->assertTrue($definition->projectSettable, "{$definition->key} is tuning a project may set");
            $this->assertSame(\SugarCraft\Crush\Config\Settings\RiskClass::Tuning, $definition->riskClass);
            $this->assertSame(\SugarCraft\Crush\Config\Settings\ApplyMode::Restart, $definition->applyMode, 'read once at launch');
        }
    }

    public function testASaveThatBreaksTheTierOrderIsRefused(): void
    {
        $auto = SettingsSchema::byKey('compaction.autoPercent');
        $this->assertNotNull($auto);

        $this->assertNull($auto->validate(80, ['compaction.reminderPercent' => 70, 'compaction.autoPercent' => 80, 'compaction.blockPercent' => 95]));
        $this->assertNotNull($auto->validate(60, ['compaction.reminderPercent' => 70, 'compaction.autoPercent' => 60]));
        $this->assertNotNull($auto->validate(100), 'a percentage of the window is at most 99');
        $this->assertNotNull(SettingsSchema::byKey('compaction.autoTokens')?->validate(-1));
        $this->assertNull(SettingsSchema::byKey('compaction.autoTokens')?->validate(0), '0 is "off"');
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    /**
     * About $tokens estimated tokens: big OLDER exchanges a compaction can
     * condense, then ten trivial recent ones it keeps.
     *
     * @return list<array{role:string,content:string}>
     */
    private static function compactable(int $tokens): array
    {
        $older = intdiv($tokens, 10_000);
        $rest = $tokens - $older * 10_000;
        $history = self::pairs($older, 10_000);
        if ($rest > 100) {
            $history[] = ['role' => 'user', 'content' => str_repeat('r', ($rest - 100) * 4)];
            $history[] = ['role' => 'assistant', 'content' => 'ok'];
        }

        return [...$history, ...self::pairs(10, 0)];
    }

    /**
     * Ten recent exchanges of $tokens each (what compaction preserves), after
     * one small older one.
     *
     * @return list<array{role:string,content:string}>
     */
    private static function heavyTail(int $tokens): array
    {
        return [...self::pairs(1, 0), ...self::pairs(10, $tokens)];
    }

    /** @return list<array{role:string,content:string}> */
    private static function pairs(int $count, int $tokensEach): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $half = max(1, intdiv($tokensEach * 4, 2));
            $out[] = ['role' => 'user', 'content' => $tokensEach === 0 ? "q{$i}" : str_repeat('u', $half)];
            $out[] = ['role' => 'assistant', 'content' => $tokensEach === 0 ? "a{$i}" : str_repeat('a', $half)];
        }

        return $out;
    }

    /** @param list<array{role:string,content:string}> $history */
    private static function estimate(array $history): int
    {
        $total = 0;
        foreach ($history as $message) {
            $total += intdiv(strlen($message['content']), 4) + 10;
        }

        return $total;
    }
}

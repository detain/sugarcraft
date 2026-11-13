<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config\Settings\Definitions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Config\Settings\Definitions\ContextSettings;
use SugarCraft\Code\Config\Settings\SettingCategory;
use SugarCraft\Code\Config\Settings\SettingDefinition;
use SugarCraft\Code\Config\Settings\SettingDefinitionSet;
use SugarCraft\Code\Config\Settings\SettingsSchema;
use SugarCraft\Code\Config\Settings\SettingType;

/**
 * Pins the compaction and context-window keys that ContextSettings contributes to the schema: the exact
 * key roster (order is the settings-UI order), each row's type, risk class and
 * tiering, and that every row is registered in {@see SettingsSchema}.
 */
final class ContextSettingsTest extends TestCase
{
    /**
     * key => [type, risk class, project-settable, layered]
     *
     * @var array<string, array{string, string, bool, bool}>
     */
    private const ROWS = [
            'compaction.reminderPercent' => ['Int', 'Tuning', true, true],
            'compaction.autoPercent' => ['Int', 'Tuning', true, true],
            'compaction.blockPercent' => ['Int', 'Tuning', true, true],
            'compaction.keepRecent' => ['Int', 'Tuning', true, true],
            'compaction.summaryUserChars' => ['Int', 'Tuning', true, true],
            'compaction.summaryAssistantChars' => ['Int', 'Tuning', true, true],
            'compaction.toolOutputChars' => ['Int', 'Tuning', true, true],
            'compaction.reminderTokens' => ['Int', 'Tuning', true, true],
            'compaction.autoTokens' => ['Int', 'Tuning', true, true],
            'compaction.blockTokens' => ['Int', 'Tuning', true, true],
            'compaction.modelTokenCaps' => ['Map', 'Tuning', true, true],
            'compaction.idleOfferSeconds' => ['Int', 'Tuning', true, true],
            'compaction.mode' => ['Enum', 'Tuning', true, true],
            'compaction.refillLimit' => ['Int', 'Spend', false, true],
            'compaction.idleAuto' => ['Enum', 'Tuning', false, true],
            'promptCache.keepWarm' => ['Int', 'Spend', false, true],
            'contextPruning.minContextTokens' => ['Int', 'Tuning', true, true],
            'contextPruning.maxContextTokens' => ['Int', 'Tuning', true, true],
            'contextPruning.nudgeFrequency' => ['Int', 'Tuning', true, true],
            'contextPruning.iterationNudgeThreshold' => ['Int', 'Tuning', true, true],
            'contextPruning.compress' => ['Enum', 'Tuning', false, false],
            'symbolMap.enabled' => ['Bool', 'Narrowing', false, false],
            'repoMap.enabled' => ['Bool', 'Narrowing', true, true],
            'repoMap.maxBytes' => ['Int', 'Spend', false, true],
            'env.gitDiffAfterWrites' => ['Bool', 'Narrowing', true, true],
            'env.diffMaxBytes' => ['Int', 'Spend', false, true],
            'notices.transcriptLimit' => ['Int', 'Tuning', false, false],
            'contextWindow' => ['Json', 'Tuning', false, true],
            'contextPruning.mode' => ['Enum', 'Tuning', false, false],
    ];

    public function testItIsASettingDefinitionSetForItsCategory(): void
    {
        self::assertInstanceOf(SettingDefinitionSet::class, new class() implements SettingDefinitionSet {
            public static function category(): SettingCategory
            {
                return ContextSettings::category();
            }

            public static function definitions(): array
            {
                return ContextSettings::definitions();
            }
        });
        self::assertTrue(is_subclass_of(ContextSettings::class, SettingDefinitionSet::class));
        self::assertSame(SettingCategory::Context, ContextSettings::category());
    }

    public function testItOwnsExactlyTheDocumentedKeysInOrder(): void
    {
        $keys = array_map(static fn (SettingDefinition $d): string => $d->key, ContextSettings::definitions());

        self::assertSame(array_keys(self::ROWS), $keys);
        self::assertSame($keys, array_values(array_unique($keys)), 'no duplicate keys');
    }

    public function testEveryRowKeepsItsTypeRiskAndTiering(): void
    {
        foreach (ContextSettings::definitions() as $d) {
            self::assertSame(
                self::ROWS[$d->key],
                [$d->type->name, $d->riskClass->name, $d->projectSettable, $d->layered],
                $d->key,
            );
        }
    }

    public function testEveryRowIsFiledUnderTheSetsCategoryWithResolvedCopy(): void
    {
        foreach (ContextSettings::definitions() as $d) {
            self::assertSame(ContextSettings::category(), $d->category, $d->key);
            self::assertNotSame('', trim($d->label), $d->key . ' label');
            self::assertNotSame('', trim($d->help), $d->key . ' help');
            self::assertStringStartsNotWith('settings.', $d->label, $d->key . ' label is an unresolved lang key');
        }
    }

    public function testEnumRowsDeclareTheirValuesAndDefaultsAreMembers(): void
    {
        $enums = [];
        foreach (ContextSettings::definitions() as $d) {
            if ($d->type !== SettingType::Enum) {
                continue;
            }
            $enums[] = $d->key;
            self::assertNotSame([], $d->enumValues, $d->key . ' is an Enum with no values');
            if ($d->default !== null) {
                self::assertContains($d->default, $d->enumValues, $d->key . ' default');
            }
        }
        self::assertSame(['compaction.mode', 'compaction.idleAuto', 'contextPruning.compress', 'contextPruning.mode'], $enums);
    }

    public function testEveryRowIsRegisteredInTheSchema(): void
    {
        foreach (ContextSettings::definitions() as $d) {
            $registered = SettingsSchema::byKey($d->key);
            self::assertNotNull($registered, $d->key . ' is not in SettingsSchema');
            self::assertSame($d->type, $registered->type, $d->key);
            self::assertSame(ContextSettings::category(), $registered->category, $d->key);
        }
    }
}

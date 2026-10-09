<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config\Settings;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Config\Settings\ApplyMode;
use SugarCraft\Crush\Config\Settings\Definitions\MediaSettings;
use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingCategory;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingSource;
use SugarCraft\Crush\Config\Settings\SettingsResolver;
use SugarCraft\Crush\Config\Settings\SettingsSchema;
use SugarCraft\Crush\Config\Settings\SettingType;
use SugarCraft\Crush\Config\Settings\UiEditability;
use SugarCraft\Crush\Providers\ProviderFactory;
use SugarCraft\Mosaic\Mosaic;

/**
 * The Media category's schema rows (plan_crush_media W1.8): the eight keys
 * resolve with the tiers, defaults and env bindings the plan names, the
 * render-mode vocabulary is cross-pinned against candy-mosaic's live
 * `fromModeString()` oracle, and the provider schema carries the three media
 * optionals without disturbing a single pre-existing provider block.
 */
final class MediaSettingsTest extends TestCase
{
    private const ENV_KEYS = ['SUGARCRUSH_SD_BASE_URL', 'SUGARCRUSH_MEDIA_RENDER_MODE'];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        // Snapshot FIRST (HomeSandboxTrait law, lane-cf): capture before any
        // putenv() unsets, so tearDown restores exactly what the process had.
        foreach (self::ENV_KEYS as $var) {
            $this->savedEnv[$var] = getenv($var);
            putenv($var);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $var => $value) {
            putenv($value === false ? $var : "{$var}={$value}");
        }

        parent::tearDown();
    }

    private static function def(string $key): SettingDefinition
    {
        $definition = SettingsSchema::byKey($key);
        self::assertNotNull($definition, "{$key} has no schema row");

        return $definition;
    }

    public function testTheMediaSetOwnsExactlyItsEightKeys(): void
    {
        $keys = array_map(
            static fn (SettingDefinition $d): string => $d->key,
            SettingsSchema::inCategory(SettingCategory::Media),
        );

        self::assertSame([
            'sd.baseUrl',
            'sd.apiKey',
            'sd.timeoutSeconds',
            'sd.defaultModel',
            'ui.imageRenderMode',
            'sd.presets',
            'ui.mediaDisplayOverrides',
            'sd.savePattern',
        ], $keys, 'row order is declaration order — the panel lists in it');
        self::assertSame(SettingCategory::Media, MediaSettings::category());
    }

    public function testTheMediaCaseSitsBetweenToolsAndMemory(): void
    {
        self::assertSame('media', SettingCategory::Media->value);
        self::assertSame(SettingCategory::Tools->order() + 1, SettingCategory::Media->order());
        self::assertSame(SettingCategory::Media->order() + 1, SettingCategory::MemoryRules->order());
        self::assertSame('settings.category.media', SettingCategory::Media->labelKey());
        self::assertNotSame('', SettingCategory::Media->label());
    }

    public function testTheTiersAreWhatThePlanNames(): void
    {
        $baseUrl = self::def('sd.baseUrl');
        self::assertSame(SettingType::Url, $baseUrl->type);
        self::assertSame(RiskClass::Egress, $baseUrl->riskClass);
        self::assertTrue($baseUrl->layered, 'sd.baseUrl must ride the layered merge (mutation pin: dropping withLayered() reddens here)');
        self::assertFalse($baseUrl->projectSettable, 'egress direction is user tier only');
        self::assertSame('SUGARCRUSH_SD_BASE_URL', $baseUrl->envVar);

        $apiKey = self::def('sd.apiKey');
        self::assertSame(SettingType::Secret, $apiKey->type, 'the panel masks what it is not typed to mask');
        self::assertSame(RiskClass::Security, $apiKey->riskClass);
        self::assertTrue($apiKey->layered);
        self::assertFalse($apiKey->projectSettable);
        self::assertStringContainsString('${VAR}', (string) $apiKey->defaultText);
        self::assertStringContainsString('resolves to empty', (string) $apiKey->defaultText);

        $timeout = self::def('sd.timeoutSeconds');
        self::assertSame(SettingType::Int, $timeout->type);
        self::assertSame(ApplyMode::NextTurn, $timeout->applyMode);
        self::assertTrue($timeout->layered);

        $renderMode = self::def('ui.imageRenderMode');
        self::assertSame(SettingType::Enum, $renderMode->type);
        self::assertSame(RiskClass::Cosmetic, $renderMode->riskClass);
        self::assertSame('SUGARCRUSH_MEDIA_RENDER_MODE', $renderMode->envVar);
        self::assertTrue($renderMode->layered);
        self::assertSame(ApplyMode::Restart, $renderMode->applyMode, 'no apply arm exists yet — only Restart is honest');

        self::assertSame(SettingType::Map, self::def('sd.presets')->type);
        self::assertSame(UiEditability::Complex, self::def('sd.presets')->ui);
        self::assertSame(SettingType::Map, self::def('ui.mediaDisplayOverrides')->type);
        self::assertSame(SettingType::String, self::def('sd.defaultModel')->type);
        self::assertSame(SettingType::String, self::def('sd.savePattern')->type);
    }

    public function testTheDefaultsAreByteExact(): void
    {
        self::assertNull(self::def('sd.baseUrl')->default);
        self::assertNull(self::def('sd.apiKey')->default);
        self::assertSame(300, self::def('sd.timeoutSeconds')->default);
        self::assertNull(self::def('sd.defaultModel')->default);
        self::assertSame('auto', self::def('ui.imageRenderMode')->default);
        self::assertSame([], self::def('sd.presets')->default);
        self::assertSame([], self::def('ui.mediaDisplayOverrides')->default);
        self::assertSame('[date]-[time]-[seed]-[number]', self::def('sd.savePattern')->default);
    }

    /**
     * The vocabulary is mosaic's, not a restatement that may drift: every word
     * the schema accepts must still answer non-null from the live oracle, and
     * the oracle's own match arms must all appear in the schema.
     */
    public function testTheRenderModeVocabularyIsTheLiveMosaicOracle(): void
    {
        $values = self::def('ui.imageRenderMode')->enumValues;
        self::assertSame(MediaSettings::RENDER_MODES, $values);

        foreach ($values as $word) {
            self::assertNotNull(Mosaic::fromModeString($word), "schema word '{$word}' is not a mosaic mode word");
        }

        foreach (['sixel', 'kitty', 'iterm2', 'halfblock', 'half', 'ansi', 'quarterblock', 'quarter', 'ascii', 'ansi256', 'truecolor', 'chafa'] as $word) {
            self::assertContains($word, $values, "mosaic accepts '{$word}' but the schema does not");
        }
    }

    public function testTheRenderModeRefusesAnythingElseNamingTheAllowedSet(): void
    {
        $reason = self::def('ui.imageRenderMode')->validate('bitmap');
        self::assertNotNull($reason, 'junk render mode must be refused (mutation pin: accepting junk reddens here)');
        self::assertStringStartsWith('must be one of:', $reason);
        self::assertStringContainsString('sixel', $reason, 'the refusal names the allowed set');
        self::assertStringContainsString('chafa', $reason);
        // The house comparator is case-exact; mosaic's trim/lowercase is the
        // reader's business, not the schema's.
        self::assertNotNull(self::def('ui.imageRenderMode')->validate('Kitty'));
    }

    public function testEveryMediaRowIsLayeredAndNoneIsProjectTier(): void
    {
        $layered = SettingsSchema::layeredKeys();
        foreach (MediaSettings::definitions() as $definition) {
            self::assertContains($definition->key, $layered, "{$definition->key} claims a tier the schema does not grant");
            self::assertNotContains($definition->key, SettingsSchema::projectTierKeys());
            self::assertContains(SettingSource::UserSettings, $definition->sources());
            self::assertNotContains(SettingSource::ProjectShared, $definition->sources());
        }
    }

    public function testBaseUrlResolvesDefaultThenUserSettingsThenEnv(): void
    {
        $definition = self::def('sd.baseUrl');

        $resolved = SettingsResolver::new()->resolve($definition);
        self::assertSame(SettingSource::Default, $resolved->source);
        self::assertNull($resolved->value);

        $resolved = SettingsResolver::new()
            ->withLayer(SettingSource::UserSettings, ['sd.baseUrl' => 'http://settings.example:7860'])
            ->resolve($definition);
        self::assertSame(SettingSource::UserSettings, $resolved->source);
        self::assertSame('http://settings.example:7860', $resolved->value);

        $resolved = SettingsResolver::new()
            ->withLayer(SettingSource::UserSettings, ['sd.baseUrl' => 'http://settings.example:7860'])
            ->withEnvironment(['SUGARCRUSH_SD_BASE_URL' => 'http://env.example:7860'])
            ->resolve($definition);
        self::assertSame(SettingSource::Env, $resolved->source);
        self::assertSame('http://env.example:7860', $resolved->value);
        self::assertTrue($resolved->locked);
        self::assertSame('set by SUGARCRUSH_SD_BASE_URL', $resolved->lockReason);
    }

    /** The real launch shape: Bootstrap snapshots `array_filter(getenv())`. */
    public function testTheEnvOverrideRidesTheProcessEnvironment(): void
    {
        putenv('SUGARCRUSH_MEDIA_RENDER_MODE=kitty');

        $resolved = SettingsResolver::new()
            ->withEnvironment(array_filter(getenv(), 'is_string'))
            ->resolve(self::def('ui.imageRenderMode'));

        self::assertSame(SettingSource::Env, $resolved->source);
        self::assertSame('kitty', $resolved->value);
    }

    public function testAnEmptyEnvValueIsSilentNotASetting(): void
    {
        $resolved = SettingsResolver::new()
            ->withEnvironment(['SUGARCRUSH_SD_BASE_URL' => ''])
            ->resolve(self::def('sd.baseUrl'));

        self::assertSame(SettingSource::Default, $resolved->source, 'empty env must not mask the layers below it');
    }

    public function testEnvMapCarriesBothBindings(): void
    {
        $envMap = SettingsSchema::envMap();
        self::assertSame('sd.baseUrl', $envMap['SUGARCRUSH_SD_BASE_URL'] ?? null);
        self::assertSame('ui.imageRenderMode', $envMap['SUGARCRUSH_MEDIA_RENDER_MODE'] ?? null);
    }

    public function testTheMapRowsTakeOpaqueMapsAndRefuseLists(): void
    {
        self::assertNull(self::def('sd.presets')->validate(['hd' => ['steps' => 30]]));
        self::assertNull(self::def('ui.mediaDisplayOverrides')->validate([]));
        self::assertNotNull(self::def('sd.presets')->validate(['a', 'b']), 'a list is not a map');
    }

    public function testTheProviderSchemaCarriesTheMediaOptionals(): void
    {
        $schemas = (new \ReflectionClassConstant(ProviderFactory::class, 'TYPE_SCHEMAS'))->getValue();

        foreach (['sglang', 'custom'] as $type) {
            foreach (['mediaKinds', 'mediaBaseUrl', 'mediaApiKey'] as $key) {
                self::assertContains($key, $schemas[$type]['optional'], "{$type} must accept {$key}");
            }
        }

        // Nothing else gained them, and no required list moved (regression:
        // absent-media blocks validate exactly as before this step).
        foreach (array_diff(array_keys($schemas), ['sglang', 'custom']) as $type) {
            self::assertNotContains('mediaKinds', $schemas[$type]['optional'], "{$type} gained a key the plan does not give it");
        }
        self::assertSame(['baseUrl', 'model'], $schemas['sglang']['required']);
        self::assertSame(['name', 'baseUrl', 'model'], $schemas['custom']['required']);
    }

    public function testCreateBuildsSglangAndCustomWithAndWithoutTheMediaKeys(): void
    {
        $factory = new ProviderFactory();

        $bare = $factory->create(['type' => 'sglang', 'baseUrl' => 'http://127.0.0.1:8000', 'model' => 'm']);
        $loaded = $factory->create([
            'type' => 'sglang',
            'baseUrl' => 'http://127.0.0.1:8000',
            'model' => 'm',
            'mediaKinds' => ['image', 'video'],
            'mediaBaseUrl' => 'http://127.0.0.1:7860',
            'mediaApiKey' => '${SUGARCRUSH_LANE_E_DEFINITELY_UNSET}',
        ]);
        self::assertSame($bare::class, $loaded::class);

        $custom = $factory->create([
            'type' => 'custom',
            'name' => 'e2e',
            'baseUrl' => 'http://127.0.0.1:8000',
            'model' => 'm',
            'mediaKinds' => 'video',
        ]);
        self::assertNotNull($custom);

        // Required-key posture unchanged by this step.
        $this->expectException(\RuntimeException::class);
        $factory->create(['type' => 'sglang', 'model' => 'm']);
    }

    public function testAnUnresolvedPlaceholderInMediaApiKeyHonestEmpties(): void
    {
        $factory = new ProviderFactory();
        // The same resolveEnv() create() runs over every string value, media
        // keys included: unset placeholder => empty string, never the literal.
        self::assertSame('', $factory->resolveEnv('${SUGARCRUSH_LANE_E_DEFINITELY_UNSET}'));
        self::assertSame(
            'http://x/?k=',
            $factory->resolveEnv('http://x/?k=${SUGARCRUSH_LANE_E_DEFINITELY_UNSET}'),
        );
    }

    public function testConfiguredMediaKindsIsTolerantAndCollapses(): void
    {
        self::assertSame(['image', 'video'], ProviderFactory::configuredMediaKinds(['image', 'video', 'image']));
        self::assertSame(['video'], ProviderFactory::configuredMediaKinds('video'));
        self::assertSame(['video', 'image'], ProviderFactory::configuredMediaKinds(['video', 'image', 'audio', 'image']));
        self::assertSame([], ProviderFactory::configuredMediaKinds(null));
        self::assertSame([], ProviderFactory::configuredMediaKinds('audio'));
        self::assertSame([], ProviderFactory::configuredMediaKinds(['IMAGE']));
    }

    public function testEveryMediaReaderSymbolResolves(): void
    {
        foreach (MediaSettings::definitions() as $definition) {
            self::assertNotNull($definition->readerSymbol, "{$definition->key} names no reader");
            [$class, $method] = explode('::', (string) $definition->readerSymbol, 2);
            self::assertTrue(class_exists($class), $class);
            self::assertTrue(method_exists($class, $method), "{$definition->readerSymbol} does not exist");
        }
    }
}

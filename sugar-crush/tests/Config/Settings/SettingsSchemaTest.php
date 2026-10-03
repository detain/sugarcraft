<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config\Settings;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Config\Settings\ApplyMode;
use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingCategory;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingDefinitionSet;
use SugarCraft\Crush\Config\Settings\SettingSource;
use SugarCraft\Crush\Config\Settings\SettingsSchema;
use SugarCraft\Crush\Config\Settings\SettingType;
use SugarCraft\Crush\Config\Settings\SettingValidator;
use SugarCraft\Crush\Config\Settings\UiEditability;
use SugarCraft\Crush\Config\Settings\Validator\AbsolutePathValidator;
use SugarCraft\Crush\Config\Settings\Validator\EnumValidator;
use SugarCraft\Crush\Config\Settings\Validator\ExecutableValidator;
use SugarCraft\Crush\Config\Settings\Validator\GlobListValidator;
use SugarCraft\Crush\Config\Settings\Validator\RangeValidator;
use SugarCraft\Crush\Config\Settings\Validator\ThresholdOrderValidator;
use SugarCraft\Crush\Config\Settings\Validator\UrlValidator;
use SugarCraft\Crush\Theme;

/**
 * The settings schema's invariants (Appendix N §4.2): every definition is
 * well-formed, agrees with the tier constants the merge actually filters on,
 * names a reader that exists, and cannot make a key project-settable whose
 * risk class the tier ceiling forbids.
 */
final class SettingsSchemaTest extends TestCase
{
    public function testEveryKeyIsDefinedOnce(): void
    {
        $keys = SettingsSchema::keys();

        self::assertSame($keys, array_values(array_unique($keys)));
        self::assertNotEmpty($keys);
    }

    /**
     * Phase 0 keeps the constants as the merge's source and asserts the schema
     * agrees, in both directions: a key layered without a schema row would
     * reach the generated docs undescribed, and a schema row claiming a tier
     * the merge does not grant would document a setting that is ignored.
     */
    public function testTheLayeredKeysAreExactlyLayeredSettingsLayeredKeys(): void
    {
        self::assertEqualsCanonicalizing(LayeredSettings::LAYERED_KEYS, SettingsSchema::layeredKeys());
    }

    public function testTheProjectTierKeysAreExactlyLayeredSettingsProjectTierKeys(): void
    {
        self::assertEqualsCanonicalizing(LayeredSettings::PROJECT_TIER_KEYS, SettingsSchema::projectTierKeys());
    }

    /**
     * The tier ceiling: the prose rule "no key whose meaningful direction is UP
     * belongs to a checked-out repository", made a predicate.
     */
    public function testNoProjectSettableKeyCarriesARiskClassAboveTheCeiling(): void
    {
        foreach (SettingsSchema::all() as $definition) {
            if (!$definition->projectSettable) {
                continue;
            }

            self::assertTrue(
                $definition->riskClass->projectSettableAllowed(),
                "{$definition->key} is project-settable but classed {$definition->riskClass->value}",
            );
            self::assertTrue($definition->layered, "{$definition->key} is project-settable but not layered — the merge would never read it");
        }
    }

    /** Preserves today's PROJECT_TIER_KEYS without a behaviour change (§4.2). */
    public function testParallelToolDeadlineSecondsIsTuning(): void
    {
        self::assertSame(RiskClass::Tuning, SettingsSchema::byKey('parallelToolDeadlineSeconds')?->riskClass);
    }

    public function testEveryReaderSymbolResolvesToARealMethod(): void
    {
        foreach (SettingsSchema::all() as $definition) {
            self::assertNotNull($definition->readerSymbol, "{$definition->key} names no reader");
            [$class, $method] = explode('::', (string) $definition->readerSymbol, 2);
            self::assertTrue(class_exists($class), "{$definition->key}: reader class {$class} does not exist");
            self::assertTrue(method_exists($class, $method), "{$definition->key}: {$definition->readerSymbol} does not exist");
        }
    }

    public function testEveryDefaultPassesItsOwnValidators(): void
    {
        foreach (SettingsSchema::all() as $definition) {
            self::assertNull(
                $definition->validate($definition->default),
                "{$definition->key}'s default fails its own validation",
            );
        }
    }

    public function testEveryEnumDefaultIsOneOfItsValues(): void
    {
        foreach (SettingsSchema::all() as $definition) {
            if ($definition->type !== SettingType::Enum) {
                continue;
            }

            self::assertNotEmpty($definition->enumValues, "{$definition->key} is an enum with no values");
            self::assertContains($definition->default, $definition->enumValues, "{$definition->key}'s default is not in its vocabulary");
        }
    }

    /** Defaults restated from a source constant must still equal it. */
    public function testDefaultsAgreeWithTheConstantsTheReadersFallBackTo(): void
    {
        self::assertSame(Theme::default()->name, SettingsSchema::byKey('theme')?->default);
        self::assertSame(Theme::names(), SettingsSchema::byKey('theme')?->enumValues);

        $mode = (new \ReflectionClassConstant(Bootstrap::class, 'DEFAULT_PERMISSION_MODE'))->getValue();
        self::assertSame($mode->value, SettingsSchema::byKey('permissionMode')?->default);
    }

    /**
     * The strict keys are exactly the pair `permissionSettingsLayer()` keeps —
     * the schema's claim that they are readable from `settings.json` rests on it.
     */
    public function testTheStrictKeysAreThePermissionSettingsKeys(): void
    {
        $strict = array_values(array_map(
            static fn (SettingDefinition $d): string => $d->key,
            array_filter(SettingsSchema::all(), static fn (SettingDefinition $d): bool => $d->strict),
        ));
        $live = (new \ReflectionClassConstant(Bootstrap::class, 'PERMISSION_SETTINGS_KEYS'))->getValue();

        self::assertEqualsCanonicalizing($live, $strict);
        foreach ($strict as $key) {
            self::assertNotContains($key, LayeredSettings::LAYERED_KEYS);
        }
    }

    /**
     * The four trust lists are answered by `config.json` alone and frozen per
     * process: no lower layer, no session overlay, no project file.
     */
    public function testTheTrustKeysComeFromTheWrittenConfigAloneAndAreFrozen(): void
    {
        foreach (['trustedProjectHooks', 'trustedProjectMcp', 'trustedProjectCommands', LayeredSettings::PROJECT_SETTINGS_TRUST_KEY] as $key) {
            $definition = SettingsSchema::byKey($key);
            self::assertNotNull($definition, "{$key} has no schema row");
            self::assertSame(ApplyMode::Frozen, $definition->applyMode);
            self::assertSame([SettingSource::Default, SettingSource::UserConfig], $definition->sources());
            self::assertSame(RiskClass::Security, $definition->riskClass);
        }
    }

    /**
     * Every environment variable the schema names is a row of
     * `docs/ENVIRONMENT.md`'s app table, so the schema cannot invent one.
     */
    public function testEveryNamedEnvVarIsDocumented(): void
    {
        $page = (string) file_get_contents(\dirname(__DIR__, 3) . '/docs/ENVIRONMENT.md');
        foreach (array_keys(SettingsSchema::envMap()) as $variable) {
            self::assertMatchesRegularExpression('/^\| `' . preg_quote($variable, '/') . '` \|/m', $page, "{$variable} has no ENVIRONMENT.md row");
        }
    }

    /** D7: literal English now, the i18n key kept for later. */
    public function testEveryDefinitionCarriesAnEnglishLabelHelpAndI18nKeys(): void
    {
        foreach (SettingsSchema::all() as $definition) {
            self::assertNotSame('', trim($definition->help), "{$definition->key} has no help text");
            self::assertNotSame($definition->key, $definition->label, "{$definition->key} has no label of its own");
            self::assertSame('settings.' . $definition->key . '.label', $definition->labelKey);
            self::assertSame('settings.' . $definition->key . '.help', $definition->helpKey);
        }

        foreach (SettingCategory::cases() as $category) {
            self::assertNotSame('', $category->label());
            self::assertSame('settings.category.' . $category->value, $category->labelKey());
        }
    }

    public function testAllIsInCategoryOrderAndInCategoryPartitionsIt(): void
    {
        $orders = array_map(static fn (SettingDefinition $d): int => $d->category->order(), SettingsSchema::all());
        $sorted = $orders;
        sort($sorted);
        self::assertSame($sorted, $orders);

        $count = 0;
        foreach (SettingCategory::cases() as $category) {
            foreach (SettingsSchema::inCategory($category) as $definition) {
                self::assertSame($category, $definition->category);
                ++$count;
            }
        }

        self::assertSame(\count(SettingsSchema::all()), $count);
    }

    public function testByKeyAndEnvMap(): void
    {
        self::assertSame('provider', SettingsSchema::byKey('provider')?->key);
        self::assertNull(SettingsSchema::byKey('model'), 'nothing reads a top-level model key');
        self::assertSame('parallelToolCalls', SettingsSchema::envMap()['SUGARCRUSH_DISABLE_PARALLEL_TOOL_CALLS'] ?? null);
        self::assertSame('permissionMode', SettingsSchema::envMap()['SUGARCRUSH_PERMISSION_MODE'] ?? null);
        self::assertSame('--permission-mode', SettingsSchema::byKey('permissionMode')?->cliFlag);
    }

    /**
     * DH-KEYS: one definitions file per category under `Definitions/`, every
     * file listed on {@see SettingsSchema::DEFINITION_SETS}, no category split
     * across two files and no set empty.
     */
    public function testEveryDefinitionsFileIsListedOnceAndOwnsOneCategory(): void
    {
        $dir = \dirname(__DIR__, 3) . '/src/Config/Settings/Definitions';
        $files = array_map(
            static fn (string $path): string => 'SugarCraft\\Crush\\Config\\Settings\\Definitions\\' . basename($path, '.php'),
            glob($dir . '/*.php') ?: [],
        );

        self::assertEqualsCanonicalizing($files, SettingsSchema::DEFINITION_SETS);

        $categories = [];
        foreach (SettingsSchema::DEFINITION_SETS as $set) {
            self::assertTrue(is_subclass_of($set, SettingDefinitionSet::class), "{$set} is not a SettingDefinitionSet");
            self::assertNotEmpty($set::definitions(), "{$set} defines no keys");
            $categories[] = $set::category()->value;
            foreach ($set::definitions() as $definition) {
                self::assertSame($set::category(), $definition->category, "{$definition->key} is filed in {$set}");
            }
        }

        self::assertSame($categories, array_values(array_unique($categories)), 'a category is split across two files');
    }

    // -------------------------------------------------------------------------
    // SettingDefinition
    // -------------------------------------------------------------------------

    /** A definition that forgot to classify itself cannot widen a tier. */
    public function testANewDefinitionIsUserConfigOnlyAndClassedSecurity(): void
    {
        $definition = SettingDefinition::new('example', SettingType::Int, 3);

        self::assertFalse($definition->projectSettable);
        self::assertFalse($definition->layered);
        self::assertFalse($definition->strict);
        self::assertSame(RiskClass::Security, $definition->riskClass);
        self::assertSame(ApplyMode::Restart, $definition->applyMode);
        self::assertSame(UiEditability::Easy, $definition->ui);
        self::assertSame([SettingSource::Default, SettingSource::UserConfig, SettingSource::Session], $definition->sources());
        self::assertSame('—', $definition->readByText());
    }

    public function testWithersReturnNewInstancesAndLeaveTheOriginalAlone(): void
    {
        $original = SettingDefinition::new('example', SettingType::Bool, true);
        $changed = $original
            ->withCategory(SettingCategory::Tools)
            ->withRiskClass(RiskClass::Narrowing)
            ->withLayered()
            ->withProjectSettable()
            ->withApplyMode(ApplyMode::NextTurn)
            ->withEnvVar('EXAMPLE_ENV')
            ->withCliFlag('--example')
            ->withUi(UiEditability::List)
            ->withLabel('Example')
            ->withLabelKey('x.label')
            ->withHelp('Help.')
            ->withHelpKey('x.help')
            ->withDocAnchor('SETTINGS.md#example')
            ->withReaderSymbol(Bootstrap::class . '::chat')
            ->withReadBy('`custom`');

        self::assertNotSame($original, $changed);
        self::assertSame(SettingCategory::Advanced, $original->category);
        self::assertSame(SettingCategory::Tools, $changed->category);
        self::assertSame(RiskClass::Narrowing, $changed->riskClass);
        self::assertTrue($changed->layered);
        self::assertTrue($changed->projectSettable);
        self::assertSame(ApplyMode::NextTurn, $changed->applyMode);
        self::assertSame('EXAMPLE_ENV', $changed->envVar);
        self::assertSame('--example', $changed->cliFlag);
        self::assertSame('Example', $changed->label);
        self::assertSame('x.label', $changed->labelKey);
        self::assertSame('Help.', $changed->help);
        self::assertSame('x.help', $changed->helpKey);
        self::assertSame('SETTINGS.md#example', $changed->docAnchor);
        self::assertSame('Bootstrap::chat()', $changed->readerShort());
        self::assertSame('`custom`', $changed->readByText());
        self::assertSame('`Bootstrap::chat()`', $changed->withReadBy(null)->readByText());
        self::assertSame(
            [
                SettingSource::Default,
                SettingSource::ProjectShared,
                SettingSource::ProjectLocal,
                SettingSource::UserSettings,
                SettingSource::UserConfig,
                SettingSource::Session,
                SettingSource::Env,
                SettingSource::Flag,
            ],
            $changed->sources(),
        );
    }

    public function testAKeyThatIsNotACamelCaseNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SettingDefinition::new('Not A Key', SettingType::String);
    }

    public function testAReaderSymbolMustBeClassColonColonMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SettingDefinition::new('example', SettingType::String)->withReaderSymbol('justAFunction');
    }

    public function testValidateChecksTheShapeTheTypeImplies(): void
    {
        self::assertSame('must be a bool', SettingDefinition::new('a', SettingType::Bool)->validate('yes'));
        self::assertSame('must be a string', SettingDefinition::new('a', SettingType::String)->validate(3));
        self::assertSame('must be a object', SettingDefinition::new('a', SettingType::Map)->validate(['x']));
        self::assertSame('must be a whole number', SettingDefinition::new('a', SettingType::Int)->validate('90'));
        self::assertSame('must be at least 1', SettingDefinition::new('a', SettingType::Int)->withRange(1)->validate(0));
        self::assertSame('must be a list', SettingDefinition::new('a', SettingType::StringList)->validate(['k' => 'v']));
        self::assertNull(SettingDefinition::new('a', SettingType::Map)->validate([]));
        self::assertNull(SettingDefinition::new('a', SettingType::Int)->withRange(1)->validate(null), 'null is unset, and unset always passes');
    }

    // -------------------------------------------------------------------------
    // Validators
    // -------------------------------------------------------------------------

    /** @return iterable<string, array{SettingValidator, mixed, bool}> */
    public static function validatorCases(): iterable
    {
        yield 'range in' => [RangeValidator::new(1, 10), 5, true];
        yield 'range low' => [RangeValidator::new(1, 10), 0, false];
        yield 'range high' => [RangeValidator::new(1, 10), 11, false];
        yield 'range float refused when integer' => [RangeValidator::new(1), 1.5, false];
        yield 'range float accepted' => [RangeValidator::new(0, 2, integer: false), 0.7, true];
        yield 'range numeric string refused' => [RangeValidator::new(), '5', false];
        yield 'enum member' => [EnumValidator::new(['a', 'b']), 'b', true];
        yield 'enum case-sensitive' => [EnumValidator::new(['a', 'b']), 'B', false];
        yield 'absolute path' => [AbsolutePathValidator::new(), '/srv/repo', true];
        yield 'home path' => [AbsolutePathValidator::new(), '~/src/repo', true];
        yield 'relative path refused' => [AbsolutePathValidator::new(), '.', false];
        yield 'path list with a relative entry refused' => [AbsolutePathValidator::new(), ['/a', 'b'], false];
        yield 'executable' => [ExecutableValidator::new(), \PHP_BINARY, true];
        yield 'executable relative refused' => [ExecutableValidator::new(), 'php', false];
        yield 'executable missing refused' => [ExecutableValidator::new(), '/nonexistent/sugarcrush-binary', false];
        yield 'url https' => [UrlValidator::new(), 'https://searx.example.org/search', true];
        yield 'url ftp refused' => [UrlValidator::new(), 'ftp://example.org', false];
        yield 'url garbage refused' => [UrlValidator::new(), 'not a url', false];
        yield 'glob list' => [GlobListValidator::new(), ['mcp__git__*', 'Bash'], true];
        yield 'glob list empty entry refused' => [GlobListValidator::new(), ['Bash', ' '], false];
        yield 'glob list object refused' => [GlobListValidator::new(), ['a' => 'Bash'], false];
    }

    #[DataProvider('validatorCases')]
    public function testValidators(SettingValidator $validator, mixed $value, bool $passes): void
    {
        self::assertSame($passes, $validator->validate($value) === null);
        self::assertNull($validator->validate(null), 'null is unset and always passes');
    }

    public function testThresholdOrderValidatorJudgesOnlyTheKeysThatAreSet(): void
    {
        $order = ThresholdOrderValidator::new(['reminder', 'auto', 'block']);

        self::assertNull($order->validate(null, ['reminder' => 70, 'auto' => 85, 'block' => 95]));
        self::assertNull($order->validate(null, ['reminder' => 70, 'block' => 95]));
        self::assertSame('block must be greater than auto', $order->validate(null, ['auto' => 95, 'block' => 90]));
        self::assertSame('auto must be greater than reminder', $order->validate(null, ['reminder' => 80, 'auto' => 80]));
    }

    /** @return iterable<string, array{\Closure}> */
    public static function nonsenseValidators(): iterable
    {
        yield 'inverted range' => [static fn () => RangeValidator::new(10, 1)];
        yield 'empty vocabulary' => [static fn () => EnumValidator::new([])];
        yield 'one-key order' => [static fn () => ThresholdOrderValidator::new(['only'])];
    }

    #[DataProvider('nonsenseValidators')]
    public function testValidatorConstructorsRefuseNonsense(\Closure $build): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $build();
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui\Settings;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Config\Settings\OptionsProvider;
use SugarCraft\Crush\Config\Settings\OptionsSource;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingsSchema;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\Settings\SettingsEditor;
use SugarCraft\Crush\Tui\Settings\SettingsFieldFactory;
use SugarCraft\Crush\Tui\Settings\SettingsSources;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\Select;

/**
 * Each editable setting becomes the candy-forms field that edits it, and the
 * field's answer comes back as a value of the key's type (N-P2).
 */
final class SettingsFieldFactoryTest extends TestCase
{
    private static function def(string $key): SettingDefinition
    {
        return SettingsSchema::byKey($key) ?? throw new \LogicException($key);
    }

    public function testEachTypeGetsItsField(): void
    {
        $options = OptionsProvider::new();

        self::assertInstanceOf(Confirm::class, SettingsFieldFactory::field(self::def('parallelToolCalls'), true, $options));
        self::assertInstanceOf(Select::class, SettingsFieldFactory::field(self::def('theme'), 'dracula', $options));
        self::assertInstanceOf(Select::class, SettingsFieldFactory::field(self::def('permissionMode'), 'plan', $options));
        self::assertInstanceOf(Input::class, SettingsFieldFactory::field(self::def('maxToolSteps'), 40, $options));
        self::assertInstanceOf(Input::class, SettingsFieldFactory::field(self::def('disabledTools'), ['WebSearch'], $options));
    }

    public function testReadOnlyTrustAndStrictlyParsedKeysGetNoInlineField(): void
    {
        $options = OptionsProvider::new();

        foreach (['permissionRules', 'layout', 'trustedProjectSettings', 'claudeMcpBinary', 'claudeMcpEnv'] as $key) {
            self::assertNull(SettingsFieldFactory::field(self::def($key), null, $options), $key);
            self::assertNotNull(SettingsFieldFactory::whyNotEditable(self::def($key)), $key);
        }
    }

    /** N-P5: a complex map is edited as one JSON object, and only an object comes back. */
    public function testAComplexMapIsEditedAsOneJsonObject(): void
    {
        $options = OptionsProvider::new();
        $prices = ['gpt-x' => ['input' => 3, 'output' => 15]];

        foreach (['modelPrices', 'statusLine', 'lintCommands', 'lsp', 'attribution', 'extraBody', 'compaction.modelTokenCaps'] as $key) {
            self::assertInstanceOf(Input::class, SettingsFieldFactory::field(self::def($key), null, $options), $key);
            self::assertNull(SettingsFieldFactory::whyNotEditable(self::def($key)), $key);
        }

        $field = SettingsFieldFactory::field(self::def('modelPrices'), $prices, $options);
        self::assertSame('{"gpt-x":{"input":3,"output":15}}', $field?->value());
        self::assertSame(
            ['gpt-x' => ['input' => 3, 'output' => 15], 'gpt-y' => ['input' => 1, 'output' => 2]],
            SettingsFieldFactory::value(self::def('modelPrices'), Input::new('modelPrices')->withValue(
                '{"gpt-x":{"input":3,"output":15},"gpt-y":{"input":1,"output":2}}',
            )),
        );

        foreach (['[1, 2]', 'not json', '{}', '"text"'] as $text) {
            try {
                SettingsFieldFactory::value(self::def('modelPrices'), Input::new('modelPrices')->withValue($text));
                self::fail("{$text} was taken for a map");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTextComesBackAsTheKeysType(): void
    {
        $options = OptionsProvider::new();

        $steps = SettingsFieldFactory::field(self::def('maxToolSteps'), 40, $options);
        self::assertSame('40', $steps->value());
        self::assertSame(40, SettingsFieldFactory::value(self::def('maxToolSteps'), $steps));

        $tools = SettingsFieldFactory::field(self::def('disabledTools'), ['WebSearch', 'Lsp'], $options);
        self::assertSame('WebSearch, Lsp', $tools->value());
        self::assertSame(['WebSearch', 'Lsp'], SettingsFieldFactory::value(self::def('disabledTools'), $tools));

        $this->expectException(\InvalidArgumentException::class);
        SettingsFieldFactory::value(self::def('maxToolSteps'), Input::new('maxToolSteps')->withValue('lots'));
    }

    public function testAContextWindowIsANumberOrAMap(): void
    {
        self::assertSame(200000, SettingsFieldFactory::value(self::def('contextWindow'), Input::new('contextWindow')->withValue('200000')));
        self::assertSame(['gpt-5' => 400000], SettingsFieldFactory::value(self::def('contextWindow'), Input::new('contextWindow')->withValue('{"gpt-5": 400000}')));
    }

    /** D9: the model field edits the active provider's entry and keeps the others. */
    public function testTheModelFieldEditsTheActiveProvidersEntryOnly(): void
    {
        $current = ['openai' => 'gpt-4o', 'sglang' => 'qwen'];
        $field = SettingsFieldFactory::field(self::def('models'), $current, OptionsProvider::new(), 'openai');

        self::assertInstanceOf(Input::class, $field);
        self::assertSame('gpt-4o', $field->value());
        self::assertSame(['openai' => 'gpt-5', 'sglang' => 'qwen'], SettingsFieldFactory::value(self::def('models'), $field->withValue('gpt-5'), $current, 'openai'));
        self::assertSame(['sglang' => 'qwen'], SettingsFieldFactory::value(self::def('models'), $field->withValue(''), $current, 'openai'), 'empty = provider default');
    }

    /** N-P5: `<provider>=<model id>` reaches any provider's entry, active or not. */
    public function testTheModelFieldNamesAnyProviderWithProviderEqualsModel(): void
    {
        $current = ['openai' => 'gpt-4o', 'sglang' => 'qwen'];
        $models = self::def('models');
        $field = SettingsFieldFactory::field($models, $current, OptionsProvider::new(), 'openai');

        self::assertSame(
            ['openai' => 'gpt-4o', 'sglang' => 'qwen3'],
            SettingsFieldFactory::value($models, $field->withValue('sglang=qwen3'), $current, 'openai'),
        );
        self::assertSame(
            ['openai' => 'gpt-4o', 'sglang' => 'qwen', 'anthropic' => 'claude-x'],
            SettingsFieldFactory::value($models, $field->withValue('anthropic = claude-x'), $current, 'openai'),
        );
        self::assertSame(['openai' => 'gpt-4o'], SettingsFieldFactory::value($models, $field->withValue('sglang='), $current, 'openai'), '<provider>= clears it');

        // With no provider resolved there is no "active" entry: the field
        // opens empty and asks for the provider by name.
        $unnamed = SettingsFieldFactory::field($models, $current, OptionsProvider::new());
        self::assertInstanceOf(Input::class, $unnamed);
        self::assertSame('', $unnamed->value());
        self::assertSame(['openai' => 'gpt-5', 'sglang' => 'qwen'], SettingsFieldFactory::value($models, $unnamed->withValue('openai=gpt-5'), $current));
        $this->expectException(\InvalidArgumentException::class);
        SettingsFieldFactory::value($models, $unnamed->withValue('gpt-5'), $current);
    }

    public function testOptionsComeFromTheDefinitionThenItsSource(): void
    {
        $options = OptionsProvider::new()->withList(OptionsSource::Providers, ['openai', 'dev-sglang', 'openai']);

        self::assertSame(Theme::names(), $options->for(self::def('theme')));
        self::assertSame(array_map(static fn (PermissionMode $m): string => $m->value, PermissionMode::cases()), $options->options(OptionsSource::PermissionModes));
        self::assertSame(['openai', 'dev-sglang'], $options->for(self::def('provider')));
        self::assertSame([], OptionsProvider::new()->options(OptionsSource::Skills));
    }

    // ── the editor's edit API ───────────────────────────────────────────

    public function testTheEditorEditsAFieldAndStagesItsValue(): void
    {
        $editor = SettingsEditor::open(SettingsSources::fromLaunch(null, null, null, null, []), 'max tool steps');
        $editor = $editor->beginEdit();
        self::assertNotNull($editor->editing);

        foreach (str_split('12') as $char) {
            $editor = $editor->editKey(new KeyMsg(KeyType::Char, $char));
        }

        self::assertStringContainsString('12', $editor->view(Theme::default(), 100, 20));
        $editor = $editor->commitEdit();
        self::assertNull($editor->editing);
        self::assertSame(['maxToolSteps' => 12], $editor->set);
    }

    public function testBadTextIsNotStagedAndTheFieldStaysOpen(): void
    {
        $editor = SettingsEditor::open(SettingsSources::fromLaunch(null, null, null, null, []), 'max tool steps')->beginEdit();
        $editor = $editor->editKey(new KeyMsg(KeyType::Char, 'x'))->commitEdit();

        self::assertNotNull($editor->editing);
        self::assertSame([], $editor->set);
        self::assertStringStartsWith('Not staged:', (string) $editor->status);
        self::assertNull($editor->cancelEdit()->editing);
    }

    public function testAKeyWithNoInlineFieldSaysSo(): void
    {
        $editor = SettingsEditor::open(SettingsSources::fromLaunch(null, null, null, null, []), 'permission rules')->beginEdit();

        self::assertNull($editor->editing);
        self::assertSame(SettingsFieldFactory::whyNotEditable(self::def('permissionRules')), $editor->status);
        self::assertStringContainsString('edited by hand', (string) $editor->status, 'it says where the key IS changed');
    }

    public function testTheModelEntryFollowsTheResolvedProvider(): void
    {
        $dir = sys_get_temp_dir() . '/crush-field-' . bin2hex(random_bytes(4));
        mkdir($dir . '/' . LayeredSettings::dir(), 0o700, true);
        $config = $dir . '/' . LayeredSettings::dir() . '/config.json';
        file_put_contents($config, (string) json_encode(['provider' => 'sglang', 'models' => ['sglang' => 'qwen']]));

        try {
            $editor = SettingsEditor::open(SettingsSources::fromLaunch(null, null, null, $config, []), 'model');
            // "model" also matches the other model keys (summary, sub-agent,
            // embedding); walk the filtered rows to the `models` entry itself.
            for ($i = 0; $i < \count($editor->rows()) && ($editor->selected()?->key ?? null) !== 'models'; $i++) {
                $editor = $editor->update(new KeyMsg(KeyType::Down)) ?? $editor;
            }
            self::assertSame('models', $editor->selected()?->key);
            $editor = $editor->beginEdit();
            self::assertNotNull($editor->editing);
            self::assertSame('qwen', $editor->editing->value());
        } finally {
            exec('rm -rf ' . escapeshellarg($dir) . ' 2>&1', $out);
        }
    }
}

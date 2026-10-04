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

    public function testComplexReadOnlyAndTrustKeysGetNoInlineField(): void
    {
        $options = OptionsProvider::new();

        foreach (['permissionRules', 'layout', 'statusLine', 'trustedProjectSettings', 'claudeMcpBinary'] as $key) {
            self::assertNull(SettingsFieldFactory::field(self::def($key), null, $options), $key);
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
        self::assertNull(SettingsFieldFactory::field(self::def('models'), $current, OptionsProvider::new()), 'no provider, no entry to edit');
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
        self::assertSame('Permission rules is not edited here', $editor->status);
    }

    public function testTheModelEntryFollowsTheResolvedProvider(): void
    {
        $dir = sys_get_temp_dir() . '/crush-field-' . bin2hex(random_bytes(4));
        mkdir($dir . '/' . LayeredSettings::dir(), 0o700, true);
        $config = $dir . '/' . LayeredSettings::dir() . '/config.json';
        file_put_contents($config, (string) json_encode(['provider' => 'sglang', 'models' => ['sglang' => 'qwen']]));

        try {
            $editor = SettingsEditor::open(SettingsSources::fromLaunch(null, null, null, $config, []), 'model')->beginEdit();
            self::assertNotNull($editor->editing);
            self::assertSame('qwen', $editor->editing->value());
        } finally {
            exec('rm -rf ' . escapeshellarg($dir) . ' 2>&1', $out);
        }
    }
}

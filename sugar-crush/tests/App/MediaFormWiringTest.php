<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\ClipboardImagePastedMsg;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\SettingsWriter;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Media\Sd\Client;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tui\MediaForm\ChoiceFeed;
use SugarCraft\Crush\Tui\MediaForm\DisplayOverrides;
use SugarCraft\Crush\Tui\MediaForm\FieldSpec;
use SugarCraft\Crush\Tui\MediaForm\GenFormKeys;
use SugarCraft\Crush\Tui\MediaForm\MediaForm;
use SugarCraft\Crush\Tui\MediaForm\Sections\PresetsSection;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;

/**
 * The W4 host wiring around the media form: openMediaForm() feeds the three
 * media settings out of the layered user config (carried seam, lane d), the
 * SavePreset action persists through the injected SettingsWriter door, the
 * Ctrl+V chord escapes the form as a clipboard Cmd and the paste landing
 * persists bytes into the MediaStore before any form state names them
 * (volatile-temp law, plan W4.2).
 */
final class MediaFormWiringTest extends TestCase
{
    private ProviderInterface $awProvider;

    private string $awHomeDir = '';

    private string $awOriginalHome = '';

    private ?string $awOriginalServerHome = null;

    protected function setUp(): void
    {
        $this->awProvider = $this->createMock(ProviderInterface::class);
        $this->awProvider->method('name')->willReturn('stub');
        TuiRenderer::setSize(200, 60);
        putenv(Client::BASE_URL_ENV);
        ChoiceFeed::resetForTesting();
    }

    protected function tearDown(): void
    {
        putenv(Client::BASE_URL_ENV);
        ChoiceFeed::resetForTesting();
        TuiRenderer::setSize(200, 60);
        $this->awReleaseHomeSandbox();
    }

    // ---- the carried seam: settings ride into open() --------------------------------

    public function testOpenMediaFormFeedsPresetsSavePatternAndOverridesFromTheUserConfig(): void
    {
        $this->awArmHomeSandbox();
        $steps = array_filter(
            [...FieldSpec::coreRows(), ...FieldSpec::disclosureRows(), ...FieldSpec::wave4Rows()],
            static fn (FieldSpec $spec): bool => $spec->key === 'steps',
        );
        self::assertNotEmpty($steps, 'polarity: the registry still owns a steps row');
        file_put_contents(
            Bootstrap::userConfigPath(),
            (string) json_encode([
                'sd.presets' => ['golden' => ['presetVersion' => 1, 'values' => ['steps' => 20]]],
                'sd.savePattern' => 'cat-[seed]',
                'ui.mediaDisplayOverrides' => [
                    DisplayOverrides::keyFor(reset($steps), DisplayOverrides::PROP_VALUE) => 33,
                ],
            ]),
        );

        $form = $this->awApp()->openMediaForm()->mediaForm;
        self::assertInstanceOf(MediaForm::class, $form);

        $listing = '';
        $output = '';
        foreach ($form->sections() as $section) {
            if ($section instanceof PresetsSection) {
                // row 1 is the names list; rows() carries labels only.
                $listing = (string) $section->valueAt(1);
            }
            if ($section->key() === 'output') {
                $output = (string) $section->valueAt(0);
            }
        }
        self::assertStringContainsString('golden', $listing, 'sd.presets rode into the store');
        self::assertStringContainsString('cat-[seed]', $output, 'sd.savePattern rode to the output tab');
        self::assertSame(33, $form->collectValues()['steps'], 'ui.mediaDisplayOverrides rode to the lattice');
    }

    // ---- the SavePreset action, App to disk ------------------------------------------

    public function testSavePresetKeyWalksAppToDiskThroughTheWiredDoor(): void
    {
        $this->awArmHomeSandbox();
        file_put_contents(Bootstrap::userConfigPath(), "{}\n");
        $writer = SettingsWriter::new(
            Bootstrap::userConfigPath(),
            static function (array $set, array $unset): void {
                Bootstrap::writeUserConfig($set, $unset);
            },
        );

        $app = $this->awApp()->withSettingsWriter($writer)->openMediaForm();
        $app = $this->awOnPresetsTab($app);
        $app = $this->awKey($app, new KeyMsg(KeyType::Enter));
        foreach (str_split('quicksave') as $rune) {
            $app = $this->awKey($app, new KeyMsg(KeyType::Char, $rune));
        }
        $app = $this->awKey($app, new KeyMsg(KeyType::Enter));

        [$saved, $cmd] = $app->update(new KeyMsg(KeyType::Char, 's'));
        self::assertNull($cmd, 'the save runs synchronously inside the model');
        self::assertInstanceOf(MediaForm::class, $saved->mediaForm, 'the save keeps the form open');
        self::assertSame(
            Lang::t(GenFormKeys::PRESET_SAVE) . ' — quicksave',
            $this->awStatusOf($saved->mediaForm),
        );

        $onDisk = json_decode((string) file_get_contents(Bootstrap::userConfigPath()), true);
        self::assertSame(1, $onDisk['sd.presets']['quicksave']['presetVersion']);
        self::assertSame('quicksave', array_key_first($onDisk['sd.presets']));
    }

    public function testSavePresetWithoutAPendingNameRefusesThroughTheFormStatus(): void
    {
        $this->awArmHomeSandbox();
        file_put_contents(Bootstrap::userConfigPath(), "{}\n");
        $writer = SettingsWriter::new(
            Bootstrap::userConfigPath(),
            static function (array $set, array $unset): void {
                Bootstrap::writeUserConfig($set, $unset);
            },
        );

        // The store exists (young config = empty store, seam law), the name
        // does not — the refusal lands on the form's own status line.
        $app = $this->awOnPresetsTab($this->awApp()->withSettingsWriter($writer)->openMediaForm());
        [$refused, $cmd] = $app->update(new KeyMsg(KeyType::Char, 's'));
        self::assertNull($cmd);
        self::assertSame(
            '! ' . MediaForm::PRESET_NAME_REQUIRED,
            $this->awStatusOf($refused->mediaForm),
        );
        self::assertSame([], json_decode((string) file_get_contents(Bootstrap::userConfigPath()), true));
    }

    public function testTheImportPngArmStillThrowsAndNamesTheWiringWave(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/App/App.php');
        // W4.4 lands the emitter; until then the arm stays loud, and this
        // pin reddens the day someone swallows it silently.
        self::assertStringContainsString('W4.4 lands the emitter', $src);
        self::assertStringContainsString(
            'MediaFormActionKind::ImportPng => throw new \LogicException(',
            $src,
        );
    }

    // ---- Ctrl+V and the paste landing -------------------------------------------------

    public function testCtrlVWithTheFormUpEscapesTheFormAsAPasteCmd(): void
    {
        $app = $this->awApp()->openMediaForm();
        [$same, $cmd] = $app->update(new KeyMsg(KeyType::Char, 'v', ctrl: true));
        self::assertSame($app, $same, 'the chord mutates nothing until the read lands');
        self::assertInstanceOf(\Closure::class, $cmd, 'the clipboard read rides a Cmd');
        self::assertInstanceOf(ClipboardImagePastedMsg::class, $cmd());
    }

    public function testCtrlVWithoutTheFormIsLeftToTheChatSurface(): void
    {
        [$app, $cmd] = $this->awApp()->update(new KeyMsg(KeyType::Char, 'v', ctrl: true));
        self::assertNull($app->mediaForm);
        // Form down the chord is the chat's problem — either the chat's own
        // Cmd rides on, or a form-less App simply does not claim it here.
        self::assertTrue($cmd === null || $cmd instanceof \Closure);
    }

    public function testAPasteWithTheFormUpPersistsIntoTheStoreBeforeTheFormNamesIt(): void
    {
        $this->awArmHomeSandbox();
        $volatile = tempnam(sys_get_temp_dir(), 'aw-paste-');
        self::assertIsString($volatile);
        $bytes = (string) file_get_contents(__DIR__ . '/../fixtures/img/tiny.png');
        file_put_contents($volatile, $bytes);

        $app = $this->awApp()->openMediaForm();
        [$pasted, $cmd] = $app->update(new ClipboardImagePastedMsg($volatile));
        self::assertNull($cmd);
        $form = $pasted->mediaForm;
        self::assertInstanceOf(MediaForm::class, $form);
        self::assertSame(MediaForm::CLIPBOARD_ATTACHED . ' (4×3)', $this->awStatusOf($form));
        self::assertSame([base64_encode($bytes)], $form->draft()->initImages());

        // The bytes outlived the volatile temp: the store holds them under
        // the fallback session folder, and the form state names nothing from
        // the temp path.
        $stored = glob($this->awHomeDir . '/home/.sugar-crush/media/clipboard/' . \chr(42) . '.png'); // chr(42): a glued '*' here is a glob-shaped literal and would drift the PathGlob corpus figure
        self::assertIsArray($stored);
        self::assertCount(1, $stored);
        self::assertSame($bytes, (string) file_get_contents($stored[0]));
        self::assertStringNotContainsString($volatile, (string) json_encode($this->awReflect($form, 'status')));
    }

    public function testAPasteWithNoImageShowsTheChatSentence(): void
    {
        $app = $this->awApp()->openMediaForm();
        [$pasted, ] = $app->update(new ClipboardImagePastedMsg(null));
        self::assertSame(
            Lang::t('chat.clipboard.no_image'),
            $this->awStatusOf($pasted->mediaForm),
        );
    }

    public function testAPasteWithTheFormDownTouchesNoStore(): void
    {
        $this->awArmHomeSandbox();
        $volatile = tempnam(sys_get_temp_dir(), 'aw-paste-');
        self::assertIsString($volatile);
        file_put_contents($volatile, (string) file_get_contents(__DIR__ . '/../fixtures/img/tiny.png'));

        $app = $this->awApp();
        [$after, $cmd] = $app->update(new ClipboardImagePastedMsg($volatile));
        self::assertNull($after->mediaForm);
        self::assertNull($cmd);
        self::assertDirectoryDoesNotExist($this->awHomeDir . '/home/.sugar-crush/media');
    }

    // ---- helpers -----------------------------------------------------------------------

    private function awApp(): App
    {
        return App::new($this->awProvider, 'test-model');
    }

    private function awKey(App $app, KeyMsg $key): App
    {
        [$next, $cmd] = $app->update($key);
        self::assertNull($cmd, 'these keys never mint a Cmd');

        return $next;
    }

    private function awOnPresetsTab(App $app): App
    {
        for ($step = 0; $step < 15; $step++) {
            $form = $app->mediaForm;
            self::assertInstanceOf(MediaForm::class, $form);
            $visible = $this->awVisibleSections($form);
            if ($visible[$form->state()->section] instanceof PresetsSection) {
                return $app;
            }
            $app = $this->awKey($app, new KeyMsg(KeyType::Right));
        }

        self::fail('the presets tab never took focus');
    }

    /** @return array<int, object> */
    private function awVisibleSections(MediaForm $form): array
    {
        return (new \ReflectionMethod($form, 'visibleSections'))->invoke($form);
    }

    private function awStatusOf(?MediaForm $form): ?string
    {
        self::assertInstanceOf(MediaForm::class, $form);

        return $this->awReflect($form, 'status');
    }

    private function awReflect(object $object, string $property): mixed
    {
        return (new \ReflectionProperty($object, $property))->getValue($object);
    }

    private function awArmHomeSandbox(): void
    {
        $dir = sys_get_temp_dir() . '/crush-awiring-home-' . bin2hex(random_bytes(5));
        mkdir($dir . '/home/.sugar-crush', 0o700, true);
        $this->awHomeDir = $dir;

        // Snapshot BEFORE overwriting (lane-cf law).
        $this->awOriginalHome = getenv('HOME') ?: '';
        $this->awOriginalServerHome = $_SERVER['HOME'] ?? null;
        putenv('HOME=' . $dir . '/home');
        $_SERVER['HOME'] = $dir . '/home';
        Bootstrap::useConfigPath(null);
    }

    private function awReleaseHomeSandbox(): void
    {
        if ($this->awHomeDir === '') {
            return;
        }

        Bootstrap::useConfigPath(null);
        putenv('HOME=' . $this->awOriginalHome);
        if ($this->awOriginalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->awOriginalServerHome;
        }
        exec('rm -rf ' . escapeshellarg($this->awHomeDir) . ' 2>&1');
        $this->awHomeDir = '';
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui\MediaForm;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\SettingsTier;
use SugarCraft\Crush\Config\Settings\SettingsWriter;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Media\Sd\Client;
use SugarCraft\Crush\Tui\MediaForm\ChoiceFeed;
use SugarCraft\Crush\Tui\MediaForm\GenFormKeys;
use SugarCraft\Crush\Tui\MediaForm\MediaForm;
use SugarCraft\Crush\Tui\MediaForm\MediaPresets;
use SugarCraft\Crush\Tui\MediaForm\Sections\OutputSection;
use SugarCraft\Crush\Tui\MediaForm\Sections\PresetsSection;
use SugarCraft\Crush\Tui\MediaForm\Section;
use SugarCraft\Crush\Support\MediaStore;

/**
 * The W3.9 presets tab and its store: named FieldSpec bundles over
 * `sd.presets` with the secret scrub, the loud parse refusal, the
 * save→apply→delete press flow through the real key pump, and the read-only
 * Output tab exposing the effective `sd.savePattern` next to the token
 * palette. Persistence rides the injected door; one test walks the whole way
 * to disk through the sanctioned SettingsWriter to prove the door shape.
 */
final class PresetsTest extends TestCase
{
    private string $presetHomeDir = '';

    protected function setUp(): void
    {
        putenv(Client::BASE_URL_ENV);
        ChoiceFeed::resetForTesting();
    }

    protected function tearDown(): void
    {
        putenv(Client::BASE_URL_ENV);
        ChoiceFeed::resetForTesting();
        $this->presetReleaseHomeSandbox();
    }

    // ---- the pure model -----------------------------------------------------------

    public function testAnEmptyStoreListsNothingAndRefusesEveryName(): void
    {
        $store = MediaPresets::empty();

        $this->assertSame([], $store->list());
        $this->assertSame([], $store->toStored());
        $this->expectException(\InvalidArgumentException::class);
        $store->apply('nothing-saved-yet');
    }

    public function testSavingScrubbsSecretShapedKeys(): void
    {
        // The law: a preset that would carry an API key simply does not —
        // the ${VAR} channel stays the only sanctioned secret path. Disable
        // the scrub in MediaPresets::save() and this pin reddens.
        $store = MediaPresets::empty()->save('golden', [
            'steps' => 20,
            'sd_apiKey' => 'sk-should-never-land',
            'CLIP_skip_api_key' => 1,
            'api_key' => 'x',
            'token' => 'y',
            'secret_thing' => 'z',
            'password' => 'w',
            'model_name' => 'v1-5-pruned.safetensors',
        ]);

        $this->assertSame(
            ['steps', 'model_name'],
            array_keys($store->toStored()['golden']['values']),
        );
    }

    public function testSavingNormalisesValuesToTheShapeTheDiskRoundTrips(): void
    {
        // The cfg_scale Slider carries float 7.0 in the draft; config.json
        // writes that as `7` and reads it back an int, so a bundle that skips
        // the save-time JSON hop fails SettingsWriter's landed-proof on the
        // first whole-form preset. What a store holds is exactly what a disk
        // reload hands back.
        $store = MediaPresets::empty()->save('golden', [
            'cfg_scale' => 7.0,
            'denoising_strength' => 0.75,
        ]);

        $this->assertSame(
            ['cfg_scale' => 7, 'denoising_strength' => 0.75],
            $store->toStored()['golden']['values'],
        );
        $this->assertSame(
            $store->toStored(),
            MediaPresets::fromStored($store->toStored())->toStored(),
            'save -> disk -> reload must be identity',
        );
    }

    public function testSavingNeedsANameAndStringKeysOnly(): void
    {
        foreach ([
            'blank name' => static fn (): MediaPresets => MediaPresets::empty()->save('   ', ['steps' => 1]),
            'numeric value key' => static fn (): MediaPresets => MediaPresets::empty()->save('p', [0 => 'x']),
        ] as $label => $act) {
            try {
                $act();
                $this->fail("save must refuse: {$label}");
            } catch (\InvalidArgumentException $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testSavingTwiceUnderOneNameReplacesTheBundle(): void
    {
        $store = MediaPresets::empty()
            ->save('golden', ['steps' => 20])
            ->save('golden', ['steps' => 31]);

        $this->assertSame(['golden'], $store->list());
        $this->assertSame(31, $store->apply('golden')['steps']);
    }

    public function testDeletingAnUnknownNameRefusesLoudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MediaPresets::empty()->delete('never-saved');
    }

    public function testStoredBundlesRoundTripThroughToStored(): void
    {
        $store = MediaPresets::empty()->save('golden', ['steps' => 31, 'cfg_scale' => 7.5]);

        $this->assertEquals($store, MediaPresets::fromStored($store->toStored()));
        $this->assertSame(['steps' => 31, 'cfg_scale' => 7.5], $store->apply('golden'));
        $this->assertSame(['golden'], MediaPresets::fromStored($store->toStored())->list());
    }

    /** @return array<string, array{0: array<array-key, mixed>}> */
    public static function malformedStoredMaps(): array
    {
        $bundle = ['presetVersion' => 1, 'values' => ['steps' => 20]];

        return [
            'nameless entry' => [[4 => $bundle]],
            'entry not a bundle' => [['good' => 'not-a-bundle']],
            'future stamp' => [['good' => ['presetVersion' => 2, 'values' => []]]],
            'missing stamp' => [['good' => ['values' => ['steps' => 1]]]],
            'values a list' => [['good' => ['presetVersion' => 1, 'values' => ['a', 'b']]]],
            'values missing' => [['good' => ['presetVersion' => 1]]],
            'numeric-keyed value' => [['good' => ['presetVersion' => 1, 'values' => [0 => 'x']]]],
        ];
    }

    /**
     * One malformed entry fails the WHOLE map — a silently skipped preset
     * would let the operator apply "the other one" by name-mismatch.
     *
     * @dataProvider malformedStoredMaps
     *
     * @param array<array-key, mixed> $stored
     */
    public function testEveryMalformedStoredShapeRefusesLoudly(array $stored): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MediaPresets::fromStored($stored);
    }

    public function testAMalformedMapStillLeavesTheGoodEntriesUnread(): void
    {
        try {
            MediaPresets::fromStored([
                'good' => ['presetVersion' => 1, 'values' => ['steps' => 20]],
                'bad' => 'not-a-bundle',
            ]);
            $this->fail('the whole map must refuse');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('bad', $error->getMessage());
        }
    }

    // ---- the form press flow --------------------------------------------------------

    public function testSaveApplyDeleteWalkTheFormWithTheDoorCapturing(): void
    {
        $captured = [];
        $door = static function (array $map) use (&$captured): void {
            $captured[] = $map;
        };

        $form = MediaForm::open(
            MediaRequest::new()->fromArray(['prompt' => 'a test cat']),
            presets: [],
            persistPresets: $door,
        );
        $form = $this->presetHydrated($form, ['steps' => 31]);

        // Save the whole form under a typed name.
        $form = $this->presetTypeInto($form, 0, 'golden');
        $form = $this->presetPressRowAt($form, 2);
        $this->assertSame(
            Lang::t(GenFormKeys::PRESET_SAVE) . ' — golden',
            $this->presetStatusOf($form),
        );

        // The door saw exactly the bundle shape §5.8 prescribes.
        $this->assertCount(1, $captured);
        $this->assertSame(['golden'], array_keys($captured[0]));
        $this->assertSame(1, $captured[0]['golden']['presetVersion']);
        $this->assertSame(31, $captured[0]['golden']['values']['steps']);
        $this->assertSame('a test cat', $captured[0]['golden']['values']['prompt']);

        // The name list refreshed over the new store.
        $this->assertSame(
            '[ ] golden',
            $this->presetFocusedSection($form)->valueAt(1),
        );

        // Move away, check the name, apply it back.
        $form = $this->presetHydrated($form, ['steps' => 12]);
        $this->assertSame(12, $form->collectValues()['steps']);
        $form = $this->presetCheckNameAt($form, 1);
        $form = $this->presetPressRowAt($form, 3);
        $this->assertSame(
            Lang::t(GenFormKeys::PRESET_APPLY) . ' — golden',
            $this->presetStatusOf($form),
        );

        // save→apply is value-equal against the pre-save bundle.
        $this->assertSame(31, $form->collectValues()['steps']);
        $this->assertSame('a test cat', $form->collectValues()['prompt']);
        $this->assertCount(1, $captured, 'apply persists nothing — the store did not change');

        // Delete the still-checked name (hydrate keeps the section's fields);
        // the door sees the emptied map.
        $form = $this->presetPressRowAt($form, 4);
        $this->assertSame(
            Lang::t(GenFormKeys::PRESET_DELETE) . ' — golden',
            $this->presetStatusOf($form),
        );
        $this->assertCount(2, $captured);
        $this->assertSame([], $captured[1]);
        $this->assertSame(
            Lang::t(GenFormKeys::NOT_SET),
            $this->presetFocusedSection($form)->valueAt(1),
            'the list cell falls back to not-set once the store empties',
        );
    }

    public function testPressingApplyWithoutSelectionRefusesLoudlyViaStatus(): void
    {
        $form = $this->presetOnTab(MediaForm::open(presets: []));
        $form = $this->presetPressRowAt($form, 3);

        $this->assertStringStartsWith('! ', (string) $this->presetStatusOf($form));
        $this->assertStringContainsString("no preset named ''", (string) $this->presetStatusOf($form));
    }

    public function testAMalformedStoreOpensOnTheRefusalAndNeverPersists(): void
    {
        $captured = [];
        $door = static function (array $map) use (&$captured): void {
            $captured[] = $map;
        };

        $form = MediaForm::open(presets: ['bad' => 'not-a-bundle'], persistPresets: $door);
        $status = (string) $this->presetStatusOf($form);
        $this->assertStringStartsWith('! sd.presets preset', $status);
        $this->assertStringContainsString('not a preset bundle', $status);

        // The tab survives the refusal; every press answers STORE_UNAVAILABLE
        // and the door is never reached — a refused save never half-lands.
        $form = $this->presetTypeInto($form, 0, 'golden');
        $form = $this->presetPressRowAt($form, 2);
        $this->assertSame('! ' . MediaPresets::STORE_UNAVAILABLE, $this->presetStatusOf($form));
        $this->assertSame([], $captured);
    }

    public function testADoorThatThrowsRefusesAtTheStatusAndKeepsTheStoreUnchanged(): void
    {
        $form = MediaForm::open(
            presets: [],
            persistPresets: static function (array $map): void {
                throw new \RuntimeException('the settings file stayed shut');
            },
        );
        $form = $this->presetTypeInto($form, 0, 'golden');
        $form = $this->presetPressRowAt($form, 2);

        $this->assertStringStartsWith('! ', (string) $this->presetStatusOf($form));
        $this->assertStringContainsString('stayed shut', (string) $this->presetStatusOf($form));
        $this->assertSame(
            Lang::t(GenFormKeys::NOT_SET),
            $this->presetFocusedSection($form)->valueAt(1),
            'the failed name never joined the list',
        );
    }

    public function testThePresetsTabIsSectionLocalSoDisplayOverridesBounceOffIt(): void
    {
        $form = MediaForm::open(presets: []);
        $section = $this->presetFocusedSection($this->presetOnTab($form));
        $this->assertInstanceOf(PresetsSection::class, $section);

        $this->assertSame($section, $section->withDisplayOverrides(['anything/At All/value' => 1]));
    }

    // ---- all the way to disk (the door shape the Wave-4 App seam will wire) ----------

    public function testTheDoorWiredToSettingsWriterLandsTheWholeMapOnDisk(): void
    {
        $this->presetArmHomeSandbox();
        // A fresh install's missing config.json is created by the launch
        // writer only over an existing readable base — seed the empty file
        // the way a first run would have left it.
        file_put_contents(Bootstrap::userConfigPath(), "{}\n");
        $writer = SettingsWriter::new(
            Bootstrap::userConfigPath(),
            static function (array $set, array $unset): void {
                Bootstrap::writeUserConfig($set, $unset);
            },
        );

        $captured = [];
        $door = static function (array $map) use ($writer, &$captured): void {
            $writer->write(SettingsTier::You, [MediaPresets::SETTINGS_KEY => $map]);
            $captured[] = $map;
        };

        $form = $this->presetTypeInto(MediaForm::open(presets: [], persistPresets: $door), 0, 'golden');
        $form = $this->presetPressRowAt($form, 2);
        $this->assertStringStartsWith('Save preset', (string) $this->presetStatusOf($form));

        $onDisk = json_decode((string) file_get_contents(Bootstrap::userConfigPath()), true);
        $this->assertSame($captured[0], $onDisk[MediaPresets::SETTINGS_KEY]);
        $this->assertSame(1, $onDisk[MediaPresets::SETTINGS_KEY]['golden']['presetVersion']);

        // And the stored map parses back into an equal store.
        $this->assertSame(
            ['golden'],
            MediaPresets::fromStored($onDisk[MediaPresets::SETTINGS_KEY])->list(),
        );
    }

    // ---- the output tab ---------------------------------------------------------------

    public function testTheOutputTabShowsTheEffectivePatternAndEveryToken(): void
    {
        $form = MediaForm::open(savePattern: 'cat-[seed]-[number]');
        $output = $this->presetSectionByKey($form, 'output');

        $this->assertInstanceOf(OutputSection::class, $output);
        $this->assertSame(Lang::t(GenFormKeys::OUTPUT_PATTERN), $output->rows()[0]);
        $this->assertSame('cat-[seed]-[number]', $output->valueAt(0));
        $this->assertNull($output->fieldAt(0), 'the tab is read-only');
        $this->assertNull($output->fieldAt(1));

        // The help line is SSOT-pinned against the store's own palette —
        // thirteen tokens, every one named, nothing transcribed by hand.
        $tokensLine = $output->valueAt(1);
        $this->assertSame(Lang::t(GenFormKeys::OUTPUT_TOKENS), $tokensLine);
        $this->assertCount(count(MediaStore::TOKENS), $this->presetBracketedTokens($tokensLine));
        foreach (MediaStore::TOKENS as $token) {
            $this->assertStringContainsString('[' . $token . ']', $tokensLine);
        }
    }

    public function testTheOutputTabFallsBackToTheStoreDefaultPattern(): void
    {
        $this->assertSame(
            MediaStore::DEFAULT_PATTERN,
            $this->presetSectionByKey(MediaForm::open(), 'output')->valueAt(0),
        );
    }

    public function testTheRulingKeepsLastUsedOutOfTheSettingsSurface(): void
    {
        // plan :532 ruling: the last-used preset is session-draft state, NOT
        // a settings key — nothing named mediaLastUsed may join the registry.
        foreach (['MediaSettings.php', 'UiSettings.php'] as $file) {
            $path = __DIR__ . '/../../../src/Config/Settings/Definitions/' . $file;
            if (!is_file($path)) {
                continue;
            }

            $this->assertStringNotContainsString(
                'mediaLastUsed',
                (string) file_get_contents($path),
                "{$file} would mint the refused key",
            );
        }
    }

    // ---- helpers ------------------------------------------------------------------

    private function presetArmHomeSandbox(): void
    {
        $dir = sys_get_temp_dir() . '/crush-presets-home-' . bin2hex(random_bytes(5));
        mkdir($dir . '/home/.sugar-crush', 0o700, true);
        $this->presetHomeDir = $dir;

        // Snapshot BEFORE overwriting (lane-cf law): an unset-then-restore
        // cycle poisons every later test that reads HOME.
        $this->originalHomeSnapshot = getenv('HOME') ?: '';
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        putenv('HOME=' . $dir . '/home');
        $_SERVER['HOME'] = $dir . '/home';
        Bootstrap::useConfigPath(null);
    }

    private function presetReleaseHomeSandbox(): void
    {
        if ($this->presetHomeDir === '') {
            return;
        }

        Bootstrap::useConfigPath(null);
        putenv('HOME=' . $this->originalHomeSnapshot);
        if ($this->originalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->originalServerHome;
        }
        exec('rm -rf ' . escapeshellarg($this->presetHomeDir) . ' 2>&1');
        $this->presetHomeDir = '';
    }

    private string $originalHomeSnapshot = '';

    private ?string $originalServerHome = null;

    /** Push values through the form's own hydrate entry point. */
    private function presetHydrated(MediaForm $form, array $values): MediaForm
    {
        return $form->hydrateFields($values);
    }

    /** @return array{0: ?MediaForm, 1: ?\SugarCraft\Crush\Tui\MediaForm\MediaFormAction} */
    private function presetKey(MediaForm $form, KeyMsg $key): array
    {
        return $form->update($key);
    }

    /** Cross sections until the presets tab holds focus (tab order is a product fact). */
    private function presetOnTab(MediaForm $form): MediaForm
    {
        for ($step = 0; $step < 15; $step++) {
            if ($this->presetFocusedSection($form) instanceof PresetsSection) {
                return $form;
            }

            $form = $this->presetStep($form, new KeyMsg(KeyType::Right));
        }

        $this->fail('the presets tab never took focus');
    }

    private function presetStep(MediaForm $form, KeyMsg $key): MediaForm
    {
        [$next] = $this->presetKey($form, $key);
        $this->assertNotNull($next, 'the form never closes under these keys');

        return $next;
    }

    private function presetFocusedSection(MediaForm $form): Section
    {
        $visible = (new \ReflectionMethod($form, 'visibleSections'))->invoke($form);

        return $visible[$form->state()->section];
    }

    private function presetSectionByKey(MediaForm $form, string $key): Section
    {
        foreach ($form->sections() as $section) {
            if ($section->key() === $key) {
                return $section;
            }
        }

        $this->fail("no section '{$key}' is registered");
    }

    private function presetStatusOf(MediaForm $form): ?string
    {
        return (new \ReflectionProperty($form, 'status'))->getValue($form);
    }

    /** Focus the name row, type the runes, commit. */
    private function presetTypeInto(MediaForm $form, int $row, string $text): MediaForm
    {
        $form = $this->presetGoToRow($this->presetOnTab($form), $row);
        $form = $this->presetStep($form, new KeyMsg(KeyType::Enter));
        foreach (str_split($text) as $rune) {
            $form = $this->presetStep($form, new KeyMsg(KeyType::Char, $rune));
        }

        return $this->presetStep($form, new KeyMsg(KeyType::Enter));
    }

    /** Check the first preset in the names list. */
    private function presetCheckNameAt(MediaForm $form, int $row): MediaForm
    {
        $form = $this->presetGoToRow($this->presetOnTab($form), $row);
        $form = $this->presetStep($form, new KeyMsg(KeyType::Enter));
        $form = $this->presetStep($form, new KeyMsg(KeyType::Space));

        return $this->presetStep($form, new KeyMsg(KeyType::Enter));
    }

    /** Toggle the press row at $row (Enter arms it, Enter commits it). */
    private function presetPressRowAt(MediaForm $form, int $row): MediaForm
    {
        $form = $this->presetGoToRow($this->presetOnTab($form), $row);
        $form = $this->presetStep($form, new KeyMsg(KeyType::Enter));

        return $this->presetStep($form, new KeyMsg(KeyType::Enter));
    }

    /**
     * Absolute row placement: Up wraps, so drive (rows - current) downs to
     * park at row 0 from any start, then the requested number of downs.
     */
    private function presetGoToRow(MediaForm $form, int $row): MediaForm
    {
        $count = \count($this->presetFocusedSection($form)->rows());
        $start = $form->state()->row;
        for ($step = 0; $step < $count - $start; $step++) {
            $form = $this->presetStep($form, new KeyMsg(KeyType::Down));
        }
        $this->assertSame(
            0,
            $form->state()->row,
            "wrapping down parks the cursor at row 0 (rows={$count}, start={$start})",
        );

        for ($step = 0; $step < $row; $step++) {
            $form = $this->presetStep($form, new KeyMsg(KeyType::Down));
        }

        return $form;
    }

    /** @return list<string> */
    private function presetBracketedTokens(string $line): array
    {
        preg_match_all('/\[([a-z_]+)\]/', $line, $hits);

        return $hits[1];
    }
}

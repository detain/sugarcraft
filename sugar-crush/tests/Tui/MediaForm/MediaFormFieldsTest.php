<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui\MediaForm;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Media\Sd\Client;
use SugarCraft\Forms\Field\NumberField;
use SugarCraft\Forms\Field\Slider;
use SugarCraft\Crush\Tui\MediaForm\ChoiceFeed;
use SugarCraft\Crush\Tui\MediaForm\FieldSpec;
use SugarCraft\Crush\Tui\MediaForm\MediaForm;
use SugarCraft\Crush\Tui\MediaForm\Sections\ParamsSection;
use SugarCraft\Crush\Theme;

/**
 * The W3.4 FieldSpec registry feeding real widgets: the coercion matrix is
 * DATA-PROVIDER-DRIVEN from the table itself (plan §10.3 law - every range,
 * default and step edge is covered by construction, so a row added to the
 * registry is coerced by these same tests without a new assertion).
 *
 * Regenerate goldens with `UPDATE_GOLDENS=1` after an intended change.
 */
final class MediaFormFieldsTest extends TestCase
{
    private const FIELDS_GOLDEN_DIR = __DIR__ . '/golden';

    protected function setUp(): void
    {
        putenv(Client::BASE_URL_ENV);
        ChoiceFeed::resetForTesting();
    }

    protected function tearDown(): void
    {
        putenv(Client::BASE_URL_ENV);
        ChoiceFeed::resetForTesting();
    }

    // ---- registry lattice -----------------------------------------------------

    /**
     * @dataProvider numericCoreSpecRows
     */
    public function testEveryNumericDefaultSitsOnItsOwnLattice(FieldSpec $spec): void
    {
        $key = $spec->key;
        $this->assertIsNumeric($spec->default, "{$key}: default must be numeric");
        $this->assertIsNumeric($spec->min, "{$key}: range min required");
        $this->assertIsNumeric($spec->max, "{$key}: range max required");
        $this->assertIsNumeric($spec->step, "{$key}: step required");

        $offset = abs(((float) $spec->default) - ((float) $spec->min));
        $remainder = fmod($offset, (float) $spec->step);
        $this->assertLessThan(
            1e-9,
            min($remainder, (float) $spec->step - $remainder),
            "{$key}: default {$spec->default} is off the [{$spec->min}, {$spec->step}] lattice the table specifies"
        );
    }

    /**
     * @return array<string, array{FieldSpec}>
     */
    public static function numericCoreSpecRows(): array
    {
        $rows = [];
        foreach (FieldSpec::coreRows() as $spec) {
            if ($spec->widget !== FieldSpec::WIDGET_SLIDER && $spec->widget !== FieldSpec::WIDGET_NUMBER) {
                continue;
            }
            $rows["{$spec->key} default"] = [$spec];
        }

        return $rows;
    }

    /**
     * Each numeric registry row coerces its own below-range, above-range and
     * in-range raw values through the widget the table minted (clamped to the
     * §2 table bounds - the DTO never clamps, the form does).
     *
     * @dataProvider coercionRows
     */
    public function testRawValuesCoerceOntoTheRangeLattice(FieldSpec $spec): void
    {
        $key = $spec->key;
        $below = self::stepAway($spec, -1);
        $this->assertSame(
            $spec->min,
            self::coerce($spec, $below),
            "{$key}: a value under the floor clamps to the floor"
        );

        if (self::hasFiniteCeiling($spec)) {
            $above = self::stepAway($spec, 1);
            $this->assertSame(
                $spec->max,
                self::coerce($spec, $above),
                "{$key}: a value over the ceiling clamps to the ceiling"
            );
        }

        $this->assertSame(
            $spec->default,
            self::coerce($spec, $spec->default),
            "{$key}: the table default passes through untouched"
        );
    }

    /**
     * @return array<string, array{FieldSpec}>
     */
    public static function coercionRows(): array
    {
        $rows = [];
        foreach (FieldSpec::coreRows() as $spec) {
            if ($spec->widget !== FieldSpec::WIDGET_SLIDER && $spec->widget !== FieldSpec::WIDGET_NUMBER) {
                continue;
            }
            $rows["{$spec->key} coercion"] = [$spec];
        }

        return $rows;
    }

    public function testOffLatticeSnapsRoundHalfAwayFromZero(): void
    {
        $width = self::spec('width');
        $this->assertSame(64, self::coerce($width, 67));
        $this->assertSame(72, self::coerce($width, 68));

        $cfg = self::spec('cfg_scale');
        $this->assertSame(7.5, self::coerce($cfg, 7.3));
    }

    public function testTheSeedFieldCarriesInt64SeedsDigitExact(): void
    {
        $seed = self::spec('seed');

        $this->assertSame(-1, self::coerce($seed, -2), 'below the -1 sentinel clamps to it');

        $vast = 99999999999999999;
        $carried = self::coerce($seed, $vast);
        $this->assertIsInt($carried);
        $this->assertSame($vast, $carried, 'a full int64 seed must not round-trip through float');
    }

    // ---- hydrate / collect ------------------------------------------------------

    public function testHydratedValuesRoundTripThroughCollect(): void
    {
        $values = [
            'prompt' => 'x',
            'negative_prompt' => 'no',
            'styles' => ['Default'],
            'width' => 640,
            'height' => 768,
            'n_iter' => 2,
            'batch_size' => 3,
            'cfg_scale' => 8.5,
            'steps' => 30,
            'sampler_name' => 'Euler a',
            'scheduler' => 'Karras',
            'seed' => 123,
            // W4.1: the img2img section is open by default, so its lattice
            // defaults ride the values map alongside the hydrated fields.
            'resize_mode' => 'Just resize',
            'denoising_strength' => 0.75,
            // W4.3 opened the inpaint half too: its §2.7 defaults follow.
            'mask_blur' => 4,
            'inpainting_mask_invert' => 'Inpaint masked',
            'inpainting_fill' => 'original',
            'inpaint_full_res' => 'Whole picture',
            'inpaint_full_res_padding' => 32,
        ];

        $form = MediaForm::open(MediaRequest::new())->hydrateFields($values);

        $this->assertSame($values, $form->collectValues());
        $wire = $form->collect()->toArray();
        $this->assertSame(640, $wire['width']);
        $this->assertSame('Euler a', $wire['sampler_name']);
        $this->assertSame('Karras', $wire['scheduler']);
        $this->assertSame(123, $wire['seed']);
    }

    public function testJunkAndUnknownChoicesFailSoftToTheCurrentSelection(): void
    {
        $form = MediaForm::open(MediaRequest::new())->hydrateFields([
            'width' => 'banana',
            'sampler_name' => 'Nope Not A Sampler',
        ]);

        $values = $form->collectValues();
        $this->assertSame(512, $values['width'], 'a non-numeric draft value leaves the field alone');
        $this->assertSame('Euler', $values['sampler_name'], 'an unknown choice cannot deselect');
    }

    public function testTheDefaultStyleCollectsNothingOntoTheWire(): void
    {
        $wire = MediaForm::open(MediaRequest::new())
            ->hydrateFields(['styles' => ['Default']])
            ->collect()
            ->toArray();

        // The marker folds to an explicit null: present in the always-post
        // frame, never a value the server would apply as a style.
        $this->assertArrayHasKey('styles', $wire);
        $this->assertNull($wire['styles'], "'Default' is the A1111 no-style marker, folded to null");

        $blank = MediaForm::open(MediaRequest::new())
            ->hydrateFields(['prompt' => '   '])
            ->collect()
            ->toArray();
        $this->assertNull($blank['prompt'], 'whitespace trims to no prompt on the wire');
    }

    // ---- runtime choices --------------------------------------------------------

    public function testRuntimeChoicesReplaceTheBakedEnumLists(): void
    {
        $section = ParamsSection::forDraft(MediaRequest::new(), [
            FieldSpec::CHOICES_SAMPLERS => ['OnlyOne'],
            FieldSpec::CHOICES_SCHEDULERS => ['Automatic'],
        ]);

        $values = $section->fieldValues();
        $this->assertSame('OnlyOne', $values['sampler_name']);

        $wire = $section->collect(MediaRequest::new())->toArray();
        $this->assertSame('OnlyOne', $wire['sampler_name']);
    }

    public function testARuntimeLegacyLabelSplitsOnCollect(): void
    {
        $form = MediaForm::open(MediaRequest::new(), [
            FieldSpec::CHOICES_SAMPLERS => ['Euler', 'DPM++ 2M Karras'],
            FieldSpec::CHOICES_SCHEDULERS => ['Automatic', 'Karras'],
        ])->hydrateFields(['sampler_name' => 'DPM++ 2M Karras']);

        $wire = $form->collect()->toArray();
        $this->assertSame('DPM++ 2M', $wire['sampler_name']);
        $this->assertSame('Karras', $wire['scheduler']);
    }

    /**
     * fix_p_invalid_sampler_and_scheduler analog: split only when the
     * scheduler slot was left on Automatic AND the prefix is a real sampler.
     *
     * @dataProvider splitLawRows
     */
    public function testTheSamplerSchedulerSplitLaw(?string $scheduler, string $label, string $wantSampler, ?string $wantScheduler): void
    {
        $draft = MediaRequest::new()->withSamplerName($label);
        if ($scheduler !== null) {
            $draft = $draft->withScheduler($scheduler);
        }

        $wire = ParamsSection::applySchedulerSplit($draft)->toArray();
        $this->assertSame($wantSampler, $wire['sampler_name']);
        $this->assertSame($wantScheduler, $wire['scheduler'] ?? null);
    }

    /**
     * @return array<string, array{?string, string, string, string}>
     */
    public static function splitLawRows(): array
    {
        return [
            'legacy label splits when the schedule is unset' => [null, 'DPM++ 2M Karras', 'DPM++ 2M', 'Karras'],
            'legacy label splits over Automatic' => ['Automatic', 'UniPC Karras', 'UniPC', 'Karras'],
            'an explicit schedule vetoes the split' => ['Uniform', 'DPM++ 2M Karras', 'DPM++ 2M Karras', 'Uniform'],
            'an unknown prefix stays whole' => [null, 'HyperMega Karras', 'HyperMega Karras', null],
            'a plain sampler label is untouched' => [null, 'DPM++ 2M', 'DPM++ 2M', null],
            'a bare ancestral suffix is not a scheduler' => [null, 'Euler a', 'Euler a', null],
        ];
    }

    public function testTheChoiceFeedStaysSilentWithoutAConfiguredBase(): void
    {
        $this->assertNull(ChoiceFeed::forForm(), 'no base URL means no HTTP and no overrides');
    }

    public function testTheChoiceFeedReadsNameRowsAndDropsJunk(): void
    {
        $this->assertSame(
            ['A'],
            ChoiceFeed::namesFrom([['name' => 'A'], ['name' => ''], ['x' => 1], ['name' => 5]])
        );
    }

    // ---- seed press rows ----------------------------------------------------------

    public function testCommittingTheRandomizeRowParksTheSeedOnMinusOne(): void
    {
        $form = self::onParamsRow(
            MediaForm::open(MediaRequest::new()->withSeed(5)),
            9,
        );

        $this->assertSame(-1, $form->collectValues()['seed']);
    }

    public function testCommittingTheReuseRowCopiesTheRememberedSeed(): void
    {
        $form = self::onParamsRow(
            MediaForm::open(MediaRequest::new()->withSeed(5))->withLastSeed(777),
            10,
        );

        $this->assertSame(777, $form->collectValues()['seed']);
    }

    public function testTheLastSeedRowIsAReadOnlyDisclosure(): void
    {
        $section = ParamsSection::forDraft(MediaRequest::new())->withLastSeed(4242);

        $this->assertSame('4242', $section->valueAt(11));
        $this->assertNull($section->fieldAt(11), 'the disclosure row never opens an editor');
    }

    // ---- golden ---------------------------------------------------------------------

    public function testTheParamsSectionFrameAt100x40MatchesTheGoldenFrame(): void
    {
        [$form] = MediaForm::open(MediaRequest::new())->update(new KeyMsg(KeyType::Right));

        $this->assertGolden('params-100x40.txt', self::plain($form->view(Theme::default(), 100, 40)));
    }

    // ---- helpers ----------------------------------------------------------------------

    /**
     * Focus the Params section, walk to $row and fire one press-commit cycle
     * (Enter begins the edit, Enter commits it - the Confirm-as-press law).
     */
    private static function onParamsRow(MediaForm $form, int $row): MediaForm
    {
        [$form] = $form->update(new KeyMsg(KeyType::Right));
        for ($i = 0; $i < $row; ++$i) {
            [$form] = $form->update(new KeyMsg(KeyType::Down));
        }
        [$form] = $form->update(new KeyMsg(KeyType::Enter));
        [$form] = $form->update(new KeyMsg(KeyType::Enter));

        return $form;
    }

    /**
     * Push a raw value through the widget kind the registry row declares.
     */
    private static function coerce(FieldSpec $spec, int|float $raw): mixed
    {
        $field = $spec->createField($spec->key);
        if ($field instanceof Slider) {
            return $field->withValue($raw)->value();
        }
        if ($field instanceof NumberField) {
            return $field->withValue($raw)->value();
        }

        self::fail("registry row '{$spec->key}' does not mint a numeric widget");
    }

    private static function spec(string $key): FieldSpec
    {
        foreach (FieldSpec::coreRows() as $candidate) {
            if ($candidate->key === $key) {
                return $candidate;
            }
        }

        self::fail("registry row '{$key}' vanished from FieldSpec::coreRows()");
    }

    private static function hasFiniteCeiling(FieldSpec $spec): bool
    {
        return is_int($spec->max) ? $spec->max < PHP_INT_MAX - 1 : (float) $spec->max < (float) PHP_INT_MAX;
    }

    private static function stepAway(FieldSpec $spec, int $direction): int|float
    {
        $edge = $direction < 0 ? $spec->min : $spec->max;
        $stepped = $edge + ($direction * (float) $spec->step);

        return is_int($edge) && is_int($spec->step) ? $edge + ($direction * $spec->step) : $stepped;
    }

    private static function plain(string $rendered): string
    {
        return Ansi::strip($rendered);
    }

    private function assertGolden(string $name, string $actual): void
    {
        $path = self::FIELDS_GOLDEN_DIR . '/' . $name;
        if (getenv('UPDATE_GOLDENS') === '1') {
            if (!is_dir(self::FIELDS_GOLDEN_DIR)) {
                mkdir(self::FIELDS_GOLDEN_DIR, 0o755, true);
            }
            file_put_contents($path, $actual . "\n");
        }

        $this->assertFileExists($path, "no golden at {$path}; run with UPDATE_GOLDENS=1 to create it");
        $this->assertSame((string) file_get_contents($path), $actual . "\n", "layout drifted from {$name}");
    }
}

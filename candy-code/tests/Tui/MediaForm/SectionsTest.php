<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui\MediaForm;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Media\MediaRequest;
use SugarCraft\Code\Tui\MediaForm\ChoiceFeed;
use SugarCraft\Code\Tui\MediaForm\FieldSpec;
use SugarCraft\Code\Tui\MediaForm\GenFormKeys;
use SugarCraft\Code\Tui\MediaForm\MediaForm;
use SugarCraft\Code\Tui\MediaForm\Sections\HiresSection;
use SugarCraft\Code\Tui\MediaForm\Sections\RefinerSection;
use SugarCraft\Code\Tui\MediaForm\Sections\SeedSection;
use SugarCraft\Code\Media\Sd\Client;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\Field\Confirm;

/**
 * The W3.5 disclosure law, group by group: a collapsed accordion renders
 * only its gate row, collects nothing, and stale-clears any value the draft
 * already carries to null (never a hidden setting riding the wire). Opening
 * it back up hydrates from the same draft (§2.1/§2.3).
 */
final class SectionsTest extends TestCase
{
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

    // ---- Extra (variation seed) ------------------------------------------------

    public function testTheExtraGroupCollapsesToOneGateRowAndCollectsNothingFresh(): void
    {
        $section = SeedSection::forDraft(MediaRequest::new());

        $this->assertSame([Lang::t(GenFormKeys::SECTION_EXTRA)], $section->rows());
        $this->assertSame(Lang::t(GenFormKeys::STATE_OFF), $section->valueAt(0));
        $this->assertSame([], array_intersect_key(
            $section->collect(MediaRequest::new())->toArray(),
            array_flip(SeedSection::GATED_KEYS),
        ));
    }

    public function testOpeningExtraExposesTheFourVariationRowsAndFoldsThem(): void
    {
        $open = $this->extraOpen();
        $this->assertCount(5, $open->rows());

        $seeded = $this->asSeed($open->withField($this->extraField($open, 1, 777)));
        $wire = $seeded->collect(MediaRequest::new())->toArray();

        $this->assertSame(777, $wire['subseed']);
        $this->assertSame(0.0, $wire['subseed_strength']);
        $this->assertSame(0, $wire['seed_resize_from_w']);
        $this->assertSame(0, $wire['seed_resize_from_h']);
    }

    public function testClosingExtraStaleClearsWhatItHidAndNeverInventsKeys(): void
    {
        $touched = $this->extraOpen()->collect(MediaRequest::new());
        $this->assertArrayHasKey('subseed', $touched->toArray());

        $reclosed = $this->asSeed($this->extraOpen()->withField(Confirm::new(SeedSection::ROW_DISCLOSE, false)));
        $cleared = $reclosed->collect($touched)->toArray();
        $this->assertNull($cleared['subseed'], 'a hidden value must not ride the wire');

        $neverTouched = $reclosed->collect(MediaRequest::new())->toArray();
        $this->assertFalse(array_key_exists('subseed', $neverTouched));
    }

    public function testADraftCarryingVariationValuesOpensTheGateByItself(): void
    {
        // An infotext import that names a subseed must not hide what it set.
        $section = SeedSection::forDraft(MediaRequest::new()->withSubseed(42));

        $this->assertCount(5, $section->rows());
        $this->assertSame(42, $section->collect(MediaRequest::new())->toArray()['subseed']);
    }

    // ---- Hires. fix --------------------------------------------------------------

    public function testTheHiresAccordionCollapsesToTheEnableRow(): void
    {
        $section = HiresSection::forDraft(MediaRequest::new());

        $this->assertSame([Lang::t(GenFormKeys::SECTION_HIRES)], $section->rows());
        $this->assertSame(Lang::t(GenFormKeys::STATE_OFF), $section->valueAt(0));
        $this->assertFalse(array_key_exists('enable_hr', $section->collect(MediaRequest::new())->toArray()));
    }

    public function testOpeningHiresShowsTheSixLatentModesAndHidesTheRevealRows(): void
    {
        $open = $this->hiresOpen();
        $labels = $open->rows();

        // enable + upscaler + steps + denoising + scale + resize x/y + three
        // reveal checkboxes + output size = 11 rows while reveals stay off.
        $this->assertCount(11, $labels);
        $this->assertContains(Lang::t(GenFormKeys::HIRES_UPSCALER), $labels);
        $this->assertNotContains(Lang::t(GenFormKeys::HIRES_SAMPLING_METHOD), $labels);

        $this->assertSame('Latent', $open->collect(MediaRequest::new())->toArray()['hr_upscaler']);
        $this->assertSame(FieldSpec::latentUpscalers(), [
            'Latent',
            'Latent (antialiased)',
            'Latent (bicubic)',
            'Latent (bicubic antialiased)',
            'Latent (nearest)',
            'Latent (nearest-exact)',
        ], 'the §2.8 six, verbatim - the wire spells them exactly like this');
    }

    public function testTheSharedDenoisingFieldFoldsAtItsHiresContextDefault(): void
    {
        // §2.2 context note: denoising_strength is ONE wire field; the hires
        // tab seeds it 0.7, the (gated) img2img group owns 0.75.
        $wire = $this->hiresOpen()->collect(MediaRequest::new())->toArray();
        $this->assertSame(0.7, $wire['denoising_strength']);
        $this->assertTrue($wire['enable_hr']);
        $this->assertSame(0, $wire['hr_second_pass_steps']);
        $this->assertSame(2.0, $wire['hr_scale']);
    }

    public function testTheSamplerRevealSentinelFoldsToWireNull(): void
    {
        $open = $this->hiresOpen();
        $shown = $this->asHires($open->withField(Confirm::new(HiresSection::ROW_SHOW_SAMPLER, true)));
        $this->assertCount(13, $shown->rows());

        $sampler = $shown->fieldAt($this->labelRow($shown, Lang::t(GenFormKeys::HIRES_SAMPLING_METHOD)));
        $this->assertSame(
            FieldSpec::useSameLabel(GenFormKeys::NOUN_SAMPLER),
            (string) $sampler?->value(),
            'a fresh reveal lands on the Use-same sentinel',
        );

        $wire = $shown->collect(MediaRequest::new())->toArray();
        $this->assertTrue(array_key_exists('hr_sampler_name', $wire), 'the sentinel folds the key explicitly...');
        $this->assertNull($wire['hr_sampler_name'], '...as null: use the first-pass sampler');
        $this->assertTrue(array_key_exists('hr_scheduler', $wire));
        $this->assertNull($wire['hr_scheduler'], '...and the schedule row too');
    }

    public function testChoosingARealSecondPassSamplerReachesTheWire(): void
    {
        $shown = $this->revealSampler();
        $wire = $shown->collect(MediaRequest::new())->toArray();
        $this->assertSame('DPM++ 2M', $wire['hr_sampler_name']);
    }

    public function testHidingTheRevealAgainStaleClearsTheCommittedSampler(): void
    {
        $picked = $this->revealSampler();
        $committed = $picked->collect(MediaRequest::new());
        $this->assertSame('DPM++ 2M', $committed->toArray()['hr_sampler_name']);

        $rehidden = $this->asHires($picked->withField(Confirm::new(HiresSection::ROW_SHOW_SAMPLER, false)));
        $wire = $rehidden->collect($committed)->toArray();
        $this->assertTrue(array_key_exists('hr_sampler_name', $wire));
        $this->assertNull($wire['hr_sampler_name'], 'a hidden value must not ride the wire');
    }

    public function testTheOutputSizeRowPreviewsTheSectionTwentyPointFourMath(): void
    {
        $open = $this->hiresOpen();
        $row = $this->labelRow($open, Lang::t(GenFormKeys::HIRES_OUTPUT_SIZE));

        $this->assertSame('from 512x512 to 1024x1024', $open->valueAt($row));

        $oddDraft = MediaRequest::new()->withWidth(512)->withHeight(1024)->withHrScale(1.7);
        $odd = $this->asHires(
            HiresSection::forDraft($oddDraft)->withField(Confirm::new(HiresSection::ROW_ENABLE, true))
        )->withDraftContext($oddDraft);

        $this->assertSame(
            'from 512x1024 to 870x1740',
            $odd->valueAt($this->labelRow($odd, Lang::t(GenFormKeys::HIRES_OUTPUT_SIZE))),
        );
    }

    public function testThePreviewRowRecomputesFromTheLiveDraftThroughTheShell(): void
    {
        // DraftContextSection wiring: committing hr_scale on the Hires tab
        // must change the Output size cell without reopening anything.
        $form = MediaForm::open(MediaRequest::new()->withWidth(640)->withHeight(640));
        $form = $form->click(MediaForm::TAB_ZONE . '3');
        $scaled = $this->hiresScaledUp($form, 3.0);

        $row = $this->labelRow($scaled->sections()[3], Lang::t(GenFormKeys::HIRES_OUTPUT_SIZE));
        $this->assertSame('from 640x640 to 1920x1920', $scaled->sections()[3]->valueAt($row));
    }

    // ---- Refiner -----------------------------------------------------------------

    public function testRefinerOffEmitsNothingAtAll(): void
    {
        $section = RefinerSection::forDraft(MediaRequest::new());

        $this->assertSame([Lang::t(GenFormKeys::REFINER_CHECKPOINT)], $section->rows());
        $wire = $section->collect(MediaRequest::new())->toArray();
        $this->assertFalse(array_key_exists('refiner_checkpoint', $wire));
        $this->assertFalse(array_key_exists('refiner_switch_at', $wire));
    }

    public function testPickingARefinerCheckpointExposesAndFoldsTheSwitchRow(): void
    {
        $section = RefinerSection::forDraft(MediaRequest::new(), [
            FieldSpec::CHOICES_MODELS => ['sd_xl_base_1.0.safetensors'],
        ]);
        $picked = $this->asRefiner(
            $section->withField($this->selectedAt($section, 0, 'sd_xl_base_1.0.safetensors'))
        );

        $wire = $picked->collect(MediaRequest::new())->toArray();
        $this->assertSame('sd_xl_base_1.0.safetensors', $wire['refiner_checkpoint']);
        $this->assertSame(0.8, $wire['refiner_switch_at']);
        $this->assertCount(2, $picked->rows());
    }

    public function testSwitchingTheRefinerBackToNoneStaleClearsBothKeys(): void
    {
        $section = RefinerSection::forDraft(MediaRequest::new(), [
            FieldSpec::CHOICES_MODELS => ['refiner.safetensors'],
        ]);
        $on = $this->asRefiner($section->withField($this->selectedAt($section, 0, 'refiner.safetensors')));
        $committed = $on->collect(MediaRequest::new());

        $off = $this->asRefiner($on->withField($this->selectedAt($on, 0, FieldSpec::noneLabel())));
        $wire = $off->collect($committed)->toArray();
        $this->assertNull($wire['refiner_checkpoint']);
        $this->assertNull($wire['refiner_switch_at']);
    }

    // ---- fixtures -----------------------------------------------------------------

    private function extraOpen(): SeedSection
    {
        return SeedSection::forDraft(MediaRequest::new())
            ->withField(Confirm::new(SeedSection::ROW_DISCLOSE, true));
    }

    private function hiresOpen(): HiresSection
    {
        return HiresSection::forDraft(MediaRequest::new())
            ->withField(Confirm::new(HiresSection::ROW_ENABLE, true));
    }

    /** Commit a new hr_scale through the live form's Hires tab (edit cycle). */
    private function hiresScaledUp(MediaForm $form, float $scale): MediaForm
    {
        $hires = $this->asHires($form->sections()[3])
            ->withField(Confirm::new(HiresSection::ROW_ENABLE, true));
        $hires = $this->asHires($hires);
        $row = $this->labelRow($hires, Lang::t(GenFormKeys::HIRES_UPSCALE_BY));
        $field = $hires->fieldAt($row);
        $this->assertInstanceOf(\SugarCraft\Forms\Field\Slider::class, $field);

        $updated = $form->sections();
        $updated[3] = $this->asHires($hires->withField($field->withValue($scale)));

        return MediaForm::fromSections(array_values($updated), $form->draft());
    }

    /** @param SeedSection|HiresSection|RefinerSection $section */
    private function labelRow(object $section, string $label): int
    {
        $row = array_search($label, $section->rows(), true);
        $this->assertNotFalse($label === '' ? false : $row, "no row labelled {$label}");

        return (int) $row;
    }

    private function extraField(SeedSection $section, int $row, int $value): Field
    {
        $field = $section->fieldAt($row);
        $this->assertInstanceOf(Field\NumberField::class, $field);

        return $field->withValue($value);
    }

    /** Commit an option into a Select row of any section, typed back. */
    private function selectedAt(object $section, int $row, string $option): Field
    {
        $field = $section->fieldAt($row);
        $this->assertInstanceOf(Field\Select::class, $field);

        return $field->withSelected($option);
    }

    private function revealSampler(): HiresSection
    {
        $shown = $this->asHires(
            $this->hiresOpen()->withField(Confirm::new(HiresSection::ROW_SHOW_SAMPLER, true))
        );

        return $this->asHires($shown->withField(
            $this->selectedAt($shown, $this->labelRow($shown, Lang::t(GenFormKeys::HIRES_SAMPLING_METHOD)), 'DPM++ 2M')
        ));
    }

    private function asSeed(mixed $section): SeedSection
    {
        $this->assertInstanceOf(SeedSection::class, $section);

        return $section;
    }

    private function asHires(mixed $section): HiresSection
    {
        $this->assertInstanceOf(HiresSection::class, $section);

        return $section;
    }

    private function asRefiner(mixed $section): RefinerSection
    {
        $this->assertInstanceOf(RefinerSection::class, $section);

        return $section;
    }
}

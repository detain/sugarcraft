<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui\MediaForm;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Media\Sd\Client;
use SugarCraft\Crush\Tui\MediaForm\ChoiceFeed;
use SugarCraft\Crush\Tui\MediaForm\FieldSpec;
use SugarCraft\Crush\Tui\MediaForm\GenFormKeys;
use SugarCraft\Crush\Tui\MediaForm\MediaForm;
use SugarCraft\Crush\Tui\MediaForm\Sections\ImageSection;
use SugarCraft\Crush\Tui\MediaForm\Sections\InpaintSection;
use SugarCraft\Crush\Tui\MediaForm\Wave4Gate;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\RadioPills;
use SugarCraft\Forms\Field\Slider;
use SugarCraft\Crush\Theme;

/**
 * The W3.7 present-but-gated law: with {@see Wave4Gate} closed the img2img
 * and inpaint sections are ONE note row, ZERO editable widgets, and ZERO
 * collected values — and with the gate forced open (the explicit override
 * the tests use) the §2.7 vocabularies coerce exactly: radio labels bridge
 * to wire indexes, "Only masked" bridges to the DTO bool, and the masked-
 * content list is EXACTLY the four upstream options (conflict C-1: a fifth
 * option or a 5-way split would be a CRITICAL drift from A1111).
 */
final class ImageSectionGateTest extends TestCase
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

    // ---- §2.7 vocabularies --------------------------------------------------------

    public function testTheMaskedContentListIsExactlyTheFourUpstreamOptions(): void
    {
        $this->assertSame(
            ['fill', 'original', 'latent noise', 'latent nothing'],
            FieldSpec::fillOptions(),
            'conflict C-1: A1111 offers four masked-content modes, never a fifth',
        );
    }

    public function testTheResizeModeListCarriesTheFourVerbatimLabels(): void
    {
        $this->assertSame(
            ['Just resize', 'Crop and resize', 'Resize and fill', 'Just resize (latent upscale)'],
            FieldSpec::resizeModeOptions(),
        );
    }

    public function testRadioLabelsBridgeToTheirWireIndexes(): void
    {
        $modes = $this->wave4Spec('resize_mode');
        $fills = $this->wave4Spec('inpainting_fill');
        $invert = $this->wave4Spec('inpainting_mask_invert');

        $this->assertSame(1, $modes->applyTo(MediaRequest::new(), 'Crop and resize')->resizeMode());
        $this->assertSame(0, $fills->applyTo(MediaRequest::new(), 'fill')->inpaintingFill());
        $this->assertSame(1, $fills->applyTo(MediaRequest::new(), 'original')->inpaintingFill());
        $this->assertSame(3, $fills->applyTo(MediaRequest::new(), 'latent nothing')->inpaintingFill());
        $this->assertSame(1, $invert->applyTo(MediaRequest::new(), 'Inpaint not masked')->inpaintingMaskInvert());
    }

    public function testTheWireIndexReadsBackAsItsLabelAndBogusLabelsFoldToNull(): void
    {
        $modes = $this->wave4Spec('resize_mode');
        $roundTrip = $modes->valueFrom(MediaRequest::new()->withResizeMode(2));
        $this->assertSame('Resize and fill', $roundTrip);

        $carried = $modes->applyTo(MediaRequest::new(), 'not a mode');
        $wire = $carried->toArray();
        $this->assertArrayHasKey('resize_mode', $wire, 'a committed bogus label still clears the key');
        $this->assertNull($wire['resize_mode']);
    }

    public function testTheInpaintAreaLabelBridgesToTheDtoBool(): void
    {
        $area = $this->wave4Spec('inpaint_full_res');
        $options = FieldSpec::areaOptions();

        $this->assertFalse($area->applyTo(MediaRequest::new(), $options[0])->inpaintFullRes());
        $this->assertTrue($area->applyTo(MediaRequest::new(), $options[1])->inpaintFullRes());
        $this->assertSame($options[0], $area->valueFrom(MediaRequest::new()));
        $this->assertSame(
            $options[1],
            $area->valueFrom(MediaRequest::new()->withInpaintFullRes(true)),
            'true reads back as "Only masked"',
        );
    }

    public function testDenoisingStrengthIsOneWireFieldWithTwoContextDefaults(): void
    {
        $hires = null;
        foreach (FieldSpec::disclosureRows() as $spec) {
            if ($spec->key === 'denoising_strength') {
                $hires = $spec;
            }
        }
        $this->assertInstanceOf(FieldSpec::class, $hires, 'the hires registry row exists');

        $this->assertSame(0.7, $hires->valueFrom(MediaRequest::new()), 'hires context (§2.3)');
        $this->assertSame(0.75, $this->wave4Spec('denoising_strength')->valueFrom(MediaRequest::new()), 'img2img context (§2.6)');

        // One wire field: whichever section is open folds the shared key.
        $folded = $this->wave4Spec('denoising_strength')->applyTo(MediaRequest::new(), 0.42);
        $this->assertSame(0.42, $hires->valueFrom($folded));
    }

    // ---- gate split (W4.1: image open, inpaint gated) ------------------------------

    public function testTheWaveFourGateIsSplitImageOpenInpaintClosed(): void
    {
        $this->assertTrue(Wave4Gate::img2img(), 'W4.1 opened the img2img half');
        $this->assertFalse(Wave4Gate::inpaint(), 'the inpaint half waits for its own ingress (lane b)');
        $this->assertFalse(Wave4Gate::enabled(), 'the wave as a whole is still closed');

        // Default construction is the production polarity: open, source-led.
        $section = ImageSection::forDraft(MediaRequest::new());
        $this->assertNotSame(
            [Lang::t(GenFormKeys::GATE_WAVE4)],
            $section->rows(),
            'the img2img tab is no longer one note row in production',
        );

        // The explicit closed override still behaves under the W3.7 law.
        $closed = ImageSection::forDraft(MediaRequest::new(), null, false);
        $this->assertSame([Lang::t(GenFormKeys::GATE_WAVE4)], $closed->rows());
        $this->assertNull($closed->fieldAt(0), 'no widget to edit — Enter is inert');
        $this->assertSame('', $closed->valueAt(0));

        $held = MediaRequest::new()->withResizeMode(2);
        $this->assertSame($held->toArray(), $closed->collect($held)->toArray(), 'a closed gate is absent, not nulling');
    }

    public function testTheClosedInpaintGateHidesItsRowsAndNeverMintsTheTransparencyKey(): void
    {
        $section = InpaintSection::forDraft(MediaRequest::new());

        $this->assertSame([Lang::t(GenFormKeys::GATE_WAVE4)], $section->rows());
        $this->assertNull($section->fieldAt(0));
        $this->assertSame([], $section->collect(MediaRequest::new())->toArray());
    }

    public function testTheShellShowsTheGateSentenceOnBothTabsAndTheirLabelsInTheStrip(): void
    {
        $frame = $this->strippedFrame(MediaForm::open());
        $this->assertStringContainsString('img2img', $frame, 'the Image tab rides the strip');
        $this->assertStringContainsString('Inpaint', $frame, 'the Inpaint tab rides the strip');

        $inpaintTab = Ansi::strip(
            MediaForm::open()->click(MediaForm::TAB_ZONE . '6')->view(Theme::default(), 100, 40),
        );
        $this->assertStringContainsString('arrive in a later build', $inpaintTab);
    }

    // ---- gate forced open (the W4 preview) ----------------------------------------

    public function testTheOpenImageGateRendersItsRowsAndFoldsSectionTwentySixDefaults(): void
    {
        $section = ImageSection::forDraft(MediaRequest::new(), null, true);

        $this->assertSame(
            [
                Lang::t(GenFormKeys::IMAGE_SOURCE),
                Lang::t(GenFormKeys::FIELD_RESIZE_MODE),
                Lang::t(GenFormKeys::FIELD_DENOISING_STRENGTH),
                Lang::t(GenFormKeys::IMAGE_SHOW_CFG),
            ],
            $section->rows(),
            'W4.1: the attach row leads, image CFG scale stays hidden until revealed, and the reveal row closes the list',
        );

        $wire = $section->collect(MediaRequest::new())->toArray();
        $this->assertSame(0, $wire['resize_mode']);
        $this->assertSame(0.75, $wire['denoising_strength']);
        $this->assertArrayNotHasKey('image_cfg_scale', $wire);
        $this->assertArrayNotHasKey('init_images', $wire, 'zero sources never sets the img2img field');
    }

    public function testRevealingImageCfgExposesItAndHidingItAgainStaleClears(): void
    {
        $section = ImageSection::forDraft(MediaRequest::new(), null, true);
        $revealed = $this->asImage($section->withField(Confirm::new(ImageSection::ROW_SHOW_IMAGE_CFG, false)));

        $this->assertCount(5, $revealed->rows(), 'attach row + three lattice rows + the revealed CFG row');
        $this->assertSame(
            1.5,
            $revealed->collect(MediaRequest::new())->toArray()['image_cfg_scale'],
            '§2.6 UI default 1.5',
        );

        $tuned = $this->asImage($revealed->withField(
            Slider::new('image_cfg_scale', 0.0, 3.0, 0.05)->withValue(2.0),
        ));
        $carried = $tuned->collect(MediaRequest::new());
        $this->assertSame(2.0, $carried->toArray()['image_cfg_scale']);

        // The shell folds every commit into the live draft, so hide-again
        // collects against a draft that CARRIES the value — stale clearance
        // nulls it instead of letting a hidden 2.0 ride the wire.
        $hidden = $this->asImage($tuned->withField(Confirm::new(ImageSection::ROW_SHOW_IMAGE_CFG, false)));
        $wire = $hidden->collect($carried)->toArray();
        $this->assertArrayHasKey('image_cfg_scale', $wire, 'stale clearance folds null, never a hidden 2.0');
        $this->assertNull($wire['image_cfg_scale']);
    }

    public function testTheOpenInpaintGateFoldsTheSectionTwentySevenDefaults(): void
    {
        $section = InpaintSection::forDraft(MediaRequest::new(), null, true);

        $this->assertSame(
            [
                Lang::t(GenFormKeys::MASK_BLUR),
                Lang::t(GenFormKeys::FIELD_MASK_MODE),
                Lang::t(GenFormKeys::FIELD_MASKED_CONTENT),
                Lang::t(GenFormKeys::FIELD_INPAINT_AREA),
                Lang::t(GenFormKeys::AREA_PADDING),
                Lang::t(GenFormKeys::MASK_TRANSPARENCY),
            ],
            $section->rows(),
        );
        $this->assertSame(
            Lang::t(GenFormKeys::MASK_TRANSPARENCY_NOTE),
            $section->valueAt(5),
            'the transparency row is an editor-only note',
        );

        $wire = $section->collect(MediaRequest::new())->toArray();
        $this->assertSame(4, $wire['mask_blur']);
        $this->assertSame(0, $wire['inpainting_mask_invert']);
        $this->assertSame(1, $wire['inpainting_fill'], 'UI default is "original"');
        $this->assertFalse($wire['inpaint_full_res']);
        $this->assertSame(32, $wire['inpaint_full_res_padding']);
        $this->assertArrayNotHasKey('mask_transparency', $wire);
        $this->assertArrayNotHasKey('mask_transparency_note', $wire);
    }

    public function testChoosingOnlyMaskedReachesTheWireAsTrue(): void
    {
        $section = InpaintSection::forDraft(MediaRequest::new(), null, true);
        $options = FieldSpec::areaOptions();
        $picked = $this->asInpaint($section->withField(
            RadioPills::new('inpaint_full_res', ...$options)->withValue($options[1]),
        ));

        $this->assertTrue($picked->collect(MediaRequest::new())->toArray()['inpaint_full_res']);
    }

    public function testTheTransparencyNoteIsInertEvenWithTheGateOpen(): void
    {
        $section = InpaintSection::forDraft(MediaRequest::new(), null, true);

        $this->assertNull($section->fieldAt(5), 'a note row has no widget behind it');
    }

    // ---- fixtures -----------------------------------------------------------------

    private function wave4Spec(string $key): FieldSpec
    {
        foreach (FieldSpec::wave4Rows() as $spec) {
            if ($spec->key === $key) {
                return $spec;
            }
        }

        $this->fail('no wave4 registry row for ' . $key);
    }

    private function asImage(mixed $section): ImageSection
    {
        $this->assertInstanceOf(ImageSection::class, $section);

        return $section;
    }

    private function asInpaint(mixed $section): InpaintSection
    {
        $this->assertInstanceOf(InpaintSection::class, $section);

        return $section;
    }

    private function strippedFrame(MediaForm $form): string
    {
        return Ansi::strip($form->view(Theme::default(), 100, 40));
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui\MediaForm;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Media\MediaRequest;
use SugarCraft\Code\Media\Sd\Client;
use SugarCraft\Code\Tui\MediaForm\ChoiceFeed;
use SugarCraft\Code\Tui\MediaForm\DisplayOverrides;
use SugarCraft\Code\Tui\MediaForm\FieldSpec;
use SugarCraft\Code\Tui\MediaForm\MediaForm;
use SugarCraft\Code\Tui\MediaForm\Sections\ParamsSection;

/**
 * The W3.9 ui-config analog: `ui.mediaDisplayOverrides` entries keyed
 * "<section>/<Label>/<prop>" transform COPIES of the FieldSpec lattice at
 * section-build — ranges move, `value` pre-fills, `visible:false` drops the
 * row out of render AND collect, and every id that resolves to nothing
 * (stale, mistyped, or a verbatim A1111 string) is silently ignored (§5.5
 * tolerance parity: one dead key must never fail a generation).
 */
final class DisplayOverridesTest extends TestCase
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

    // ---- addressing ------------------------------------------------------------

    public function testTheElementIdIsTheSectionSlashedLabelAndKeysRideProps(): void
    {
        $cfg = $this->overrideSpec('cfg_scale');
        $element = DisplayOverrides::elementId($cfg);

        // A1111 convention: "<section>/<Label>" — derived live from the spec,
        // never a transcription of the translated text.
        $this->assertSame('params/' . $this->overrideLabel($cfg), $element);
        $this->assertSame($element . '/step', DisplayOverrides::keyFor($cfg, DisplayOverrides::PROP_STEP));
        foreach (DisplayOverrides::PROPS as $prop) {
            $this->assertStringEndsWith('/' . $prop, DisplayOverrides::keyFor($cfg, $prop));
        }
    }

    public function testAVerbatimA1111IdResolvesToNothingAndChangesNoRow(): void
    {
        // The plan's naming example is a gradio tab id our lattice never
        // mints; tolerance says it rides through as silence, not as an error.
        $baseline = ParamsSection::forDraft(MediaRequest::new());
        $overridden = $baseline->withDisplayOverrides(['txt2img/CFG Scale/step' => 0.5]);

        $this->assertSame($baseline->fieldValues(), $overridden->fieldValues());
        $this->assertSame($baseline->rows(), $overridden->rows());
    }

    public function testAStaleKeyForADroppedRowIsSilentlyIgnored(): void
    {
        $section = ParamsSection::forDraft(MediaRequest::new())
            ->withDisplayOverrides(['params/no such dial/minimum' => 99]);

        $this->assertSame(
            ParamsSection::forDraft(MediaRequest::new())->fieldValues(),
            $section->fieldValues(),
            'an id nothing resolves to changes nothing (§5.5)',
        );
    }

    // ---- prop semantics ----------------------------------------------------------

    public function testMaximumMovesTheClampAndTheOperatorValueSurvivesInRange(): void
    {
        $steps = $this->overrideSpec('steps');
        $clamped = ParamsSection::forDraft(MediaRequest::new())
            ->withDisplayOverrides([DisplayOverrides::keyFor($steps, DisplayOverrides::PROP_MAXIMUM) => 10]);

        // The untouched row sits on the OLD default (20), so the pre-fill law
        // hands it the new bounds and the widget clamps it down.
        $this->assertSame(10, $clamped->fieldValues()['steps']);

        // A value the operator actually moved inside the new range rides.
        $carried = ParamsSection::forDraft(MediaRequest::new())->withFieldValues(['steps' => 6])
            ->withDisplayOverrides([DisplayOverrides::keyFor($steps, DisplayOverrides::PROP_MAXIMUM) => 10]);
        $this->assertSame(6, $carried->fieldValues()['steps']);

        // And outside it, the widget's own coercion clamps — never an error.
        $over = ParamsSection::forDraft(MediaRequest::new())->withFieldValues(['steps' => 40])
            ->withDisplayOverrides([DisplayOverrides::keyFor($steps, DisplayOverrides::PROP_MAXIMUM) => 10]);
        $this->assertSame(10, $over->fieldValues()['steps']);
    }

    public function testTheValuePropPreFillsAFreshRow(): void
    {
        $steps = $this->overrideSpec('steps');
        $seeded = ParamsSection::forDraft(MediaRequest::new())
            ->withDisplayOverrides([DisplayOverrides::keyFor($steps, DisplayOverrides::PROP_VALUE) => 33]);

        $this->assertSame(33, $seeded->fieldValues()['steps']);
    }

    public function testVisibleFalseDropsTheRowFromRenderAndCollectBothPolarities(): void
    {
        $cfg = $this->overrideSpec('cfg_scale');
        $hideKey = DisplayOverrides::keyFor($cfg, DisplayOverrides::PROP_VISIBLE);

        $control = ParamsSection::forDraft(MediaRequest::new());
        $hidden = $control->withDisplayOverrides([$hideKey => false]);

        $this->assertArrayHasKey('cfg_scale', $control->fieldValues(), 'polarity: the unhidden row collects');
        $this->assertArrayNotHasKey('cfg_scale', $hidden->fieldValues(), 'visible:false leaves collect');
        $this->assertNotContains($this->overrideLabel($cfg), $hidden->rows(), 'visible:false leaves render');
        $this->assertContains($this->overrideLabel($cfg), $control->rows(), 'and the control still renders it');

        // Draft-side proof through the whole form: a hidden row cannot be
        // collected even when the draft carries a value for it.
        $form = MediaForm::open(
            MediaRequest::new()->fromArray(['cfg_scale' => 9.5]),
            displayOverrides: [$hideKey => false],
        );
        $this->assertArrayNotHasKey('cfg_scale', $form->collectValues());

        $shown = MediaForm::open(MediaRequest::new()->fromArray(['cfg_scale' => 9.5]));
        $this->assertSame(9.5, $shown->collectValues()['cfg_scale']);
    }

    public function testWrongShapedPropsReadAsNoOverrideAtAll(): void
    {
        $cfg = $this->overrideSpec('cfg_scale');
        $steps = $this->overrideSpec('steps');
        $expect = ParamsSection::forDraft(MediaRequest::new());

        $section = $expect->withDisplayOverrides([
            DisplayOverrides::keyFor($cfg, DisplayOverrides::PROP_VISIBLE) => 'yes',   // not a bool
            DisplayOverrides::keyFor($steps, DisplayOverrides::PROP_MINIMUM) => 'lots', // not numeric
            DisplayOverrides::keyFor($steps, DisplayOverrides::PROP_VALUE) => [],        // not a scalar
            DisplayOverrides::keyFor($steps, 'precision') => 2,                          // not a known prop
        ]);

        $this->assertSame($expect->fieldValues(), $section->fieldValues());
        $this->assertSame($expect->rows(), $section->rows());
    }

    public function testRangePropsOnlyBiteNumericWidgets(): void
    {
        // prompt is a Text row: minimum/maximum/step there are inert (§5.5).
        $prompt = $this->overrideSpec('prompt');
        $this->assertSame([], DisplayOverrides::forElement($prompt, [
            DisplayOverrides::keyFor($prompt, DisplayOverrides::PROP_MINIMUM) => 1,
            DisplayOverrides::keyFor($prompt, DisplayOverrides::PROP_MAXIMUM) => 9,
            DisplayOverrides::keyFor($prompt, DisplayOverrides::PROP_STEP) => 2,
        ]));

        // but a string value and a boolean visible are exactly its language.
        $props = DisplayOverrides::forElement($prompt, [
            DisplayOverrides::keyFor($prompt, DisplayOverrides::PROP_VALUE) => 'a test cat',
            DisplayOverrides::keyFor($prompt, DisplayOverrides::PROP_VISIBLE) => false,
        ]);
        $this->assertSame(['value' => 'a test cat', 'visible' => false], $props);
    }

    public function testAValueOverrideOnAPresetNameLikeMultiRowStaysInert(): void
    {
        // styles is the MULTI widget: `value` has no widget-matched arm, so
        // forElement drops it rather than attempting a coercion.
        $styles = $this->overrideSpec('styles');
        $this->assertSame([], DisplayOverrides::forElement($styles, [
            DisplayOverrides::keyFor($styles, DisplayOverrides::PROP_VALUE) => 'something',
        ]));
    }

    public function testTheUntouchedRowsKeepTheirWidgetsIdentityAndLabelsWithUnchangedProps(): void
    {
        $steps = $this->overrideSpec('steps');
        $section = ParamsSection::forDraft(MediaRequest::new())
            ->withDisplayOverrides([DisplayOverrides::keyFor($steps, DisplayOverrides::PROP_STEP) => 4]);

        $rows = $section->rows();
        $this->assertContains($this->overrideLabel($steps), $rows, 'the reshaped row still renders');
        $this->assertSame(
            ParamsSection::forDraft(MediaRequest::new())->rows(),
            $rows,
            'a range move never adds or drops a row',
        );
    }

    // ---- form-level plumbing ------------------------------------------------------

    public function testOpenFoldsOverridesAndAnEmptyMapIsTheIdentity(): void
    {
        $plain = MediaForm::open();
        $empty = MediaForm::open(displayOverrides: []);

        $this->assertSame($plain->collectValues(), $empty->collectValues());
        $this->assertSame(
            array_map($this->overrideSectionKey(...), $plain->sections()),
            array_map($this->overrideSectionKey(...), $empty->sections()),
        );
    }

    public function testHydrateRefusesToReviveAHiddenRow(): void
    {
        $cfg = $this->overrideSpec('cfg_scale');
        $form = MediaForm::open(displayOverrides: [
            DisplayOverrides::keyFor($cfg, DisplayOverrides::PROP_VISIBLE) => false,
        ]);

        $hydrated = $form->hydrateFields(['cfg_scale' => 7.5]);

        // The lattice simply has no door for the hidden key — the hydrate is a
        // no-op there and collect keeps the row out of the wire map.
        $this->assertArrayNotHasKey('cfg_scale', $hydrated->collectValues());
    }

    // ---- fixtures -------------------------------------------------------------------

    private function overrideSpec(string $key): FieldSpec
    {
        $lattice = [...FieldSpec::coreRows(), ...FieldSpec::disclosureRows(), ...FieldSpec::wave4Rows()];
        $found = array_filter($lattice, static fn (FieldSpec $s): bool => $s->key === $key);
        if ($found === []) {
            $this->fail("the registry has no row '{$key}'");
        }

        return reset($found);
    }

    private function overrideLabel(FieldSpec $spec): string
    {
        $section = $this->overrideSpec($spec->key);

        return Lang::t($section->labelKey);
    }

    private function overrideSectionKey(object $section): string
    {
        return $section->key();
    }
}

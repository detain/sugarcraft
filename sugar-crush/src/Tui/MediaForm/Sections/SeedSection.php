<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\MediaForm\Sections;

use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Tui\MediaForm\FieldSpec;
use SugarCraft\Crush\Tui\MediaForm\FieldValuedSection;
use SugarCraft\Crush\Tui\MediaForm\GenFormKeys;
use SugarCraft\Crush\Tui\MediaForm\Section;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\NumberField;
use SugarCraft\Forms\Field\Slider;

/**
 * The Extra disclosure (§2.1, crush-media W3.5): one "Extra" press-row opens
 * the variation-seed group — subseed, subseed_strength, seed_resize_from_w/h.
 *
 * WHY these four exist (§2.1 slerp semantics): with a subseed set, the
 * sampler's initial noise is the spherical interpolation
 *     noise = slerp(subseed_strength, randn(seed), randn(subseed))
 * so 0.0 stays pure `seed`, 1.0 becomes pure `subseed`, and anything between
 * morphs composition while keeping identity — the A1111 "Variation seed"
 * workflow. seed_resize_from_w/h go further: the noise is generated at that
 * latent pixel size and then center-cropped (or pasted) back to the output
 * size, so the same seed yields the same image shifted/rescaled rather than
 * re-rolled — zoom-and-pan over a fixed latent landscape.
 *
 * Collapsed law (W3.5 disclosure law, inherited): the closed group renders
 * only its gate row, contributes nothing to collect(), and any value the
 * user committed before closing folds to null via
 * {@see RegistrySection::clearGatedOff()} — never a stale subseed riding a
 * generation the user believes is plain.
 */
final class SeedSection implements Section, FieldValuedSection
{
    use RegistrySection {
        collect as registryCollect;
        fieldAt as registryFieldAt;
        accept as registryAccept;
        display as registryDisplay;
        extraLabel as registryExtraLabel;
    }


    private function __construct(
        protected readonly array $specs,
        protected readonly array $fields,
        protected readonly array $rowKeys,
    ) {
    }

    public const ROW_DISCLOSE = 'extra_disclose';

    public const GATED_KEYS = ['subseed', 'subseed_strength', 'seed_resize_from_w', 'seed_resize_from_h'];

    public static function forDraft(MediaRequest $draft): self
    {
        $specs = [];
        $fields = [];
        foreach (FieldSpec::disclosureRows() as $spec) {
            if (!$spec->belongsTo('extra')) {
                continue;
            }

            $specs[$spec->key] = $spec;
            $fields[$spec->key] = self::seededDisclosureField($spec, $draft);
        }

        // The gate starts closed unless the draft already carries a value —
        // an imported infotext with a subseed must not hide what it set.
        $open = self::draftCarriesVariation($draft);
        $rowKeys = array_merge([self::ROW_DISCLOSE], array_keys($specs));
        $fields[self::ROW_DISCLOSE] = Confirm::new(self::ROW_DISCLOSE, $open)
            ->withTitle(Lang::t(GenFormKeys::SECTION_EXTRA));

        return new self($specs, $fields, $rowKeys);
    }

    public function key(): string
    {
        return 'extra';
    }

    public function label(): string
    {
        return Lang::t(GenFormKeys::SECTION_EXTRA);
    }

    public function collect(MediaRequest $draft): MediaRequest
    {
        return $this->clearGatedOff($this->registryCollect($draft));
    }

    protected function visibleRowKeys(): array
    {
        return $this->gateOpen() ? $this->rowKeys : [self::ROW_DISCLOSE];
    }

    protected function editableSpecs(): array
    {
        if ($this->gateOpen()) {
            return $this->specs;
        }

        return array_diff_key($this->specs, array_flip(self::GATED_KEYS));
    }

    /** Pressing the "Extra" row flips the group open/closed (a state row). */
    protected function accept(string $key, Field $field): static
    {
        return $key === self::ROW_DISCLOSE
            ? $this->rebuild([self::ROW_DISCLOSE => $this->flippedGate()])
            : $this->registryAccept($key, $field);
    }

    protected function extraLabel(string $key): string
    {
        return $key === self::ROW_DISCLOSE
            ? Lang::t(GenFormKeys::SECTION_EXTRA)
            : throw new \InvalidArgumentException('extra has no row ' . $key);
    }

    /** The gate row's own cell shows the disclosure state, like a checkbox. */
    protected function display(string $key): string
    {
        return $key === self::ROW_DISCLOSE
            ? self::boolCell($this->gateOpen())
            : $this->registryDisplay($key);
    }

    private function gateOpen(): bool
    {
        return (bool) $this->fields[self::ROW_DISCLOSE]?->value();
    }

    private function flippedGate(): Confirm
    {
        return Confirm::new(self::ROW_DISCLOSE, !$this->gateOpen())
            ->withTitle(Lang::t(GenFormKeys::SECTION_EXTRA));
    }

    private static function disclosureNumber(FieldSpec $spec, mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        throw new \LogicException('extra row ' . $spec->key . ' default is not numeric');
    }

    private static function draftCarriesVariation(MediaRequest $draft): bool
    {
        foreach (self::GATED_KEYS as $key) {
            if ($draft->isSet($key)) {
                return true;
            }
        }

        return false;
    }

    private static function seededDisclosureField(FieldSpec $spec, MediaRequest $draft): Field
    {
        $label = Lang::t($spec->labelKey);
        $field = $spec->createField($spec->key);
        $value = $spec->valueFrom($draft);

        if ($field instanceof Slider || $field instanceof NumberField) {
            return $field->withTitle($label)->withValue(self::disclosureNumber($spec, $value));
        }

        throw new \LogicException('extra row ' . $spec->key . ' carries a non-numeric widget');
    }
}

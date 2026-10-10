<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\MediaForm\Sections;

use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Tui\MediaForm\FieldSpec;
use SugarCraft\Crush\Tui\MediaForm\GenFormKeys;
use SugarCraft\Crush\Tui\MediaForm\Wave4Gate;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\NumberField;
use SugarCraft\Forms\Field\RadioPills;
use SugarCraft\Forms\Field\Slider;

/**
 * The img2img group (§2.6/§2.7, crush-media W3.7) — PRESENT-BUT-GATED:
 * while {@see Wave4Gate} is closed this section renders as a single note
 * row, exposes zero editable widgets (fieldAt answers null, so Enter is a
 * no-op) and collects zero values (the draft rides through untouched —
 * pre-W4 a draft cannot carry these keys, so identity, not stale-clearance,
 * is the honest shape).
 *
 * Open, the group is resize_mode (§2.7's four verbatim labels bridged to the
 * 0–3 wire index) and denoising strength at its img2img context default 0.75
 * — the same wire field as the hires row's 0.7 (FieldSpec::disclosureRows()
 * carries the ruling). "Image CFG scale" reproduces A1111's `enable_image_cfg`
 * checkbox as form state: hidden until revealed (the edit-model hint the
 * webui gates on model type becomes an explicit user reveal in a terminal
 * that cannot ask the server cheaply).
 *
 * The §2.6 source rows (upload, batch dir, detect size, scale) wait for W4
 * image ingress — there is nothing to point them at yet.
 */
final class ImageSection extends RegistrySection
{
    public const ROW_GATE_NOTE = 'image_gate_note';
    public const ROW_SHOW_IMAGE_CFG = 'image_show_cfg';

    private const CFG_KEY = 'image_cfg_scale';

    private function __construct(
        array $specs,
        array $fields,
        array $rowKeys,
        private readonly bool $enabled,
    ) {
        parent::__construct($specs, $fields, $rowKeys);
    }

    /**
     * @param array<string, list<string>>|null $runtimeChoices unused here —
     *        the group is range/radio only; kept for the registration shape.
     * @param bool|null $enabled override to pin both gate polarities in tests.
     */
    public static function forDraft(MediaRequest $draft, ?array $runtimeChoices = null, ?bool $enabled = null): self
    {
        $enabled ??= Wave4Gate::enabled();
        $specs = [];
        $fields = [];
        $rowKeys = [];
        foreach (FieldSpec::wave4Rows() as $spec) {
            if (!$spec->belongsTo('image')) {
                continue;
            }

            $specs[$spec->key] = $spec;
            $fields[$spec->key] = self::seededImageField($spec, $draft);
            $rowKeys[] = $spec->key;
        }

        $fields[self::ROW_SHOW_IMAGE_CFG] = Confirm::new(self::ROW_SHOW_IMAGE_CFG, false)
            ->withTitle(Lang::t(GenFormKeys::IMAGE_SHOW_CFG));
        $rowKeys[] = self::ROW_SHOW_IMAGE_CFG;

        return new self($specs, $fields, $rowKeys, $enabled);
    }

    public function key(): string
    {
        return 'image';
    }

    public function label(): string
    {
        return Lang::t(GenFormKeys::SECTION_IMAGE);
    }

    public function collect(MediaRequest $draft): MediaRequest
    {
        // Gate closed the feature is ABSENT, not collapsed: identity beats
        // clearGatedOff because no pre-W4 draft can carry these keys, and a
        // future one that does must not be silently nulled by a closed tab.
        return $this->enabled ? $this->clearGatedOff(parent::collect($draft)) : $draft;
    }

    protected function visibleRowKeys(): array
    {
        if (!$this->enabled) {
            return [self::ROW_GATE_NOTE];
        }

        $keys = [];
        foreach ($this->rowKeys as $key) {
            if ($key === self::CFG_KEY && !$this->revealed()) {
                continue;
            }

            $keys[] = $key;
        }

        return $keys;
    }

    public function fieldAt(int $row): ?Field
    {
        return $this->enabled ? parent::fieldAt($row) : null;
    }

    protected function editableSpecs(): array
    {
        if (!$this->enabled) {
            return [];
        }

        return $this->revealed()
            ? $this->specs
            : array_diff_key($this->specs, [self::CFG_KEY => null]);
    }

    /** Pressing the reveal checkbox flips it; the gate row is inert. */
    protected function accept(string $key, Field $field): static
    {
        return match ($key) {
            self::ROW_SHOW_IMAGE_CFG => $this->rebuild([
                self::ROW_SHOW_IMAGE_CFG => Confirm::new($key, !$this->revealed())
                    ->withTitle(Lang::t(GenFormKeys::IMAGE_SHOW_CFG)),
            ]),
            self::ROW_GATE_NOTE => $this,
            default => parent::accept($key, $field),
        };
    }

    protected function extraLabel(string $key): string
    {
        return match ($key) {
            self::ROW_GATE_NOTE => Lang::t(GenFormKeys::GATE_WAVE4),
            self::ROW_SHOW_IMAGE_CFG => Lang::t(GenFormKeys::IMAGE_SHOW_CFG),
            default => throw new \InvalidArgumentException('image has no row ' . $key),
        };
    }

    protected function display(string $key): string
    {
        // The gate sentence rides the LABEL column; the value cell stays empty.
        return $key === self::ROW_GATE_NOTE ? '' : parent::display($key);
    }

    private function revealed(): bool
    {
        return (bool) $this->fields[self::ROW_SHOW_IMAGE_CFG]?->value();
    }

    private static function seededImageField(FieldSpec $spec, MediaRequest $draft): Field
    {
        $label = Lang::t($spec->labelKey);
        $value = $spec->valueFrom($draft);
        $field = $spec->createField($spec->key);

        if ($field instanceof RadioPills) {
            return $field->withTitle($label)->withValue((string) $value);
        }

        if ($field instanceof Slider || $field instanceof NumberField) {
            if (!is_int($value) && !is_float($value)) {
                throw new \LogicException('image row ' . $spec->key . ' default is not numeric');
            }

            return $field->withTitle($label)->withValue($value);
        }

        throw new \LogicException('image row ' . $spec->key . ' carries an unbuilt widget');
    }

    protected function rebuild(array $changes): static
    {
        return new self($this->specs, [...$this->fields, ...$changes], $this->rowKeys, $this->enabled);
    }
}

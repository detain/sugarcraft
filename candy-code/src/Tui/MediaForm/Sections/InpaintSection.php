<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\MediaForm\Sections;

use SugarCraft\Code\Lang;
use SugarCraft\Code\Media\MediaRequest;
use SugarCraft\Code\Tui\MediaForm\FieldSpec;
use SugarCraft\Code\Tui\MediaForm\FieldValuedSection;
use SugarCraft\Code\Tui\MediaForm\GenFormKeys;
use SugarCraft\Code\Tui\MediaForm\Section;
use SugarCraft\Code\Tui\MediaForm\Wave4Gate;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\Field\NumberField;
use SugarCraft\Forms\Field\RadioPills;
use SugarCraft\Forms\Field\Slider;

/**
 * The inpaint group (§2.7, crush-media W3.7) — gated exactly like
 * {@see ImageSection}: closed gate means one note row, no widgets, no
 * collects. Production opened this half with W4.3 (mask ingress on the image
 * tab); tests pin the closed polarity through the explicit `$enabled`
 * override, never by mutating the gate.
 *
 * Open, it carries mask blur (0–64, default 4), the mask mode pair, the
 * masked-content radio — EXACTLY the four §2.7 options fill / original /
 * latent noise / latent nothing, UI default "original", wire index =
 * position (a fifth option or 5-way split would be Forge/SD.Next, not
 * stock A1111: C-1 CRITICAL, pinned in the gate test) — the inpaint area
 * pair bridged to the one BOOL field ("Only masked" = true), its padding
 * slider (0–256, step 4, default 32), and a final read-only row explaining
 * that mask transparency is editor-only: A1111 keeps it client-side, so it
 * is a note here and NEVER a collected key.
 *
 * TWO §4.3 TRAPS this group deliberately rides:
 * - SINGLE-BLUR LAW: the UI shows ONE blur slider and the form sends only
 *   `mask_blur`; the per-axis API pair `mask_blur_x`/`mask_blur_y` stays in
 *   the override-settings escape hatch (§2.1 rows), never a registry field.
 * - MASK_ROUND DEFAULT: `mask_round` defaults TRUE server-side and A1111
 *   applies the blur BEFORE the rounding pass, so with round left at its
 *   default a large blur is partially re-hardened — this is upstream
 *   behaviour, not our bug. We do not expose mask_round in the lattice (§2.7
 *   row list omits it); anyone tempted to "fix" the blur as ineffective must
 *   read the override hatch, not delete this note.
 *
 * The mask FILE itself is an upload and lives on ImageSection's mask rows
 * (W4.3); this tab only parameterises it.
 */
final class InpaintSection implements Section, FieldValuedSection
{
    use RegistrySection {
        collect as registryCollect;
        fieldAt as registryFieldAt;
        accept as registryAccept;
        display as registryDisplay;
        extraLabel as registryExtraLabel;
    }

    public const ROW_GATE_NOTE = 'inpaint_gate_note';
    public const ROW_TRANSPARENCY = 'mask_transparency_note';

    private function __construct(
        protected readonly array $specs,
        protected readonly array $fields,
        protected readonly array $rowKeys,
        private readonly bool $enabled,
    ) {

    }

    /**
     * @param bool|null $enabled override to pin both gate polarities in tests.
     */
    public static function forDraft(MediaRequest $draft, ?array $runtimeChoices = null, ?bool $enabled = null): self
    {
        $enabled ??= Wave4Gate::inpaint();
        $specs = [];
        $fields = [];
        $rowKeys = [];
        foreach (FieldSpec::wave4Rows() as $spec) {
            if (!$spec->belongsTo('inpaint')) {
                continue;
            }

            $specs[$spec->key] = $spec;
            $fields[$spec->key] = self::seededInpaintField($spec, $draft);
            $rowKeys[] = $spec->key;
        }

        // Editor-only display row: no field, no spec — fieldAt answers null
        // through the base map miss, collect never sees a key for it.
        $rowKeys[] = self::ROW_TRANSPARENCY;

        return new self($specs, $fields, $rowKeys, $enabled);
    }

    public function key(): string
    {
        return 'inpaint';
    }

    public function label(): string
    {
        return Lang::t(GenFormKeys::SECTION_INPAINT);
    }

    public function collect(MediaRequest $draft): MediaRequest
    {
        return $this->enabled ? $this->clearGatedOff($this->registryCollect($draft)) : $draft;
    }

    protected function visibleRowKeys(): array
    {
        return $this->enabled ? $this->rowKeys : [self::ROW_GATE_NOTE];
    }

    public function fieldAt(int $row): ?Field
    {
        return $this->enabled ? $this->registryFieldAt($row) : null;
    }

    protected function editableSpecs(): array
    {
        return $this->enabled ? $this->specs : [];
    }

    protected function extraLabel(string $key): string
    {
        return match ($key) {
            self::ROW_GATE_NOTE => Lang::t(GenFormKeys::GATE_WAVE4),
            self::ROW_TRANSPARENCY => Lang::t(GenFormKeys::MASK_TRANSPARENCY),
            default => throw new \InvalidArgumentException('inpaint has no row ' . $key),
        };
    }

    protected function display(string $key): string
    {
        return match ($key) {
            // The gate sentence rides the LABEL column; the cell stays empty.
            self::ROW_GATE_NOTE => '',
            self::ROW_TRANSPARENCY => Lang::t(GenFormKeys::MASK_TRANSPARENCY_NOTE),
            default => $this->registryDisplay($key),
        };
    }

    private static function seededInpaintField(FieldSpec $spec, MediaRequest $draft): Field
    {
        $label = Lang::t($spec->labelKey);
        $value = $spec->valueFrom($draft);
        $field = $spec->createField($spec->key);

        if ($field instanceof RadioPills) {
            return $field->withTitle($label)->withValue((string) $value);
        }

        if ($field instanceof Slider || $field instanceof NumberField) {
            if (!is_int($value) && !is_float($value)) {
                throw new \LogicException('inpaint row ' . $spec->key . ' default is not numeric');
            }

            return $field->withTitle($label)->withValue($value);
        }

        throw new \LogicException('inpaint row ' . $spec->key . ' carries an unbuilt widget');
    }

    protected function rebuild(array $changes): static
    {
        return new self($this->specs, [...$this->fields, ...$changes], $this->rowKeys, $this->enabled);
    }

    protected function rebuildLattice(array $specs, array $fields, array $rowKeys): static
    {
        return new self($specs, $fields, $rowKeys, $this->enabled);
    }
}

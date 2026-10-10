<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\MediaForm\Sections;

use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Media\ImageIngress;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Tui\MediaForm\FieldSpec;
use SugarCraft\Crush\Tui\MediaForm\FieldValuedSection;
use SugarCraft\Crush\Tui\MediaForm\GenFormKeys;
use SugarCraft\Crush\Tui\MediaForm\Section;
use SugarCraft\Crush\Tui\MediaForm\Wave4Gate;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\FilePicker;
use SugarCraft\Forms\Field\NumberField;
use SugarCraft\Forms\Field\RadioPills;
use SugarCraft\Forms\Field\Slider;

/**
 * The img2img group (§2.6/§2.7, crush-media W3.7, ingress live in W4.1).
 *
 * While {@see Wave4Gate::img2img()} is closed — pinned per-test via the
 * `$enabled` override — the section is a single note row with zero widgets
 * and a collect that is the identity. Open, it leads with the §2.6 source
 * rows: an attach row whose widget is candy-forms' {@see FilePicker} (Enter
 * browses, Enter again commits the selection), one inert note row per
 * attached image, and a clear press-row. Attached bytes fold into the draft
 * as raw-base64 `init_images` entries in pick order (§4.3's multi-image
 * batch); a refused file never occupies a slot — its message lands on the
 * attach row's value cell instead.
 *
 * The picker browses the filesystem inside its own widget, as candy-forms
 * designed it (W4.1 deviation from the plan's host-modal sketch, disclosed
 * in the lane report: crush has no App-hosted file modal and building one
 * dwarfs "minimal host edits"; the SSRF ruling is enforced at
 * {@see ImageIngress}, not by which widget picks the path).
 *
 * Behind the source rows sit the registry rows: resize_mode (§2.7's four
 * verbatim labels bridged to the 0–3 wire index) and denoising strength at
 * its img2img context default 0.75 — the same wire field as the hires row's
 * 0.7 (FieldSpec::disclosureRows() carries the ruling). "Image CFG scale"
 * reproduces A1111's `enable_image_cfg` checkbox as form state: hidden
 * until revealed (the edit-model hint the webui gates on model type becomes
 * an explicit user reveal in a terminal that cannot ask the server cheaply).
 */
final class ImageSection implements Section, FieldValuedSection
{
    use RegistrySection {
        collect as registryCollect;
        fieldAt as registryFieldAt;
        accept as registryAccept;
        display as registryDisplay;
        extraLabel as registryExtraLabel;
    }

    public const ROW_GATE_NOTE = 'image_gate_note';
    public const ROW_SHOW_IMAGE_CFG = 'image_show_cfg';
    public const ROW_ATTACH = 'image_attach';
    public const ROW_CLEAR = 'image_clear';

    /** Prefix of the inert per-image note rows; full key is prefix + index. */
    public const ROW_SOURCE_PREFIX = 'image_source_';

    private const CFG_KEY = 'image_cfg_scale';

    /**
     * @param list<ImageIngress> $sources attached images, pick order
     */
    private function __construct(
        protected readonly array $specs,
        protected readonly array $fields,
        protected readonly array $rowKeys,
        private readonly bool $enabled,
        private readonly array $sources = [],
        private readonly ?string $attachError = null,
    ) {

    }

    /**
     * @param array<string, list<string>>|null $runtimeChoices unused here —
     *        the group is range/radio only; kept for the registration shape.
     * @param bool|null $enabled override to pin both gate polarities in tests.
     */
    public static function forDraft(MediaRequest $draft, ?array $runtimeChoices = null, ?bool $enabled = null): self
    {
        $enabled ??= Wave4Gate::img2img();
        $specs = [];
        $fields = [];
        $rowKeys = [];

        if ($enabled) {
            // Source rows lead the group (§2.6's upload-first order); they
            // are section-local, never registry specs, so preset hydration
            // and collectValues() can never touch payload bytes.
            $rowKeys[] = self::ROW_ATTACH;
            $fields[self::ROW_ATTACH] = self::pickerField(getcwd() ?: '.');
        }

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

        if ($enabled) {
            $fields[self::ROW_CLEAR] = Confirm::new(self::ROW_CLEAR, false)
                ->withTitle(Lang::t(GenFormKeys::IMAGE_CLEAR));
            // Owned by the section (the accept door gates on rowKeys
            // membership) though only conditionally VISIBLE — the
            // visibleRowKeys pass skips this key and slots it after the
            // source rows when any source exists.
            $rowKeys[] = self::ROW_CLEAR;
        }

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

    /** @return list<ImageIngress> */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * Admit an already-validated ingress (the W4.2 clipboard paste arrives
     * this way; the picker commits go through {@see self::accept()}).
     */
    public function withIngress(ImageIngress $ingress): self
    {
        return $this->mutate(sources: [...$this->sources, $ingress], attachError: null);
    }

    public function collect(MediaRequest $draft): MediaRequest
    {
        // Gate closed the feature is ABSENT, not collapsed: identity beats
        // clearGatedOff because no pre-W4 draft can carry these keys, and a
        // future one that does must not be silently nulled by a closed tab.
        if (!$this->enabled) {
            return $draft;
        }

        $draft = $this->clearGatedOff($this->registryCollect($draft));
        if ($this->sources !== []) {
            $draft = $draft->withInitImages(array_map(
                static fn (ImageIngress $source): string => $source->base64,
                $this->sources,
            ));
        }

        return $draft;
    }

    protected function visibleRowKeys(): array
    {
        if (!$this->enabled) {
            return [self::ROW_GATE_NOTE];
        }

        $keys = [self::ROW_ATTACH];
        foreach ($this->sources as $index => $source) {
            $keys[] = self::ROW_SOURCE_PREFIX . $index;
        }

        if ($this->sources !== []) {
            $keys[] = self::ROW_CLEAR;
        }

        foreach ($this->rowKeys as $key) {
            if ($key === self::ROW_ATTACH || $key === self::ROW_CLEAR) {
                continue;
            }

            if ($key === self::CFG_KEY && !$this->revealed()) {
                continue;
            }

            $keys[] = $key;
        }

        return $keys;
    }

    public function fieldAt(int $row): ?Field
    {
        return $this->enabled ? $this->registryFieldAt($row) : null;
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

    /**
     * A committed picker means the user finished a browse: validate through
     * {@see ImageIngress} and take the slot, or land the refusal on the row.
     * Either way the picker resets (fresh selection, browsed directory kept)
     * so the next Enter cannot re-add the same file. The clear row is a
     * press-row; source note rows and the gate row are inert.
     */
    protected function accept(string $key, Field $field): static
    {
        return match ($key) {
            self::ROW_ATTACH => $field instanceof FilePicker && $field->value() !== null
                ? $this->takePath((string) $field->value())
                : $this,
            self::ROW_CLEAR => $this->mutate(
                fields: [self::ROW_CLEAR => Confirm::new($key, false)->withTitle(Lang::t(GenFormKeys::IMAGE_CLEAR))],
                sources: [],
                attachError: null,
            ),
            self::ROW_SHOW_IMAGE_CFG => $this->rebuild([
                self::ROW_SHOW_IMAGE_CFG => Confirm::new($key, !$this->revealed())
                    ->withTitle(Lang::t(GenFormKeys::IMAGE_SHOW_CFG)),
            ]),
            self::ROW_GATE_NOTE => $this,
            default => $this->registryAccept($key, $field),
        };
    }

    protected function extraLabel(string $key): string
    {
        if (str_starts_with($key, self::ROW_SOURCE_PREFIX)) {
            $source = $this->sources[(int) substr($key, \strlen(self::ROW_SOURCE_PREFIX))] ?? null;

            return $source === null ? '' : $source->name();
        }

        return match ($key) {
            self::ROW_GATE_NOTE => Lang::t(GenFormKeys::GATE_WAVE4),
            self::ROW_SHOW_IMAGE_CFG => Lang::t(GenFormKeys::IMAGE_SHOW_CFG),
            self::ROW_ATTACH => Lang::t(GenFormKeys::IMAGE_SOURCE),
            self::ROW_CLEAR => Lang::t(GenFormKeys::IMAGE_CLEAR),
            default => throw new \InvalidArgumentException('image has no row ' . $key),
        };
    }

    protected function display(string $key): string
    {
        if (str_starts_with($key, self::ROW_SOURCE_PREFIX)) {
            $source = $this->sources[(int) substr($key, \strlen(self::ROW_SOURCE_PREFIX))] ?? null;

            return $source === null ? '' : $source->dimensions() . ' · ' . $source->mime;
        }

        // The gate sentence rides the LABEL column; the value cell stays empty.
        return match ($key) {
            self::ROW_GATE_NOTE => '',
            self::ROW_ATTACH => $this->attachError !== null
                ? '! ' . $this->attachError
                : (count($this->sources) . ' × ' . Lang::t(GenFormKeys::IMAGE_SOURCE)),
            default => $this->registryDisplay($key),
        };
    }

    private function takePath(string $path): self
    {
        try {
            $ingress = ImageIngress::fromPath($path);
        } catch (\InvalidArgumentException | \RuntimeException $error) {
            return $this->mutate(
                fields: [self::ROW_ATTACH => self::pickerField(dirname($path))],
                attachError: $error->getMessage(),
            );
        }

        return $this->mutate(
            fields: [self::ROW_ATTACH => self::pickerField(dirname($path))],
            sources: [...$this->sources, $ingress],
            attachError: null,
        );
    }

    private static function pickerField(string $cwd): FilePicker
    {
        return FilePicker::new(self::ROW_ATTACH, $cwd)
            ->exts(ImageIngress::EXTENSIONS)
            ->dirAllowed(false)
            ->fileAllowed(true)
            ->title(Lang::t(GenFormKeys::IMAGE_SOURCE));
    }

    /**
     * @param array<string, Field> $fields
     * @param list<ImageIngress>|null $sources
     */
    private function mutate(array $fields = [], ?array $sources = null, ?string $attachError = 'SENTINEL'): self
    {
        return new self(
            $this->specs,
            [...$this->fields, ...$fields],
            $this->rowKeys,
            $this->enabled,
            $sources ?? $this->sources,
            $attachError === 'SENTINEL' ? $this->attachError : $attachError,
        );
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
        return new self(
            $this->specs,
            [...$this->fields, ...$changes],
            $this->rowKeys,
            $this->enabled,
            $this->sources,
            $this->attachError,
        );
    }

    protected function rebuildLattice(array $specs, array $fields, array $rowKeys): static
    {
        return new self($specs, $fields, $rowKeys, $this->enabled, $this->sources, $this->attachError);
    }
}

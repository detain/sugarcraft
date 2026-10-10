<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\MediaForm\Sections;

use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Tui\MediaForm\ChoiceFeed;
use SugarCraft\Crush\Tui\MediaForm\DraftContextSection;
use SugarCraft\Crush\Tui\MediaForm\Enum\Samplers;
use SugarCraft\Crush\Tui\MediaForm\Enum\Schedulers;
use SugarCraft\Crush\Tui\MediaForm\FieldSpec;
use SugarCraft\Crush\Tui\MediaForm\GenFormKeys;
use SugarCraft\Crush\Tui\MediaForm\HiresMath;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\NumberField;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Field\Slider;
use SugarCraft\Forms\Field\Text;

/**
 * The Hires. fix accordion (§2.1/§2.3/§2.4, crush-media W3.5): the enable_hr
 * press-row gates the whole second-pass group — upscaler (the six §2.8
 * latent modes verbatim, joined by the server's /upscalers roster through
 * {@see ChoiceFeed}), hires steps (0 = reuse the main count), the shared
 * `denoising_strength` field at its hires context default 0.7 (the img2img
 * context default 0.75 belongs to W3.7's gated group — one wire field,
 * context-dependent UI default, pinned in FieldSpec::disclosureRows()),
 * upscale-by (1–4, step 0.05, default 2), and the two resize-to sliders.
 *
 * Two A1111 reveal checkboxes are reproduced as form state, never wire
 * values (their `hires_fix_show_*` settings analogues are personal config,
 * not request fields): "Show extra checkpoint/sampling method/prompts" gate
 * the seven advanced rows. A gated-away row renders not, collects not, and
 * stale-clears to null (§2.3's own rule for the second-pass sampler rows).
 * The three reveal dropdowns carry the §2.1 "Use same …" sentinel as option
 * zero, which folds to wire null — the same thing omitting the field means.
 *
 * The final "Output size" row is a live recompute ({@see HiresMath}, §2.4)
 * over the draft MediaForm committed last — it previews the request, it is
 * never sent.
 */
final class HiresSection extends RegistrySection implements DraftContextSection
{
    public const ROW_ENABLE = 'enable_hr';
    public const ROW_SHOW_CHECKPOINT = 'hires_show_checkpoint';
    public const ROW_SHOW_SAMPLER = 'hires_show_sampler';
    public const ROW_SHOW_PROMPTS = 'hires_show_prompts';
    public const ROW_FINAL_RES = 'hires_final_resolution';

    /** Rows revealed only while enable_hr is on (§2.1 accordion). */
    private const CHILD_KEYS = [
        'hr_upscaler',
        'hr_second_pass_steps',
        'denoising_strength',
        'hr_scale',
        'hr_resize_x',
        'hr_resize_y',
        self::ROW_SHOW_CHECKPOINT,
        'hr_checkpoint_name',
        self::ROW_SHOW_SAMPLER,
        'hr_sampler_name',
        'hr_scheduler',
        self::ROW_SHOW_PROMPTS,
        'hr_prompt',
        'hr_negative_prompt',
        self::ROW_FINAL_RES,
    ];

    /** Which reveal checkbox gates which advanced row (§2.1). */
    private const REVEAL_GATES = [
        'hr_checkpoint_name' => self::ROW_SHOW_CHECKPOINT,
        'hr_sampler_name' => self::ROW_SHOW_SAMPLER,
        'hr_scheduler' => self::ROW_SHOW_SAMPLER,
        'hr_prompt' => self::ROW_SHOW_PROMPTS,
        'hr_negative_prompt' => self::ROW_SHOW_PROMPTS,
    ];

    /**
     * @param array<string, list<string>>|null $runtimeChoices
     */
    private function __construct(
        array $specs,
        array $fields,
        array $rowKeys,
        private readonly ?MediaRequest $context = null,
        private readonly ?array $runtimeChoices = null,
    ) {
        parent::__construct($specs, $fields, $rowKeys);
    }

    /**
     * @param array<string, list<string>>|null $runtimeChoices a
     *        {@see ChoiceFeed} map: "upscalers" replaces the static six,
     *        "models" fills the checkpoint dropdown, samplers/schedulers
     *        extend the reveal dropdowns after their "Use same …" sentinel.
     */
    public static function forDraft(MediaRequest $draft, ?array $runtimeChoices = null): self
    {
        $specs = [];
        $fields = [];
        foreach (FieldSpec::disclosureRows() as $spec) {
            if (!$spec->belongsTo('hires')) {
                continue;
            }

            $specs[$spec->key] = $spec;
            $fields[$spec->key] = self::seededHiresField($spec, $draft, $runtimeChoices);
        }

        $fields[self::ROW_SHOW_CHECKPOINT] = self::revealRow(self::ROW_SHOW_CHECKPOINT, GenFormKeys::HIRES_SHOW_CHECKPOINT);
        $fields[self::ROW_SHOW_SAMPLER] = self::revealRow(self::ROW_SHOW_SAMPLER, GenFormKeys::HIRES_SHOW_SAMPLER);
        $fields[self::ROW_SHOW_PROMPTS] = self::revealRow(self::ROW_SHOW_PROMPTS, GenFormKeys::HIRES_SHOW_PROMPTS);

        $rowKeys = [self::ROW_ENABLE, ...self::CHILD_KEYS];

        return new self($specs, $fields, $rowKeys, $draft, $runtimeChoices);
    }

    public function key(): string
    {
        return 'hires';
    }

    public function label(): string
    {
        return Lang::t(GenFormKeys::SECTION_HIRES);
    }

    public function withDraftContext(MediaRequest $context): DraftContextSection
    {
        return new self($this->specs, $this->fields, $this->rowKeys, $context, $this->runtimeChoices);
    }

    public function collect(MediaRequest $draft): MediaRequest
    {
        return $this->clearGatedOff(parent::collect($draft));
    }

    protected function visibleRowKeys(): array
    {
        if (!$this->enabled()) {
            return [self::ROW_ENABLE];
        }

        return array_values(array_filter(
            $this->rowKeys,
            fn (string $key): bool => !isset(self::REVEAL_GATES[$key]) || $this->revealed(self::REVEAL_GATES[$key]),
        ));
    }

    protected function editableSpecs(): array
    {
        if (!$this->enabled()) {
            // Collapsed accordion: the group is invisible on the wire — even
            // enable_hr itself folds nothing on a fresh draft (folding false
            // would be legal but "hires never mentioned" is the tighter pin,
            // and clearGatedOff() nulls the key when a draft did carry it).
            return [];
        }

        return array_filter(
            $this->specs,
            fn (string $key): bool => !isset(self::REVEAL_GATES[$key]) || $this->revealed(self::REVEAL_GATES[$key]),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** Pressing enable_hr flips the accordion; reveals flip their checkboxes. */
    protected function accept(string $key, Field $field): static
    {
        return match ($key) {
            self::ROW_ENABLE => $this->rebuild([self::ROW_ENABLE => $this->flippedEnable()]),
            self::ROW_SHOW_CHECKPOINT,
            self::ROW_SHOW_SAMPLER,
            self::ROW_SHOW_PROMPTS => $this->rebuild([$key => $this->flippedReveal($key)]),
            default => parent::accept($key, $field),
        };
    }

    protected function extraLabel(string $key): string
    {
        return match ($key) {
            self::ROW_SHOW_CHECKPOINT => Lang::t(GenFormKeys::HIRES_SHOW_CHECKPOINT),
            self::ROW_SHOW_SAMPLER => Lang::t(GenFormKeys::HIRES_SHOW_SAMPLER),
            self::ROW_SHOW_PROMPTS => Lang::t(GenFormKeys::HIRES_SHOW_PROMPTS),
            self::ROW_FINAL_RES => Lang::t(GenFormKeys::HIRES_OUTPUT_SIZE),
            default => throw new \InvalidArgumentException('hires has no row ' . $key),
        };
    }

    protected function display(string $key): string
    {
        return $key === self::ROW_FINAL_RES
            ? $this->finalResolutionCell()
            : parent::display($key);
    }

    private function enabled(): bool
    {
        return (bool) $this->fields[self::ROW_ENABLE]?->value();
    }

    private function effectiveNumber(string $key): ?float
    {
        $value = $this->fields[$key]?->value();

        return is_numeric($value) ? (float) $value : null;
    }

    private function effectiveInt(string $key): ?int
    {
        $value = $this->fields[$key]?->value();

        return is_numeric($value) ? (int) $value : null;
    }

    private function revealed(string $revealKey): bool
    {
        return (bool) $this->fields[$revealKey]?->value();
    }

    /** §2.4 txtimg_hr_finalres: "from {WxH} to {WxH}" over the live draft. */
    private function finalResolutionCell(): string
    {
        $context = $this->context ?? MediaRequest::new();
        $width = $context->width() ?? 512;
        $height = $context->height() ?? 512;
        // The preview mirrors what collect() would fold RIGHT NOW: the
        // section's own widgets answer first, the committed draft only
        // covers the params the Hires tab does not own (width/height).
        [$targetWidth, $targetHeight] = HiresMath::target(
            $width,
            $height,
            $this->effectiveNumber('hr_scale') ?? $context->hrScale(),
            $this->effectiveInt('hr_resize_x') ?? $context->hrResizeX(),
            $this->effectiveInt('hr_resize_y') ?? $context->hrResizeY(),
        );

        return Lang::t(GenFormKeys::HIRES_FINAL_RESOLUTION, [
            'from' => $width . 'x' . $height,
            'to' => $targetWidth . 'x' . $targetHeight,
        ]);
    }

    private function flippedEnable(): Confirm
    {
        return Confirm::new(self::ROW_ENABLE, !$this->enabled())
            ->withTitle(Lang::t(GenFormKeys::SECTION_HIRES));
    }

    private function flippedReveal(string $key): Confirm
    {
        return Confirm::new($key, !$this->revealed($key))
            ->withTitle(Lang::t(match ($key) {
                self::ROW_SHOW_CHECKPOINT => GenFormKeys::HIRES_SHOW_CHECKPOINT,
                self::ROW_SHOW_SAMPLER => GenFormKeys::HIRES_SHOW_SAMPLER,
                default => GenFormKeys::HIRES_SHOW_PROMPTS,
            }));
    }

    private static function revealRow(string $key, string $labelKey): Confirm
    {
        return Confirm::new($key, false)->withTitle(Lang::t($labelKey));
    }

    /**
     * @param array<string, list<string>>|null $runtimeChoices
     */
    private static function seededHiresField(FieldSpec $spec, MediaRequest $draft, ?array $runtimeChoices): Field
    {
        $label = Lang::t($spec->labelKey);
        $value = $spec->valueFrom($draft);

        if ($spec->key === self::ROW_ENABLE) {
            return Confirm::new($spec->key, (bool) $value)->withTitle($label);
        }

        if ($spec->key === 'hr_upscaler') {
            $options = self::feed($runtimeChoices, FieldSpec::CHOICES_UPSCALERS) ?? FieldSpec::latentUpscalers();

            return Select::new($spec->key)
                ->withOptions(...$options)
                ->withTitle($label)
                ->withSelected((string) $value);
        }

        if ($spec->key === 'hr_checkpoint_name') {
            return self::revealSelect($spec, $label, $value, FieldSpec::useSameLabel(GenFormKeys::NOUN_CHECKPOINT), self::feed($runtimeChoices, FieldSpec::CHOICES_MODELS));
        }

        if ($spec->key === 'hr_sampler_name') {
            return self::revealSelect($spec, $label, $value, FieldSpec::useSameLabel(GenFormKeys::NOUN_SAMPLER), self::feed($runtimeChoices, FieldSpec::CHOICES_SAMPLERS) ?? Samplers::names());
        }

        if ($spec->key === 'hr_scheduler') {
            return self::revealSelect($spec, $label, $value, FieldSpec::useSameLabel(GenFormKeys::NOUN_SCHEDULER), self::feed($runtimeChoices, FieldSpec::CHOICES_SCHEDULERS) ?? Schedulers::names());
        }

        $field = $spec->createField($spec->key);

        if ($field instanceof Slider || $field instanceof NumberField) {
            if (!is_int($value) && !is_float($value)) {
                throw new \LogicException('hires row ' . $spec->key . ' default is not numeric');
            }

            return $field->withTitle($label)->withValue($value);
        }

        if ($field instanceof Text) {
            return $field->withTitle($label)->withValue((string) $value);
        }

        throw new \LogicException('hires row ' . $spec->key . ' carries an unbuilt widget');
    }

    /**
     * A §2.1 reveal dropdown: sentinel option zero means "use the first-pass
     * value" and folds to wire null ({@see FieldSpec::applyTo()}); an unset
     * draft lands on the sentinel because Select's default selection is
     * index 0 and a null current value never matches a later option.
     *
     * @param list<string>|null $roster
     */
    private static function revealSelect(FieldSpec $spec, string $label, mixed $value, string $sentinel, ?array $roster): Select
    {
        return Select::new($spec->key)
            ->withOptions($sentinel, ...($roster ?? []))
            ->withTitle($label)
            ->withSelected($value === null ? '' : (string) $value);
    }

    /**
     * @param array<string, list<string>>|null $runtimeChoices
     * @return list<string>|null
     */
    private static function feed(?array $runtimeChoices, string $name): ?array
    {
        $labels = $runtimeChoices[$name] ?? null;

        return $labels === null || $labels === [] ? null : $labels;
    }

    protected function rebuild(array $changes): static
    {
        return new self(
            $this->specs,
            [...$this->fields, ...$changes],
            $this->rowKeys,
            $this->context,
            $this->runtimeChoices,
        );
    }
}

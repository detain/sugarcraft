<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\MediaForm;

use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Tui\MediaForm\Enum\Schedulers;
use SugarCraft\Crush\Tui\MediaForm\Enum\Samplers;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\Field\NumberField;
use SugarCraft\Forms\Field\Slider;
use SugarCraft\Forms\Field\Text;

/**
 * One declarative row of the generate form (crush-media W3.4): key, label
 * (a `tui.genform.*` id), widget kind with its §2-table range/step/default,
 * the DTO bridge (the wire name IS the key), and the A1111 infotext label.
 * ONE registry feeds the form, the request bridge, the infotext table, and
 * (later) presets — a control exists in exactly one row here, so a number
 * cannot drift between surfaces.
 *
 * PROVEN-NEGATIVES RULING (plan §W3.4 step e, mystage §3.1 sweep): TCD,
 * regional seeds, per axis weights, vary region, diffuse noise, and
 * Prompt S R as a standalone script are Forge or SD.Next concepts, not
 * stock A1111 features. They stay OUT of this core field set on purpose;
 * the only sanctioned home for them is user added rows through the
 * `/script-info` scrape or the override settings editor, both landed by
 * W3.8.
 *
 * The coercion law: widgets coerce (Slider/NumberField clamp-and-snap at
 * construction and on every commit), the DTO never clamps — so a value
 * that reached MediaRequest already sits on its widget's lattice. The
 * §2 tables are the edge-case spec; the fields test walks this registry's
 * own rows as its data provider (§10.3).
 *
 * Batch count note (§2.1 "1–(no max in UI)"): the webui ships an unbounded
 * gr.Slider; a bounded Slider here would invent a ceiling, so the row is
 * the int64-safe {@see NumberField} at 1…int64 max — the honest "no UI
 * max" shape. Seed and variation seed cap at PHP_INT_MAX minus one so the
 * lattice walk from the -1 sentinel stays on NumberField's exact integer
 * path (the int64 law); the dropped top value of the range is beyond any
 * seed A1111 itself can roll (§2.2: random seeds come from
 * `randrange(4294967294)`).
 */
final readonly class FieldSpec
{
    public const string WIDGET_SLIDER = 'slider';
    public const string WIDGET_NUMBER = 'number';
    public const string WIDGET_TEXT = 'text';
    public const string WIDGET_MULTI = 'multi';
    public const string WIDGET_SELECT = 'select';
    public const string WIDGET_RADIO = 'radio';
    public const string WIDGET_CONFIRM = 'confirm';
    public const string WIDGET_NOTE = 'note';

    /** Choice-set feed name for option-bearing widgets (sampler/scheduler/upscaler). */
    public const string CHOICES_SAMPLERS = 'samplers';
    public const string CHOICES_SCHEDULERS = 'schedulers';
    public const string CHOICES_UPSCALERS = 'upscalers';
    public const string CHOICES_MODELS = 'models';

    /**
     * @param string $key wire name on MediaRequest / GenerationParams (also the widget key suffix)
     * @param string $labelKey a `tui.genform.*` GenFormKeys constant
     * @param string $widget one of the WIDGET_ constants
     * @param list<string>|null $options fixed option labels (radios); selects use $choices
     * @param string|null $choices feed name from {@see ChoiceFeed} when options are runtime-resolved
     * @param list<string>|null $default list value for multi widgets
     * @param string $section the owning section key
     * @param string $infotextLabel the canonical A1111 infotext spelling for this knob
     */
    private function __construct(
        public string $key,
        public string $labelKey,
        public string $widget,
        public int|float|null $min,
        public int|float|null $max,
        public int|float|null $step,
        public int|float|string|bool|null $default,
        public string $section,
        public string $infotextLabel,
        public ?array $options = null,
        public ?string $choices = null,
        public ?array $defaultList = null,
        public int $rows = 3,
    ) {
    }

    /**
     * Every core txt2img row (§2.1/§2.2) owned by the W3.4 Prompt and Params
     * sections, in display order. Later steps append their rows in-step:
     * W3.5 adds the Extra/Hires/Refiner groups, W3.7 the img2img/inpaint
     * groups, W3.8 the advanced escape hatch.
     *
     * @return list<self>
     */
    public static function coreRows(): array
    {
        return [
            new self(
                key: 'prompt', labelKey: GenFormKeys::ROW_PROMPT, widget: self::WIDGET_TEXT,
                min: null, max: null, step: null, default: '',
                section: 'prompt', infotextLabel: 'Prompt',
            ),
            new self(
                key: 'negative_prompt', labelKey: GenFormKeys::FIELD_NEGATIVE_PROMPT, widget: self::WIDGET_TEXT,
                min: null, max: null, step: null, default: '',
                section: 'prompt', infotextLabel: 'Negative prompt',
            ),
            new self(
                key: 'styles', labelKey: GenFormKeys::FIELD_STYLES, widget: self::WIDGET_MULTI,
                min: null, max: null, step: null, default: null,
                section: 'prompt', infotextLabel: 'Style',
                options: ['Default'], defaultList: ['Default'],
            ),
            new self(
                key: 'width', labelKey: GenFormKeys::FIELD_WIDTH, widget: self::WIDGET_SLIDER,
                min: 64, max: 2048, step: 8, default: 512,
                section: 'params', infotextLabel: 'Size',
            ),
            new self(
                key: 'height', labelKey: GenFormKeys::FIELD_HEIGHT, widget: self::WIDGET_SLIDER,
                min: 64, max: 2048, step: 8, default: 512,
                section: 'params', infotextLabel: 'Size',
            ),
            new self(
                key: 'n_iter', labelKey: GenFormKeys::FIELD_BATCH_COUNT, widget: self::WIDGET_NUMBER,
                min: 1, max: PHP_INT_MAX, step: 1, default: 1,
                section: 'params', infotextLabel: 'Batch count',
            ),
            new self(
                key: 'batch_size', labelKey: GenFormKeys::FIELD_BATCH_SIZE, widget: self::WIDGET_SLIDER,
                min: 1, max: 8, step: 1, default: 1,
                section: 'params', infotextLabel: 'Batch size',
            ),
            new self(
                key: 'cfg_scale', labelKey: GenFormKeys::ROW_CFG_SCALE, widget: self::WIDGET_SLIDER,
                min: 1.0, max: 30.0, step: 0.5, default: 7.0,
                section: 'params', infotextLabel: 'CFG scale',
            ),
            new self(
                key: 'steps', labelKey: GenFormKeys::FIELD_SAMPLING_STEPS, widget: self::WIDGET_SLIDER,
                min: 1, max: 150, step: 1, default: 20,
                section: 'params', infotextLabel: 'Sampling steps',
            ),
            new self(
                key: 'sampler_name', labelKey: GenFormKeys::FIELD_SAMPLING_METHOD, widget: self::WIDGET_SELECT,
                min: null, max: null, step: null, default: Samplers::defaultName(),
                section: 'params', infotextLabel: 'Sampler',
                choices: self::CHOICES_SAMPLERS,
            ),
            new self(
                key: 'scheduler', labelKey: GenFormKeys::FIELD_SAMPLING_SCHEDULE, widget: self::WIDGET_SELECT,
                min: null, max: null, step: null, default: Schedulers::defaultName(),
                section: 'params', infotextLabel: 'Schedule type',
                choices: self::CHOICES_SCHEDULERS,
            ),
            new self(
                key: 'seed', labelKey: GenFormKeys::ROW_SEED, widget: self::WIDGET_NUMBER,
                min: -1, max: PHP_INT_MAX - 1, step: 1, default: -1,
                section: 'params', infotextLabel: 'Seed',
            ),
        ];
    }

    /**
     * Build the sugar-forms widget this row describes, already coerced onto
     * its lattice with the row's default. `$choices` overrides the feed for
     * option-bearing widgets (a runtime `/samplers` set); a select with no
     * option source fails fast — a silent empty dropdown would hide a wiring
     * bug until a user finds it.
     */
    public function createField(string $key, ?array $choices = null): Field
    {
        return match ($this->widget) {
            self::WIDGET_TEXT => Text::new($key)
                ->withHeight($this->rows),
            self::WIDGET_SLIDER => Slider::new($key, $this->min, $this->max, $this->step, $this->default),
            self::WIDGET_NUMBER => NumberField::new($key, $this->min, $this->max, $this->step, $this->default),
            self::WIDGET_CONFIRM => \SugarCraft\Forms\Field\Confirm::new($key, (bool) $this->default),
            self::WIDGET_MULTI => \SugarCraft\Forms\Field\MultiSelect::new($key)
                ->withOptions(...($this->options ?? [])),
            self::WIDGET_RADIO => \SugarCraft\Forms\Field\RadioPills::new($key, ...$this->optionLabels()),
            self::WIDGET_SELECT => \SugarCraft\Forms\Field\Select::new($key)
                ->withOptions(...$this->optionLabels($choices)),
            default => throw new \InvalidArgumentException('no widget factory for ' . $this->widget),
        };
    }

    /**
     * A copy with display-override props folded in (crush-media W3.9, the
     * ui-config analog): `minimum`/`maximum`/`step` move the widget range
     * and `value` re-seeds the row's default — the pre-fill a fresh form
     * opens on. Typing and widget-sense belong to {@see DisplayOverrides},
     * the parse boundary; this door trusts what it was handed.
     *
     * @param array<string, int|float|string|bool> $props keyed by the DisplayOverrides PROP_ constants
     */
    public function withDisplayProps(array $props): self
    {
        return new self(
            key: $this->key,
            labelKey: $this->labelKey,
            widget: $this->widget,
            min: \array_key_exists(DisplayOverrides::PROP_MINIMUM, $props) ? $props[DisplayOverrides::PROP_MINIMUM] : $this->min,
            max: \array_key_exists(DisplayOverrides::PROP_MAXIMUM, $props) ? $props[DisplayOverrides::PROP_MAXIMUM] : $this->max,
            step: \array_key_exists(DisplayOverrides::PROP_STEP, $props) ? $props[DisplayOverrides::PROP_STEP] : $this->step,
            default: \array_key_exists(DisplayOverrides::PROP_VALUE, $props) ? $props[DisplayOverrides::PROP_VALUE] : $this->default,
            section: $this->section,
            infotextLabel: $this->infotextLabel,
            options: $this->options,
            choices: $this->choices,
            defaultList: $this->defaultList,
            rows: $this->rows,
        );
    }

    /**
     * The option labels for this row: the runtime feed wins, the row's
     * fixed options are next, the registry default is last.
     *
     * @param list<string>|null $choices
     * @return list<string>
     */
    public function optionLabels(?array $choices = null): array
    {
        if ($choices !== null && $choices !== []) {
            return $choices;
        }

        return $this->options ?? match ($this->choices) {
            self::CHOICES_SAMPLERS => Samplers::names(),
            self::CHOICES_SCHEDULERS => Schedulers::names(),
            // The six latent modes are the complete offline truth (§2.8);
            // a live /upscalers set arrives through the runtime choice feed.
            self::CHOICES_UPSCALERS => self::latentUpscalers(),
            // Checkpoint names are unknowable without a client — the honest
            // static shape is the neutral entry the sections prepend to.
            self::CHOICES_MODELS => [self::noneLabel()],
            default => throw new \InvalidArgumentException('select row ' . $this->key . ' has no option source'),
        };
    }

    /**
     * §2.8's six latent upscale modes, verbatim. Like sampler names these are
     * wire identifiers displayed as-is (data, not UI copy); the server's own
     * /upscalers names join them through {@see ChoiceFeed} when a client is
     * configured.
     *
     * @return list<string>
     */
    public static function latentUpscalers(): array
    {
        return [
            'Latent',
            'Latent (antialiased)',
            'Latent (bicubic)',
            'Latent (bicubic antialiased)',
            'Latent (nearest)',
            'Latent (nearest-exact)',
        ];
    }

    /** The dropdown's neutral "off" entry (refiner checkpoint, W3.5). */
    public static function noneLabel(): string
    {
        return Lang::t(GenFormKeys::OPTION_NONE);
    }

    /** The hires dropdowns' "Use same …" sentinel label for one noun row. */
    public static function useSameLabel(string $nounKey): string
    {
        return Lang::t(GenFormKeys::HIRES_USE_SAME, ['field' => Lang::t($nounKey)]);
    }

    /**
     * The W3.5 disclosure groups (§2.1/§2.3): the Extra variation-seed rows,
     * the whole Hires. fix accordion, and the Refiner pair. Every range,
     * step and default below is the §2-table number verbatim.
     *
     * CONTEXT-DEPENDENT DEFAULT (§2.2 non-conflict note): `denoising_strength`
     * is ONE wire field whose UI default depends on the owning context —
     * 0.7 here (hires), 0.75 in the W3.7 img2img group. While W3.7's group
     * ships gated (zero collects) the hires row is its single owner; when
     * the gate lifts, tab order decides the fold (documented in HiresSection).
     *
     * @return list<self>
     */
    public static function disclosureRows(): array
    {
        return [
            new self(
                key: 'subseed', labelKey: GenFormKeys::FIELD_SUBSEED, widget: self::WIDGET_NUMBER,
                min: -1, max: PHP_INT_MAX - 1, step: 1, default: -1,
                section: 'extra', infotextLabel: 'Variation seed',
            ),
            new self(
                key: 'subseed_strength', labelKey: GenFormKeys::FIELD_SUBSEED_STRENGTH, widget: self::WIDGET_SLIDER,
                min: 0.0, max: 1.0, step: 0.01, default: 0.0,
                section: 'extra', infotextLabel: 'Variation strength',
            ),
            new self(
                key: 'seed_resize_from_w', labelKey: GenFormKeys::FIELD_SEED_RESIZE_FROM_W, widget: self::WIDGET_SLIDER,
                min: 0, max: 2048, step: 8, default: 0,
                section: 'extra', infotextLabel: 'Seed resize from',
            ),
            new self(
                key: 'seed_resize_from_h', labelKey: GenFormKeys::FIELD_SEED_RESIZE_FROM_H, widget: self::WIDGET_SLIDER,
                min: 0, max: 2048, step: 8, default: 0,
                section: 'extra', infotextLabel: 'Seed resize from',
            ),
            new self(
                key: 'enable_hr', labelKey: GenFormKeys::SECTION_HIRES, widget: self::WIDGET_CONFIRM,
                min: null, max: null, step: null, default: false,
                section: 'hires', infotextLabel: 'Hires fix',
            ),
            new self(
                key: 'hr_upscaler', labelKey: GenFormKeys::HIRES_UPSCALER, widget: self::WIDGET_SELECT,
                min: null, max: null, step: null, default: 'Latent',
                section: 'hires', infotextLabel: 'Hires upscaler',
                choices: self::CHOICES_UPSCALERS,
            ),
            new self(
                key: 'hr_second_pass_steps', labelKey: GenFormKeys::HIRES_STEPS, widget: self::WIDGET_SLIDER,
                min: 0, max: 150, step: 1, default: 0,
                section: 'hires', infotextLabel: 'Hires steps',
            ),
            new self(
                key: 'denoising_strength', labelKey: GenFormKeys::FIELD_DENOISING_STRENGTH, widget: self::WIDGET_SLIDER,
                min: 0.0, max: 1.0, step: 0.01, default: 0.7,
                section: 'hires', infotextLabel: 'Denoising strength',
            ),
            new self(
                key: 'hr_scale', labelKey: GenFormKeys::HIRES_UPSCALE_BY, widget: self::WIDGET_SLIDER,
                min: 1.0, max: 4.0, step: 0.05, default: 2.0,
                section: 'hires', infotextLabel: 'Hires upscale',
            ),
            new self(
                key: 'hr_resize_x', labelKey: GenFormKeys::HIRES_RESIZE_WIDTH, widget: self::WIDGET_SLIDER,
                min: 0, max: 2048, step: 8, default: 0,
                section: 'hires', infotextLabel: 'Hires resize-X',
            ),
            new self(
                key: 'hr_resize_y', labelKey: GenFormKeys::HIRES_RESIZE_HEIGHT, widget: self::WIDGET_SLIDER,
                min: 0, max: 2048, step: 8, default: 0,
                section: 'hires', infotextLabel: 'Hires resize-Y',
            ),
            new self(
                key: 'hr_checkpoint_name', labelKey: GenFormKeys::HIRES_CHECKPOINT, widget: self::WIDGET_SELECT,
                min: null, max: null, step: null, default: null,
                section: 'hires', infotextLabel: 'Hires checkpoint',
                choices: self::CHOICES_MODELS,
            ),
            new self(
                key: 'hr_sampler_name', labelKey: GenFormKeys::HIRES_SAMPLING_METHOD, widget: self::WIDGET_SELECT,
                min: null, max: null, step: null, default: null,
                section: 'hires', infotextLabel: 'Hires sampler',
                choices: self::CHOICES_SAMPLERS,
            ),
            new self(
                key: 'hr_scheduler', labelKey: GenFormKeys::HIRES_SAMPLING_SCHEDULE, widget: self::WIDGET_SELECT,
                min: null, max: null, step: null, default: null,
                section: 'hires', infotextLabel: 'Hires scheduler',
                choices: self::CHOICES_SCHEDULERS,
            ),
            new self(
                key: 'hr_prompt', labelKey: GenFormKeys::HIRES_PROMPT, widget: self::WIDGET_TEXT,
                min: null, max: null, step: null, default: '',
                section: 'hires', infotextLabel: 'Hires prompt',
            ),
            new self(
                key: 'hr_negative_prompt', labelKey: GenFormKeys::HIRES_NEGATIVE_PROMPT, widget: self::WIDGET_TEXT,
                min: null, max: null, step: null, default: '',
                section: 'hires', infotextLabel: 'Hires negative prompt',
            ),
            new self(
                key: 'refiner_checkpoint', labelKey: GenFormKeys::REFINER_CHECKPOINT, widget: self::WIDGET_SELECT,
                min: null, max: null, step: null, default: null,
                section: 'refiner', infotextLabel: 'Refiner',
                choices: self::CHOICES_MODELS,
            ),
            new self(
                key: 'refiner_switch_at', labelKey: GenFormKeys::REFINER_SWITCH_AT, widget: self::WIDGET_SLIDER,
                min: 0.01, max: 1.0, step: 0.01, default: 0.8,
                section: 'refiner', infotextLabel: 'Refiner switch at',
            ),
        ];
    }

    /**
     * The W3.7 img2img/inpaint registry (§2.6/§2.7). Every range, step and
     * default below is the §2-table number verbatim; the radio rows carry
     * their option labels (§2.7 spellings) and bridge label↔index at the
     * wire. Present-but-gated: while {@see Wave4Gate} is closed no section
     * collects these rows, so the registry exists to pin the vocabulary and
     * coercion in tests long before the editor ships.
     *
     * RULINGS. No mode-selector row is invented: A1111's `mode_img2img`
     * sub-tab index (Sketch/Inpaint tabs) IS the crush tab strip — Image and
     * Inpaint are their own sections here, mirroring the webui's split of
     * img2img vs inpaint knobs. The §2.6 source rows (upload, batch dir,
     * detect size, scale) have no DTO home until W4 image ingress lands and
     * deliberately wait there. `denoising_strength` is the second per-section
     * view of ONE wire key (0.7 hires-context in {@see disclosureRows()},
     * 0.75 img2img-context here, §2.2) — while the gate holds the hires row
     * is the sole collector; when it lifts, tab order decides the fold.
     *
     * @return list<self>
     */
    public static function wave4Rows(): array
    {
        return [
            new self(
                key: 'resize_mode', labelKey: GenFormKeys::FIELD_RESIZE_MODE, widget: self::WIDGET_RADIO,
                min: null, max: null, step: null, default: self::resizeModeOptions()[0],
                section: 'image', infotextLabel: 'Resize mode',
                options: self::resizeModeOptions(),
            ),
            new self(
                key: 'denoising_strength', labelKey: GenFormKeys::FIELD_DENOISING_STRENGTH, widget: self::WIDGET_SLIDER,
                min: 0.0, max: 1.0, step: 0.01, default: 0.75,
                section: 'image', infotextLabel: 'Denoising strength',
            ),
            new self(
                key: 'image_cfg_scale', labelKey: GenFormKeys::FIELD_IMAGE_CFG_SCALE, widget: self::WIDGET_SLIDER,
                min: 0.0, max: 3.0, step: 0.05, default: 1.5,
                section: 'image', infotextLabel: 'Image CFG scale',
            ),
            new self(
                key: 'mask_blur', labelKey: GenFormKeys::MASK_BLUR, widget: self::WIDGET_SLIDER,
                min: 0, max: 64, step: 1, default: 4,
                section: 'inpaint', infotextLabel: 'Mask blur',
            ),
            new self(
                key: 'inpainting_mask_invert', labelKey: GenFormKeys::FIELD_MASK_MODE, widget: self::WIDGET_RADIO,
                min: null, max: null, step: null, default: self::maskInvertOptions()[0],
                section: 'inpaint', infotextLabel: 'Mask mode',
                options: self::maskInvertOptions(),
            ),
            new self(
                key: 'inpainting_fill', labelKey: GenFormKeys::FIELD_MASKED_CONTENT, widget: self::WIDGET_RADIO,
                min: null, max: null, step: null, default: self::fillOptions()[1],
                section: 'inpaint', infotextLabel: 'Masked content',
                options: self::fillOptions(),
            ),
            new self(
                key: 'inpaint_full_res', labelKey: GenFormKeys::FIELD_INPAINT_AREA, widget: self::WIDGET_RADIO,
                min: null, max: null, step: null, default: self::areaOptions()[0],
                section: 'inpaint', infotextLabel: 'Inpaint area',
                options: self::areaOptions(),
            ),
            new self(
                key: 'inpaint_full_res_padding', labelKey: GenFormKeys::AREA_PADDING, widget: self::WIDGET_SLIDER,
                min: 0, max: 256, step: 4, default: 32,
                section: 'inpaint', infotextLabel: 'Only masked padding, pixels',
            ),
        ];
    }

    /**
     * §2.7's four resize modes, verbatim A1111 spellings — wire index = position.
     *
     * @return list<string>
     */
    public static function resizeModeOptions(): array
    {
        return [
            Lang::t(GenFormKeys::RESIZE_JUST),
            Lang::t(GenFormKeys::RESIZE_CROP),
            Lang::t(GenFormKeys::RESIZE_FILL),
            Lang::t(GenFormKeys::RESIZE_LATENT),
        ];
    }

    /**
     * §2.7's masked-content options. EXACTLY FOUR (C-1): fill, original,
     * latent noise, latent nothing — a fifth entry or a 5-way split would
     * be a Forge/SD.Next concept, not stock A1111, and is a CRITICAL defect.
     * UI default is "original" (index 1); wire index = position.
     *
     * @return list<string>
     */
    public static function fillOptions(): array
    {
        return [
            Lang::t(GenFormKeys::FILL_FILL),
            Lang::t(GenFormKeys::FILL_ORIGINAL),
            Lang::t(GenFormKeys::FILL_LATENT_NOISE),
            Lang::t(GenFormKeys::FILL_LATENT_NOTHING),
        ];
    }

    /** §2.7 mask mode: inpaint the masked region or its complement.
     *
     * @return list<string>
     */
    public static function maskInvertOptions(): array
    {
        return [
            Lang::t(GenFormKeys::MASK_MODE_MASKED),
            Lang::t(GenFormKeys::MASK_MODE_NOT_MASKED),
        ];
    }

    /** §2.7 inpaint area: whole picture (false) or only masked (true).
     *
     * @return list<string>
     */
    public static function areaOptions(): array
    {
        return [
            Lang::t(GenFormKeys::AREA_WHOLE),
            Lang::t(GenFormKeys::AREA_ONLY_MASKED),
        ];
    }

    /** Does this row's section still want it visible given the draft state? (W3.5 owns conditionals.) */
    public function belongsTo(string $sectionKey): bool
    {
        return $this->section === $sectionKey;
    }

    /** The draft's current value for this row, or its default when unset. */
    public function valueFrom(MediaRequest $draft): mixed
    {
        $read = match ($this->key) {
            'prompt' => $draft->prompt(),
            'negative_prompt' => $draft->negativePrompt(),
            'styles' => $draft->styles(),
            'width' => $draft->width(),
            'height' => $draft->height(),
            'n_iter' => $draft->nIter(),
            'batch_size' => $draft->batchSize(),
            'cfg_scale' => $draft->cfgScale(),
            'steps' => $draft->steps(),
            'sampler_name' => $draft->samplerName(),
            'scheduler' => $draft->scheduler(),
            'seed' => $draft->seed(),
            'subseed' => $draft->subseed(),
            'subseed_strength' => $draft->subseedStrength(),
            'seed_resize_from_w' => $draft->seedResizeFromW(),
            'seed_resize_from_h' => $draft->seedResizeFromH(),
            'enable_hr' => $draft->enableHr(),
            'hr_upscaler' => $draft->hrUpscaler(),
            'hr_second_pass_steps' => $draft->hrSecondPassSteps(),
            'denoising_strength' => $draft->denoisingStrength(),
            'hr_scale' => $draft->hrScale(),
            'hr_resize_x' => $draft->hrResizeX(),
            'hr_resize_y' => $draft->hrResizeY(),
            'hr_checkpoint_name' => $draft->hrCheckpointName(),
            'hr_sampler_name' => $draft->hrSamplerName(),
            'hr_scheduler' => $draft->hrScheduler(),
            'hr_prompt' => $draft->hrPrompt(),
            'hr_negative_prompt' => $draft->hrNegativePrompt(),
            'refiner_checkpoint' => $draft->refinerCheckpoint(),
            'refiner_switch_at' => $draft->refinerSwitchAt(),
            // W3.7 radios store their wire shape as ints/bools; the row's
            // readable value is the option LABEL at that index.
            'resize_mode' => self::labelAtIndex($draft->resizeMode(), self::resizeModeOptions()),
            'inpainting_mask_invert' => self::labelAtIndex($draft->inpaintingMaskInvert(), self::maskInvertOptions()),
            'inpainting_fill' => self::labelAtIndex($draft->inpaintingFill(), self::fillOptions()),
            'inpaint_full_res' => self::labelAtIndex(
                $draft->inpaintFullRes() === null ? null : (int) $draft->inpaintFullRes(),
                self::areaOptions(),
            ),
            'image_cfg_scale' => $draft->imageCfgScale(),
            'mask_blur' => $draft->maskBlur(),
            'inpaint_full_res_padding' => $draft->inpaintFullResPadding(),
            default => null,
        };

        return $read ?? ($this->key === 'styles' ? $this->defaultList : $this->default);
    }

    /** Fold a widget value for this row into the draft (the wire bridge). */
    public function applyTo(MediaRequest $draft, mixed $value): MediaRequest
    {
        return match ($this->key) {
            'prompt' => $draft->withPrompt(trim((string) $value) === '' ? null : (string) $value),
            'negative_prompt' => $draft->withNegativePrompt(trim((string) $value) === '' ? null : (string) $value),
            'styles' => $draft->withStyles(self::isDefaultStyles($value) ? null : array_values($value)),
            'width' => $draft->withWidth((int) $value),
            'height' => $draft->withHeight((int) $value),
            'n_iter' => $draft->withNIter((int) $value),
            'batch_size' => $draft->withBatchSize((int) $value),
            'cfg_scale' => $draft->withCfgScale((float) $value),
            'steps' => $draft->withSteps((int) $value),
            'sampler_name' => $draft->withSamplerName((string) $value),
            'scheduler' => $draft->withScheduler((string) $value),
            'seed' => $draft->withSeed((int) $value),
            // Every W3.5 arm is null-tolerant: a closed disclosure folds its
            // row to null (the DTO's set-to-default, guarded server-side per
            // field), never a stale user value — see the disclosure law in
            // Sections/SeedSection.php.
            'subseed' => $draft->withSubseed(self::intOrNull($value)),
            'subseed_strength' => $draft->withSubseedStrength(self::floatOrNull($value)),
            'seed_resize_from_w' => $draft->withSeedResizeFromW(self::intOrNull($value)),
            'seed_resize_from_h' => $draft->withSeedResizeFromH(self::intOrNull($value)),
            'enable_hr' => $draft->withEnableHr($value === null ? null : (bool) $value),
            'hr_upscaler' => $draft->withHrUpscaler(self::stringOrNull($value)),
            'hr_second_pass_steps' => $draft->withHrSecondPassSteps(self::intOrNull($value)),
            'denoising_strength' => $draft->withDenoisingStrength(self::floatOrNull($value)),
            'hr_scale' => $draft->withHrScale(self::floatOrNull($value)),
            'hr_resize_x' => $draft->withHrResizeX(self::intOrNull($value)),
            'hr_resize_y' => $draft->withHrResizeY(self::intOrNull($value)),
            'hr_checkpoint_name' => $draft->withHrCheckpointName(self::sentinelWire($value, self::useSameLabel(GenFormKeys::NOUN_CHECKPOINT))),
            'hr_sampler_name' => $draft->withHrSamplerName(self::sentinelWire($value, self::useSameLabel(GenFormKeys::NOUN_SAMPLER))),
            'hr_scheduler' => $draft->withHrScheduler(self::sentinelWire($value, self::useSameLabel(GenFormKeys::NOUN_SCHEDULER))),
            'hr_prompt' => $draft->withHrPrompt(trim((string) $value) === '' ? null : (string) $value),
            'hr_negative_prompt' => $draft->withHrNegativePrompt(trim((string) $value) === '' ? null : (string) $value),
            'refiner_checkpoint' => $draft->withRefinerCheckpoint(self::sentinelWire($value, self::noneLabel())),
            'refiner_switch_at' => $draft->withRefinerSwitchAt(self::floatOrNull($value)),
            // W3.7 radio arms: the label is what the widget holds, the index
            // is what the wire carries (§2.7); inpaint_full_res is the one
            // BOOL field of the set ("Only masked" = index 1 = true).
            'resize_mode' => $draft->withResizeMode(self::indexAtLabel($value, self::resizeModeOptions())),
            'inpainting_mask_invert' => $draft->withInpaintingMaskInvert(self::indexAtLabel($value, self::maskInvertOptions())),
            'inpainting_fill' => $draft->withInpaintingFill(self::indexAtLabel($value, self::fillOptions())),
            'inpaint_full_res' => $draft->withInpaintFullRes(
                $value === null ? null : self::indexAtLabel($value, self::areaOptions()) === 1,
            ),
            'mask_blur' => $draft->withMaskBlur(self::intOrNull($value)),
            'inpaint_full_res_padding' => $draft->withInpaintFullResPadding(self::intOrNull($value)),
            'image_cfg_scale' => $draft->withImageCfgScale(self::floatOrNull($value)),
            default => $draft->withUnknown($this->key, $value),
        };
    }

    /**
     * A1111 posts `["Default"]` for "no style"; the wire stays sparse and
     * the DTO law is server-decides, so Default collapses to unset.
     */
    public static function isDefaultStyles(mixed $styles): bool
    {
        return !is_array($styles) || $styles === [] || $styles === ['Default'];
    }

    /** Null stays null (the DTO's set-to-default); anything else snaps to int. */
    private static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /** Null stays null; anything else snaps to float. */
    private static function floatOrNull(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /** Null and the empty string both fold to null (absent == default). */
    private static function stringOrNull(mixed $value): ?string
    {
        return $value === null || (string) $value === '' ? null : (string) $value;
    }

    /**
     * A dropdown's neutral sentinel ("Use same checkpoint", "None") selects
     * the server default, so it rides the wire as null — never the label.
     */
    private static function sentinelWire(mixed $value, string $sentinel): ?string
    {
        return $value === null || (string) $value === '' || (string) $value === $sentinel
            ? null
            : (string) $value;
    }

    /**
     * The option label at a wire index, or null when the draft never set
     * one (the row default takes over via valueFrom's fallback). Out-of-range
     * indices are treated as unset — the registry is the only writer, so a
     * stray index means data from outside this form.
     *
     * @param list<string> $options
     */
    private static function labelAtIndex(mixed $index, array $options): ?string
    {
        return is_int($index) && isset($options[$index]) ? $options[$index] : null;
    }

    /**
     * The wire index of an option label; an unknown label (or null from a
     * closed group) rides the DTO as null — set-to-default, never a guess.
     *
     * @param list<string> $options
     */
    private static function indexAtLabel(mixed $label, array $options): ?int
    {
        if ($label === null) {
            return null;
        }

        $index = array_search((string) $label, $options, true);

        return $index === false ? null : $index;
    }
}

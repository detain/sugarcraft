<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media;

use SugarCraft\Core\Concerns\Mutable;

/**
 *  * Form-shaped generation knobs — the caller-side parameter tree, plan W1.1.
 *
 * The wire surface lives on MediaRequest; this object is what a Wave-3 form
 * (G-1..G-5 tabs) and the config presets bind against: same guarded fields as
 * the request minus prompt text (separate composer argument) minus plumbing
 * that is never a user knob (send_images/save_images are client decisions,
 * force_task_id/infotext are task-management, scripts/override_settings are
 * advanced pass-throughs, init_images/mask are payload bytes). MediaRequest's
 * mediaRequestFrom()/toParamsProjection() bridge the two shapes.
 *
 * Same sentinel + no-coercion laws as MediaRequest (see its docblock); the
 * three domain refusals (subseed_strength 0..1, batch_size >= 1, n_iter >= 1)
 * are mirrored here so a bad value is rejected at whichever door it arrives.
 */
final readonly class GenerationParams
{
    use Mutable;

    /**
     * [key, prop, kind, guard] — canonical order IS the wire emit order.
     */
    private const FIELD_SPEC = [
        ['negativePrompt', 'negativePrompt', 's', null],
        ['styles', 'styles', 'a', null],
        ['seed', 'seed', 'i', null],
        ['subseed', 'subseed', 'i', null],
        ['subseedStrength', 'subseedStrength', 'f', 'fr'],
        ['seedResizeFromH', 'seedResizeFromH', 'i', null],
        ['seedResizeFromW', 'seedResizeFromW', 'i', null],
        ['samplerName', 'samplerName', 's', null],
        ['scheduler', 'scheduler', 's', null],
        ['batchSize', 'batchSize', 'i', 'g1'],
        ['nIter', 'nIter', 'i', 'g1'],
        ['steps', 'steps', 'i', null],
        ['cfgScale', 'cfgScale', 'f', null],
        ['width', 'width', 'i', null],
        ['height', 'height', 'i', null],
        ['enableHr', 'enableHr', 'b', null],
        ['denoisingStrength', 'denoisingStrength', 'f', null],
        ['firstphaseWidth', 'firstphaseWidth', 'i', null],
        ['firstphaseHeight', 'firstphaseHeight', 'i', null],
        ['hrScale', 'hrScale', 'f', null],
        ['hrUpscaler', 'hrUpscaler', 's', null],
        ['hrSecondPassSteps', 'hrSecondPassSteps', 'i', null],
        ['hrResizeX', 'hrResizeX', 'i', null],
        ['hrResizeY', 'hrResizeY', 'i', null],
        ['hrCheckpointName', 'hrCheckpointName', 's', null],
        ['hrSamplerName', 'hrSamplerName', 's', null],
        ['hrScheduler', 'hrScheduler', 's', null],
        ['hrPrompt', 'hrPrompt', 's', null],
        ['hrNegativePrompt', 'hrNegativePrompt', 's', null],
        ['restoreFaces', 'restoreFaces', 'b', null],
        ['tiling', 'tiling', 'b', null],
        ['eta', 'eta', 'f', null],
        ['sChurn', 'sChurn', 'f', null],
        ['sTmax', 'sTmax', 'f', null],
        ['sTmin', 'sTmin', 'f', null],
        ['sNoise', 'sNoise', 'f', null],
        ['sMinUncond', 'sMinUncond', 'f', null],
        ['tokenMergingRatio', 'tokenMergingRatio', 'f', null],
        ['tokenMergingRatioHr', 'tokenMergingRatioHr', 'f', null],
        ['refinerCheckpoint', 'refinerCheckpoint', 's', null],
        ['refinerSwitchAt', 'refinerSwitchAt', 'f', null],
        ['resizeMode', 'resizeMode', 'i', null],
        ['maskBlur', 'maskBlur', 'i', null],
        ['maskBlurX', 'maskBlurX', 'i', null],
        ['maskBlurY', 'maskBlurY', 'i', null],
        ['inpaintingFill', 'inpaintingFill', 'i', null],
        ['inpaintFullRes', 'inpaintFullRes', 'b', null],
        ['inpaintFullResPadding', 'inpaintFullResPadding', 'i', null],
        ['inpaintingMaskInvert', 'inpaintingMaskInvert', 'i', null],
        ['initialNoiseMultiplier', 'initialNoiseMultiplier', 'f', null],
        ['imageCfgScale', 'imageCfgScale', 'f', null],
        ['maskRound', 'maskRound', 'b', null],
    ];
    /**
     * The complete roster of modelled form knobs, in canonical wire-relative
     * order. This is the single source of truth the MediaRequest composer
     * iterates — adding a field to this class without adding it here is caught
     * by GenerationParamsTest's roster pin.
     */
    public const PROPS = [
        'negativePrompt',
        'styles',
        'seed',
        'subseed',
        'subseedStrength',
        'seedResizeFromH',
        'seedResizeFromW',
        'samplerName',
        'scheduler',
        'batchSize',
        'nIter',
        'steps',
        'cfgScale',
        'width',
        'height',
        'enableHr',
        'denoisingStrength',
        'firstphaseWidth',
        'firstphaseHeight',
        'hrScale',
        'hrUpscaler',
        'hrSecondPassSteps',
        'hrResizeX',
        'hrResizeY',
        'hrCheckpointName',
        'hrSamplerName',
        'hrScheduler',
        'hrPrompt',
        'hrNegativePrompt',
        'restoreFaces',
        'tiling',
        'eta',
        'sChurn',
        'sTmax',
        'sTmin',
        'sNoise',
        'sMinUncond',
        'tokenMergingRatio',
        'tokenMergingRatioHr',
        'refinerCheckpoint',
        'refinerSwitchAt',
        'resizeMode',
        'maskBlur',
        'maskBlurX',
        'maskBlurY',
        'inpaintingFill',
        'inpaintFullRes',
        'inpaintFullResPadding',
        'inpaintingMaskInvert',
        'initialNoiseMultiplier',
        'imageCfgScale',
        'maskRound',
    ];

    private function __construct(
        private readonly ?string $negativePrompt = null,
        private readonly bool $negativePromptSet = false,
        private readonly ?array $styles = null,
        private readonly bool $stylesSet = false,
        private readonly ?int $seed = null,
        private readonly bool $seedSet = false,
        private readonly ?int $subseed = null,
        private readonly bool $subseedSet = false,
        private readonly ?float $subseedStrength = null,
        private readonly bool $subseedStrengthSet = false,
        private readonly ?int $seedResizeFromH = null,
        private readonly bool $seedResizeFromHSet = false,
        private readonly ?int $seedResizeFromW = null,
        private readonly bool $seedResizeFromWSet = false,
        private readonly ?string $samplerName = null,
        private readonly bool $samplerNameSet = false,
        private readonly ?string $scheduler = null,
        private readonly bool $schedulerSet = false,
        private readonly ?int $batchSize = null,
        private readonly bool $batchSizeSet = false,
        private readonly ?int $nIter = null,
        private readonly bool $nIterSet = false,
        private readonly ?int $steps = null,
        private readonly bool $stepsSet = false,
        private readonly ?float $cfgScale = null,
        private readonly bool $cfgScaleSet = false,
        private readonly ?int $width = null,
        private readonly bool $widthSet = false,
        private readonly ?int $height = null,
        private readonly bool $heightSet = false,
        private readonly ?bool $enableHr = null,
        private readonly bool $enableHrSet = false,
        private readonly ?float $denoisingStrength = null,
        private readonly bool $denoisingStrengthSet = false,
        private readonly ?int $firstphaseWidth = null,
        private readonly bool $firstphaseWidthSet = false,
        private readonly ?int $firstphaseHeight = null,
        private readonly bool $firstphaseHeightSet = false,
        private readonly ?float $hrScale = null,
        private readonly bool $hrScaleSet = false,
        private readonly ?string $hrUpscaler = null,
        private readonly bool $hrUpscalerSet = false,
        private readonly ?int $hrSecondPassSteps = null,
        private readonly bool $hrSecondPassStepsSet = false,
        private readonly ?int $hrResizeX = null,
        private readonly bool $hrResizeXSet = false,
        private readonly ?int $hrResizeY = null,
        private readonly bool $hrResizeYSet = false,
        private readonly ?string $hrCheckpointName = null,
        private readonly bool $hrCheckpointNameSet = false,
        private readonly ?string $hrSamplerName = null,
        private readonly bool $hrSamplerNameSet = false,
        private readonly ?string $hrScheduler = null,
        private readonly bool $hrSchedulerSet = false,
        private readonly ?string $hrPrompt = null,
        private readonly bool $hrPromptSet = false,
        private readonly ?string $hrNegativePrompt = null,
        private readonly bool $hrNegativePromptSet = false,
        private readonly ?bool $restoreFaces = null,
        private readonly bool $restoreFacesSet = false,
        private readonly ?bool $tiling = null,
        private readonly bool $tilingSet = false,
        private readonly ?float $eta = null,
        private readonly bool $etaSet = false,
        private readonly ?float $sChurn = null,
        private readonly bool $sChurnSet = false,
        private readonly ?float $sTmax = null,
        private readonly bool $sTmaxSet = false,
        private readonly ?float $sTmin = null,
        private readonly bool $sTminSet = false,
        private readonly ?float $sNoise = null,
        private readonly bool $sNoiseSet = false,
        private readonly ?float $sMinUncond = null,
        private readonly bool $sMinUncondSet = false,
        private readonly ?float $tokenMergingRatio = null,
        private readonly bool $tokenMergingRatioSet = false,
        private readonly ?float $tokenMergingRatioHr = null,
        private readonly bool $tokenMergingRatioHrSet = false,
        private readonly ?string $refinerCheckpoint = null,
        private readonly bool $refinerCheckpointSet = false,
        private readonly ?float $refinerSwitchAt = null,
        private readonly bool $refinerSwitchAtSet = false,
        private readonly ?int $resizeMode = null,
        private readonly bool $resizeModeSet = false,
        private readonly ?int $maskBlur = null,
        private readonly bool $maskBlurSet = false,
        private readonly ?int $maskBlurX = null,
        private readonly bool $maskBlurXSet = false,
        private readonly ?int $maskBlurY = null,
        private readonly bool $maskBlurYSet = false,
        private readonly ?int $inpaintingFill = null,
        private readonly bool $inpaintingFillSet = false,
        private readonly ?bool $inpaintFullRes = null,
        private readonly bool $inpaintFullResSet = false,
        private readonly ?int $inpaintFullResPadding = null,
        private readonly bool $inpaintFullResPaddingSet = false,
        private readonly ?int $inpaintingMaskInvert = null,
        private readonly bool $inpaintingMaskInvertSet = false,
        private readonly ?float $initialNoiseMultiplier = null,
        private readonly bool $initialNoiseMultiplierSet = false,
        private readonly ?float $imageCfgScale = null,
        private readonly bool $imageCfgScaleSet = false,
        private readonly ?bool $maskRound = null,
        private readonly bool $maskRoundSet = false,
    ) {
    }

    public static function new(): self
    {
        return new self();
    }

    /**
     * @return array<string, array{0:string,1:string,2:string,3:?string}>
     */
    private static function specByKey(): array
    {
        static $cache = null;
        $cache ??= array_column(self::FIELD_SPEC, null, 1);

        return $cache;
    }

    /**
     * @return array<string, string> prop => key
     */
    private static function wireForProp(): array
    {
        static $cache = null;
        $cache ??= array_column(self::FIELD_SPEC, 0, 1);

        return $cache;
    }

    /**
     * Explicitly-set fields in canonical order (MediaRequest: wire names; GenerationParams: camel props).
     *
     * @return list<string>
     */
    public function setFields(): array
    {
        $out = [];
        foreach (self::FIELD_SPEC as $field) {
            if ($this->{$field[1] . 'Set'}) {
                $out[] = $field[1];
            }
        }

        return $out;
    }

    public function isSet(string $prop): bool
    {
        return in_array($prop, self::PROPS, true) && $this->{$prop . 'Set'};
    }

    /**
     * Internal camelCase shape (form persistence, W3's domain): set knobs only,
     * canonical order, unknown keys refused on parse — no tolerance list here
     * because this shape never crosses a network boundary.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];
        foreach (self::FIELD_SPEC as $field) {
            if ($this->{$field[1] . 'Set'}) {
                $out[$field[1]] = $this->{$field[1]};
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public static function fromArray(array $fields): self
    {
        $params = new self();
        foreach ($fields as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException(__METHOD__ . '(): keys must be strings, got ' . gettype($key));
            }
            $spec = self::specByKey()[$key] ?? null;
            if ($spec === null) {
                throw new \InvalidArgumentException(__METHOD__ . "(): '{$key}' is not a modelled generation parameter");
            }
            $method = 'with' . ucfirst($spec[1]);
            if ($value === null) {
                $params = $params->{$method}(null);

                continue;
            }
            $typed = match ($spec[2]) {
                's' => is_string($value) ? $value : self::reject($key, 'string', $value),
                'i' => is_int($value) ? $value : self::reject($key, 'int', $value),
                'f' => (is_float($value) || is_int($value)) ? (float) $value : self::reject($key, 'float', $value),
                'b' => is_bool($value) ? $value : self::reject($key, 'bool', $value),
                'a' => is_array($value) ? $value : self::reject($key, 'array', $value),
            };
            $params = $params->{$method}($typed);
        }

        return $params;
    }

    private static function reject(string $key, string $wanted, mixed $given): never
    {
        throw new \InvalidArgumentException('GenerationParams::fromArray(): parameter \'' . $key . "' expects {$wanted}, got " . get_debug_type($given));
    }

    // ——— value accessors ———

    // ——— Prompt block (crush_media §4.2, plan Appendix F) ———

    public function negativePrompt(): ?string
    {
        return $this->negativePrompt;
    }

    public function styles(): ?array
    {
        return $this->styles;
    }

    // ——— Seed block (-1 == random server-side; 0 is a legitimate pinned seed) ———

    public function seed(): ?int
    {
        return $this->seed;
    }

    public function subseed(): ?int
    {
        return $this->subseed;
    }

    public function subseedStrength(): ?float
    {
        return $this->subseedStrength;
    }

    public function seedResizeFromH(): ?int
    {
        return $this->seedResizeFromH;
    }

    public function seedResizeFromW(): ?int
    {
        return $this->seedResizeFromW;
    }

    // ——— Sampler & canvas ———

    public function samplerName(): ?string
    {
        return $this->samplerName;
    }

    public function scheduler(): ?string
    {
        return $this->scheduler;
    }

    public function batchSize(): ?int
    {
        return $this->batchSize;
    }

    public function nIter(): ?int
    {
        return $this->nIter;
    }

    public function steps(): ?int
    {
        return $this->steps;
    }

    public function cfgScale(): ?float
    {
        return $this->cfgScale;
    }

    public function width(): ?int
    {
        return $this->width;
    }

    public function height(): ?int
    {
        return $this->height;
    }

    // ——— Hires-fix block (enable_hr gates the second pass; hr_resize_x/y override hr_scale) ———

    public function enableHr(): ?bool
    {
        return $this->enableHr;
    }

    public function denoisingStrength(): ?float
    {
        return $this->denoisingStrength;
    }

    public function firstphaseWidth(): ?int
    {
        return $this->firstphaseWidth;
    }

    public function firstphaseHeight(): ?int
    {
        return $this->firstphaseHeight;
    }

    public function hrScale(): ?float
    {
        return $this->hrScale;
    }

    public function hrUpscaler(): ?string
    {
        return $this->hrUpscaler;
    }

    public function hrSecondPassSteps(): ?int
    {
        return $this->hrSecondPassSteps;
    }

    public function hrResizeX(): ?int
    {
        return $this->hrResizeX;
    }

    public function hrResizeY(): ?int
    {
        return $this->hrResizeY;
    }

    public function hrCheckpointName(): ?string
    {
        return $this->hrCheckpointName;
    }

    public function hrSamplerName(): ?string
    {
        return $this->hrSamplerName;
    }

    public function hrScheduler(): ?string
    {
        return $this->hrScheduler;
    }

    public function hrPrompt(): ?string
    {
        return $this->hrPrompt;
    }

    public function hrNegativePrompt(): ?string
    {
        return $this->hrNegativePrompt;
    }

    // ——— Sampler extras / post-finish (null restore_faces/tiling == "opts decides" — the XSet sentinel IS the None semantics) ———

    public function restoreFaces(): ?bool
    {
        return $this->restoreFaces;
    }

    public function tiling(): ?bool
    {
        return $this->tiling;
    }

    public function eta(): ?float
    {
        return $this->eta;
    }

    public function sChurn(): ?float
    {
        return $this->sChurn;
    }

    public function sTmax(): ?float
    {
        return $this->sTmax;
    }

    public function sTmin(): ?float
    {
        return $this->sTmin;
    }

    public function sNoise(): ?float
    {
        return $this->sNoise;
    }

    public function sMinUncond(): ?float
    {
        return $this->sMinUncond;
    }

    public function tokenMergingRatio(): ?float
    {
        return $this->tokenMergingRatio;
    }

    public function tokenMergingRatioHr(): ?float
    {
        return $this->tokenMergingRatioHr;
    }

    public function refinerCheckpoint(): ?string
    {
        return $this->refinerCheckpoint;
    }

    public function refinerSwitchAt(): ?float
    {
        return $this->refinerSwitchAt;
    }

    // ——— img2img block (crush_media §4.3, plan Appendix F) ———

    public function resizeMode(): ?int
    {
        return $this->resizeMode;
    }

    public function maskBlur(): ?int
    {
        return $this->maskBlur;
    }

    public function maskBlurX(): ?int
    {
        return $this->maskBlurX;
    }

    public function maskBlurY(): ?int
    {
        return $this->maskBlurY;
    }

    public function inpaintingFill(): ?int
    {
        return $this->inpaintingFill;
    }

    public function inpaintFullRes(): ?bool
    {
        return $this->inpaintFullRes;
    }

    public function inpaintFullResPadding(): ?int
    {
        return $this->inpaintFullResPadding;
    }

    public function inpaintingMaskInvert(): ?int
    {
        return $this->inpaintingMaskInvert;
    }

    public function initialNoiseMultiplier(): ?float
    {
        return $this->initialNoiseMultiplier;
    }

    public function imageCfgScale(): ?float
    {
        return $this->imageCfgScale;
    }

    public function maskRound(): ?bool
    {
        return $this->maskRound;
    }

    // ——— fluent setters (XSet law: every setter marks the field explicitly set;
    //      pass null to record an explicit None, omit the call to leave unset) ———

    // ——— Prompt block (crush_media §4.2, plan Appendix F) ———

    public function withNegativePrompt(?string $value): self
    {
        return $this->mutate(['negativePrompt' => $value, 'negativePromptSet' => true]);
    }

    public function withStyles(?array $value): self
    {
        return $this->mutate(['styles' => $value, 'stylesSet' => true]);
    }

    // ——— Seed block (-1 == random server-side; 0 is a legitimate pinned seed) ———

    /** Mirrors StableDiffusionProcessing.seed. -1 asks the server for a random seed; 0 is a legitimate pinned value, which is exactly why the sentinel (isSet) rather than the value distinguishes "unset" from "zero". */
    public function withSeed(?int $value): self
    {
        return $this->mutate(['seed' => $value, 'seedSet' => true]);
    }

    public function withSubseed(?int $value): self
    {
        return $this->mutate(['subseed' => $value, 'subseedSet' => true]);
    }

    /** Mirrors StableDiffusionProcessing.subseed_strength. Guarded 0..1: this is a domain nonsense-refusal (interpolation fraction), not value coercion — Wave-3 form owns clamping. */
    public function withSubseedStrength(?float $value): self
    {
            if ($value < 0.0 || $value > 1.0) {
                throw new \InvalidArgumentException(__METHOD__ . '(): "subseed_strength must be within 0..1, given ".$value . " — refusal, not coercion: Wave-3 form owns clamping (plan W1.1)');
            }

        return $this->mutate(['subseedStrength' => $value, 'subseedStrengthSet' => true]);
    }

    public function withSeedResizeFromH(?int $value): self
    {
        return $this->mutate(['seedResizeFromH' => $value, 'seedResizeFromHSet' => true]);
    }

    public function withSeedResizeFromW(?int $value): self
    {
        return $this->mutate(['seedResizeFromW' => $value, 'seedResizeFromWSet' => true]);
    }

    // ——— Sampler & canvas ———

    public function withSamplerName(?string $value): self
    {
        return $this->mutate(['samplerName' => $value, 'samplerNameSet' => true]);
    }

    public function withScheduler(?string $value): self
    {
        return $this->mutate(['scheduler' => $value, 'schedulerSet' => true]);
    }

    /** Mirrors StableDiffusionProcessing.batch_size (G in the A1111 UI). >=1 refusal: a zero-batch request is nonsense the server would only reject later, far from here. */
    public function withBatchSize(?int $value): self
    {
            if ($value < 1) {
                throw new \InvalidArgumentException(__METHOD__ . '(): "batch_size must be >= 1, given ".$value . " — refusal, not coercion: Wave-3 form owns clamping (plan W1.1)');
            }

        return $this->mutate(['batchSize' => $value, 'batchSizeSet' => true]);
    }

    /** Mirrors StableDiffusionProcessing.n_iter. >=1 refusal as for batch_size. */
    public function withNIter(?int $value): self
    {
            if ($value < 1) {
                throw new \InvalidArgumentException(__METHOD__ . '(): "n_iter must be >= 1, given ".$value . " — refusal, not coercion: Wave-3 form owns clamping (plan W1.1)');
            }

        return $this->mutate(['nIter' => $value, 'nIterSet' => true]);
    }

    /** No guard: the UI floor is 20 but sdapi itself accepts small values — pinning withSteps(0) as legal-but-set keeps clamping out of this layer. */
    public function withSteps(?int $value): self
    {
        return $this->mutate(['steps' => $value, 'stepsSet' => true]);
    }

    public function withCfgScale(?float $value): self
    {
        return $this->mutate(['cfgScale' => $value, 'cfgScaleSet' => true]);
    }

    public function withWidth(?int $value): self
    {
        return $this->mutate(['width' => $value, 'widthSet' => true]);
    }

    public function withHeight(?int $value): self
    {
        return $this->mutate(['height' => $value, 'heightSet' => true]);
    }

    // ——— Hires-fix block (enable_hr gates the second pass; hr_resize_x/y override hr_scale) ———

    public function withEnableHr(?bool $value): self
    {
        return $this->mutate(['enableHr' => $value, 'enableHrSet' => true]);
    }

    /** On txt2img this is the hires second-pass denoise (§4.2); on img2img it is the primary knob (§4.3). Same field, different meaning per route — the request shape, not the DTO, carries that distinction. */
    public function withDenoisingStrength(?float $value): self
    {
        return $this->mutate(['denoisingStrength' => $value, 'denoisingStrengthSet' => true]);
    }

    public function withFirstphaseWidth(?int $value): self
    {
        return $this->mutate(['firstphaseWidth' => $value, 'firstphaseWidthSet' => true]);
    }

    public function withFirstphaseHeight(?int $value): self
    {
        return $this->mutate(['firstphaseHeight' => $value, 'firstphaseHeightSet' => true]);
    }

    public function withHrScale(?float $value): self
    {
        return $this->mutate(['hrScale' => $value, 'hrScaleSet' => true]);
    }

    public function withHrUpscaler(?string $value): self
    {
        return $this->mutate(['hrUpscaler' => $value, 'hrUpscalerSet' => true]);
    }

    /** Mirrors hr_second_pass_steps: 0 means "same as steps" server-side; that meaning is why no >=1 guard rides here. */
    public function withHrSecondPassSteps(?int $value): self
    {
        return $this->mutate(['hrSecondPassSteps' => $value, 'hrSecondPassStepsSet' => true]);
    }

    /** Mirrors hr_resize_x: nonzero overrides hr_scale server-side (§4.2). */
    public function withHrResizeX(?int $value): self
    {
        return $this->mutate(['hrResizeX' => $value, 'hrResizeXSet' => true]);
    }

    /** Mirrors hr_resize_y: with hr_resize_x, nonzero overrides hr_scale (§4.2). */
    public function withHrResizeY(?int $value): self
    {
        return $this->mutate(['hrResizeY' => $value, 'hrResizeYSet' => true]);
    }

    public function withHrCheckpointName(?string $value): self
    {
        return $this->mutate(['hrCheckpointName' => $value, 'hrCheckpointNameSet' => true]);
    }

    public function withHrSamplerName(?string $value): self
    {
        return $this->mutate(['hrSamplerName' => $value, 'hrSamplerNameSet' => true]);
    }

    public function withHrScheduler(?string $value): self
    {
        return $this->mutate(['hrScheduler' => $value, 'hrSchedulerSet' => true]);
    }

    public function withHrPrompt(?string $value): self
    {
        return $this->mutate(['hrPrompt' => $value, 'hrPromptSet' => true]);
    }

    public function withHrNegativePrompt(?string $value): self
    {
        return $this->mutate(['hrNegativePrompt' => $value, 'hrNegativePromptSet' => true]);
    }

    // ——— Sampler extras / post-finish (null restore_faces/tiling == "opts decides" — the XSet sentinel IS the None semantics) ———

    /** Mirrors StableDiffusionProcessing.restore_faces. Absent == deferred to server opts; explicit false forces no-fix. Nullable+sentinel preserves that third state. */
    public function withRestoreFaces(?bool $value): self
    {
        return $this->mutate(['restoreFaces' => $value, 'restoreFacesSet' => true]);
    }

    /** Mirrors StableDiffusionProcessing.tiling. Absent == opts, same None-semantics as restore_faces. */
    public function withTiling(?bool $value): self
    {
        return $this->mutate(['tiling' => $value, 'tilingSet' => true]);
    }

    public function withEta(?float $value): self
    {
        return $this->mutate(['eta' => $value, 'etaSet' => true]);
    }

    public function withSChurn(?float $value): self
    {
        return $this->mutate(['sChurn' => $value, 'sChurnSet' => true]);
    }

    public function withSTmax(?float $value): self
    {
        return $this->mutate(['sTmax' => $value, 'sTmaxSet' => true]);
    }

    public function withSTmin(?float $value): self
    {
        return $this->mutate(['sTmin' => $value, 'sTminSet' => true]);
    }

    public function withSNoise(?float $value): self
    {
        return $this->mutate(['sNoise' => $value, 'sNoiseSet' => true]);
    }

    public function withSMinUncond(?float $value): self
    {
        return $this->mutate(['sMinUncond' => $value, 'sMinUncondSet' => true]);
    }

    public function withTokenMergingRatio(?float $value): self
    {
        return $this->mutate(['tokenMergingRatio' => $value, 'tokenMergingRatioSet' => true]);
    }

    public function withTokenMergingRatioHr(?float $value): self
    {
        return $this->mutate(['tokenMergingRatioHr' => $value, 'tokenMergingRatioHrSet' => true]);
    }

    /** Mirrors refiner_checkpoint (§4.2, SD3/Refiner flows). */
    public function withRefinerCheckpoint(?string $value): self
    {
        return $this->mutate(['refinerCheckpoint' => $value, 'refinerCheckpointSet' => true]);
    }

    public function withRefinerSwitchAt(?float $value): self
    {
        return $this->mutate(['refinerSwitchAt' => $value, 'refinerSwitchAtSet' => true]);
    }

    // ——— img2img block (crush_media §4.3, plan Appendix F) ———

    /** 0 stretch, 1 crop, 2 fill (§4.3). */
    public function withResizeMode(?int $value): self
    {
        return $this->mutate(['resizeMode' => $value, 'resizeModeSet' => true]);
    }

    public function withMaskBlur(?int $value): self
    {
        return $this->mutate(['maskBlur' => $value, 'maskBlurSet' => true]);
    }

    public function withMaskBlurX(?int $value): self
    {
        return $this->mutate(['maskBlurX' => $value, 'maskBlurXSet' => true]);
    }

    public function withMaskBlurY(?int $value): self
    {
        return $this->mutate(['maskBlurY' => $value, 'maskBlurYSet' => true]);
    }

    /** Exactly four values (A1111 inpainting_fill.py): 0 fill, 1 original, 2 latent noise, 3 latent nothing (conflict C-1 adjudicated against the clone). No guard here — Wave-3 select enforces the domain. */
    public function withInpaintingFill(?int $value): self
    {
        return $this->mutate(['inpaintingFill' => $value, 'inpaintingFillSet' => true]);
    }

    public function withInpaintFullRes(?bool $value): self
    {
        return $this->mutate(['inpaintFullRes' => $value, 'inpaintFullResSet' => true]);
    }

    /** API default 0, UI default 32 (§4.3) — why leaving it unset differs from setting 0. */
    public function withInpaintFullResPadding(?int $value): self
    {
        return $this->mutate(['inpaintFullResPadding' => $value, 'inpaintFullResPaddingSet' => true]);
    }

    /** 0 normal, 1 invert (black repaints) per §4.3. */
    public function withInpaintingMaskInvert(?int $value): self
    {
        return $this->mutate(['inpaintingMaskInvert' => $value, 'inpaintingMaskInvertSet' => true]);
    }

    /** img2img-only (§4.3). */
    public function withInitialNoiseMultiplier(?float $value): self
    {
        return $this->mutate(['initialNoiseMultiplier' => $value, 'initialNoiseMultiplierSet' => true]);
    }

    /** Edit-models channel (§4.3). */
    public function withImageCfgScale(?float $value): self
    {
        return $this->mutate(['imageCfgScale' => $value, 'imageCfgScaleSet' => true]);
    }

    /** Mirrors StableDiffusionProcessingImg2Img.mask_round (wire default true): rounds the mask to 8px latent alignment; upstream docs note blur interplay — when rounding is on, sub-8px blur detail can be lost at round time. Read mask_blur semantics before flipping this. */
    public function withMaskRound(?bool $value): self
    {
        return $this->mutate(['maskRound' => $value, 'maskRoundSet' => true]);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media;

use SugarCraft\Core\Concerns\Mutable;

/**
 *  * One sdapi generation request — the full txt2img (§4.2) plus img2img (§4.3)
 * parameter surface as one tolerant shape, plan W1.1 (plan_crush_media.md).
 *
 * Mirrors stable-diffusion-webui's StableDiffusionProcessingTxt2Img and
 * StableDiffusionProcessingImg2Img API-mode fields; wire names are the exact
 * snake_case spellings from api.txt (crush_media Appendix F §4.2/§4.3). One
 * class rather than two because every img2img field is accepted by the
 * txt2img dataclass path too (the handler just ignores what does not apply),
 * and the route choice is the caller's (W1.4's Request builders), not the
 * DTO's.
 *
 * House laws pinned here (plan W1.1):
 * - Unset != zero: each field carries a paired sentinel; toArray() emits ONLY
 *   explicitly-set fields, so a never-touched request serialises to {} and the
 *   SERVER defaults (seed=-1 random, steps=20 UI / 50 API, batch 1 …) apply.
 *   Setting `sendImages(false)` and leaving it alone are different states.
 * - No value coercion: only three domain refusals exist (subseed_strength in
 *   0..1, batch_size >= 1, n_iter >= 1); everything else passes through
 *   untouched. Wave-3 forms own clamping/sliders.
 * - Unknown wire keys are tolerated, collected, and re-emitted (§4.1) so
 *   forward-compatible extras survive round-trips; keys on the API_NOT_ALLOWED
 *   or NOT_SUPPORTED_V1 rosters are refused by name instead.
 * - Explicit null on the wire parses to set-with-null — the None → "opts
 *   decides" semantics of restore_faces/tiling et al. round-trip exactly.
 *
 * Immutable + fluent per candy-core Mutable/XSet convention
 * (candy-sprinkles/src/Style.php is the canonical shape).
 */
final readonly class MediaRequest
{
    use Mutable;

    /**
     * [key, prop, kind, guard] — canonical order IS the wire emit order.
     */
    private const FIELD_SPEC = [
        ['prompt', 'prompt', 's', null],
        ['negative_prompt', 'negativePrompt', 's', null],
        ['styles', 'styles', 'a', null],
        ['seed', 'seed', 'i', null],
        ['subseed', 'subseed', 'i', null],
        ['subseed_strength', 'subseedStrength', 'f', 'fr'],
        ['seed_resize_from_h', 'seedResizeFromH', 'i', null],
        ['seed_resize_from_w', 'seedResizeFromW', 'i', null],
        ['sampler_name', 'samplerName', 's', null],
        ['scheduler', 'scheduler', 's', null],
        ['batch_size', 'batchSize', 'i', 'g1'],
        ['n_iter', 'nIter', 'i', 'g1'],
        ['steps', 'steps', 'i', null],
        ['cfg_scale', 'cfgScale', 'f', null],
        ['width', 'width', 'i', null],
        ['height', 'height', 'i', null],
        ['enable_hr', 'enableHr', 'b', null],
        ['denoising_strength', 'denoisingStrength', 'f', null],
        ['firstphase_width', 'firstphaseWidth', 'i', null],
        ['firstphase_height', 'firstphaseHeight', 'i', null],
        ['hr_scale', 'hrScale', 'f', null],
        ['hr_upscaler', 'hrUpscaler', 's', null],
        ['hr_second_pass_steps', 'hrSecondPassSteps', 'i', null],
        ['hr_resize_x', 'hrResizeX', 'i', null],
        ['hr_resize_y', 'hrResizeY', 'i', null],
        ['hr_checkpoint_name', 'hrCheckpointName', 's', null],
        ['hr_sampler_name', 'hrSamplerName', 's', null],
        ['hr_scheduler', 'hrScheduler', 's', null],
        ['hr_prompt', 'hrPrompt', 's', null],
        ['hr_negative_prompt', 'hrNegativePrompt', 's', null],
        ['restore_faces', 'restoreFaces', 'b', null],
        ['tiling', 'tiling', 'b', null],
        ['eta', 'eta', 'f', null],
        ['s_churn', 'sChurn', 'f', null],
        ['s_tmax', 'sTmax', 'f', null],
        ['s_tmin', 'sTmin', 'f', null],
        ['s_noise', 'sNoise', 'f', null],
        ['s_min_uncond', 'sMinUncond', 'f', null],
        ['token_merging_ratio', 'tokenMergingRatio', 'f', null],
        ['token_merging_ratio_hr', 'tokenMergingRatioHr', 'f', null],
        ['refiner_checkpoint', 'refinerCheckpoint', 's', null],
        ['refiner_switch_at', 'refinerSwitchAt', 'f', null],
        ['override_settings', 'overrideSettings', 'a', null],
        ['override_settings_restore_afterwards', 'overrideSettingsRestoreAfterwards', 'b', null],
        ['do_not_save_samples', 'doNotSaveSamples', 'b', null],
        ['do_not_save_grid', 'doNotSaveGrid', 'b', null],
        ['disable_extra_networks', 'disableExtraNetworks', 'b', null],
        ['comments', 'comments', 's', null],
        ['script_name', 'scriptName', 's', null],
        ['script_args', 'scriptArgs', 'a', null],
        ['send_images', 'sendImages', 'b', null],
        ['save_images', 'saveImages', 'b', null],
        ['alwayson_scripts', 'alwaysonScripts', 'a', null],
        ['force_task_id', 'forceTaskId', 's', null],
        ['infotext', 'infotext', 's', null],
        ['init_images', 'initImages', 'a', null],
        ['resize_mode', 'resizeMode', 'i', null],
        ['mask', 'mask', 's', null],
        ['mask_blur', 'maskBlur', 'i', null],
        ['mask_blur_x', 'maskBlurX', 'i', null],
        ['mask_blur_y', 'maskBlurY', 'i', null],
        ['inpainting_fill', 'inpaintingFill', 'i', null],
        ['inpaint_full_res', 'inpaintFullRes', 'b', null],
        ['inpaint_full_res_padding', 'inpaintFullResPadding', 'i', null],
        ['inpainting_mask_invert', 'inpaintingMaskInvert', 'i', null],
        ['initial_noise_multiplier', 'initialNoiseMultiplier', 'f', null],
        ['image_cfg_scale', 'imageCfgScale', 'f', null],
        ['include_init_images', 'includeInitImages', 'b', null],
        ['mask_round', 'maskRound', 'b', null],
    ];
    /**
     * Fields sdapi's API mode REFUSES in the request (crush_media §4.1, plan
     * Appendix F): server-owned internals, legacy aliases, or fields the handler
     * re-derives. Parsed requests carrying one of these fail fast with this
     * exact vocabulary rather than being silently dropped or forwarded.
     */
    public const API_NOT_ALLOWED = [
        'self',
        'kwargs',
        'sd_model',
        'outpath_samples',
        'outpath_grids',
        'sampler_index',
        'extra_generation_params',
        'overlay_images',
        'do_not_reload_embeddings',
        'seed_enable_extras',
        'prompt_for_display',
        'sampler_noise_scheduler_override',
        'ddim_discretize',
    ];

    /**
     * Wire fields this v1 deliberately does not model (crush_media §4.3):
     * latent_mask is init=False on the upstream dataclass — only the script/UI
     * canvas side sets it, so an API-level refusal is honest and the reason is
     * discoverable at the throw site.
     *
     * @var array<string, string>
     */
    public const NOT_SUPPORTED_V1 = [
        'latent_mask' => 'UI/canvas-side only (upstream init=False, crush_media §4.3)',
    ];

    private function __construct(
        private readonly ?string $prompt = null,
        private readonly bool $promptSet = false,
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
        private readonly ?array $overrideSettings = null,
        private readonly bool $overrideSettingsSet = false,
        private readonly ?bool $overrideSettingsRestoreAfterwards = null,
        private readonly bool $overrideSettingsRestoreAfterwardsSet = false,
        private readonly ?bool $doNotSaveSamples = null,
        private readonly bool $doNotSaveSamplesSet = false,
        private readonly ?bool $doNotSaveGrid = null,
        private readonly bool $doNotSaveGridSet = false,
        private readonly ?bool $disableExtraNetworks = null,
        private readonly bool $disableExtraNetworksSet = false,
        private readonly ?string $comments = null,
        private readonly bool $commentsSet = false,
        private readonly ?string $scriptName = null,
        private readonly bool $scriptNameSet = false,
        private readonly ?array $scriptArgs = null,
        private readonly bool $scriptArgsSet = false,
        private readonly ?bool $sendImages = null,
        private readonly bool $sendImagesSet = false,
        private readonly ?bool $saveImages = null,
        private readonly bool $saveImagesSet = false,
        private readonly ?array $alwaysonScripts = null,
        private readonly bool $alwaysonScriptsSet = false,
        private readonly ?string $forceTaskId = null,
        private readonly bool $forceTaskIdSet = false,
        private readonly ?string $infotext = null,
        private readonly bool $infotextSet = false,
        private readonly ?array $initImages = null,
        private readonly bool $initImagesSet = false,
        private readonly ?int $resizeMode = null,
        private readonly bool $resizeModeSet = false,
        private readonly ?string $mask = null,
        private readonly bool $maskSet = false,
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
        private readonly ?bool $includeInitImages = null,
        private readonly bool $includeInitImagesSet = false,
        private readonly ?bool $maskRound = null,
        private readonly bool $maskRoundSet = false,
        private readonly array $unknowns = [],
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
        $cache ??= array_column(self::FIELD_SPEC, null, 0);

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
                $out[] = $field[0];
            }
        }

        return $out;
    }

    /**
     * Tolerated extension keys (§4.1 tolerance law): unknown wire names collected
     * here survive parse → toArray round-trips so a forge-style extra_params or a
     * future sdapi addition is never silently destroyed by a DTO rewrite.
     *
     * @return array<string, mixed>
     */
    public function unknownKeys(): array
    {
        return $this->unknowns;
    }

    public function withUnknown(string $key, mixed $value): self
    {
        if ($key === '' || array_key_exists($key, self::specByKey())) {
            throw new \InvalidArgumentException(__METHOD__ . "(): '{$key}' is not an extension key — modelled fields use their own withers");
        }

        return $this->mutate(['unknowns' => $this->unknowns + [$key => $value]]);
    }

    /**
     * Is the given WIRE name explicitly set (including set-to-null)?
     */
    public function isSet(string $wireName): bool
    {
        $spec = self::specByKey()[$wireName] ?? null;

        return $spec !== null && $this->{$spec[1] . 'Set'};
    }

    /**
     * Internal-prop-name view of isSet(), used by the params composer.
     */
    public function propIsSet(string $prop): bool
    {
        return $this->isSet(self::wireForProp()[$prop] ?? "\0");
    }

    /**
     * The sdapi JSON body: only explicitly-set fields, in canonical §4.2-then-
     * §4.3 order, then tolerated unknown keys (§4.1). Unset fields are ABSENT —
     * never null, never a DTO-invented default — so the server defaults own what
     * the caller did not decide.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];
        foreach (self::FIELD_SPEC as $field) {
            if ($this->{$field[1] . 'Set'}) {
                $out[$field[0]] = $this->{$field[1]};
            }
        }
        foreach ($this->unknowns as $key => $value) {
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Parse an sdapi JSON body at the boundary (plan W1.1): every key routes
     * through the same guarded withers the builder UI will use, so a hand-typed
     * payload cannot skip the three domain refusals. Explicit null means
     * "set-to-null" (the None → opts semantics), never "absent".
     *
     * @param  array<string, mixed>  $wire
     */
    public static function fromArray(array $wire): self
    {
        $request = new self();
        foreach ($wire as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException(__METHOD__ . '(): wire keys must be strings, got ' . gettype($key));
            }
            $spec = self::specByKey()[$key] ?? null;
            if ($spec === null) {
                if (in_array($key, self::API_NOT_ALLOWED, true)) {
                    throw new \InvalidArgumentException(__METHOD__ . "(): '{$key}' is not accepted in API mode (crush_media §4.1)");
                }
                if (isset(self::NOT_SUPPORTED_V1[$key])) {
                    throw new \InvalidArgumentException(__METHOD__ . "(): '{$key}' is not supported in v1 — " . self::NOT_SUPPORTED_V1[$key]);
                }
                $request = $request->withUnknown($key, $value);

                continue;
            }
            $request = self::applyWireValue($request, $spec, $key, $value);
        }

        return $request;
    }

    /**
     * Build a wire request from the form-shaped parameter object plus the
     * free-text prompt (plan W1.1): only fields the params carry are mapped, so
     * unset stays unset end-to-end and the server defaults survive.
     */
    public static function mediaRequestFrom(GenerationParams $params, string $prompt): self
    {
        $request = self::new()->withPrompt($prompt);
        foreach (GenerationParams::PROPS as $prop) {
            if (!$params->isSet($prop)) {
                continue;
            }
            $method = 'with' . ucfirst($prop);
            $request = $request->{$method}($params->{$prop}());
        }

        return $request;
    }

    /**
     * Inverse projection: the modelled-knob subset of this request as form
     * params (§4.2). Client-internal fields (send_images, force_task_id, …) and
     * payload fields (init_images, mask, …) have no home in GenerationParams by
     * design and are dropped — prompt too, which is why the projection is not
     * claimed to be a total inverse of mediaRequestFrom.
     */
    public function toParamsProjection(): GenerationParams
    {
        $params = GenerationParams::new();
        foreach (GenerationParams::PROPS as $prop) {
            if (!$this->propIsSet($prop)) {
                continue;
            }
            $method = 'with' . ucfirst($prop);
            $params = $params->{$method}($this->{$prop}());
        }

        return $params;
    }

    private static function applyWireValue(self $request, array $spec, string $key, mixed $value): self
    {
        $method = 'with' . ucfirst($spec[1]);
        if ($value === null) {
            return $request->{$method}(null);
        }
        $typed = match ($spec[2]) {
            's' => is_string($value) ? $value : self::reject($key, 'string', $value),
            'i' => is_int($value) ? $value : self::reject($key, 'int', $value),
            // JSON cannot distinguish 7 from 7.0; an int arriving at a float
            // field is the same number, so it is widened, not rejected.
            'f' => (is_float($value) || is_int($value)) ? (float) $value : self::reject($key, 'float', $value),
            'b' => is_bool($value) ? $value : self::reject($key, 'bool', $value),
            'a' => is_array($value) ? $value : self::reject($key, 'array', $value),
        };

        return $request->{$method}($typed);
    }

    private static function reject(string $key, string $wanted, mixed $given): never
    {
        throw new \InvalidArgumentException("MediaRequest::fromArray(): field '{$key}' expects {$wanted}, got " . get_debug_type($given));
    }

    // ——— value accessors ———

    // ——— Prompt block (crush_media §4.2, plan Appendix F) ———

    public function prompt(): ?string
    {
        return $this->prompt;
    }

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

    // ——— Output control / meta ———

    public function overrideSettings(): ?array
    {
        return $this->overrideSettings;
    }

    public function overrideSettingsRestoreAfterwards(): ?bool
    {
        return $this->overrideSettingsRestoreAfterwards;
    }

    public function doNotSaveSamples(): ?bool
    {
        return $this->doNotSaveSamples;
    }

    public function doNotSaveGrid(): ?bool
    {
        return $this->doNotSaveGrid;
    }

    public function disableExtraNetworks(): ?bool
    {
        return $this->disableExtraNetworks;
    }

    public function comments(): ?string
    {
        return $this->comments;
    }

    public function scriptName(): ?string
    {
        return $this->scriptName;
    }

    public function scriptArgs(): ?array
    {
        return $this->scriptArgs;
    }

    public function sendImages(): ?bool
    {
        return $this->sendImages;
    }

    public function saveImages(): ?bool
    {
        return $this->saveImages;
    }

    public function alwaysonScripts(): ?array
    {
        return $this->alwaysonScripts;
    }

    public function forceTaskId(): ?string
    {
        return $this->forceTaskId;
    }

    public function infotext(): ?string
    {
        return $this->infotext;
    }

    // ——— img2img block (crush_media §4.3, plan Appendix F) ———

    public function initImages(): ?array
    {
        return $this->initImages;
    }

    public function resizeMode(): ?int
    {
        return $this->resizeMode;
    }

    public function mask(): ?string
    {
        return $this->mask;
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

    public function includeInitImages(): ?bool
    {
        return $this->includeInitImages;
    }

    public function maskRound(): ?bool
    {
        return $this->maskRound;
    }

    // ——— fluent setters (XSet law: every setter marks the field explicitly set;
    //      pass null to record an explicit None, omit the call to leave unset) ———

    // ——— Prompt block (crush_media §4.2, plan Appendix F) ———

    public function withPrompt(?string $value): self
    {
        return $this->mutate(['prompt' => $value, 'promptSet' => true]);
    }

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

    // ——— Output control / meta ———

    /** Mirrors override_settings: arbitrary opts patch for the request lifetime; values may be ${ENV} placeholders that A1111 interpolates (§4.2). */
    public function withOverrideSettings(?array $value): self
    {
        return $this->mutate(['overrideSettings' => $value, 'overrideSettingsSet' => true]);
    }

    public function withOverrideSettingsRestoreAfterwards(?bool $value): self
    {
        return $this->mutate(['overrideSettingsRestoreAfterwards' => $value, 'overrideSettingsRestoreAfterwardsSet' => true]);
    }

    /** Mirrors do_not_save_samples. Note: the sdapi handler derives it from send_images/save_images semantics server-side (§4.2) — set it only when deliberately overriding. */
    public function withDoNotSaveSamples(?bool $value): self
    {
        return $this->mutate(['doNotSaveSamples' => $value, 'doNotSaveSamplesSet' => true]);
    }

    public function withDoNotSaveGrid(?bool $value): self
    {
        return $this->mutate(['doNotSaveGrid' => $value, 'doNotSaveGridSet' => true]);
    }

    public function withDisableExtraNetworks(?bool $value): self
    {
        return $this->mutate(['disableExtraNetworks' => $value, 'disableExtraNetworksSet' => true]);
    }

    public function withComments(?string $value): self
    {
        return $this->mutate(['comments' => $value, 'commentsSet' => true]);
    }

    /** Mirrors script_name: legacy single-script channel; modern scripts ride alwayson_scripts (§4.2). */
    public function withScriptName(?string $value): self
    {
        return $this->mutate(['scriptName' => $value, 'scriptNameSet' => true]);
    }

    public function withScriptArgs(?array $value): self
    {
        return $this->mutate(['scriptArgs' => $value, 'scriptArgsSet' => true]);
    }

    /** Wire default is true; leaving it UNSET lets the server default apply, which is not the same as forcing true (sentinel law). */
    public function withSendImages(?bool $value): self
    {
        return $this->mutate(['sendImages' => $value, 'sendImagesSet' => true]);
    }

    public function withSaveImages(?bool $value): self
    {
        return $this->mutate(['saveImages' => $value, 'saveImagesSet' => true]);
    }

    /** Shape: {"Script Title": {"args": [...]}} keyed by display name — the script-name registry is /sdapi/v1/scripts (W1.4), args are script-private payloads this layer passes through untouched (§4.5). */
    public function withAlwaysonScripts(?array $value): self
    {
        return $this->mutate(['alwaysonScripts' => $value, 'alwaysonScriptsSet' => true]);
    }

    /** Client-chosen task pin (§4.6): lets the caller know the queue id before submit; the server accepts or ignores it. Request-side only — never appears in GenerationParams. */
    public function withForceTaskId(?string $value): self
    {
        return $this->mutate(['forceTaskId' => $value, 'forceTaskIdSet' => true]);
    }

    /** Server-side paste-apply channel (§4.2): an infotext string whose parsed parameters fill unset fields. Request-side only. */
    public function withInfotext(?string $value): self
    {
        return $this->mutate(['infotext' => $value, 'infotextSet' => true]);
    }

    // ——— img2img block (crush_media §4.3, plan Appendix F) ———

    /** Base64 image strings (no data: prefix per §4.3); payload bytes live on the request, never on GenerationParams. */
    public function withInitImages(?array $value): self
    {
        return $this->mutate(['initImages' => $value, 'initImagesSet' => true]);
    }

    /** 0 stretch, 1 crop, 2 fill (§4.3). */
    public function withResizeMode(?int $value): self
    {
        return $this->mutate(['resizeMode' => $value, 'resizeModeSet' => true]);
    }

    /** Base64 PNG; white repaints, black keeps (§4.3). */
    public function withMask(?string $value): self
    {
        return $this->mutate(['mask' => $value, 'maskSet' => true]);
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

    /** Return the untouched init images in the response array (§4.3). */
    public function withIncludeInitImages(?bool $value): self
    {
        return $this->mutate(['includeInitImages' => $value, 'includeInitImagesSet' => true]);
    }

    /** Mirrors StableDiffusionProcessingImg2Img.mask_round (wire default true): rounds the mask to 8px latent alignment; upstream docs note blur interplay — when rounding is on, sub-8px blur detail can be lost at round time. Read mask_blur semantics before flipping this. */
    public function withMaskRound(?bool $value): self
    {
        return $this->mutate(['maskRound' => $value, 'maskRoundSet' => true]);
    }
}

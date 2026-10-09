<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media;

/**
 * Two-way label tables for the A1111 'parameters' infotext codec.
 *
 * Mirrors the label vocabulary of stable-diffusion-webui
 * `modules/images.py::create_infotext` and the legacy-spelling normalisation
 * of `modules/infotext_utils.py` / `infotext_versions.py`, as recorded in
 * plan_crush_media.md §3.5/§3.6 (Appendix F).
 *
 * SLOTS is the canonical emission skeleton in the exact §3.5 order; each slot
 * resolves its value from the DTO first, then from the caller's extras map,
 * then from the §2.10 sampler-knob region, so the codec can byte-replay a
 * parsed string without rehydrating every field. CONDITIONAL slots (Variation
 * pair, Tiling, Hires) are gated in Infotext::emit, not here.
 *
 * FIELDS maps a canonical label to the snake_case field name the DTO layer
 * speaks; labels with no DTO home (Model, VAE, Clip skip, ENSD, RNG, …) keep
 * a descriptive field name that applyTo() parks in MediaRequest's tolerated
 * unknown-key map — that gap is reported to the orchestrator, not patched
 * here (the DTOs are frozen for this lane).
 *
 * LEGACY folds upstream spellings onto canonical labels at PARSE time only;
 * emit always writes canonical (round-trip: legacy input re-emits canonical,
 * which is the documented normalisation direction).
 */
final class InfotextFields
{
    /**
     * Canonical label => snake field, in §3.5 emit-slot order.
     *
     * @var array<string, string>
     */
    public const FIELDS = [
        'Steps' => 'steps',
        'Sampler' => 'sampler_name',
        'Schedule type' => 'scheduler',
        'CFG scale' => 'cfg_scale',
        'Image CFG scale' => 'image_cfg_scale',
        'Seed' => 'seed',
        'Face restoration' => 'restore_faces',
        'Size' => 'size',
        'Model hash' => 'sd_model_hash',
        'Model' => 'sd_model_name',
        'FP8 weight' => 'fp8_weight',
        'Cache FP16 weight for LoRA' => 'fp8_cache_lora',
        'VAE hash' => 'sd_vae_hash',
        'VAE' => 'sd_vae_name',
        'Variation seed' => 'subseed',
        'Variation seed strength' => 'subseed_strength',
        'Seed resize from' => 'seed_resize_from',
        'Denoising strength' => 'denoising_strength',
        'Conditional mask weight' => 'conditional_mask_weight',
        'Clip skip' => 'clip_skip',
        'ENSD' => 'eta_noise_seed_delta',
        'Token merging ratio' => 'token_merging_ratio',
        'Token merging ratio hr' => 'token_merging_ratio_hr',
        'Init image hash' => 'init_images_hash',
        'RNG' => 'randn_source',
        'Tiling' => 'tiling',
        // §2.10 sampler-knob family — emitted only when the DTO carries them.
        'Eta' => 'eta',
        'Sigma churn' => 's_churn',
        'Schedule max sigma' => 's_tmax',
        'Schedule min sigma' => 's_tmin',
        'Sigma noise' => 's_noise',
        'NGMS' => 's_min_uncond',
        // Hires-fix family (post-1.7 infotext) — extras-only, DTO has the
        // wire fields but §3.5's line does not, so these ride the extras.
        'Hires upscale' => 'hr_scale',
        'Hires upscaler' => 'hr_upscaler',
        'Hires steps' => 'hr_second_pass_steps',
        'Hires denoising strength' => 'hr_denoising_strength',
        'Firstpass width' => 'firstphase_width',
        'Firstpass height' => 'firstphase_height',
        // Bookends and strays.
        'Style' => 'styles',
        'Sugar-crush' => 'sugar_crush_version',
        'Version' => 'version',
        'User' => 'user',
    ];

    /**
     * Legacy label => canonical label (parse-time normalisation only).
     *
     * 'Variance seed' is the pre-rename spelling of 'Variation seed' kept in
     * old PNGs; 'Sampling steps' is the pre-1.6 spelling of 'Steps'.
     *
     * @var array<string, string>
     */
    public const LEGACY = [
        'Sampling steps' => 'Steps',
        'Variance seed' => 'Variation seed',
        'Variance seed strength' => 'Variation seed strength',
        'Variation amount' => 'Variation seed strength',
        'Hires upscale' => 'Hires upscale',
        'Denoising strength (highres)' => 'Hires denoising strength',
        'Hires denoising strength' => 'Hires denoising strength',
    ];

    /**
     * Labels allowed to anchor the parameter line during parse — the first
     * token of a real A1111 parameters tail. A line only becomes the param
     * line when it opens with one of these followed by ':'.
     *
     * @var list<string>
     */
    public const STARTERS = [
        'Steps', 'Sampling steps', 'Sampler', 'Schedule type', 'CFG scale',
        'Image CFG scale', 'Seed', 'Variation seed', 'Variance seed',
        'Variation seed strength', 'Variance seed strength',
        'Seed resize from', 'Denoising strength', 'Face restoration',
        'Size', 'Model', 'Model hash', 'VAE', 'VAE hash',
        'Conditional mask weight', 'Clip skip', 'ENSD',
        'Token merging ratio', 'Token merging ratio hr', 'Init image hash',
        'RNG', 'Tiling', 'Style', 'Eta', 'Sigma churn', 'NGMS',
        'Hires upscale', 'Hires upscaler', 'Hires steps',
        'Hires denoising strength', 'Denoising strength (highres)',
        'Firstpass width', 'Firstpass height', 'Sugar-crush',
        'Version', 'User',
    ];

    /**
     * Known schedule-suffix words A1111 folds into legacy combined sampler
     * names ('DPM++ 2M Karras' => sampler 'DPM++ 2M' + scheduler 'Karras').
     *
     * @var list<string>
     */
    public const SCHEDULE_SUFFIXES = [
        'Karras', 'Exponential', 'Polyexponential', 'SGM Uniform',
        'DDIM', 'SGS', 'Simple', 'Align Your Steps', 'Normal',
    ];

    /**
     * Resolve any accepted label to its canonical spelling.
     */
    public static function canonicalLabel(string $label): string
    {
        return self::LEGACY[$label] ?? $label;
    }

    /**
     * Snake field for a label (canonicalised); null for unmodelled strays.
     */
    public static function fieldFor(string $label): ?string
    {
        return self::FIELDS[self::canonicalLabel($label)] ?? null;
    }

    /**
     * Is this exact label part of the canonical vocabulary?
     */
    public static function isCanonical(string $label): bool
    {
        return isset(self::FIELDS[$label]);
    }
}

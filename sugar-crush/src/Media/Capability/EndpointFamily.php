<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media\Capability;

/**
 * Which API dialect an SD endpoint speaks (plan W1.2, crush_media §10.1).
 *
 * The dialect law from the plan: DETECT, don't assume — but ship
 * IMPLEMENTATION for one family first. Today that is sdapi (A1111 /
 * stable-diffusion-webui API). W0.1's live probe of skynet2:30001 proved
 * SGLang-Diffusion is real, reachable and VIDEO-only (Wan2.2-T2V,
 * is_image_gen false), so it is first-class DETECTABLE here; per Appendix C-1
 * it is deliberately UNIMPLEMENTED in v1 — isImplemented() is the single
 * honest door callers use to refuse wiring it, instead of the enum pretending
 * there is no difference between "we can speak it" and "we can see it".
 *
 * Backed values are the config vocabulary for the (W1.8) `mediaFamily`
 * setting.
 */
enum EndpointFamily: string
{
    /** A1111 stable-diffusion-webui /sdapi/v1 — the implemented dialect. */
    case SdApi = 'sdapi';

    /** OpenAI-compatible /v1/images/generations (+/v1/images/edits). */
    case OpenAiImages = 'openai-images';

    /** SGLang-Diffusion /v1/videos + /model_info — detectable, unimplemented (C-1). */
    case SglangDiffusion = 'sglang-diffusion';

    /** ComfyUI /object_info graph API — detectable, unimplemented. */
    case ComfyUi = 'comfyui';

    /** Caller-declared bespoke dialect; never probed for. */
    case Custom = 'custom';

    /** Nothing matched (or nothing was attempted). */
    case Unknown = 'unknown';

    /**
     * Does crush speak this family end-to-end yet? Only sdapi in v1; widen
     * this door when a generation client for another family lands.
     */
    public function isImplemented(): bool
    {
        return $this === self::SdApi;
    }
}

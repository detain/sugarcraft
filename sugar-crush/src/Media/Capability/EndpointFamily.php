<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media\Capability;

/**
 * Which API dialect an SD endpoint speaks (plan W1.2, crush_media §10.1).
 *
 * The dialect law from the plan: DETECT, don't assume — but ship
 * IMPLEMENTATION for one family first. sdapi (A1111 / stable-diffusion-webui
 * API) was that first family; the W7 adapter extended implementation to
 * sglang-diffusion, whose reality W0.1's live probe of skynet2:30001 proved
 * (Wan2.2-T2V, video-first, image routes present in the openapi schema).
 * isImplemented() remains the single honest door callers use to refuse
 * families we can SEE but not SPEAK — comfyui, openai-images and custom are
 * still on the detectable-unimplemented side of Appendix C-1, and the enum
 * must never pretend there is no difference between "we can speak it" and
 * "we can see it".
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

    /** SGLang-Diffusion /v1/videos + /v1/images + /model_info — implemented (W7 adapter). */
    case SglangDiffusion = 'sglang-diffusion';

    /** ComfyUI /object_info graph API — detectable, unimplemented. */
    case ComfyUi = 'comfyui';

    /** Caller-declared bespoke dialect; never probed for. */
    case Custom = 'custom';

    /** Nothing matched (or nothing was attempted). */
    case Unknown = 'unknown';

    /**
     * Does crush speak this family end-to-end? sdapi since W1, and
     * sglang-diffusion since the W7 adapter (image dialect in Client, video
     * job contract in JobClient); the door widens again when the next
     * generation client lands.
     */
    public function isImplemented(): bool
    {
        return $this === self::SdApi || $this === self::SglangDiffusion;
    }
}

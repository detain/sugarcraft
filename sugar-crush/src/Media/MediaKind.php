<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media;

/**
 * The modality a media request or artifact carries.
 *
 * Part of plan W1.1 (plan_crush_media.md): the DTO layer knows only image and
 * video — the two modalities every targeted backend (A1111 sdapi today,
 * SGLang-Diffusion/Wan video per W0 findings) actually serves. Backed strings
 * double as the config wire vocabulary: the `mediaKinds` provider-setting list
 * (W1.8) serialises to exactly these values, and capability detection maps
 * server-advertised output types onto them.
 *
 * Adding a modality (audio, 3D) later is additive: the enum is the single
 * roster and every `MediaKind::tryFrom` door fails soft on unknown values.
 */
enum MediaKind: string
{
    /** Still raster output (PNG/JPEG bytes or a saved file path). */
    case Image = 'image';

    /** Time-based output (mp4/gif bytes or a saved file path) — SGLang-Diffusion
     *  Wan2.2-T2V is video-only per W0.1 probe of skynet2:30001. */
    case Video = 'video';
}

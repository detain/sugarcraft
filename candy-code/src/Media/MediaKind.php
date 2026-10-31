<?php

declare(strict_types=1);

namespace SugarCraft\Code\Media;

/**
 * The modality a media request or artifact carries.
 *
 * Part of plan W1.1 (plan_crush_media.md): the DTO layer knows image and
 * video — the two GENERATIVE modalities every targeted backend (A1111 sdapi
 * today, SGLang-Diffusion/Wan video per W0 findings) actually serves. Backed
 * strings double as the config wire vocabulary: the `mediaKinds`
 * provider-setting list (W1.8) serialises to exactly those values, and
 * capability detection maps server-advertised output types onto them.
 *
 * `Html` rides the roster for the OTHER half of its job: the enum is also the
 * protocol `MEDIA_REF.kind` vocabulary (`Definitions.php` builds the schema
 * union from `cases()`), and a Canvas artifact projects as it (C-TOOL-6.g).
 * It is not generative ({@see isGenerative()}) — no provider is ever asked to
 * dial out for a page — so every config door refuses it while the projection
 * and fetch surfaces accept it. Widening vocabulary and widening capability
 * stay separate decisions on purpose.
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

    /** A self-contained HTML page saved as a session artifact by the `Canvas`
     *  tool (C-TOOL-6.f); the web client renders it only through the
     *  single-use sandboxed `/canvas/{token}` page, never a terminal. */
    case Html = 'html';

    /**
     * Whether a media backend can be asked to GENERATE this kind. `mediaKinds`
     * config and capability discovery speak only generative kinds; `Html` is
     * artifact vocabulary, and declaring it as a provider output would
     * promise a dial no transport makes.
     */
    public function isGenerative(): bool
    {
        return $this !== self::Html;
    }
}

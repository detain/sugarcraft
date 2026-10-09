<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media\Sd;

/**
 * The sdapi v1 endpoint registry: every path the media client may dial, the
 * twelve-clause legacy-path map, and the reject verdicts for dead names.
 *
 * Doctrine (mystage fact (h), plan W1.4): THE CLIENT MAPS, NEVER GUESSES. A
 * caller — or a copied curl line from a 2022 forum post — handing us a legacy
 * spelling gets either a deterministic rewrite to the live route or an
 * SdException naming the closest registered candidate; nothing is silently
 * passed through to a 404.
 *
 * Provenance: canonical paths verified against the A1111 tree snapshot in
 * plan_crush_media.md Appendix F §1.2 ("verified against this tree", clone
 * @82a973c — the clone itself was absent from disk when this table was cut,
 * see tests/fixtures/sd/clone-routes.txt header). Deliberately NOT exposed,
 * allowlist-by-omission: /train/*, /create/*, /sdapi/v1/server-kill|-restart|
 * -stop, /flush-memory — this scope is read + generate + progress-control, and
 * the closed-set roster in EndpointsTest reddens on any unregistered addition.
 *
 * Glossary note (conflict ledger C-1/Q-10): sdapi `cfg_scale` and the
 * sglang-diffusion / OpenAI-family `guidance_scale` are DISTINCT knobs with
 * the same intent (classifier-free guidance strength); MediaRequest keeps the
 * sdapi wire name and no aliasing happens at this layer.
 */
final class Endpoints
{
    // ---- generation -------------------------------------------------------
    public const TXT2IMG = '/sdapi/v1/txt2img';

    public const IMG2IMG = '/sdapi/v1/img2img';

    public const EXTRA_SINGLE_IMAGE = '/sdapi/v1/extra-single-image';

    public const EXTRA_BATCH_IMAGES = '/sdapi/v1/extra-batch-images';

    public const PNG_INFO = '/sdapi/v1/png-info';

    public const INTERROGATE = '/sdapi/v1/interrogate';

    // ---- progress + control ------------------------------------------------
    public const PROGRESS = '/sdapi/v1/progress';

    public const INTERRUPT = '/sdapi/v1/interrupt';

    public const SKIP = '/sdapi/v1/skip';

    // ---- settings + discovery ----------------------------------------------
    public const OPTIONS = '/sdapi/v1/options';

    public const CMD_FLAGS = '/sdapi/v1/cmd-flags';

    public const MEMORY = '/sdapi/v1/memory';

    public const INTERNAL_PING = '/internal/ping';

    // ---- resource pickers (GET rosters) --------------------------------------
    public const SAMPLERS = '/sdapi/v1/samplers';

    public const SCHEDULERS = '/sdapi/v1/schedulers';

    public const UPSCALERS = '/sdapi/v1/upscalers';

    public const LATENT_UPSCALE_MODES = '/sdapi/v1/latent-upscale-modes';

    public const SD_MODELS = '/sdapi/v1/sd-models';

    public const SD_VAE = '/sdapi/v1/sd-vae';

    public const HYPERNETWORKS = '/sdapi/v1/hypernetworks';

    public const FACE_RESTORERS = '/sdapi/v1/face-restorers';

    public const REALESRGAN_MODELS = '/sdapi/v1/realesrgan-models';

    public const PROMPT_STYLES = '/sdapi/v1/prompt-styles';

    public const EMBEDDINGS = '/sdapi/v1/embeddings';

    public const SCRIPTS = '/sdapi/v1/scripts';

    public const SCRIPT_INFO = '/sdapi/v1/script-info';

    public const EXTENSIONS = '/sdapi/v1/extensions';

    // ---- model management (registered; getters land with the lanes that need them)
    public const REFRESH_CHECKPOINTS = '/sdapi/v1/refresh-checkpoints';

    public const REFRESH_VAE = '/sdapi/v1/refresh-vae';

    public const REFRESH_EMBEDDINGS = '/sdapi/v1/refresh-embeddings';

    public const UNLOAD_CHECKPOINT = '/sdapi/v1/unload-checkpoint';

    public const RELOAD_CHECKPOINT = '/sdapi/v1/reload-checkpoint';

    /**
     * The OpenAI-compatible images projection. NOT served by stock A1111 —
     * it exists so `/v1/images` rewrites to the correct modern OpenAI-family
     * spelling for servers that do speak that dialect (sglang-diffusion,
     * Forge's openai extension). Roster tests exempt it from the sdapi
     * clone-route coverage check by this exact annotation.
     */
    public const OPENAI_IMAGES_GENERATIONS = '/v1/images/generations';

    /**
     * Closed registry of every path resolve() passes through unchanged.
     *
     * @var list<string>
     */
    public const CANONICAL = [
        self::TXT2IMG,
        self::IMG2IMG,
        self::EXTRA_SINGLE_IMAGE,
        self::EXTRA_BATCH_IMAGES,
        self::PNG_INFO,
        self::INTERROGATE,
        self::PROGRESS,
        self::INTERRUPT,
        self::SKIP,
        self::OPTIONS,
        self::CMD_FLAGS,
        self::MEMORY,
        self::INTERNAL_PING,
        self::SAMPLERS,
        self::SCHEDULERS,
        self::UPSCALERS,
        self::LATENT_UPSCALE_MODES,
        self::SD_MODELS,
        self::SD_VAE,
        self::HYPERNETWORKS,
        self::FACE_RESTORERS,
        self::REALESRGAN_MODELS,
        self::PROMPT_STYLES,
        self::EMBEDDINGS,
        self::SCRIPTS,
        self::SCRIPT_INFO,
        self::EXTENSIONS,
        self::REFRESH_CHECKPOINTS,
        self::REFRESH_VAE,
        self::REFRESH_EMBEDDINGS,
        self::UNLOAD_CHECKPOINT,
        self::RELOAD_CHECKPOINT,
    ];

    /**
     * Legacy spellings (pre-2023 sd-webui API era and neighbouring dialects)
     * with a live equivalent. The twelve mystage-(h) clauses arrive as
     * fourteen concrete spellings — the per-tab progress/skip/interrupt
     * clause expands to three.
     *
     * @var array<string, string>
     */
    public const LEGACY_ALIASES = [
        '/sdapi/v1/settings' => self::OPTIONS,
        // '/option/{key}' is the pattern clause — see LEGACY_OPTION_PREFIX.
        '/txt2img/progress' => self::PROGRESS,
        '/txt2img/skip' => self::SKIP,
        '/txt2img/interrupt' => self::INTERRUPT,
        '/tick' => self::INTERNAL_PING,
        '/v1/images' => self::OPENAI_IMAGES_GENERATIONS,
        '/refresh-checkpoints-models' => self::REFRESH_CHECKPOINTS,
        '/mem-usage' => self::MEMORY,
    ];

    /**
     * Legacy spellings whose twelve-clause entry carries NO equivalent: each
     * is refused with the recorded why instead of a nearest-neighbour guess.
     *
     * @var array<string, string>
     */
    public const LEGACY_REJECTIONS = [
        '/sd-metadata.json' => 'ComfyUI-family metadata, not an sdapi route — that dialect is detected via /object_info by CapabilityDiscoverer, and media-scope v1 speaks sdapi only.',
        '/internal/info' => 'Forge-only server introspection; absent from stock A1111 api.py with no stable sdapi v1 equivalent (cmd-flags + options reads are the nearest live surface, not an equivalent).',
        '/v1/embeddings' => 'embeddings belong to the chat-side OpenAI family, outside the media generation scope.',
        '/reload-clips' => 'the CLIP-model reload path was removed from the modern tree; refresh-checkpoints rescans model dirs but is not an equivalent, and we do not guess.',
        '/img2img-grids' => 'no separate grid endpoint exists — the grid is PREPENDED to the generation response (info.index_of_first_image === 1); see Response::generation().',
    ];

    /**
     * The `/option/{key}` pattern clause: any deeper spelling rewrites to the
     * whole-map options GET; the key itself is the caller's to filter (v1 has
     * no single-key read route).
     */
    public const LEGACY_OPTION_PREFIX = '/option/';

    /**
     * Targets that legitimately sit outside the stock-A1111 route snapshot
     * (OpenAI-family projections). EndpointsTest asserts every other
     * LEGACY_ALIASES target is a registered sdapi v1 canonical path.
     *
     * @var list<string>
     */
    public const FAMILY_EXEMPT_TARGETS = [
        self::OPENAI_IMAGES_GENERATIONS,
    ];

    private function __construct()
    {
    }

    /**
     * Resolve a requested path to the canonical route to dial.
     *
     * Live registered paths pass through; the twelve legacy clauses rewrite
     * (or refuse, for the five recorded no-equivalent verdicts); anything
     * unknown fails fast naming the closest registered candidates.
     *
     * @throws SdException on reject/unknown/non-absolute input.
     */
    public static function resolve(string $path): string
    {
        if (!str_starts_with($path, '/')) {
            throw SdException::protocol(
                'Endpoints::resolve',
                "path {$path} is not server-root-relative (must start with /) — refusing to guess a base.",
            );
        }

        if (in_array($path, self::CANONICAL, true)) {
            return $path;
        }

        if (str_starts_with($path, self::LEGACY_OPTION_PREFIX) && strlen($path) > strlen(self::LEGACY_OPTION_PREFIX)) {
            // /option/{key} → whole-map options GET; v1 has no per-key route.
            return self::OPTIONS;
        }

        if (isset(self::LEGACY_ALIASES[$path])) {
            return self::LEGACY_ALIASES[$path];
        }

        if (isset(self::LEGACY_REJECTIONS[$path])) {
            throw SdException::protocol(
                'Endpoints::resolve',
                "legacy path {$path} has no media-scope equivalent: " . self::LEGACY_REJECTIONS[$path],
            );
        }

        throw SdException::protocol(
            'Endpoints::resolve',
            "unknown endpoint {$path} — the client maps, never guesses." . self::closestHint($path),
        );
    }

    /**
     * "did you mean" tail built from the nearest registered names so a typo
     * costs one glance, not a debugging session.
     */
    private static function closestHint(string $path): string
    {
        $candidates = array_merge(self::CANONICAL, array_keys(self::LEGACY_ALIASES), array_keys(self::LEGACY_REJECTIONS));
        $scored = [];
        foreach ($candidates as $candidate) {
            $scored[$candidate] = levenshtein($path, $candidate);
        }
        asort($scored);
        $nearest = array_slice(array_keys($scored), 0, 3);

        return ' closest registered: ' . implode(', ', $nearest) . '.';
    }
}

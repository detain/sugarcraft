<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media;

use SugarCraft\Crush\Media\Sd\Response;
use SugarCraft\Crush\Support\MediaStore;

/**
 * The batch fan-out view of one generation response (plan W4.5): `n_iter ×
 * batch_size` samples plus the optional grid, each paired with ITS OWN raw
 * infotext row and the save-name token map it will be filed under.
 *
 * WHY A CLASS OF ITS OWN. The pairing laws are exactly where an off-by-one
 * silently mislabels every image in a batch, and the tools layer must not
 * re-implement them next to the decoder. {@see Response} applies the laws
 * once when attaching the PARSED infotext and the aligned `all_seeds`
 * companion to each artifact; this class is the sole consumer-side site that
 * re-reads the RAW infotext rows (verbatim re-embed needs the original text,
 * not the parse) and derives filename tokens — one pairing implementation,
 * pinned directly, instead of a second shift-arithmetic copy in the tool.
 *
 * THE INFOTEXT SHIFT LAW (mirrors Response's docblock; R1 MAJOR-1): when the
 * server prepends the grid, it also prepends the grid's infotext at
 * infotexts[0], so sample s's text lives at infotexts[s + 1]; without a grid
 * the lists align unshifted. The grid pairs with infotexts[0].
 *
 * THE INCREMENTAL-SEED LAW (§2.3, plan W4.5 step 1): the server hands back
 * `all_seeds` — the i-th artifact ↔ the i-th seed. Response attaches it as
 * the `seed` token value only when the list lines up with the sample count,
 * so a desynced companion never mislabels an image. Where no aligned seed
 * exists (missing or desynced `all_seeds`), the base seed sent in the request
 * is the documented fallback: an honest echo of what was ASKED, which for
 * `seed: -1` names the file `-1` exactly as the server's own default naming
 * does. With no request seed either, the token stays unfilled and
 * MediaStore's `missing_tokens` note reports it — degraded, but never wrong.
 *
 * THE GRID NAME (plan W4.5): the grid is a first-class artifact saved with a
 * `-grid` suffix on the save pattern ({@see self::gridPattern()}), so it
 * reads as the batch's overview instead of competing with sample names; the
 * `[number]` collision tick stays live inside the suffixed pattern, keeping
 * repeated grids of identical batches unique the same way samples are.
 *
 * PURE: built once from the decoded response plus the request echo; touches
 * no clock, no filesystem, no shared state.
 */
final readonly class BatchResults
{
    /** Entry role: one real generated sample. */
    public const ROLE_SAMPLE = 'sample';

    /** Entry role: the batch grid the server may prepend. */
    public const ROLE_GRID = 'grid';

    /** Filename marker appended to the save pattern for the grid artifact. */
    public const GRID_NAME_SUFFIX = '-grid';

    /**
     * One savable artifact: the bytes-carrying artifact, the verbatim
     * infotext row paired with it (or null when the server sent none), the
     * palette-only save-name tokens, and its role.
     *
     * @phpstan-type Entry array{artifact: MediaArtifact, infotext: ?string, tokens: array<string, mixed>, role: string}
     */
    private function __construct(
        /** @var list<array{artifact: MediaArtifact, infotext: ?string, tokens: array<string, mixed>, role: string}> */
        public readonly array $samples,
        /** @var array{artifact: MediaArtifact, infotext: ?string, tokens: array<string, mixed>, role: string}|null */
        public readonly ?array $grid,
    ) {
    }

    /**
     * Pair every sample (and the grid, when present) with its raw infotext
     * row and filename tokens, honouring the shift and seed laws above.
     */
    public static function fromResponse(Response $decoded, MediaRequest $request): self
    {
        $infotexts = isset($decoded->info['infotexts']) && is_array($decoded->info['infotexts'])
            ? array_values($decoded->info['infotexts'])
            : [];
        $grid = $decoded->grid;
        $shift = $grid !== null ? 1 : 0;

        $samples = [];
        foreach ($decoded->artifacts() as $slot => $artifact) {
            $samples[] = [
                'artifact' => $artifact,
                'infotext' => self::textAt($infotexts, $slot + $shift),
                'tokens' => self::tokensFor($artifact, $request),
                'role' => self::ROLE_SAMPLE,
            ];
        }

        return new self(
            $samples,
            $grid === null ? null : [
                'artifact' => $grid,
                // The grid's own row is infotexts[0] — the very entry the
                // shift law moves every sample off of.
                'infotext' => self::textAt($infotexts, 0),
                'tokens' => self::tokensFor($grid, $request),
                'role' => self::ROLE_GRID,
            ],
        );
    }

    /** Nothing to save or show — the caller reports the empty response. */
    public function isEmpty(): bool
    {
        return $this->samples === [] && $this->grid === null;
    }

    /**
     * The save pattern with the grid marker applied; null takes the store's
     * own default so the two spellings can never drift.
     */
    public static function gridPattern(?string $pattern): string
    {
        return ($pattern ?? MediaStore::DEFAULT_PATTERN) . self::GRID_NAME_SUFFIX;
    }

    /**
     * The artifact's aligned companions (the `seed` from `all_seeds` rides
     * here once Response has checked the list length), filtered to the
     * save-name palette — MediaStore refuses off-palette keys by name, so
     * the off-palette `subseed` companion is dropped here, not at the door.
     * Everything the palette wants that the artifact lacks falls back to the
     * request echo; `model_name` has no v1 producer because `sd_model` is
     * api-owned and refused at the request door, so a request can never name
     * a model to echo.
     *
     * @return array<string, mixed>
     */
    private static function tokensFor(MediaArtifact $artifact, MediaRequest $request): array
    {
        static $palette = null;
        $palette ??= array_flip(MediaStore::TOKENS);

        $tokens = array_intersect_key($artifact->tokenValues(), $palette);

        $fromRequest = [
            'seed' => $request->isSet('seed') ? $request->seed() : null,
            'steps' => $request->isSet('steps') ? $request->steps() : null,
            'cfg' => $request->isSet('cfg_scale') ? $request->cfgScale() : null,
            'sampler' => $request->isSet('sampler_name') ? $request->samplerName() : null,
            'width' => $request->isSet('width') ? $request->width() : null,
            'height' => $request->isSet('height') ? $request->height() : null,
        ];
        foreach ($fromRequest as $token => $value) {
            if (!isset($tokens[$token]) && $value !== null) {
                $tokens[$token] = $value;
            }
        }

        return $tokens;
    }

    /** The infotext row at $index when it is a usable string, else null. */
    private static function textAt(array $infotexts, int $index): ?string
    {
        $raw = $infotexts[$index] ?? null;

        return is_string($raw) && trim($raw) !== '' ? $raw : null;
    }
}

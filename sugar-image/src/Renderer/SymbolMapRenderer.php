<?php

declare(strict_types=1);

namespace SugarCraft\Image\Renderer;

use SugarCraft\Core\Util\ColorUtil;
use SugarCraft\Image\Concerns\OptimizationCarrierTrait;
use SugarCraft\Image\Concerns\RenderValidationTrait;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Lang;
use SugarCraft\Image\Symbol\CanvasMode;
use SugarCraft\Image\Symbol\SymbolDef;
use SugarCraft\Image\Symbol\SymbolMap;
use SugarCraft\Image\Symbol\WideSymbolDef;
use SugarCraft\Image\Support\OutputOptimizer;

/**
 * Coverage-class symbol renderer — the 'symbols' tier (A-04/A-07/A-08/D-03).
 *
 * Resamples the source to an 8x8-pixel grid per terminal cell, derives
 * one fg/bg colour pair per cell from its extreme pixels (chafa
 * work_cell_get_contrasting_color_pair, RGB channels), turns the cell
 * into a binary bitmap by nearest-pen voting
 * (chafa_work_cell_to_bitmap), narrows the corpus with chafa's flat
 * Hamming walk {@see SymbolMap::findCandidates()}, and scores each
 * surviving candidate by the summed squared colour error of painting
 * each pixel with the mean colour of its coverage group (chafa
 * eval_symbol). The inverse trial rides the same candidate buffer to
 * enrich the shortlist (chafa-symbol-renderer.c's eval never consumes the
 * inversion flag — the winner always paints its own coverage, whose group
 * means make the inverted paint an identity on the emitted glyph).
 *
 * Dials:
 *  - work factor: {@see withWorkFactor()} (A-08/D-03) on chafa's config
 *    scale; default 0.5 keeps the top-5 candidate window byte-stable.
 *  - canvas mode: {@see withCanvasMode()} fixed-palette quantize-before-
 *    scoring (A-07/PC-14). Fgbg runs chafa's fg-only discipline: the
 *    terminal's OSC 10/11 default pair (F-08 consumer wire, ctor-
 *    injected) scores every cell, no inversion trials, nothing emitted.
 *
 * Transparency follows the lib-wide binary rule (GD alpha >= 127 reads
 * transparent): a fully-transparent cell renders blank (terminal
 * default on both pens); with a partial cell the transparent pixels are
 * absent from the pair scan and from every group average — never a
 * black paint (A-05a convention). They still occupy their positions in
 * the bitmap vote (background side), which is what keeps the picked
 * glyph honest about the cell geometry.
 *
 * A-09 wide glyphs: when the selection carries a wide array (kana via
 * {@see SymbolMap::withKana()} or a `wide` selector), each row gets a
 * chafa lookback trial after the narrow pass; a winning pair emits the
 * wide lead and zero bytes for the continuation cell (see
 * {@see applyWidePass()}). With an empty wide array — every default,
 * latin and narrow-selector map — the pass is skipped outright and the
 * emitted bytes are identical to the pre-A-09 renderer (byte-freeze).
 *
 * Mirrors chafa.ChafaSymbolRenderer (chafa/internal/chafa-symbol-renderer.c).
 */
final class SymbolMapRenderer implements Renderer, AcceptsOptimization, ConfigFingerprintProvider, CanvasModeConfigurable, WorkFactorConfigurable
{
    use RenderValidationTrait;
    use OptimizationCarrierTrait;

    /** chafa config default work_factor 0.5 → (int)(0.5*10+0.5) = 5 candidate window. */
    public const DEFAULT_WORK_FACTOR = 0.5;

    private readonly SymbolMap $map;

    private CanvasMode $canvasMode = CanvasMode::TrueColor;

    private float $workFactor = self::DEFAULT_WORK_FACTOR;

    /** chafa `--symbols=` selector expression, or null for the default corpus. */
    private ?string $symbolSelector = null;

    /** chafa canvas-init solid_char (chafa-canvas.c:573); see {@see solidChar()}. */
    private bool $solidCharResolved = false;

    private ?string $solidCharCache = null;

    private ?string $defaultFg;

    private ?string $defaultBg;

    /** @internal A-08 op-counter seam; see {@see withCandidateObserver()}. */
    private ?\Closure $candidateObserver = null;

    /**
     * @param ?string $defaultFg OSC 10 default foreground as 'rrggbb' (F-08)
     * @param ?string $defaultBg OSC 11 default background as 'rrggbb' (F-08)
     */
    public function __construct(?SymbolMap $map = null, ?string $defaultFg = null, ?string $defaultBg = null)
    {
        $this->map = $map ?? SymbolMap::new();
        $this->defaultFg = $defaultFg;
        $this->defaultBg = $defaultBg;
    }

    /**
     * chafa's work-factor dial (A-08, D-03): 0..1, clamped. The config
     * float maps to chafa's integer (int)(wf*10 + 0.5); values >= 8 run
     * the exhaustive slow path (every corpus symbol, direct coverage
     * only — chafa-symbol-renderer.c), smaller values narrow the search
     * to the top-{@see candidateLimit()} Hamming candidates (with the
     * inverse trial riding along). Default 0.5 keeps the pre-A-08
     * behaviour byte-identical.
     *
     * Concrete-class dial only: widening the shared Renderer interface
     * would force edits onto renderers outside this lane's ownership
     * (recorded seam).
     */
    public function withWorkFactor(float $workFactor): self
    {
        $clone = clone $this;
        $clone->workFactor = max(0.0, min(1.0, $workFactor));

        return $clone;
    }

    public function workFactor(): float
    {
        return $this->workFactor;
    }

    public function canvasMode(): CanvasMode
    {
        return $this->canvasMode;
    }

    /**
     * F-17/REACH-A04: select the glyph corpus with chafa's `--symbols=`
     * tag grammar ({@see SymbolMap::withSelectors()}). The default corpus
     * (W6's 676 entries) stays byte-frozen; a selector widens the map to
     * the full 1,260-glyph narrow array (1,251 under the BAD-vetoing `all`)
     * or narrows it to one class family. An invalid expression throws at
     * configuration time.
     */
    public function withSymbolSelectors(string $selectors): self
    {
        $clone = new self($this->map->withSelectors($selectors), $this->defaultFg, $this->defaultBg);
        $clone->canvasMode = $this->canvasMode;
        $clone->workFactor = $this->workFactor;
        $clone->candidateObserver = $this->candidateObserver;
        $clone->symbolSelector = $selectors;

        return $clone;
    }

    public function symbolSelector(): ?string
    {
        return $this->symbolSelector;
    }

    /**
     * Only non-default rungs fingerprint: the default (TrueColor, work
     * 0.5) reports '' so existing poster-cache keys never churn.
     */
    public function configFingerprint(): string
    {
        $parts = [];
        if ($this->canvasMode !== CanvasMode::TrueColor) {
            $parts[] = 'canvas=' . $this->canvasMode->value;
        }
        if ($this->workFactor !== self::DEFAULT_WORK_FACTOR) {
            $parts[] = 'work=' . (string) $this->workFactor;
        }
        if ($this->symbolSelector !== null) {
            $parts[] = 'symbols=' . $this->symbolSelector;
        }

        return implode('|', $parts);
    }

    /**
     * A-08 deterministic op-counter seam: called once per scored cell as
     * fn(bool $exhaustive, int $evaluated, string $winner, int $bestError).
     * Timing-fragile pins are banned repo-wide, so the work-factor dial is
     * measured through this observer instead of wall clock.
     */
    public function withCandidateObserver(?\Closure $observer): self
    {
        $clone = clone $this;
        $clone->candidateObserver = $observer;

        return $clone;
    }

    /** The raw chafa integer step: (int)(wf*10+0.5) over the clamped dial. */
    public static function workFactorToInt(float $workFactor): int
    {
        return (int) floor(max(0.0, min(1.0, $workFactor)) * 10 + 0.5);
    }

    /** True when the raw step reaches chafa's exhaustive slow-path flag. */
    public static function isExhaustiveWorkFactor(float $workFactor): bool
    {
        return self::workFactorToInt($workFactor) >= 8;
    }

    /** Candidate window size: the raw step clamped into chafa's 1..8. */
    public static function candidateLimit(float $workFactor): int
    {
        return max(1, min(8, self::workFactorToInt($workFactor)));
    }

    /**
     * Select the fixed-palette canvas mode (A-07, PC-14). TrueColor (the
     * default) reproduces the pre-A-07 byte output exactly; the limited
     * palettes snap the scoring pens BEFORE the candidate error sum —
     * a 16-colour terminal must pick the best glyph FOR THAT PALETTE —
     * and emit the snapped pens (Fgbg emits none, riding terminal
     * defaults). Chafa counterpart: chafa-canvas.c update_cell palette
     * dispatch + use_quantized_error.
     */
    public function withCanvasMode(CanvasMode $mode): self
    {
        $clone = clone $this;
        $clone->canvasMode = $mode;

        return $clone;
    }

    public function render(ImageSource $image, int $width, ?int $height = null): string
    {
        $effectiveHeight = $this->prepareRender($image, $width, $height);

        $img = imagecreatefromstring($image->bytes);
        if ($img === false) {
            throw new \RuntimeException(Lang::t('renderer.gd_load_failed'));
        }
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        imagesavealpha($img, true);

        try {
            $cells = $this->sampleCells($img, $width, $effectiveHeight);
        } finally {
            imagedestroy($img);
        }

        $pen = new OutputOptimizer(repeatCells: $this->emitsRepeatCells());
        $widePass = $this->map->wideDefs() !== [];
        $lines = [];
        foreach ($cells as $row) {
            $painted = [];
            foreach ($row as $cell) {
                $painted[] = $this->paintCell($cell);
            }
            if ($widePass) {
                $this->applyWidePass($painted, $row);
            }
            foreach ($painted as [$glyph, $fg, $bg]) {
                if ($glyph === null) {
                    // A-09 continuation half of a committed wide pair:
                    // chafa's printer emits zero bytes for c == 0.
                    continue;
                }
                $pen->cell($glyph, $this->canvasMode->fgSgr($fg), $this->canvasMode->bgSgr($bg));
            }
            $lines[] = $pen->finishRow();
        }

        return implode("\n", $lines);
    }

    public function name(): string
    {
        return 'symbols';
    }

    public function supportsAlpha(): bool
    {
        return false;
    }

    public function isInline(): bool
    {
        return true;
    }

    /**
     * Symbol-map rendering is plain SGR text — no stored image identity.
     */
    public function delete(string $imageId): string
    {
        return '';
    }

    /**
     * Resample to 8 px per cell and hand back one 64-entry pixel list per
     * cell (position index = y*8+x), each entry [r, g, b, ?int] with
     * alpha null on transparent pixels (lib-wide >= 127 rule).
     *
     * @return list<list<list<array{int,int,int,?int}>>> rows of cells
     */
    private function sampleCells(\GdImage $img, int $cellW, int $cellH): array
    {
        $srcW = imagesx($img);
        $srcH = imagesy($img);
        $canvas = imagecreatetruecolor($cellW * 8, $cellH * 8);
        imagealphablending($canvas, false);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $cellW * 8 - 1, $cellH * 8 - 1, $transparent);
        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $img, 0, 0, 0, 0, $cellW * 8, $cellH * 8, $srcW, $srcH);

        $cells = [];
        for ($cy = 0; $cy < $cellH; $cy++) {
            $row = [];
            for ($cx = 0; $cx < $cellW; $cx++) {
                $pixels = [];
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $rgba = imagecolorat($canvas, $cx * 8 + $x, $cy * 8 + $y);
                        $a = ((int) ($rgba >> 24)) & 0x7F;
                        $pixels[] = [
                            (int) (($rgba >> 16) & 0xFF),
                            (int) (($rgba >> 8) & 0xFF),
                            (int) ($rgba & 0xFF),
                            $a >= 127 ? null : $a,
                        ];
                    }
                }
                $row[] = $pixels;
            }
            $cells[] = $row;
        }
        imagedestroy($canvas);

        return $cells;
    }

    /**
     * Resolve one cell to (glyph, foreground, background, error), or a
     * blank when nothing is opaque. Pens as RGB triples; render() turns
     * them into SGR parameters. The error is the winning symbol's colour
     * error — chafa's update_cell return, the quantity the A-09 wide
     * lookback compares against. A blank or perfect-solid cell is pinned
     * at 0: it carries no error to improve, and the wide pass never
     * trials over it (A-05a discipline, see {@see applyWidePass()}).
     *
     * @param list<array{int,int,int,?int}> $pixels 64 cell pixels
     *
     * @return array{0:string, 1:?array{int,int,int}, 2:?array{int,int,int}, 3:int}
     */
    private function paintCell(array $pixels): array
    {
        if ($this->map->defs() === []) {
            // chafa update_cell's n_symbols == 0 early return: the cell
            // stays blank with a maximal error, which hands every pair
            // over to the wide trial (a `wide`-only selection renders).
            // PHP_INT_MAX/8 mirrors chafa's SYMBOL_ERROR_MAX; the error
            // stays int and far from any overflow on the pair sum.
            return [' ', null, null, \intdiv(PHP_INT_MAX, 8)];
        }

        $opaque = self::opaqueOf($pixels);

        if ($opaque === []) {
            return [' ', null, null, 0];
        }

        $rgb0 = $opaque[0];
        $flat = true;
        foreach ($opaque as $rgb) {
            if ($rgb !== $rgb0) {
                $flat = false;
                break;
            }
        }
        if ($flat) {
            $solid = $this->solidChar();
            if ($solid !== null) {
                // One colour everywhere: the best available solid
                // reproduces it exactly.
                return [$solid, $rgb0, $rgb0, 0];
            }
            // The selection holds no usable solid (chafa's
            // find_best_solid_char returned 0): the flat cell rides the
            // ordinary symbol path below, exactly like chafa's fall-through.
        }

        if ($this->canvasMode === CanvasMode::Fgbg) {
            [$def, $error] = $this->pickFgbg($pixels, $opaque);

            return [$def->char, null, null, $error];
        }

        [$bg, $fg] = $this->contrastingPair($opaque);
        $bitmap = $this->bitmapFromPair($pixels, $bg, $fg);

        [$def, $error] = $this->pick($bitmap, $pixels);

        $pens = $this->scoredPens($pixels, SymbolMap::coverageOf($def->bits));

        return [$def->char, $pens[1], $pens[0], $error];
    }

    /**
     * The opaque RGB triples of a cell, in pixel order — the one place
     * the alpha >= 127 drop is applied (A-05a).
     *
     * @param list<array{int,int,int,?int}> $pixels
     *
     * @return list<array{int,int,int}>
     */
    private static function opaqueOf(array $pixels): array
    {
        $opaque = [];
        foreach ($pixels as $px) {
            if ($px[3] !== null) {
                $opaque[] = [$px[0], $px[1], $px[2]];
            }
        }

        return $opaque;
    }

    /**
     * chafa_color_average_2 (chafa-color.h:97): per 8-bit channel
     * (a>>1)+(b>>1) — truncating pairwise averaging, not rounding; the
     * shared wide pair is built with exactly this arithmetic.
     *
     * @param array{int,int,int} $a
     * @param array{int,int,int} $b
     *
     * @return array{int,int,int}
     */
    private static function average2(array $a, array $b): array
    {
        return [
            ($a[0] >> 1) + ($b[0] >> 1),
            ($a[1] >> 1) + ($b[1] >> 1),
            ($a[2] >> 1) + ($b[2] >> 1),
        ];
    }

    /**
     * A-09: chafa's update_cells_row wide lookback (chafa-symbol-
     * renderer.c:790). After the narrow pass painted the row, walk left
     * to right; every pair whose left cell is not already a wide
     * continuation gets a double-width trial, and it replaces the two
     * narrow cells if and only if the per-half wide errors sum strictly
     * below the two narrow errors (ties keep the narrow pair, chafa's
     * strict &lt;). The replaced right half becomes a continuation —
     * null glyph — which render() never feeds to the output pen, the
     * exact byte shape of the printer's `if (cell->c == 0) continue;`.
     *
     * chafa's four-entry work-cell ring (N_BUF_CELLS) is a memory
     * optimization only: it still trials every adjacent pair and commits
     * at most one per step, which this whole-row port reproduces while
     * holding the row's raw pixels directly.
     *
     * Deviation from the C, recorded (A-05a): cells this port pinned at
     * error 0 — fully transparent (rendered blank) and perfect solids —
     * are never trialled over. Chafa would trial them and could commit a
     * wide lead painting foreground across a transparent half; the
     * lib-wide "transparent pixels are never a paint" rule outranks
     * byte-fidelity to the C here, and it also keeps the printer's
     * fg-only-pass transparent-lead space idiom unreachable.
     *
     * @param list<array{0:?string,1:?array{int,int,int},2:?array{int,int,int},3:int}> $painted narrow cells, modified in place
     * @param list<list<array{int,int,int,?int}>>                                       $row    raw pixels aligned with $painted
     */
    private function applyWidePass(array &$painted, array $row): void
    {
        $n = count($painted);
        for ($cx = 1; $cx < $n; $cx++) {
            if ($painted[$cx - 1][0] === null) {
                continue; // right half of a just-committed pair (chafa's c == 0)
            }
            $narrowA = $painted[$cx - 1][3];
            $narrowB = $painted[$cx][3];
            if ($narrowA === 0 || $narrowB === 0) {
                continue; // blank or perfect solid — see the A-05a note above
            }

            $wide = $this->pickWide($row[$cx - 1], $row[$cx]);
            if ($wide === null) {
                continue;
            }
            [$char, $fg, $bg, $errorA, $errorB] = $wide;
            if ($errorA + $errorB < $narrowA + $narrowB) {
                $painted[$cx - 1] = [$char, $fg, $bg, $errorA];
                $painted[$cx] = [null, $fg, $bg, $errorB];
            }
        }
    }

    /**
     * A-09: chafa's update_cells_wide trial for one adjacent pair
     * (pick_symbol_and_colors_wide_fast / _slow). The two cells share a
     * single colour pair: the per-cell contrasting pairs averaged with
     * chafa_color_average_2 channels — or the terminal default pair
     * under Fgbg, where scoring never re-derives pens (chafa's
     * fg_only_enabled branch in eval_symbol_wide). Both 8x8 bitmaps
     * vote against that shared pair. Candidates come from the wide
     * selection's joint-Hamming walk, or from the whole popcount-sorted
     * wide array once the work step reaches chafa's exhaustive flag.
     *
     * Each candidate paints with per-half mean colours averaged into
     * the shared pair (chafa's eval_symbol_colors_wide calling
     * get_mean_colors_for_symbol per side), snapped through the canvas
     * mode before scoring (PC-14 quantize-before-scoring, exactly the
     * narrow scoredPens idiom), and scores each half against its own
     * coverage. Winner: strictly lowest summed error, earliest wins
     * ties. The inversion flag enriches the shortlist but is never
     * consumed by scoring — chafa's eval ignores it too (class
     * docblock), and chafa's solid-char revert (quantized 16-8 modes
     * turning a wide back into two solids) does not apply: this port
     * emits continuation skips, not palette rewrites.
     *
     * @param list<array{int,int,int,?int}> $pixelsA left cell pixels
     * @param list<array{int,int,int,?int}> $pixelsB right cell pixels
     *
     * @return ?array{0:string,1:?array{int,int,int},2:?array{int,int,int},3:int,4:int}
     *         lead glyph, fg, bg, left error, right error (pens null under Fgbg)
     */
    private function pickWide(array $pixelsA, array $pixelsB): ?array
    {
        $fgOnly = $this->canvasMode === CanvasMode::Fgbg;
        if ($fgOnly) {
            $bg = self::parseHex($this->defaultBg) ?? [0, 0, 0];
            $fg = self::parseHex($this->defaultFg) ?? [255, 255, 255];
        } else {
            $opaqueA = self::opaqueOf($pixelsA);
            $opaqueB = self::opaqueOf($pixelsB);
            if ($opaqueA === [] || $opaqueB === []) {
                return null; // a transparent half is never painted (A-05a)
            }
            $pairA = $this->contrastingPair($opaqueA);
            $pairB = $this->contrastingPair($opaqueB);
            $bg = self::average2($pairA[0], $pairB[0]);
            $fg = self::average2($pairA[1], $pairB[1]);
        }

        $bitmapL = $this->bitmapFromPair($pixelsA, $bg, $fg);
        $bitmapR = $this->bitmapFromPair($pixelsB, $bg, $fg);

        $candidates = self::isExhaustiveWorkFactor($this->workFactor)
            ? array_map(static fn (WideSymbolDef $def): array => [$def, false], $this->map->wideCorpus())
            : $this->map->findWideCandidates($bitmapL, $bitmapR, !$fgOnly, self::candidateLimit($this->workFactor));
        if ($candidates === []) {
            return null;
        }

        $bestChar = null;
        $bestFg = null;
        $bestBg = null;
        $bestErrorA = 0;
        $bestErrorB = 0;
        $bestTotal = PHP_INT_MAX;

        foreach ($candidates as [$def]) {
            $covL = SymbolMap::coverageOf($def->bitsLeft);
            $covR = SymbolMap::coverageOf($def->bitsRight);
            if ($fgOnly) {
                $penFg = $fg;
                $penBg = $bg;
            } else {
                $meanA = $this->meanPens($pixelsA, $covL);
                $meanB = $this->meanPens($pixelsB, $covR);
                $penFg = self::average2($meanA[1], $meanB[1]);
                $penBg = self::average2($meanA[0], $meanB[0]);
                if ($this->canvasMode->quantizes()) {
                    $penFg = $this->canvasMode->quantizeRgb($penFg);
                    $penBg = $this->canvasMode->quantizeRgb($penBg);
                }
            }

            $errorA = self::halfError($pixelsA, $covL, $penBg, $penFg, $bestTotal);
            if ($errorA >= $bestTotal) {
                continue;
            }
            $errorB = self::halfError($pixelsB, $covR, $penBg, $penFg, $bestTotal - $errorA);
            $total = $errorA + $errorB;
            if ($total < $bestTotal) {
                $bestTotal = $total;
                $bestChar = $def->char;
                $bestFg = $fgOnly ? null : $penFg;
                $bestBg = $fgOnly ? null : $penBg;
                $bestErrorA = $errorA;
                $bestErrorB = $errorB;
            }
        }

        if ($bestChar === null) {
            return null;
        }

        return [$bestChar, $bestFg, $bestBg, $bestErrorA, $bestErrorB];
    }

    /**
     * Summed squared colour error of painting one 8x8 half of a wide
     * pair with the shared pens (chafa's eval_symbol_error over the
     * per-half coverage), limited to the pair's opaque pixels by the
     * A-05a convention, bailing as soon as the running sum reaches
     * $budget so doomed candidates never finish.
     *
     * @param list<array{int,int,int,?int}> $pixels 64 cell pixels
     * @param list<int>                     $cov    64 binary coverage bytes
     */
    private static function halfError(array $pixels, array $cov, array $bg, array $fg, int $budget): int
    {
        $error = 0;
        foreach ($pixels as $i => $px) {
            if ($px[3] === null) {
                continue;
            }
            $error += ColorUtil::squaredDistance([$px[0], $px[1], $px[2]], $cov[$i] === 1 ? $fg : $bg);
            if ($error >= $budget) {
                return $error;
            }
        }

        return $error;
    }

    /**
     * chafa's find_best_solid_char (chafa/chafa-canvas.c:335-374), resolved
     * once at canvas init (chafa-canvas.c:573) and memoized here the same
     * way: U+2588 when the selection holds it
     * ({@see SymbolMap::hasSymbol()}); otherwise the nearest corpus symbol
     * to full coverage when it leaves at most 32 pixels unpainted (chafa's
     * hamming_distance <= 32 gate against the all-ones bitmap); otherwise
     * null — chafa's 0 return — and flat cells fall through to the ordinary
     * symbol path. Before F-17/rev-w10-2 M2 this shortcut returned U+2588
     * unguarded, so `--symbols=braille` still painted 449 of 450 flat cells
     * with a glyph the selection had forbidden.
     *
     * Adaptation at the A-05b fill-map seam: chafa consults its separate
     * fill_symbol_map first (has_symbol on both maps, then
     * find_fill_candidates); this port has no fill map, so both fill
     * lookups collapse into the single symbol-map walk below — the
     * candidate bitmap 0xffffffffffffffff arrives as the int -1 in PHP
     * two's complement (findCandidates' popcount is sign-safe).
     */
    private function solidChar(): ?string
    {
        if ($this->solidCharResolved) {
            return $this->solidCharCache;
        }
        $this->solidCharResolved = true;

        if ($this->map->hasSymbol(0x2588)) {
            return $this->solidCharCache = "\u{2588}";
        }

        $candidates = $this->map->findCandidates(-1, false, 8);
        if ($candidates !== [] && $candidates[0][0]->bgWeight() <= 32) {
            return $this->solidCharCache = $candidates[0][0]->char;
        }

        return null;
    }

    /**
     * chafa work_cell_to_bitmap(): a pixel votes 1 when it is strictly
     * closer to the foreground pen than to the background pen;
     * transparent pixels sit on the background side.
     *
     * @param list<array{int,int,int,?int}> $pixels 64 cell pixels
     */
    private function bitmapFromPair(array $pixels, array $bg, array $fg): int
    {
        $bitmap = 0;
        foreach ($pixels as $px) {
            $vote = false;
            if ($px[3] !== null) {
                $rgb = [$px[0], $px[1], $px[2]];
                $vote = ColorUtil::squaredDistance($rgb, $bg) > ColorUtil::squaredDistance($rgb, $fg);
            }
            $bitmap = ($bitmap << 1) | ($vote ? 1 : 0);
        }

        return $bitmap;
    }

    /**
     * chafa get_contrasting_color_pair(): per RGB channel the extreme
     * pixels (min index strict <, max index >=, so a tie moves the max
     * to the later pixel); the widest-ranged channel picks the pair —
     * background = its min pixel, foreground = its max pixel. Chafa
     * also scans alpha as a fourth channel; sugar-image has already
     * dropped transparent pixels by here, so only R, G and B are ranked.
     *
     * @param list<array{int,int,int}> $opaque
     *
     * @return array{0:array{int,int,int}, 1:array{int,int,int}} bg, fg
     */
    private function contrastingPair(array $opaque): array
    {
        $minIndex = $maxIndex = [0, 0, 0];
        $range = [0, 0, 0];
        $n = count($opaque);
        foreach ([0, 1, 2] as $ch) {
            $min = $max = $opaque[0][$ch];
            for ($i = 1; $i < $n; $i++) {
                $v = $opaque[$i][$ch];
                if ($v < $min) {
                    $min = $v;
                    $minIndex[$ch] = $i;
                }
                if ($v >= $max) {
                    $max = $v;
                    $maxIndex[$ch] = $i;
                }
            }
            $range[$ch] = $max - $min;
        }

        $best = 0;
        for ($ch = 1; $ch < 3; $ch++) {
            if ($range[$ch] > $range[$best]) {
                $best = $ch;
            }
        }

        return [$opaque[$minIndex[$best]], $opaque[$maxIndex[$best]]];
    }

    /**
     * chafa pick: the fast path narrows the corpus with the Hamming walk
     * (inverse trial included) and scores the window; the slow path
     * (work step >= 8) scores every symbol with its direct coverage —
     * exactly chafa-symbol-renderer.c's dispatch. Strict < keeps the
     * earliest winner on ties.
     *
     * @param list<array{int,int,int,?int}> $pixels 64 cell pixels
     *
     * @return array{0:SymbolDef, 1:int} winning symbol (its own coverage paints) and its colour error
     */
    private function pick(int $bitmap, array $pixels): array
    {
        $exhaustive = self::isExhaustiveWorkFactor($this->workFactor);
        $candidates = $exhaustive
            ? $this->map->defs()
            : array_map(
                static fn (array $entry): SymbolDef => $entry[0],
                $this->map->findCandidates($bitmap, true, self::candidateLimit($this->workFactor))
            );

        $bestDef = null;
        $bestError = PHP_INT_MAX;

        foreach ($candidates as $def) {
            $cov = SymbolMap::coverageOf($def->bits);
            $pens = $this->scoredPens($pixels, $cov);

            $error = 0;
            foreach ($pixels as $i => $px) {
                if ($px[3] === null) {
                    continue;
                }
                $error += ColorUtil::squaredDistance([$px[0], $px[1], $px[2]], $pens[$cov[$i]]);
                if ($error >= $bestError) {
                    break;
                }
            }

            if ($error < $bestError) {
                $bestError = $error;
                $bestDef = $def;
            }
        }

        // findCandidates yields at least one entry for a non-empty corpus.
        \assert($bestDef !== null);
        if ($this->candidateObserver !== null) {
            ($this->candidateObserver)($exhaustive, count($candidates), $bestDef->char, $bestError);
        }

        return [$bestDef, $bestError];
    }

    /**
     * chafa FGBG discipline: fg_only forces the canvas default pair
     * (OSC 10/11 when probed — F-08), the bitmap votes against THAT
     * pair, every candidate scores with the FIXED pens (chafa's
     * eval_symbol_colors bypass, chafa-symbol-renderer.c:188-220), and
     * consider_inverted is FALSE (chafa-canvas.c:556). Nothing is
     * emitted: the terminal paints both legs with its own defaults.
     *
     * @param list<array{int,int,int,?int}> $pixels 64 cell pixels
     * @param list<array{int,int,int}>      $opaque
     *
     * @return array{0:SymbolDef, 1:int} winning symbol and its fixed-pen colour error
     */
    private function pickFgbg(array $pixels, array $opaque): array
    {
        $bg = self::parseHex($this->defaultBg) ?? [0, 0, 0];
        $fg = self::parseHex($this->defaultFg) ?? [255, 255, 255];
        $bitmap = $this->bitmapFromPair($pixels, $bg, $fg);

        $exhaustive = self::isExhaustiveWorkFactor($this->workFactor);
        $candidates = $exhaustive
            ? $this->map->defs()
            : array_map(
                static fn (array $entry): SymbolDef => $entry[0],
                $this->map->findCandidates($bitmap, false, self::candidateLimit($this->workFactor))
            );

        $bestDef = null;
        $bestError = PHP_INT_MAX;

        foreach ($candidates as $def) {
            $cov = SymbolMap::coverageOf($def->bits);
            $error = 0;
            foreach ($pixels as $i => $px) {
                if ($px[3] === null) {
                    continue;
                }
                $pen = $cov[$i] === 1 ? $fg : $bg;
                $error += ColorUtil::squaredDistance([$px[0], $px[1], $px[2]], $pen);
                if ($error >= $bestError) {
                    break;
                }
            }
            if ($error < $bestError) {
                $bestError = $error;
                $bestDef = $def;
            }
        }

        \assert($bestDef !== null);
        if ($this->candidateObserver !== null) {
            ($this->candidateObserver)($exhaustive, count($candidates), $bestDef->char, $bestError);
        }

        return [$bestDef, $bestError];
    }

    /**
     * 'rrggbb' → RGB triple, null on absent or malformed input.
     *
     * @return ?array{int,int,int}
     */
    private static function parseHex(?string $hex): ?array
    {
        if ($hex === null || preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            return null;
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * chafa work_cell_get_mean_colors_for_symbol(): group the cell's
     * pixels by the (possibly inverted) coverage bytes; each group's
     * average is that pen. Chafa divides by the symbol's static fg/bg
     * weights — over a full 64-pixel opaque cell the actual per-group
     * counts equal those weights, so dividing by the live counts keeps
     * byte-identical means upstream and keeps partial-transparency cells
     * from bleeding black (they divide out the absent pixels instead).
     * A single-pixel group skips the division (weight-<=1 fast path).
     *
     * @param list<array{int,int,int,?int}> $pixels 64 cell pixels
     * @param list<int> $cov 64 binary coverage bytes
     *
     * @return array{0:array{int,int,int}, 1:array{int,int,int}} bg, fg
     */
    /**
     * meanPens() through the canvas mode's snap: under a fixed palette
     * the pens that SCORE a candidate are the pens that would paint it
     * (PC-14 quantize-before-scoring). TrueColor passes through.
     *
     * @param list<array{int,int,int,?int}> $pixels 64 cell pixels
     * @param list<int> $cov 64 binary coverage bytes
     *
     * @return array{0:array{int,int,int}, 1:array{int,int,int}} bg, fg
     */
    private function scoredPens(array $pixels, array $cov): array
    {
        $pens = $this->meanPens($pixels, $cov);
        if ($this->canvasMode->quantizes()) {
            $pens = [$this->canvasMode->quantizeRgb($pens[0]), $this->canvasMode->quantizeRgb($pens[1])];
        }

        return $pens;
    }

    private function meanPens(array $pixels, array $cov): array
    {
        $accums = [[0, 0, 0], [0, 0, 0]];
        $counts = [0, 0];
        foreach ($pixels as $i => $px) {
            if ($px[3] === null) {
                continue;
            }
            $g = $cov[$i];
            $accums[$g][0] += $px[0];
            $accums[$g][1] += $px[1];
            $accums[$g][2] += $px[2];
            $counts[$g]++;
        }

        $pens = [];
        foreach ([0, 1] as $g) {
            $pens[$g] = $counts[$g] > 1
                ? [intdiv($accums[$g][0], $counts[$g]), intdiv($accums[$g][1], $counts[$g]), intdiv($accums[$g][2], $counts[$g])]
                : $accums[$g];
        }

        return $pens;
    }
}

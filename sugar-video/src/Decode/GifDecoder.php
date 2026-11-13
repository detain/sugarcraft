<?php

declare(strict_types=1);

namespace SugarCraft\Video\Decode;

use SugarCraft\Animation\Decoder as AnimationDecoder;
use SugarCraft\Animation\Frame as AnimationFrame;
use SugarCraft\Video\AudioPlayer;
use SugarCraft\Video\Lang;
use SugarCraft\Video\Render\Mode;

/**
 * Decoder implementation that wraps sugar-animation's pure-PHP GIF decoder.
 *
 * Uses AnimationDecoder::decodeBytes() to get a stream of
 * AnimationFrame objects over bytes captured ONCE at open, then converts each
 * frame's cell grid to an RgbFrame:
 *   - AnimationFrame::$cells is list<list<array{0:int,1:int,2:int}|null>>
 *   - null cells become black [0, 0, 0]
 *   - Each row is left-to-right, top-to-bottom scanning
 *   - The decode height is cellsH * $mode->rowsPerCell() so a GIF frame has the
 *     same pixel resolution as FfmpegDecoder for the given mode (HalfBlock packs
 *     2 source rows per cell → cellsH * 2; the 1-row modes → cellsH).
 *
 * @implements Decoder
 */
final class GifDecoder implements Decoder
{
    /**
     * Byte ceiling for the open-time GIF capture, mirroring sugar-image's
     * `ImageSource::MAX_BYTES`: the whole point of holding the bytes
     * is that no LATER read can surprise us with more, so the ceiling must
     * fire at the first (and only) read.
     */
    public const MAX_SOURCE_BYTES = 67_108_864; // 64 MiB

    /**
     * Resident-pixel budget for the whole decoded frame list (M6; cap
     * re-derived by SV-M42 from the post-M40 representation).
     *
     * MEASURED, not imagined (step report quotes the numbers): the upstream
     * AnimationFrame cell roster costs 255 bytes per resident cell-pixel on
     * this PHP (fresh-process deltas: 8 frames of 316×316 = 798,848 cells
     * hold 203.9 MB; the 16×16 series matches the same per-cell slope, so the
     * unit is per-cell, not per-frame overhead). The M42 target is ≤256 MB of
     * decoder residency end to end:
     *
     *   650,000 px × 255 B/px  ≈ 166 MB  cells (this budget)
     *   + 64 MB                 =  MAX_SOURCE_BYTES (the one capture)
     *   + 24 MB                 =  MAX_EMIT_CACHE_BYTES (M40)
     *   ────────────────────────────────
     *   ≈ 254 MB worst case
     *
     * The old 8,000,000 px cap projected to ~2 GiB of the same measured
     * units — a hostile in-cap GIF OOMed the host rather than degrading the
     * terminal, which is the whole reason this budget exists. Affordability
     * was checked against real use, as the row demands: an 80×24 HalfBlock
     * clip costs 3,840 px per frame, so ordinary terminal GIFs keep 169
     * frames (≈28 minutes at 10 fps) of headroom; the inputs the cap now
     * refuses are large-graphics-source animations (a 316×316 grid spends the
     * roster in 6 frames), and the typed refusal names the budget and points
     * at a smaller geometry or shorter clip. A rolling-window forward decode
     * could host those at bounded memory too, but that is a representation
     * redesign, not a ceiling, and the derive showed the CURRENT shape is
     * affordable without it — so the cap ships, documented honestly.
     *
     * The number sits deliberately BELOW sugar-animation's own eager
     * {@see AnimationDecoder::MAX_TOTAL_CELLS} joint gate (2M): for any
     * roster this decoder can be asked to hydrate, the running budget here
     * is what fires, in sugar-video's own advice wording — the sibling's
     * eager refusal only preempts at products so far past the budget that
     * both say “refuse”, and hydrate() translates its wording too.
     */
    private const MAX_TOTAL_FRAME_PIXELS = 650_000;

    /**
     * `st_mode` masks used by {@see readBoundedBytes()} to prove the source
     * is a regular file at every check, mirroring the constants
     * sugar-image's hardened ingress uses (same S_IFmt/S_IFREG pair).
     */
    private const STAT_TYPE_MASK = 0170000;
    private const STAT_REGULAR_BIT = 0100000;

    /**
     * Byte ceiling on the converted-frame emit cache (M40).
     *
     * The resident canonical form stays the indexed cell grid — that is what
     * {@see MAX_TOTAL_FRAME_PIXELS} budgets. This ceiling budgets the DERIVED
     * products (upscaled PNGs mostly, raw grids where no resample ran) so the
     * convert-once cache is honest about what it adds on top: measured against
     * the dominant cost, an ordinary 80x24 graphics clip keeps PNG-compressed
     * frames well inside 23 MiB, and an adversarial one evicts instead of
     * growing. Oldest entries are forfeit past the ceiling, never the newest.
     */
    public const MAX_EMIT_CACHE_BYTES = 24_000_000;

    /**
     * Frames already converted to pixels, keyed by frame index, for the
     * CURRENT decode shape (M40).
     *
     * A conversion is a pure function of (frame index, grid size, presentation
     * box) — the index alone only identifies a frame within one shape, so
     * {@see self::$emitStamp} guards the whole map and any shape change drops
     * it. The cache survives close() and same-shape reopen() exactly like
     * $bytes does, which is what makes a loop restart cost zero re-conversion;
     * it dies with a NEW capture (different source = different truth) or when
     * the byte ceiling evicts the oldest entries.
     *
     * @var array<int,RgbFrame>
     */
    private array $emitCache = [];

    private int $emitCacheBytes = 0;
    private string $emitStamp = '';
    private int $conversions = 0;
    private static ?int $emitCacheCeilingOverride = null;

    /** @var list<AnimationFrame> */
    private array $frames = [];

    private int $frameIndex = 0;
    private int $cellsW = 0;
    private int $cellsH = 0;

    /**
     * The container bytes captured at open. Every decode this object
     * performs — open, re-hydrate, reopen — runs over THIS buffer, never over
     * the caller's path again: a file swapped beneath a live player used to
     * change frame counts and dimensions mid-playback, the exact cross-open
     * TOCTOU sugar-image hardened against. Survives close() by
     * design (bounded by MAX_SOURCE_BYTES, released with the object); a fresh
     * open of a DIFFERENT source replaces it.
     */
    private ?string $bytes = null;

    private string $bytesSource = '';

    /**
     * Total playthroughs AUTHORED by the NETSCAPE2.0 loop extension of
     * the captured bytes (libnsgif normalisation: raw 0 and no-extension both
     * mean infinite = null; raw N means N+1). null while unopened or when the
     * stream is unauthored. Read via {@see loopCount()}; it is pure file
     * state — the sugar-video Player's own loop flag is engine policy and
     * stays independent.
     */
    private ?int $authorLoops = null;

    /**
     * Graphics modes only: the pixel box the frame must be presented at,
     * which is the FULL cellsW·cellPxW × cellsH·cellPxH terminal resolution.
     * 0 in every text mode, where the decode grid is already the frame.
     *
     * It is tracked separately from the decode grid because the two stopped
     * being the same number — see {@see open()} — and
     * {@see \SugarCraft\Video\Render\GraphicsRenderer::render()} recovers the
     * cell footprint by dividing the frame's pixel size by the cell box, so a
     * frame handed over at the decode size would be drawn into a fraction of
     * the cells the player reserved for it.
     */
    private int $targetW = 0;
    private int $targetH = 0;

    /**
     * @param int $cellPxW Pixel width of a terminal cell — graphics modes decode at
     *                     cellsW·cellPxW pixels so the image protocols get full
     *                     resolution rather than one pixel per cell.
     * @param int $cellPxH Pixel height of a terminal cell.
     */
    public function __construct(
        private readonly int $cellPxW = 10,
        private readonly int $cellPxH = 20,
    ) {
    }

    /**
     * @inheritDoc
     *
     * Text modes decode at cellsW·colsPerCell × cellsH·rowsPerCell so GIF output
     * matches FfmpegDecoder per mode (HalfBlock packs 2 source rows per cell →
     * cellsH*2; the 1-row modes → cellsH; $mode === null defaults to HalfBlock).
     * Graphics modes decode at the terminal's full pixel resolution
     * (cellsW·cellPxW × cellsH·cellPxH) so the image protocols get real detail —
     * the raw frame is handed to {@see GraphicsRenderer}, which encodes it once.
     *
     * @see GifDecoder::reopen() for the single-capture note on re-decodes.
     */
    public function open(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0, array $headers = []): void
    {
        // A GIF is decoded from a local file by sugar-animation, not fetched, so HTTP
        // request headers never apply here. They are accepted (interface parity with
        // the ffmpeg path) and dropped — but said so out loud, not silently, so a
        // caller who believed credentials were being sent for a GIF source learns the
        // truth from the log rather than debugging a phantom 200-OK-with-wrong-frames.
        if ($headers !== []) {
            error_log(Lang::t('header.ignored_local_source', [
                'count' => count($headers),
                'source' => AudioPlayer::redactCredentials($source),
            ]));
        }

        // Single-capture law: the source is read EXACTLY ONCE, here, bounded by
        // MAX_SOURCE_BYTES. From this point on every decode in this object's
        // lifetime — including reopen() — runs over $this->bytes, never over
        // $source again, so no later mutation of the file can change what
        // plays. A refusal (missing, non-regular, oversized) is a fail-loud
        // RuntimeException before any grid is computed.
        $this->bytes = self::readBoundedBytes($source);
        $this->bytesSource = $source;

        // A NEW capture invalidates everything the old bytes produced: an
        // index-keyed cache would serve a dead source's pixels (M40). The
        // conversion spy resets here too, so one open() owns one history.
        $this->emitCache = [];
        $this->emitCacheBytes = 0;
        $this->emitStamp = '';
        $this->conversions = 0;

        // Pure-PHP GIF decoding takes no request headers: whatever arrived was
        // logged-and-dropped above, so nothing downstream can forget to.
        $this->hydrate($cellsW, $cellsH, $fps, $mode, $startSec);
    }

    /**
     * Rebuild the frame list from the bytes already captured at open.
     *
     * Split out of {@see open()} so reopen() of the SAME source can refresh
     * the playback state without touching the filesystem again. The
     * header-drop notice belongs to the callers — they still have $source.
     */
    /**
     * m14: non-null hint when the graphics path will silently degrade because
     * this PHP has no image extension; null on a normal GD-capable host.
     */
    public static function textSampled(): ?string
    {
        return function_exists('imagecreatetruecolor') ? null : Lang::t('decoder.text_sampled');
    }

    private function hydrate(int $cellsW, int $cellsH, float $fps, ?Mode $mode, float $startSec): void
    {
        $bytes = $this->bytes;
        if ($bytes === null) {
            throw new \RuntimeException('GIF hydrate called without a captured source — open() must precede it.');
        }

        $this->cellsW = $cellsW;
        $this->cellsH = $cellsH;
        $this->frameIndex = 0;

        // Decode the GIF using sugar-animation's pure-PHP decoder, at the resolution the
        // render mode needs: graphics modes decode at the source's own pixel size so the
        // resident grid is loss-free and the single resample lands at use time;
        // the text modes scale the cell grid by their source-pixels-per-cell packing.
        // The decode runs over the captured bytes, not the path.
        if ($mode?->isGraphics() ?? false) {
            $this->targetW = max(1, $cellsW * $this->cellPxW);
            $this->targetH = max(1, $cellsH * $this->cellPxH);
            [$decodeW, $decodeH] = $this->graphicsDecodeGrid($bytes, $this->targetW, $this->targetH);
        } else {
            $this->targetW = 0;
            $this->targetH = 0;
            // SV-L51: the null-mode fallback is the HalfBlock policy spelled as a
            // mode (DecoderFactory default) — the packing arithmetic lives in Mode.
            $pack = $mode ?? Mode::HalfBlock;
            [$decodeW, $decodeH] = [$cellsW * $pack->colsPerCell(), $cellsH * $pack->rowsPerCell()];
        }

        // Index only identifies a frame WITHIN one decode shape: the same GIF
        // re-hydrated at a different grid or presentation box must not serve
        // the old geometry's cached pixels (M40). A same-shape re-hydrate —
        // the loop-restart reopen — is exactly the case worth serving from
        // cache, so the stamp keeps it and any shape change drops it.
        $stamp = $decodeW . 'x' . $decodeH . '@' . $this->targetW . 'x' . $this->targetH;
        if ($stamp !== $this->emitStamp) {
            $this->emitCache = [];
            $this->emitCacheBytes = 0;
            $this->emitStamp = $stamp;
        }

        // Defensive guard: sugar-animation documents RuntimeException, but an older vendored
        // copy (or a new crafted-header shape) could still leak a raw ValueError
        // from imagecreatetruecolor(). Reel's decoder contract is RuntimeException
        // (see FfmpegDecoder), so translate narrowly instead of letting the
        // engine-level error class escape.
        // RESIDENCY BUDGET: the decoder upstream caps a single frame's grid
        // (graphics path) and the frame roster, but never their PRODUCT.
        // The product is the whole cost: the roster measures 255 bytes per
        // cell-pixel on this PHP (SV-M42 fresh-process deltas), so the
        // re-derived MAX_TOTAL_FRAME_PIXELS keeps even a hostile in-cap GIF
        // inside ~166 MB of cells (total decoder residency ≈ 254 MB with the
        // source capture and emit cache accounted). Consume the generator
        // frame by frame and refuse the moment the running product would
        // pass the budget: at most one frame's allocation beyond the budget
        // is ever made, then nothing over-budget stays resident and the
        // refusal is loud and typed.
        // m14: without ext-gd the graphics path silently degrades to the
        // (much smaller) native-pixel grid upstream. Say so, once per process.
        if ($mode !== null && $mode->isGraphics()) {
            static $gdHintAnnounced = false;
            if (!$gdHintAnnounced && ($hint = self::textSampled()) !== null) {
                $gdHintAnnounced = true;
                error_log($hint);
            }
        }

        $perFrame = max(1, $decodeW * $decodeH);
        $frames = [];
        try {
            foreach (AnimationDecoder::decodeBytes($bytes, $decodeW, $decodeH) as $frame) {
                if (self::exceedsResidentBudget(count($frames), $perFrame)) {
                    throw new \RuntimeException(Lang::t('decoder.gif_budget', [
                        'frame' => (string) (count($frames) + 1),
                        'grid' => (string) $perFrame,
                        'max' => (string) self::MAX_TOTAL_FRAME_PIXELS,
                    ]));
                }
                $frames[] = $frame;
            }
        } catch (\RuntimeException $e) {
            // m13: the upstream grid refusal names its own library and quotes
            // a raw cell count nobody can act on. Keep its sentence as the
            // technical detail but answer as sugar-video advice. The budget
            // refusal raised above passes through untranslated.
            //
            // SV-M42: the sibling's EAGER joint gate (MAX_TOTAL_CELLS) speaks
            // the same refuse about the same product, at call time — before
            // this loop's running budget can reach frame #7 of a roster that
            // will never hydrate anyway. Recognise both wordings so a hostile
            // file always hears sugar-video's advice, never a foreign
            // library's bare sentence, and the accounting story stays one
            // budget with one voice.
            if (!str_contains($e->getMessage(), 'cell grid product exceeds maximum')
                && !str_contains($e->getMessage(), 'total cell budget exceeded')) {
                throw $e;
            }

            throw new \RuntimeException(Lang::t('decoder.gif_grid', [
                'detail' => $e->getMessage(),
            ]));
        } catch (\ValueError $e) {
            throw new \RuntimeException("GIF decode failed: {$e->getMessage()}", 0, $e);
        }
        $this->frames = $frames;

        // The authored loop count, read from the SAME audited bytes as
        // the frames (never a second open — the single-capture law) and re-derived on every
        // hydrate, so open, mid-play re-hydrate and same-source reopen all
        // report the truth of the one capture. loopCount() throws only on a
        // bad signature, which the successful decode above has already ruled
        // out; truncation degrades to "unauthored" inside it, never warns.
        $this->authorLoops = AnimationDecoder::loopCount($bytes);

        // Best-effort time seek: all GIF frames are already in memory, so advance
        // the cursor to the frame at $startSec (clamped). GIF timing is per-frame,
        // so this uses the caller's nominal fps as an approximation.
        //
        // The seek clamp is symmetric. The old upper bound was count(), which put
        // any seek that reached or rounded past the last frame one slot BEYOND it —
        // next() hit EOF at once and the Player's loop wrapped to frame 0, so a
        // late-time seek visibly jumped to START. Landing on the LAST frame is the
        // honest semantics for a seek past the end, and max(0, …) keeps a negative
        // nominal index off the cursor even if the > 0.0 guard above ever moves.
        if ($startSec > 0.0 && $fps > 0.0) {
            $this->frameIndex = max(0, min(count($this->frames) - 1, (int) round($startSec * $fps)));
        }
    }

    /**
     * @inheritDoc
     */
    public function next(): ?RgbFrame
    {
        if ($this->frameIndex >= count($this->frames)) {
            return null;
        }

        $index = $this->frameIndex++;

        // CONVERT-ONCE (M40): the cell→pixel walk (and, on graphics rungs,
        // the resample + PNG encode) is pure work per (frame, shape) — a
        // second pass over the same capture (loop restart, frameAt seek walk)
        // must never pay it again. Served frames are the same instances;
        // RgbFrame is readonly, so sharing is safe.
        $cached = $this->emitCache[$index] ?? null;
        if ($cached !== null) {
            return $cached;
        }

        $frame = $this->animationFrameToRgbFrame($this->frames[$index]);
        $this->conversions++;
        $this->rememberEmitted($index, $frame);

        return $frame;
    }

    /**
     * Keep one converted frame, evicting oldest-first past the byte ceiling.
     *
     * The newest frame is never evicted by itself: a single oversized
     * presentation box (a 4K PNG leg, like a giant single frame under the
     * source cap) may sit alone above the ceiling — every OLDER entry is
     * forfeit while the total is over, so the cache can only be as stale as
     * one frame and playback always keeps its current byte.
     */
    private function rememberEmitted(int $index, RgbFrame $frame): void
    {
        $this->emitCache[$index] = $frame;
        $this->emitCacheBytes += self::emittedSize($frame);

        $ceiling = self::$emitCacheCeilingOverride ?? self::MAX_EMIT_CACHE_BYTES;
        while ($this->emitCacheBytes > $ceiling && count($this->emitCache) > 1) {
            $oldest = array_key_first($this->emitCache);
            $this->emitCacheBytes -= self::emittedSize($this->emitCache[$oldest]);
            unset($this->emitCache[$oldest]);
        }
    }

    /**
     * The bytes one emitted frame really costs the cache: its PNG when the
     * graphics path encoded, its raw grid otherwise — never both, and never
     * an estimate (the ceiling is meant to be honoured, not admired).
     */
    private static function emittedSize(RgbFrame $frame): int
    {
        return strlen($frame->png ?? $frame->bytes);
    }

    /**
     * Conversions this decoder has actually performed (M40).
     *
     * The spy the cache contract is pinned with: a full walk costs one
     * conversion per served frame, and a same-shape re-walk must not move
     * the counter at all.
     */
    public function conversionCount(): int
    {
        return $this->conversions;
    }

    /** Resident bytes currently held by the converted-frame cache (M40). */
    public function emitCacheBytes(): int
    {
        return $this->emitCacheBytes;
    }

    /**
     * Test seam for the emit-cache byte ceiling; null restores
     * {@see MAX_EMIT_CACHE_BYTES}. The ceiling is eviction policy, and
     * policy is only honest if its trip point is unit-testable without
     * shipping a 23 MiB fixture — the project's budget-seam idiom.
     */
    public static function setEmitCacheCeilingForTesting(?int $bytes): void
    {
        self::$emitCacheCeilingOverride = $bytes;
    }

    /**
     * Whether accepting one more resident frame would pass the budget (M6).
     *
     * Pure arithmetic split out so the exact trip point is unit-testable
     * without crafting a GIF that survives upstream's frame coalescing —
     * {@see self::MAX_TOTAL_FRAME_PIXELS} for the measured basis.
     */
    private static function exceedsResidentBudget(int $heldFrames, int $pixelsPerFrame): bool
    {
        return ($heldFrames + 1) * $pixelsPerFrame > self::MAX_TOTAL_FRAME_PIXELS;
    }

    /**
     * The playthrough count authored into the GIF's NETSCAPE2.0 loop
     * extension, normalised by sugar-animation's {@see AnimationDecoder::loopCount()}
     * — null means infinite (no extension present, or the raw-0 "loop forever"
     * value; libnsgif's gif.c:862-871 reading), an integer N >= 2 means N
     * total plays (raw values spell EXTRA repeats, so a total of 1 is not
     * reachable from authored bytes — see {@see playOnce()}). null also answers
     * before any open(); after close() it keeps reporting the last audited
     * capture, because $bytes outlives close() by design.
     *
     * This is FILE STATE, not playback policy: sugar-video's own looping is
     * the Player's engine-side `loop` flag (restart at EOF), which this
     * accessor informs for hosts but does not silently override.
     */
    public function loopCount(): ?int
    {
        return $this->authorLoops;
    }

    /**
     * True only when the bytes explicitly author exactly one
     * playthrough. Unreachable from a valid NETSCAPE extension (raw 0 =
     * forever, raw N > 0 = N+1 totals, so 1 is never produced — libnsgif
     * documents "the only way to play the animation once is not to have this
     * extension at all", gif.c:862-871, and the chicle CLI never consumes
     * the loop field at all, it plays forever); this is the honest answer a
     * host needs to know the difference between "the author said once"
     * (some day, some other container) and "unauthored, play forever by
     * policy".
     */
    public function playOnce(): bool
    {
        return $this->authorLoops === 1;
    }

    /**
     * The grid sugar-animation is actually asked for on the graphics path.
     *
     * THE BUG THIS CLOSES: `php examples/play.php synthetic sixel` died with
     * `sugar-animation: cell grid product exceeds maximum (100000)`. The graphics
     * branch asked for the terminal's full pixel box — 80×24 cells at the
     * default 10×20 cell geometry is 800×480, or 384,000 — and sugar-animation
     * caps a decode at {@see AnimationDecoder::MAX_CELLS}. That cap is not
     * arbitrary: sugar-animation area-averages in PHP, one `imagecolorat()` per
     * source pixel per cell, so 384k cells would have been a multi-second,
     * multi-gigabyte way to fail instead of an instant one.
     *
     * Single-resample design: the grid is the SOURCE'S OWN SIZE, never a
     * box-derived intermediate. The old rule shrank the pixel box by
     * `min(srcW/targetW, srcH/targetH)` — so a 120×60 GIF presented into an
     * 80×24 cell box decoded at 100×60, a 1.2× horizontal squeeze baked in by
     * the PHP area-average — and then {@see upscaleToTarget()} stretched that
     * squeezed grid to 800×480. Two lossy resamples with an invented aspect in
     * the middle is the double-loss this method's successor closes; chicle
     * and chafa never had it because they composite full-canvas RGBA and size
     * output only at print time.
     *
     * Asking for exactly the screen size makes sugar-animation's
     * `sampleCanvas()` a 1:1 pixel copy (every cell's source span collapses
     * to one pixel), so the resident cell grid IS the composited frame — same
     * indexed representation, same MAX_CELLS residency envelope,
     * just no invented aspect. The one and only resample then happens at use
     * time in true-colour space, current frame only, in {@see upscaleToTarget()}.
     *
     * For sources larger than the cap the grid shrinks UNIFORMLY — a
     * `sqrt` fit that preserves the source's own aspect — because the cap is
     * a residency bound, not a presentation choice. An unreadable logical
     * screen descriptor (crafts the decoder's own gates refuse anyway) falls
     * back to the legacy box-derived grid.
     *
     * @return array{0:int,1:int}
     */
    private function graphicsDecodeGrid(string $bytes, int $targetW, int $targetH): array
    {
        // Dimensions come from the captured bytes' logical screen
        // descriptor, not @getimagesize($path) — the path must stay dead after
        // the one open-time capture.
        [$srcW, $srcH] = self::gifScreenDims($bytes);

        if ($srcW > 0 && $srcH > 0) {
            $w = $srcW;
            $h = $srcH;
            if ($w * $h > AnimationDecoder::MAX_CELLS) {
                $scale = sqrt(AnimationDecoder::MAX_CELLS / ($w * $h));
                $w = max(1, (int) floor($w * $scale));
                $h = max(1, (int) floor($h * $scale));
            }

            return [$w, $h];
        }

        $w = $targetW;
        $h = $targetH;

        if ($w * $h > AnimationDecoder::MAX_CELLS) {
            $scale = sqrt(AnimationDecoder::MAX_CELLS / ($w * $h));
            $w = max(1, (int) floor($w * $scale));
            $h = max(1, (int) floor($h * $scale));
        }

        return [$w, $h];
    }

    /**
     * The GIF logical-screen size read straight from the container bytes.
     *
     * Bytes 0-5 are the signature, 6-9 the two little-endian uint16s of the
     * logical screen descriptor. Anything that is not a recognisable GIF87a /
     * GIF89a screen returns [0, 0] — the same "no size known" answer
     * `@getimagesize()` used to give on a non-image, so callers treat it as
     * "skip the source-size shrink" and let the decoder's own gates refuse.
     *
     * @return array{0:int,1:int}
     */
    private static function gifScreenDims(string $bytes): array
    {
        if (strlen($bytes) < 13) {
            return [0, 0];
        }

        $signature = substr($bytes, 0, 6);
        if ($signature !== 'GIF87a' && $signature !== 'GIF89a') {
            return [0, 0];
        }

        $lsd = unpack('vwidth/vheight', substr($bytes, 6, 4));
        if (!is_array($lsd) || !isset($lsd['width'], $lsd['height'])) {
            return [0, 0];
        }

        return [(int) $lsd['width'], (int) $lsd['height']];
    }

    /**
     * Whether $path names an existing file whose first six bytes are the
     * GIF87a/GIF89a signature — container magic, not file extension.
     *
     * Public because {@see DecoderFactory::new()} routes on it: on a host
     * without ffmpeg only a REAL gif can be salvaged by this reader, and
     * anything else must fail with the ffmpeg-missing guidance instead of
     * this class's "not a GIF" blame (M4 — the file may be a perfectly good
     * mp4 that merely has no decoder available here).
     */
    public static function isGifFile(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            $signature = (string) fread($handle, 6);
        } finally {
            fclose($handle);
        }

        return $signature === 'GIF87a' || $signature === 'GIF89a';
    }

    /**
     * Read the whole file, once, under the same hard gates sugar-image's
     * hardening applies: the path is opened exactly one time,
     * the entry must be a regular file at BOTH the stat and the post-open
     * fstat (a swap between the two is a TOCTOU plant).
     *
     * The capture is bounded on TWO axes, and this method enforces only the
     * first. SIZE is bounded by {@see MAX_SOURCE_BYTES}, read with one byte
     * of over-read so a file that yields more than the cap — even one whose
     * stat size understated it (the swap the post-open fstat cannot see if
     * it lands between stat and read) — fails loud rather than being
     * silently truncated to the ceiling. A file exactly AT the cap is
     * ACCEPTED: the gate is `size > cap`, not `>=`. COUNT is bounded
     * separately, downstream: the canonical sugar-animation decoder this
     * buffer feeds truncates the frame stream at MAX_FRAMES (256), so even
     * an in-cap GIF cannot pin an unbounded number of frames in memory.
     *
     * @throws \RuntimeException on missing, non-regular, unreadable, or oversized source
     */
    private static function readBoundedBytes(string $source): string
    {
        $stat = @stat($source);
        if ($stat === false) {
            throw new \RuntimeException(Lang::t('gif.source_unreadable', [
                'path' => AudioPlayer::redactCredentials($source),
                'reason' => 'no such file or directory',
            ]));
        }

        if (($stat['mode'] & self::STAT_TYPE_MASK) !== self::STAT_REGULAR_BIT) {
            throw new \RuntimeException(Lang::t('gif.source_unreadable', [
                'path' => AudioPlayer::redactCredentials($source),
                'reason' => 'not a regular file',
            ]));
        }

        $handle = @fopen($source, 'rb');
        if ($handle === false) {
            throw new \RuntimeException(Lang::t('gif.source_unreadable', [
                'path' => AudioPlayer::redactCredentials($source),
                'reason' => 'unreadable before open',
            ]));
        }

        try {
            $afterOpen = fstat($handle);
            if (!is_array($afterOpen) || (($afterOpen['mode'] & self::STAT_TYPE_MASK) !== self::STAT_REGULAR_BIT)) {
                throw new \RuntimeException(Lang::t('gif.source_unreadable', [
                    'path' => AudioPlayer::redactCredentials($source),
                    'reason' => 'not a regular file after open',
                ]));
            }

            if ((int) $afterOpen['size'] > self::MAX_SOURCE_BYTES) {
                throw new \RuntimeException(Lang::t('gif.source_too_large', [
                    'size' => (int) $afterOpen['size'],
                    'max' => self::MAX_SOURCE_BYTES,
                ]));
            }

            $bytes = stream_get_contents($handle, self::MAX_SOURCE_BYTES + 1);
            if ($bytes === false) {
                throw new \RuntimeException(Lang::t('gif.source_unreadable', [
                    'path' => AudioPlayer::redactCredentials($source),
                    'reason' => 'read failed',
                ]));
            }

            if (strlen($bytes) > self::MAX_SOURCE_BYTES) {
                throw new \RuntimeException(Lang::t('gif.source_too_large', [
                    'size' => strlen($bytes),
                    'max' => self::MAX_SOURCE_BYTES,
                ]));
            }

            return $bytes;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Present a decoded grid at the graphics path's full pixel box, as PNG.
     *
     * {@see graphicsDecodeGrid()} deliberately decodes below the terminal's
     * pixel box, so something has to make up the difference before
     * {@see \SugarCraft\Video\Render\GraphicsRenderer} divides the frame size
     * by the cell box to recover the cell footprint. GD does it: one
     * `imagecopyresampled()` plus one `imagepng()`, both in C.
     *
     * The result rides in `$png` rather than `$bytes` for the reason
     * {@see RgbFrame} gives — the graphics protocols consume a PNG verbatim,
     * and reading 384,000 upscaled pixels back out with `imagecolorat()` just
     * to rebuild an rgb24 buffer nothing on this path reads would reintroduce
     * the per-pixel PHP loop the resample exists to avoid. It is also exactly
     * what {@see FfmpegDecoder} already hands the same renderer.
     *
     * SINGLE-RESAMPLE DESIGN: because the decode grid is
     * the source's own pixel size, the cells arriving here ARE 1:1 composited
     * source pixels, and this method is the one lossy step between container
     * and presentation. The true-colour rebuild + resample below is the
     * transient per-frame RGBA pass: it materializes ONLY the current frame,
     * inside these braces, and every GD allocation is destroyed before
     * returning — the resident form stays the indexed cell grid, so the
     * ban on holding N × W × H × 4 for the playback lifetime is untouched
     * (decode-time capture via {@see AnimationFrame::rgbaBytes()} was ruled
     * out for exactly that reason: hydrate() holds every frame eagerly, bounded only by MAX_TOTAL_FRAME_PIXELS).
     *
     * Filter semantics mirror chafa's DEFAULT canvas scaler: chafa sizes the
     * composited RGBA canvas at print time through smolscale, which picks its
     * filter per-axis from the scale ratio — box (weighted averaging) once
     * src:dest reaches SMOL_BILIN_BOX_CUTOFF=14, bilinear below it
     * (smolscale.c:26-27,972-979). GD's `imagecopyresampled` carries ONE
     * resampling kernel and does NOT switch at a ratio: the match is exact
     * for gentle minifications/magnifications (bilinear territory) and for
     * ratios past the cutoff GD's box-ish averaging approximates chafa's box
     * without reproducing it — an approximation, not an identity, and that is
     * the honest mirror. Nearest exists in chafa only as an explicit option,
     * never as the default — and the player inherits it here as an absence,
     * honestly disclosed.
     */
    private function upscaleToTarget(string $bytes, int $w, int $h): ?RgbFrame
    {
        if (!\extension_loaded('gd')) {
            return null;
        }

        $src = @\imagecreatetruecolor($w, $h);
        if ($src === false) {
            return null;
        }

        try {
            // Row-block import (M40): coalesced GIF cells arrive as long
            // horizontal runs of ONE colour, and every per-pixel GD call is
            // a PHP↔C crossing — the exact tax the resident-grid ban exists
            // to keep small. Scanning runs and painting each with one
            // imagefilledrectangle (GD clips inclusive x1, and a 1-tall rect
            // is the same pixels imagesetpixel would have touched) leaves the
            // canvas byte-identical at a fraction of the call count.
            $offset = 0;
            for ($y = 0; $y < $h; $y++) {
                $x = 0;
                while ($x < $w) {
                    $color = (\ord($bytes[$offset]) << 16) | (\ord($bytes[$offset + 1]) << 8) | \ord($bytes[$offset + 2]);
                    $runStart = $x;
                    do {
                        $x++;
                        $offset += 3;
                    } while ($x < $w
                        && ((\ord($bytes[$offset]) << 16) | (\ord($bytes[$offset + 1]) << 8) | \ord($bytes[$offset + 2])) === $color);
                    \imagefilledrectangle($src, $runStart, $y, $x - 1, $y, $color);
                }
            }

            $dst = @\imagecreatetruecolor($this->targetW, $this->targetH);
            if ($dst === false) {
                return null;
            }

            try {
                \imagecopyresampled($dst, $src, 0, 0, 0, 0, $this->targetW, $this->targetH, $w, $h);

                \ob_start();
                $ok = \imagepng($dst, null, 1);
                $png = (string) \ob_get_clean();
            } finally {
                \imagedestroy($dst);
            }
        } finally {
            \imagedestroy($src);
        }

        if ($ok === false || $png === '') {
            return null;
        }

        return new RgbFrame('', $this->targetW, $this->targetH, $png);
    }

    /**
     * Convert a AnimationFrame to an RgbFrame.
     *
     * @param AnimationFrame $animationFrame
     * @return RgbFrame
     */
    private function animationFrameToRgbFrame(AnimationFrame $animationFrame): RgbFrame
    {
        $cells = $animationFrame->cells;
        $h = count($cells);
        $w = $h > 0 ? count($cells[0]) : 0;

        // Build rgb24 bytes: row-by-row, left-to-right, top-to-bottom
        $bytes = '';
        for ($cy = 0; $cy < $h; $cy++) {
            $row = $cells[$cy] ?? [];
            for ($cx = 0; $cx < $w; $cx++) {
                $cell = $row[$cx] ?? null;
                if ($cell === null) {
                    // Transparent or black
                    $bytes .= "\x00\x00\x00";
                } else {
                    // cell is array{0:int,1:int,2:int} representing R, G, B
                    $bytes .= chr($cell[0]) . chr($cell[1]) . chr($cell[2]);
                }
            }
        }

        // Graphics modes: hand back the terminal's full pixel box, not the
        // (deliberately smaller) grid sugar-animation was asked for. A GD failure
        // degrades to the raw grid rather than killing playback — the image
        // lands small, which is visible and survivable, unlike a fatal.
        if ($this->targetW > 0 && ($w !== $this->targetW || $h !== $this->targetH) && $w > 0 && $h > 0) {
            $scaled = $this->upscaleToTarget($bytes, $w, $h);
            if ($scaled !== null) {
                return $scaled;
            }
        }

        return new RgbFrame($bytes, $w, $h);
    }

    /**
     * @inheritDoc
     */
    public function getIterator(): \Generator
    {
        while (($frame = $this->next()) !== null) {
            yield $frame;
        }
    }

    /**
     * @inheritDoc
     *
     * Drops the decoded frames and rewinds the cursor. The captured bytes are
     * KEPT on purpose: a reopen of the same source must be able to
     * re-hydrate without re-reading the path, and the buffer is already
     * bounded by {@see MAX_SOURCE_BYTES} — it is released when this object
     * dies, or replaced when a DIFFERENT source is opened.
     *
     * The converted-frame cache (M40) survives with them for the same
     * reason and the same discipline: it is bounded by
     * {@see MAX_EMIT_CACHE_BYTES}, it describes the bytes still in hand, and
     * a loop restart that has to re-convert frames it already painted is
     * exactly the per-pixel PHP cost this row exists to retire.
     */
    public function close(): void
    {
        $this->frames = [];
        $this->frameIndex = 0;
    }

    /**
     * @inheritDoc
     *
     * Closes and re-hydrates the decoder with the given parameters.
     *
     * Single-capture ingress: the historical bug was that open()/reopen()
     * re-read the PATH on every call, so a file mutated between open and
     * reopen was re-decoded with different frame counts or dimensions
     * mid-playback — the cross-open TOCTOU sugar-image hardened against. It
     * never landed here. It lands now: the bytes are captured
     * once in {@see open()} and a reopen of the SAME source re-hydrates from
     * that capture without touching the filesystem again. Reopening a
     * DIFFERENT source takes the fresh-capture path (a legit playlist hop).
     */
    public function reopen(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0, array $headers = []): void
    {
        $this->close();

        if ($this->bytes !== null && $source === $this->bytesSource) {
            if ($headers !== []) {
                error_log(Lang::t('header.ignored_local_source', [
                    'count' => count($headers),
                    'source' => AudioPlayer::redactCredentials($source),
                ]));
            }

            $this->hydrate($cellsW, $cellsH, $fps, $mode, $startSec);
            return;
        }

        $this->open($source, $cellsW, $cellsH, $fps, $mode, $startSec, $headers);
    }

    /**
     * False — a seek cannot WALK this decoder's live cursor in place the way
     * an injected double replays from its head; positions are taken by
     * re-hydrating (the seconds route or the index walk both re-open from the
     * captured bytes and land the cursor).
     *
     * It does NOT mean "rebuild me as a new object": since M38 the Player's
     * factory-else branch recognises this class by name and reuses THIS
     * decoder through its same-source reopen() — re-hydrating from the
     * open-time capture with zero filesystem re-read, with the M40 emit cache
     * making the walk free. Only the ffmpeg path (whose rebuild is a respawn
     * anyway) still goes through a fresh DecoderFactory object.
     */
    public function reopensInPlace(): bool
    {
        return false;
    }
}

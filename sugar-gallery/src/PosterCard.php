<?php

declare(strict_types=1);

namespace SugarCraft\Gallery;

use InvalidArgumentException;
use SugarCraft\Core\ImageOverlay;
use SugarCraft\Core\Util\Width;

/**
 * One poster tile: a poster area (a placeholder until its rendered ANSI is
 * attached), a title, and an optional progress bar (e.g. continue-watching).
 *
 * Immutable and rendering-agnostic — the card holds *already-rendered* poster
 * bytes (produced however the app likes, e.g. sugar-image), so this widget
 * pulls in no image decoder. Attach the poster with {@see withPoster()} when an
 * async render resolves (bytes VERBATIM — the caller owns their safety) or
 * {@see withSafePoster()} when it cannot (the guard sweeps every non-SGR
 * control out per line).
 *
 * Title sizing is ANSI-aware (via sugar-core {@see Width}): an optional
 * pre-styled title (e.g. a fuzzy-highlighted one set with {@see withStyledTitle()})
 * keeps its escape sequences and still pads to the correct *visible* cell width.
 */
final readonly class PosterCard
{
    /**
     * md5 of the raw {@see $poster} bytes, computed ONCE at construction
     * (sugar-gallery-014: the fit-memo key used to hash the whole poster — a
     * 24 KB art payload — on every bodyRows() call, i.e. per rendered cell
     * per frame). A readonly instance makes that stored hash content-stable;
     * {@see carrying()} recomputes it exactly when a carrier moves the poster
     * under it, so every ingress (constructor, new(), withPoster(),
     * withSafePoster()) ends up with the same hash for the same bytes.
     */
    private readonly string $posterHash;

    /**
     * @param string|null $styledTitle  Optional ANSI-styled title rendered in place
     *                                   of the plain {@see $title}.
     * @param string|null $posterImage  Raw pixel-graphics bytes (sixel/kitty/iTerm2)
     *                                   for the poster, painted as an out-of-band
     *                                   overlay rather than inline cell text — see
     *                                   {@see withImage()}. Only one fill is drawn:
     *                                   when both are set the overlay wins and the
     *                                   inline {@see $poster} bytes are not painted
     *                                   (pinned by PosterCardTest, because a caller
     *                                   migrating between the two modes can hold
     *                                   both for a frame).
     * @param int|null    $imageId      Overlay id for {@see $posterImage}; the card
     *                                   draws a one-cell {@see ImageOverlay::marker()}
     *                                   at the poster's top-left and the runtime
     *                                   paints the bytes there. Null with an image
     *                                   set means nothing can address the art —
     *                                   an id outside ImageOverlay's paintable
     *                                   range 0..MAX_IMAGES-1 likewise — so
     *                                   the cell renders as a skeleton and
     *                                   {@see hasPoster()} reports it unfilled.
     * @param bool        $failed        Set once the owner's fetch for this
     *                                   cell FAILED: the body then renders a
     *                                   distinct unavailable state (localised
     *                                   line over '?' fill rows) instead of the
     *                                   loading skeleton, so a dead request is
     *                                   not indistinguishable from a slow one.
     *                                   {@see hasPoster()} reports the
     *                                   cell unfilled, keeping it inside the
     *                                   re-queue policy for a retry.
     * @param string      $posterHash    Internal: md5 of $poster, computed by
     *                                   the constructor when a carrier does not
     *                                   supply it — hosts never need to pass it.
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $posterUrl = null,
        public ?float $progress = null,
        public ?string $poster = null,
        public ?string $styledTitle = null,
        public ?string $posterImage = null,
        public ?int $imageId = null,
        public bool $failed = false,
        string $posterHash = '',
    ) {
        $this->posterHash = $posterHash !== '' ? $posterHash : md5($poster ?? '');
    }

    /** Canonical factory — mirrors the public constructor. */
    public static function new(
        string $id,
        string $title,
        ?string $posterUrl = null,
        ?float $progress = null,
        ?string $poster = null,
        ?string $styledTitle = null,
        ?string $posterImage = null,
        ?int $imageId = null,
        bool $failed = false,
    ): self {
        return new self($id, $title, $posterUrl, $progress, $poster, $styledTitle, $posterImage, $imageId, $failed);
    }

    /**
     * Mark the cell's art-fetch as failed (or, passing false, back to loading):
     * the body shows a localised unavailable line over '?' fill rows instead
     * of the loading skeleton, and {@see hasPoster()} reports the cell
     * unfilled so a retry policy still re-queues it. Attaching real art
     * afterwards (withPoster/withImage) does NOT clear the flag — pass
     * withFailed(false) alongside the fill, which is exactly what a
     * retry-resolved callback does.
     */
    public function withFailed(bool $failed = true): self
    {
        return $this->carrying(['failed' => $failed]);
    }

    /**
     * Return a copy with a different plain title (sugar-gallery-010: the
     * library shipped with* carriers for every other field but the host had
     * to hand-spell the whole nine-argument constructor to rename a card).
     * The styled title is a SEPARATE field — see {@see withStyledTitle()};
     * a card rendering a styled title keeps showing it until that one moves.
     */
    public function withTitle(string $title): self
    {
        return $this->carrying(['title' => $title]);
    }

    /** Whether the last fetch for this cell failed (see {@see withFailed()}). */
    public function isFailed(): bool
    {
        return $this->failed;
    }

    /**
     * Attach already-rendered poster bytes VERBATIM. The caller owns their
     * safety — see the class docblock's trust-boundary law and
     * {@see withSafePoster()} for the coercing setter.
     */
    public function withPoster(string $ansi): self
    {
        return $this->carrying(['poster' => $ansi]);
    }

    /**
     * Attach poster bytes after keeping only SGR styling and printable
     * glyphs — the coercing setter at the poster trust boundary, for bytes
     * that arrived from somewhere the caller cannot vouch for (a cache, a
     * server, a disk pipeline). {@see AnsiGuard::sanitize()} drops cursor
     * moves, erases, and every OSC/DCS/APC payload (forged OSC-8 hyperlinks
     * and stray `ESC` bytes alike); a poster rendered by e.g.
     * `Mosaic::halfBlock()` from clean art is returned byte-identical,
     * because half-block glyphs and true-color SGR are exactly what the
     * guard admits.
     *
     * The sweep runs PER LINE: LF is a C0 control the guard drops with
     * everything else unsafe, and a poster is inherently multi-line, so
     * whole-string sanitising would collapse the art into one row. Sweeping
     * each line keeps the row structure (any line ending normalises to
     * `\n`, matching what {@see posterRows()} splits on anyway) while no
     * line can smuggle a control sequence to the terminal.
     *
     * Like {@see withSafeStyledTitle()}, this guards the TEXT channel only.
     * Private-use-area codepoints survive as printable glyphs to the scanner —
     * with one deliberate carve-out (the fleet untrusted-for-marked-frames
     * law): the candy-mouse ZONE sentinels
     * U+E000/U+E001 are swept, because a forged pair reads as zone markup to
     * whatever marks or scans the frame downstream. Image-overlay markers
     * (U+E002 and up) still survive — the same declared boundary sugar-core's
     * Sanitize keeps; an app that composes frames for a marker-consuming
     * renderer sanitises the assembled frame at its own boundary, not here.
     */
    public function withSafePoster(string $ansi): self
    {
        $lines = preg_split('/\r\n|\r|\n/', $ansi) ?: [''];

        return $this->carrying(['poster' => implode("\n", array_map(
            static fn (string $line): string => AnsiGuard::sanitize($line),
            $lines,
        ))]);
    }

    /**
     * Attach a pixel-graphics poster (sixel/kitty/iTerm2 bytes) to be painted as
     * an overlay at $id. Unlike {@see withPoster()} the bytes never enter the
     * text frame — the card renders a marker block reserving the poster area, and
     * the {@see \SugarCraft\Core\Program} paints the image on top. The graphics
     * bytes are stored VERBATIM and bypass the text guard — they are out-of-band
     * payloads, not frame text: the CALLER owns their provenance. An $id
     * outside ImageOverlay's paintable range degrades the cell to an unfilled
     * skeleton (re-queued for a fresh id) instead of throwing.
     */
    public function withImage(string $bytes, int $id): self
    {
        return $this->carrying(['posterImage' => $bytes, 'imageId' => $id]);
    }

    /**
     * Set the download/watch progress fraction, or null to UNSET it (the
     * progress row disappears). NAN/±INF are refused at this ingress
     * (sugar-gallery-035: ±INF read silently as a full bar and NAN even
     * rendered "100 %" downstream — a poisoned float from a remote ratio
     * must name itself, not decorate the card).
     *
     * @throws InvalidArgumentException when $progress is a non-finite float
     */
    public function withProgress(?float $progress): self
    {
        if ($progress !== null && !is_finite($progress)) {
            throw new InvalidArgumentException(sprintf(
                'PosterCard::withProgress() refuses non-finite progress (%s) — pass a finite ratio or null to unset',
                match (true) {
                    is_nan($progress) => 'NAN',
                    $progress > 0 => '+INF',
                    default => '-INF',
                },
            ));
        }

        return $this->carrying(['progress' => $progress]);
    }

    /**
     * Attach a pre-styled (ANSI) title to display in place of the plain
     * {@see $title} — e.g. a fuzzy-highlighted search result. The escapes are
     * preserved and the title cell is padded to the correct visible width. The
     * plain {@see $title} is kept for identity/sort.
     *
     * TRUST BOUNDARY — the styled title is NOT sanitised. Where the plain
     * {@see $title} is run through {@see AnsiGuard::stripControls()} in
     * {@see render()} to
     * neutralise cursor-move / clear-screen / ESC bytes from untrusted DB text,
     * a styled title is emitted verbatim: it is only ever truncated (ANSI-aware,
     * never stripped) — stripping C0 would destroy the very SGR escapes it exists to
     * carry. The CALLER therefore owns that escaping: pass ONLY styling you
     * produced yourself over already-safe text (e.g. a sugar-fuzzy highlight of
     * a sanitised title), NEVER raw untrusted / DB-sourced bytes. When the
     * source is untrusted, leave this unset and rely on the plain
     * {@see $title} — that is the sanitised path.
     *
     * Two opt-ins make the contract enforceable instead of merely documented —
     * both accept SGR styling only, everything else (cursor moves, erase, OSC /
     * DCS payloads, bare controls) counts as unsafe, per {@see AnsiGuard}:
     *
     *  - `$assertSafe: true` fails fast on a byte the widget would have echoed
     *    verbatim, for a call site that claims the bytes are self-produced and
     *    wants the lie caught in development. It throws
     *    {@see \InvalidArgumentException} with the offset + hex of the offender.
     *  - {@see withSafeStyledTitle()} coerces instead of throwing, for a call
     *    site holding bytes it cannot vouch for.
     *
     * Leaving `$assertSafe` false is the historical behaviour, unchanged.
     */
    public function withStyledTitle(string $ansi, bool $assertSafe = false): self
    {
        if ($assertSafe) {
            self::assertSafeAnsi($ansi);
        }

        return $this->carrying(['styledTitle' => $ansi]);
    }

    /**
     * The ONE carrier path every with*-mutator flows through: snapshot the
     * whole current state, apply named overrides, rebuild via the
     * constructor. A `readonly class` forbids writing to a clone, so state
     * re-enters only through `new self(...)` — and routing that through a
     * single choke point means a field can never be silently dropped the way
     * five hand-rolled positional reconstructions could (the snapshot rides
     * {@see get_object_vars()}, so a field added to
     * the constructor flows through automatically and a typo in an override
     * name fails loud).
     *
     * @param array<string, mixed> $overrides constructor parameter name => new value
     *
     * @throws InvalidArgumentException when an override names no constructor field
     */
    private function carrying(array $overrides): self
    {
        foreach (array_keys($overrides) as $name) {
            if (!property_exists($this, (string) $name)) {
                throw new InvalidArgumentException(sprintf(
                    'PosterCard::carrying(): "%s" is not a constructor field of %s.',
                    (string) $name,
                    self::class,
                ));
            }
        }

        return new self(...array_merge(
            get_object_vars($this),
            // A carrier that moves the poster moves its stored hash with it
            // (sugar-gallery-014) — md5 runs once per ingress, never per frame.
            array_key_exists('poster', $overrides)
                ? $overrides + ['posterHash' => md5((string) $overrides['poster'])]
                : $overrides,
        ));
    }

    /**
     * Attach a styled title after dropping everything that is not SGR styling
     * ({@see AnsiGuard::sanitize()}) — the coercing setter of the
     * {@see withStyledTitle()} trust boundary, for a highlight built over text
     * the caller does not control. Colour may be lost; a cursor move never will be.
     *
     * When sanitising leaves nothing to show — empty, or styling and ASCII blank
     * space only — the card keeps its plain {@see $title} (and the receiver is
     * returned unchanged) rather than rendering a blank title row: the plain path
     * is the sanitised one by design. A payload-only input like `"\e[31m\e[2J"`
     * sanitises to a lone colour sequence — non-empty, but carrying no text at
     * all — and is treated the same way.
     *
     * The line is drawn at `trim()`'s whitespace, not at every blank-looking
     * glyph: a non-breaking space is text to {@see AnsiGuard} (it survives the
     * guard and the stripper alike), so styling wrapped around `"\u{a0}"` is kept
     * rather than discarded — a deliberate residue, since a caller who asked for
     * an invisible-but-present run of background colour gets the one they wrote.
     */
    public function withSafeStyledTitle(string $ansi): self
    {
        $safe = AnsiGuard::sanitize($ansi);

        return trim(AnsiGuard::stripControls($safe)) === '' ? $this : $this->withStyledTitle($safe);
    }

    /**
     * Guard for the {@see withStyledTitle()} trust boundary: return $ansi when it
     * carries nothing but SGR styling and printable text, throw otherwise.
     *
     * A convenience delegate to {@see AnsiGuard::assertSafe()} so the widget's own
     * entry point is one call away at the call site that assembles a highlight:
     *
     *     $card->withStyledTitle(PosterCard::assertSafeAnsi($highlight));
     *
     * @throws \InvalidArgumentException when $ansi carries a non-SGR escape or control byte
     */
    public static function assertSafeAnsi(string $ansi): string
    {
        return AnsiGuard::assertSafe($ansi);
    }

    /**
     * Whether this cell holds art the grid can paint — either inline cell text or
     * an overlay fill. The fill policy keys on this, so it must agree with what
     * {@see render()} actually draws: an overlay blob with no id has no marker to
     * paint, renders as a skeleton, and must count as unfilled so the owner re-queues it.
     *
     * Three more shapes are UNFILLED under the same law:
     *  - an empty inline poster — `withPoster('')` is the shape a cache or HTTP
     *    layer leaves behind when it stored nothing, and reading it as "loaded"
     *    would park the cell as a blank filled frame forever, outside the
     *    re-queue policy;
     *  - an overlay id outside {@see ImageOverlay}'s paintable range
     *    0..MAX_IMAGES-1 — one poisoned id (a stale cache, a restored session,
     *    a host minting its own numbering) used to throw from every frame the
     *    grid drew and never re-queue, because {@see hasPoster()} called it
     *    filled;
     *  - a FAILED fetch — the cell shows the unavailable state, not art,
     *    and stays re-queueable for a retry.
     */
    public function hasPoster(): bool
    {
        if ($this->failed) {
            return false;
        }

        return $this->filledPoster() !== null || $this->paintableOverlayId() !== null;
    }

    /**
     * Whether inline poster bytes exist and carry anything — as the poster
     * string itself when filled, null when there is no art yet. The bytes are
     * still stored VERBATIM (a pinned contract of withPoster()) — only the fill
     * predicates treat the empty string as "no art yet".
     */
    private function filledPoster(): ?string
    {
        return $this->poster !== null && $this->poster !== '' ? $this->poster : null;
    }

    /**
     * Whether overlay bytes are present under an id the marker scheme can
     * address — as that id when paintable, null otherwise
     * (see {@see overlayIdIsPaintable()}).
     */
    private function paintableOverlayId(): ?int
    {
        if ($this->posterImage === null || $this->imageId === null) {
            return null;
        }

        return self::overlayIdIsPaintable($this->imageId) ? $this->imageId : null;
    }

    /**
     * Range gate in front of the {@see ImageOverlay::marker()} throw site: ids
     * are the blessed stable per-item index in 0..{@see ImageOverlay::MAX_IMAGES}-1.
     * Out-of-range ids degrade like a null id — skeleton body, unfilled cell —
     * so a single poisoned card can never crash the whole grid frame.
     */
    private static function overlayIdIsPaintable(int $imageId): bool
    {
        return $imageId >= 0 && $imageId < ImageOverlay::MAX_IMAGES;
    }

    /**
     * Render a fixed-width block: poster (or a placeholder) rows, a title row,
     * and a progress row when set. Every row is exactly $width visual cells wide
     * so a rail or grid can stitch cards side by side.
     */
    public function render(bool $focused, int $width, int $posterHeight): string
    {
        $width = max(4, $width);
        $posterHeight = max(1, $posterHeight);

        // Poster/image body rows are memoized (see bodyRows()): they depend only
        // on the card's immutable content and the geometry, never on $focused, so
        // the per-row Width::string() work is done once and reused across frames.
        // Copy-on-write means the append below never mutates the cached array.
        $lines = $this->bodyRows($width, $posterHeight);

        $marker = $focused ? '▸' : ' ';
        // Plain titles are DB-sourced, so they go through the same scanner the
        // styled-title guard uses: every C0/C1 control and any stray escape
        // sequence (SGR included) is removed before the row is drawn. A styled
        // title is the caller's own bytes and takes the ANSI-preserving path —
        // and the SAME budget law: an overflowing styled title shows the ellipsis
        // a plain one gets, so silent truncation never reads as a finished title
        // (sugar-gallery-032: the asymmetry was an affordance lie).
        $title = $this->styledTitle !== null
            ? self::truncateStyled($this->styledTitle, $width - 2)
            : self::truncate(AnsiGuard::stripControls($this->title), $width - 2);
        $lines[] = Width::padRight($marker . ' ' . $title, $width);

        if ($this->progress !== null) {
            $lines[] = Width::padRight(self::progressBar($this->progress, $width), $width);
        }

        return implode("\n", $lines);
    }

    /** Cap on the shared fitted-body memo; the eviction law itself has one
     *  home at {@see RenderMemo::evictIfFull()} (sugar-gallery-046). */
    private const FIT_CACHE_CAP = RenderMemo::CACHE_CAP;

    /**
     * The poster/image/placeholder body rows for this card at the given
     * geometry — the render hot path, memoized process-wide.
     *
     * {@see posterRows()} runs an ANSI-strip + grapheme-cluster
     * {@see Width::string()} pass per row (via {@see fitWidth()}), and a grid or
     * rail re-renders every visible cell every frame with most cells — and every
     * unloaded skeleton — repeating verbatim, so that per-row cost recurs on
     * every frame. Keying the fitted rows by content + geometry turns it into a
     * one-time cost. The key includes the poster bytes' length + hash so a
     * different poster (even one re-encoded CRLF→LF) can never collide onto a
     * stale body; the memo is therefore byte-preserving by construction. Bounded
     * to {@see FIT_CACHE_CAP}, evicting the oldest half when full — the working
     * set is the on-screen cards, which are re-inserted on the next frame.
     *
     * @return list<string>
     */
    private function bodyRows(int $width, int $posterHeight): array
    {
        // A failed cell bypasses the memo entirely: its first line carries
        // LOCALE-resolved text, and the process-wide memo must never freeze a
        // translation against a later T::setLocale(). The rebuild is a
        // str_repeat plus one fit — no per-frame fit cost worth caching.
        $key = $this->bodyKey($width, $posterHeight);
        if ($key === null) {
            return self::failedRows($width, $posterHeight);
        }

        $cache = &self::fitCacheRef();
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $paintableOverlay = $this->paintableOverlayId();
        $posterFilled = $this->filledPoster();
        if ($paintableOverlay !== null) {
            // Overlay mode: reserve the poster area with blank cells and drop a
            // one-cell marker at the top-left; the runtime paints the graphics
            // bytes there on top of the text frame.
            $lines = self::imageRows($paintableOverlay, $width, $posterHeight);
        } elseif ($posterFilled !== null) {
            $lines = self::posterRows($posterFilled, $width, $posterHeight);
        } else {
            $lines = array_fill(0, $posterHeight, str_repeat('░', $width));
        }

        $cache = RenderMemo::evictIfFull($cache);
        $cache[$key] = $lines;

        return $lines;
    }

    /**
     * Identity of the fitted body rows for this card's content at a geometry —
     * exactly the string {@see bodyRows()} keys its memo on, or null for a
     * failed cell (whose first line resolves through `Lang::t()` at draw time
     * and must never be frozen into a content-keyed memo).
     *
     * The key branches, the draw branches and hasPoster() all consult the SAME
     * predicates — paintableOverlayId()/filledPoster() — so a body can never
     * be cached under a branch key its draw disagrees with (the C1/M7 lesson:
     * an unaddressable overlay or empty poster must fall through to the
     * skeleton exactly like hasPoster() reports it).
     */
    private function bodyKey(int $width, int $posterHeight): ?string
    {
        if ($this->failed) {
            return null;
        }

        if ($this->paintableOverlayId() !== null) {
            return 'i|' . $this->imageId . '|' . $width . '|' . $posterHeight;
        }

        $posterFilled = $this->filledPoster();
        if ($posterFilled !== null) {
            // The hash rides the card from ingress (sugar-gallery-014): same
            // bytes, same key — no per-frame md5 of the whole payload.
            return 'p|' . $width . '|' . $posterHeight . '|' . strlen($posterFilled) . '|' . $this->posterHash;
        }

        return 's|' . $width . '|' . $posterHeight;
    }

    /**
     * A content key naming the exact bytes {@see render()} emits at this
     * geometry, or null when the card is a failed cell (see
     * {@see bodyKey()} — a locale-resolved notice line cannot be content-keyed
     * without freezing a translation).
     *
     * Two cards share a key only when their whole render is byte-identical —
     * body rows, focus marker, the title field that feeds the title row, and
     * the progress ratio — so a consumer may memo rendered output by this key
     * instead of hashing the emitted block. That is what kills the grid's
     * second full-art hash (sugar-gallery-047: {@see PosterGrid::box()} re-
     * keyed every frame with an md5 over the finished block while the card
     * already carried the ingress hash from sugar-gallery-014).
     */
    public function renderMemoKey(bool $focused, int $width, int $posterHeight): ?string
    {
        $body = $this->bodyKey(max(4, $width), max(1, $posterHeight));
        if ($body === null) {
            return null;
        }

        // The discriminator names which field feeds the title row: a plain
        // title runs strip-then-truncate while a styled title takes the
        // ANSI-preserving path, so equal characters in different fields must
        // not share a key even when their bytes would.
        $titleField = $this->styledTitle !== null ? 'S' . $this->styledTitle : 'P' . $this->title;

        return $body
            . '|' . ($focused ? 'f' : '-')
            . '|t' . md5($titleField)
            . ($this->progress !== null ? '|p' . $this->progress : '');
    }

    /**
     * Reference to the process-wide fitted-body memo. A `readonly class` forbids
     * mutable static *properties*, so the shared array lives in a by-reference
     * method-static instead — semantically one process-wide cache, just the only
     * form PHP permits mutable shared state to take inside a readonly class.
     *
     * @return array<string, list<string>>
     */
    private static function &fitCacheRef(): array
    {
        static $cache = [];

        return $cache;
    }

    /**
     * Drop the shared fitted-body memo, returning how many entries were cleared.
     * Rendering populates it lazily and it is self-bounding ({@see FIT_CACHE_CAP});
     * a long-lived program that churns through many distinct posters can call
     * this to reclaim the memory eagerly (e.g. on a result-set reset). Purely an
     * optimization — clearing only costs the next render its fit work again and
     * never changes a single output byte.
     */
    public static function clearFitCache(): int
    {
        $cache = &self::fitCacheRef();
        $count = count($cache);
        $cache = [];

        return $count;
    }

    /**
     * The unavailable-state body rows for a failed cell: a localised
     * notice on the first row, neutral-width '?' fill on the rest — visually
     * distinct from both the loading skeleton ('░') and blank overlay
     * reservations, and never memoized so the locale stays live.
     *
     * @return list<string>
     */
    private static function failedRows(int $width, int $posterHeight): array
    {
        $lines = [self::fitWidth(Lang::t('card.failed'), $width)];
        for ($i = 1; $i < $posterHeight; $i++) {
            $lines[] = str_repeat('?', $width);
        }

        return $lines;
    }

    /**
     * Build the $posterHeight-row marker block for an overlay image: the
     * top-left cell is a one-cell {@see ImageOverlay::marker()} and every other
     * cell is a blank space, so the box reserves exactly $width × $posterHeight
     * cells for the runtime to paint the graphics bytes into.
     *
     * @return list<string>
     */
    private static function imageRows(int $imageId, int $width, int $posterHeight): array
    {
        $rows = [ImageOverlay::marker($imageId) . str_repeat(' ', $width - 1)];
        for ($i = 1; $i < $posterHeight; $i++) {
            $rows[] = str_repeat(' ', $width);
        }

        // Safety net: route through fitWidth() so imageRows and posterRows are
        // handled identically. The rows are already width-exact so this is no-op.
        return array_map(static fn (string $row): string => self::fitWidth($row, $width), $rows);
    }

    /**
     * Normalise raw poster bytes into exactly $posterHeight rows.
     *
     * Splits on any line ending (CRLF / CR / LF) so a poster encoded with a
     * separator other than "\n" — e.g. a stale cache entry written before an
     * upstream renderer fix — never leaves a stray carriage return mid-row.
     * The row count is then clamped to $posterHeight: a short poster is padded
     * with blank (width-wide) rows and a tall one is cropped, so the tile always
     * occupies the height the rail/grid reserved for it and cards stay aligned.
     * Every row is then normalised to exactly $width cells (clamp/pad) so a
     * rail or grid can stitch cards side-by-side without width misalignment.
     *
     * @return list<string>
     */
    private static function posterRows(string $poster, int $width, int $posterHeight): array
    {
        $rows = preg_split('/\r\n|\r|\n/', $poster);
        if ($rows === false) {
            // Fail CLOSED: harmonised with withSafePoster()'s `?: ['']` —
            // a PCRE internal failure must not wave unsplit raw bytes at the
            // terminal. The padding loop below renders the reserved area blank.
            $rows = [];
        }

        if (count($rows) > $posterHeight) {
            $rows = array_slice($rows, 0, $posterHeight);
        }

        $rowCount = count($rows);
        while ($rowCount < $posterHeight) {
            $rows[] = str_repeat(' ', $width);
            $rowCount++;
        }

        // Width-normalise every row so the card upholds the render invariant:
        // every card row is exactly $width visual cells, matching PosterGrid::box().
        return array_map(static fn (string $row): string => self::fitWidth($row, $width), $rows);
    }

    /**
     * Normalise a row to exactly $width visual cells: truncate over-wide rows
     * and pad under-wide rows. Mirrors the per-line logic in PosterGrid::box().
     */
    private static function fitWidth(string $row, int $width): string
    {
        $w = Width::string($row);

        return $w > $width
            ? Width::truncateAnsi($row, $width)
            : ($w < $width ? Width::padRight($row, $width) : $row);
    }

    /**
     * Truncate a plain title to $max visible cells, appending an ellipsis when
     * it overflows. ANSI-aware via {@see Width} so wide (CJK) titles count
     * correctly; styled titles take the ANSI-preserving {@see Width::truncateAnsi}
     * path in {@see render()} instead.
     */
    private static function truncate(string $text, int $max): string
    {
        $max = max(1, $max);

        return Width::string($text) <= $max ? $text : Width::truncate($text, max(0, $max - 1)) . '…';
    }

    /**
     * The ANSI-preserving twin of {@see self::truncate()} (sugar-gallery-032):
     * same budget, same trailing '…' on overflow — a caller who styled the
     * title must not lose the visual signal the plain path gives for free.
     * Width::truncateAnsi counts cells (not escape bytes), so the ellipsis
     * lands after the last surviving cell.
     */
    private static function truncateStyled(string $styled, int $max): string
    {
        $max = max(1, $max);

        return Width::string($styled) <= $max ? $styled : Width::truncateAnsi($styled, max(0, $max - 1)) . '…';
    }

    /**
     * A clamped 0..1 bar. When the row can carry it, the exact percentage is
     * spelled out as a trailing label inside the reserved width and the bar
     * shrinks to match — the glyph run alone saturates at both clamped ends
     * (every ▓ is a full bar, every ░ an empty one) and rounds coarse at
     * typical widths, so '(60%)' tells the user what the blocks only suggest.
     * Too narrow for a bar beside the label, the label is dropped and
     * the bar takes the full width as before.
     */
    private static function progressBar(float $progress, int $width): string
    {
        $progress = max(0.0, min(1.0, $progress));
        $label = ' (' . (int) round($progress * 100) . '%)';
        $barWidth = $width - Width::string($label);

        if ($barWidth < 2) {
            $barWidth = $width;
            $label = '';
        }

        $filled = (int) round($progress * $barWidth);

        return str_repeat('▓', $filled) . str_repeat('░', $barWidth - $filled) . $label;
    }
}

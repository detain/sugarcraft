<?php

declare(strict_types=1);

namespace SugarCraft\Overlay;

use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Hyperlink;
use SugarCraft\CellBuffer\Style as BufferStyle;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Mouse\Zone;
use SugarCraft\Style\Border;
use SugarCraft\Style\Style;
use SugarCraft\Overlay\Animation\AnimationKind;
use SugarCraft\Overlay\Animation\Fade;
use SugarCraft\Overlay\Animation\Scale;
use SugarCraft\Overlay\Animation\Slide;

/**
 * Terminal overlay compositor.
 *
 * Composites a foreground string over a background string at a given
 * position with optional cell offsets (columns / rows). Supports backdrop dimming
 * and animated transitions via sugar-spring CubicBezier easing.
 *
 * Mirrors the compositor semantics of rmhubbert/bubbletea-overlay.
 *
 * @see https://github.com/rmhubbert/bubbletea-overlay
 */
final class Veil
{
    /** @var int Backdrop opacity 0–100 (0 = no dimming, 100 = fully dimmed) */
    private readonly int $backdropOpacity;

    /** @var AnimationKind|Fade|Scale|Slide|null Animation driver: a preset kind or a carried instance (with custom easing) */
    private readonly AnimationKind|Fade|Scale|Slide|null $animation;

    /** @var int Stacking order — higher renders on top of lower */
    private readonly int $zIndex;

    /** @var bool Dismiss veil when mouse click lands outside its zone */
    private readonly bool $clickOutsideDismiss;

    /** @var bool Deprecated no-op — dimensions are ALWAYS measured from content */
    private readonly bool $autoSize;

    /** @var Border|null Border chrome to wrap content with */
    private readonly ?Border $border;

    /** @var Scanner Self-contained mouse hit-testing scanner (always present) */
    private readonly Scanner $scanner;

    /** @var string|null Last rendered output — feed to scanner via scan() before hit-testing */
    private readonly ?string $lastRendered;

    /** @var Mark Zone marker helper */
    private readonly Mark $marker;

    /** @var bool Reduced-motion: animate() treats every progress as 1.0 (no intermediate frames) */
    private readonly bool $reducedMotion;

    /** @var RenderSession Mutable diff/session state shared across with*() clones */
    private readonly RenderSession $session;

    /**
     * Lazily-built frame→buffer factory for the diff session. Allocated
     * once per instance instead of once per emitted frame; it reads no
     * instance state beyond the private method reference, so sharing it
     * across with*() clones is byte-equivalent.
     */
    private ?\Closure $bufferFactory = null;

    /** @var Position|null Vertical position anchor for per-veil positioning */
    private readonly ?Position $vPosition;

    /** @var Position|null Horizontal position anchor for per-veil positioning */
    private readonly ?Position $hPosition;

    /** @var int X offset in columns */
    private readonly int $posX;

    /** @var int Y offset in rows */
    private readonly int $posY;

    /** @var string Overlay content this veil paints when driven by a VeilStack (empty = backdrop-only layer) */
    private readonly string $content;

    /**
     * @param int             $backdropOpacity 0–100 backdrop dimming
     * @param AnimationKind|Fade|Scale|Slide|null $animation Animation driver for transitions
     * @param int $zIndex Stacking order
     * @param bool $clickOutsideDismiss Dismiss on outside click
     * @param bool $autoSize Deprecated no-op (dimensions always come from content)
     * @param Border|null $border Border chrome
     * @param Scanner|null $scanner Self-contained mouse hit-testing scanner
     * @param string|null $lastRendered Last rendered output for scanner
     * @param Mark|null $marker Zone marker helper (null = enabled Mark)
     * @param bool $reducedMotion Snap animate() progress to 1.0
     * @param RenderSession|null $session Mutable diff/session state
     * @param Position|null $vPosition Vertical position anchor
     * @param Position|null $hPosition Horizontal position anchor
     * @param int $posX X offset in columns
     * @param int $posY Y offset in rows
     * @param string $content Overlay content for stack-driven compositing
     */
    private function __construct(
        int $backdropOpacity = 0,
        AnimationKind|Fade|Scale|Slide|null $animation = null,
        int $zIndex = 0,
        bool $clickOutsideDismiss = false,
        bool $autoSize = false,
        ?Border $border = null,
        ?Scanner $scanner = null,
        ?string $lastRendered = null,
        ?Mark $marker = null,
        ?RenderSession $session = null,
        ?Position $vPosition = null,
        ?Position $hPosition = null,
        int $posX = 0,
        int $posY = 0,
        string $content = '',
        bool $reducedMotion = false,
    ) {
        $this->backdropOpacity = \max(0, \min(100, $backdropOpacity));
        $this->animation = $animation;
        $this->zIndex = $zIndex;
        $this->clickOutsideDismiss = $clickOutsideDismiss;
        $this->autoSize = $autoSize;
        $this->border = $border;
        $this->scanner = $scanner ?? Scanner::new();
        $this->lastRendered = $lastRendered;
        $this->marker = $marker ?? new Mark();
        $this->reducedMotion = $reducedMotion;
        $this->session = $session ?? new RenderSession();
        $this->vPosition = $vPosition;
        $this->hPosition = $hPosition;
        $this->posX = $posX;
        $this->posY = $posY;
        $this->content = $content;
    }

    /**
     * Create a new Veil instance.
     */
    public static function new(): self
    {
        return new self();
    }

    /**
     * The modal preset: dim the backdrop, then centre the content over it.
     *
     * Every production modal spells the same thing — a 50% backdrop and the
     * CENTER/CENTER anchor pair (upstream's Bubble Tea modal recipe). This
     * delegates to exactly that call, byte for byte; it is shorthand, never
     * a second code path.
     */
    public static function centered(
        string $content,
        string $backdrop,
        int $dimmingPercent = 50,
        int $xOffset = 0,
        int $yOffset = 0,
    ): string {
        return self::new()
            ->withBackdrop($dimmingPercent)
            ->composite($content, $backdrop, Position::CENTER, Position::CENTER, $xOffset, $yOffset);
    }

    /**
     * Set the backdrop opacity for dimming the background.
     *
     * @param int $opacity 0–100 (0 = no dimming, 100 = fully dimmed)
     */
    public function withBackdrop(int $opacity): self
    {
        return $this->mutate(backdropOpacity: $opacity);
    }

    /**
     * Set the animation for overlay transitions.
     *
     * Pass an {@see AnimationKind} preset (the default curve per kind) or
     * a configured instance — `Fade::new(CubicBezier::easeIn())`,
     * `Slide::new($curve)`, `Scale::new($curve)` — to carry custom easing
     * through animate(): the SAME instance drives the content and the
     * scrim ramp (one eased progress, no contrast oscillation, and no
     * second manual apply()+composite() path). Passing null clears the
     * animation (composite runs un-animated).
     */
    public function withAnimation(AnimationKind|Fade|Scale|Slide|null $animation): self
    {
        return $this->mutate(animation: $animation, animationSet: true);
    }

    /** Read-only accessor for the animation driver (preset kind or instance). */
    public function animation(): AnimationKind|Fade|Scale|Slide|null
    {
        return $this->animation;
    }

    /** Read-only accessor for z-index. */
    public function zIndex(): int
    {
        return $this->zIndex;
    }

    /** Read-only accessor for click-outside-dismiss flag. */
    public function clickOutsideDismiss(): bool
    {
        return $this->clickOutsideDismiss;
    }

    /**
     * Read-only accessor for the auto-size flag.
     *
     * @deprecated The flag is a no-op: composite() always measures the
     *             foreground (border chrome included) to decide its own
     *             dimensions — there is no fixed width/height mode to opt
     *             out of. Kept for source compatibility only.
     */
    public function autoSize(): bool
    {
        return $this->autoSize;
    }

    /** Read-only accessor for border chrome. */
    public function border(): ?Border
    {
        return $this->border;
    }

    /**
     * Set the z-index for stacking order.
     *
     * Veils with higher z-index render on top of those with lower z-index.
     * When rendering a stack, sort by z-index ascending and composite in order.
     */
    public function withZIndex(int $zIndex): self
    {
        return $this->mutate(zIndex: $zIndex);
    }

    /**
     * Set the click-outside-dismiss flag.
     *
     * When true, clicking outside the veil's zone will dismiss it.
     * Uses the self-contained sugar-mouse Scanner for hit testing.
     */
    public function withClickOutsideDismiss(bool $enabled = true): self
    {
        return $this->mutate(clickOutsideDismiss: $enabled);
    }

    /**
     * Set the auto-size flag.
     *
     * @deprecated The flag is a no-op. Border chrome is applied by
     *             composite() unconditionally (see withBorder()) and the
     *             overlay is always sized from its content — sugar-overlay
     *             has no fixed-width/height mode. Retained so pre-existing
     *             call sites keep compiling.
     */
    public function withAutoSize(bool $enabled = true): self
    {
        return $this->mutate(autoSize: $enabled);
    }

    /**
     * Set the border chrome for wrapping veil content, or clear it with null.
     *
     * Uses sugar-style Border + Style to wrap the content with a terminal
     * border. The chrome is applied by composite() whenever a border is
     * set — unconditionally, independent of the (deprecated, no-op)
     * autoSize flag — and the overlay is then positioned and diffed as the
     * bordered block, so anchoring accounts for the border's own cells.
     */
    public function withBorder(?Border $border): self
    {
        return $this->mutate(border: $border, borderSet: true);
    }

    /**
     * Set the vertical and horizontal position anchors for this veil.
     *
     * When set on a Veil in a VeilStack, compositeAll() will use these
     * positions instead of defaulting to CENTER/CENTER.
     *
     * Passing null for an axis CLEARS that anchor (the veil falls back to
     * the stack default on that axis); offsets always reset to the given
     * values, so withPosition(null, null) restores full defaults.
     *
     * @param Position|null $vertical Vertical anchor; TOP-family pins to the first row, BOTTOM-family to the last, others center (any of the 9 cases accepted — only its row component is read on this axis); null clears
     * @param Position|null $horizontal Horizontal anchor; LEFT-family pins to column 0, RIGHT-family to the last column, others center (column component read, row component ignored); null clears
     * @param int $x Additional columns rightward (+) / leftward (-)
     * @param int $y Additional lines downward (+) / upward (-)
     */
    public function withPosition(?Position $vertical, ?Position $horizontal, int $x = 0, int $y = 0): self
    {
        return $this->mutate(
            vPosition: $vertical,
            vPositionSet: true,
            hPosition: $horizontal,
            hPositionSet: true,
            posX: $x,
            posY: $y,
        );
    }

    /**
     * Attach the overlay content this veil paints when a VeilStack drives
     * it. A veil WITHOUT content acts as a backdrop-only layer: the stack
     * pass dims (or passes through) the canvas beneath it and writes no
     * foreground of its own.
     *
     * TRUST BOUNDARY: content is passed through VERBATIM, and the
     * compositor is marker-sensitive — U+E000/U+E001 zone sentinels become
     * real hit-test zones and OSC 8 hyperlinks become live links in the
     * rendered frame. Content from anything you do not control (network,
     * transcripts, other processes) must arrive swept: pass it through
     * Sanitize::untrustedForMarkedFrames() (or use
     * withUntrustedContent()) rather than handing it to this method raw.
     * Sweeping is caller-side, not automatic, because on this raw channel
     * a sentinel inside your string is indistinguishable from your own
     * intended mark() zone. The sweep is also total: it strips the ENTIRE
     * U+E000/U+E001 arena, legitimate zones included — so it must run on
     * the raw payload BEFORE mark() wraps it, never after (see
     * withUntrustedContent(), which sweeps on arrival and lets zones be
     * composed afterwards).
     *
     * The verbatim path carries C0 controls as well: a raw TAB ships into
     * the frame painted at the terminal's next 8-column stop while Width
     * charges it 4 cells, and a raw CR measures 0 yet repaints column 0 —
     * either one desyncs delta frames. Only withUntrustedContent() folds
     * them (CR to LF, TAB to four spaces). Physical tab-stop accounting is
     * deliberately NOT modelled on this channel: Width's fixed-4 is the
     * fleet measure, and the 8-stop truth is sugar-core Width's problem,
     * not one consumer's.
     *
     * Replayed TERMINAL output is in scope on the SGR side: the pen parser
     * is a full ECMA-48 fold — colon sub-parameter colors (`38:2:1:2:3`
     * and the color-space form `38:2::1:2:3`) arm a pen exactly like the
     * semicolon spelling — so attacker-SGR reaches the grid on purpose (a
     * painted cell must match what the emitting emulator showed). The
     * defense for a line left mid-style is the splice isolation and the dim
     * wrap at the compositor seam, not a stripped pen.
     *
     * @see withUntrustedContent()
     * @see mark()
     */
    public function withContent(string $content): self
    {
        return $this->mutate(content: $content);
    }

    /**
     * withContent() for text you do NOT trust: before attaching, the sweep
     * applies Sanitize::untrustedForDisplay() (SGR/OSC/C1 controls marked
     * invisible, CRLF and lone CR folded to LF) plus stripZoneSentinels()
     * (forged U+E000/U+E001 zone sentinels removed), then folds TAB to
     * four spaces — the fixed cost Width itself charges a tab, NOT the
     * cell the terminal moves to (a surviving TAB advances to the next
     * 8-column stop). The fold keeps the compositor's width accounting
     * from being poisoned by a byte whose painted width differs from
     * the one Width charges. Image markers (U+E002 and up)
     * are preserved by design.
     *
     * This is the sugar-core house law: untrusted text enters render
     * pipelines only through the sanitizer, never through the raw channel.
     * Content attached here can no longer mint phantom hit-zones, hidden
     * hyperlinks, or width-desyncing carriage returns; the veil's own
     * mark() calls remain effective because they wrap AFTER this point
     * (mark the sanitized text, then compose).
     *
     * Why not untrustedForMarkedFrames(): that contract PRESERVES CR and
     * TAB for frame transports whose writers re-frame every row. A veil
     * row is emitted verbatim into a terminal — a surviving CR makes the
     * line after it overwrite a previous one while the diff model still
     * believed the cell was blank, and a TAB measures 4 cells to Width
     * but terminals advance to the next 8-column stop. Neither is a
     * marked-FRAME concern; both are marked-DISPLAY ones, so the stricter
     * display sweep is the right floor here.
     */
    public function withUntrustedContent(string $content): self
    {
        $swept = Sanitize::stripZoneSentinels(Sanitize::untrustedForDisplay($content));

        return $this->withContent(\str_replace("\t", '    ', $swept));
    }

    /** Read-only accessor for the stack-driven overlay content ('' = none). */
    public function content(): string
    {
        return $this->content;
    }

    /**
     * Return a copy whose mark() emits no zone envelopes at all.
     *
     * With markers disabled, mark($id, $content) returns $content
     * byte-for-byte — the zone is not registered and the scanner sees
     * nothing — while id/content VALIDATION still runs (a bad id throws
     * exactly as before, so disabling is never a validation bypass).
     *
     * This is the opt-out for hosts that cannot strip the U+E000 id
     * U+E001 envelopes before display: withoutZoneMarkers() means the
     * frame carries no sentinel bytes at all, so no consumer-side sweep
     * duty exists (see README "Zone markers and presentation"). The
     * hit-testing methods stay CALLABLE on such a veil but answer
     * vacuously: scan() finds no zones, hit() always returns null, and a
     * dismiss-enabled markerless veil's isClickOutside() therefore
     * returns TRUE for EVERY click — a silent always-dismiss hazard, not
     * an error. The state is invisible to this lib from that end —
     * nothing throws at click time — so the pairing of
     * {@see withClickOutsideDismiss()} with this call is a review
     * contract, not a type contract.
     *
     * @see isClickOutside()
     */
    public function withoutZoneMarkers(): self
    {
        return $this->mutate(marker: Mark::disabled());
    }

    /**
     * Honour the user's reduced-motion preference.
     *
     * When enabled, animate() treats every progress value as 1.0: no
     * intermediate slide/fade/scale frames, no scrim ramp — the overlay
     * simply appears fully settled in one frame (with the backdrop at its
     * configured opacity).
     *
     * This lib does not probe the terminal itself (sugar-colorprofile is
     * not a dependency); the host app decides, typically from
     * Probe::reducedMotion() and/or a user setting, and passes the verdict
     * in — mirroring the sugar-animation house convention that motion
     * preferences are a wiring concern, not a rendering one.
     */
    public function withReducedMotion(bool $reduced = true): self
    {
        return $this->mutate(reducedMotion: $reduced);
    }

    /**
     * The FULL (non-delta) frame the last composite()/animate() emitted,
     * or null if none has been painted through this veil's session yet.
     *
     * When diffing is enabled, the RETURNED string of most frames is a
     * cursor-addressed delta that carries no zone markers — zones live only
     * in full frames. Feed THIS string to scan() (or sugar-mouse's
     * Scanner) to hit-test a modal across animated/diffed frames; scanning
     * the delta itself silently registers zero zones.
     *
     * @see RenderSession::lastFullFrame()
     */
    public function lastFullFrame(): ?string
    {
        return $this->session->lastFullFrame();
    }

    /** Read-only accessor for vertical position anchor. */
    public function vPosition(): ?Position
    {
        return $this->vPosition;
    }

    /** Read-only accessor for horizontal position anchor. */
    public function hPosition(): ?Position
    {
        return $this->hPosition;
    }

    /** Read-only accessor for X position offset in columns. */
    public function positionX(): int
    {
        return $this->posX;
    }

    /** Read-only accessor for Y position offset in rows. */
    public function positionY(): int
    {
        return $this->posY;
    }

    /**
     * Wrap content with border chrome using sugar-style Style.
     *
     * @param string $content The content to wrap
     * @return string Content wrapped in border (or unchanged if no border set)
     */
    public function applyBorderChrome(string $content): string
    {
        if ($this->border === null) {
            return $content;
        }
        return Style::new()
            ->border($this->border)
            ->render($content);
    }

    /**
     * Check if a mouse message is outside the veil zone.
     *
     * Requires scan($renderedOutput) to be called first with the
     * rendered veil output so the scanner knows the zone bounds
     * (with diffing active, scan {@see lastFullFrame()}, not the delta).
     *
     * The mouse coordinates are passed straight to {@see hit()}, which is
     * 1-based like every sugar-mouse zone — a host whose MouseMsg carries
     * 0-based cells must add 1 to each axis before calling.
     *
     * On a {@see withoutZoneMarkers()} veil every lookup misses, so with
     * the dismiss flag enabled this answers TRUE for every click —
     * including one inside the overlay's own footprint. Declared hazard,
     * pinned by test; not an error.
     *
     * @throws \RuntimeException if click-outside dismissal is ENABLED and
     *         no scan() has run yet (with the flag off this answers false
     *         unconditionally — the guard never fires)
     *
     * @see scan()
     * @see hit()
     */
    public function isClickOutside(\SugarCraft\Core\Msg\MouseMsg $mouse): bool
    {
        if ($this->clickOutsideDismiss === false) {
            return false;
        }
        if ($this->lastRendered === null) {
            throw new \RuntimeException(
                'isClickOutside() requires scan() to be called first with the rendered veil output.'
            );
        }
        return $this->hit($mouse->x, $mouse->y) === null;
    }

    /**
     * Feed a rendered output string to the internal scanner so that
     * subsequent hit-testing can determine which zone contains
     * a given coordinate pair.
     *
     * Call this after animate() or composite() return, before calling
     * hit() or isClickOutside().
     *
     * DIFFING LAW: when the session has emitted more than one frame, the
     * string composite()/animate() RETURNED is usually a cursor-addressed
     * delta with no zone envelopes in it — scanning it registers ZERO
     * zones and every click then reads as outside. Scan
     * {@see lastFullFrame()} instead; it is the complete frame the delta
     * was derived from, markers and all.
     *
     * Uses sugar-mouse Scanner internally — no external Manager needed.
     *
     * The clone carries a FRESH scanner holding only this render's zones:
     * Scanner::scan() replaces zones wholesale, so scanning the shared
     * instance in place would retro-change the hit-testing of every other
     * clone. Later with*() calls carry this scanned instance forward
     * together with lastRendered, so hit-testing survives model updates
     * (e.g. withContent()) made between the render and the click; only a
     * subsequent scan() replaces it.
     *
     * @throws \InvalidArgumentException if the frame contains two zones
     *         with the same id (Scanner::scan() rejects duplicate ids —
     *         reuse of a marker id across rows of one frame is a bug in
     *         the caller's mark() layout, not something to catch)
     */
    public function scan(string $rendered): self
    {
        $scanner = Scanner::new();
        $scanner->scan($rendered);
        return $this->mutate(scanner: $scanner, lastRendered: $rendered, lastRenderedSet: true);
    }

    /**
     * Return the zone at the given terminal cell, or null if no zone
     * contains it. Columns and rows are 1-based (sugar-mouse zone space).
     *
     * Requires scan() to have been called after the last render — of
     * {@see lastFullFrame()} when the session is diffing.
     *
     * @see lastZoneRect() for the same zone as a paint rect (focus rings).
     */
    public function hit(int $col, int $row): ?Zone
    {
        return $this->scanner->hit($col, $row);
    }

    /**
     * The rect the current scan registered for $id — the focus-ring door
     * (sugar-overlay-030): a host that carries a focused zone id can paint
     * its ring from published geometry without owning the Scanner.
     *
     * Converted from the Zone's 1-based inclusive corners to a paint rect:
     * x/y stay 1-based (same space as {@see hit()}), w/h are cell counts.
     * Null when no scan has registered that id.
     *
     * @return null|array{x: int, y: int, w: int, h: int}
     *
     * @see zoneRects()
     * @see scan()
     */
    public function lastZoneRect(string $id): ?array
    {
        $zone = $this->scanner->get($id);
        if ($zone === null) {
            return null;
        }

        return [
            'x' => $zone->startCol,
            'y' => $zone->startRow,
            'w' => $zone->width(),
            'h' => $zone->height(),
        ];
    }

    /**
     * Every zone the current scan registered, in the Scanner's scan order,
     * as {id, x, y, w, h} paint rects (the same 1-based space as
     * {@see lastZoneRect()}). Tab/arrow cycling walks this list; the ring
     * of the selected entry is drawn from lastZoneRect(). Empty on a
     * markerless scan.
     *
     * @return list<array{id: string, x: int, y: int, w: int, h: int}>
     *
     * @see lastZoneRect()
     */
    public function zoneRects(): array
    {
        $rects = [];
        foreach ($this->scanner->all() as $zone) {
            $rects[] = [
                'id' => $zone->id,
                'x'  => $zone->startCol,
                'y'  => $zone->startRow,
                'w'  => $zone->width(),
                'h'  => $zone->height(),
            ];
        }

        return $rects;
    }

    /**
     * Wrap $content with a zone marker so the scanner can later extract
     * its bounding box.
     *
     * The envelope is NOT invisible: it costs zero CELLS in this lib's
     * width accounting (MarkedFrame/compositor/paintRow all skip it) but
     * its U+E000 id U+E001 bytes ship verbatim inside the frame, and the
     * id between them is ordinary printable text — a terminal that does
     * not strip the private-use sentinels paints the id as garbage.
     * Hosts that emit raw frames to such terminals must strip envelopes
     * for display (Sanitize::stripZoneSentinels) or opt out entirely with
     * withoutZoneMarkers(). See README "Zone markers and presentation".
     *
     * Uses sugar-mouse Mark internally — no external Manager needed.
     *
     * TRUST BOUNDARY: the marker IS the U+E000/U+E001 sentinel pair —
     * the same bytes a forged payload would use to mint a phantom zone.
     * Only ever mark() text you assembled yourself (or text already swept
     * via withUntrustedContent()); never wrap untrusted text in a
     * mark()ed zone and assume the zone is all the attacker got — bytes
     * OUTSIDE the markers flow into the frame unvalidated too.
     */
    public function mark(string $id, string $content): string
    {
        return $this->marker->wrap($id, $content);
    }

    /**
     * Apply animation and composite the overlay onto the background.
     *
     * @param string    $foreground  The overlay content (e.g. a modal). TRUST BOUNDARY: passed through verbatim — forged zone sentinels / OSC 8 links inside it become REAL zones and links (see withContent()).
     * @param string    $background    The base content. TRUST BOUNDARY: equally attacker-reachable — forged sentinels or OSC 8 links in the BACKDROP mint real zones and links too, so sweep background text the same way you sweep foreground text.
     * @param Position  $vertical     Vertical position anchor
     * @param Position  $horizontal   Horizontal position anchor
     * @param float     $progress    Animation progress — clamped to 0.0–1.0 (0=start, 1=end); values outside the interval saturate instead of overshooting. Non-finite inputs take documented ends: NAN paints the settled end (1.0), +INF saturates high, -INF saturates low.
     * @param int       $xOffset      Additional columns rightward (+) / leftward (-)
     * @param int       $yOffset     Additional lines downward (+) / upward (-)
     * @return string                 The composited output
     */
    public function animate(
        string $foreground,
        string $background,
        Position $vertical,
        Position $horizontal,
        float $progress,
        int $xOffset = 0,
        int $yOffset = 0,
    ): string {
        // NAN is not absorbed by the clamp: comparisons with NAN are
        // libc-dependent, so max/min alone could silently freeze, skip, or
        // propagate the poison depending on the host. The interval law
        // spells the end out: NAN takes the settled end (the box-probed
        // value, so no host regresses), ±INF saturate through the clamp.
        $progress = \is_nan($progress) ? 1.0 : \max(0.0, \min(1.0, $progress));
        if ($this->reducedMotion) {
            $progress = 1.0;
        }

        $animFg = $foreground;
        $animXOffset = $xOffset;
        $animYOffset = $yOffset;

        if ($this->animation !== null && $progress < 1.0) {
            $result = $this->applyAnimation($foreground, $progress, $vertical, $horizontal);
            $animFg = $result->foreground;
            $animXOffset = $xOffset + $result->horizontalOffset;
            $animYOffset = $yOffset + $result->verticalOffset;
        }

        // Mid-animation the scrim fades IN with the content: at progress 0
        // the backdrop is untouched, at 1.0 it carries the configured
        // opacity. Without this ramp, a fade-open overlay paints its
        // dimming at full strength on the very first frame — the scrim
        // snaps while the modal glides. The ramp rides the SAME eased
        // progress the content glides on (sugar-overlay-066: a linear
        // scrim against eased content made mid-animation contrast
        // oscillate — two different p-curves fighting over one frame).
        $veil = $this;
        if ($this->animation !== null && $progress < 1.0 && $this->backdropOpacity > 0) {
            $eased = $this->animationDriver()?->easedProgress($progress) ?? $progress;
            $veil = $this->mutate(backdropOpacity: (int) \round($this->backdropOpacity * $eased));
        }

        return $veil->composite($animFg, $background, $vertical, $horizontal, $animXOffset, $animYOffset);
    }

    /**
     * The concrete driver behind the carried animation: a configured
     * instance passes through (custom easing rides animate()); a preset
     * kind resolves to that kind's default curve (per-class docblocks
     * state which curve is whose — the defaults differ, sugar-overlay-058).
     */
    private function animationDriver(): Fade|Scale|Slide|null
    {
        $animation = $this->animation;
        if ($animation instanceof Fade || $animation instanceof Scale || $animation instanceof Slide) {
            return $animation;
        }

        return match ($animation) {
            AnimationKind::SLIDE => Slide::new(),
            AnimationKind::FADE => Fade::new(),
            AnimationKind::SCALE => Scale::new(),
            null => null,
        };
    }

    /**
     * Apply the configured animation to the foreground at the given progress.
     */
    private function applyAnimation(
        string $foreground,
        float $progress,
        Position $vertical,
        Position $horizontal,
    ): AnimationFrame {
        $driver = $this->animationDriver();
        if ($driver instanceof Slide) {
            return $driver->applyFrame($foreground, $progress, $vertical, $horizontal);
        }

        if ($driver === null) {
            throw new \LogicException('Veil::applyAnimation() called with no animation set.');
        }

        return new AnimationFrame(foreground: $driver->apply($foreground, $progress));
    }

    /**
     * Composite a foreground string over a background string.
     *
     * On the first composite (or after a resize), emits the full output.
     * On subsequent composites with the same dimensions, emits only the
     * delta via Buffer::diff() + DiffEncoder for reduced SSH bandwidth.
     *
     * The background is the canvas and the overlay is clipped to it. A
     * background with no cells (the empty string, or rows of zero width)
     * offers no canvas, so the overlay itself is returned as the frame
     * (mirroring upstream's `bg == ""` early return). That frame still goes
     * through the diff session like any other, so the next composite diffs
     * against what was actually emitted; a frame with no cells at all resets
     * the session, forcing the next one out in full.
     *
     * @param string    $foreground  The overlay content (e.g. a modal). TRUST BOUNDARY: passed through verbatim — forged zone sentinels / OSC 8 links inside it become REAL zones and links (see withContent()).
     * @param string    $background   The base content. TRUST BOUNDARY: equally attacker-reachable — forged sentinels or links in the BACKDROP mint real zones and links too (the same zero-assumption walk paints it); sweep untrusted backdrop text before compositing.
     * @param Position $vertical     Vertical position anchor
     * @param Position $horizontal    Horizontal position anchor
     * @param int       $xOffset      Additional columns rightward (+) / leftward (-)
    /**
     * The single placement computation every public spell shares: split,
     * border chrome, one tokenize per row, anchor resolution, offsets.
     *
     * composite() consumes the bundle; coveredCells() folds the painted
     * rect from it. Two arithmetics that could drift is how zone-lift
     * hosts get lied to — so there is exactly one (sugar-overlay-012).
     * The canvas anchors project against is the backdrop's measured
     * bounding box — widest line × line count — not the terminal
     * (sugar-overlay-011); a modal that must center the SCREEN pads or
     * squares its canvas to true dimensions first.
     *
     * @return array{canvasEmpty: bool, bgLines: list<string>, bgSegs: array<int, list<array{string,int}>>, bgWidths: list<int>, bgWidth: int, bgHeight: int, fgLines: list<string>, fgSegs: array<int, list<array{string,int}>>, fgWidths: list<int>, fgWidth: int, fgHeight: int, x: int, y: int}
     */
    private function resolvePlacement(
        string $foreground,
        string $background,
        Position $vertical,
        Position $horizontal,
        int $xOffset,
        int $yOffset,
    ): array {
        $bgLines  = $this->splitLines($background);
        $bgHeight = \count($bgLines);
        // One tokenize per row (sugar-overlay-003): widths, the covered-row
        // clips and both prefix/suffix splits all fold from these segment
        // lists — the same numbers the old repeated MarkedFrame::measure()/
        // take()/drop() string walks produced, at one walk per row instead
        // of 7-10 per frame.
        $bgSegs   = [];
        $bgWidths = [];
        foreach ($bgLines as $r => $line) {
            $segs         = MarkedFrame::segments($line);
            $bgSegs[$r]   = $segs;
            $bgWidths[$r] = MarkedFrame::measureSegments($segs);
        }
        $bgWidth = $bgWidths === [] ? 0 : \max($bgWidths);

        // Border chrome is unconditional: a set border wraps the foreground
        // before any measurement, so anchoring and diffing see the bordered
        // block (there is no fixed-size mode; the legacy autoSize flag
        // never re-gated this — sugar-overlay has always sized from
        // content).
        if ($this->border !== null) {
            $foreground = $this->applyBorderChrome($foreground);
        }

        $fgLines  = $this->splitLines($foreground);
        $fgHeight = \count($fgLines);
        $fgSegs   = [];
        $fgWidths = [];
        foreach ($fgLines as $r => $line) {
            $segs         = MarkedFrame::segments($line);
            $fgSegs[$r]   = $segs;
            $fgWidths[$r] = MarkedFrame::measureSegments($segs);
        }
        $fgWidth = $fgWidths === [] ? 0 : \max($fgWidths);

        if ($bgHeight === 0 || $bgWidth === 0) {
            // No canvas to clip against; composite() returns the overlay
            // itself as the frame and coveredCells() reads the same flag.
            return [
                'canvasEmpty' => true,
                'bgLines' => $bgLines, 'bgSegs' => $bgSegs, 'bgWidths' => $bgWidths,
                'bgWidth' => $bgWidth, 'bgHeight' => $bgHeight,
                'fgLines' => $fgLines, 'fgSegs' => $fgSegs, 'fgWidths' => $fgWidths,
                'fgWidth' => $fgWidth, 'fgHeight' => $fgHeight,
                'x' => 0, 'y' => 0,
            ];
        }

        // Resolve base position. The background is the canvas, so an anchor
        // never pushes the overlay's TOP off it: a centre/bottom anchor on an
        // overlay taller than the backdrop would yield a negative baseY and
        // silently drop the overlay's first rows (they sit above row 0).
        // Clamping the anchor to >= 0 paints from the overlay's top instead
        // and the overflow is clipped at the bottom — the least-surprising
        // reading of "clip to canvas". Explicit $yOffset below is NOT
        // clamped: negative offsets stay legal for slide-in animations.
        //
        // baseX is deliberately the ASYMMETRIC axis: it is not clamped, so
        // a RIGHT/LEFT anchor with a too-wide overlay (or a slide offset)
        // can push the block off the columns and whole rows drop. This is
        // sugar-overlay's OWN divergence, dated 2026-11 (sugar-overlay-028
        // — an earlier revision of this comment wrongly called it "upstream
        // slide semantics": upstream has no slide/animation at all, and
        // clamps both axes). We keep x unclamped because the Animation/Slide
        // arms rely on partial-width paints at negative x; a fit-to-canvas
        // mode that shrinks the backdrop's canvas instead is a product
        // design seam, not silently added here.
        $baseX = $horizontal->xOffset($fgWidth, $bgWidth);
        $baseY = \max(0, $vertical->yOffset($fgHeight, $bgHeight));

        // Apply additional offsets
        return [
            'canvasEmpty' => false,
            'bgLines' => $bgLines, 'bgSegs' => $bgSegs, 'bgWidths' => $bgWidths,
            'bgWidth' => $bgWidth, 'bgHeight' => $bgHeight,
            'fgLines' => $fgLines, 'fgSegs' => $fgSegs, 'fgWidths' => $fgWidths,
            'fgWidth' => $fgWidth, 'fgHeight' => $fgHeight,
            'x' => $baseX + $xOffset,
            'y' => $baseY + $yOffset,
        ];
    }

    /**
     * Which canvas cells the composite actually covers — the rect zone-lift
     * hosts need to know which hot zones an overlay hides (sugar-overlay-012).
     *
     * Same inputs, same resolvePlacement() arithmetic as composite(); the
     * per-row visibility guards mirror the paint loop verbatim. Returns null
     * when no cell of the overlay lands on-canvas (fully off-canvas offsets,
     * or a zero-cell overlay over a real backdrop).
     *
     * @return ?array{x: int, y: int, w: int, h: int}
     */
    public function coveredCells(
        string $foreground,
        string $background,
        Position $vertical,
        Position $horizontal,
        int $xOffset = 0,
        int $yOffset = 0,
    ): ?array {
        $p        = $this->resolvePlacement($foreground, $background, $vertical, $horizontal, $xOffset, $yOffset);
        $fgWidths = $p['fgWidths'];

        if ($p['canvasEmpty']) {
            // The overlay itself is the frame — every fg cell is painted.
            if ($p['fgHeight'] === 0 || $p['fgWidth'] === 0) {
                return null;
            }

            return ['x' => 0, 'y' => 0, 'w' => $p['fgWidth'], 'h' => $p['fgHeight']];
        }

        // Fold the painted extent row by row, mirroring the composite loop's
        // guards: the row must land on-canvas, x must not start past the
        // canvas, and the line's own width must not end at or left of
        // column 0 (the mirror guard — a zero-width or fully off-left line
        // paints nothing).
        $x       = $p['x'];
        $y       = $p['y'];
        $bgWidth = $p['bgWidth'];
        $lo      = PHP_INT_MAX;
        $hi      = PHP_INT_MIN;
        $top     = PHP_INT_MAX;
        $bottom  = PHP_INT_MIN;
        foreach ($fgWidths as $fy => $rowWidth) {
            $row = $y + $fy;
            if ($row < 0 || $row >= $p['bgHeight']) {
                continue;
            }

            if ($x >= $bgWidth || $x + $rowWidth <= 0) {
                continue;
            }

            $lo     = \min($lo, \max(0, $x));
            $hi     = \max($hi, \min($bgWidth, $x + $rowWidth));
            $top    = \min($top, $row);
            $bottom = \max($bottom, $row);
        }

        if ($hi <= $lo) {
            return null;
        }

        return ['x' => $lo, 'y' => $top, 'w' => $hi - $lo, 'h' => $bottom - $top + 1];
    }

    /**
     * How far the block overhangs the canvas — the predicate that lets a
     * host warn BEFORE rendering a clipped modal (sugar-overlay-007).
     *
     * Same resolvePlacement() arithmetic as composite(); bounding-edge
     * overhang per side, in cells (a block shoved entirely off-left reports
     * how far its left edge sticks, not how many of its cells were hidden —
     * coveredCells() answers that question). Returns null when nothing
     * overhangs: every cell the block paints lands on-canvas. A fit-to-canvas
     * SHRINK mode stays a product seam this library does not choose; the
     * predicate hands the host the numbers to choose it itself.
     *
     * @return ?array{clippedLeft: int, clippedRight: int, clippedTop: int, clippedBottom: int}
     */
    public function overflowFor(
        string $foreground,
        string $background,
        Position $vertical,
        Position $horizontal,
        int $xOffset = 0,
        int $yOffset = 0,
    ): ?array {
        $p = $this->resolvePlacement($foreground, $background, $vertical, $horizontal, $xOffset, $yOffset);

        if ($p['canvasEmpty']) {
            // No canvas, no clipping: the overlay itself is the frame.
            return null;
        }

        $clip = [
            'clippedLeft'   => \max(0, -$p['x']),
            'clippedRight'  => \max(0, $p['x'] + $p['fgWidth'] - $p['bgWidth']),
            'clippedTop'    => \max(0, -$p['y']),
            'clippedBottom' => \max(0, $p['y'] + $p['fgHeight'] - $p['bgHeight']),
        ];

        foreach ($clip as $cells) {
            if ($cells > 0) {
                return $clip;
            }
        }

        return null;
    }

    /**
     * @param int       $yOffset      Additional lines downward (+) / upward (-)
     * @return string                 The composited output
     */
    public function composite(
        string $foreground,
        string $background,
        Position $vertical,
        Position $horizontal,
        int $xOffset = 0,
        int $yOffset = 0,
    ): string {
        $p = $this->resolvePlacement($foreground, $background, $vertical, $horizontal, $xOffset, $yOffset);

        $bgLines  = $p['bgLines'];
        $bgSegs   = $p['bgSegs'];
        $bgWidths = $p['bgWidths'];
        $bgWidth  = $p['bgWidth'];
        $bgHeight = $p['bgHeight'];
        $fgSegs   = $p['fgSegs'];
        $fgWidths = $p['fgWidths'];
        $fgHeight = $p['fgHeight'];
        $x        = $p['x'];
        $y        = $p['y'];

        if ($p['canvasEmpty']) {
            // A background with no cells is no canvas to clip against, so the
            // overlay itself is the frame — mirrors upstream Composite()'s
            // `if bg == "" { return fg }` early return. It still goes through
            // the session bookkeeping: a skipped rememberFull() would let the
            // next composite diff against a frame from before this one.
            if ($fgHeight === 0 || $p['fgWidth'] === 0) {
                return $this->emitFrame($background, 0, 0);
            }

            return $this->emitFrame(\implode("\n", $p['fgLines']), $p['fgWidth'], $fgHeight);
        }

        // Build the output a row at a time. Each foreground line is overlaid as a
        // single styled SEGMENT at [x, x + visibleWidth) — cell-aware (via Width)
        // so its ANSI escape sequences are never split — and the backdrop is dimmed
        // only OUTSIDE the foreground footprint, so the overlay stays bright over a
        // dimmed background instead of inheriting the dim.
        //
        // Unlike the old clamp, we allow $x/$y to fall entirely outside the
        // background bounds so that animations (e.g. SLIDE at progress 0) can
        // position the overlay fully off-screen without showing a sliver.
        $output = [];
        $pen    = $this->dimPen();
        for ($row = 0; $row < $bgHeight; $row++) {
            $bgLine = $bgLines[$row];
            $fy = $row - $y;

            if ($fy < 0 || $fy >= $fgHeight) {
                // Not covered by the foreground — dim the whole background line.
                $output[$row] = $this->dimLine($bgLine);
                continue;
            }

            // If the overlay starts to the right of the background, skip this row.
            if ($x >= $bgWidth) {
                $output[$row] = $this->dimLine($bgLine);
                continue;
            }

            // Mirror guard for the left edge: an overlay whose whole footprint
            // ends at or left of column 0 (negative xOffset, or an anchor
            // centre where fg is wider than bg) shows nothing — including a
            // zero-width line, whose drop below lands at x=0.
            if ($x + $fgWidths[$fy] <= 0) {
                $output[$row] = $this->dimLine($bgLine);
                continue;
            }

            // Clip the foreground line to the row, keeping its escapes cell-
            // aware — and its zone envelopes BALANCED (MarkedFrame: Width's
            // ANSI walkers charge the marker bytes, so a marked line would
            // anchor, clip and splice as though those cells were visible).
            // Drop the head when x < 0 so off-screen-left columns never leak
            // onto col 0, then truncate to the room left. All three ops fold
            // from the row's cached segment list; $fgCarried is exactly the
            // segments that re-tokenize the clipped bytes for isolation.
            if ($x < 0) {
                [, , $shifted] = MarkedFrame::dropSegments($fgSegs[$fy], -$x);
                [$fgLine, $fgVis, $fgCarried] = MarkedFrame::takeSegments($shifted, $bgWidth);
            } else {
                [$fgLine, $fgVis, $fgCarried] = MarkedFrame::takeSegments($fgSegs[$fy], $bgWidth - $x);
            }

            // Compute prefix/suffix from the background; with the head clipped
            // the overlay always starts at column max(0, x).
            $prefixWidth = \max(0, $x);
            [$prefix, $prefVis, $prefCarried] = MarkedFrame::takeSegments($bgSegs[$row], $prefixWidth);
            $prefix .= \str_repeat(' ', $prefixWidth - $prefVis);
            $cut = $prefixWidth + $fgVis;
            [$suffix, $suffixVis] = MarkedFrame::dropSegments($bgSegs[$row], $cut);
            // MarkedFrame::dropSegments() drops a wide backdrop grapheme straddling
            // the cut WHOLE — it consumes its right-half cell one column BEYOND
            // the overlay footprint. Without a replacement the rest of the
            // row slides left and the composite is a cell narrower than the
            // backdrop (e.g. 'abcde語fgh' + 'ZZZ' at x=3 yielded 9 cells).
            // The shortfall is the row's cached width minus the cut minus the
            // surviving tail width; blank it so every composited row keeps the
            // backdrop's exact width (the left edge needs no twin because the
            // pad above already re-blanks).
            $straddled = $bgWidths[$row] - $cut - $suffixVis;
            if ($straddled > 0) {
                $suffix = \str_repeat(' ', $straddled) . $suffix;
            }

            // Splice with pen isolation: a segment that ends on a non-default
            // color pen (crop mid-style, no trailing reset) must not bleed
            // that pen into the next segment — the dim wrap and the reset
            // order below make each of the three segments COLOUR-self-contained
            // (SGR 39/49 at the boundaries). Attributes are the documented
            // exception: bold/underline/reverse intentionally RIDE ACROSS the
            // segment seams (sugar-overlay-063 — the old claim of full
            // self-containment overclaimed; the byte truth is pinned in
            // SgrWalkerTest::testAttributesDeliberatelyBleedAcrossSeams).
            // The neutralizers fold from the carried segment lists: the pad
            // spaces appended after the take contribute no SGR, so they are
            // invisible to the fold exactly as re-walking the bytes would see.
            if ($pen === null || $prefix === '') {
                $dp        = $prefix;
                $dpCarried = $prefCarried;
            } else {
                $dp        = $pen['open'] . $prefix . $pen['close'];
                $dpCarried = \array_merge([$pen['openSeg']], $prefCarried, [$pen['closeSeg']]);
            }
            $output[$row] = $dp . MarkedFrame::isolationSegments($dpCarried) . $fgLine
                . MarkedFrame::isolationSegments($fgCarried) . $this->dimLine($suffix);
        }

        return $this->emitFrame(\implode("\n", $output), $bgWidth, $bgHeight);
    }

    /**
     * Route one composited frame through the diff session: a full frame on
     * the first call or after a resize, otherwise the delta against the
     * previous frame.
     *
     * A zero-area frame has no cells to diff, so it is emitted as-is and the
     * session is reset — the next non-empty frame must be emitted full, since
     * whatever the terminal shows no longer matches the remembered frame.
     */
    private function emitFrame(string $fullOutput, int $width, int $height): string
    {
        if ($width === 0 || $height === 0) {
            $this->session->reset();

            return $fullOutput;
        }

        if ($this->session->shouldEmitFull($width, $height) === true) {
            $this->session->rememberFull($fullOutput, $width, $height);
            return $fullOutput;
        }

        return $this->session->diff(
            $fullOutput,
            $width,
            $height,
            $this->bufferFactory ??= fn (string $out, int $w, int $h): Buffer => $this->bufferFromOutput($out, $w, $h),
        );
    }

    /**
     * Dim a single background line using truecolor opacity blend toward black.
     *
     * Uses a truecolor foreground color that is the default terminal foreground
     * (white-ish) blended toward black proportional to the backdrop opacity.
     * This replaces the old nested FAINT approach which only produced ~2 visual
     * states; the truecolor blend gives a smooth gradient across 0–100%.
     *
     * Every non-empty line is wrapped when a dim is active — including lines
     * that START with an escape sequence. The wrap only PREPENDS and APPENDS
     * SGR sequences; embedded OSC/CSI payloads are never rewritten, so a
     * hyperlink-led line keeps its target. A styled line whose own SGR sets a
     * color after the dim pen keeps that color (the pen inside wins): the
     * dim reaches exactly the cells that were on the default foreground.
     *
     * Two honest limits: dims do not COMPOUND — the pen closest to the
     * text wins, so a second wrap leaves the first pen bytes intact and
     * the outer scrim simply never reaches already-dimmed cells: stacking
     * two veils at 50% does not read as 75%, the first dim to touch a
     * cell governs. And the blend assumes a light default foreground —
     * on a light terminal the gray pen can brighten dark text instead of
     * dimming it.
     */
    private function dimLine(string $line): string
    {
        $pen = $this->dimPen();
        if ($pen === null || $line === '') {
            return $line;
        }

        return $pen['open'] . $line . $pen['close'];
    }

    /**
     * The dim pen when a backdrop opacity is armed, or null.
     *
     * One source of truth for both the wrap bytes ({@see dimLine()}) and
     * the SGR segments the compositor's pen-isolation folds over — the
     * segs re-tokenize exactly as the emitted bytes would (sugar-overlay-003:
     * the isolation walk of a just-dimmed prefix was a pure re-scan).
     *
     * Default terminal foreground (approx white) blended toward black
     * by the opacity percentage. Formula: new = original * (1 - opacity/100)
     *
     * @return null|array{open:string, close:string, openSeg:array{kind:string,bytes:string,width:int,id:string}, closeSeg:array{kind:string,bytes:string,width:int,id:string}}
     */
    private function dimPen(): ?array
    {
        $opacity = $this->backdropOpacity;
        if ($opacity === 0) {
            return null;
        }

        $factor = 1.0 - ($opacity / 100.0);
        $r = (int) \round(255 * $factor);
        $g = (int) \round(255 * $factor);
        $b = (int) \round(255 * $factor);

        return [
            'open'  => "\e[38;2;{$r};{$g};{$b}m",
            'close' => "\e[39m",
            'openSeg'  => ['kind' => 'sgr', 'bytes' => "\e[38;2;{$r};{$g};{$b}m", 'width' => 0, 'id' => "38;2;{$r};{$g};{$b}"],
            'closeSeg' => ['kind' => 'sgr', 'bytes' => "\e[39m", 'width' => 0, 'id' => '39'],
        ];
    }

    /**
     * Split a multi-line string into an array of lines.
     *
     * Same rule as {@see Lines::split()}: a trailing "\n" closes the last
     * line rather than opening an empty one; the Slide and Scale
     * animations measure with this exact rule.
     *
     * @return list<string>
     */
    public function splitLines(string $text): array
    {
        return Lines::split($text);
    }

    /**
     * Get the maximum line width (in characters) of an array of lines.
     *
     * Cells, not bytes: every line is weighed by {@see lineWidth()}
     * (which is {@see MarkedFrame::measure()}).
     *
     * @param list<string> $lines
     */
    public function maxLineWidth(array $lines): int
    {
        $max = 0;
        foreach ($lines as $line) {
            $w = $this->lineWidth($line);
            if ($w > $max) {
                $max = $w;
            }
        }
        return $max;
    }

    /**
     * Get the display width of a single line, in terminal cells.
     *
     * ANSI escapes and sugar-mouse zone markers (the U+E000 id U+E001
     * envelopes {@see mark()} adds) cost zero cells and are excluded —
     * the same accounting the compositor and the Scanner use. Delegates
     * to {@see MarkedFrame::measure()}; this is the pointer, not a
     * second implementation.
     */
    public function lineWidth(string $line): int
    {
        return MarkedFrame::measure($line);
    }

    /**
     * Create a new instance with updated properties.
     *
     * Nullable fields use paired `…Set` sentinels so an explicit null
     * CLEARS the value (house immutable-fluent law): without a sentinel,
     * `?? $this->x` cannot distinguish "set to null" from "leave alone".
     */
    private function mutate(
        ?int $backdropOpacity = null,
        AnimationKind|Fade|Scale|Slide|null $animation = null,
        bool $animationSet = false,
        ?int $zIndex = null,
        ?bool $clickOutsideDismiss = null,
        ?bool $autoSize = null,
        ?Border $border = null,
        bool $borderSet = false,
        ?Scanner $scanner = null,
        ?string $lastRendered = null,
        bool $lastRenderedSet = false,
        ?Mark $marker = null,
        ?Position $vPosition = null,
        bool $vPositionSet = false,
        ?Position $hPosition = null,
        bool $hPositionSet = false,
        ?int $posX = null,
        ?int $posY = null,
        ?string $content = null,
        ?bool $reducedMotion = null,
    ): self {
        return new self(
            backdropOpacity: $backdropOpacity ?? $this->backdropOpacity,
            animation: $animationSet ? $animation : $this->animation,
            zIndex: $zIndex ?? $this->zIndex,
            clickOutsideDismiss: $clickOutsideDismiss ?? $this->clickOutsideDismiss,
            autoSize: $autoSize ?? $this->autoSize,
            border: $borderSet ? $border : $this->border,
            // Carry the scanned zones forward: lastRendered rides every clone,
            // so dropping the scanner here would leave isClickOutside()'s
            // "forgot to scan" guard muted while hit() answered from an
            // empty zone set — every click would read as outside.
            scanner: $scanner ?? $this->scanner,
            lastRendered: $lastRenderedSet ? $lastRendered : $this->lastRendered,
            marker: $marker ?? $this->marker,
            session: $this->session,
            vPosition: $vPositionSet ? $vPosition : $this->vPosition,
            hPosition: $hPositionSet ? $hPosition : $this->hPosition,
            posX: $posX ?? $this->posX,
            posY: $posY ?? $this->posY,
            content: $content ?? $this->content,
            reducedMotion: $reducedMotion ?? $this->reducedMotion,
        );
    }

    /**
     * Build a Buffer from a multi-line string output.
     *
     * Walks each row with a terminal-faithful tokenizer (mirroring the
     * sugar-vterm emulator's cell representation, ESC-2/ESC-3 in commit
     * 9a8c8879c) so the diff compares what a terminal would actually
     * SHOW, not raw bytes:
     *
     *  - grapheme clusters get their DISPLAY width from Width::of();
     *    a width-2 head is followed by a Cell::continuation() tail, so
     *    CJK text lands on the columns the terminal paints it on.
     *  - CSI SGR sequences mutate a running pen; every cell stamped
     *    after them carries that Style, so a style-only frame change
     *    (e.g. backdrop opacity) is visible to Cell::equals() and
     *    produces a delta instead of being diffed away.
     *  - OSC 8 hyperlinks open/close a running link pen (an empty URI
     *    closes; SGR reset does NOT, per xterm) and other OSC sequences
     *    are consumed through BEL/ST — never emitted as visible cells.
     *  - sugar-mouse zone sentinels (U+E000 id U+E001 triples) are the
     *    scanner's addressing, not content: they consume ZERO columns
     *    here, exactly as MarkedFrame/composite() charge them (they still
     *    occupy bytes in the frame — see mark() and the README strip duty).
     *
     * Rows are split with a raw explode("\n"), NOT {@see Lines::split()}:
     * the buffer addresses fixed-height rows, so a trailing empty line
     * must STAY (it is a real blank row at the frame's bottom edge);
     * Lines::split() drops it, which would desync row indexing from the
     * height the caller passed in.
     *
     * @param string $output Multi-line string from composite()
     * @param int    $width  Buffer width in cells
     * @param int    $height Buffer height in rows
     */
    private function bufferFromOutput(string $output, int $width, int $height): Buffer
    {
        $lines = \explode("\n", $output);
        /** @var list<Cell> $grid */
        $grid = [];
        for ($row = 0; $row < $height; $row++) {
            foreach ($this->paintRow($lines[$row] ?? '', $width) as $cell) {
                $grid[] = $cell;
            }
        }

        return Buffer::fromGrid($width, $height, $grid);
    }

    /**
     * Tokenize one rendered row into exactly $width grid cells.
     *
     * @return list<Cell>
     */
    private function paintRow(string $line, int $width): array
    {
        // Blank row first; visible placement overwrites from the left. This
        // keeps fromGrid()'s exact width*height contract regardless of where
        // the walk stops (row overrun, unplaceable wide glyph).
        /** @var list<Cell> $cells */
        $cells = [];
        for ($i = 0; $i < $width; $i++) {
            $cells[] = Cell::new(' ', null, null, 1);
        }

        $pen  = null;   // running SGR style
        $link = null;   // running OSC 8 link (survives SGR reset, per xterm)

        $len = \strlen($line);
        $pos = 0;
        $col = 0;

        while ($pos < $len) {
            $byte = $line[$pos];

            // ESC-introducers: CSI and OSC only — both consume zero columns.
            if ($byte === "\e" && $pos + 1 < $len) {
                $intro = $line[$pos + 1];

                if ($intro === '[') {
                    // CSI: parameters run until the first final byte (0x40–0x7E).
                    // Only SGR ('m') feeds the pen; cursor moves and the rest are
                    // skipped whole (the old ctype_alpha scan mis-terminated on
                    // parameter bytes like 'p' or '>').
                    $p = $pos + 2;
                    while ($p < $len && \ord($line[$p]) < 0x40) {
                        $p++;
                    }
                    if ($p < $len && $line[$p] === 'm') {
                        $pen = self::penFromSgr(\substr($line, $pos + 2, $p - $pos - 2), $pen);
                    }
                    $pos = $p < $len ? $p + 1 : $len;
                    continue;
                }

                if ($intro === ']') {
                    // OSC: terminated by BEL or ST (ESC \). An ESC NOT
                    // followed by the ST backslash terminates the OSC at
                    // the ESC itself, and that ESC is re-parsed as the next
                    // sequence's introducer — swallowing it blind (plus one
                    // more byte, the old `$tail + 2`) used to paint the
                    // opener's parameters (e.g. "0m") as literal cells.
                    $tail = $pos + 2 + \strcspn($line, "\x07\x1b", $pos + 2);
                    $body = \substr($line, $pos + 2, $tail - $pos - 2);
                    if ($tail >= $len) {
                        // Unterminated OSC swallows the rest of the row,
                        // matching the emulator.
                        $pos = $len;
                    } elseif ($line[$tail] === "\x07") {
                        $pos = $tail + 1; // BEL consumed
                    } elseif (($line[$tail + 1] ?? '') === '\\') {
                        $pos = $tail + 2; // real ST (ESC \)
                    } else {
                        $pos = $tail;     // bare ESC: re-read it as an introducer
                    }
                    $link = self::linkFromOsc($body, $link);
                    continue;
                }

                // Any other two-byte ESC sequence (ESC ( B charset selects, a
                // stray ST, …): consume both bytes without touching the pens.
                $pos += 2;
                continue;
            }

            // sugar-mouse zone sentinels: U+E000 … U+E001 triples (the id
            // rides BETWEEN them in plain ASCII) plus stray closes are
            // zero-column to a terminal. An unmatched open swallows the tail.
            if ($byte === "\xEE" && $pos + 2 < $len && $line[$pos + 1] === "\x80") {
                if ($line[$pos + 2] === "\x80") { // U+E000 OPEN — skip id to CLOSE
                    $close = \strpos($line, Sanitize::ZONE_SENTINEL_CLOSE, $pos + 3);
                    $pos = $close === false ? $len : $close + 3;
                    continue;
                }
                if ($line[$pos + 2] === "\x81") { // U+E001 CLOSE without an OPEN
                    $pos += 3;
                    continue;
                }
            }

            $cluster = Cluster::next($line, $pos);
            $gw      = Width::of($cluster);

            if ($gw === 0) {
                // Combining marks / ZWJ joiners render inside the preceding
                // cell; giving them their own column would shift the row
                // against the terminal's own layout. Drop them (the base
                // grapheme already placed at this column carries the glyph).
                $pos += \strlen($cluster);
                continue;
            }

            if ($col + $gw > $width) {
                break; // no room left on this row (wide glyph at last column)
            }

            $cells[$col] = Cell::new($cluster, $pen, $link, $gw);
            if ($gw === 2) {
                $cells[$col + 1] = Cell::continuation();
            }
            $col += $gw;
            $pos += \strlen($cluster);
        }

        return $cells;
    }

    /**
     * Merge one CSI SGR parameter string into the live pen.
     *
     * A thin shell over {@see SgrWalker::fold()} — the ONE parser this lib
     * shares with the splice neutralizer (sugar-overlay-004: two hand-rolled
     * `explode(';')` walks drifted on exactly the rules the walker now holds
     * once: colon sub-parameters, and the no-op shape of a malformed or
     * last-parameter 38/48). The fold mirrors sugar-cellbuffer's private
     * `Buffer::styleFromSgr()` — reset, 16-color + bright ranges, 38;5/38;2
     * and 48;5/48;2 color keys (colon and semicolon forms), 39/49 default
     * colors, and the attribute on-codes — so veil's diff pen and
     * sugar-cellbuffer's own parse agree on what a style means. Promoting
     * THAT copy into the shared API is the owner-gated leg of SO-06: a
     * report row, not minted here.
     * SGR reset deliberately does NOT clear the link pen (xterm scopes
     * links to the OSC 8 protocol).
     *
     * Out-of-range color operands are CLAMPED into 0–255 (see
     * SgrWalker::channel()), never bit-masked — truecolor components and
     * 38;5/48;5 palette indices alike, the same rule sugar-cellbuffer's
     * styleFromSgr() applies. The pen is re-encoded into every delta, so a
     * wrapped component or an index past the palette would repaint a cell
     * in a color the full frame never showed.
     */
    private static function penFromSgr(string $params, ?BufferStyle $carry): ?BufferStyle
    {
        $pen = SgrWalker::fold($params, [
            'fg'    => $carry?->fg(),
            'bg'    => $carry?->bg(),
            'attrs' => $carry?->attrs() ?? 0,
        ]);

        return $pen['fg'] !== null || $pen['bg'] !== null || $pen['attrs'] !== 0
            ? new BufferStyle($pen['fg'], $pen['bg'], $pen['attrs'])
            : null;
    }

    /**
     * Apply one OSC body to the link pen: OSC 8 opens (or, with an empty
     * URI, closes) a hyperlink; every other OSC code is consumed without
     * touching the pen.
     */
    private static function linkFromOsc(string $body, ?Hyperlink $carry): ?Hyperlink
    {
        if (\str_starts_with($body, '8;') === false) {
            return $carry;
        }
        $rest = \substr($body, 2);
        $sep  = \strpos($rest, ';');
        if ($sep === false) {
            return $carry; // malformed OSC 8 (no URI field) — leave pen unchanged
        }
        $params = \substr($rest, 0, $sep);
        $url    = \substr($rest, $sep + 1);

        if ($url === '') {
            return null; // empty URI closes the link (OSC 8 spec)
        }

        // The body is cut at BEL/ST, but nothing stops OTHER C0 bytes
        // (CR, LF, NUL) from riding inside the URL — Hyperlink's ctor
        // guard (which throws on /[\x00-\x1f\x7f]/) is load-bearing here,
        // not belt-and-braces. A hostile frame reaching this point (a
        // raw-with-content URL with an embedded newline) must degrade to
        // "no link" rather than abort the whole diff pass.
        \preg_match('/(?:\A|:)id=([^:]*)/', $params, $m);
        try {
            return new Hyperlink($url, $m[1] ?? '');
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Reset the diff state, forcing the next composite to emit a full frame.
     *
     * Dimension changes already force a full frame on their own, so this is
     * for OUT-OF-BAND invalidation: something other than this veil painted
     * over the terminal, or the cursor position is no longer trusted, and
     * the remembered frame no longer describes the screen.
     *
     * NOTE: This does NOT reset the scanner state. Hit-testing reads the
     * zones from the most recent scan() output — Scanner::scan() replaces
     * zones wholesale, so a re-scan after the next render refreshes them;
     * zones are not accumulated across frames. If you need an unscanned
     * instance, create a new Veil instead.
     *
     * @see scan()
     * @see withoutSession()
     */
    public function resetPreviousFrame(): void
    {
        $this->session->reset();
    }

    /**
     * Copy-construct this veil, swapping the diff session (SO-22,
     * sugar-overlay-033): the ONE enumeration point for "carry every
     * field unchanged". mutate() changes selected fields; rebuild()
     * changes none but the session.
     *
     * ImmutabilityFieldParityTest pins that this body names every
     * constructor parameter — the "do not forget a new field" warning is
     * executable, not tribal: add a ctor field without carrying it here
     * and that suite goes red.
     */
    private function rebuild(?RenderSession $session = null): self
    {
        return new self(
            backdropOpacity: $this->backdropOpacity,
            animation: $this->animation,
            zIndex: $this->zIndex,
            clickOutsideDismiss: $this->clickOutsideDismiss,
            autoSize: $this->autoSize,
            border: $this->border,
            scanner: $this->scanner,
            lastRendered: $this->lastRendered,
            marker: $this->marker,
            session: $session ?? new RenderSession(),
            vPosition: $this->vPosition,
            hPosition: $this->hPosition,
            posX: $this->posX,
            posY: $this->posY,
            content: $this->content,
            reducedMotion: $this->reducedMotion,
        );
    }

    /**
     * Return a copy of this veil with a fresh RenderSession.
     *
     * This ensures that when a veil is reused in a stacking context,
     * inner compositing operations always emit FULL frames rather than
     * deltas that would otherwise corrupt the chaining computation.
     */
    public function withoutSession(): self
    {
        return $this->rebuild();
    }
}

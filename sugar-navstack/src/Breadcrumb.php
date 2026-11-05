<?php

declare(strict_types=1);

namespace SugarCraft\NavStack;

use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Mouse\Zone;

/**
 * Renders a NavStack as a breadcrumb string.
 *
 * E.g. "Home › Settings › Display"
 *
 * Can truncate to a max width by dropping the leftmost (oldest) segments.
 *
 * When a {@see Scanner} is attached via {@see withScanner()}, each
 * crumb item is wrapped in a named zone marker so the parent can
 * {@see scan()} to record bounding boxes for mouse routing.
 *
 * **Mutable by design** — `setSeparator`, `setTruncator`, `setMaxWidth`,
 * and `setItemRenderer` mutate `$this` and return `$this` for fluent
 * chaining. This is a deliberate exception to the repo-wide immutable
 * `with*()` convention: the `with*()` methods here DO return new
 * instances, but the `set*` setters mutate in place. Callers must not
 * assume copy-on-write for the `set*` family.
 *
 * **Trust split** — titles taken from the stack (or handed to
 * {@see renderTitles()}) are untrusted and run through
 * {@see TitleSanitizer::sanitize()} — terminal controls, zone sentinels,
 * invisible-formatting spoofers, and CR/LF/tab are stripped before any
 * width math. Output from a custom {@see setItemRenderer()} closure is
 * instead treated as host-theme territory: it passes through UNTOUCHED
 * (sanitizing it would strip the very ANSI styling a theme exists to add).
 * A renderer returning user data verbatim is responsible for sanitizing it
 * itself first.
 *
 * Design antecedent: KevM/bubbleo Breadcrumb (Go); implemented as a
 * native PHP component.
 *
 * @see https://github.com/KevM/bubbleo
 */
final class Breadcrumb
{
    /**
     * Mirrors bubbleo Breadcrumb constructor.
     */
    public static function new(): self
    {
        return new self();
    }

    private string $separator  = ' › ';
    private string $truncator  = '… ';
    private int    $maxWidth   = 0;  // 0 = no limit

    /**
     * Prefix for per-crumb zone ids (`{prefix}-{stackIndex}`).
     * Validated against sugar-mouse's zone-id alphabet so a hostile
     * prefix cannot smuggle sentinel or delimiter bytes into ids.
     */
    private string $zonePrefix = 'crumb';

    /** @var \Closure(NavigationItem, int): ?string */
    private ?\Closure $itemRenderer = null;

    /**
     * Private scanner for mouse-click hit-testing (copied in at
     * {@see withScanner()}), or null if disabled.
     */
    private ?Scanner $scanner = null;

    /**
     * Zone marker helper (lazy init on first use). Stateless beyond its
     * readonly `enabled` flag, so sharing it across clones is harmless.
     */
    private ?Mark $marker = null;

    /**
     * Stack indices the most recent render made visible
     * (see {@see visibleIndices()}); empty before the first render.
     *
     * @var list<int>
     */
    private array $lastVisible = [];

    /** True between a scan() of the current output and the next render. */
    private bool $scanned = false;

    /** Row the last scan() was told the breadcrumb occupies (1-based). */
    private int $scanRowOffset = 0;

    /**
     * Set the string drawn between crumbs (and folded into each zone's
     * click region except after the last crumb).
     *
     * Host ANSI styling survives; sugar-mouse zone sentinels are stripped
     * (a separator carrying `\x{E000}crumb-0\x{E001}` would forge a fake
     * zone into every crumb's rendered row), and multi-line separators are
     * refused because the breadcrumb is a single-row widget.
     *
     * @throws \InvalidArgumentException On an empty or multi-line separator
     */
    public function setSeparator(string $s): self
    {
        $s = Sanitize::stripZoneSentinels($s);
        if ($s === '') {
            throw new \InvalidArgumentException('Breadcrumb separator must be non-empty');
        }
        if (preg_match('/[\r\n]/', $s) === 1) {
            throw new \InvalidArgumentException('Breadcrumb separator must be single-line');
        }
        $this->separator = $s;
        return $this;
    }

    /**
     * Set the elision marker shown in place of dropped leftmost crumbs.
     *
     * An empty truncator is allowed (a silent "older crumbs were dropped"
     * marker); zone sentinels are stripped and CR/LF refused for the same
     * single-line/zone-forgery reasons as {@see setSeparator()}.
     *
     * @throws \InvalidArgumentException On a multi-line truncator
     */
    public function setTruncator(string $s): self
    {
        $s = Sanitize::stripZoneSentinels($s);
        if (preg_match('/[\r\n]/', $s) === 1) {
            throw new \InvalidArgumentException('Breadcrumb truncator must be single-line');
        }
        $this->truncator = $s;
        return $this;
    }

    /**
     * Hard cap on rendered cell width; 0 disables the cap.
     *
     * When the cap is smaller than the truncator marker itself, the marker
     * is dropped so the surviving crumb still receives cells — the cap is
     * never breached and crumbs never render as empty unclickable zones.
     *
     * @throws \InvalidArgumentException On a negative cap
     */
    public function setMaxWidth(int $w): self
    {
        if ($w < 0) {
            throw new \InvalidArgumentException('Breadcrumb maxWidth must be >= 0 (0 disables the cap)');
        }
        $this->maxWidth = $w;
        return $this;
    }

    /**
     * Rename the per-crumb zone id prefix (default `crumb`, ids
     * `crumb-{stackIndex}`). Useful when several breadcrumb widgets share
     * one scanner and need disjoint id namespaces.
     *
     * Restricted to sugar-mouse's zone-id alphabet
     * (`[A-Za-z0-9._:-]`, non-empty) so a prefix can never carry sentinel,
     * delimiter, or control bytes.
     *
     * @throws \InvalidArgumentException On an empty or out-of-alphabet prefix
     */
    public function setZonePrefix(string $prefix): self
    {
        if ($prefix === '' || preg_match('/[^A-Za-z0-9._:-]/', $prefix) === 1) {
            throw new \InvalidArgumentException('Breadcrumb zone prefix must be non-empty and use only [A-Za-z0-9._:-]');
        }
        $this->zonePrefix = $prefix;
        return $this;
    }

    /**
     * Custom per-item renderer. The closure MUST return string|null:
     * fn(NavigationItem $item, int $index): ?string.
     * Return null to fall back to the default (sanitized) title rendering.
     *
     * Returned strings are emitted RAW — see the trust split in the class
     * docblock; theme styling is preserved, sanitizing host data is the
     * closure's own responsibility.
     *
     * Width math counts the returned string AS-IS — SGR escape bytes inside
     * styled output consume the `setMaxWidth()` budget, so a themed trail
     * elides earlier than it visually fills the row.
     *
     * @throws \InvalidArgumentException  If the closure returns a non-string, non-null value
     */
    public function setItemRenderer(\Closure $fn): self
    {
        $this->itemRenderer = $fn;
        return $this;
    }

    /**
     * Attach a {@see Scanner} for mouse-click hit-testing.
     *
     * The scanner is COPIED, never aliased: this breadcrumb and any clones
     * derived from it keep registering zones into the copy, so two
     * breadcrumbs seeded from the same instance can never overwrite each
     * other's `crumb-N` zones. Read the owned scanner back via
     * {@see scanner()} if you need to enumerate zones yourself.
     *
     * When a scanner is attached, each crumb item is wrapped in a named
     * zone marker during render. After rendering, call scan($output) on
     * the scanner to parse zone bounds, then hit($col, $row) to find
     * which crumb was clicked.
     */
    public function withScanner(?Scanner $scanner): self
    {
        $clone         = clone $this;
        $clone->scanner = $scanner === null ? null : clone $scanner;
        $clone->scanned = false;
        return $clone;
    }

    /**
     * The scanner owned by this breadcrumb (as copied in by
     * {@see withScanner()}), or null when click tracking is disabled.
     */
    public function scanner(): ?Scanner
    {
        return $this->scanner;
    }

    /**
     * Feed a rendered breadcrumb string to the internal scanner so that
     * subsequent hit-testing can determine which crumb zone contains
     * a given coordinate pair.
     *
     * Call this after render() returns, before calling hit(). Pass the
     * terminal's viewport width so sugar-mouse clamps zone extents the
     * same way the emulator will wrap the row, and `$row` when the
     * breadcrumb is not painted on terminal row 1 — hit() then takes
     * absolute terminal coordinates. `$row` is 1-based: `0` is coerced to
     * the same offset as `1` (the first row), never a "no row" sentinel.
     *
     * @throws \LogicException When no scanner is attached (the call would
     *                         silently record nothing and every later
     *                         hit() would dead-end)
     */
    public function scan(string $rendered, ?int $viewportWidth = null, int $row = 1): self
    {
        if ($this->scanner === null) {
            throw new \LogicException('Breadcrumb::scan() requires a scanner attached via withScanner()');
        }
        $this->scanner->scan($rendered, $viewportWidth);
        $this->scanRowOffset = max(0, $row - 1);
        $this->scanned       = true;
        return $this;
    }

    /**
     * Return the zone at the given ABSOLUTE terminal coordinate, or null
     * if no crumb zone contains that cell. Without a scanner attached this
     * is a QUIET null no-op (every lookup dead-ends); the loud failure for a
     * missing scanner lives in {@see scan()}.
     *
     * Requires scan() of the current render; {@see render()} and
     * {@see renderTitles()} invalidate the geometry.
     *
     * @throws \LogicException  When a scanner is attached but scan() has
     *                          not run for the current render (a hit
     *                          against stale geometry would misroute)
     */
    public function hit(int $col, int $row): ?Zone
    {
        if ($this->scanner === null) {
            return null;
        }
        if ($this->scanned === false) {
            throw new \LogicException('Breadcrumb::hit() called before scan() of the current render');
        }
        $localRow = $row - $this->scanRowOffset;
        if ($localRow < 1) {
            return null;
        }
        return $this->scanner->hit($col, $localRow);
    }

    /**
     * Resolve an ABSOLUTE terminal coordinate straight to the stack item
     * whose crumb zone contains it — the one-call click dispatcher.
     *
     * Zone ids carry full-stack indices, so the returned item matches the
     * stack position regardless of left-elision; use the caller's own
     * stack (the one rendered) for the lookup.
     */
    public function hitItem(NavStack $stack, int $col, int $row): ?NavigationItem
    {
        $zone = $this->hit($col, $row);
        if ($zone === null) {
            return null;
        }
        $pattern = '/\A' . preg_quote($this->zonePrefix, '/') . '-(\d+)\z/';
        if (preg_match($pattern, $zone->id, $m) !== 1) {
            return null;
        }
        return $stack->items()[(int) $m[1]] ?? null;
    }

    /**
     * Stack indices made visible by the most recent render (empty before
     * the first render, and after a render of an empty stack). When
     * {@see setMaxWidth()} elides older crumbs, these are the survivors'
     * true positions — exactly the indices zone ids carry.
     *
     * @return list<int>
     */
    public function visibleIndices(): array
    {
        return $this->lastVisible;
    }

    /**
     * Render the current navigation stack as a breadcrumb string.
     *
     * Mirrors bubbleo Breadcrumb.render.
     */
    public function render(NavStack $stack): string
    {
        $items = $stack->items();
        if ($items === []) {
            $this->lastVisible = [];
            $this->scanned     = false;
            return '';
        }

        $titles = [];
        foreach ($items as $i => $item) {
            $title = $this->itemRenderer !== null
                ? ($this->itemRenderer)($item, $i)
                : null;

            if ($title !== null && \is_string($title) === false) {
                throw new \InvalidArgumentException('itemRenderer must return string|null');
            }

            // Default fallback path carries the UNTRUSTED stack title and
            // is sanitized; a renderer's own string is host-theme territory
            // and passes through raw (trust split — see class docblock).
            $titles[] = $title ?? TitleSanitizer::sanitize($item->title);
        }

        return $this->doRender($titles);
    }

    /**
     * Render a custom list of titles (not from a NavStack).
     *
     * Inputs are treated like stack titles: untrusted, sanitized at this
     * boundary before width math and zone wrapping. Note these bare
     * strings bypass {@see NavigationItem}'s constructor guards entirely —
     * the MAX_TITLE_BYTES ingest bound exists to keep oversized text out
     * of Mark::wrap on the STACK path and does not hold here; a host that
     * forwards attacker-controlled lists must bound them itself.
     *
     * @param list<string> $titles
     */
    public function renderTitles(array $titles): string
    {
        if ($titles === []) {
            $this->lastVisible = [];
            $this->scanned     = false;
            return '';
        }
        return $this->doRender(\array_map([TitleSanitizer::class, 'sanitize'], $titles));
    }

    /**
     * Shared render logic — handles truncate then zone-wrap.
     *
     * Titles arrive already sanitized (render()/renderTitles() boundaries);
     * re-sanitizing here would destroy a custom renderer's ANSI styling.
     *
     * @param list<string> $titles  Items in display order (oldest→newest), sanitized
     */
    private function doRender(array $titles): string
    {
        $this->scanned = false;

        $prefix      = '';
        $visibleFrom = 0;   // stack index of the first surviving crumb
        $all         = $titles;

        // Truncate from the left if too wide
        if ($this->maxWidth > 0 && $this->effectiveWidth(\implode($this->separator, $titles)) > $this->maxWidth) {
            [$titles, $elided] = $this->truncate($titles);
            $prefix      = $elided === true ? $this->truncator : '';
            $visibleFrom = \count($all) - \count($titles);
        }

        // Hard-cap enforcement. Left-dropping bottoms out at the single
        // most-recent crumb; when even prefix+that crumb overflows, the
        // crumb is ellipsized to the remaining budget — and if the marker
        // alone already breaches the cap, it is dropped so the survivor
        // still gets cells (setMaxWidth is a cap that is never breached,
        // and no crumb ever renders empty/unclickable).
        $result = $prefix . \implode($this->separator, $titles);
        if ($this->maxWidth > 0 && $this->effectiveWidth($result) > $this->maxWidth) {
            if (\count($titles) === 1) {
                if ($this->effectiveWidth($prefix) < $this->maxWidth) {
                    $titles = [$this->truncateSegment($titles[0], $this->maxWidth - $this->effectiveWidth($prefix))];
                } else {
                    $prefix = '';
                    $titles = [$this->truncateSegment($titles[0], $this->maxWidth)];
                }
            } else {
                // Unreachable via truncate() (its loop only grows while the
                // marker-prefixed join fits; the >1-crumbs breach corner is
                // pinned in ZoneGeometryTest). This is a belt-brace against a
                // future truncate() change, NOT the operative guard: the
                // branch drops the prefix without re-clamping the survivors.
                $prefix = '';
            }
            $result = $prefix . \implode($this->separator, $titles);
        }

        // Record the survivors' full-stack positions for visibleIndices():
        // either the whole stack, or the contiguous newest tail truncate()
        // kept (its loop breaks at the first non-fit, so survivors are a
        // suffix, never a scattering).
        $total = \count($all);
        $this->lastVisible = $visibleFrom > 0
            ? \range($visibleFrom, $total - 1)
            : \range(0, $total - 1);

        // Wrap each crumb in a zone marker when a scanner is attached. The
        // truncator prefix rides OUTSIDE the zone wrapping (it is not a
        // clickable crumb) and the zones carry stack indices, so a
        // "crumb-N" hit always dispatches to stack position N even after
        // left-elision renumbers what is visible.
        if ($this->scanner !== null) {
            $result = $prefix . $this->wrapAllCrumbs($titles, $visibleFrom);
        }

        return $result;
    }

    /**
     * Ellipsis-truncate a single crumb so its visible width fits $budget.
     * Grapheme-aware via sugar-core Width. Used only for the lone surviving
     * (most-recent) crumb when even it alone overflows maxWidth.
     */
    private function truncateSegment(string $segment, int $budget): string
    {
        if ($budget <= 0) {
            return '';
        }
        if ($this->effectiveWidth($segment) <= $budget) {
            return $segment;
        }
        $ellipsis      = '…';
        $ellipsisWidth = $this->effectiveWidth($ellipsis);
        if ($ellipsisWidth >= $budget) {
            // No room for content beside the ellipsis — hard-cut to budget.
            return Width::truncate($segment, $budget);
        }
        return Width::truncate($segment, $budget - $ellipsisWidth) . $ellipsis;
    }

    /**
     * Truncate titles to fit within maxWidth, returning the surviving titles.
     * Items are kept from most-recent to oldest until they fit.
     *
     * Deliberate divergence from bubbleo Breadcrumb.truncate: upstream
     * keeps the last crumb verbatim and can exceed the width budget; this
     * port treats maxWidth as a hard cap (doRender() finishes the job).
     *
     * @param list<string> $titles  Items in display order (oldest→newest)
     * @return array{list<string>, bool}  [kept titles oldest→newest, whether any were elided]
     */
    private function truncate(array $titles): array
    {
        // Start from the end (most recent) and prepend older items until we fit
        $out = [\end($titles)];
        for ($i = \count($titles) - 2; $i >= 0; $i--) {
            $candidate = $this->truncator . \implode($this->separator, \array_merge([$titles[$i]], \array_reverse($out)));
            if ($this->effectiveWidth($candidate) <= $this->maxWidth) {
                $out[] = $titles[$i];
            } else {
                break;
            }
        }

        // $out is ordered newest→oldest; reverse to oldest→newest for output
        $reversed = \array_reverse($out);
        $elided = \count($out) < \count($titles);

        return [$reversed, $elided];
    }

    /**
     * Wrap each crumb item in a named zone marker.
     *
     * Each item is individually wrapped so click coordinates map back
     * to the correct crumb. Zone names carry the crumb's position in the
     * FULL stack (`{prefix}-$stackOffset`, incremented per visible crumb),
     * not its position among the survivors — after setMaxWidth() elides
     * the oldest crumbs, a hit on the leftmost visible crumb still
     * reports its true stack index, so the caller's popTo($index)
     * dispatch cannot silently misroute.
     *
     * The separator after each non-final crumb is folded INTO that zone's
     * content so the gutter users instinctively click ("Home ›") routes to
     * the crumb on its left instead of dead-zoning. Width math is
     * unaffected: the same cells render either way and cap accounting
     * happens pre-wrap.
     *
     * Uses sugar-mouse Mark::wrap() internally — no external Manager needed.
     *
     * @param list<string> $titles      Visible items in display order (oldest→newest)
     * @param int          $stackOffset Index of the first visible item in the full stack
     */
    private function wrapAllCrumbs(array $titles, int $stackOffset = 0): string
    {
        if ($this->marker === null) {
            $this->marker = new Mark();
        }
        $last = \count($titles) - 1;
        $wrapped = [];
        foreach ($titles as $i => $title) {
            $content = $title . ($i < $last ? $this->separator : '');
            $wrapped[] = $this->marker->wrap($this->zonePrefix . '-' . ($stackOffset + $i), $content);
        }
        return \implode('', $wrapped);
    }

    /** Visible cell width — delegates to sugar-core's grapheme-aware util. */
    private function effectiveWidth(string $s): int
    {
        return Width::string($s);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\NavStack;

use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Scanner;

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

    public function setSeparator(string $s): self
    {
        if ($s === '') {
            throw new \InvalidArgumentException('Breadcrumb separator must be non-empty and single-line');
        }
        if (preg_match('/[\r\n]/', $s) === 1) {
            throw new \InvalidArgumentException('Breadcrumb separator must be non-empty and single-line');
        }
        $this->separator = $s;
        return $this;
    }

    public function setTruncator(string $s): self
    {
        $this->truncator = $s;
        return $this;
    }

    public function setMaxWidth(int $w): self
    {
        $this->maxWidth = $w;
        return $this;
    }

    /**
     * Custom per-item renderer. The closure MUST return string|null:
     * fn(NavigationItem $item, int $index): ?string.
     * Return null to fall back to the default title-based rendering.
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
        $clone = clone $this;
        $clone->scanner = $scanner === null ? null : clone $scanner;
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
     * Call this after render() returns, before calling hit().
     */
    public function scan(string $rendered): self
    {
        $this->scanner?->scan($rendered);
        return $this;
    }

    /**
     * Return the zone at the given terminal coordinate, or null if no
     * crumb zone contains that cell.
     *
     * Requires scan() to have been called after the last render.
     */
    public function hit(int $col, int $row): ?\SugarCraft\Mouse\Zone
    {
        return $this->scanner?->hit($col, $row);
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

            if ($title === null) {
                $title = $item->title;
            }

            $titles[] = $title;
        }

        return $this->doRender($titles);
    }

    /**
     * Render a custom list of titles (not from a NavStack).
     *
     * @param list<string> $titles
     */
    public function renderTitles(array $titles): string
    {
        if ($titles === []) {
            return '';
        }
        return $this->doRender($titles);
    }

    /**
     * Shared render logic — handles truncate then zone-wrap.
     *
     * @param list<string> $titles  Items in display order (oldest→newest)
     */
    private function doRender(array $titles): string
    {
        // Neutralize terminal-control-sequence injection from untrusted titles
        // (Shell::pushDirectory / Url::parse segments are user-controlled)
        // BEFORE any width math, truncation, or zone-wrapping — otherwise a raw
        // ESC/newline both desyncs the render and skews width accounting.
        $titles = \array_map([self::class, 'sanitizeTitle'], $titles);

        $prefix       = '';
        $visibleFrom  = 0;   // stack index of the first surviving crumb
        $all          = $titles;

        // Truncate from the left if too wide
        if ($this->maxWidth > 0 && $this->effectiveWidth(\implode($this->separator, $titles)) > $this->maxWidth) {
            [$titles, $elided] = $this->truncate($titles);
            $prefix      = $elided === true ? $this->truncator : '';
            $visibleFrom = \count($all) - \count($titles);
            $result      = $prefix . \implode($this->separator, $titles);

            // Left-dropping bottoms out at the single most-recent crumb; if even
            // that lone crumb overflows, ellipsis-truncate it so setMaxWidth() is
            // a hard cap (upstream keeps the last crumb verbatim — the bug here).
            if (\count($titles) === 1 && $this->effectiveWidth($result) > $this->maxWidth) {
                $titles = [$this->truncateSegment($titles[0], $this->maxWidth - $this->effectiveWidth($prefix))];
                $result = $prefix . $titles[0];
            }
        } else {
            $result = \implode($this->separator, $titles);
        }

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
     * Full ANSI + C0/C1 strip via sugar-core Sanitize (SSOT), then collapse the
     * whitespace controls it deliberately preserves (\n \r \t) to spaces so an
     * untrusted title cannot inject a newline into the single-line breadcrumb.
     */
    private static function sanitizeTitle(string $title): string
    {
        return \str_replace(["\r\n", "\r", "\n", "\t"], ' ', Sanitize::untrusted($title));
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
     * Mirrors bubbleo Breadcrumb.truncate.
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
     * FULL stack (`crumb-$stackOffset`, incremented per visible crumb),
     * not its position among the survivors — after setMaxWidth() elides
     * the oldest crumbs, a hit on the leftmost visible crumb still
     * reports its true stack index, so the caller's popTo($index)
     * dispatch cannot silently misroute.
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
        $wrapped = [];
        foreach ($titles as $i => $title) {
            $wrapped[] = $this->marker->wrap("crumb-" . ($stackOffset + $i), $title);
        }
        return \implode($this->separator, $wrapped);
    }

    /** Visible cell width — delegates to sugar-core's grapheme-aware util. */
    private function effectiveWidth(string $s): int
    {
        return Width::string($s);
    }
}

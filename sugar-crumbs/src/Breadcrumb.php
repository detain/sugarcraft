<?php

declare(strict_types=1);

namespace SugarCraft\Crumbs;

use SugarCraft\Core\Util\Width;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Zone\Manager;

/**
 * Renders a NavStack as a breadcrumb string.
 *
 * E.g. "Home › Settings › Display"
 *
 * Can truncate to a max width by dropping the leftmost (oldest) segments.
 *
 * When a {@see Manager} is attached via {@see withZoneManager()}, each
 * crumb item is wrapped in a named APC zone marker so the parent can
 * {@see Manager::scan()} to record bounding boxes for mouse routing.
 *
 * **Mutable by design** — `setSeparator`, `setTruncator`, `setMaxWidth`,
 * `setItemRenderer`, `withScanner`, and `withZoneManager` mutate `$this`
 * and return `$this` for fluent chaining. This is a deliberate exception to
 * the repo-wide immutable `with*()` convention: the `with*()` methods here
 * DO return new instances, but the `set*` setters mutate in place.
 * Callers must not assume copy-on-write for the `set*` family.
 *
 * Port of KevM/bubbleo Breadcrumb.
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

    /** @var \Closure(NavigationItem, int): ?string|null */
    private ?\Closure $itemRenderer = null;

    /** Self-contained scanner for mouse-click hit-testing, or null if disabled. */
    private ?Scanner $scanner = null;

    /** Zone marker helper (lazy init on first use) */
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
     * Attach a {@see Manager} for mouse-click zone tracking.
     *
     * @deprecated Internally delegates to a self-contained Scanner (same as
     *   {@see withScanner()}). Pass a Manager instance to get zone markers
     *   rendered; pass null to detach. Prefer {@see withScanner()} directly.
     */
    public function withZoneManager(?Manager $manager): self
    {
        $clone = clone $this;
        if ($manager !== null) {
            $clone->scanner = $clone->scanner ?? Scanner::new();
        } else {
            $clone->scanner = null;
        }
        return $clone;
    }

    /**
     * Attach a self-contained {@see Scanner} for mouse-click hit-testing.
     *
     * When a scanner is attached, each crumb item is wrapped in a named
     * zone marker during render. After rendering, call scan($output) on
     * the scanner to parse zone bounds, then hit($col, $row) to find
     * which crumb was clicked.
     */
    public function withScanner(?Scanner $scanner): self
    {
        $clone = clone $this;
        $clone->scanner = $scanner;
        return $clone;
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

            if ($title !== null && !\is_string($title)) {
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
        if ($titles === []) return '';
        return $this->doRender($titles);
    }

    /**
     * Shared render logic — handles truncate then zone-wrap.
     *
     * @param list<string> $titles  Items in display order (oldest→newest)
     */
    private function doRender(array $titles): string
    {
        // Truncate from the left if too wide
        if ($this->maxWidth > 0 && $this->effectiveWidth(\implode($this->separator, $titles)) > $this->maxWidth) {
            [$titles, $elided] = $this->truncate($titles);
            $result = ($elided ? $this->truncator : '') . \implode($this->separator, $titles);
        } else {
            $result = \implode($this->separator, $titles);
        }

        // Wrap each crumb in a zone marker when a scanner is attached.
        if ($this->scanner !== null) {
            $result = $this->wrapAllCrumbs($titles);
        }

        return $result;
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
     * to the correct crumb. Used after the final item list is known
     * (post-truncation).
     *
     * Uses candy-mouse Mark::wrap() internally — no external Manager needed.
     *
     * @param list<string> $titles  Items in display order (oldest→newest)
     */
    private function wrapAllCrumbs(array $titles): string
    {
        if ($this->marker === null) {
            $this->marker = new Mark();
        }
        $wrapped = [];
        foreach ($titles as $i => $title) {
            $wrapped[] = $this->marker->wrap("crumb-{$i}", $title);
        }
        return \implode($this->separator, $wrapped);
    }

    /** Visible cell width — delegates to candy-core's grapheme-aware util. */
    private function effectiveWidth(string $s): int
    {
        return Width::string($s);
    }
}

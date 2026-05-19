<?php

declare(strict_types=1);

namespace SugarCraft\Crumbs;

use SugarCraft\Core\Util\Width;
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
 * Port of KevM/bubbleo Breadcrumb.
 *
 * @see https://github.com/KevM/bubbleo
 */
final class Breadcrumb
{
    private string $separator  = ' › ';
    private string $truncator  = '… ';
    private int    $maxWidth   = 0;  // 0 = no limit

    /** @var \Closure(NavigationItem, int): string|null */
    private ?\Closure $itemRenderer = null;

    /** Zone manager for mouse-click tracking, or null if disabled. */
    private ?Manager $zoneManager = null;

    public function setSeparator(string $s): self
    {
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
     * Custom per-item renderer: fn(NavigationItem $item, int $index): ?string
     * Return null to use the default title-based rendering.
     */
    public function setItemRenderer(\Closure $fn): self
    {
        $this->itemRenderer = $fn;
        return $this;
    }

    /**
     * Attach a {@see Manager} for mouse-click zone tracking.
     *
     * When a manager is attached, each crumb item is wrapped in a named
     * APC zone (`crumb-0`, `crumb-1`, …). The parent should call
     * `Manager::scan()` on the render output to record zone bounds,
     * then route {@see \SugarCraft\Core\Msg\MouseMsg} through
     * `Manager::anyInBoundsAndUpdate()`.
     */
    public function withZoneManager(?Manager $manager): self
    {
        $clone = clone $this;
        $clone->zoneManager = $manager;
        return $clone;
    }

    /**
     * Render the current navigation stack as a breadcrumb string.
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
        $result = \implode($this->separator, $titles);

        // Truncate from the left if too wide
        if ($this->maxWidth > 0 && $this->effectiveWidth($result) > $this->maxWidth) {
            $result = $this->truncate($titles);
            // After truncation, $titles may be reduced to fit; rebuild from result
            $titles = $this->titlesFromTruncatedResult($result);
        }

        // Wrap each crumb in a zone marker when a manager is attached.
        if ($this->zoneManager !== null) {
            $result = $this->wrapAllCrumbs($titles);
        }

        return $result;
    }

    /**
     * Truncate titles to fit within maxWidth, returning the formatted string.
     * Items are kept from most-recent to oldest until they fit.
     *
     * @param list<string> $titles
     */
    private function truncate(array $titles): string
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
        $result = \implode($this->separator, $reversed);

        // If any titles were dropped, prefix the truncator so the elision is visible
        if (\count($out) < \count($titles)) {
            $result = $this->truncator . $result;
        }

        return $result;
    }

    /**
     * Parse the truncated result string back into a titles array.
     * Strips the leading truncator prefix if present, then splits on separator.
     */
    private function titlesFromTruncatedResult(string $result): array
    {
        if ($result !== '' && $result[0] === $this->truncator[0]) {
            $result = \substr($result, \strlen($this->truncator));
        }
        if ($result === '') {
            return [];
        }
        return \explode($this->separator, $result);
    }

    /**
     * Wrap each crumb item in a named APC zone marker.
     *
     * Each item is individually wrapped so click coordinates map back
     * to the correct crumb. Used after the final item list is known
     * (post-truncation).
     *
     * @param list<string> $titles  Items in display order (oldest→newest)
     */
    private function wrapAllCrumbs(array $titles): string
    {
        $wrapped = [];
        foreach ($titles as $i => $title) {
            $wrapped[] = $this->zoneManager->mark("crumb-{$i}", $title);
        }
        return \implode($this->separator, $wrapped);
    }

    /** Visible cell width — delegates to candy-core's grapheme-aware util. */
    private function effectiveWidth(string $s): int
    {
        return Width::string($s);
    }
}

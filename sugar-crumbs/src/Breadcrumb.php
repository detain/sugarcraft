<?php

declare(strict_types=1);

namespace CandyCore\Crumbs;

/**
 * Renders a NavStack as a breadcrumb string.
 *
 * E.g. "Home › Settings › Display"
 *
 * Can truncate to a max width by dropping the leftmost (oldest) segments.
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

    /** @var callable(NavigationItem, int): string|null */
    private ?callable $itemRenderer = null;

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
    public function setItemRenderer(callable $fn): self
    {
        $this->itemRenderer = $fn;
        return $this;
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

        $result = \implode($this->separator, $titles);

        // Truncate from the left if too wide
        if ($this->maxWidth > 0 && $this->effectiveWidth($result) > $this->maxWidth) {
            $result = $this->truncate($titles);
        }

        return $result;
    }

    /**
     * Render a custom list of titles (not from a NavStack).
     *
     * @param list<string> $titles
     */
    public function renderTitles(array $titles): string
    {
        if ($titles === []) return '';

        $result = \implode($this->separator, $titles);

        if ($this->maxWidth > 0 && $this->effectiveWidth($result) > $this->maxWidth) {
            $result = $this->truncate($titles);
        }

        return $result;
    }

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

        return \implode($this->separator, \array_reverse($out));
    }

    private function effectiveWidth(string $s): int
    {
        return \strlen(\preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $s) ?: '');
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout\Boxer;

use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Width;

/**
 * A node in the boxer layout tree.
 *
 * Based on the bubbleboxer Node pattern.
 * Nodes can be either:
 * - Leaf nodes: have an address and no children, contain an Item
 * - Branch nodes: have children and no address, arranged either horizontally or vertically
 *
 * @see https://github.com/charmbracelet/boxer
 */
final class Node implements Item
{
    public const HORIZONTAL_SEPARATOR = '│';
    public const VERTICAL_SEPARATOR = '─';
    public const SPACE = ' ';
    public const NEWLINE = "\n";

    /**
     * @param list<Node> $children Child nodes
     * @param bool $verticalStacked If true, children are stacked vertically; if false, arranged horizontally
     * @param \Closure|null $sizeFunc Custom size distribution function. Null means even split.
     * @param bool $noBorder If true, no border is drawn between children
     * @param string $address Address string for leaf nodes, empty string for branch nodes
     */
    public function __construct(
        private readonly array $children = [],
        private readonly bool $verticalStacked = false,
        private readonly ?\Closure $sizeFunc = null,
        private readonly bool $noBorder = false,
        private readonly string $address = '',
        private int $width = 0,
        private int $height = 0,
    ) {}

    // ─── Factory Methods ───────────────────────────────────────────

    /**
     * Create a leaf node with the given address.
     *
     * @param string $address Dotted notation address (e.g., "0", "0.1.2")
     */
    public static function leaf(string $address): self
    {
        return new self(
            children: [],
            verticalStacked: false,
            sizeFunc: null,
            noBorder: true,
            address: $address,
        );
    }

    /**
     * Create a horizontal branch node (children arranged side by side).
     *
     * By default, no border is drawn between children. Use withBorder() to change.
     *
     * @param Node ...$children
     */
    public static function horizontal(Node ...$children): self
    {
        return new self($children, false, null, true);
    }

    /**
     * Create a vertical branch node (children stacked top to bottom).
     *
     * By default, no border is drawn between children. Use withBorder() to change.
     *
     * @param Node ...$children
     */
    public static function vertical(Node ...$children): self
    {
        return new self($children, true, null, true);
    }

    /**
     * Create a node with a custom size function.
     *
     * @param \Closure(Node, int): list<int> $sizeFunc
     */
    public static function withSizeFunc(\Closure $sizeFunc, Node ...$children): self
    {
        return new self($children, false, $sizeFunc);
    }

    /**
     * Create a node that doesn't draw borders.
     */
    public static function noBorder(Node ...$children): self
    {
        $node = new self($children, false);
        // We need a different approach since noBorder needs to be set
        return new self(
            children: $children,
            verticalStacked: false,
            sizeFunc: null,
            noBorder: true,
            address: '',
        );
    }

    // ─── Accessors ─────────────────────────────────────────────────

    /**
     * @return list<Node>
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    public function isLeaf(): bool
    {
        return $this->address !== '';
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function isVerticalStacked(): bool
    {
        return $this->verticalStacked;
    }

    public function hasNoBorder(): bool
    {
        return $this->noBorder;
    }

    // ─── Size Mutation ─────────────────────────────────────────────

    /**
     * Create a new node with updated dimensions.
     */
    public function setSize(int $width, int $height): self
    {
        if ($width === $this->width && $height === $this->height) {
            return $this;
        }

        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    // ─── Tree Operations ───────────────────────────────────────────

    /**
     * Create a new node with updated children.
     *
     * @param list<Node> $children
     */
    public function withChildren(array $children): self
    {
        return new self(
            children: $children,
            verticalStacked: $this->verticalStacked,
            sizeFunc: $this->sizeFunc,
            noBorder: $this->noBorder,
            address: $this->address,
            width: $this->width,
            height: $this->height,
        );
    }

    /**
     * Create a new node with a different orientation.
     */
    public function withVerticalStacked(bool $verticalStacked): self
    {
        return new self(
            children: $this->children,
            verticalStacked: $verticalStacked,
            sizeFunc: $this->sizeFunc,
            noBorder: $this->noBorder,
            address: $this->address,
            width: $this->width,
            height: $this->height,
        );
    }

    /**
     * Create a new node with a custom size function.
     *
     * @param \Closure|null $sizeFunc
     */
    public function withCustomSizeFunc(?\Closure $sizeFunc): self
    {
        return new self(
            children: $this->children,
            verticalStacked: $this->verticalStacked,
            sizeFunc: $sizeFunc,
            noBorder: $this->noBorder,
            address: $this->address,
            width: $this->width,
            height: $this->height,
        );
    }

    /**
     * Create a new node with borders enabled or disabled between children.
     */
    public function withBorder(bool $noBorder): self
    {
        return new self(
            children: $this->children,
            verticalStacked: $this->verticalStacked,
            sizeFunc: $this->sizeFunc,
            noBorder: $noBorder,
            address: $this->address,
            width: $this->width,
            height: $this->height,
        );
    }

    // ─── Size Propagation ──────────────────────────────────────────

    /**
     * Update the size of this node and its children recursively.
     *
     * @return Node The updated node (may be a new instance)
     */
    public function updateSizeRecursive(int $totalWidth, int $totalHeight): self
    {
        // Set own size first
        $node = $this->setSize($totalWidth, $totalHeight);

        // Reduce size for border between children
        if (!$node->noBorder && count($node->children) > 0) {
            $childCount = count($node->children);
            if ($node->verticalStacked) {
                $totalHeight -= ($childCount - 1);
            } else {
                $totalWidth -= ($childCount - 1);
            }
        }

        // Check if we have enough space
        if ($totalWidth <= 0 || $totalHeight <= 0) {
            return $node;
        }

        // If leaf, we're done (size was set above)
        if ($node->isLeaf()) {
            return $node;
        }

        // Branch node - distribute size to children
        $children = $node->children;
        $childCount = count($children);

        if ($childCount === 0) {
            return $node;
        }

        // Even split if no sizeFunc
        if ($node->sizeFunc === null) {
            $newChildren = [];

            if ($node->verticalStacked) {
                $baseHeight = intdiv($totalHeight, $childCount);
                $remainder = $totalHeight % $childCount;

                for ($i = 0; $i < $childCount; $i++) {
                    $childH = $baseHeight + ($i < $remainder ? 1 : 0);
                    $newChildren[$i] = $children[$i]->updateSizeRecursive($totalWidth, $childH);
                }
            } else {
                $baseWidth = intdiv($totalWidth, $childCount);
                $remainder = $totalWidth % $childCount;

                for ($i = 0; $i < $childCount; $i++) {
                    $childW = $baseWidth + ($i < $remainder ? 1 : 0);
                    $newChildren[$i] = $children[$i]->updateSizeRecursive($childW, $totalHeight);
                }
            }

            return $node->withChildren($newChildren);
        }

        // Custom sizeFunc
        $sizeList = ($node->sizeFunc)($node, $node->verticalStacked ? $totalHeight : $totalWidth);

        if (count($sizeList) !== $childCount) {
            throw new \RuntimeException(sprintf(
                'SizeFunc returned %d sizes but node has %d children',
                count($sizeList),
                $childCount
            ));
        }

        $newChildren = [];
        for ($i = 0; $i < $childCount; $i++) {
            $s = $sizeList[$i];
            if ($node->verticalStacked) {
                $newChildren[$i] = $children[$i]->updateSizeRecursive($totalWidth, $s);
            } else {
                $newChildren[$i] = $children[$i]->updateSizeRecursive($s, $totalHeight);
            }
        }

        return $node->withChildren($newChildren);
    }

    // ─── Rendering ─────────────────────────────────────────────────

    /**
     * Render this node and its children to a list of lines.
     *
     * @param array<string, Item> $modelMap Map of address to content items
     * @return list<string>|SizeError
     */
    public function renderTree(array $modelMap, int $width, int $height): array|string
    {
        $node = $this->setSize($width, $height);

        // If leaf, render the content from modelMap
        if ($node->isLeaf()) {
            if (!isset($modelMap[$node->address])) {
                return new SizeError("model for leaf with address: '{$node->address}' not found");
            }

            $model = $modelMap[$node->address];
            $rendered = $model instanceof Sizer
                ? $model->setSize($node->width, $node->height)->render()
                : $model->render();

            $lines = explode(self::NEWLINE, $rendered);

            // Validate line count
            if (count($lines) > $node->height) {
                return new SizeError(sprintf(
                    'expecting less or equal to %d lines, but the Model with address \'%s\' has returned too many lines: %d',
                    $node->height,
                    $node->address,
                    count($lines)
                ));
            }

            // Validate line widths and pad to width
            foreach ($lines as $idx => $line) {
                $lineWidth = Width::string($line);
                if ($lineWidth > $node->width) {
                    return new SizeError(sprintf(
                        'expecting less or equal to %d character width, but line %d has %d characters: \'%s\'',
                        $node->width,
                        $idx,
                        $lineWidth,
                        $line
                    ));
                }
                // Pad line to full width
                $lines[$idx] = $line . str_repeat(self::SPACE, $node->width - $lineWidth);
            }

            return $lines;
        }

        // Branch node
        if ($node->verticalStacked) {
            return $node->renderVertical($modelMap);
        }

        return $node->renderHorizontal($modelMap);
    }

    /**
     * Render vertically stacked children.
     *
     * @param array<string, Item> $modelMap
     * @return list<string>|SizeError
     */
    private function renderVertical(array $modelMap): array|string
    {
        $childCount = count($this->children);
        if ($childCount === 0) {
            return new SizeError('no children to render - this node should be a leaf or should not exist');
        }

        $targetWidth = $this->children[0]->width;
        $boxes = [];

        for ($i = 0; $i < $childCount; $i++) {
            $child = $this->children[$i];

            if ($child->width !== $targetWidth) {
                return new SizeError('inconsistent size information: all children should have the same width when vertical arranged');
            }

            $lines = $child->renderTree($modelMap, $child->width, $child->height);
            if ($lines instanceof \Throwable) {
                return $this->wrapError($i, true, $lines);
            }

            // Add border separator between children (except first)
            if (!$this->noBorder && $i > 0) {
                $boxes[] = str_repeat(self::VERTICAL_SEPARATOR, $targetWidth);
            }

            // Pad short lines to target width
            foreach ($lines as $idx => $line) {
                $lineWidth = Width::string($line);
                if ($lineWidth > $targetWidth) {
                    return $this->wrapError($idx, true, new \RuntimeError('line too wide'));
                }
                $lines[$idx] = $line . str_repeat(self::SPACE, $targetWidth - $lineWidth);
            }

            $boxes = array_merge($boxes, $lines);

            // Pad to child's height if content is shorter than allocated height
            $paddingNeeded = $child->height - count($lines);
            for ($p = 0; $p < $paddingNeeded; $p++) {
                $boxes[] = str_repeat(self::SPACE, $targetWidth);
            }
        }

        return $boxes;
    }

    /**
     * Render horizontally arranged children.
     *
     * @param array<string, Item> $modelMap
     * @return list<string>|SizeError
     */
    private function renderHorizontal(array $modelMap): array|string
    {
        $childCount = count($this->children);
        if ($childCount === 0) {
            return new SizeError('no children to render - this node should be a leaf or should not exist');
        }

        $targetHeight = $this->children[0]->height;

        // Collect all child lines
        $childLines = [];
        for ($i = 0; $i < $childCount; $i++) {
            $child = $this->children[$i];

            if ($child->height !== $targetHeight) {
                return new SizeError('inconsistent size information: all children should have the same height when horizontal arranged');
            }

            $lines = $child->renderTree($modelMap, $child->width, $child->height);
            if ($lines instanceof \Throwable) {
                return $this->wrapError($i, false, $lines);
            }

            // Pad to target height
            while (count($lines) < $targetHeight) {
                $lines[] = str_repeat(self::SPACE, $child->width);
            }

            $childLines[] = $lines;
        }

        // Join lines horizontally
        $result = [];
        for ($y = 0; $y < $targetHeight; $y++) {
            $fullLine = [];
            for ($x = 0; $x < $childCount; $x++) {
                $line = $childLines[$x][$y];
                $lineWidth = Width::string($line);

                // Validate width
                if ($lineWidth > $this->children[$x]->width) {
                    return new SizeError("model has a too wide line: {$line}");
                }

                // Pad to child's width
                $padded = $line . str_repeat(self::SPACE, $this->children[$x]->width - $lineWidth);
                $fullLine[] = $padded;
            }

            // Join with border
            $separator = $this->noBorder ? '' : self::HORIZONTAL_SEPARATOR;
            $result[] = implode($separator, $fullLine);
        }

        return $result;
    }

    /**
     * Wrap an error with context about which child and layout type caused it.
     */
    private function wrapError(int $index, bool $vertical, \Throwable $error): SizeError
    {
        $layout = $vertical ? 'vertical' : 'horizontal';
        return new SizeError("while rendering the {$index} child of a {$layout} node: {$error->getMessage()}");
    }

    /**
     * Dummy render for Item interface - use render() with modelMap instead.
     */
    public function render(): string
    {
        return '';
    }
}

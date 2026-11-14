<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Layout\Boxer;

use SugarCraft\Dashboard\Foundation\Drawable;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\Sizer;
use SugarCraft\Dashboard\Foundation\Theme;
use SugarCraft\Dashboard\Layout\Support\PersistState;
use SugarCraft\Dashboard\State\Persistence;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;

/**
 * A tree-based layout engine for rendering multiple Items in a hierarchical structure.
 *
 * Mirrors the bubbleboxer Boxer type. Items are stored separately from the layout tree
 * so that changing an Item does not require traversing the LayoutTree.
 *
 * @see https://github.com/charmbracelet/boxer
 */
final class Boxer implements Item, Sizer
{
    /** @var array<string, Item> */
    private readonly array $modelMap;

    /**
     * @param Node $root Root node of the layout tree
     * @param array<string, Item> $modelMap Map of address string to rendered content
     * @param int $width Current allocated width
     * @param int $height Current allocated height
     */
    public function __construct(
        private readonly Node $root,
        array $modelMap = [],
        private readonly int $width = 0,
        private readonly int $height = 0,
    ) {
        // Every leaf address the tree declares must be editable from
        // birth: seed blanks for the ones the host has not supplied yet
        // (finding sugar-dashboard-033 — the factory path used to throw
        // NotFoundError on addresses the factory itself announced).
        $this->modelMap = self::seedDeclaredLeaves($root, $modelMap);
    }

    /**
     * Fill every declared leaf address missing from the map with a blank.
     *
     * @param array<string, Item> $modelMap
     * @return array<string, Item>
     */
    private static function seedDeclaredLeaves(Node $node, array $modelMap): array
    {
        if ($node->isLeaf()) {
            if (!isset($modelMap[$node->address()])) {
                $modelMap[$node->address()] = new PlaceholderItem();
            }
            return $modelMap;
        }

        foreach ($node->children() as $child) {
            $modelMap = self::seedDeclaredLeaves($child, $modelMap);
        }

        return $modelMap;
    }

    // ─── Factory Methods ───────────────────────────────────────────

    /**
     * Create a new Boxer with a single leaf node.
     *
     * @param string $address Unique address for the leaf (e.g., "0" or "0.1.2")
     * @param Item $model The content item for this leaf
     */
    public static function leaf(string $address, Item $model): self
    {
        if ($address === '') {
            throw new \InvalidArgumentException('Address must not be empty');
        }

        $node = Node::leaf($address);
        return new self($node, [$address => $model]);
    }

    /**
     * Create a new Boxer with a tree structure of nodes.
     *
     * @param array<string, Item> $modelMap Map of address to content items
     */
    public static function tree(Node $root, array $modelMap = []): self
    {
        return new self($root, $modelMap);
    }

    /**
     * Create a horizontal layout with children arranged side by side.
     *
     * Declared leaf children are blank-seeded: editLeaf() works on their
     * addresses immediately (see {@see self::editLeaf()}).
     *
     * @param Node ...$children
     */
    public static function horizontal(Node ...$children): self
    {
        return new self(Node::horizontal(...$children));
    }

    /**
     * Create a vertical layout with children stacked top to bottom.
     *
     * Declared leaf children are blank-seeded: editLeaf() works on their
     * addresses immediately (see {@see self::editLeaf()}).
     *
     * @param Node ...$children
     */
    public static function vertical(Node ...$children): self
    {
        return new self(Node::vertical(...$children));
    }

    // ─── Leaf Editing ──────────────────────────────────────────────

    /**
     * Edit a leaf node's content, returning a new Boxer with the changes.
     *
     * Addresses declared by the tree (factory children included) are
     * seeded with a blank placeholder at construction, so the factory
     * path `Boxer::vertical(Node::leaf('0'), ...)` can jump straight to
     * editLeaf; addresses the tree does not declare still throw.
     *
     * If the edit function returns an error, the exception is thrown.
     *
     * @param string $address The leaf address to edit
     * @param callable(Item): (Item|array{Item, null}|array{null, \Throwable}) $editFunc
     * @return self New Boxer with the edited content
     * @throws NotFoundError if address not found
     * @throws \Throwable if editFunc returns an error
     */
    public function editLeaf(string $address, callable $editFunc): self
    {
        if (!isset($this->modelMap[$address])) {
            throw new NotFoundError("Address '{$address}' not found");
        }

        $model = $this->modelMap[$address];
        $result = $editFunc($model);

        // Handle both tuple format [Item, null] and [null, Throwable] and direct Item
        if (is_array($result)) {
            [$newModel, $error] = $result;
            if ($error !== null) {
                throw $error;
            }
            if ($newModel !== null) {
                $newMap = $this->modelMap;
                $newMap[$address] = $newModel;
                return new self($this->root, $newMap, $this->width, $this->height);
            }
            return $this;
        }

        if ($result instanceof Item) {
            $newMap = $this->modelMap;
            $newMap[$address] = $result;
            return new self($this->root, $newMap, $this->width, $this->height);
        }

        return $this;
    }

    // ─── Size Propagation ──────────────────────────────────────────

    /**
     * Set the allocated dimensions and propagate to the tree.
     */
    public function setSize(int $width, int $height): Sizer
    {
        if ($width <= 0 || $height <= 0) {
            // Finding sugar-dashboard-121: the degenerate set used to carry the
            // previous pass's node sizes into the zero-sized boxer, so a
            // later re-read saw stale geometry. Collapse the whole subtree
            // through the public update path instead.
            return new self($this->root->updateSizeRecursive(0, 0), $this->modelMap, 0, 0);
        }

        if ($width === $this->width && $height === $this->height) {
            return $this;
        }

        // Update sizes in the tree
        $newRoot = $this->root->updateSizeRecursive($width, $height);

        // If model items are Sizers, propagate size to them.
        // Finding sugar-dashboard-121: the lookup used to walk the OLD tree
        // while the fresh geometry lives on $newRoot, so every model
        // received last pass's dimensions. One DFS indexes the sized tree;
        // a repeated setSize then costs O(1) per address instead of O(tree)
        // per address.
        $nodeByAddress = $this->indexTree($newRoot);

        $newModelMap = [];
        foreach ($this->modelMap as $addr => $item) {
            $addrStr = (string) $addr;
            $node = $nodeByAddress[$addrStr] ?? null;
            $newModelMap[$addrStr] = $item;
            if ($item instanceof Sizer && $node !== null) {
                $newModelMap[$addrStr] = $item->setSize($node->width(), $node->height());
            }
        }

        return new self($newRoot, $newModelMap, $width, $height);
    }

    /**
     * Index every node of a tree by address in one DFS pass.
     *
     * @return array<string, Node>
     */
    private function indexTree(Node $node): array
    {
        $map = [$node->address() => $node];
        foreach ($node->children() as $child) {
            $map += $this->indexTree($child);
        }

        return $map;
    }

    /**
     * Find a node by its address string.
     */
    private function findNode(Node $root, string $address): ?Node
    {
        if ($root->address() === $address) {
            return $root;
        }

        foreach ($root->children() as $child) {
            $found = $this->findNode($child, $address);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    // ─── Rendering ─────────────────────────────────────────────────

    /**
     * Render the boxer layout to a string.
     */
    public function render(): string
    {
        if ($this->width <= 0 || $this->height <= 0) {
            return 'waiting for size information';
        }

        $result = $this->root->renderTree($this->modelMap, $this->width, $this->height);

        if ($result instanceof SizeError) {
            // SizeError messages embed the offending model line verbatim —
            // hostile content must not reach the terminal raw (finding M-6).
            return 'size error: ' . Sanitize::untrustedForDisplay($result->getMessage());
        }

        if ($result instanceof \Throwable) {
            return 'error: ' . Sanitize::untrustedForDisplay($result->getMessage());
        }

        return implode("\n", $result);
    }

    // ─── Accessors ─────────────────────────────────────────────────

    public function root(): Node
    {
        return $this->root;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::root()} instead. REMOVED-IN: 2.0.0.
     */
    public function getRoot(): Node { return $this->root(); }

    /**
     * @return array<string, Item>
     */
    public function modelMap(): array
    {
        return $this->modelMap;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::modelMap()} instead. REMOVED-IN: 2.0.0.
     */
    public function getModelMap(): array { return $this->modelMap(); }

    public function width(): int
    {
        return $this->width;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::width()} instead. REMOVED-IN: 2.0.0.
     */
    public function getWidth(): int { return $this->width(); }

    public function height(): int
    {
        return $this->height;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::height()} instead. REMOVED-IN: 2.0.0.
     */
    public function getHeight(): int { return $this->height(); }

    /**
     * Get the item at a given address.
     */
    public function item(string $address): ?Item
    {
        return $this->modelMap[$address] ?? null;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::item()} instead. REMOVED-IN: 2.0.0.
     */
    public function getItem(string $address): ?Item { return $this->item($address); }

    /**
     * Get the node at a given address.
     */
    public function node(string $address): ?Node
    {
        return $this->findNode($this->root, $address);
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::node()} instead. REMOVED-IN: 2.0.0.
     */
    public function getNode(string $address): ?Node { return $this->node($address); }

    // ─── Persistence ─────────────────────────────────────────────

    use PersistState;

    /**
     * Apply a theme, fanning it down to any theme-aware children.
     */
    public function withTheme(Theme $theme): self
    {
        $themedMap = [];
        foreach ($this->modelMap as $addr => $item) {
            if ($item instanceof Drawable) {
                $themedMap[$addr] = $item->withTheme($theme);
            } else {
                $themedMap[$addr] = $item;
            }
        }

        return new self($this->root, $themedMap, $this->width, $this->height);
    }
}

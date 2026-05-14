<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout\Boxer;

use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
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
    /**
     * @param Node $root Root node of the layout tree
     * @param array<string, Item> $modelMap Map of address string to rendered content
     * @param int $width Current allocated width
     * @param int $height Current allocated height
     */
    public function __construct(
        private readonly Node $root,
        private readonly array $modelMap = [],
        private readonly int $width = 0,
        private readonly int $height = 0,
    ) {}

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
     * @param Node ...$children
     */
    public static function horizontal(Node ...$children): self
    {
        return new self(Node::horizontal(...$children));
    }

    /**
     * Create a vertical layout with children stacked top to bottom.
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
            return new self($this->root, $this->modelMap, 0, 0);
        }

        if ($width === $this->width && $height === $this->height) {
            return $this;
        }

        // Update sizes in the tree
        $newRoot = $this->root->updateSizeRecursive($width, $height);

        // If model items are Sizers, propagate size to them
        $newModelMap = [];
        foreach ($this->modelMap as $addr => $item) {
            $addrStr = (string) $addr;
            $node = $this->findNode($this->root, $addrStr);
            $newModelMap[$addrStr] = $item;
            if ($item instanceof Sizer && $node !== null) {
                $newModelMap[$addrStr] = $item->setSize($node->getWidth(), $node->getHeight());
            }
        }

        return new self($newRoot, $newModelMap, $width, $height);
    }

    /**
     * Find a node by its address string.
     */
    private function findNode(Node $root, string $address): ?Node
    {
        if ($root->getAddress() === $address) {
            return $root;
        }

        foreach ($root->getChildren() as $child) {
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
            return 'size error: ' . $result->getMessage();
        }

        if ($result instanceof \Throwable) {
            return 'error: ' . $result->getMessage();
        }

        return implode("\n", $result);
    }

    // ─── Accessors ─────────────────────────────────────────────────

    public function getRoot(): Node
    {
        return $this->root;
    }

    /**
     * @return array<string, Item>
     */
    public function getModelMap(): array
    {
        return $this->modelMap;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    /**
     * Get the item at a given address.
     */
    public function getItem(string $address): ?Item
    {
        return $this->modelMap[$address] ?? null;
    }

    /**
     * Get the node at a given address.
     */
    public function getNode(string $address): ?Node
    {
        return $this->findNode($this->root, $address);
    }
}

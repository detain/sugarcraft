<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout\Boxer;

use SugarCraft\Dash\Foundation\Item;

/**
 * A node in the boxer layout tree.
 *
 * Based on the bubbleboxer Node pattern.
 */
final class Node implements Item
{
    /**
     * @param list<Node> $children
     * @param SizeFunc|null $sizeFunc Custom size distribution function
     */
    public function __construct(
        private readonly array $children = [],
        private readonly bool $verticalStacked = false,
        private readonly ?SizeFunc $sizeFunc = null,
        private readonly bool $noBorder = false,
        private readonly ?Address $address = null,
        private int $width = 0,
        private int $height = 0,
    ) {}

    public static function leaf(Address $address): self
    {
        return new self(
            children: [],
            verticalStacked: false,
            sizeFunc: null,
            noBorder: true,
            address: $address,
        );
    }

    public static function horizontal(Node ...$children): self
    {
        return new self($children, false);
    }

    public static function vertical(Node ...$children): self
    {
        return new self($children, true);
    }

    public function getChildren(): array
    {
        return $this->children;
    }

    public function isLeaf(): bool
    {
        return $this->address !== null;
    }

    public function getAddress(): ?Address
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

    public function setSize(int $width, int $height): self
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    public function render(): string
    {
        return '';
    }

    /**
     * Update the size of this node and its children.
     *
     * @param map<string, Item> $modelMap Map of address to rendered content
     * @return list<string> Rendered lines
     */
    public function updateSize(int $width, int $height, array $modelMap = []): array
    {
        $node = $this->setSize($width, $height);

        if ($node->isLeaf()) {
            return [''];
        }

        $children = $node->getChildren();
        if ($children === []) {
            return [''];
        }

        // Calculate sizes for children
        if ($node->noBorder) {
            $childCount = count($children);
            if ($childCount === 0) {
                return [''];
            }

            if ($node->verticalStacked) {
                $baseHeight = intdiv($height, $childCount);
                $remainder = $height % $childCount;
                for ($i = 0; $i < $childCount; $i++) {
                    $childH = $baseHeight + ($i < $remainder ? 1 : 0);
                    $children[$i] = $children[$i]->setSize($width, $childH);
                }
            } else {
                $baseWidth = intdiv($width, $childCount);
                $remainder = $width % $childCount;
                for ($i = 0; $i < $childCount; $i++) {
                    $childW = $baseWidth + ($i < $remainder ? 1 : 0);
                    $children[$i] = $children[$i]->setSize($childW, $height);
                }
            }
        }

        return [''];
    }

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
}

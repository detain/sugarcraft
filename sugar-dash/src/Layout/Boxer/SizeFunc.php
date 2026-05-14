<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout\Boxer;

/**
 * Function type for computing sizes of child nodes.
 *
 * @ callable(list<int>, int): list<int>
 */
final class SizeFunc
{
    /**
     * @var callable(Node, int): list<int>
     */
    private $func;

    private function __construct(callable $func)
    {
        $this->func = $func;
    }

    /**
     * Create a size func that divides space evenly among children.
     */
    public static function even(Node $node, int $totalSize): array
    {
        $childCount = count($node->getChildren());
        if ($childCount === 0) {
            return [];
        }

        $baseSize = intdiv($totalSize, $childCount);
        $remainder = $totalSize % $childCount;

        $sizes = [];
        for ($i = 0; $i < $childCount; $i++) {
            $sizes[] = $baseSize + ($i < $remainder ? 1 : 0);
        }

        return $sizes;
    }

    /**
     * Create from a callable.
     *
     * @param callable(Node, int): list<int> $callable
     */
    public static function from(callable $callable): self
    {
        return new self($callable);
    }

    /**
     * Invoke the size func.
     *
     * @return list<int>
     */
    public function __invoke(Node $node, int $totalSize): array
    {
        return ($this->func)($node, $totalSize);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Render;

/**
 * Stack of block contexts that tracks accumulated indent and margins
 * for computing available width at any nesting depth.
 *
 * Available width = wordWrap - accumulatedIndent - marginCount * 2
 * computed from root on each call, not running subtraction.
 */
class BlockStack
{
    /** @var BlockContext[] */
    private array $stack = [];

    /**
     * Push a context onto the stack.
     */
    public function push(BlockContext $ctx): void
    {
        $this->stack[] = $ctx;
    }

    /**
     * Pop and return the topmost context.
     *
     * @throws \UnderflowException if stack is empty
     */
    public function pop(): BlockContext
    {
        if ($this->stack === []) {
            throw new \UnderflowException('BlockStack is empty');
        }
        return array_pop($this->stack);
    }

    /**
     * Pop contexts until the given kind is found (or stack is empty).
     *
     * Returns the matching context, or null if not found.
     */
    public function popTo(BlockKind $kind): ?BlockContext
    {
        $found = null;
        while (($ctx = array_pop($this->stack)) !== null) {
            if ($ctx->kind === $kind) {
                $found = $ctx;
                break;
            }
        }
        return $found;
    }

    /**
     * Peek at the topmost context without removing it.
     */
    public function peek(): ?BlockContext
    {
        return $this->stack[count($this->stack) - 1] ?? null;
    }

    /**
     * Peek at the topmost block's kind without removing it.
     */
    public function peekKind(): ?BlockKind
    {
        return $this->peek()?->kind;
    }

    /**
     * The sum of accumulated indents across all blocks in the stack.
     * Additive — every block in stack contributes its indent.
     */
    public function accumulatedIndent(): int
    {
        $total = 0;
        foreach ($this->stack as $ctx) {
            $total += $ctx->accumulatedIndent;
        }
        return $total;
    }

    /**
     * Number of blocks in the stack that carry a margin (typically
     * blockquotes and list items that add vertical spacing).
     */
    public function marginCount(): int
    {
        // Currently only BlockQuote and ListItem are considered margin-bearing.
        $count = 0;
        foreach ($this->stack as $ctx) {
            if ($ctx->kind === BlockKind::BlockQuote || $ctx->kind === BlockKind::ListItem) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Compute available width for text inside the current block context.
     *
     * @param int $wordWrap The total wrap width (from renderer or theme)
     * @return int Available cells for text content
     */
    public function availableWidth(int $wordWrap): int
    {
        if ($wordWrap <= 0) {
            return 0;
        }
        $indent = $this->accumulatedIndent();
        $margins = $this->marginCount();
        $result = $wordWrap - $indent - ($margins * 2);
        return max(1, $result);
    }

    /**
     * Whether the stack is empty.
     */
    public function isEmpty(): bool
    {
        return $this->stack === [];
    }

    /**
     * Current depth (number of blocks nested, not counting Document root).
     */
    public function depth(): int
    {
        return count($this->stack);
    }
}

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
final class BlockStack
{
    /** @var BlockContext[] */
    private array $stack = [];
    /** Running total of accumulatedIndent values — updated incrementally in push/pop. */
    private int $indentTotal = 0;
    /** Running count of margin-bearing blocks — updated incrementally in push/pop. */
    private int $marginCount = 0;

    /**
     * Push a context onto the stack.
     */
    public function push(BlockContext $ctx): void
    {
        $this->indentTotal += $ctx->accumulatedIndent;
        if ($ctx->kind === BlockKind::BlockQuote || $ctx->kind === BlockKind::ListItem) {
            $this->marginCount++;
        }
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
        $ctx = array_pop($this->stack);
        $this->indentTotal -= $ctx->accumulatedIndent;
        if ($ctx->kind === BlockKind::BlockQuote || $ctx->kind === BlockKind::ListItem) {
            $this->marginCount--;
        }
        return $ctx;
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
     * O(1) — maintained incrementally via $indentTotal.
     */
    public function accumulatedIndent(): int
    {
        return $this->indentTotal;
    }

    /**
     * Number of blocks in the stack that carry a margin (typically
     * blockquotes and list items that add vertical spacing).
     * O(1) — maintained incrementally via $marginCount.
     */
    public function marginCount(): int
    {
        return $this->marginCount;
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
        $result = $wordWrap - $this->indentTotal - ($this->marginCount * 2);
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

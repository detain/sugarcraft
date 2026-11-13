<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Support;

/**
 * Per-instance render memoization (sugar-charts-112).
 *
 * Renderers in this family are immutable value objects: every with and
 * push path constructs a `new self(...)` (source audit: no in-place property
 * writes outside constructors), so a freshly built object starts with an
 * empty memo and a cached string can never outlive the state that produced
 * it. Repeated `view()` calls on the SAME instance — the animation-frame
 * and preview-loop pattern the finding describes — pay the full compose
 * cost exactly once.
 *
 * A renderer that paints a caller-owned cell grid as a side effect of
 * composing (the LineChart/Chart CH-07 canvas bridge) must NOT route
 * through this memo: every call has to repaint. That bailout lives in the
 * renderer, not here.
 */
trait MemoizesRender
{
    /** @var string|null lazily filled composed render of THIS instance */
    private ?string $renderMemo = null;

    /**
     * @param callable(): string $produce full composition, run at most once
     */
    protected function memoizedRender(callable $produce): string
    {
        return $this->renderMemo ??= $produce();
    }
}

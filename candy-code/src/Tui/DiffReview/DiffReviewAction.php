<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\DiffReview;

use function max;

/**
 * The typed answer {@see DiffReview::update()} hands the wiring step: which
 * base to reload (with which view), or that the overlay is closing. A model
 * action, not a command — reloads are the wiring's job, and this step ships
 * none (C-TUI-2.d, per its brief).
 */
final class DiffReviewAction
{
    private function __construct(
        public readonly DiffReviewActionKind $kind,
        public readonly int $stepsBack = 1,
        public readonly bool $turnOnly = false,
    ) {}

    /** Re-read the diff for $stepsBack checkpoint(s) back, in $turnOnly view. */
    public static function reload(int $stepsBack, bool $turnOnly): self
    {
        return new self(DiffReviewActionKind::Reload, max(1, $stepsBack), $turnOnly);
    }

    public static function close(): self
    {
        return new self(DiffReviewActionKind::Close);
    }
}

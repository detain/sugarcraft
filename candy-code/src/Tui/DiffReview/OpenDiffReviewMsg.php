<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\DiffReview;

use SugarCraft\Core\Msg;

/**
 * The finished diff review, delivered for the wiring step to show: either a
 * ready {@see DiffReview} or the plain reason there is nothing to review
 * (C-TUI-2.d ships the model and the source; the command/panel that dials
 * {@see DiffReviewSource::load()} and answers this message is a later step —
 * "No wiring in this step").
 */
final class OpenDiffReviewMsg implements Msg
{
    public function __construct(
        public readonly ?DiffReview $review,
        public readonly string $reason = '',
    ) {}
}

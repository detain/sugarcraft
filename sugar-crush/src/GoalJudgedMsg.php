<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;
use SugarCraft\Crush\Goal\GoalVerdict;

/**
 * The `/goal` judge's answer about the turn that just settled (roadmap 3.D-3),
 * built by {@see Goal\GoalJudge::call()} and landed by `Chat`.
 *
 * Stamped with the $generation the judging was armed under: the judge holds
 * the turn slot while it runs, so Esc Esc (which bumps the generation) strands
 * a verdict on its way, and the loop does not carry on behind a cancel.
 *
 * Exactly one of $verdict and $error is set. The usage is accounted whatever
 * became of the answer, the rule every call on the user's key follows.
 */
final class GoalJudgedMsg implements Msg
{
    public function __construct(
        public readonly int $generation,
        public readonly ?string $sessionId,
        public readonly ?GoalVerdict $verdict,
        public readonly ?string $error = null,
        public readonly ?Usage $usage = null,
    ) {
    }
}

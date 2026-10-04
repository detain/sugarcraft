<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Goal;

/**
 * Which command set a goal (roadmap 3.D-3), and so how many follow-up rounds
 * it may drive before the loop gives up.
 *
 * Both are judged the same way — the title model reads the transcript after
 * every turn — and differ only in budget. `/goal` stops after
 * {@see GOAL_ROUNDS}, OpenHands' cap for the same loop; `/grind` is for the
 * long haul (Goose's `/grind`, "continue until it is fully done") and allows
 * {@see GRIND_ROUNDS}. Goose's grind has no judge and simply nudges until the
 * turn budget runs out; with a judge in place that would only spend rounds on
 * a goal already met, so here the judge ends a grind exactly as it ends a goal.
 */
enum GoalMode: string
{
    case Goal = 'goal';
    case Grind = 'grind';

    /** `/goal`'s follow-up rounds (OpenHands' cap). */
    public const GOAL_ROUNDS = 10;

    /** `/grind`'s follow-up rounds. */
    public const GRIND_ROUNDS = 50;

    /** The most follow-up prompts a goal of this mode sends before it stops. */
    public function maxRounds(): int
    {
        return match ($this) {
            self::Goal => self::GOAL_ROUNDS,
            self::Grind => self::GRIND_ROUNDS,
        };
    }

    /** The slash command that sets it, `/goal` or `/grind`. */
    public function command(): string
    {
        return '/' . $this->value;
    }
}

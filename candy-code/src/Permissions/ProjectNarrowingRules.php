<?php

declare(strict_types=1);

namespace SugarCraft\Code\Permissions;

/**
 * The deny/ask-only partition of a project tier's permission rules.
 *
 * WHY THIS EXISTS, and it is the self-grant floor restated on the rule axis:
 * a cloned repository must never hand the model more room than the operator
 * gave it. A `deny`/`ask` rule NARROWS what a session may do, which is what a
 * trusted project is allowed to do to its own directory's launches; an `allow`
 * rule WIDENS it, and an allow arriving through `git clone` is exactly the
 * grant-by-arrival the trust model refuses everywhere else — so allow entries
 * cannot reach the gate from this tier, and never will.
 *
 * IT REPORTS THE DROPPED ALLOWS INSTEAD OF SWALLOWING THEM. A silently-dropped
 * `allow` is a policy the project author believes is in force and is not; the
 * launch notice names the count so the mismatch is heard at start, by both
 * sides, rather than discovered as a missing grant mid-task. `kept` and
 * `droppedAllows` are both kept as rule lists (not just counts) because a
 * diagnostic that can name WHICH rule fell is the difference between an
 * answer and a shrug.
 *
 * Not a port — no upstream equivalent; charmbracelet has no project tier at
 * all. The partition itself is a `match` on the action enum; everything
 * interesting here is the WHY above.
 */
final class ProjectNarrowingRules
{
    /** @var list<PermissionRule> the deny/ask entries, original order preserved */
    public readonly array $kept;

    /** @var list<PermissionRule> the allow entries this tier may not contribute */
    public readonly array $droppedAllows;

    /**
     * @param list<PermissionRule> $rules one tier's parsed rules, in order
     */
    public function __construct(array $rules)
    {
        $kept = [];
        $dropped = [];

        foreach ($rules as $rule) {
            if ($rule->action === PermissionAction::Allow) {
                $dropped[] = $rule;
                continue;
            }

            $kept[] = $rule;
        }

        $this->kept = $kept;
        $this->droppedAllows = $dropped;
    }

    /**
     * How many rules this tier is allowed to contribute (deny/ask).
     */
    public function narrowedRules(): int
    {
        return count($this->kept);
    }

    /**
     * How many allow entries were dropped as out of this tier's reach.
     */
    public function allowsDropped(): int
    {
        return count($this->droppedAllows);
    }
}

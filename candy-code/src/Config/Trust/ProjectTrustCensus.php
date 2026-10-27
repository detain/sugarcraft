<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config\Trust;

/**
 * Which {@see TrustTarget}s a project root DECLARES but does not yet hold
 * (C-SEC-5.a).
 *
 * WHY THIS CLASS IS PURE AND THE CALLER IS NOT. The two questions the census
 * answers are read from entirely different machinery — "declares" from
 * filesystem probes and the launch's MCP decision, "holds" from the
 * once-per-process trust memos on {@see \SugarCraft\Code\Cli\Bootstrap} — and
 * a class that read both directly would freeze into the same twelve-copies
 * shape the MCP discovery path just escaped. So the shape lives here, under
 * two injected closures, and the wiring lives beside the doors it reads.
 * Every ordering, short-circuit and set rule is asserted against plain
 * closures in {@see \SugarCraft\Code\Tests\Config\Trust\ProjectTrustCensusTest}
 * without a filesystem in sight.
 *
 * THE CONTRACT OF EACH CLOSURE.
 *  - `$declaresTarget($target)`: the root carries content this target could
 *    act on — an MCP file naming servers, a hooks file present, a command
 *    file with a `!` form, a settings file on disk. For `McpPin` it means a
 *    PIN REFUSAL stands against this launch's memo client: that is the content
 *    the grant would answer.
 *  - `$targetIsTrusted($target)`: the grant already exists. For `McpPin` the
 *    honest reading of "granted" is "no refusal stands" — the pin question has
 *    no answer except re-recording, so the caller answers it from the same
 *    refusal read, and an empty refusal list retires the row.
 *
 * A target that is not declared is never pending, trusted or not: the census
 * reports work the user could actually do, not every key they have not
 * written. That is the difference between one prompt per cloned repo and one
 * prompt per repo that could run something.
 */
final class ProjectTrustCensus
{
    /**
     * The pending targets, in declaration order of {@see TrustTarget::cases()}.
     *
     * @param \Closure(TrustTarget): bool $declaresTarget
     * @param \Closure(TrustTarget): bool $targetIsTrusted
     *
     * @return list<TrustTarget>
     */
    public static function pending(\Closure $declaresTarget, \Closure $targetIsTrusted): array
    {
        $pending = [];

        foreach (TrustTarget::cases() as $target) {
            if (!$declaresTarget($target) || $targetIsTrusted($target)) {
                continue;
            }

            $pending[] = $target;
        }

        return $pending;
    }
}

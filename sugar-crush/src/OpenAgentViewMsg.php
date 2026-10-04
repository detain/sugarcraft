<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;

/**
 * Open the read-only Agent View on one delegated run (roadmap P-C2, Appendix
 * P §5.1): the main transcript area shows that run's own transcript until
 * {@see CloseAgentViewMsg}. Answered by {@see \SugarCraft\Crush\App\App}, which
 * holds the view state ({@see \SugarCraft\Crush\App\App::openAgentView()}); a
 * chat run without a shell ignores it, as it does every message it does not
 * claim.
 *
 * Every entry point sends this one message: a click on an `agent:<runId>`
 * zone (a Task row's live line, the live agents strip, a sibling arrow in the
 * view's header), `Enter` on a focused strip item, `Enter` in the dashboard's
 * peek ({@see \SugarCraft\Crush\Tui\AgentViewMode::Attach}), and `Enter` on a
 * sub-agent row of the session picker.
 *
 * ALSO THE VIEW'S REFRESH. The shell's tail tick sends it again for the run
 * already on screen, and opening the run that is already open reads what its
 * log gained since — so "open" is idempotent, and the tick needs no message
 * of its own.
 */
final readonly class OpenAgentViewMsg implements Msg
{
    /**
     * @param string      $agentId        the run ({@see \SugarCraft\Crush\Agents\Live\AgentLiveState::$id}),
     *                                     or the dashboard row's key or name for a worker that is not one
     * @param string|null $childSessionId the `subagent` child session to read when no live
     *                                     transcript is known for $agentId — a finished run
     *                                     opened from the session picker or after a restart
     * @param string|null $name           the agent's name for the view's header, when the
     *                                     shell cannot read it off a live run
     */
    public function __construct(
        public string $agentId,
        public ?string $childSessionId = null,
        public ?string $name = null,
    ) {
    }
}

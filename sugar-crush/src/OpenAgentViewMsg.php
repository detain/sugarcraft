<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;

/**
 * Open one delegated run (Appendix P §5.1): a click on an `agent:<runId>`
 * zone — a live agents strip item today — sent by {@see Chat} as a command
 * result for the shell that hosts it.
 *
 * Until the Agent View lands (P-C2), {@see \SugarCraft\Crush\App\App}
 * answers it with the dashboard, the run selected and its peek up
 * ({@see \SugarCraft\Crush\App\App::openAgent()}). A chat run without a
 * shell ignores it, as it does every message it does not claim.
 */
final readonly class OpenAgentViewMsg implements Msg
{
    public function __construct(
        public string $agentId,
    ) {
    }
}

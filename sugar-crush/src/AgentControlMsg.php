<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;

/**
 * Something the user asked of one or more delegated runs (roadmap P-D2,
 * Appendix P §5.3), from the Agent View's composer. The shell
 * ({@see \SugarCraft\Crush\App\App::consumeShellCmd()}) translates its key
 * commands into this one message and hands it to the hosted {@see Chat},
 * which owns the runs' mailboxes ({@see \SugarCraft\Crush\Agents\Live\AgentInbox})
 * and the live registry the ids name.
 *
 * Every verb names its runs by {@see \SugarCraft\Crush\Agents\Live\AgentLiveState::$id};
 * the shell expands a group into the list before sending, so the chat never
 * guesses a batch.
 *
 * - {@see MESSAGE}: the composer's draft, to each run — a mailbox line the
 *   run reads at its next step, or, for a run that has finished, a
 *   follow-up run that continues its conversation ({@see Host\AgentResume}).
 */
final readonly class AgentControlMsg implements Msg
{
    public const MESSAGE = 'message';

    /** Every verb, in the order the class doc lists them. */
    public const VERBS = [self::MESSAGE];

    /**
     * @param list<string> $agentIds the runs it is for
     * @param string       $text     what to say; empty means the composer's
     *                               draft
     */
    public function __construct(
        public string $verb,
        public array $agentIds,
        public string $text = '',
    ) {
        if (!\in_array($verb, self::VERBS, true)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not an agent control verb (%s)', $verb, implode(', ', self::VERBS)));
        }
    }
}

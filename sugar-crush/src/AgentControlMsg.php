<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;

/**
 * Something the user asked of one or more delegated runs (roadmap P-D2/P-D3,
 * Appendix P §5.3–5.5), from the Agent View's composer and `Ctrl+X` chords,
 * the agent dashboard's `c`/`r`/`s`, or `Ctrl+G`. The shell
 * ({@see \SugarCraft\Crush\App\App::consumeShellCmd()}) translates its key
 * commands into this one message and hands it to the hosted {@see Chat},
 * which owns the runs' mailboxes ({@see \SugarCraft\Crush\Agents\Live\AgentInbox})
 * and the live registry the ids name.
 *
 * Every verb names its runs by {@see \SugarCraft\Crush\Agents\Live\AgentLiveState::$id};
 * the shell expands a group (stop-all, a broadcast) into the list before
 * sending, so the chat never guesses a batch.
 *
 * - {@see MESSAGE}: the composer's draft, to each run — a mailbox line the
 *   run reads at its next step, or, for a run that has finished, a
 *   follow-up run that continues its conversation ({@see Host\AgentResume}).
 * - {@see CANCEL}: soft cancel — a `cancel` control the run reads at its next
 *   tool or step, so it stops resumable. {@see STOP} is the hard second
 *   press: the turn's own `cancel_tool` for the run's Task call.
 * - {@see PAUSE} / {@see RESUME}: hold a running run at its next step
 *   boundary (for ten minutes at most, see
 *   {@see \SugarCraft\Crush\Tools\BuiltIn\TaskTool}) and let it go again.
 *   RESUME on a finished run continues it with "Continue." (or $text).
 * - {@see OPEN_SESSION}: make the run's stored child session the session on
 *   screen — the "fork the agent into a full session" escape hatch.
 */
final readonly class AgentControlMsg implements Msg
{
    public const MESSAGE = 'message';
    public const CANCEL = 'cancel';
    public const STOP = 'stop';
    public const PAUSE = 'pause';
    public const RESUME = 'resume';
    public const OPEN_SESSION = 'open-session';

    /** Every verb, in the order the class doc lists them. */
    public const VERBS = [self::MESSAGE, self::CANCEL, self::STOP, self::PAUSE, self::RESUME, self::OPEN_SESSION];

    /**
     * @param list<string> $agentIds the runs it is for
     * @param string       $text     what to say; empty means the composer's
     *                               draft for {@see MESSAGE} and "Continue."
     *                               for a {@see RESUME} that continues a
     *                               finished run
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

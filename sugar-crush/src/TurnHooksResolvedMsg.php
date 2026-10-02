<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;
use SugarCraft\Crush\Hooks\HookResult;

/**
 * Internal Msg carrying the verdicts of a turn's `UserPromptSubmit` (and, on a
 * session's first prompt, `SessionStart`) hook chain back from the forked child
 * that ran it (audit 15b-04).
 *
 * WHY THE CHAIN RUNS OFF update(): a {@see Hooks\ScriptHook} is a blocking
 * `proc_open()` drain bounded at 60 s per hook, and it used to run inside
 * {@see Chat::update()} — so a slow prompt hook froze the whole TUI with no
 * repaint, no Escape and no Ctrl+C. {@see Chat} now forks the chain from a Cmd
 * and re-enters its submit path when this arrives, consuming these verdicts in
 * place of running the hooks again.
 *
 * $generation is the latch: the pending submission bumped
 * {@see Chat::$generation}, and a double-Escape cancel bumps it again, so a
 * verdict for a submission the user abandoned is dropped instead of dispatching
 * the prompt they just cancelled.
 */
final class TurnHooksResolvedMsg implements Msg
{
    /**
     * @param int         $generation the generation the pending submission armed
     * @param string      $text       the submitted prompt the verdicts judged
     * @param HookResult  $prompt     the `UserPromptSubmit` chain's verdict
     * @param ?HookResult $session    the `SessionStart` chain's verdict, or null
     *                                when it was not fired (history not empty,
     *                                or the prompt gate did not permit)
     */
    public function __construct(
        public readonly int $generation,
        public readonly string $text,
        public readonly HookResult $prompt,
        public readonly ?HookResult $session = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host;

use React\Promise\PromiseInterface;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Message;
use SugarCraft\Code\Memory\AutoMemoryConsolidator;
use SugarCraft\Code\Memory\DreamPass;
use SugarCraft\Code\Memory\MemoryStore;
use SugarCraft\Code\Memory\MemoryWriter;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\SessionStore;
use SugarCraft\Code\Workspace\AutoCommitter;

/**
 * One decision table, two hosts (the {@see \SugarCraft\Code\Goal\GoalLoop}
 * shape): the four side-calls a settled turn may schedule — the next-prompt
 * suggestion, auto-memory consolidation, the `turn`-mode auto-commit and the
 * dream pass — decided from flags any settle knows and nothing else.
 *
 * WHY A VALUE AND NOT A PROCEDURE. `Chat`'s settle applies these same four
 * gates through {@see \SugarCraft\Code\Chat\Handlers\HandlesTurnCompletion};
 * a host with no TUI (Appendix O's server, roadmap C-CTX-2) must reach the
 * identical decisions without instantiating a screen. So the decision lives
 * here, fed only by the flags below, and each owner applies the outcome
 * through its own machinery: `Chat` wraps a call in `Cmd::promise`, a server
 * hands it to its loop. The gates mirror the settle exactly —
 *
 *  - carried-on: a queued prompt took the turn's place, so the conversation
 *    never settled; nothing is scheduled and every call rides the settle of
 *    the turn that prompt starts;
 *  - goal-active: a live `/goal` keeps the conversation going, so only the
 *    suggestion stands down — the guess would be stale before the input box
 *    is free to show it, while the other three answer the turn that just
 *    ended whatever happens next;
 *  - no memory store: consolidation and the dream pass have nowhere to
 *    write, so neither is even asked (their own `call()` gates stay behind
 *    this one, untouched: spend cap, environment switch, throttles);
 *  - auto-commit mode: {@see AutoCommitter::turnCall()} reads the setting,
 *    the store and the checkpoint itself — the only Chat-state pieces it
 *    needs are on this table.
 *
 * WHAT A SLOT CARRIES is a call factory, `(\Closure(): PromiseInterface)|null`,
 * exactly as the callee returns it: gating (and the throttles' claim) runs
 * when `plan()` is called; the request runs only when the host invokes the
 * factory. The promises resolve to the existing messages —
 * `PromptSuggestionMsg`, `MemoryConsolidatedMsg`, `AutoCommittedMsg`,
 * `DreamPassCompletedMsg` — or to null when the answer came back empty.
 *
 * DORMANT UNTIL WIRED: no host calls `plan()` yet. `Chat` keeps its inline
 * gates and its own `scheduleAutoCommit()`; C-CTX-2.c gives `SessionHost`
 * this table and C-CTX-2.e folds `Chat` onto it, after which the TUI copy of
 * the auto-commit body dies.
 */
final class TurnSettleWork
{
    /**
     * @param (\Closure(): PromiseInterface)|null $suggestion the next-prompt guess, resolving a `PromptSuggestionMsg`
     * @param (\Closure(): PromiseInterface)|null $consolidation the auto-memory run, resolving a `MemoryConsolidatedMsg`
     * @param (\Closure(): PromiseInterface)|null $autoCommit the `turn`-mode commit, resolving an `AutoCommittedMsg`
     * @param (\Closure(): PromiseInterface)|null $dream the journal fold, resolving a `DreamPassCompletedMsg`
     */
    private function __construct(
        public readonly ?\Closure $suggestion,
        public readonly ?\Closure $consolidation,
        public readonly ?\Closure $autoCommit,
        public readonly ?\Closure $dream,
    ) {}

    /**
     * The table itself. Every argument is something both hosts already hold
     * at a settle: the two flags, the memory store (null when memory is off),
     * the three backends by role — the session's engine, the tool-less
     * summary model, the tool-less title model — the settled transcript, the
     * spend-cap verdict, the session id and the history generation the
     * suggestion message must match, the project root ('' for none), the
     * merged user settings and the session store.
     *
     * @param list<Message> $history
     * @param array<string, mixed> $userConfig
     * @param TitleService|null $titleService the host's service (settings and
     *        the suggestions switch live there); a bare {@see TitleService::new()}
     *        decides for a host that registers none
     */
    public static function plan(
        bool $carriedOn,
        bool $goalActive,
        ?MemoryStore $memoryStore,
        ?Backend $engineBackend,
        ?Backend $summaryBackend,
        ?Backend $titleBackend,
        array $history,
        bool $capReached,
        ?string $sessionId,
        int $generation,
        string $projectRoot,
        array $userConfig,
        SessionStore|EnhancedSessionStore|null $sessionStore,
        ?TitleService $titleService = null,
    ): self {
        if ($carriedOn) {
            return new self(null, null, null, null);
        }

        // One writer serves both memory calls, as the settle builds it: the
        // project store when the tree hosts one, the home store beside it.
        $writer = $memoryStore === null ? null : MemoryWriter::new($memoryStore, $projectRoot);

        return new self(
            suggestion: $goalActive
                ? null
                : ($titleService ?? TitleService::new())->suggestionCall(
                    $titleBackend, $history, $generation, $sessionId, $capReached,
                ),
            consolidation: $writer === null
                ? null
                : AutoMemoryConsolidator::new($writer)->call($summaryBackend, $history, $capReached, $sessionId),
            autoCommit: AutoCommitter::turnCall($userConfig, $sessionStore, $sessionId, $projectRoot, $history, $capReached, $titleBackend),
            dream: $writer === null
                ? null
                : DreamPass::new($writer)->call($engineBackend, $capReached, $sessionId),
        );
    }
}

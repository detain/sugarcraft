<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\SessionHost;

use SugarCraft\Code\Host\CompactionService;
use SugarCraft\Code\Host\SessionTurnRules;
use SugarCraft\Code\Host\SpendLedger;
use SugarCraft\Code\Host\SubmitOptions;
use SugarCraft\Code\Host\TurnController;
use SugarCraft\Code\Host\TurnTicket;

use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Agents\Live\SessionMailbox;
use SugarCraft\Code\Agents\PrimaryAgent;
use SugarCraft\Code\App\AssistantMsg;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CacheHealthWatch;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Backend\InteractiveTurn;
use SugarCraft\Code\Backend\PendingAsk;
use SugarCraft\Code\Backend\PendingClientCall;
use SugarCraft\Code\Backend\QueueMode;
use SugarCraft\Code\Backend\SummarisesWithCache;
use SugarCraft\Code\Background\ScheduleRegistry;
use SugarCraft\Code\App\BackendToolEventsMsg;
use SugarCraft\Code\App\BangShellResultMsg;
use SugarCraft\Code\Chat;
use SugarCraft\Code\CommandParser;
use SugarCraft\Code\Commands\BangShell;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\McpPromptCommands;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Commands\Specs\BuiltInCommands;
use SugarCraft\Code\Context\Compaction\HistoryFingerprint;
use SugarCraft\Code\Context\CompactorConfig;
use SugarCraft\Code\Context\ContextCompactor;
use SugarCraft\Code\Context\IdleCompactionPolicy;
use SugarCraft\Code\Context\KeepWarmPolicy;
use SugarCraft\Code\Diagnostics\NoticeSink;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Events\PermissionAsked;
use SugarCraft\Code\Events\PermissionResolved;
use SugarCraft\Code\Events\ToolFinished;
use SugarCraft\Code\Events\ToolStarted;
use SugarCraft\Code\Goal\GoalJudge;
use SugarCraft\Code\Goal\GoalLoop;
use SugarCraft\Code\Goal\GoalState;
use SugarCraft\Code\App\GoalJudgedMsg;
use SugarCraft\Code\App\MemoryConsolidatedMsg;
use SugarCraft\Code\App\DreamPassCompletedMsg;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Observability\EventSink;
use SugarCraft\Code\Observability\EventSinkFactory;
use SugarCraft\Code\Observability\NullEventSink;
use SugarCraft\Code\App\HistoryCompactedMsg;
use SugarCraft\Code\App\PromptSuggestionMsg;
use SugarCraft\Code\Host\SessionHost\SessionLandings;
use SugarCraft\Code\Host\SessionHost\SessionClientTools;
use SugarCraft\Code\Host\SessionHost\SessionIdleCompaction;
use SugarCraft\Code\Host\SessionHost\SessionCommands;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\CommandEffect;
use SugarCraft\Code\Host\Commands\CommandEffectKind;
use SugarCraft\Code\Host\Commands\CommandResult;
use SugarCraft\Code\Host\Commands\CommandText;
use SugarCraft\Code\Host\ClientTools\ClientToolRegistry;
use SugarCraft\Code\Host\ClientTools\ClientToolSpec;
use SugarCraft\Code\Host\ContextSources;
use SugarCraft\Code\Lang;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\Memory\AutoMemoryConsolidator;
use SugarCraft\Code\Memory\DreamPass;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionReply;
use SugarCraft\Code\Permissions\SafetyClassifier;
use SugarCraft\Code\Permissions\SessionPermissionMemo;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\SessionLock;
use SugarCraft\Code\ToolCall;
use SugarCraft\Code\Usage;
use SugarCraft\Code\Util\TokenTracker;
use SugarCraft\Code\Workspace\AutoCommittedMsg;
use SugarCraft\Core\Util\Sanitize;

/**
 * Part of the CWPD-14 split of SessionHost.php (lane G4-H, OD-30 guard-first).
 *
 * The methods below were MOVED byte-verbatim into a composition trait: Serve/
 * SessionHost compile them in, so every self:: call, private-const read and
 * property touch resolves exactly where it did before the split. Behavior is
 * byte-identical; the structural census in tests/Host/SessionHostSurfaceCensusTest
 * pins that. The docblocks carried below are prose history notes, kept verbatim.
 */
trait SessionAdmission
{

    // ── admission ──────────────────────────────────────────────────────

    private function admitWhileBusy(string $text, SubmitOptions $options): TurnTicket
    {
        $turns = $this->turns();

        switch ($turns->midTurnRoute($text, $this->isWorkflowControl($text), $options)) {
            case TurnController::ROUTE_WORKFLOW_CONTROL:
                // `/workflow pause|status` inside the run they control (audit
                // WF-4): run, and leave the run holding the session.
                return $this->command($text) ?? TurnTicket::refused($turns->hostCommandNotice($text));

            case TurnController::ROUTE_SIDE_QUESTION:
                // `/btw` (roadmap 5.14b): answered beside the running turn,
                // never queued behind it and never in the model's history.
                return $this->command($text) ?? TurnTicket::refused($turns->hostCommandNotice($text));

            case TurnController::ROUTE_QUIT:
            case TurnController::ROUTE_REFUSE_COMMAND:
                return TurnTicket::refused($turns->hostCommandNotice($text));

            case TurnController::ROUTE_STEER:
                $steerId = $this->workspace->backend instanceof InteractiveTurn
                    ? $this->turn?->steer($text)
                    : null;
                // NEVER LOST, as in the TUI: a steer is also held as a
                // follow-up, and the settle drops it if the turn read it.
                $this->queue[] = $text;
                if ($steerId === null) {
                    return TurnTicket::queued(\count($this->queue));
                }

                return TurnTicket::steered($this->turnId(), $steerId, \count($this->queue));

            case TurnController::ROUTE_INTERRUPT:
                $this->cancel();

                return $this->admit($text, $options);

            default:
                $this->queue[] = $text;

                return TurnTicket::queued(\count($this->queue));
        }
    }

    private function admit(string $text, SubmitOptions $options): TurnTicket
    {
        $turns = $this->turns();

        // `!cmd` (roadmap 5.14g): the user's own shell command, run here as the
        // TUI runs it. It calls no model, so it needs no backend.
        $bang = BangShell::commandOf($text);
        if ($bang !== null) {
            return $this->runBang($bang);
        }

        // FILE-BASED COMMANDS FIRST, ahead of the built-ins: a project
        // `compact.md` replaces `/compact`, and a command file IS a prompt, so
        // everything below applies to its expansion.
        $command = $turns->resolveCustomCommand($text, $this->customCommands);
        if ($command !== null) {
            if ($this->workspace->backend === null) {
                return TurnTicket::refused($turns->noBackendNotice());
            }
            // C-SEC-3.b: the serve path carries the same user-tier opt-in
            // lists the TUI forwards — an empty pair here would deny-all a
            // bundle the owner explicitly trusted in their own config.
            if ($turns->customCommandMustFork(
                $text,
                $this->customCommands,
                null,
                $this->workspace->projectCommandsTrusted,
                $this->workspace->trustedBundleCommands,
                $this->workspace->mcpPromptShell,
            )) {
                return $this->parkExpansion($text, $command);
            }

            // C-MCP-2.a: the fetched-body leg Chat's expandCustomCommand runs,
            // for the no-fork shape of serve this branch takes. An MCP prompt
            // whose fetch fails expands to nothing and rides the existing
            // empty-expansion refusal below — it is never sent half-guessed.
            [$spec, $arguments] = $command;
            if (McpPromptCommands::isMcpPrompt($spec)) {
                $materialized = McpPromptCommands::materialize($spec, $arguments, $this->mcpProvider);
                if ($materialized === null) {
                    return $this->admitExpanded($text, '');
                }
                $spec = $materialized;
            }

            return $this->admitExpanded(
                $text,
                $turns->expandCustomCommand($spec, $arguments, $this->directive($spec)),
                $spec,
            );
        }

        // A built-in runs through its `Host\Commands` body, as in the TUI; a
        // `/word` that names none is prose, as it is there.
        $ran = $this->command($text);
        if ($ran !== null) {
            return $ran;
        }

        if ($this->workspace->backend === null) {
            return TurnTicket::refused($turns->noBackendNotice());
        }

        // C-WEB-2.b: the VIS-5 rewrite on the served route too. A typed
        // `/name args` whose name a user-invocable skill claims is the same
        // request as the `$name args` mention spelling, and the shared gate
        // that answers it in the TUI is
        // {@see \SugarCraft\Code\Skills\SkillSlashInvocation::draft()}. The
        // precedence matches `Chat::submit()`: the file-command and built-in
        // branches above passed on the name, so a command-claimed name is
        // never rewritten here. The rewrite rides only user-typed text — a
        // send that declines mention resolution is by definition not that,
        // and the rewritten `$name` attaches through exactly that channel —
        // and the typed draft survives as the rewind draft, as in the TUI.
        $draft = $text;
        $skilled = $options->resolveMentions
            ? \SugarCraft\Code\Skills\SkillSlashInvocation::draft($text, $this->workspace->skills)
            : null;
        if ($skilled !== null) {
            $text = $skilled;
        }

        return $this->admitPrompt($text, $options->resolveMentions, $draft, attachments: $options->attachments, modeCeiling: $options->modeCeiling);
    }

    /**
     * A command file's expansion, judged as the TUI judges it: an expansion
     * that produced nothing is refused, not sent, and its mentions are never
     * read (audit 15b-15) — the body is repository-authored, with its own `@`
     * form a trust tier may just have refused. The `/rewind` draft is the line
     * as typed.
     *
     * C-EXT-2.e: $spec is the command file's own spec (null for an expansion
     * no spec stands behind, like the MCP fetch-refusal leg). Beside it this
     * route applies the same two judgements Chat::submit() applies to a
     * command it expands: a `subtask:`/`agent:` command rides out wrapped in
     * {@see TurnController::wrapCommandDelegation()}'s delegation directive,
     * and a `model:` command pins exactly this one turn through
     * {@see TurnController::commandTurnModel()} — the pin is threaded to
     * {@see dispatch()}'s backend, never stored on the session, so the next
     * turn answers on the session's model by construction.
     */
    private function admitExpanded(string $typed, ?string $expanded, ?CommandSpec $spec = null): TurnTicket
    {
        $expanded = trim((string) $expanded);
        if ($expanded === '') {
            return TurnTicket::refused($this->turns()->emptyCustomCommandNotice($typed));
        }

        $oneTurnModel = null;
        $commandModelNotice = null;
        if ($spec !== null) {
            $expanded = $this->turns()->wrapCommandDelegation($expanded, $spec);
            $engine = $this->backend();
            $decided = $this->turns()->commandTurnModel($spec, $engine instanceof EngineBackend ? $engine : null);
            if ($decided !== null) {
                [$oneTurnModel, $commandModelNotice] = $decided;
            }
        }

        return $this->admitPrompt(
            $expanded,
            false,
            $typed,
            oneTurnModel: $oneTurnModel,
            commandModelNotice: $commandModelNotice,
        );
    }

    /**
     * Expand $text's command file in a forked child (audit 15b-20) and go on
     * once it answers. The session is held — mid-turn rules apply — until
     * then.
     *
     * @param array{0: CommandSpec, 1: string} $command
     */
    private function parkExpansion(string $text, array $command): TurnTicket
    {
        [$spec, $arguments] = $command;
        $turns = $this->turns();
        $root = $this->root();
        $trusted = $this->workspace->projectCommandsTrusted;
        $gate = $this->workspace->permissionGate;
        $provider = $this->mcpProvider;
        // C-SEC-3.b: the forked child expands with the SAME user-tier opt-in
        // lists the parent's fork decision consulted; thread them through the
        // closure's capture, not the (empty) defaults.
        $bundles = $this->workspace->trustedBundleCommands;
        $mcpShell = $this->workspace->mcpPromptShell;
        $run = static function () use ($turns, $spec, $arguments, $root, $trusted, $gate, $provider, $bundles, $mcpShell): array {
            $gated = [];
            // C-MCP-2.a: for an MCP prompt row the network fetch IS the
            // reason this turn is parked (the posture always forks one), so
            // it belongs in this child — the parent's keystroke and event
            // threads never block on a server. A refused fetch reports the
            // empty string, which rides the parent's existing empty-
            // expansion refusal; a fetched body replaces the marker before
            // the directive scan, so C-SEC-3 judges shell forms on what the
            // model will actually see.
            if (McpPromptCommands::isMcpPrompt($spec)) {
                $materialized = McpPromptCommands::materialize($spec, $arguments, $provider);
                if ($materialized === null) {
                    return ['', $gated];
                }
                $spec = $materialized;
            }
            $expanded = $turns->expandCustomCommand(
                $spec,
                $arguments,
                $turns->commandDirective($spec, $root, $trusted, $gate, static function (string $command) use (&$gated): void {
                    $gated[] = $command;
                }, $bundles, $mcpShell),
            );

            return [$expanded, $gated];
        };

        $this->park(
            static function () use ($run): string {
                [$expanded, $gated] = $run();

                return TurnController::customCommandPayload($expanded, $gated);
            },
            static fn (string $file): array => TurnController::customCommandExpansionFromPayload(TurnController::takePayload($file)),
            $run,
            function (array $result) use ($text, $command): void {
                [$expanded, $gated] = $result;
                // The child's gate evaluations, replayed against this session's
                // own gate, so Auto mode's circuit breaker counts what a
                // synchronous expansion would have counted. Root-less: a Bash
                // verdict never reads the root.
                foreach ($gated as $command) {
                    $this->workspace->permissionGate?->evaluate(new ToolCall('Bash', ['command' => $command]));
                }
                if ($expanded === null) {
                    return;
                }
                // C-MCP-2.a: a refused MCP fetch is the first expansion that
                // can only ever arrive through the forking posture, so its
                // refusal must land as the visible transcript notice Chat's
                // refuseEmptyCustomCommand records — dropping the returned
                // ticket silently would leave a web client watching a turn
                // that vanished. A null expansion (the child's own judge
                // refusal) keeps its existing silence; this leg is only the
                // empty-string one admitExpanded refuses.
                if (trim($expanded) === '') {
                    $this->appendMessageRow(Message::notice($this->turns()->emptyCustomCommandNotice($text)));

                    return;
                }
                $this->admitExpanded($text, $expanded, $command[0]);
            },
        );

        return TurnTicket::pending();
    }

    /**
     * A prompt (typed, or a command file's expansion) from the spend cap on.
     * $verdicts are a forked turn-hook chain's, on the re-entry that consumes
     * them; null fires the chain now.
     *
     * @param array{0: HookResult, 1: ?HookResult}|null $verdicts
     * @param list<\SugarCraft\Code\Attachment> $attachments pasted images riding this
     *     prompt (WEB-5b); threaded unchanged through a forked hook's resume
     * @param ?PermissionMode $modeCeiling the C-AGT-2.b gate ceiling of the
     *     submission that starts this turn; threaded unchanged through a
     *     forked hook's resume, null for an expansion that names none
     * @param ?string $oneTurnModel C-EXT-2.e: the model a command file's
     *     `model:` pinned for exactly this turn (see
     *     {@see TurnController::commandTurnModel()}); threaded unchanged
     *     through a forked hook's resume so the pin cannot be forked away
     * @param ?string $commandModelNotice C-EXT-2.e: the fall-back sentence when
     *     the requested model could not be served and the turn rides the
     *     session's model instead; a notice row, never an override
     */
    private function admitPrompt(
        string $text,
        bool $resolveMentions,
        string $draft,
        ?array $verdicts = null,
        array $attachments = [],
        ?PermissionMode $modeCeiling = null,
        ?string $oneTurnModel = null,
        ?string $commandModelNotice = null,
    ): TurnTicket {
        $turns = $this->turns();
        $ledger = $this->ledger();

        // Spend cap, where the TUI checks it: after command dispatch.
        if ($ledger->capReached($this->spend, $this->workspace->maxCostUsd)) {
            return TurnTicket::refused($ledger->refusalNotice(
                $this->spend,
                (float) $this->workspace->maxCostUsd,
                SpendLedger::crossedByPreviousTurn(),
            ));
        }

        // The automatic tier: the synchronous heuristic route.
        $tokenLimit = $this->meter()->limit($this->backend());
        $tokenCount = $this->estimate($this->history);
        $wireHistory = CompactionService::compactionWire($this->history);
        $baseHistory = $this->history;
        $reports = [];
        $refilled = null;
        // Which route the dispatch takes is the shared judgement
        // SessionTurnRules::compactionRoute() (TUIARCH-04, mirroring
        // Chat::submit()'s tiers): `off` skips the automatic tier yet still
        // refuses an over-window prompt with the blocking refusal. What the
        // COMPACT route then spends stays this driver's own.
        $config = $this->compactor->config();
        // ISS-015: the tiers read the calibrated estimate plus tool-argument
        // bytes (the status bar's figure), not the content-only wire tally.
        $tierTokenCount = CompactionService::tierTokenCount($tokenCount, $this->history);
        $route = SessionTurnRules::compactionRoute(
            $config->autoCompacts(),
            fn(): bool => $this->compactor->shouldCompactForeground($wireHistory, $tokenLimit, $tierTokenCount),
            fn(): bool => $this->compactor->shouldCompact($wireHistory, $tokenLimit, $tierTokenCount),
        );
        if ($route === SessionTurnRules::ROUTE_FOREGROUND_BLOCKED) {
            $rows = $this->compaction()->foregroundBlockedRows('', $this->history, $tokenCount, $tokenLimit);

            return TurnTicket::refused(array_pop($rows)->content);
        }
        if ($route === SessionTurnRules::ROUTE_COMPACT) {
            $compaction = $this->compaction();
            if (IdleCompactionPolicy::thrashTripped($this->consecutiveRefills, $config->refillLimit)) {
                return TurnTicket::refused($compaction->thrashBreakerNotice($config->refillLimit));
            }

            // ISS-064: a landed ahead-of-need summary whose fingerprint still
            // matches this history is spliced in — the tier gets the model's
            // records for the exchanges it is about to condense. Empty or
            // stale passes nothing extra: the heuristic lines as before.
            $spliced = null;
            $held = $this->aheadOfNeed;
            if ($held !== null && ($held['summaries'] ?? []) !== []
                && $held['fingerprint']->matches($compaction->historyFingerprint($this->compactor, $this->history))
            ) {
                $spliced = CompactionService::splicedSummaries($held['summaries'], $this->history);
                // Used up either way: the history it described is rewritten
                // below, and the next 70% dispatch asks afresh.
                $this->aheadOfNeed = null;
            }
            // CTX-4 on the served route: the slot holds a HOLD verdict's
            // detail instead of splices. Nothing is refused — a served turn
            // parks nothing and holding it would 400 a request already
            // accepted — the fold the tier writes is the heuristic one, so the
            // fold's own report carries the advisory naming the audit.
            $safeguardFold = null;
            if ($spliced === null && $held !== null && ($held['safeguardHold'] ?? null) !== null
                && $held['fingerprint']->matches($compaction->historyFingerprint($this->compactor, $this->history))
            ) {
                $safeguardFold = $held['safeguardHold'];
                $this->aheadOfNeed = null;
            }

            $tier = $turns->inlineTier(
                $compaction,
                $this->compactor,
                $this->history,
                $wireHistory,
                $tokenLimit,
                $tokenCount,
                $this->estimate(...),
                $spliced ?? [],
            );
            if ($tier['outcome'] === 'blocked') {
                // As the TUI's refusal does, the rewrite is kept: each further
                // attempt drops the oldest exchange, so a resend gets through.
                $this->consecutiveRefills = $compaction->refillCount($this->consecutiveRefills, $tier['refilled'], false);
                $rows = $compaction->foregroundBlockedRows('', $tier['history'], $tier['tokenCount'], $tokenLimit, $tier['compactionNotice']);
                $refusal = array_pop($rows);
                $this->history = $rows;
                $this->save();

                return TurnTicket::refused($refusal->content);
            }
            $baseHistory = $tier['history'];
            $this->announceCompaction($tier, $tokenCount);
            if ($safeguardFold !== null) {
                // Cause before effect: the advisory names the audit, the
                // tier's own report then measures the fold it describes.
                $reports[] = Message::notice($compaction->safeguardFoldAdvisory($safeguardFold));
            }
            foreach ([$tier['compactionNotice'], $tier['truncationNotice']] as $report) {
                if ($report instanceof Message) {
                    $reports[] = $report;
                }
            }
            // The rescue exemption (ruling P8.S5-R6): a rescued dispatch
            // neither extends nor breaks the breaker's run.
            $refilled = $tier['outcome'] === 'sent' ? $tier['refilled'] : null;
        }

        // The turn hooks, gate-first, off this thread when one leaves the process.
        $hooks = $this->workspace->hooks;
        $notes = [];
        if ($hooks !== null) {
            if ($verdicts === null) {
                $verdicts = $this->fireTurnHooks($text, $resolveMentions, $draft, $attachments, $modeCeiling, $oneTurnModel, $commandModelNotice);
                if ($verdicts === null) {
                    return TurnTicket::pending();
                }
            }

            [$prompt, $session] = $verdicts;
            $blocked = $turns->turnHookRefusalReason($prompt);
            if ($blocked !== null) {
                return TurnTicket::refused($blocked);
            }
            $notes = $turns->turnHookNotes($prompt, $session);
        }

        return $this->dispatch($text, $resolveMentions, $draft, $baseHistory, $reports, $notes, $refilled, $attachments, $modeCeiling, $oneTurnModel, $commandModelNotice);
    }

    /**
     * Fire `UserPromptSubmit` and, into an empty history, `SessionStart` for
     * one submission — this host's dispatch site for both, as
     * `Chat::dispatchTurnHooks()` is the TUI's. In process, the verdicts come
     * back now; when a hook leaves the process the chain is forked
     * (audit 15b-04), the session is held, null is returned, and
     * {@see admitPrompt()} is re-entered with the verdicts when they land.
     * The two C-EXT-2.e tail parameters ride the fork so a command's one-turn
     * model pin and its fall-back notice survive the chain.
     *
     * @return array{0: HookResult, 1: ?HookResult}|null
     */
    private function fireTurnHooks(string $text, bool $resolveMentions, string $draft, array $attachments = [], ?PermissionMode $modeCeiling = null, ?string $oneTurnModel = null, ?string $commandModelNotice = null): ?array
    {
        $hooks = $this->workspace->hooks ?? throw new \LogicException('fireTurnHooks() needs a hook manager');
        $turns = $this->turns();
        $firesSessionStart = \count($this->history) === 0;
        $promptEvent = HookEvent::UserPromptSubmit;
        $sessionEvent = HookEvent::SessionStart;
        $promptContext = $turns->turnHookContext($promptEvent->value, $text, false, $this->sessionId, $this->root());
        $sessionContext = $firesSessionStart
            ? $turns->turnHookContext($sessionEvent->value, $text, true, $this->sessionId, $this->root())
            : null;
        $run = static fn (): array => TurnController::runTurnHooks($hooks, $promptContext, $sessionContext);

        if (!$turns->turnHooksMustFork($hooks, $firesSessionStart ? [$promptEvent, $sessionEvent] : [$promptEvent])) {
            return $run();
        }

        $this->park(
            static function () use ($run): string {
                [$prompt, $session] = $run();

                return TurnController::turnHookPayload($prompt, $session);
            },
            static fn (string $file): array => TurnController::turnHookResultsFromPayload(TurnController::takePayload($file)),
            $run,
            function (array $verdicts) use ($text, $resolveMentions, $draft, $attachments, $modeCeiling, $oneTurnModel, $commandModelNotice): void {
                $this->admitPrompt($text, $resolveMentions, $draft, $verdicts, $attachments, $modeCeiling, $oneTurnModel, $commandModelNotice);
            },
        );

        return null;
    }

    /**
     * Hold the session behind $childWork in a forked child
     * ({@see TurnController::forkPayload()}) and run $resume with what it
     * reported — unless the hold was cancelled meanwhile. Whatever the resume
     * leaves idle releases the queue, as every turn end does.
     *
     * @param \Closure(): string $childWork
     * @param \Closure(string): array<int, mixed> $collect
     * @param \Closure(): array<int, mixed> $inline
     * @param \Closure(array<int, mixed>): void $resume
     */
    private function park(\Closure $childWork, \Closure $collect, \Closure $inline, \Closure $resume): void
    {
        $cancellation = new CancellationToken();
        $this->turn = $cancellation;
        $generation = ++$this->generation;

        TurnController::forkPayload($childWork, $collect, $inline, $cancellation)->then(
            function (?array $result) use ($generation, $resume): void {
                if ($result === null || $generation !== $this->generation) {
                    return;
                }
                $this->turn = null;
                $resume($result);
                if ($this->turn === null) {
                    $this->releaseQueue();
                }
            },
        );
    }
}

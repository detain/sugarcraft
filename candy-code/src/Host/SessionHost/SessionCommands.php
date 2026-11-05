<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\SessionHost;

use SugarCraft\Code\Host\CompactionService;
use SugarCraft\Code\Host\SessionEvent;
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
trait SessionCommands
{
    public function runCommand(string $name, string $args = ''): CommandResult
    {
        $name = ltrim(trim($name), '/');
        $text = trim('/' . $name . ' ' . trim($args));

        if ($this->isBusy()) {
            // `$options` is never consulted for a '/'-prefixed name before
            // the table refuses it, so the default here cannot change the
            // verdict — the table is what it is for the keyboard's door.
            $route = $this->turns()->midTurnRoute($text, $this->isWorkflowControl($text), SubmitOptions::new());
            if ($route !== TurnController::ROUTE_WORKFLOW_CONTROL
                && $route !== TurnController::ROUTE_SIDE_QUESTION
            ) {
                return CommandResult::refused($this->turns()->hostCommandNotice($text));
            }
        }

        $result = $this->dispatchCommand($text)
            ?? CommandResult::refused(Lang::t('host.command.unknown', ['name' => $name]));
        if (!$result->isRefused()) {
            $this->applyCommandResult($result, trim($name));
        }

        return $result;
    }

    private function command(string $text): ?TurnTicket
    {
        $result = $this->dispatchCommand($text);
        if ($result === null) {
            return null;
        }
        if ($result->isRefused()) {
            return TurnTicket::refused((string) $result->error);
        }

        // C-CTX-2.d: the dispatched name rides into the result application
        // so the context-changing roster can be keyed on it (see
        // {@see self::CONTEXT_CHANGING_COMMANDS}); the parser's own name is
        // what dispatchCommand matched, including the bare-MCP-auth shape.
        $parsed = (new CommandParser())->parse($text);
        $this->applyCommandResult($result, $parsed?->name ?? ltrim(CommandText::tokens($text)[0] ?? '', '/'));

        return TurnTicket::handled();
    }

    private function dispatchCommand(string $text): ?CommandResult
    {
        if (TurnController::isBareMcpAuthCommand($text)) {
            $spec = BuiltInCommands::forSpelling('mcp');
        } else {
            $parsed = (new CommandParser())->parse($text);
            if ($parsed === null || !str_starts_with($text, '/' . $parsed->name)) {
                return null;
            }
            $spec = BuiltInCommands::forSpelling($parsed->name);
            if ($spec !== null && !$spec->accepts($text, $parsed->name)) {
                return null;
            }
        }
        if (!$spec instanceof BuiltInCommand) {
            return null;
        }

        $command = $spec->instantiateHostCommand();
        if ($command === null) {
            return CommandResult::clientOnly($spec->name());
        }

        $result = $command->run($this->commandContext(), $text);

        // The screen-only follow-ups (the inline title editor, the media
        // form modal, the video player) are unservable here, so the headless
        // driver says so.
        return $result->effect(CommandEffectKind::OpenTitleEditor) !== null
            || $result->effect(CommandEffectKind::OpenMediaForm) !== null
            || $result->effect(CommandEffectKind::OpenVideoPlayer) !== null
            ? CommandResult::clientOnly($spec->name())
            : $result;
    }

    private function isWorkflowControl(string $text): bool
    {
        if (!$this->workflowTurn || $this->workspace->workflowEngine === null) {
            return false;
        }

        $tokens = CommandText::tokens($text);

        return $tokens[0] === '/workflow'
            && \in_array($tokens[1] ?? '', ['pause', 'status'], true)
            && $this->turns()->resolveCustomCommand($text, $this->customCommands) === null;
    }

    private function applyCommandResult(CommandResult $result, string $commandName = ''): void
    {
        // WEB-2: a prompt an init-style command queued is submitted only AFTER
        // this command's own rows have landed — the transcript reads echo,
        // marker, turn, exactly the order the TUI's `withInputBuf(...)->submit()`
        // route produces.
        $queuedPrompt = '';
        // C-WEB-1.a: a served `/model` or `/rename` changes what the session
        // summary says without changing its status, so the feed's status lane
        // never announces it; this flag carries the one announcement the
        // bound hook owes.
        $summaryChanged = false;
        foreach ($result->effects as $effect) {
            switch ($effect->kind) {
                case CommandEffectKind::ClearTranscript:
                    $this->history = [];
                    // The breaker counted rewrites of the transcript just cleared.
                    $this->consecutiveRefills = 0;
                    break;

                case CommandEffectKind::RestoreCheckpoint:
                    $this->history = $effect->messages();
                    break;

                case CommandEffectKind::Async:
                    $this->runAsync($effect);
                    break;

                case CommandEffectKind::OccupyTurn:
                    $this->occupy($effect);
                    break;

                case CommandEffectKind::SetPermissionGrants:
                    $this->grants = $effect->grants() ?? SessionPermissionMemo::new();
                    if (!$this->ephemeral) {
                        $this->transcripts->savePermissionGrants($this->sessionId, $this->grants->grants());
                    }
                    break;

                case CommandEffectKind::SetPermissionMode:
                    $this->setPermissionMode($effect->permissionMode());
                    break;

                case CommandEffectKind::SetModel:
                    $built = $effect->modelBackend();
                    if ($built !== null) {
                        $this->sessionBackend = $built;
                        // C-WEB-1.a: the names ride beside the backend. A
                        // provider-only switch names no model on the wire;
                        // the model the built engine resolved IS the one the
                        // session now runs, so the override carries that
                        // truth instead of blanking what the web reads.
                        $named = $effect->modelName();
                        if ($named === '' && $built instanceof EngineBackend) {
                            $named = $built->model();
                        }
                        $this->modelOverride = ['provider' => $effect->modelProvider(), 'model' => $named];
                        $summaryChanged = true;
                    }
                    break;

                case CommandEffectKind::SetPrimaryAgent:
                    // C-AGT-4.b: the host records the session's choice the
                    // way the SetModel arm records the pair — name only, no
                    // config write, host-lifetime. `default` (and a plain
                    // `/agent use default`) stores null, which is the same
                    // state a fresh host starts in. MAJOR-2: the name is not
                    // merely stored — every turn dispatch reads it through
                    // backend() and runs narrowed while it stands.
                    $this->primaryAgentOverride = $effect->primaryAgentName();
                    // C-AGT-4.c: the choice is summary state (the wire carries
                    // it), so a switch announces session.updated exactly like
                    // the SetModel arm above — no client re-poll needed.
                    $summaryChanged = true;
                    break;

                case CommandEffectKind::CompactTranscript:
                    $this->typedCompact($effect->compactText());
                    break;

                case CommandEffectKind::SubmitPrompt:
                    $queuedPrompt = $effect->promptText();
                    break;

                case CommandEffectKind::RenameSession:
                    // C-WEB-1.a: the rename is already in the store (the host
                    // keeps no title in memory), but a served rename changed
                    // nothing the web's sidebar could hear — the summary only
                    // re-announced on a status transition. Flag it here.
                    $summaryChanged = true;
                    break;

                case CommandEffectKind::SwitchSession:
                case CommandEffectKind::OpenTitleEditor:
                    break;
            }
        }

        $this->history = [...$this->history, ...$result->rows];
        $this->save();
        foreach ($result->rows as $row) {
            $this->announceTranscriptRow($row);
        }

        // C-WEB-1.a: announced AFTER the command's own transcript rows — the
        // stream a client reads stays in the order the transcript settled —
        // and only once, however many summary-changing effects one command
        // carried.
        if ($summaryChanged && $this->onSummaryChanged !== null) {
            ($this->onSummaryChanged)();
        }

        // C-CTX-2.d: the handful of built-ins whose answer rewrites what the
        // prompt is built from — `/rules` toggles a rule pack, `/memory`
        // writes a note, `/init` sets an instruction file in motion — all
        // return plain reply rows no effect marks, so the roster is keyed on
        // the name the caller dispatched. A client hears `context.changed`
        // and re-reads `context.sources`; commands outside the roster cannot
        // move it, so they stay quiet.
        if ($commandName !== '' && \in_array($commandName, self::CONTEXT_CHANGING_COMMANDS, true)) {
            $this->announce(SessionEvent::new(SessionEvent::CONTEXT_CHANGED, ['reason' => $commandName], $this->sessionId));
        }

        if ($queuedPrompt !== '') {
            $ticket = $this->submit($queuedPrompt);
            if (!$ticket->isAdmitted()) {
                // The turn never went out (cap, blocking tier, a refusing
                // hook): the reason lands where the user just watched the
                // command settle, the way the TUI's stopped-goal row does.
                $this->appendCommandRow((string) $ticket->reason, true);
            }
        }
    }

    private function typedCompact(string $text): void
    {
        $compaction = $this->compaction();
        $held = $this->aheadOfNeed;
        $matches = $held !== null
            && $held['fingerprint']->matches($compaction->historyFingerprint($this->compactor, $this->history));

        if ($matches) {
            $this->aheadOfNeed = null;
            if ($held['safeguardHold'] !== null) {
                // The fold's own echo never lands on this branch, so the
                // refusal carries it: the draft must stay visible in the
                // transcript even though nothing was condensed.
                $this->appendMessageRow(Message::user($text)->withUiOnly());
                $this->appendMessageRow(Message::notice($compaction->safeguardHoldRefusal($held['safeguardHold'])));

                return;
            }
        }

        $summaries = $matches && ($held['summaries'] ?? []) !== []
            ? CompactionService::splicedSummaries($held['summaries'], $this->history)
            : [];

        $before = count($this->history);
        $this->history = $compaction->compactedHistory(
            $this->compactor,
            $text,
            $this->history,
            $summaries,
            fn (): int => $this->meter()->limit($this->backend()),
            $this->estimate(...),
        );
        $this->save();
        foreach (array_slice($this->history, $before) as $row) {
            $this->announceTranscriptRow($row);
        }

        // C-CTX-2.d: the fold rewrote the history the token breakdown and the
        // spliced sources ride on — but only past the safeguard-hold door,
        // which returns above without folding anything.
        $this->announce(SessionEvent::new(SessionEvent::CONTEXT_CHANGED, ['reason' => 'compaction'], $this->sessionId));
    }

    private function runAsync(CommandEffect $effect): void
    {
        $describe = $effect->describe();
        try {
            $promise = ($effect->run())();
        } catch (\Throwable $e) {
            $this->appendCommandRow(Lang::t('host.command.error', ['error' => $e->getMessage()]), true);

            return;
        }

        $promise->then(
            function (mixed $value) use ($describe): void {
                $row = $describe === null ? null : $describe($value);
                if ($row instanceof Message) {
                    $this->appendMessageRow($row);
                }
            },
            function (\Throwable $e): void {
                $this->appendCommandRow(Lang::t('host.command.error', ['error' => $e->getMessage()]), true);
            },
        );
    }

    private function occupy(CommandEffect $effect): void
    {
        $cancellation = $effect->cancellation() ?? new CancellationToken();
        $this->turn = $cancellation;
        $this->workflowTurn = $effect->isWorkflow();
        $generation = ++$this->generation;

        $land = function (string|Message $report, bool $error = false) use ($generation): void {
            // A Message report carries its own verdict (ISS-097); a bare
            // string is an ordinary local answer.
            $report instanceof Message
                ? $this->appendMessageRow($report)
                : $this->appendCommandRow($report, $error);
            if ($generation !== $this->generation) {
                // Cancelled: the report is the record of what ran, and the
                // session it held was released by the cancel.
                return;
            }
            $this->turn = null;
            $this->workflowTurn = false;
            $this->releaseQueue();
        };

        try {
            $promise = ($effect->run())();
        } catch (\Throwable $e) {
            $land(Lang::t('host.command.error', ['error' => $e->getMessage()]), true);

            return;
        }

        $promise->then($land, static fn (\Throwable $e) => $land(Lang::t('host.command.error', ['error' => $e->getMessage()]), true));
    }

    private function appendCommandRow(string $text, bool $error = false): void
    {
        $this->appendMessageRow(Message::assistant($text)->withUiOnly()->withError($error));
    }

    private function commandContext(): CommandContext
    {
        // The session's own view of its backend: an override this host's
        // `/model` stored must be what the bodies measure and switch from
        // (WEB-2); with no override this is exactly what was read before.
        $backend = $this->sessionBackend ?? $this->workspace->backend;

        return CommandContext::new(
            history: $this->history,
            sessionId: $this->sessionId,
            root: $this->workspace->root,
            permissionGate: $this->workspace->permissionGate,
            backend: $backend,
            titleBackend: $this->workspace->titleBackend,
            agentManager: $this->workspace->agentManager,
            sessionStore: $this->workspace->sessionStore,
            transcripts: $this->transcripts,
            turnRunner: $this->runner(),
            rulesState: $this->workspace->rulesState,
            workflowEngine: $this->workspace->workflowEngine,
            backgroundSupervisor: $this->workspace->backgroundSupervisor,
            memoryStore: $this->workspace->memoryStore,
            contextTokenLimit: $backend === null ? null : $this->meter()->limit($backend),
            contextTokens: $this->estimate($this->history),
            workspace: $this->workspace,
            grants: $this->grants,
            scheduleRegistry: $this->workspace->service(\SugarCraft\Code\Background\ScheduleRegistry::class),
            spendTracker: $this->spend,
            spendCap: $this->workspace->maxCostUsd,
        );
    }
}

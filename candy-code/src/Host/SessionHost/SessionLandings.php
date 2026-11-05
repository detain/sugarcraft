<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\SessionHost;

use SugarCraft\Code\Host\SessionEvent;
use SugarCraft\Code\Host\TitleService;
use SugarCraft\Code\Host\TurnRunner;

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
trait SessionLandings
{
    /**
     * Land a prompt-suggestion answer (C-CTX-2.d, mirroring Chat's
     * `PromptSuggestionMsg` arm): billed FIRST whatever becomes of it — the
     * guess ran on the user's key on the title engine, so the OBS-3 model
     * the ledger sees is that engine's — then shown to the composer as the
     * ephemeral `prompt.suggested` event only while it is still current:
     * same generation, same history length, same session, nothing in flight.
     * A suggestion is NEVER persisted — the point is a ghost prompt the user
     * may decline, and a transcript row would show the model its own guess
     * as conversation on the next turn. An answer arriving late, or after
     * {@see self::release()}, is billed and dropped.
     */
    private function landPromptSuggestion(mixed $msg): void
    {
        if (!$msg instanceof PromptSuggestionMsg) {
            return;
        }

        $title = $this->workspace->titleBackend;
        $this->ledger()->account($this->spend, $msg->usage, $this->usageRecordSink(), $title instanceof EngineBackend ? $title->model() : null, 'aux');
        if ($this->released) {
            return;
        }

        // Chat's staleness test verbatim: any of these means the guess is no
        // longer about the turn that settled — a new turn moved the
        // generation, a queued prompt or a landed notice row moved the
        // history, and an in-flight turn has its own settle coming.
        $text = TitleService::sanitizeSuggestion($msg->suggestion);
        $stale = $msg->generation !== $this->generation
            || $msg->historyCount !== count($this->history)
            || $msg->sessionId !== $this->sessionId;
        if ($text === '' || $stale || $this->turn !== null) {
            return;
        }

        $this->announce(SessionEvent::new(SessionEvent::PROMPT_SUGGESTED, [
            'text' => $text,
            'generation' => $msg->generation,
        ], $this->sessionId));
    }

    /**
     * Land a consolidation answer (C-CTX-2.c, mirroring Chat's
     * `MemoryConsolidatedMsg` arm): billed FIRST whatever the outcome, on the
     * model the summary engine actually ran (the TUI's OBS-3 rule — the
     * ledger must not bill a call to a model that did not make it), then the
     * producer's own notice row, or nothing when the run saved nothing.
     * An answer arriving after {@see self::release()} is billed and dropped.
     */
    private function landConsolidation(mixed $msg): void
    {
        if (!$msg instanceof MemoryConsolidatedMsg) {
            return;
        }

        $summary = $this->workspace->summaryBackend;
        $this->ledger()->account($this->spend, $msg->usage, $this->usageRecordSink(), $summary instanceof EngineBackend ? $summary->model() : null, 'compact');
        if ($this->released) {
            return;
        }

        $notice = AutoMemoryConsolidator::notice($msg);
        if ($notice === null) {
            return;
        }

        $this->appendMessageRow(Message::notice($notice));
    }

    /**
     * Land an auto-commit answer (C-CTX-2.c, mirroring Chat's
     * `AutoCommittedMsg` arm): the commit is already made (or refused), so
     * the subject's provider call is billed first — on the title engine's
     * model per OBS-3 — and the line saying what was committed, or why not,
     * ALWAYS shows. An answer arriving after {@see self::release()} is
     * billed and dropped.
     */
    private function landAutoCommit(mixed $msg): void
    {
        if (!$msg instanceof AutoCommittedMsg) {
            return;
        }

        $title = $this->workspace->titleBackend;
        $this->ledger()->account($this->spend, $msg->usage, $this->usageRecordSink(), $title instanceof EngineBackend ? $title->model() : null, 'aux');
        if ($this->released) {
            return;
        }

        $this->appendMessageRow(Message::notice($msg->notice()));
    }

    /**
     * Land a dream-pass answer (C-CTX-2.c, mirroring Chat's
     * `DreamPassCompletedMsg` arm): billed first — the pass ran on the
     * session's own engine, so the engine {@see self::runSettleWork()} handed
     * the table IS the OBS-3 model here — then the producer's notice, or
     * nothing when the pass neither saved nor proposed. An answer arriving
     * after {@see self::release()} is billed and dropped.
     */
    private function landDream(mixed $msg, ?Backend $engine): void
    {
        if (!$msg instanceof DreamPassCompletedMsg) {
            return;
        }

        $this->ledger()->account($this->spend, $msg->usage, $this->usageRecordSink(), $engine instanceof EngineBackend ? $engine->model() : null, 'aux');
        if ($this->released) {
            return;
        }

        $notice = DreamPass::notice($msg);
        if ($notice === null) {
            return;
        }

        $this->appendMessageRow(Message::notice($notice));
    }

    /**
     * Hold the turn slot and ask the title model to judge the live goal
     * (W7a-3, mirroring {@see Chat::scheduleGoalJudge()} exactly): the bump
     * of the generation strands anything already in flight, and the verdict
     * lands through {@see self::landGoalVerdict()} on this promise's own
     * channel — the same shape the ahead-of-need compaction uses.
     */
    private function scheduleGoalJudge(GoalState $goal): void
    {
        $judge = $this->workspace->titleBackend;
        $stop = GoalLoop::refusalReason($judge, $this->ledger()->capReached($this->spend, $this->workspace->maxCostUsd));
        if ($stop !== null || $judge === null) {
            $this->endGoal($goal, GoalState::STOPPED, Lang::t('chat.goal.check_stopped', [
                'reason' => $stop,
                'command' => $goal->mode->command(),
            ]));

            return;
        }

        $this->generation++;
        $this->turn = new CancellationToken();
        // C-CTX-2.a: the judge's fork runs under THIS session's sink, the
        // same window rule the ahead-of-need request keeps — its records
        // (a parser warning off the title call, say) are this session's.
        RuntimeNoticeSink::using($this->notices, fn (): PromiseInterface => GoalJudge::new()->call(
            $judge,
            $goal->condition,
            $this->history,
            $this->generation,
            $this->sessionId,
        )())->then(function (mixed $msg): void {
            $this->landGoalVerdict($msg);
        });
    }

    /**
     * Land the `/goal` judge's verdict on a served session (W7a-3): the
     * judge's usage is billed first — stamped with the JUDGE's model, the
     * threaded sink from W7a-1 — then the shared {@see GoalLoop} table says
     * whether the goal ended (met, budget spent, no usable verdict) or the
     * agent goes back to work with what is still missing.
     *
     * A prompt the user queued while the turn ran goes first and the goal
     * waits: the user's own message is the conversation carrying on, and the
     * turn it starts is judged when it settles like any other. The follow-up
     * prompt goes out through {@see self::submit()}, so every admission gate
     * applies to it, refusal row and all.
     */
    private function landGoalVerdict(mixed $msg): void
    {
        if (!$msg instanceof GoalJudgedMsg) {
            return;
        }

        $judge = $this->workspace->titleBackend;
        $this->ledger()->account($this->spend, $msg->usage, $this->usageRecordSink(), $judge instanceof EngineBackend ? $judge->model() : null, 'goal');
        if ($msg->generation !== $this->generation || $this->turn === null || $msg->sessionId !== $this->sessionId) {
            // A verdict that raced a cancel or a newer turn: billed, never
            // acted on — the live turn owns the decision now.
            return;
        }

        $this->turn = null;
        $goal = GoalState::activeIn($this->history);
        if ($goal === null) {
            // `/goal clear` (or a resume that rewrote the transcript) during
            // judging: nothing to end, keep the queue moving.
            $this->releaseQueue();

            return;
        }

        if ($msg->verdict === null) {
            $this->endGoal($goal, GoalState::STOPPED, GoalLoop::stopped($goal, $msg->error)->notice);

            return;
        }

        $outcome = GoalLoop::afterVerdict($goal, $msg->verdict);
        if ($outcome->kind !== GoalLoop::CONTINUE) {
            $this->endGoal($goal, $outcome->status, $outcome->notice);

            return;
        }

        if ($this->queue !== []) {
            // The user's own message goes first and the goal waits — the
            // turn it starts is judged when it settles like any other.
            $this->releaseQueue();

            return;
        }

        $this->appendMessageRow($outcome->next->toMessage());
        $ticket = $this->submit($outcome->nextPrompt);
        if (!$ticket->isAdmitted()) {
            $this->appendCommandRow((string) $ticket->reason, true);
        }
    }

    /**
     * End $goal with $status and say so in $notice, then let the queue move —
     * the served twin of {@see Chat::endGoal()}/{@see Chat::goalEnded()}
     * minus the TUI's terminal-notification, which a headless session has no
     * terminal to notify.
     */
    private function endGoal(GoalState $goal, string $status, string $notice): void
    {
        $this->appendMessageRow(Message::notice($notice));
        $this->appendMessageRow($goal->withStatus($status)->toMessage());
        $this->releaseQueue();
    }

    /**
     * Roadmap 5.7-2: a plan the user approved through `PlanExit` ends plan
     * mode once its turn is over — the mode the approval names, else
     * `default` — and only while the session is still in `plan`, before a
     * queued prompt starts the next turn ({@see TurnRunner::approvedPlanExit()}).
     */
    private function applyApprovedPlanExit(): void
    {
        if ($this->permissionMode() !== PermissionMode::Plan) {
            return;
        }
        $switch = TurnRunner::approvedPlanExit($this->history);
        if ($switch !== null) {
            $this->setPermissionMode($switch->mode ?? PermissionMode::Default);
        }
    }
}

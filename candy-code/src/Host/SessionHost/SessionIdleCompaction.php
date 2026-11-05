<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\SessionHost;

use SugarCraft\Code\Host\CompactionService;

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
trait SessionIdleCompaction
{
    /**
     * Put out the ahead-of-need summarisation request (roadmap 2.10) and
     * latch its landing slot — the block the 70% dispatch tier used to carry
     * inline, shared now with the CTX-5 idle watch. Sharing is the point:
     * the timer's whole trick is to run the very request the next dispatch
     * would have asked for, through the same gate, so the served splice —
     * billing included — never sees two sources and never pays twice.
     *
     * @param list<Message> $dispatched the history the summaries are asked against
     * @param list<Message> $baseHistory the conversation to summarise
     * @return bool whether a request actually went out
     */

    private function requestAheadOfNeed(array $dispatched, array $baseHistory): bool
    {
        $request = $this->compaction()->buildSummarizationRequest(
            $this->backend(),
            $this->compactor,
            $dispatched,
            null,
            null,
            '',
            true,
            $baseHistory,
        );
        if ($request === null) {
            return false;
        }

        $this->aheadOfNeed = [
            'id' => $request['id'],
            'fingerprint' => $request['fingerprint'],
            'summaries' => null,
            'safeguardHold' => null,
        ];
        // C-CTX-2.a: this request forks a model call from either of its
        // callers — the 70% dispatch tier or the CTX-5 idle timer — so it
        // carries its own using() window: the summaries' child records land
        // in THIS session's inbox wherever the request went out from, and
        // the queue fallback for a sink without transport fills the same way.
        RuntimeNoticeSink::using($this->notices, fn (): PromiseInterface => $request['promise']())
            ->then(function (HistoryCompactedMsg $msg) use ($request): void {
            // Billed before the latch check, as the TUI bills it: a
            // paid-for provider call is never unbilled, even when the
            // summaries it carried are superseded on arrival.
            $this->ledger()->account($this->spend, $msg->usage, $this->usageRecordSink(), null, 'compact');
            if ($this->aheadOfNeed === null || $this->aheadOfNeed['id'] !== $request['id']) {
                return;
            }
            // CTX-4: a HOLD is remembered so the tier that lands the
            // heuristic fold can say why, but its summaries are
            // discarded and never spliced.
            $this->aheadOfNeed['summaries'] = $msg->error === null && $msg->blockedBy === null && $msg->safeguardHold === null
                ? $msg->summaries
                : [];
            $this->aheadOfNeed['safeguardHold'] = $msg->safeguardHold;
        });

        return true;
    }

    /**
     * (Re-)arm the CTX-5 idle auto-compaction watch. Called from every event
     * that RE-STARTS the idle window: an accepted prompt, a settled turn, and
     * an alarm that fired into a running turn (C-CTX-6.a — dropping the watch
     * on a busy fire forfeits the fold the opt-in exists to make). Each call
     * replaces the deadline (cancel first — a user
     * who returned must not leave an orphan alarm that fires against the
     * NEXT quiet gap and charges for it to the wrong window), and the
     * opt-out shape never arms at all, so the default-off default costs a
     * served session exactly nothing.
     *
     * The +2 s slack exists because {@see IdleCompactionPolicy::shouldAutoCompact()}
     * keeps the offer clock's strict `>` semantics while an event-loop timer's
     * wall-clock elapsed at fire time can land a hair under its deadline —
     * and, unlike the TUI's tick, this watch gets no second chance. An
     * unattended fold that waits two extra seconds — of an hour-long idle, or
     * of the 240 seconds a 5-minute cache leaves after the margin
     * (C-CTX-5.c) — is invisible; a fold whose one-shot alarm under-shot by a
     * rounding and so silently never fired until the next prompt is the bug.
     */

    private function armIdleAutoCompaction(): void
    {
        if ($this->idleAutoTimer !== null) {
            Loop::cancelTimer($this->idleAutoTimer);
            $this->idleAutoTimer = null;
        }

        $config = $this->compactor->config();
        if (!$config->idleAuto || !$config->autoCompacts()
            || $config->idleOfferSeconds <= 0 || $this->workspace->backend === null
        ) {
            return;
        }

        // C-CTX-5.c: `cacheTtl` re-times the alarm to the provider's prompt-cache
        // clock, margin included, instead of the offer window — the window still
        // gates whether a watch exists at all (the documented shape of the
        // step), and a provider that says it caches nothing for this model has
        // no clock to arm to, so its sessions simply never carry an alarm.
        $seconds = $config->idleOfferSeconds;
        if ($config->idleAutoMode === CompactorConfig::IDLE_AUTO_CACHE_TTL) {
            $ttl = IdleCompactionPolicy::cacheTtlSecondsOf($this->workspace->backend);
            if ($ttl === null) {
                return;
            }

            $seconds = max(1, $ttl - IdleCompactionPolicy::CACHE_TTL_MARGIN_SECONDS);
        }

        $markedAt = $this->lastPromptAt;
        $this->idleAutoTimer = Loop::addTimer(
            (float) ($seconds + 2),
            function () use ($markedAt): void {
                $this->idleAutoTimer = null;
                $this->onIdleAutoCompaction($markedAt);
            },
        );
    }

    /**
     * The idle watch's verdict, every condition re-derived at fire time —
     * between the arming and the alarm anything they depend on has had an
     * hour to move. Passing means exactly what passing means at the tier:
     * the shared {@see requestAheadOfNeed()}, whose landed summaries the
     * existing splice consumes on the next submit, nobody re-billed and no
     * turn ever dispatched unattended.
     */

    private function onIdleAutoCompaction(?\DateTimeImmutable $markedAt): void
    {
        // The user returned — a later prompt re-stamped the clock (and
        // re-armed) — so this is no longer an idle fold; the ordinary tiers
        // take it. The stamp identity alone says it: every event that moves
        // the window re-arms, so a live alarm always carries the current
        // prompt stamp.
        if ($this->lastPromptAt !== $markedAt) {
            return;
        }

        // C-CTX-6.a: a running turn is a momentary silence, not the end of
        // the watch. The TUI's tick simply re-checks the next minute and
        // loses nothing; this one-shot hands itself to the next gap with the
        // re-arm below, so the fold is judged from the settle instead of
        // forfeited to the turn that outlived the alarm.
        if ($this->isBusy()) {
            $this->armIdleAutoCompaction();

            return;
        }

        $config = $this->compactor->config();
        // One request per cycle (the dispatch mirror's own rule), nobody to
        // pay while the cap is breached, and the breaker: a fold run that
        // keeps refilling without a turn in between is the loop the tier
        // already refuses to start, and an unattended one has even less
        // business being started.
        if ($this->aheadOfNeed !== null
            || $this->workspace->backend === null
            || $this->ledger()->capReached($this->spend, $this->workspace->maxCostUsd)
            || IdleCompactionPolicy::thrashTripped($this->consecutiveRefills, $config->refillLimit)
        ) {
            return;
        }

        // Idle since the LATER of the two activity stamps — the prompt, or
        // the settle of the turn that ran after it (C-CTX-6.a). Measuring
        // from the prompt alone would count the turn itself as silence and
        // bill a fold moments after a reply landed.
        $idleAnchor = $this->lastPromptAt;
        if ($this->lastSettledAt !== null
            && ($idleAnchor === null || $this->lastSettledAt > $idleAnchor)
        ) {
            $idleAnchor = $this->lastSettledAt;
        }

        // The tier itself, measured exactly as submit() measures it, AND-ed
        // with the shared idle clock so one policy answers both verdicts —
        // except in `cacheTtl` mode (C-CTX-5.c), where the policy's cache
        // predicate replaces the over-tier test with its below-the-tier floor
        // and re-measures the provider's cache clock against the same anchor.
        $windowLimit = $this->meter()->limit($this->backend());
        $tierTokens = CompactionService::tierTokenCount($this->estimate($this->history), $this->history);
        $foldDue = $config->idleAutoMode === CompactorConfig::IDLE_AUTO_CACHE_TTL
            ? IdleCompactionPolicy::shouldAutoCompactAtCacheTtl(
                $this->workspace->backend,
                $tierTokens,
                $windowLimit,
                $idleAnchor,
            )
            : IdleCompactionPolicy::shouldAutoCompact(
                $this->compactor->shouldCompact(
                    CompactionService::compactionWire($this->history),
                    $windowLimit,
                    $tierTokens,
                ),
                $idleAnchor,
                $config->idleAuto,
                idleSeconds: $config->idleOfferSeconds,
            );

        if (!$foldDue) {
            // C-CTX-5.d: the fold declined this alarm. Where keep-warm is on,
            // the silence may instead buy a cached-read refresh — one ping,
            // capped per stretch by the same policy the TUI's tick consults.
            $this->keepWarmServed($tierTokens, $windowLimit, $idleAnchor);

            return;
        }

        if ($this->requestAheadOfNeed($this->history, $this->history)) {
            // MINOR-1, round-1 review: stamp the model this ask went out
            // under, exactly like the dispatch arm below does. Without it a
            // model-A idle prefetch survived a SetModel and spliced its
            // records into the model-B session — the very harm the switch
            // rule exists to prevent.
            $idleEngine = $this->backend();
            $idleModel = $idleEngine instanceof EngineBackend ? $idleEngine->model() : '';
            $this->aheadOfNeed = [...$this->aheadOfNeed, 'model' => $idleModel];
            // The human learns what the machine did while nobody was
            // watching, in the transcript, like the advisory above it.
            // Message::notice() is uiOnly by construction, and
            // compactionWire() reads agent-visible rows only — the row
            // cannot move the fingerprint the splice validates against.
            $row = Message::notice(Lang::t('chat.compact.idle_auto_scheduled', [
                'tokens' => $this->estimate($this->history),
                'limit' => $this->meter()->limit($this->backend()),
            ]));
            $this->history[] = $row;
            $this->save();
            $this->announceTranscriptRow($row);
        }
    }

    /**
     * Served keep-warm (C-CTX-5.d) — the alarm-side twin of the TUI's
     * `Chat::handleKeepWarmTick()`, consulted at exactly the point where the
     * fold declined this fire. A ping goes out over the session engine's own
     * frozen-prefix route (the conversation as a turn would send it plus one
     * instruction row asking for a one-character reply), lands billed under
     * the `warm` source label like any other call the ledger has ever seen,
     * and — because this host carries a one-shot alarm where the TUI has a
     * 60-second tick — re-arms the very same idle watch so the stretch stays
     * watched and its cap keeps counting down. A fire that meets a ping
     * still out finds the latch and decides nothing; no timeout is armed on
     * the ask (E646), so a stalled ping is a held latch and nothing louder.
     *
     * C-CTX-6.a's rule holds throughout: the fold, when it IS due, still
     * owns this alarm untouched — this method is reached only from the
     * `!$foldDue` branch above it.
     */

    private function keepWarmServed(int $tierTokens, int $windowLimit, ?\DateTimeImmutable $idleAnchor): void
    {
        // One anchor per fire, computed here like the TUI twin: the verdict
        // and the latch must count against the SAME idle stretch, and a
        // second read of the stamp could only ever disagree with the first.
        $anchorTs = $idleAnchor?->getTimestamp();
        if ($anchorTs === null) {
            return; // no stretch to speak of: nothing's cache is going cold
        }

        $backend = $this->workspace->backend;
        if (!KeepWarmPolicy::shouldPing(
            $this->workspace->userConfig,
            $this->sessionId,
            $backend,
            $tierTokens,
            $windowLimit,
            foldDue: false, // reached only where the fold just declined
            turnInFlight: $this->isBusy(),
            idleAnchorTs: $anchorTs,
        )) {
            return;
        }

        if (!$backend instanceof SummarisesWithCache) {
            return;
        }

        if (!CacheHealthWatch::noteWarmPing($this->sessionId, $anchorTs)) {
            return;
        }

        $history = $this->history;
        $sessionId = $this->sessionId;
        $backend->summariseAsync($history, KeepWarmPolicy::PING_INSTRUCTION)->then(
            function (Message $reply) use ($sessionId): void {
                CacheHealthWatch::settleWarmPing($sessionId, $reply->usage !== null);
                if ($reply->usage !== null) {
                    $this->ledger()->account($this->spend, $reply->usage, $this->usageRecordSink(), null, 'warm');
                }
            },
            static function (\Throwable $failure) use ($sessionId): void {
                // A ping that never answered warmed nothing and billed
                // nothing; only the latch comes down.
                CacheHealthWatch::settleWarmPing($sessionId, false);
            },
        );

        $this->armIdleAutoCompaction();
    }
}

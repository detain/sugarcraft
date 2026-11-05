<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\SessionHost;


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
trait SessionClientTools
{
    /**
     * C-TOOL-6.h: host $specs as this client's tools for the session's NEXT
     * turns, replacing whatever $clientKey held before. Returns the
     * registration id every later answer for these tools must carry.
     * Throws with a one-word verdict when the batch is not shippable
     * ({@see ClientToolRegistry::register()}); who may call this at all —
     * a connection holding Write scope, behind `server.clientTools` — is
     * the protocol step's door (6.i), not this substrate's.
     *
     * @param list<ClientToolSpec> $specs
     */
    public function registerClientTools(string $clientKey, array $specs): string
    {
        return $this->clientTools->register($clientKey, $specs);
    }

    /**
     * C-TOOL-6.h: drop $clientKey's registrations (its connection closed —
     * the ruling that kills a client's tools with it) and settle its open
     * calls as errors, so a child blocked on one of them learns the truth
     * instead of waiting out its deadline.
     */
    public function forgetClientTools(string $clientKey): void
    {
        $this->clientTools->forget($clientKey);
        foreach ($this->pendingClientCalls as $callId => $entry) {
            if ($entry['clientKey'] !== $clientKey) {
                continue;
            }
            unset($this->pendingClientCalls[$callId]);
            $entry['pending']->cancel('the client that hosts this tool is gone');
        }
    }

    /**
     * C-TOOL-6.h: answer the open client call $callId — first answer wins
     * (the same law {@see answer()} keeps for questions). An id not open, a
     * call already settled another way, and an answer carrying a
     * registration id other than the one that opened the call all read the
     * same from here: false. A client re-registered between the call and
     * the answer is exactly the case that id exists to refuse.
     */
    public function resolveClientCall(string $callId, string $registrationId, string $content, bool $isError): bool
    {
        $entry = $this->pendingClientCalls[$callId] ?? null;
        if ($entry === null) {
            return false;
        }
        // The id must be the one THIS call was opened under: the answerer is
        // the relay that heard the notification, and nobody else's id — not a
        // fresh registration of the same client, not a stranger's — spends a
        // call it was never told about. A refused answer leaves the call
        // open: the parent's deadline settles that, not this caller.
        if ($entry['registrationId'] !== $registrationId) {
            return false;
        }
        if ($entry['pending']->isSettled()) {
            unset($this->pendingClientCalls[$callId]);

            return false;
        }
        unset($this->pendingClientCalls[$callId]);

        return $entry['pending']->resolve($content, $isError);
    }

    /**
     * C-TOOL-6.h: bind (or pass null to clear) the listener that hears each
     * freshly opened client call with the client and registration that must
     * answer it — the handle the serve relay (6.i) forwards on.
     *
     * @param (\Closure(PendingClientCall, string, string): void)|null $hook
     */
    public function onClientCall(?\Closure $hook): void
    {
        $this->clientCallListener = $hook;
    }

    /**
     * C-TOOL-6.h: the sink {@see \SugarCraft\Code\Backend\EngineBackend::withClientCallSink()}
     * takes — called in the parent when a turn child puts a client-hosted
     * call on the channel. It opens the call in {@see $pendingClientCalls}
     * under the registration that owns the name, tells the 6.i relay, and
     * leaves the answer to {@see resolveClientCall()}; the engine's own
     * deadline stays the bound either way. A call whose name no
     * registration owns (the client forgot it mid-turn, after this turn's
     * roster was fixed) is answered as an error at once rather than held.
     */
    private function clientCallSink(): \Closure
    {
        return function (PendingClientCall $pending, \Closure $answer): void {
            foreach ($this->pendingClientCalls as $callId => $entry) {
                if ($entry['pending']->isSettled()) {
                    unset($this->pendingClientCalls[$callId]);
                }
            }
            $clientKey = $this->clientTools->owner($pending->name);
            if ($clientKey === null) {
                $answer('the client that hosts this tool is gone', true);

                return;
            }
            $registrationId = (string) $this->clientTools->currentRegistrationId($clientKey);
            $this->pendingClientCalls[$pending->callId] = [
                'pending' => $pending,
                'clientKey' => $clientKey,
                'registrationId' => $registrationId,
            ];
            $hook = $this->clientCallListener;
            if ($hook !== null) {
                $hook($pending, $clientKey, $registrationId);
            }
        };
    }
}

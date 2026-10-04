<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\QueueMode;
use SugarCraft\Crush\Backend\SocketSteerInbox;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Context\ContextCompactor;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Host\CompactionService;
use SugarCraft\Crush\Host\ContextMeter;
use SugarCraft\Crush\Host\EventLog;
use SugarCraft\Crush\Host\SubmitOptions;
use SugarCraft\Crush\Host\TranscriptStore;
use SugarCraft\Crush\Host\TurnController;
use SugarCraft\Crush\Host\WorkspaceContext;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Session\EnhancedSessionStore;

/**
 * Roadmap O-2g: what a submitted line becomes is {@see TurnController}'s
 * logic, and `Chat` delegates to it. These pin the controller's answers on
 * their own and, where `Chat` writes the same sentence, that the two agree —
 * the parity the extraction promises a headless host.
 */
final class TurnControllerTest extends TestCase
{
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir === null) {
            return;
        }
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        foreach (glob($this->dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            foreach (glob($sub . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($sub);
        }
        @rmdir($this->dir);
    }

    // ── admission ──────────────────────────────────────────────────────

    public function testTheMidTurnRouteMatchesTheTuisRules(): void
    {
        $turns = TurnController::new();
        $enter = SubmitOptions::new();

        self::assertSame(TurnController::ROUTE_QUIT, $turns->midTurnRoute('/exit', true, $enter), '/exit quits even during a workflow');
        self::assertSame(TurnController::ROUTE_QUIT, $turns->midTurnRoute('/quit', false, $enter));
        self::assertSame(TurnController::ROUTE_WORKFLOW_CONTROL, $turns->midTurnRoute('/workflow pause', true, $enter));
        self::assertSame(TurnController::ROUTE_REFUSE_COMMAND, $turns->midTurnRoute('/compact', false, $enter));
        self::assertSame(TurnController::ROUTE_REFUSE_COMMAND, $turns->midTurnRoute('/exit now', false, $enter), 'only the bare spelling quits');
        self::assertSame(TurnController::ROUTE_REFUSE_COMMAND, $turns->midTurnRoute('mcp auth list', false, $enter));
        self::assertSame(TurnController::ROUTE_STEER, $turns->midTurnRoute('mcp authentication fails, why?', false, $enter), 'prose is not the bare mcp spelling (audit 15b-11)');
        self::assertSame(TurnController::ROUTE_QUEUE, $turns->midTurnRoute('!git status', false, $enter), 'a !cmd is never steered into the turn');
        self::assertSame(TurnController::ROUTE_STEER, $turns->midTurnRoute('also check the tests', false, $enter), 'Enter steers (decision D6)');
        self::assertSame(TurnController::ROUTE_QUEUE, $turns->midTurnRoute('later', false, $enter->withDelivery(QueueMode::Followup)));
        self::assertSame(TurnController::ROUTE_INTERRUPT, $turns->midTurnRoute('stop, do this', false, $enter->withDelivery(QueueMode::Interrupt)));
        self::assertSame(TurnController::ROUTE_REFUSE_COMMAND, $turns->midTurnRoute('/clear', false, $enter->withDelivery(QueueMode::Interrupt)), 'a delivery never lets a command through');
    }

    public function testADeliveredSteerCancelsExactlyOneQueuedCopyAfterTheTurnsPrompt(): void
    {
        $history = [
            Message::user('first'),
            Message::user(SocketSteerInbox::content('again'))->withUserVisible(false),
            Message::assistant('ok'),
            Message::user('second'),
            Message::user(SocketSteerInbox::content('again'))->withUserVisible(false),
            Message::assistant('done'),
        ];

        self::assertSame(
            ['again', 'other'],
            TurnController::withoutDeliveredSteers(['again', 'again', 'other'], $history),
            'one delivered row cancels one queued entry; the copy delivered BEFORE the turn\'s prompt does not count',
        );
        self::assertSame(['x'], TurnController::withoutDeliveredSteers(['x'], [Message::user('p')]));
    }

    public function testAQuotedDraftIsBoundedAndControlByteFree(): void
    {
        $quoted = TurnController::quoteDraft("\e[31mred\e[0m " . str_repeat('é', 200));

        self::assertStringNotContainsString("\e", $quoted);
        self::assertSame(TurnController::IN_FLIGHT_QUOTE_MAX_CHARS, mb_strlen($quoted, 'UTF-8'));
        self::assertStringEndsWith('…', $quoted);
    }

    /**
     * Parity: the notices `Chat` writes are the controller's sentences.
     */
    public function testChatWritesTheControllersQueueAndRefusalSentences(): void
    {
        $turns = TurnController::new();
        [$queued] = self::type(new Chat(inFlight: true, inputBuf: 'later please'), KeyType::Tab);
        self::assertSame($turns->queuedNotice(1, 'later please'), self::last($queued)->content);

        [$refused] = self::type(new Chat(inFlight: true, inputBuf: '/compact'), KeyType::Enter);
        self::assertSame($turns->inFlightCommandNotice('/compact'), self::last($refused)->content);

        [$ran] = (new Chat(inFlight: true))->runCommand('/model');
        self::assertSame($turns->runCommandInFlightNotice('/model'), self::last($ran)->content);
    }

    // ── command files ──────────────────────────────────────────────────

    public function testTheColonSpellingResolvesTheFileAndPrependsItsTail(): void
    {
        $turns = TurnController::new();
        $spec = CommandSpec::new('compact', 'd', 'Custom', template: 'Summarise $ARGUMENTS');
        $commands = ['compact' => $spec, 'deploy/staging' => CommandSpec::new('deploy/staging', 'd', 'Custom', template: 'Deploy')];

        self::assertSame([$spec, 'x y'], $turns->resolveCustomCommand('/compact:x y', $commands));
        self::assertSame('deploy/staging', $turns->resolveCustomCommand('/deploy/staging now', $commands)[0]->name ?? null);
        self::assertNull($turns->resolveCustomCommand('/nope', $commands));
        self::assertNull($turns->resolveCustomCommand('/compact', []));
        self::assertSame(
            'Summarise the logs',
            $turns->expandCustomCommand($spec, 'the logs', $turns->commandDirective($spec, sys_get_temp_dir(), false, null)),
        );
    }

    public function testAProjectShellFormNeedsTrustBeforeTheGateIsEvenAsked(): void
    {
        $turns = TurnController::new();
        $project = CommandSpec::new('ci', 'd', 'Custom', template: 'run', tier: 'project');
        $asked = [];
        $record = static function (string $command) use (&$asked): void {
            $asked[] = $command;
        };
        $gate = new PermissionGate(PermissionMode::Default);

        $refusal = $turns->refuseCommandShell($project, 'make test', false, $gate, $record);
        self::assertNotNull($refusal);
        self::assertStringContainsString('trustedProjectCommands', $refusal);
        self::assertSame([], $asked, 'a tier refusal never moves the gate');

        self::assertNull($turns->refuseCommandShell($project, 'make test', true, $gate, $record), 'an Ask proceeds');
        self::assertSame(['make test'], $asked);
        self::assertNull($turns->refuseCommandShell($project, 'make test', true, null), 'no gate is not a refusal');
    }

    public function testAnExpansionPayloadCarriesRawBytesAndReadsBackFailClosed(): void
    {
        $raw = "out\xff\xfe" . 'put';
        [$expanded, $gated] = TurnController::customCommandExpansionFromPayload(
            TurnController::customCommandPayload($raw, ['ls', 'pwd']),
        );

        self::assertSame($raw, $expanded, 'base64 keeps non-UTF-8 output byte-identical');
        self::assertSame(['ls', 'pwd'], $gated);
        self::assertSame([null, []], TurnController::customCommandExpansionFromPayload(''));
        self::assertSame([null, []], TurnController::customCommandExpansionFromPayload(false));
    }

    // ── turn hooks ─────────────────────────────────────────────────────

    public function testATurnHookVerdictPayloadRoundTripsAndANoReportIsADeny(): void
    {
        [$prompt, $session] = TurnController::turnHookResultsFromPayload(
            TurnController::turnHookPayload(HookResult::allow('', 'note'), HookResult::deny('no')),
        );
        self::assertTrue($prompt->permitsExecution());
        self::assertSame('note', $prompt->additionalContext);
        self::assertNotNull($session);
        self::assertFalse($session->permitsExecution());

        [$failed, $none] = TurnController::turnHookResultsFromPayload('{"prompt":null}');
        self::assertFalse($failed->permitsExecution(), 'an unreadable verdict is a DENY, never an allow');
        self::assertNull($none);
    }

    public function testHookNotesAreSessionFirstAndABlockedSessionStartOnlySaysWhy(): void
    {
        $turns = TurnController::new();

        $notes = $turns->turnHookNotes(HookResult::allow('', 'prompt note'), HookResult::allow('', 'session note'));
        self::assertSame(['session note', 'prompt note'], array_map(static fn (Message $m): string => $m->content, $notes));
        self::assertSame(Role::System, $notes[0]->role);

        $blocked = $turns->turnHookNotes(HookResult::allow(), HookResult::deny('not today'));
        self::assertCount(1, $blocked);
        self::assertTrue($blocked[0]->uiOnly, 'the reason is a notice, the note is discarded');
        self::assertStringContainsString('not today', $blocked[0]->content);

        self::assertSame([], $turns->turnHookNotes(HookResult::allow(), null), 'no stdout adds no row');
    }

    public function testAnAskFromATurnHookIsARefusal(): void
    {
        $turns = TurnController::new();

        self::assertNull($turns->turnHookRefusalReason(HookResult::allow()));
        self::assertNotNull($turns->turnHookRefusalReason(HookResult::ask('please confirm')));
        self::assertStringContainsString('without giving a reason', (string) $turns->turnHookRefusalReason(HookResult::deny('')));
    }

    public function testTheTurnHookContextKeepsSlashesAndUnicodeAndNamesTheSession(): void
    {
        $context = TurnController::new()->turnHookContext('SessionStart', 'read /etc/hosts — café', true, 's1', '/root');

        self::assertSame('{"prompt":"read /etc/hosts — café","source":"startup"}', $context->toolInput);
        self::assertSame('s1', $context->sessionId);
        self::assertSame('SessionStart', $context->toolName);
        self::assertSame('/root', $context->projectRoot);
        self::assertSame('', TurnController::new()->turnHookContext('UserPromptSubmit', 'x', false, null, '/r')->sessionId);
    }

    // ── the user's row and the dispatch's bookkeeping ─────────────────

    public function testMentionsAreAttachedOnlyWhenTheUserTypedThem(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-turn-controller-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        file_put_contents($this->dir . '/notes.txt', 'hello');
        $turns = TurnController::new();

        [$typed, $notices] = $turns->userTurnMessage('read @notes.txt', true, $this->dir, null, null);
        self::assertCount(1, $typed->attachments);
        self::assertSame([], $notices);

        [$expanded] = $turns->userTurnMessage('read @notes.txt', false, $this->dir, null, null);
        self::assertSame([], $expanded->attachments, 'a command file\'s expansion is never read for mentions');
    }

    public function testTheCheckpointIsTheStateBeforeThePromptWithoutReminders(): void
    {
        $turns = TurnController::new();
        $state = $turns->checkpointState(
            [Message::user('old'), CompactionService::contextReminderMessage(90_000)],
            'the draft',
            4,
            's1',
        );

        self::assertSame(['old'], array_map(static fn (Message $m): string => $m->content, $state['messages']));
        self::assertTrue($state[TurnController::CHECKPOINT_PRE_TURN_KEY]);
        self::assertSame('the draft', $state['inputBuf']);
        self::assertSame(4, $state['inputCursor']);
        self::assertSame(['currentSessionId' => 's1'], $state['agentContext']);
        self::assertSame('p2', $turns->turnPrompt([Message::user('p1'), Message::notice('n'), Message::user('p2')]));
        self::assertNull($turns->turnPrompt([Message::notice('n')]));
    }

    public function testADispatchCheckpointsCountsTheTurnAndAnnouncesThePromptRow(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-turn-controller-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $store = new EnhancedSessionStore($this->dir . '/session.db');
        $store->createSession('s', 'p', 'm');
        $turns = TurnController::new();
        $transcripts = TranscriptStore::new($store);
        $prompt = Message::user('ship it');

        $capture = $turns->saveCheckpoint($store, 's', null, $turns->checkpointState([], 'ship it', null, 's'));
        self::assertNull($capture, 'no explicit root, no workspace snapshot');
        self::assertCount(1, $store->listCheckpoints('s'));

        $turns->recordTurn($store, 's', [Message::notice('n'), $prompt]);
        self::assertSame(1, (int) ($store->getSession('s')['turns'] ?? 0));

        $written = $turns->recordMessagesCreated($transcripts, 's', [Message::system('hook note'), Message::notice('n'), $prompt]);
        self::assertCount(1, $written, 'only the row the user sent');
        $events = EventLog::new($store)->since('s');
        self::assertCount(1, $events);
        self::assertSame(TurnController::MESSAGE_CREATED, $events[0]['type']);
        self::assertSame('ship it', $events[0]['payload']['content']);
        self::assertSame('user', $events[0]['payload']['kind']);
        self::assertSame($transcripts->identityOf($prompt)[0] ?? null, $events[0]['payload']['messageId'], 'named by the identity the row is saved under');

        self::assertSame([], $turns->recordMessagesCreated(TranscriptStore::new(null), 's', [$prompt]), 'a store that persists nothing records nothing');
        self::assertSame([], $turns->recordMessagesCreated($transcripts, null, [$prompt]));
    }

    // ── the inline tier ────────────────────────────────────────────────

    public function testTheInlineTierSendsARewriteThatBoughtSpaceAndReportsIt(): void
    {
        $history = [];
        for ($i = 0; $i < 30; $i++) {
            $history[] = Message::user("question {$i} " . str_repeat('lorem ipsum ', 60));
            $history[] = Message::assistant("answer {$i} " . str_repeat('dolor sit amet ', 60));
        }
        $compaction = CompactionService::new();
        $compactor = new ContextCompactor(CompactorConfig::new());
        $estimate = static fn (array $rows): int => ContextMeter::new()->estimate($rows, null);
        $limit = (int) ($estimate($history) / 0.9);

        $tier = TurnController::new()->inlineTier(
            $compaction,
            $compactor,
            $history,
            CompactionService::compactionWire($history),
            $limit,
            $estimate($history),
            $estimate,
        );

        self::assertSame('sent', $tier['outcome']);
        self::assertNotNull($tier['compactionNotice'], 'a rewrite that bought space is announced');
        self::assertLessThan($estimate($history), $tier['tokenCount']);
        self::assertGreaterThanOrEqual(\count($history), \count($tier['history']), 'rows are hidden, never removed (1.B-3)');
        self::assertLessThan(\count($history), \count(Message::agentVisible($tier['history'])), 'the model reads fewer rows');
    }

    // ── helpers ────────────────────────────────────────────────────────

    /** @return array{0: Chat, 1: mixed} */
    private static function type(Chat $chat, KeyType $key): array
    {
        return $chat->update(new KeyMsg($key));
    }

    private static function last(Chat $chat): Message
    {
        $history = $chat->history;

        return $history[\count($history) - 1];
    }

    /** Keep WorkspaceContext referenced for the service-locator note above. */
    public function testChatReachesTheControllerThroughTheWorkspaceLocator(): void
    {
        $registered = TurnController::new();
        $workspace = WorkspaceContext::new()->withService(TurnController::class, $registered);

        self::assertSame($registered, $workspace->service(TurnController::class));
    }
}

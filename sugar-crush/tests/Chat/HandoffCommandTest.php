<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Context\Compaction\StateSummaryTemplate;
use SugarCraft\Crush\Host\Commands\CommandContext;
use SugarCraft\Crush\Host\Commands\HandoffHostCommand;
use SugarCraft\Crush\Host\Commands\HandoffSeededMsg;
use SugarCraft\Crush\Host\SubmitOptions;
use SugarCraft\Crush\Host\TranscriptStore;
use SugarCraft\Crush\Host\TurnController;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionKind;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Usage;

/**
 * Roadmap 5.14c: `/handoff [focus]` opens a new session, forked as a branch of
 * this one, whose transcript is a single state-summary row (the W5 template),
 * and moves the window onto it.
 *
 * @see HandoffHostCommand
 */
final class HandoffCommandTest extends TestCase
{
    private const MODEL_BLOCK = "<session-state>\n## Goal\nShip the login fix\n## Constraints\n\"never touch prod.env\"\n"
        . "## Progress\n### Done\n- patched Auth.php\n### In progress\n- none\n### Blocked\n- none\n## Key decisions\nkeep bcrypt\n"
        . "## Current work\nwriting the regression test\n## Next step\nrun the suite\n## Pending tasks\nnone\n"
        . "## Errors and fixes\nnone\n## Files modified\n- invented.php\n</session-state>";

    private string $dir;

    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/handoff_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
        $this->store = new EnhancedSessionStore($this->dir . '/s.db');
        $this->store->createSession('parent', 'sugarcrush', 'unknown', name: 'login work');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testTheNewSessionIsALinkedBranchSeededWithTheModelsStateBlock(): void
    {
        $summary = $this->backend(self::MODEL_BLOCK);
        $chat = $this->chat($summary);

        [$asked, $cmd] = $this->submit($chat, '/handoff the auth tests');

        self::assertSame('parent', $asked->currentSessionId(), 'nothing moves until the summary lands');
        self::assertSame('/handoff the auth tests', $asked->history[\count($asked->history) - 2]->content);
        self::assertStringContainsString('summary model is writing', $asked->history[\count($asked->history) - 1]->content);

        $landed = $this->resolve($cmd);
        self::assertInstanceOf(HandoffSeededMsg::class, $landed);
        self::assertTrue($landed->modelWritten);
        self::assertNotNull($landed->sessionId);

        [$moved] = $asked->update($landed);
        $newId = $moved->currentSessionId();
        self::assertSame($landed->sessionId, $newId);
        self::assertFalse($moved->inFlight);

        // The seed: one agent-visible USER row carrying the state block.
        $visible = Message::agentVisible($moved->history);
        self::assertCount(1, $visible);
        self::assertSame(Role::User, $visible[0]->role);
        self::assertTrue(StateSummaryTemplate::isStateRow($visible[0]->content));
        $state = StateSummaryTemplate::fromRow($visible[0]->content);
        self::assertNotNull($state);
        self::assertSame('Ship the login fix', $state->section('Goal'));
        self::assertSame('"never touch prod.env"', $state->section('Constraints'));
        self::assertSame('- src/Auth.php', $state->section('Files modified'), 'derived, never the model\'s');
        self::assertSame('add a regression test', $state->section('Latest unresolved user request'));

        // Linked: a branch of the session it came from, holding only the seed.
        $row = $this->store->getSession((string) $newId);
        self::assertSame('parent', $row['parent_id']);
        self::assertSame(SessionKind::Branch->value, $row['kind']);
        $stored = TranscriptStore::new($this->store)->load((string) $newId);
        self::assertCount(1, $stored);
        self::assertSame($visible[0]->content, $stored[0]->content);
        self::assertSame('fix the login bug', TranscriptStore::new($this->store)->load('parent')[0]->content, 'the parent keeps its transcript');

        self::assertEqualsWithDelta(0.001, $moved->spentUsd(), 1e-9, 'the summary call is accounted');

        self::assertCount(1, $summary->asked);
        $request = $summary->asked[0];
        self::assertStringContainsString('## Constraints', $request[0]->content);
        self::assertStringContainsString('fix the login bug', $request[1]->content);
        self::assertStringContainsString("<focus>\nthe auth tests\n</focus>", $request[1]->content);
    }

    public function testWithNoSummaryModelTheBlockIsReadOffTheTranscript(): void
    {
        $chat = $this->chat(null);

        [$asked, $cmd] = $this->submit($chat, '/handoff');
        $landed = $this->resolve($cmd);
        self::assertInstanceOf(HandoffSeededMsg::class, $landed);
        self::assertFalse($landed->modelWritten);
        self::assertNull($landed->error);

        [$moved] = $asked->update($landed);
        $state = StateSummaryTemplate::fromRow(Message::agentVisible($moved->history)[0]->content);
        self::assertNotNull($state);
        self::assertSame('fix the login bug', $state->section('Goal'));
        self::assertSame('- src/Auth.php', $state->section('Files modified'));
        self::assertStringContainsString('a mechanical state summary', $moved->history[\count($moved->history) - 1]->content);
    }

    public function testAReplyWithNoBlockFallsBackAndSaysSo(): void
    {
        [$asked, $cmd] = $this->submit($this->chat($this->backend('Sure, here is a summary of things.')), '/handoff');
        $landed = $this->resolve($cmd);

        self::assertInstanceOf(HandoffSeededMsg::class, $landed);
        self::assertFalse($landed->modelWritten);
        self::assertStringContainsString('wrote no state block', (string) $landed->error);
        self::assertNotNull($landed->sessionId, 'a handoff always opens a session');
    }

    public function testAFailedSummaryCallFallsBack(): void
    {
        $failing = new class implements Backend {
            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                throw new \RuntimeException('down');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                return \React\Promise\reject(new \RuntimeException("provider\n down"));
            }
        };

        [, $cmd] = $this->submit($this->chat($failing), '/handoff');
        $landed = $this->resolve($cmd);

        self::assertInstanceOf(HandoffSeededMsg::class, $landed);
        self::assertNotNull($landed->sessionId);
        self::assertStringContainsString('the summary model failed (provider down)', (string) $landed->error);
    }

    public function testAtTheSpendCapNoModelIsAsked(): void
    {
        $summary = $this->backend(self::MODEL_BLOCK);
        $chat = (new Chat(
            history: $this->history(),
            backend: new EchoBackend(),
            summaryBackend: $summary,
            sessionStore: $this->store,
            maxCostUsd: 0.0005,
        ))->withCurrentSessionId('parent')->withSize(100, 30);
        [$spent] = $chat->update(new HandoffSeededMsg('elsewhere', null, '', false, Usage::new(10, 0.001)));

        [, $cmd] = $this->submit($spent, '/handoff');
        $landed = $this->resolve($cmd);

        self::assertSame([], $summary->asked);
        self::assertInstanceOf(HandoffSeededMsg::class, $landed);
        self::assertFalse($landed->modelWritten);
    }

    public function testALandingForAnotherSessionDoesNotMoveTheWindow(): void
    {
        $chat = $this->chat(null);

        [$after] = $chat->update(new HandoffSeededMsg('another', 'child', 'seed', true, Usage::new(5, 0.002)));

        self::assertSame('parent', $after->currentSessionId());
        self::assertSame($chat->history, $after->history);
        self::assertEqualsWithDelta(0.002, $after->spentUsd(), 1e-9, 'still accounted');
    }

    public function testALandingDuringATurnStaysAndSaysWhereTheSessionIs(): void
    {
        $busy = $this->chat(null, inFlight: true);

        [$after] = $busy->update(new HandoffSeededMsg('parent', 'child', 'seed', false));

        self::assertSame('parent', $after->currentSessionId());
        $last = $after->history[\count($after->history) - 1];
        self::assertTrue($last->uiOnly);
        self::assertStringContainsString('Handed off to session child', $last->content);
        self::assertStringContainsString('open it from /sessions', $last->content);
    }

    public function testItNeedsAStoreASessionAndSomethingToHandOff(): void
    {
        [$noStore] = $this->submit(new Chat(history: $this->history(), backend: new EchoBackend()), '/handoff');
        self::assertStringContainsString('not configured', $noStore->history[\count($noStore->history) - 1]->content);

        [$noSession] = $this->submit(new Chat(history: $this->history(), backend: new EchoBackend(), sessionStore: $this->store), '/handoff');
        self::assertStringContainsString('No active session', $noSession->history[\count($noSession->history) - 1]->content);

        $empty = (new Chat(backend: new EchoBackend(), sessionStore: $this->store))->withCurrentSessionId('parent');
        [$nothing, $cmd] = $this->submit($empty, '/handoff');
        self::assertNull($cmd);
        self::assertStringContainsString('Nothing to hand off', $nothing->history[\count($nothing->history) - 1]->content);
    }

    public function testItIsRefusedMidTurn(): void
    {
        self::assertSame(
            TurnController::ROUTE_REFUSE_COMMAND,
            TurnController::new()->midTurnRoute('/handoff', false, SubmitOptions::new()),
        );
    }

    public function testAHeadlessHostOpensTheSessionAndReportsIt(): void
    {
        $context = CommandContext::new(
            history: $this->history(),
            sessionId: 'parent',
            sessionStore: $this->store,
        );

        $result = (new HandoffHostCommand())->run($context, '/handoff');
        $effect = $result->effects[0];
        $landed = null;
        ($effect->run())()->then(static function (mixed $msg) use (&$landed): void {
            $landed = $msg;
        });

        self::assertInstanceOf(HandoffSeededMsg::class, $landed);
        self::assertStringStartsWith('Handed off to session ' . $landed->sessionId, (string) ($effect->describe())($landed));
        self::assertSame('parent', $this->store->getSession((string) $landed->sessionId)['parent_id']);
    }

    public function testTheSystemPromptNamesEveryModelHeading(): void
    {
        $prompt = HandoffHostCommand::summaryInstructions();

        foreach (StateSummaryTemplate::MODEL_HEADINGS as $heading) {
            self::assertStringContainsString('## ' . $heading, $prompt);
        }
        foreach (StateSummaryTemplate::DERIVED_HEADINGS as $heading) {
            self::assertStringNotContainsString('## ' . $heading, $prompt);
        }
    }

    public function testItIsAdvertised(): void
    {
        $rows = array_values(array_filter(CommandRegistry::slashCommands(), static fn($s): bool => $s->name === 'handoff'));

        self::assertCount(1, $rows);
        self::assertSame('[focus]', $rows[0]->argumentHint);
    }

    /** @return list<Message> */
    private function history(): array
    {
        return [
            Message::user('fix the login bug'),
            Message::system('Edit')->withToolResults([
                ToolResult::ok('Edit', 'edited', 'c1')->withArguments(['file_path' => 'src/Auth.php']),
            ]),
            Message::assistant('Patched Auth.php.'),
            Message::user('add a regression test'),
        ];
    }

    private function chat(?Backend $summary, bool $inFlight = false): Chat
    {
        $chat = new Chat(
            history: $this->history(),
            inFlight: $inFlight,
            backend: new EchoBackend(),
            summaryBackend: $summary,
            sessionStore: $this->store,
            inFlightCancellation: $inFlight ? new CancellationToken() : null,
        );
        TranscriptStore::new($this->store)->save('parent', $chat->history);

        return $chat->withCurrentSessionId('parent')->withSize(100, 30);
    }

    /** @return array{0: Chat, 1: ?\Closure} */
    private function submit(Chat $chat, string $draft): array
    {
        $drafted = (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($chat, ['inputBuf' => $draft]);

        return $drafted->update(new KeyMsg(KeyType::Enter));
    }

    private function resolve(?\Closure $cmd): mixed
    {
        self::assertNotNull($cmd);
        $async = $cmd();
        self::assertInstanceOf(AsyncCmd::class, $async);
        $resolved = null;
        $async->promise->then(static function (mixed $msg) use (&$resolved): void {
            $resolved = $msg;
        });

        return $resolved;
    }

    private function backend(string $reply): Backend
    {
        return new class ($reply) implements Backend {
            /** @var list<list<Message>> */
            public array $asked = [];

            public function __construct(private readonly string $reply)
            {
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant($this->reply);
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $this->asked[] = $history;

                return \React\Promise\resolve(Message::assistant($this->reply)->withUsage(Usage::new(20, 0.001)));
            }
        };
    }
}

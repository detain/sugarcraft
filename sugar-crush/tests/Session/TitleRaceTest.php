<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Session;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionStore;
use SugarCraft\Crush\SessionTitledMsg;
use SugarCraft\Crush\Usage;

/**
 * The auto-titler is fire-and-forget: its request goes out with the first
 * turn and lands after it. A `/rename` typed in that window used to be
 * overwritten twice over — the promise renamed the row unconditionally and
 * the `SessionTitledMsg` arm latched the generated title over the user's
 * (audit B2). The user's name must survive in the store AND in
 * `currentSessionName`, while the title call's cost is still accounted.
 *
 * @see SessionStore::renameSessionIfUnnamed()
 */
final class TitleRaceTest extends TestCase
{
    public function testStoreRenameIfUnnamedNamesAnUnnamedRowOnly(): void
    {
        $store = new SessionStore(':memory:');
        $store->createSession('s1', 'p', 'm');

        $this->assertTrue($store->renameSessionIfUnnamed('s1', 'Generated'));
        $this->assertSame('Generated', $store->getSession('s1')['name']);

        $this->assertFalse($store->renameSessionIfUnnamed('s1', 'Second'));
        $this->assertSame('Generated', $store->getSession('s1')['name']);
    }

    public function testStoreRenameIfUnnamedTreatsAnEmptyNameAsUnnamed(): void
    {
        $store = new SessionStore(':memory:');
        $store->createSession('s1', 'p', 'm', null, '');

        $this->assertTrue($store->renameSessionIfUnnamed('s1', 'Generated'));
        $this->assertSame('Generated', $store->getSession('s1')['name']);
    }

    public function testStoreRenameIfUnnamedOnAMissingRowIsRefused(): void
    {
        $store = new SessionStore(':memory:');

        $this->assertFalse($store->renameSessionIfUnnamed('nope', 'Generated'));
    }

    public function testEnhancedStoreDelegatesTheConditionalRename(): void
    {
        $store = new EnhancedSessionStore(':memory:');
        $store->createSession('s1', 'p', 'm');
        $store->renameSession('s1', 'Mine');

        $this->assertFalse($store->renameSessionIfUnnamed('s1', 'Generated'));
        $this->assertSame('Mine', $store->getSession('s1')['name']);
    }

    public function testConditionalRenameInvalidatesTheSessionListMemo(): void
    {
        $store = new SessionStore(':memory:');
        $store->createSession('s1', 'p', 'm');
        $this->assertNull($store->listSessions()[0]['name']);

        $store->renameSessionIfUnnamed('s1', 'Generated');

        $this->assertSame('Generated', $store->listSessions()[0]['name']);
    }

    public function testRenameTypedWhileTheTitleIsInFlightSurvivesInStoreAndUi(): void
    {
        $store = new SessionStore(':memory:');
        $store->createSession('sess-race', 'sugarcrush', 'test-model');
        $chat = new Chat(
            inputBuf: 'explain the event loop',
            backend: new EchoBackend(),
            sessionStore: $store,
            currentSessionId: 'sess-race',
            titleBackend: $this->titleBackend('Generated Title'),
        );

        // Turn 1 goes out; the title Cmd is built but not yet run.
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $batch = $cmd();
        $this->assertInstanceOf(BatchMsg::class, $batch);
        $this->assertCount(2, $batch->cmds);

        // The user renames before the title request lands.
        $store->renameSession('sess-race', 'My Name');
        $renamed = $next->withCurrentSessionName('My Name');

        $titled = $this->resolveAsyncCmd($batch->cmds[1]);
        $this->assertInstanceOf(SessionTitledMsg::class, $titled);
        $this->assertSame('', $titled->title, 'a refused rename must not hand a title to the UI');
        $this->assertNotNull($titled->usage, 'the call still cost money');
        $this->assertSame('My Name', $store->getSession('sess-race')['name']);

        [$after] = $renamed->update($titled);
        $this->assertSame('My Name', $after->currentSessionName());
    }

    public function testATitleArrivingAfterTheUserNamedTheSessionIsNotLatched(): void
    {
        $store = new SessionStore(':memory:');
        $store->createSession('sess-latched', 'p', 'm');
        $chat = new Chat(
            sessionStore: $store,
            currentSessionId: 'sess-latched',
            currentSessionName: 'Mine',
        );

        // A title whose store write won the race (the rename came after it)
        // still must not displace a name the UI has already latched.
        [$next, $cmd] = $chat->update(new SessionTitledMsg('sess-latched', 'Generated', Usage::new(7, 0.002)));

        $this->assertNull($cmd);
        $this->assertSame('Mine', $next->currentSessionName());
    }

    public function testAnUnnamedSessionStillLatchesTheGeneratedTitle(): void
    {
        $store = new SessionStore(':memory:');
        $store->createSession('sess-fresh', 'p', 'm');
        $chat = new Chat(sessionStore: $store, currentSessionId: 'sess-fresh');

        [$next] = $chat->update(new SessionTitledMsg('sess-fresh', 'Generated'));

        $this->assertSame('Generated', $next->currentSessionName());
    }

    private function titleBackend(string $reply): Backend
    {
        return new class ($reply) implements Backend {
            public function __construct(private readonly string $reply) {}

            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant($this->reply);
            }

            public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                return \React\Promise\resolve(Message::assistant($this->reply)->withUsage(Usage::new(12, 0.001)));
            }
        };
    }

    private function resolveAsyncCmd(\Closure $cmd): mixed
    {
        $asyncCmd = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $asyncCmd);
        $resolved = null;
        $asyncCmd->promise->then(function ($msg) use (&$resolved): void {
            $resolved = $msg;
        });

        return $resolved;
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Plugins\Host\HostServices;
use SugarCraft\Plugins\Tests\Support\FakeHostServices;

final class FakeHostServicesTest extends TestCase
{
    public function testTheFakeSatisfiesTheHostServicesContract(): void
    {
        $this->assertInstanceOf(HostServices::class, new FakeHostServices());
    }

    public function testTheInterfaceCarriesExactlyTheSixteenInventoriedCalls(): void
    {
        $names = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(HostServices::class))->getMethods(),
        );
        sort($names);

        $this->assertSame(
            [
                'cancelSession', 'connect', 'createSession', 'disconnect', 'execCommand',
                'followedSessions', 'hello', 'isConnected', 'pendingPermissions',
                'respondPermission', 'sendTurn', 'sessionSnapshot', 'subscribeEvents',
                'subscribeFollowResults', 'subscribeSession', 'unsubscribeSession',
            ],
            $names,
        );
    }

    public function testEveryCallIsRecordedInOrderWithItsArguments(): void
    {
        $fake = new FakeHostServices();
        $listener = static function (): void {
        };

        $fake->createSession('general', 'ask');
        $fake->sendTurn('s1', 'hi', 'queue', 'key-1', ['img'], []);
        $fake->subscribeEvents($listener);
        $fake->disconnect();

        $this->assertSame(
            [
                ['method' => 'createSession', 'args' => ['general', 'ask']],
                ['method' => 'sendTurn', 'args' => ['s1', 'hi', 'queue', 'key-1', ['img'], []]],
                ['method' => 'subscribeEvents', 'args' => [$listener]],
                ['method' => 'disconnect', 'args' => []],
            ],
            $fake->calls,
        );
    }

    public function testScriptedResultsAreReturnedAndUnscriptedCallsGetDefaults(): void
    {
        $fake = new FakeHostServices([
            'createSession' => ['id' => 's9'],
            'isConnected' => true,
        ]);

        $this->assertSame(['id' => 's9'], $fake->createSession(null, null));
        $this->assertTrue($fake->isConnected());
        $this->assertSame([], $fake->hello());
        $this->assertSame([], $fake->followedSessions());
        $this->assertFalse((new FakeHostServices())->isConnected());
    }

    public function testConnectReturnsAPromiseThatResolvesByDefault(): void
    {
        $fake = new FakeHostServices();

        $promise = $fake->connect();

        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $resolved = false;
        $promise->then(static function () use (&$resolved): void {
            $resolved = true;
        });
        $this->assertTrue($resolved);
        $this->assertSame('connect', $fake->calls[0]['method']);
    }
}

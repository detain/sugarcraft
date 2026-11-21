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

    /** Every method of the contract is asynchronous: its declared return type is a promise. */
    public function testEveryMethodOfTheContractReturnsAPromise(): void
    {
        foreach ((new \ReflectionClass(HostServices::class))->getMethods() as $method) {
            $type = $method->getReturnType();
            $this->assertInstanceOf(\ReflectionNamedType::class, $type, $method->getName());
            $this->assertSame(PromiseInterface::class, $type->getName(), $method->getName());
        }
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

    public function testScriptedResultsResolveAndUnscriptedCallsResolveToDefaults(): void
    {
        $fake = new FakeHostServices([
            'createSession' => ['id' => 's9'],
            'isConnected' => true,
        ]);

        $this->assertSame(['id' => 's9'], $this->settle($fake->createSession(null, null)));
        $this->assertTrue($this->settle($fake->isConnected()));
        $this->assertSame([], $this->settle($fake->hello()));
        $this->assertSame([], $this->settle($fake->followedSessions()));
        $this->assertFalse($this->settle((new FakeHostServices())->isConnected()));
    }

    public function testAVoidCallResolvesToNull(): void
    {
        $fake = new FakeHostServices();

        $this->assertNull($this->settle($fake->connect()));
        $this->assertNull($this->settle($fake->disconnect()));
        $this->assertNull($this->settle($fake->unsubscribeSession('s1')));
        $this->assertNull($this->settle($fake->subscribeEvents(static function (): void {
        })));
    }

    public function testAScriptedThrowableRejectsWithTheSameError(): void
    {
        $refusal = new \RuntimeException('session.create returned no id');
        $fake = new FakeHostServices(['sendTurn' => $refusal]);

        $promise = $fake->sendTurn('s1', 'hi', 'queue', null, [], []);

        $this->assertSame($refusal, $this->reasonOf($promise));
        $this->assertSame('sendTurn', $fake->calls[0]['method']);
    }

    public function testAScriptedRejectionDoesNotResolveTheCall(): void
    {
        $fake = new FakeHostServices(['connect' => new \RuntimeException('dial failed')]);

        $promise = $fake->connect();

        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $this->assertInstanceOf(\RuntimeException::class, $this->reasonOf($promise));
    }

    /** The fulfilled value of a promise that settled synchronously; the fake's promises always do. */
    private function settle(PromiseInterface $promise): mixed
    {
        $value = null;
        $settled = false;
        $promise->then(static function (mixed $v) use (&$value, &$settled): void {
            $value = $v;
            $settled = true;
        });
        $this->assertTrue($settled, 'the promise resolved');

        return $value;
    }

    /** The rejection reason of a promise that settled synchronously. */
    private function reasonOf(PromiseInterface $promise): mixed
    {
        $reason = null;
        $rejected = false;
        $promise->then(null, static function (mixed $e) use (&$reason, &$rejected): void {
            $reason = $e;
            $rejected = true;
        });
        $this->assertTrue($rejected, 'the promise rejected');

        return $reason;
    }
}

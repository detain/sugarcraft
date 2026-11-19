<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests\Bridge;

use PHPUnit\Framework\TestCase;
use SugarCraft\Plugins\Bridge\HostServicesServeClient;
use SugarCraft\Plugins\Bridge\ServeClient;
use SugarCraft\Plugins\Host\RefusedCall;
use SugarCraft\Plugins\Tests\Support\FakeHostServices;

/**
 * PL-5d: the ServeClient the router uses, answered by a HostServices in process. Each router
 * call must reach the host method it maps to with the arguments the router sent, and a refusal
 * must reach the router through the bundle's refusal mapper.
 */
final class HostServicesServeClientTest extends TestCase
{
    /** @return array{value: mixed, error: ?\Throwable} */
    private function settle(\React\Promise\PromiseInterface $promise): array
    {
        $out = ['value' => null, 'error' => null];
        $promise->then(
            static function (mixed $value) use (&$out): void {
                $out['value'] = $value;
            },
            static function (\Throwable $e) use (&$out): void {
                $out['error'] = $e;
            },
        );

        return $out;
    }

    public function testItIsAServeClient(): void
    {
        $this->assertInstanceOf(ServeClient::class, new HostServicesServeClient(new FakeHostServices()));
    }

    public function testFollowMapsToSubscribeSessionWithItsCursor(): void
    {
        $host = new FakeHostServices(['subscribeSession' => ['sessionId' => 's1']]);
        $cursor = static fn (): int => 7;
        $client = new HostServicesServeClient($host);

        $out = $this->settle($client->follow('s1', 4, $cursor));

        $this->assertSame(['sessionId' => 's1'], $out['value']);
        $this->assertSame('subscribeSession', $host->calls[0]['method']);
        $this->assertSame(['s1', 4, $cursor], $host->calls[0]['args']);
    }

    public function testSendMapsToSendTurnWithTheAttachments(): void
    {
        $host = new FakeHostServices(['sendTurn' => ['accepted' => true]]);
        $client = new HostServicesServeClient($host);
        $images = [['mime' => 'image/png', 'data' => 'AAA']];

        $this->settle($client->send('s1', 'hello', 'steer', 'key-1', $images, []));

        $this->assertSame('sendTurn', $host->calls[0]['method']);
        $this->assertSame(['s1', 'hello', 'steer', 'key-1', $images, []], $host->calls[0]['args']);
    }

    public function testSendDefaultsToTheQueueDelivery(): void
    {
        $host = new FakeHostServices();
        $client = new HostServicesServeClient($host);

        $this->settle($client->send('s1', 'hi'));

        $this->assertSame(['s1', 'hi', 'queue', null, [], []], $host->calls[0]['args']);
    }

    public function testCreateSessionMapsToCreateSession(): void
    {
        $host = new FakeHostServices(['createSession' => ['id' => 'new-1']]);
        $client = new HostServicesServeClient($host);

        $out = $this->settle($client->createSession('discord:x', 'default'));

        $this->assertSame(['id' => 'new-1'], $out['value']);
        $this->assertSame(['discord:x', 'default'], $host->calls[0]['args']);
    }

    public function testRespondPermissionMapsToRespondPermission(): void
    {
        $host = new FakeHostServices();
        $client = new HostServicesServeClient($host);

        $this->settle($client->respondPermission('s1', 'ask-1', 'allow', 'by alice'));

        $this->assertSame('respondPermission', $host->calls[0]['method']);
        $this->assertSame(['s1', 'ask-1', 'allow', 'by alice'], $host->calls[0]['args']);
    }

    public function testCancelMapsToCancelSessionWithTheHardDefault(): void
    {
        $host = new FakeHostServices();
        $client = new HostServicesServeClient($host);

        $this->settle($client->cancel('s1'));

        $this->assertSame('cancelSession', $host->calls[0]['method']);
        $this->assertSame(['s1', 'hard'], $host->calls[0]['args']);
    }

    public function testRawCallsMapByMethodName(): void
    {
        $host = new FakeHostServices([
            'execCommand' => ['text' => 'ok'],
            'sessionSnapshot' => ['id' => 's1'],
            'pendingPermissions' => ['pending' => []],
        ]);
        $client = new HostServicesServeClient($host);

        $this->assertSame(['text' => 'ok'], $this->settle($client->call('command.exec', ['sessionId' => 's1', 'name' => 'model', 'args' => 'gpt']))['value']);
        $this->assertSame(['id' => 's1'], $this->settle($client->call('session.get', ['sessionId' => 's1', 'limit' => 1]))['value']);
        $this->assertSame(['pending' => []], $this->settle($client->call('permission.pending', []))['value']);

        $this->assertSame(['s1', 'model', 'gpt'], $host->calls[0]['args']);
        $this->assertSame(['s1', 1], $host->calls[1]['args']);
        $this->assertSame([], $host->calls[2]['args']);
    }

    public function testACommandWithNoArgsSendsAnEmptyString(): void
    {
        $host = new FakeHostServices();
        $client = new HostServicesServeClient($host);

        $this->settle($client->call('command.exec', ['sessionId' => 's1', 'name' => 'model']));

        $this->assertSame(['s1', 'model', ''], $host->calls[0]['args']);
    }

    public function testAnUnmappedRawMethodRejects(): void
    {
        $client = new HostServicesServeClient(new FakeHostServices());

        $out = $this->settle($client->call('server.hello', []));

        $this->assertInstanceOf(\InvalidArgumentException::class, $out['error']);
        $this->assertStringContainsString('server.hello', $out['error']->getMessage());
    }

    public function testARefusalPassesThroughTheMapperWithItsMethod(): void
    {
        $refusal = new class ('session_not_found', 'gone') extends \RuntimeException implements RefusedCall {
            public function __construct(private readonly string $kind, string $message)
            {
                parent::__construct($message);
            }

            public function refusalKind(): string
            {
                return $this->kind;
            }

            public function refusalData(): array
            {
                return ['kind' => $this->kind];
            }
        };
        $seen = [];
        $mapped = new \RuntimeException('mapped');
        $client = new HostServicesServeClient(
            new FakeHostServices(['sendTurn' => $refusal]),
            static function (string $method, \Throwable $e) use (&$seen, $mapped): \Throwable {
                $seen[] = [$method, $e];

                return $mapped;
            },
        );

        $out = $this->settle($client->send('s1', 'hi'));

        $this->assertSame($mapped, $out['error']);
        $this->assertSame('session.send', $seen[0][0]);
        $this->assertSame($refusal, $seen[0][1]);
    }

    public function testARefusalPassesThroughUnchangedWithoutAMapper(): void
    {
        $boom = new \RuntimeException('boom');
        $client = new HostServicesServeClient(new FakeHostServices(['createSession' => $boom]));

        $this->assertSame($boom, $this->settle($client->createSession())['error']);
    }

    public function testOneHostSubscriptionFansOutToEveryListener(): void
    {
        $host = new FakeHostServices();
        $client = new HostServicesServeClient($host);
        $first = [];
        $second = [];
        $client->onEvent(static function (array $e) use (&$first): void {
            $first[] = $e;
        });
        $client->onEvent(static function (array $e) use (&$second): void {
            $second[] = $e;
        });

        $subscriptions = array_values(array_filter($host->calls, static fn (array $c): bool => $c['method'] === 'subscribeEvents'));
        $this->assertCount(1, $subscriptions, 'the host is subscribed once, however many listeners register');

        $listener = $subscriptions[0]['args'][0];
        $listener(['sessionId' => 's1', 'seq' => 3, 'type' => 'turn.done', 'data' => []]);

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertSame(3, $first[0]['seq']);
    }

    public function testAThrowingListenerNeverStarvesItsSibling(): void
    {
        $host = new FakeHostServices();
        $client = new HostServicesServeClient($host);
        $second = [];
        $client->onEvent(static function (array $e): void {
            throw new \RuntimeException('listener A blew up');
        });
        $client->onEvent(static function (array $e) use (&$second): void {
            $second[] = $e;
        });

        $subscriptions = array_values(array_filter($host->calls, static fn (array $c): bool => $c['method'] === 'subscribeEvents'));
        $listener = $subscriptions[0]['args'][0];

        try {
            $listener(['sessionId' => 's1', 'seq' => 7, 'type' => 'turn.done', 'data' => []]);
            $this->fail('the failing listener must still reach the host, so its drain can log it');
        } catch (\RuntimeException $e) {
            $this->assertSame('listener A blew up', $e->getMessage());
        }

        $this->assertCount(1, $second, 'the sibling still receives the envelope');
        $this->assertSame(7, $second[0]['seq']);
    }

    public function testSeveralFailingListenersRethrowAsOneAggregateWithTheFirstAsPrevious(): void
    {
        $host = new FakeHostServices();
        $client = new HostServicesServeClient($host);
        $survivor = [];
        $client->onEvent(static function (array $e): void {
            throw new \RuntimeException('first blew up');
        });
        $client->onEvent(static function (array $e): void {
            throw new \LogicException('second blew up');
        });
        $client->onEvent(static function (array $e) use (&$survivor): void {
            $survivor[] = $e;
        });

        $subscriptions = array_values(array_filter($host->calls, static fn (array $c): bool => $c['method'] === 'subscribeEvents'));
        $listener = $subscriptions[0]['args'][0];

        try {
            $listener(['sessionId' => 's1', 'seq' => 8, 'type' => 'turn.done', 'data' => []]);
            $this->fail('every listener failure must reach the host');
        } catch (\RuntimeException $e) {
            $this->assertSame('2 event listeners failed: first blew up; second blew up', $e->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            $this->assertSame('first blew up', $e->getPrevious()->getMessage());
        }

        $this->assertCount(1, $survivor, 'the healthy listener after two failures still receives the envelope');
    }

    public function testOnFollowResultNeverSubscribesToTheHost(): void
    {
        $host = new FakeHostServices();
        $client = new HostServicesServeClient($host);

        $client->onFollowResult(static function (): void {
        });

        $this->assertSame([], $host->calls);
    }
}

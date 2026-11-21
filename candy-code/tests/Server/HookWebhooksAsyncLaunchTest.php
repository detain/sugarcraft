<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\ServerRequest as HttpRequest;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Server\Http\HookWebhooks;
use SugarCraft\Code\Server\ServerConfig;

/**
 * P4-12 CC-1 row f on the inbound webhook door: a delivery whose launch waits
 * on its daemon answers when the launch settles, so the serve loop never waits
 * on a daemon. A redelivery of the same event while that launch is pending
 * shares its answer, and a refused launch leaves its id free for the retry.
 */
final class HookWebhooksAsyncLaunchTest extends TestCase
{
    private const SECRET = 'async-launch-webhook-secret-longer-than-thirty';

    /** @var list<Deferred<array<string, mixed>>> one per launch the door made, in order */
    private array $launches = [];

    private function handler(): HookWebhooks
    {
        // A signed row: its delivery id is what makes a redelivery recognisable.
        $hooks = ServerConfig::webhooksValue([
            'deploy' => [
                'secret' => self::SECRET,
                'prompt' => 'Look at {{payload}} now',
                'rate' => 5,
                'signature' => ['scheme' => 'github-sha256', 'header' => 'x-hub-signature-256', 'deliveryHeader' => 'x-github-delivery'],
            ],
        ]);

        return HookWebhooks::new($hooks, function (): PromiseInterface {
            $launch = new Deferred();
            $this->launches[] = $launch;

            return $launch->promise();
        }, static fn (): float => 1_700_000_000.0);
    }

    private function deliver(HookWebhooks $handler, string $id): PromiseInterface|ResponseInterface
    {
        $body = '{"ref":"main"}';

        return $handler->handle(
            new HttpRequest('POST', 'http://127.0.0.1/api/hooks/deploy', [
                'x-hub-signature-256' => 'sha256=' . hash_hmac('sha256', $body, self::SECRET),
                'x-github-delivery' => $id,
            ], $body),
            'deploy',
            '127.0.0.1',
        );
    }

    /** The answer once it has settled, or null while it has not. */
    private static function settled(PromiseInterface|ResponseInterface $answer): ?ResponseInterface
    {
        if ($answer instanceof ResponseInterface) {
            return $answer;
        }
        $settled = null;
        $answer->then(static function (ResponseInterface $response) use (&$settled): void {
            $settled = $response;
        });

        return $settled;
    }

    public function testADeliveryAnswersWhenItsLaunchSettles(): void
    {
        $handler = $this->handler();

        $answer = $this->deliver($handler, 'delivery-1');
        self::assertInstanceOf(PromiseInterface::class, $answer, 'a launch that waits on its daemon answers later');
        self::assertNull(self::settled($answer));

        $this->launches[0]->resolve(['bgId' => 'bg-1', 'status' => 'running']);

        $response = self::settled($answer);
        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"bgId":"bg-1"', (string) $response->getBody());
    }

    public function testARedeliveryWhileItsLaunchIsPendingSharesTheFirstAnswer(): void
    {
        $handler = $this->handler();

        $first = $this->deliver($handler, 'delivery-2');
        $second = $this->deliver($handler, 'delivery-2');

        self::assertCount(1, $this->launches, 'the same event never launches twice');
        $this->launches[0]->resolve(['bgId' => 'bg-2', 'status' => 'running']);

        self::assertSame(200, self::settled($first)?->getStatusCode());
        self::assertSame((string) self::settled($first)?->getBody(), (string) self::settled($second)?->getBody());
    }

    public function testARefusedLaunchAnswers503AndLeavesTheIdReplayable(): void
    {
        $handler = $this->handler();

        $refused = $this->deliver($handler, 'delivery-3');
        $this->launches[0]->reject(new \RuntimeException('the daemon never authenticated'));

        self::assertSame(503, self::settled($refused)?->getStatusCode());
        self::assertStringContainsString('hook_unavailable', (string) self::settled($refused)?->getBody());
        self::assertStringNotContainsString('never authenticated', (string) self::settled($refused)?->getBody(), 'the refusal names no cause');

        $retry = $this->deliver($handler, 'delivery-3');
        self::assertCount(2, $this->launches, 'a refused id is not remembered, so its retry runs');
        $this->launches[1]->resolve(['bgId' => 'bg-3', 'status' => 'running']);
        self::assertSame(200, self::settled($retry)?->getStatusCode());
    }
}

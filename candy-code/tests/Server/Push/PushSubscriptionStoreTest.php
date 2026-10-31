<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server\Push;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Server\Push\PushSubscription;
use SugarCraft\Code\Server\Push\PushSubscriptionStore;
use SugarCraft\Code\Server\StateDir;

/**
 * The subscription rows' life on disk (C-WEB-5.d): owner-only file mode,
 * sha256-keyed rows, per-principal ownership, the revocation prune, and the
 * load-time re-validation that keeps a tampered or drifted row from ever
 * reaching a dial. The state directory is the same `serve` corner
 * {@see \SugarCraft\Code\Server\Push\VapidKeys} keeps its PEM in, so every
 * write here rides the {@see \SugarCraft\Code\Support\PrivateRetainedDir}
 * discipline the session store set.
 */
final class PushSubscriptionStoreTest extends TestCase
{
    private const SERVICE_BASE = 'https://updates.push.services.mozilla.com/wpush/v2/';

    private const RFC_POINT = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';

    private const RFC_AUTH = 'BTBZMqHH6r4Tts7J_aSIgg';

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/serve-' . \bin2hex(\random_bytes(4)) . '/pushstore';
        \mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->dir . '/' . chr(42)) ?: [] as $left) {
            @\unlink($left);
        }
        @\rmdir($this->dir);
    }

    public function testTheRowsFileIsOwnerOnlyAndKeyedByTheEndpointDigest(): void
    {
        $subscription = $this->rowOn('tokenabc');

        self::assertTrue($this->store()->put($subscription, 'bearer', false));

        $path = $this->dir . '/' . StateDir::PUSH_STORE_FILE;
        self::assertFileExists($path);
        \clearstatcache();
        self::assertSame('0600', \substr(\sprintf('%o', \fileperms($path)), -4), 'an endpoint is a bearer secret');

        $decoded = (array) \json_decode((string) \file_get_contents($path), true);
        $rows = (array) $decoded['subscriptions'];
        self::assertSame([PushSubscriptionStore::keyHash($subscription->endpoint)], \array_keys($rows),
            'the map key is the endpoint digest, never the endpoint');
        $key = PushSubscriptionStore::keyHash($subscription->endpoint);
        self::assertSame($subscription->endpoint, $rows[$key]['endpoint'] ?? null);
        self::assertSame('bearer', $rows[$key]['principal'] ?? null);
        self::assertFalse($rows[$key]['name'] ?? true);
    }

    public function testPutAndReloadRoundTripPreservesPrincipalAndNameFlag(): void
    {
        $subscription = $this->rowOn('tokenabc');

        self::assertTrue($this->store()->put($subscription, 'cookie', true));

        $entries = $this->store()->entries();
        self::assertCount(1, $entries, 'a second store over the same dir sees what the first persisted');
        $entry = $entries[PushSubscriptionStore::keyHash($subscription->endpoint)] ?? null;
        self::assertNotNull($entry);
        self::assertSame($subscription->endpoint, $entry['subscription']->endpoint);
        self::assertSame('cookie', $entry['principal']);
        self::assertTrue($entry['withName'], 'the opt-in flag is row data, not process memory');
    }

    public function testACrossPrincipalUnsubscribeIsRefusedAndLeavesTheRowAlone(): void
    {
        $subscription = $this->rowOn('tokenabc');
        $store = $this->store();
        $store->put($subscription, 'bearer', false);

        self::assertFalse($store->remove($subscription->endpoint, 'cookie'),
            'one credential class unregistering an endpoint never drops another class\'s row');
        self::assertCount(1, $store->entries());
        self::assertFalse($store->remove('https://updates.push.services.mozilla.com/wpush/v2/unseen', 'bearer'),
            'an endpoint nobody stored answers false, not an error');

        self::assertTrue($store->remove($subscription->endpoint, 'bearer'));
        self::assertSame([], $store->entries());
    }

    public function testPrunePrincipalDropsExactlyThatClassRowsOnce(): void
    {
        $store = $this->store();
        $a = $this->rowOn('tokenA');
        $b = $this->rowOn('tokenB');
        $c = $this->rowOn('tokenC');
        $store->put($a, 'bearer', false);
        $store->put($b, 'cookie', false);
        $store->put($c, 'bearer', true);

        self::assertSame(2, $store->prunePrincipal('bearer'), 'the store side of retiring a token');
        self::assertSame([$b->endpoint], \array_values(\array_map(
            static fn (array $entry): string => $entry['subscription']->endpoint,
            $store->entries(),
        )));

        self::assertSame(0, $store->prunePrincipal('bearer'), 'pruning an absent class is quiet');
        self::assertSame(0, $store->prunePrincipal('ticket'), 'pruning a never-used class is quiet too');
    }

    public function testTamperedRowsAreDroppedAtLoadAndTheFileIsRewrittenClean(): void
    {
        $good = $this->rowOn('tokenabc');
        $rows = [
            PushSubscriptionStore::keyHash($good->endpoint) => [...$good->toArray(), 'principal' => 'bearer', 'name' => false],
            \str_repeat('ab', 32) => [...$good->toArray(), 'principal' => 'bearer', 'name' => false],
            PushSubscriptionStore::keyHash('http://updates.push.services.mozilla.com/wpush/v2/bad') => [
                'endpoint' => 'http://updates.push.services.mozilla.com/wpush/v2/bad',
                'p256dh' => self::RFC_POINT,
                'auth' => self::RFC_AUTH,
                'principal' => 'bearer',
                'name' => false,
            ],
            PushSubscriptionStore::keyHash($good->endpoint) . 'cd' => [
                'endpoint' => $good->endpoint,
                'p256dh' => 'short',
                'auth' => self::RFC_AUTH,
                'principal' => 'bearer',
                'name' => false,
            ],
        ];
        \file_put_contents($this->dir . '/' . StateDir::PUSH_STORE_FILE, (string) \json_encode(['subscriptions' => $rows]));

        $entries = $this->store()->entries();
        self::assertSame([$good->endpoint], \array_values(\array_map(
            static fn (array $entry): string => $entry['subscription']->endpoint,
            $entries,
        )), 'a digest that disagrees with its endpoint, and a shape this build would refuse to dial, both die at load');

        $rewritten = (array) \json_decode((string) \file_get_contents($this->dir . '/' . StateDir::PUSH_STORE_FILE), true);
        self::assertCount(1, (array) $rewritten['subscriptions'], 'the drop is persisted, not just memory');
        \clearstatcache();
        self::assertSame('0600', \substr(\sprintf('%o', \fileperms($this->dir . '/' . StateDir::PUSH_STORE_FILE)), -4),
            'the cleanup write rejoins the 0600 discipline');
    }

    public function testARowOverAnUnusablePathCostsDurabilityNotTheRequest(): void
    {
        $blocked = $this->dir . '/not-a-directory';
        \file_put_contents($blocked, 'x');
        $store = PushSubscriptionStore::forStateDir($blocked);

        self::assertFalse($store->put($this->rowOn('tokenabc'), 'bearer', false), 'an unverifiable state path answers false');
        self::assertSame([], $store->entries(), 'and reads answer empty — the server keeps serving');
        self::assertSame(0, $store->prunePrincipal('bearer'));
    }

    public function testResubscribingTheSameEndpointReplacesItsRowNotDuplicatesIt(): void
    {
        $store = $this->store();
        $subscription = $this->rowOn('tokenabc');

        self::assertTrue($store->put($subscription, 'bearer', false));
        self::assertTrue($store->put($subscription, 'bearer', true));

        $entries = $store->entries();
        self::assertCount(1, $entries, 'push.subscribe is idempotent by endpoint');
        self::assertTrue($entries[PushSubscriptionStore::keyHash($subscription->endpoint)]['withName']);
    }

    private function store(): PushSubscriptionStore
    {
        return PushSubscriptionStore::forStateDir($this->dir);
    }

    private function rowOn(string $token): PushSubscription
    {
        return PushSubscription::new(self::SERVICE_BASE . $token, self::RFC_POINT, self::RFC_AUTH);
    }
}

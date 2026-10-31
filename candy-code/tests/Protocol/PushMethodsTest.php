<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use React\Http\Browser;
use React\Http\Message\Response;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Host\SessionEvent;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Code\Protocol\ServerContext;
use SugarCraft\Code\Server\Push\PushPayloadCipher;
use SugarCraft\Code\Server\Push\PushSubscription;
use SugarCraft\Code\Server\Push\PushSubscriptionStore;
use SugarCraft\Code\Server\Push\VapidKeys;
use SugarCraft\Code\Server\Push\WebPushSender;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Server\StateDir;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\WireClient;

/**
 * C-WEB-5.d: the three `push.*` RPCs, the subscription store they write, and
 * the two triggers that read it.
 *
 * The dialling itself (allowlist-before-dial, TTL, guard, cipher, VAPID) is
 * {@see \SugarCraft\Code\Tests\Server\Push\WebPushSenderTest}'s ground; this
 * file pins only what the wiring adds: refusals never reach the store, a
 * stored row speaks for its dialling principal alone, an attached client
 * silences the push for its session, and a question or a completion each
 * fire exactly one send whose plaintext is generic text — the session name
 * rides only a row the client opted in with `name: true`.
 */
final class PushMethodsTest extends TestCase
{
    /** An autopush endpoint the sender's allowlist admits (RFC 8291 example shape). */
    private const RPC_URL = 'https://updates.push.services.mozilla.com/wpush/v2/tokenabc';
    private const RPC_POINT = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
    private const RPC_AUTH = 'BTBZMqHH6r4Tts7J_aSIgg';
    private const RPC_IP = '93.184.216.34';

    private ProtocolFixture $rig;
    private ?Dispatcher $seamDispatcher = null;

    protected function setUp(): void
    {
        $this->rig = ProtocolFixture::new();
    }

    protected function tearDown(): void
    {
        if ($this->seamDispatcher !== null) {
            $this->seamDispatcher->stop('test over');
            $this->seamDispatcher = null;
        }
        $this->rig->tearDown();
    }

    // ---------------------------------------------------------------- specs

    /**
     * No live read-scope connection exists yet — every minted credential is
     * owner (Dispatcher's own comment) — so the scope law is pinned on the
     * spec: Dispatcher::requireScope consults exactly this value (the same
     * stance ContextInstructionWriteTest takes for `context.write`).
     */
    public function testThePushSpecsCarryTheirScopesAndEffectMarks(): void
    {
        $registry = Dispatcher::methods();

        $key = $registry->get('push.vapidKey');
        self::assertNotNull($key);
        self::assertSame(Scope::Read, $key->scope);
        self::assertFalse($key->sideEffects, 'reading a public key changes nothing');

        $sub = $registry->get('push.subscribe');
        self::assertNotNull($sub);
        self::assertSame(Scope::Write, $sub->scope);
        self::assertTrue($sub->sideEffects);

        $unsub = $registry->get('push.unsubscribe');
        self::assertNotNull($unsub);
        self::assertSame(Scope::Write, $unsub->scope);
        self::assertTrue($unsub->sideEffects);
    }

    // ------------------------------------------------------------------ RPC

    public function testSubscribingStoresTheRowUnderTheDiallingPrincipal(): void
    {
        $client = $this->rig->client();
        $this->rig->session($client);

        $result = $client->call('push.subscribe', [
            'endpoint' => self::RPC_URL,
            'keys' => ['p256dh' => self::RPC_POINT, 'auth' => self::RPC_AUTH],
        ]);

        self::assertTrue($result['subscribed']);
        self::assertSame(hash('sha256', self::RPC_URL), $result['keyHash']);

        $entries = $this->rig->context->pushStore()->entries();
        self::assertSame([$result['keyHash']], array_keys($entries));
        self::assertSame('bearer', $entries[$result['keyHash']]['principal'],
            'the row answers for the credential class that wrote it');
        self::assertFalse($entries[$result['keyHash']]['withName'],
            'name is opt-in; the default row never sees the session title');

        $file = $this->rig->dir . '/' . StateDir::PUSH_STORE_FILE;
        self::assertSame('0600', substr(\sprintf('%o', (int) \fileperms($file)), -4));
        $raw = (array) json_decode((string) file_get_contents($file), true);
        $rows = (array) $raw['subscriptions'];
        self::assertSame(self::RPC_URL, $rows[$result['keyHash']]['endpoint']);
    }

    public function testAnOffAllowlistEndpointIsRefusedAndNeverStored(): void
    {
        $client = $this->rig->client();
        $this->rig->session($client);

        $reply = $client->request('push.subscribe', [
            'endpoint' => 'https://attacker.example/notify-me',
            'keys' => ['p256dh' => self::RPC_POINT, 'auth' => self::RPC_AUTH],
        ]);

        self::assertSame(ErrorCode::InvalidParams->value, $reply['error']['code']);
        self::assertSame('endpoint_denied', $reply['error']['data']['kind']);
        self::assertStringContainsString('attacker.example', $reply['error']['message']);
        self::assertSame([], $this->rig->context->pushStore()->entries(),
            'the words of the sender allowlist are the store door too');
    }

    public function testAMalformedEndpointIsRefusedBeforeTheStore(): void
    {
        $client = $this->rig->client();
        $this->rig->session($client);

        $reply = $client->request('push.subscribe', [
            'endpoint' => 'http://updates.push.services.mozilla.com/wpush/v2/tokenabc',
            'keys' => ['p256dh' => self::RPC_POINT, 'auth' => self::RPC_AUTH],
        ]);

        self::assertSame(ErrorCode::InvalidParams->value, $reply['error']['code']);
        self::assertSame('endpoint_refused', $reply['error']['data']['kind']);
        self::assertSame([], $this->rig->context->pushStore()->entries());
    }

    public function testKeyPartsThatAreNotStringsComeBackAsParamErrors(): void
    {
        $client = $this->rig->client();
        $this->rig->session($client);

        $reply = $client->request('push.subscribe', [
            'endpoint' => self::RPC_URL,
            'keys' => ['p256dh' => 42, 'auth' => self::RPC_AUTH],
        ]);

        self::assertSame(ErrorCode::InvalidParams->value, $reply['error']['code']);
        self::assertStringContainsString('keys.p256dh', $reply['error']['message'],
            'the wire schema types the key parts, so the refusal names the member before the handler runs');
        self::assertSame([], $this->rig->context->pushStore()->entries());
    }

    public function testUnsubscribeRemovesTheRowAndAnswersFalseForNothingLeft(): void
    {
        $client = $this->rig->client();
        $this->rig->session($client);

        $client->call('push.subscribe', [
            'endpoint' => self::RPC_URL,
            'keys' => ['p256dh' => self::RPC_POINT, 'auth' => self::RPC_AUTH],
        ]);

        $gone = $client->call('push.unsubscribe', ['endpoint' => self::RPC_URL]);
        self::assertTrue($gone['unsubscribed']);
        self::assertSame([], $this->rig->context->pushStore()->entries());

        $again = $client->call('push.unsubscribe', ['endpoint' => self::RPC_URL]);
        self::assertFalse($again['unsubscribed']);
    }

    public function testTheVapidKeyAnswerIsTheMintedPublicPoint(): void
    {
        $client = $this->rig->client();

        $first = $client->call('push.vapidKey');
        $second = $client->call('push.vapidKey');

        $wire = (string) $first['publicKey'];
        self::assertSame($wire, $second['publicKey'], 'one pair per state dir, mint once');

        $bytes = base64_decode(strtr($wire, '-_', '+/'), true);
        self::assertIsString($bytes, 'the answer is base64url with no padding games');
        self::assertSame(65, \strlen($bytes));
        self::assertSame("\x04", $bytes[0], 'an uncompressed P-256 point is what a UA registers');

        $pem = $this->rig->dir . '/' . StateDir::VAPID_KEY_FILE;
        self::assertSame('0600', substr(\sprintf('%o', (int) \fileperms($pem)), -4));
    }

    // ------------------------------------------------------------ triggers

    public function testAQuestionAndACompletionEachDialOnceWithRedactedText(): void
    {
        [$seam, $store, $browser, $cipher, $sessionId] = $this->pushRig(false);

        $seam->host($sessionId)->announce(SessionEvent::new(
            SessionEvent::PERMISSION_REQUESTED,
            ['askId' => 'ask-1', 'toolCallId' => 'call-1', 'tool' => 'Edit'],
            $sessionId,
        ));
        $seam->host($sessionId)->announce(SessionEvent::new(
            SessionEvent::TURN_COMPLETED,
            ['stopReason' => 'end_turn'],
            $sessionId,
        ));

        self::assertCount(2, $browser->calls, 'one send per event, no doubles');

        $question = (string) json_encode([
            'kind' => 'question',
            'body' => Lang::t('serve.push.notify_question'),
            'sessionId' => $sessionId,
        ]);
        $finished = (string) json_encode([
            'kind' => 'finished',
            'body' => Lang::t('serve.push.notify_completed'),
            'sessionId' => $sessionId,
        ]);

        self::assertSame($cipher->encrypt($question, $this->rpcSubscription()), $browser->calls[0]['body']);
        self::assertSame($cipher->encrypt($finished, $this->rpcSubscription()), $browser->calls[1]['body']);
        self::assertStringNotContainsString('Edit', $question . $finished,
            'the tool name never rides into a push payload — the body is generic by law');

        // Nothing settled yet on either dial (see the next test for the
        // deferred half); the row survives until an answer says otherwise.
        self::assertNotSame([], $store->entries());
    }

    public function testDeliveryIsNeverAwaitedAndAGoneAnswerPrunesTheRow(): void
    {
        [$seam, $store, $browser, , $sessionId] = $this->pushRig(false);

        $seam->host($sessionId)->announce(SessionEvent::new(SessionEvent::TURN_COMPLETED, ['stopReason' => 'end_turn'], $sessionId));

        // The announce already returned while the transport answer is still
        // open — the serve loop never blocks on a dial (design risk law).
        self::assertCount(1, $browser->calls);
        self::assertNotSame([], $store->entries(), 'no pruning before anything answered');

        $browser->pend[0]->resolve(new Response(410));
        $this->rig->run(0.02);

        self::assertSame([], $store->entries(), 'a 410 means the UA forgot this endpoint; stop carrying it');
    }

    public function testAFollowedSessionSendsNothing(): void
    {
        [$seam, $store, $browser, , $sessionId] = $this->pushRig(false);

        $this->seamDispatcher = Dispatcher::new($seam);
        $watcher = WireClient::open($this->seamDispatcher, 'push-rpc-watcher');
        $watcher->hello();
        $watcher->call('session.subscribe', ['sessionId' => $sessionId]);

        $seam->host($sessionId)->announce(SessionEvent::new(SessionEvent::PERMISSION_REQUESTED, ['askId' => 'ask-9'], $sessionId));

        self::assertSame([], $browser->calls,
            'a client is watching — the terminal answers, the phone must stay silent');
        self::assertNotSame([], $store->entries());
    }

    public function testAnOptedInRowCarriesTheSessionNameAndOthersDoNot(): void
    {
        [$seam, , $browser, $cipher, $sessionId] = $this->pushRig(true);

        $client = $this->rig->client();
        $client->call('session.rename', ['sessionId' => $sessionId, 'name' => 'Deploy notes']);

        $seam->host($sessionId)->announce(SessionEvent::new(SessionEvent::PERMISSION_REQUESTED, ['askId' => 'ask-3'], $sessionId));

        $withName = (string) json_encode([
            'kind' => 'question',
            'body' => Lang::t('serve.push.notify_question'),
            'sessionId' => $sessionId,
            'title' => 'Deploy notes',
        ]);
        self::assertSame($cipher->encrypt($withName, $this->rpcSubscription()), $browser->calls[0]['body'],
            'the row that opted in with `name: true` is the only one that may carry the title');
    }

    // ------------------------------------------------------------- plumbing

    /**
     * A session over the fixture hub, a second ServerContext carrying the
     * injected store and a fake-dialled sender, and one subscription row.
     *
     * @return array{0: ServerContext, 1: PushSubscriptionStore, 2: PushRpcFakeBrowser, 3: PushPayloadCipher, 4: string}
     */
    private function pushRig(bool $wantsName): array
    {
        $client = $this->rig->client();
        $sessionId = $this->rig->session($client);

        $pushDir = $this->rig->dir . '/push-' . \bin2hex(\random_bytes(4));
        self::assertTrue(mkdir($pushDir, 0700));

        $store = PushSubscriptionStore::forStateDir($pushDir);
        $store->put($this->rpcSubscription(), 'bearer', $wantsName);

        $browser = new PushRpcFakeBrowser();

        $cipher = $this->rpcFixedEntropyCipher();
        $sender = WebPushSender::new(
            VapidKeys::new($pushDir),
            $cipher,
            static fn (string $host): Browser => $browser,
            static fn (): array => [self::RPC_IP],
            null,
        );

        $seam = ServerContext::new(
            $this->rig->hub,
            ServerConfig::new($pushDir)->withRoot($this->rig->root),
            'cweb5d',
            pushStore: $store,
            pushSender: $sender,
        );
        $seam->feedFor($seam->host($sessionId));

        return [$seam, $store, $browser, $cipher, $sessionId];
    }

    private function rpcSubscription(): PushSubscription
    {
        return PushSubscription::new(self::RPC_URL, self::RPC_POINT, self::RPC_AUTH);
    }

    /** Salt and receiver-side pair frozen so a re-encryption proves the bytes. */
    private function rpcFixedEntropyCipher(): PushPayloadCipher
    {
        $salt = \random_bytes(16);
        $resource = \openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($resource === false || \openssl_pkey_export($resource, $localPem) !== true) {
            throw new \LogicException('OpenSSL could not create a P-256 test pair');
        }

        return PushPayloadCipher::new()->withEntropy(
            static fn (): string => $salt,
            static fn (): string => (string) $localPem,
        );
    }
}

/**
 * Records every request and hands back a still-open promise; extends the
 * real Browser so the sender runs its full contract unchanged.
 */
final class PushRpcFakeBrowser extends Browser
{
    /** @var list<array{method: string, url: string, headers: array<array-key, mixed>, body: string}> */
    public array $calls = [];

    /** @var list<Deferred> one open answer per request, for tests to settle */
    public array $pend = [];

    public function __construct()
    {
        parent::__construct();
    }

    public function request(mixed $method, mixed $url, array $headers = [], mixed $body = ''): PromiseInterface
    {
        $this->calls[] = [
            'method' => (string) $method,
            'url' => (string) $url,
            'headers' => $headers,
            'body' => (string) $body,
        ];
        $this->pend[] = $deferred = new Deferred();

        return $deferred->promise();
    }
}

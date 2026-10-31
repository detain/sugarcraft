<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server\Push;

use Closure;
use LogicException;
use PHPUnit\Framework\TestCase;
use React\Http\Browser;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use RuntimeException;
use SugarCraft\Code\Server\Push\PushPayloadCipher;
use SugarCraft\Code\Server\Push\PushSubscription;
use SugarCraft\Code\Server\Push\VapidKeys;
use SugarCraft\Code\Server\Push\WebPushSender;
use SugarCraft\Code\Server\StateDir;

/**
 * The sender's two doors, in the order it asks them: the allowlist first
 * (a host outside the push-service family is refused as a policy decision,
 * with nothing resolved and nothing dialed), then the same SSRF guard every
 * other outbound fetch answers to (a vetted host whose name resolves into a
 * reserved range still never reaches a socket).
 *
 * The transport is a recording {@see PushCmpFakeBrowser}; the production
 * factory's no-redirect and no-error-rejection contracts are pinned against
 * the class's own source, because they are only exercised on the socket path.
 */
final class WebPushSenderTest extends TestCase
{
    private const DIAL_ADDRESS = '93.184.216.34';

    private string $dir = '';

    /** @var list<PushCmpFakeBrowser> */
    private array $browsers = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/serve-' . bin2hex(random_bytes(4)) . '/pushsend';
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/' . chr(42)) ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testAHostOutsideThePushServiceFamilyIsRefusedAndNeverDialled(): void
    {
        $sender = $this->senderResolvingTo(self::DIAL_ADDRESS);
        $subscription = PushSubscription::new(
            'https://attacker.example/notify/me',
            $this->makeLocalPair()[1],
            'BTBZMqHH6r4Tts7J_aSIgg'
        );

        [, $error] = $this->settlePromise($sender->send($subscription, 'hello', 60));

        self::assertInstanceOf(RuntimeException::class, $error);
        self::assertStringContainsString('is not a known push service', $error->getMessage());
        self::assertStringContainsString('attacker.example', $error->getMessage());
        self::assertSame([], $this->allDialledUrls(), 'the allowlist door closes before DNS or sockets');
    }

    public function testAnHttpEndpointCannotEvenDescribeASubscription(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PushSubscription::new('http://fcm.googleapis.com/push/x', $this->makeLocalPair()[1], 'BTBZMqHH6r4Tts7J_aSIgg');
    }

    public function testTheAllowlistIsExactHostsPlusStrictServiceSuffixes(): void
    {
        foreach ([
            'fcm.googleapis.com',
            'push.services.mozilla.com',
            'updates.push.services.mozilla.com',
            'api.push.apple.com',
            'api.sandbox.push.apple.com',
            'gateway.push.apple.com',
            'informed.notify.microsoft.com',
            'eu.notify.windows.com',
            'any.notify.live.net',
        ] as $admitted) {
            self::assertTrue(WebPushSender::endpointAllowed($admitted), "$admitted is a push service");
        }

        foreach ([
            'notify.microsoft.com',
            'notnotify.microsoft.com',
            'fcm.googleapis.com.attacker.example',
            'attacker.fcm.googleapis.com',
            'apple.com',
        ] as $refused) {
            self::assertFalse(WebPushSender::endpointAllowed($refused), "$refused must stay outside the family");
        }
    }

    public function testTheDialNamesTheGuardLiteralWhileTheHostHeaderNamesTheService(): void
    {
        [$sender, $cipher] = $this->fixedEntropySender(self::DIAL_ADDRESS);
        $subscription = $this->mozillaSubscription();

        [$status, $error] = $this->settlePromise($sender->send($subscription, 'stand by', 120));

        self::assertNull($error);
        self::assertSame(201, $status);
        $call = $this->browsers[0]->calls[0];

        self::assertSame('POST', $call['method']);
        self::assertSame('https://' . self::DIAL_ADDRESS . ':443/wpush/v2/tokenabc', $call['url'],
            'the URL authority is the vetted literal, never the hostname');
        self::assertSame('updates.push.services.mozilla.com', $call['headers']['Host']);
        self::assertSame('120', $call['headers']['TTL']);
        self::assertSame('aes128gcm', $call['headers']['Content-Encoding']);
        self::assertSame('application/octet-stream', $call['headers']['Content-Type']);
        self::assertStringStartsWith('vapid t=', $call['headers']['Authorization']);
        self::assertSame($cipher->encrypt('stand by', $subscription), $call['body']);
    }

    public function testAReservedAddressBehindAVettedHostIsRefusedBeforeDialling(): void
    {
        $sender = $this->senderResolvingTo('127.0.0.1');

        [, $error] = $this->settlePromise($sender->send($this->mozillaSubscription(), 'hello'));

        self::assertInstanceOf(RuntimeException::class, $error);
        self::assertStringContainsString('cannot be dialed', $error->getMessage());
        self::assertStringContainsString('private/link-local', $error->getMessage());
        self::assertSame([], $this->allDialledUrls());
    }

    public function testAnAllowedHostThatResolvesNowhereRefusesWithoutDialling(): void
    {
        $sender = $this->buildSender(static fn(): array => [], static fn(): PromiseInterface => \React\Promise\resolve(new Response(201)));

        [, $error] = $this->settlePromise($sender->send($this->mozillaSubscription(), 'hello'));

        self::assertInstanceOf(RuntimeException::class, $error);
        self::assertStringContainsString('cannot be dialed', $error->getMessage());
        self::assertSame([], $this->allDialledUrls());
    }

    public function testGoneStatusesComeBackAsDataAndMarkTheSubscriptionGone(): void
    {
        foreach ([404, 410] as $status) {
            $sender = $this->senderResolvingTo(self::DIAL_ADDRESS, $status);

            [$answered, $error] = $this->settlePromise($sender->send($this->mozillaSubscription(), 'hello'));

            self::assertNull($error, "a $status is the service answering, not a transport failure");
            self::assertSame($status, $answered);
            self::assertTrue(WebPushSender::subscriptionIsGone($status));
        }

        self::assertFalse(WebPushSender::subscriptionIsGone(500));
        self::assertFalse(WebPushSender::subscriptionIsGone(201));
        self::assertTrue(WebPushSender::deliveryAccepted(201));
        self::assertFalse(WebPushSender::deliveryAccepted(404));
    }

    public function testARedirectArrivesUnfollowedAsASingleCall(): void
    {
        $sender = $this->senderResolvingTo(self::DIAL_ADDRESS, 307);

        [$answered, $error] = $this->settlePromise($sender->send($this->mozillaSubscription(), 'hello'));

        self::assertNull($error);
        self::assertSame(307, $answered, 'a 3xx is returned as data; the sender never follows it');
        self::assertCount(1, $this->browsers[0]->calls);
    }

    public function testTheProductionTransportPinsNoRedirectsAndAllStatusesAsData(): void
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents(dirname(__DIR__, 3) . '/src/Server/Push/WebPushSender.php')) as $token) {
            if (is_string($token)) {
                $code .= $token;
            } elseif ($token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
                $code .= $token[1];
            }
        }

        self::assertSame(1, substr_count($code, '->withFollowRedirects(false)'),
            'the factory that dials real sockets must refuse redirects (class contract)');
        self::assertSame(1, substr_count($code, '->withRejectErrorResponse(false)'),
            'and report every status instead of rejecting non-2xx');
    }

    public function testASyncTransportExplosionBecomesARefusalSentence(): void
    {
        $sender = $this->buildSender(
            static fn(): array => [self::DIAL_ADDRESS],
            static function (): PromiseInterface {
                throw new RuntimeException('connection refused by peer');
            }
        );

        [, $error] = $this->settlePromise($sender->send($this->mozillaSubscription(), 'hello'));

        self::assertInstanceOf(RuntimeException::class, $error);
        self::assertStringContainsString('could not be reached', $error->getMessage());
        self::assertStringContainsString('connection refused by peer', $error->getMessage());
    }

    public function testATtlEdgeStaysHomeWhileZeroStillDials(): void
    {
        $sender = $this->senderResolvingTo(self::DIAL_ADDRESS);
        $subscription = $this->mozillaSubscription();

        [$answered, $error] = $this->settlePromise($sender->send($subscription, 'hello', 0));
        self::assertNull($error);
        self::assertSame(201, $answered);
        self::assertSame('0', $this->browsers[0]->calls[0]['headers']['TTL']);

        [, $negative] = $this->settlePromise($sender->send($subscription, 'hello', -1));
        self::assertInstanceOf(\InvalidArgumentException::class, $negative);
        self::assertStringContainsString('TTL', $negative->getMessage());

        [, $huge] = $this->settlePromise($sender->send($subscription, 'hello', PHP_INT_MAX));
        self::assertInstanceOf(\InvalidArgumentException::class, $huge);
        self::assertCount(1, $this->browsers[0]->calls, 'the refused TTLs never reached a socket');
    }

    public function testThePrivateHalfNeverRidesAnyOutboundString(): void
    {
        [$sender, $cipher] = $this->fixedEntropySender(self::DIAL_ADDRESS);

        $sender->send($this->mozillaSubscription(), 'hello')->then(null, static function () {});
        $call = $this->browsers[0]->calls[0];

        $pem = (string) file_get_contents($this->dir . '/' . StateDir::VAPID_KEY_FILE);
        $secretRun = substr(trim(explode("\n", $pem)[1]), 6, 28);

        $wire = $call['url'] . "\0" . implode("\0", $call['headers']) . "\0" . $call['body'];
        self::assertStringNotContainsString($secretRun, $wire);
        self::assertStringNotContainsString('PRIVATE KEY', $wire);
        self::assertMatchesRegularExpression('#^[^\x00]*$#', $call['url'] . implode('', array_values($call['headers'])));
        unset($cipher);
    }

    /** @return array{0: WebPushSender, 1: PushPayloadCipher} */
    private function fixedEntropySender(string $answersWith): array
    {
        $salt = random_bytes(16);
        [$localPem, ] = $this->makeLocalPair();
        $cipher = PushPayloadCipher::new()->withEntropy(
            static fn(): string => $salt,
            static fn(): string => $localPem
        );

        return [$this->buildSender(static fn(): array => [$answersWith], static fn(): PromiseInterface => \React\Promise\resolve(new Response(201)), $cipher), $cipher];
    }

    private function senderResolvingTo(string $answersWith, int $status = 201): WebPushSender
    {
        return $this->buildSender(
            static fn(): array => [$answersWith],
            static fn(): PromiseInterface => \React\Promise\resolve(new Response($status, [], ''))
        );
    }

    /** @param (callable(): PromiseInterface) $answer */
    private function buildSender(callable $resolveAddresses, callable $answer, ?PushPayloadCipher $cipher = null): WebPushSender
    {
        $sender = WebPushSender::new(
            VapidKeys::new($this->dir),
            $cipher,
            function (string $host) use ($answer): Browser {
                $browser = new PushCmpFakeBrowser($answer);
                $this->browsers[] = $browser;

                return $browser;
            },
            $resolveAddresses,
            null,
        );

        return $sender;
    }

    /** The RFC 8291 example subscription: autopush host, UA point and auth secret. */
    private function mozillaSubscription(): PushSubscription
    {
        return PushSubscription::new(
            'https://updates.push.services.mozilla.com/wpush/v2/tokenabc',
            'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4',
            'BTBZMqHH6r4Tts7J_aSIgg'
        );
    }

    /** @return array{0: string, 1: string} the PEM and the base64url public point */
    private function makeLocalPair(): array
    {
        $resource = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($resource === false || openssl_pkey_export($resource, $pem) !== true) {
            throw new LogicException('OpenSSL could not create a P-256 test pair');
        }
        $details = openssl_pkey_get_details($resource);
        if ($details === false) {
            throw new LogicException('OpenSSL could not describe the test pair');
        }

        $point = openssl_pkey_get_details($resource);
        if ($point === false) {
            throw new LogicException('OpenSSL could not describe the test pair');
        }

        return [(string) $pem, rtrim(strtr(base64_encode("\x04" . $point['ec']['x'] . $point['ec']['y']), '+/', '-_'), '=')];
    }

    /** @return array{0: mixed, 1: ?\Throwable} settled value and settled error */
    private function settlePromise(PromiseInterface $promise): array
    {
        $value = null;
        $error = null;
        $promise->then(
            static function ($answered) use (&$value): void {
                $value = $answered;
            },
            static function (\Throwable $problem) use (&$error): void {
                $error = $problem;
            }
        );

        return [$value, $error];
    }

    /** @return list<string> */
    private function allDialledUrls(): array
    {
        $urls = [];
        foreach ($this->browsers as $browser) {
            foreach ($browser->calls as $call) {
                $urls[] = (string) $call['url'];
            }
        }

        return $urls;
    }
}

/**
 * Records every request and answers with the injected closure; extends the
 * real Browser so the sender's type contract is exercised unchanged.
 */
final class PushCmpFakeBrowser extends Browser
{
    /** @var list<array{method: string, url: string, headers: array<string,string>, body: string}> */
    public array $calls = [];

    public function __construct(private readonly Closure $answer)
    {
        parent::__construct();
    }

    public function request($method, $url, array $headers = [], $body = '')
    {
        $this->calls[] = ['method' => (string) $method, 'url' => (string) $url, 'headers' => $headers, 'body' => (string) $body];

        return ($this->answer)();
    }
}

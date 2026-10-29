<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\ServerRequest as HttpRequest;
use SugarCraft\Code\Server\Auth\RateLimiter;
use SugarCraft\Code\Server\Http\HookWebhooks;
use SugarCraft\Code\Server\ServerConfig;

/**
 * C-AGT-5.d: the signed inbound door — `POST /api/hooks/{name}` with a row
 * naming a signature scheme. GitHub cannot attach a custom header, so the
 * credential becomes an HMAC-SHA256 of the RAW body keyed by the row secret,
 * `sha256=<hex>` in the scheme's signature header, verified IN PLACE OF the
 * header-secret compare; failed signatures feed the SAME per-hook-and-address
 * lockout. A seen delivery id answers the duplicate without spawning a second
 * session — ids live per hook in an LRU ring, and only admitted deliveries are
 * recorded, so a 503 keeps the sender's retry of the same event alive. An
 * over-cap signed body is answered 413 BEFORE the compare, never struck as a
 * bad signature.
 *
 * Direct-drive over {@see HookWebhooks::handle()}, like the sibling's overall
 * -rate test: the request never needs a socket, and the injected clock makes
 * the lockout window advance-on-demand (E646: no timers here).
 */
final class HookWebhooksSignatureTest extends TestCase
{
    private const SECRET = 'signed-inbound-webhook-secret-longer-than-thirty';

    private const WEBHOOK_BODY = '{"ref":"refs/heads/main"}';

    private float $now = 1_700_000_000.0;

    private bool $throwNext = false;

    /** @var list<array{name:string,hook:array<string,mixed>,prompt:string}> */
    private array $deliveries = [];

    /** @var \Closure(string,array<string,mixed>,string):array<string,mixed> */
    private \Closure $run;

    protected function setUp(): void
    {
        $this->throwNext = false;
        $this->deliveries = [];
        $this->run = function (string $name, array $hook, string $prompt): array {
            if ($this->throwNext) {
                throw new \RuntimeException('the background door refused');
            }
            $this->deliveries[] = ['name' => $name, 'hook' => $hook, 'prompt' => $prompt];

            return ['bgId' => 'bg-signature', 'status' => 'running'];
        };
    }

    public function testAValidGithubSignatureRunsAndTheHeaderSecretIsNotConsulted(): void
    {
        $handler = $this->handler($this->hooks(['scheme' => 'github-sha256']));
        // A VALID header secret is deliberately absent: the signed door must
        // not fall back to it. The fall-back refusal is pinned where it bites
        // — the valid-header-secret-rides-a-missing-signature leg in
        // testABadSignatureIsRefusedAndStrikesTheLockout().
        $answer = $handler->handle($this->request([
            'x-hub-signature-256' => $this->signature(self::WEBHOOK_BODY),
            'x-github-delivery' => 'uuid-one',
        ], self::WEBHOOK_BODY), 'deploy', '127.0.0.1');

        self::assertSame(200, $answer->getStatusCode());
        self::assertStringContainsString('"bgId":"bg-signature"', (string) $answer->getBody());
        self::assertCount(1, $this->deliveries);
        self::assertStringContainsString('Look at', $this->deliveries[0]['prompt']);
        self::assertStringContainsString('refs/heads/main', $this->deliveries[0]['prompt'], 'the body rides into the prompt fence-escaped');
    }

    public function testTheConfiguredCustomHeadersCarryTheDelivery(): void
    {
        $handler = $this->handler($this->hooks(['scheme' => 'hmac-sha256', 'header' => 'x-cust-sig', 'deliveryHeader' => 'x-req-id']));
        // Wrong-case header keys: PSR-7 lookup is case-insensitive, and the
        // resolved scheme names are lower-case.
        $answer = $handler->handle($this->request([
            'X-Cust-Sig' => $this->signature(self::WEBHOOK_BODY),
            'X-Req-Id' => 'evt-1',
        ], self::WEBHOOK_BODY), 'deploy', '127.0.0.1');

        self::assertSame(200, $answer->getStatusCode());
        self::assertCount(1, $this->deliveries);
    }

    /**
     * THE CONSTANT-TIME LAW for the new door, pinned at the source exactly as
     * the sibling pins the header compare: swapping hash_equals for `===`
     * keeps every behaviour test green — only this scan discriminates.
     */
    public function testTheSignatureCompareIsTheConstantTimePrimitive(): void
    {
        $source = (string) \file_get_contents(__DIR__ . '/../../src/Server/Http/HookWebhooks.php');

        self::assertMatchesRegularExpression(
            '/\\\\hash_equals\(\$expected, \$request->getHeaderLine\(\$signature\[\'header\'\]\)\)/',
            $source,
        );
    }

    public function testABadSignatureIsRefusedAndStrikesTheLockout(): void
    {
        $handler = $this->handler($this->hooks(['scheme' => 'github-sha256']));
        $signed = [
            'x-hub-signature-256' => 'sha256=' . \str_repeat('0', 64),
            'x-github-delivery' => 'uuid-bad',
        ];
        $wrong = $handler->handle($this->request($signed, self::WEBHOOK_BODY), 'deploy', '127.0.0.1');
        self::assertSame(401, $wrong->getStatusCode());
        self::assertStringContainsString('"message":"the webhook signature is not valid"', (string) $wrong->getBody());
        self::assertStringContainsString('"kind":"unauthorized"', (string) $wrong->getBody());

        // THE NO-FALLBACK LAW, discriminated: the header secret rides this
        // request VALID — a permissive `HMAC || header-secret` refactor would
        // admit this delivery and so reds the very next assertion; the signed
        // door refuses it either way.
        $missing = $handler->handle($this->request([
            'x-candy-code-hook' => self::SECRET,
            'x-github-delivery' => 'uuid-bad',
        ], self::WEBHOOK_BODY), 'deploy', '127.0.0.1');
        self::assertSame(401, $missing->getStatusCode(), 'a missing signature is a refused signature, header secret or not');
        self::assertCount(0, $this->deliveries);

        // Fill the failure window with bad signatures: the strike count that
        // locks the header door locks this one too (same limiter, same key).
        for ($i = 0; $i < RateLimiter::MAX_FAILURES - 2; ++$i) {
            self::assertSame(401, $handler->handle($this->request($signed, self::WEBHOOK_BODY), 'deploy', '127.0.0.1')->getStatusCode());
        }
        $locked = $handler->handle($this->request([
            'x-hub-signature-256' => $this->signature(self::WEBHOOK_BODY),
            'x-github-delivery' => 'uuid-late',
        ], self::WEBHOOK_BODY), 'deploy', '127.0.0.1');
        self::assertSame(429, $locked->getStatusCode(), 'a valid signature arrives at a locked door');
        self::assertSame((string) RateLimiter::LOCKOUT_SECONDS, (string) $locked->getHeaderLine('Retry-After'));

        $this->now += RateLimiter::LOCKOUT_SECONDS + 1.0;
        self::assertSame(200, $handler->handle($this->request([
            'x-hub-signature-256' => $this->signature(self::WEBHOOK_BODY),
            'x-github-delivery' => 'uuid-late',
        ], self::WEBHOOK_BODY), 'deploy', '127.0.0.1')->getStatusCode());
        self::assertCount(1, $this->deliveries, 'the lockout expired; the delivery runs');
    }

    public function testAReplayedDeliveryIdAnswersTheDuplicateWithoutSpawning(): void
    {
        $handler = $this->handler($this->hooks(['scheme' => 'github-sha256']));
        $headers = [
            'x-hub-signature-256' => $this->signature(self::WEBHOOK_BODY),
            'x-github-delivery' => 'uuid-replay',
        ];

        $first = $handler->handle($this->request($headers, self::WEBHOOK_BODY), 'deploy', '127.0.0.1');
        self::assertSame(200, $first->getStatusCode());
        self::assertStringContainsString('"bgId"', (string) $first->getBody());

        $second = $handler->handle($this->request($headers, self::WEBHOOK_BODY), 'deploy', '127.0.0.1');
        self::assertSame(200, $second->getStatusCode());
        self::assertSame('{"ok":true,"duplicate":true}', (string) $second->getBody(), 'the duplicate answer is its own shape, no background id');
        self::assertCount(1, $this->deliveries, 'the replay spawned nothing');
    }

    public function testReplaysChargeNeitherTheRateNorTheLockoutWindow(): void
    {
        $handler = $this->handler($this->hooks(['scheme' => 'github-sha256']));
        $headers = [
            'x-hub-signature-256' => $this->signature(self::WEBHOOK_BODY),
            'x-github-delivery' => 'uuid-churn',
        ];
        self::assertSame(200, $handler->handle($this->request($headers, self::WEBHOOK_BODY), 'deploy', '127.0.0.1')->getStatusCode());

        // The rate row is 5 — far fewer than the strikes below. A redeliver
        // storm (GitHub resends on timeout; a broker replays on restart) must
        // neither spend the delivery rate nor feed the failure window.
        for ($i = 0; $i < RateLimiter::MAX_FAILURES + 1; ++$i) {
            $answer = $handler->handle($this->request($headers, self::WEBHOOK_BODY), 'deploy', '127.0.0.1');
            self::assertSame(200, $answer->getStatusCode());
            self::assertStringContainsString('"duplicate":true', (string) $answer->getBody());
        }
        self::assertCount(1, $this->deliveries);

        $fresh = $handler->handle($this->request([
            'x-hub-signature-256' => $this->signature(self::WEBHOOK_BODY),
            'x-github-delivery' => 'uuid-fresh',
        ], self::WEBHOOK_BODY), 'deploy', '127.0.0.1');
        self::assertSame(200, $fresh->getStatusCode(), 'the id churn cost the hook no rate budget');
        self::assertStringContainsString('"bgId"', (string) $fresh->getBody());
        self::assertCount(2, $this->deliveries);
    }

    public function testADeliveryWithoutAnIdNeverDeduplicates(): void
    {
        // A bare hmac-sha256 row carries no default delivery header (opt-in):
        // two byte-identical deliveries are two sessions, exactly as before
        // this lane — dedupe must never silently eat legitimate repeats.
        $handler = $this->handler($this->hooks(['scheme' => 'hmac-sha256']));
        self::assertSame(200, $handler->handle($this->request(['x-signature' => $this->signature(self::WEBHOOK_BODY)], self::WEBHOOK_BODY), 'deploy', '127.0.0.1')->getStatusCode());
        self::assertSame(200, $handler->handle($this->request(['x-signature' => $this->signature(self::WEBHOOK_BODY)], self::WEBHOOK_BODY), 'deploy', '127.0.0.1')->getStatusCode());
        self::assertCount(2, $this->deliveries);

        // A github row whose header set omits the delivery id: present door,
        // no id → no dedupe.
        $gh = $this->handler($this->hooks(['scheme' => 'github-sha256']));
        self::assertSame(200, $gh->handle($this->request(['x-hub-signature-256' => $this->signature(self::WEBHOOK_BODY)], self::WEBHOOK_BODY), 'deploy', '127.0.0.1')->getStatusCode());
        self::assertSame(200, $gh->handle($this->request(['x-hub-signature-256' => $this->signature(self::WEBHOOK_BODY)], self::WEBHOOK_BODY), 'deploy', '127.0.0.1')->getStatusCode());
        self::assertCount(4, $this->deliveries);
    }

    public function testAFailedAdmissionKeepsTheIdReplayable(): void
    {
        $handler = $this->handler($this->hooks(['scheme' => 'github-sha256']));
        $headers = [
            'x-hub-signature-256' => $this->signature(self::WEBHOOK_BODY),
            'x-github-delivery' => 'uuid-retry',
        ];
        $this->throwNext = true;
        $failed = $handler->handle($this->request($headers, self::WEBHOOK_BODY), 'deploy', '127.0.0.1');
        self::assertSame(503, $failed->getStatusCode());
        self::assertCount(0, $this->deliveries);

        $this->throwNext = false;
        $retried = $handler->handle($this->request($headers, self::WEBHOOK_BODY), 'deploy', '127.0.0.1');
        self::assertSame(200, $retried->getStatusCode(), 'the senders retry of a failed event must still run');
        self::assertStringContainsString('"bgId"', (string) $retried->getBody(), 'not an answer of duplicate to an event never served');
        self::assertCount(1, $this->deliveries);

        // And NOW the id is recorded: the third arrival is the duplicate.
        $third = $handler->handle($this->request($headers, self::WEBHOOK_BODY), 'deploy', '127.0.0.1');
        self::assertSame('{"ok":true,"duplicate":true}', (string) $third->getBody());
    }

    public function testAnOverCapSignedBodyIsRefusedBeforeTheCompareAndStrikesNothing(): void
    {
        $handler = $this->handler($this->hooks(['scheme' => 'github-sha256']));
        $body = \str_repeat('{"push":', ServerConfig::MAX_API_BODY_BYTES);
        $body = \substr($body, 0, ServerConfig::MAX_API_BODY_BYTES + 5000);
        $headers = [
            'x-hub-signature-256' => $this->signature($body),
            'content-length' => (string) \strlen($body),
            'x-github-delivery' => 'uuid-big',
        ];

        // More over-cap arrivals than the failure budget: the 413 must answer
        // them all, before the compare, charging no strikes — a legitimate
        // large push must never be locked out of the hook it was using.
        for ($i = 0; $i < RateLimiter::MAX_FAILURES + 2; ++$i) {
            $answer = $handler->handle($this->request($headers, $body), 'deploy', '127.0.0.1');
            self::assertSame(413, $answer->getStatusCode());
            self::assertStringContainsString('"kind":"payload_too_large"', (string) $answer->getBody());
        }
        self::assertCount(0, $this->deliveries, 'the oversized body never reached the prompt');

        $small = $handler->handle($this->request([
            'x-hub-signature-256' => $this->signature(self::WEBHOOK_BODY),
            'x-github-delivery' => 'uuid-small',
        ], self::WEBHOOK_BODY), 'deploy', '127.0.0.1');
        self::assertSame(200, $small->getStatusCode(), 'no lockout was earned by honest over-cap traffic');
        self::assertCount(1, $this->deliveries);
    }

    public function testTheDeliveryRingIsLeastRecentlyUsed(): void
    {
        $handler = $this->handler($this->hooks(['scheme' => 'github-sha256'], ServerConfig::MAX_HOOK_RATE_PER_MINUTE));
        $signature = $this->signature(self::WEBHOOK_BODY);
        // CAP+1 distinct fresh ids fill the ring; the rate window is the only
        // budget that could refuse them, so "now" advances every 550.
        for ($i = 1; $i <= HookWebhooks::INBOUND_DELIVERY_CAPACITY + 1; ++$i) {
            if ($i % 550 === 0) {
                $this->now += HookWebhooks::WINDOW_SECONDS + 1.0;
            }
            $answer = $handler->handle($this->request([
                'x-hub-signature-256' => $signature,
                'x-github-delivery' => 'd-' . $i,
            ], self::WEBHOOK_BODY), 'deploy', '127.0.0.1');
            self::assertSame(200, $answer->getStatusCode(), 'delivery ' . $i . ' admitted');
            self::assertStringContainsString('"bgId"', (string) $answer->getBody());
        }
        self::assertCount(HookWebhooks::INBOUND_DELIVERY_CAPACITY + 1, $this->deliveries);

        $first = $handler->handle($this->request([
            'x-hub-signature-256' => $signature,
            'x-github-delivery' => 'd-1',
        ], self::WEBHOOK_BODY), 'deploy', '127.0.0.1');
        self::assertStringContainsString('"bgId"', (string) $first->getBody(), 'the oldest id was evicted; its replay runs again');
        self::assertCount(HookWebhooks::INBOUND_DELIVERY_CAPACITY + 2, $this->deliveries);

        $newest = $handler->handle($this->request([
            'x-hub-signature-256' => $signature,
            'x-github-delivery' => 'd-' . (HookWebhooks::INBOUND_DELIVERY_CAPACITY + 1),
        ], self::WEBHOOK_BODY), 'deploy', '127.0.0.1');
        self::assertSame('{"ok":true,"duplicate":true}', (string) $newest->getBody(), 'the freshest ids survived the eviction');
    }

    /** @param array<string,mixed> $signature */
    private function hooks(array $signature, int $rate = 5): array
    {
        return ServerConfig::webhooksValue([
            'deploy' => [
                'secret' => self::SECRET,
                'prompt' => 'Look at {{payload}} now',
                'rate' => $rate,
                'signature' => $signature,
            ],
        ]);
    }

    /** @param array<string,array<string,mixed>> $hooks */
    private function handler(array $hooks): HookWebhooks
    {
        return HookWebhooks::new($hooks, $this->run, function (): float {
            return $this->now;
        });
    }

    /** @param array<string,string> $headers */
    private function request(array $headers, string $body): HttpRequest
    {
        return new HttpRequest('POST', 'http://127.0.0.1/api/hooks/deploy', $headers, $body);
    }

    private function signature(string $body): string
    {
        return HookWebhooks::SIGNATURE_PREFIX . \hash_hmac(HookWebhooks::SIGNATURE_ALGORITHM, $body, self::SECRET);
    }
}

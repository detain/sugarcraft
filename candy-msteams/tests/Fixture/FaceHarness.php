<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\Fixture;

use CandyCode\Bundles\MsTeams\Bridge\ConversationRouter;
use CandyCode\Bundles\MsTeams\Candy\ServeBridge;
use CandyCode\Bundles\MsTeams\MsTeams\MsTeamsAccount;
use CandyCode\Bundles\MsTeams\MsTeams\Rest;
use CandyCode\Bundles\MsTeams\MsTeams\TokenBroker;
use CandyCode\Bundles\MsTeams\Support\CredentialStore;
use CandyCode\Bundles\MsTeams\Support\Log;
use CandyCode\Bundles\MsTeams\Support\PairingCodes;
use CandyCode\Bundles\MsTeams\Support\SessionMap;
use CandyCode\Bundles\MsTeams\Support\TextSafety;
use React\EventLoop\LoopInterface;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

/**
 * The account-face harness the feature suites share: a loopback Bot Framework
 * fake, a real Rest/TokenBroker pointed at it with the loopback opt-in, a
 * router over either a dead serve (the face's own REST leg is what gets
 * exercised) or a real FakeServe bridge. Mirrors the harness in
 * MsTeamsAccountTest; kept separate so the feature suites do not reach into
 * that class's private helpers.
 */
trait FaceHarness
{
    protected const HARNESS_APP_ID = 'app-guid-1';

    protected LoopInterface $loop;

    protected FakeTeamsApi $api;

    protected MsTeamsAccount $face;

    protected CredentialStore $store;

    protected PairingCodes $pairing;

    protected TextSafety $safety;

    protected string $tempDir;

    protected ?FakeServe $served = null;

    protected ?ServeBridge $bridge = null;

    protected function bootFixtures(): void
    {
        $this->loop = Loop::get();
        $this->tempDir = \sys_get_temp_dir() . '/p3-feature-' . \getmypid() . '-' . \uniqid('', true);
        \mkdir($this->tempDir, 0o700, true);
        $this->api = new FakeTeamsApi($this->loop);
        $this->api->listen();
        $this->store = new CredentialStore($this->tempDir . '/channels.json');
        $this->safety = new TextSafety();
        $this->pairing = new PairingCodes($this->store, $this->safety);
    }

    protected function tearDownFixtures(): void
    {
        if (isset($this->face)) {
            $this->face->cancelAllTimers();
        }
        $this->bridge?->stop();
        $this->served?->stop();
        $this->api->stop();
        foreach (\glob($this->tempDir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->tempDir);
    }

    /**
     * @param \Closure(): float|null $clock injected throttle clock; null = wall time
     */
    protected function buildFace(array $cfg, ?\Closure $clock = null, ?ServeBridge $serve = null): void
    {
        $rest = new Rest(new TokenBroker(
            ['appId' => self::HARNESS_APP_ID, 'appPassword' => 'p', 'tenantId' => 't'],
            Log::silent(),
            'http://127.0.0.1:' . $this->api->port,
        ), Log::silent(), true, $this->loop, $this->safety);
        $router = new ConversationRouter(
            $serve ?? new ServeBridge('ws://127.0.0.1:1/never', '', 'subprotocol', Log::silent(), $this->loop),
            new SessionMap($this->tempDir . '/sessions.db'),
            Log::silent(),
            $this->safety,
            $this->loop,
            ['queue' => ['mode' => 'steer']],
        );
        $this->face = new MsTeamsAccount('default', self::HARNESS_APP_ID, $rest, $router, $cfg, Log::silent(),
            $this->pairing, $this->safety, $this->loop, '', $clock);
        $router->addChannel($this->face, 'default');
    }

    /** Dial a real FakeServe and keep the bridge for the test's lifetime. */
    protected function startServe(): FakeServe
    {
        $this->served = new FakeServe($this->loop, 'tok-serve');
        $port = $this->served->listen();
        $this->bridge = new ServeBridge('ws://127.0.0.1:' . $port . '/', 'tok-serve', 'subprotocol', Log::silent(), $this->loop);
        $started = false;
        $this->bridge->start()->then(static function () use (&$started): void {
            $started = true;
        });
        for ($tick = 0; $tick < 20 && !$started; $tick++) {
            $this->settle(100);
        }
        if (!$started) {
            throw new \RuntimeException('FakeServe handshake never admitted');
        }

        return $this->served;
    }

    /** A face over the live FakeServe bridge, with u1 already paired. */
    protected function serveFace(array $cfg, ?\Closure $clock = null): void
    {
        $this->store->writeMsteamsPairing('default', [], ['u1']);
        $this->buildFace($cfg, $clock, $this->bridge);
    }

    protected function serviceUrl(): string
    {
        return 'http://127.0.0.1:' . $this->api->port;
    }

    /** An inbound activity shaped like a Bot Framework POST body. */
    protected function activity(string $fromId, string $text, string $conversationType = 'personal', array $entities = [], string $type = 'message', string $id = ''): array
    {
        return [
            'type' => $type,
            'id' => $id !== '' ? $id : 'act-' . \substr(\md5($fromId . $text . $conversationType . $type . \count($entities)), 0, 8),
            'serviceUrl' => $this->serviceUrl(),
            'from' => ['id' => $fromId, 'aadObjectId' => $fromId, 'name' => 'Peer ' . $fromId],
            'conversation' => ['id' => 'conv-1', 'conversationType' => $conversationType],
            'text' => $text,
            'entities' => $entities,
        ];
    }

    /** Run the loop for $ms milliseconds of real time. */
    protected function settle(int $ms = 300): void
    {
        $this->loop->addTimer($ms / 1000.0, function (): void {
            $this->loop->stop();
        });
        $this->loop->run();
    }

    /**
     * Spin the loop until the promise settles (bounded), returning its outcome.
     *
     * @return array{state: 'pending'|'fulfilled'|'rejected', value: mixed}
     */
    protected function awaitPromise(PromiseInterface $promise, int $ticks = 40): array
    {
        $outcome = ['state' => 'pending', 'value' => null];
        $promise->then(
            static function (mixed $value) use (&$outcome): void {
                $outcome = ['state' => 'fulfilled', 'value' => $value];
            },
            static function (\Throwable $e) use (&$outcome): void {
                $outcome = ['state' => 'rejected', 'value' => $e];
            },
        );
        for ($tick = 0; $tick < $ticks && $outcome['state'] === 'pending'; $tick++) {
            $this->settle(25);
        }

        return $outcome;
    }

    /** @return list<array{method: string, path: string, body: string, auth: string}> */
    protected function callsTo(string $method, string $pathNeedle): array
    {
        return \array_values(\array_filter(
            $this->api->calls,
            static fn (array $c): bool => $c['method'] === $method && \str_contains($c['path'], $pathNeedle),
        ));
    }

    /** The `text` of every activity body sent with the given method. */
    protected function sentTexts(string $method = 'POST'): array
    {
        $texts = [];
        foreach ($this->callsTo($method, '/activities') as $call) {
            if (\str_contains($call['body'], '"type":"typing"')) {
                continue;
            }
            $texts[] = (string) ((array) \json_decode($call['body'], true))['text'];
        }

        return $texts;
    }
}

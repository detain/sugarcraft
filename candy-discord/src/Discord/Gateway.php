<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Discord;

use CandyCode\Bundles\Discord\Support\Log;
use Ratchet\Client\Connector as WebSocketConnector;
use Ratchet\Client\WebSocket;
use React\EventLoop\LoopInterface;

/**
 * Discord Gateway v10 over WSS: hello → identify → heartbeat → dispatch,
 * with resume (§7.2). The `?v=10&encoding=json` pair is a CONTRACT — the
 * docs warn against trusting the READY `v`; a gateway that speaks another
 * major must not be decoded blind.
 *
 * Intents (https://discord.com/developers/docs/topics/gateway#gateway-intents):
 *  - GUILD_GUILDS (1<<0) and GUILD_MESSAGES (1<<9) so guild channels deliver;
 *  - DIRECT_MESSAGES (1<<12) for DMs;
 *  - MESSAGE_CONTENT (1<<11) is PRIVILEGED: without it the bot sees no
 *    message text (mentions only). The bridge documents the toggle rather
 *    than assuming the portal switch: on a 4013/4014 close it retries once
 *    WITHOUT content and says so — a degraded, mention-preferring bridge
 *    beats a dead one, and the degradation is loud in the log.
 *
 * The `seq` is carried into RESUME so a reconnect replays what dropped —
 * the same idea the serve side drives with its durable cursor.
 *
 * MUTABLE ON PURPOSE: one live gateway socket.
 */
final class Gateway
{
    public const OP_DISPATCH = 0;
    public const OP_HEARTBEAT = 1;
    public const OP_IDENTIFY = 2;
    public const OP_PRESENCE = 3;
    public const OP_RESUME = 6;
    public const OP_RECONNECT = 7;
    public const OP_INVALID_SESSION = 9;
    public const OP_HELLO = 10;
    public const OP_HEARTBEAT_ACK = 11;

    public const INTENT_GUILDS = 1 << 0;
    public const INTENT_GUILD_MESSAGES = 1 << 9;
    public const INTENT_DIRECT_MESSAGES = 1 << 12;
    public const INTENT_MESSAGE_CONTENT = 1 << 11;

    /** Closes that mean "the intents you asked for are not the intents you got". */
    private const CLOSE_DISALLOWED_INTENTS = [4013, 4014];
    private const CLOSE_FATAL = [4004, 4010];

    public const DEFAULT_URL = 'wss://gateway.discord.gg/?v=10&encoding=json';

    private ?WebSocket $socket = null;
    private ?string $sessionId = null;
    private ?int $seq = null;
    private ?\React\EventLoop\TimerInterface $heartbeatTimer = null;
    private int $epoch = 0;
    private bool $stopping = false;
    private bool $gaveUp = false;
    private int $attempts = 0;
    private bool $awaitingAck = false;
    private bool $contentIntent = true;

    /** @var list<\Closure(string, array<string,mixed>): void> */
    private array $listeners = [];

    /** @var list<\Closure(): void> */
    private array $readyListeners = [];

    public ?string $applicationId = null;

    public string $resumeUrl = self::DEFAULT_URL;

    public function __construct(
        private readonly string $token,
        private readonly Log $log,
        private readonly LoopInterface $loop,
        private readonly ?string $gatewayUrl = null,
        // §7.1: `intents.messageContent` false starts in the mention-only
        // degraded posture directly; the 4013/4014 ladder below is the
        // SAME degrade, just discovered by Discord instead of declared.
        bool $wantMessageContent = true,
    ) {
        $this->contentIntent = $wantMessageContent;
    }

    /** @param \Closure(string, array<string,mixed>): void $listener */
    public function onDispatch(\Closure $listener): void
    {
        $this->listeners[] = $listener;
    }

    /** @param \Closure(): void $listener */
    public function onReady(\Closure $listener): void
    {
        $this->readyListeners[] = $listener;
    }

    public function start(): void
    {
        $this->stopping = false;
        $this->gaveUp = false;
        $this->connect($this->gatewayUrl ?? self::DEFAULT_URL);
    }

    public function connected(): bool
    {
        return $this->socket !== null;
    }

    public function stopped(): bool
    {
        return $this->gaveUp;
    }

    public function messageContentEnabled(): bool
    {
        return $this->contentIntent;
    }

    public function stop(): void
    {
        $this->stopping = true;
        $socket = $this->socket;
        $this->socket = null;
        $this->sessionId = null;
        $this->stopHeartbeat();
        $socket?->close(1000);
    }

    private function connect(string $url): void
    {
        if ($this->stopping || $this->gaveUp) {
            return;
        }
        $epoch = ++$this->epoch;
        $connector = new WebSocketConnector($this->loop);
        $connector($url)->then(
            function (WebSocket $socket) use ($epoch, $url): void {
                if ($this->stopping || $epoch !== $this->epoch) {
                    $socket->close();

                    return;
                }
                $this->socket = $socket;
                $this->log->info('discord_ws_open', ['url' => $url]);
                $socket->on('message', function ($msg) use ($epoch): void {
                    if ($epoch !== $this->epoch) {
                        return;
                    }
                    $this->onFrame((string) $msg);
                });
                $socket->on('close', function (int $code = 0, string $reason = '') use ($epoch): void {
                    if ($epoch !== $this->epoch || $this->stopping) {
                        return;
                    }
                    $this->socket = null;
                    $this->stopHeartbeat();
                    $this->onSocketClosed($code, $reason);
                });
                $socket->on('error', function (\Throwable $e) use ($epoch): void {
                    if ($epoch === $this->epoch) {
                        $this->log->warn('discord_ws_error', ['error' => $e->getMessage()]);
                    }
                });
            },
            function (\Throwable $e) use ($epoch): void {
                if ($epoch !== $this->epoch || $this->stopping) {
                    return;
                }
                $this->scheduleReconnect('dial failed: ' . $e->getMessage());
            },
        );
    }

    /** The v10 envelope: {op, d, s, t}. */
    private function onFrame(string $raw): void
    {
        $frame = \json_decode($raw, true);
        if (!\is_array($frame) || !isset($frame['op'])) {
            return;
        }
        if (isset($frame['s']) && \is_int($frame['s'])) {
            $this->seq = $frame['s'];
        }
        $op = (int) $frame['op'];
        $d = $frame['d'] ?? null;

        switch ($op) {
            case self::OP_HELLO:
                $intervalMs = (int) ((\is_array($d) ? $d['heartbeat_interval_ms'] ?? 41250 : 41250));
                $this->startHeartbeat($intervalMs);
                if ($this->sessionId !== null && $this->seq !== null) {
                    $this->send(self::OP_RESUME, ['token' => $this->token, 'session_id' => $this->sessionId, 'seq' => $this->seq]);
                    $this->log->info('discord_resume_sent', ['session' => $this->sessionId, 'seq' => $this->seq]);
                } else {
                    $this->identify();
                }
                break;
            case self::OP_HEARTBEAT_ACK:
                $this->awaitingAck = false;
                break;
            case self::OP_HEARTBEAT:
                // Gateway asked for a beat NOW (op 7 variant).
                $this->beat();
                break;
            case self::OP_RECONNECT:
                $this->log->info('discord_reconnect_requested');
                $socket = $this->socket;
                $this->socket = null;
                $this->stopHeartbeat();
                $socket?->close(4000);
                $this->connect($this->resumeUrl);
                break;
            case self::OP_INVALID_SESSION:
                $resumable = (bool) ($d ?? false);
                $this->log->warn('discord_invalid_session', ['resumable' => $resumable ? 1 : 0]);
                if (!$resumable) {
                    $this->sessionId = null;
                    $this->seq = null;
                }
                $this->attempts++;
                $delay = \min(1.0 + $this->attempts * 1.0, 20.0);
                $this->loop->addTimer($delay, function (): void {
                    if ($this->stopping || $this->gaveUp) {
                        return;
                    }
                    $socket = $this->socket;
                    $this->socket = null;
                    $this->stopHeartbeat();
                    $socket?->close(4000);
                    $this->connect($this->gatewayUrl ?? self::DEFAULT_URL);
                });
                break;
            case self::OP_DISPATCH:
                $this->dispatch((string) ($frame['t'] ?? ''), \is_array($d) ? $d : []);
                break;
        }
    }

    /** @param array<string,mixed> $d */
    private function dispatch(string $type, array $d): void
    {
        if ($type === 'READY') {
            $this->sessionId = isset($d['session_id']) ? (string) $d['session_id'] : null;
            $this->applicationId = isset($d['application']['id']) ? (string) $d['application']['id'] : (string) ($d['user']['id'] ?? '');
            if (isset($d['resume_gateway_url']) && \is_string($d['resume_gateway_url']) && $d['resume_gateway_url'] !== '') {
                $this->resumeUrl = $d['resume_gateway_url'] . '?v=10&encoding=json';
            }
            $this->attempts = 0;
            $this->log->info('discord_ready', [
                'app' => $this->applicationId,
                'resumeUrl' => $this->resumeUrl === self::DEFAULT_URL ? 'default' : 'from READY',
                'contentIntent' => $this->contentIntent ? 1 : 0,
            ]);
            foreach ($this->readyListeners as $listener) {
                try {
                    $listener();
                } catch (\Throwable $e) {
                    $this->log->error('discord_ready_listener_failed', ['error' => $e->getMessage()]);
                }
            }
        }
        if ($type === 'RESUMED') {
            $this->attempts = 0;
            $this->log->info('discord_resumed', ['session' => $this->sessionId]);
            foreach ($this->readyListeners as $listener) {
                try {
                    $listener();
                } catch (\Throwable $e) {
                    $this->log->error('discord_ready_listener_failed', ['error' => $e->getMessage()]);
                }
            }
        }
        if ($type === 'INTERACTION_CREATE') {
            $this->log->debug('discord_interaction', ['kind' => (int) ($d['type'] ?? 0)]);
        }
        foreach ($this->listeners as $listener) {
            try {
                $listener($type, $d);
            } catch (\Throwable $e) {
                $this->log->error('discord_listener_failed', ['type' => $type, 'error' => $e->getMessage()]);
            }
        }
    }

    private function identify(): void
    {
        $intents = self::INTENT_GUILDS | self::INTENT_GUILD_MESSAGES | self::INTENT_DIRECT_MESSAGES
            | ($this->contentIntent ? self::INTENT_MESSAGE_CONTENT : 0);
        $this->send(self::OP_IDENTIFY, [
            'token' => $this->token,
            'intents' => $intents,
            'properties' => ['os' => \PHP_OS_FAMILY, 'browser' => 'candy-code-discord-bundle', 'device' => 'candy-code-discord-bundle'],
        ]);
        $this->log->info('discord_identify', ['intents' => $intents, 'content' => $this->contentIntent ? 1 : 0]);
    }

    /** @param array<string,mixed> $d */
    private function send(int $op, array $d): void
    {
        $this->socket?->send((string) \json_encode(['op' => $op, 'd' => $d], \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR));
    }

    private function startHeartbeat(int $intervalMs): void
    {
        $this->stopHeartbeat();
        $seconds = $intervalMs / 1000;
        // First beat jittered across the interval (the docs ask for it; a
        // fleet of bots beating at t=0 is a thundering herd).
        $first = $seconds * \mt_rand(0, 1000) / 1000;
        $timerRef = new \stdClass();
        $beat = function () use (&$beat, $seconds, $timerRef): void {
            if ($this->awaitingAck) {
                // Zombie detection: a missed ACK means the socket is a husk.
                $this->log->warn('discord_zombie_reset');
                $socket = $this->socket;
                $this->socket = null;
                $this->stopHeartbeat();
                $socket?->close(4000);
                $this->connect($this->resumeUrl);

                return;
            }
            $this->beat();
            $timerRef->handle = $this->loop->addTimer($seconds, $beat);
            $this->heartbeatTimer = $timerRef->handle;
        };
        $timerRef->handle = $this->loop->addTimer($first, $beat);
        $this->heartbeatTimer = $timerRef->handle;
    }

    private function beat(): void
    {
        $this->awaitingAck = true;
        $this->send(self::OP_HEARTBEAT, ['seq' => $this->seq]);
    }

    private function stopHeartbeat(): void
    {
        if ($this->heartbeatTimer !== null) {
            $this->loop->cancelTimer($this->heartbeatTimer);
            $this->heartbeatTimer = null;
        }
    }

    private function onSocketClosed(int $code, string $reason): void
    {
        if (\in_array($code, self::CLOSE_FATAL, true)) {
            $this->gaveUp = true;
            $this->log->error('discord_auth_fatal', ['code' => $code, 'reason' => $reason]);

            return;
        }
        if (\in_array($code, self::CLOSE_DISALLOWED_INTENTS, true) && $this->contentIntent) {
            $this->contentIntent = false;
            $this->log->warn('discord_intents_degraded', [
                'code' => $code,
                'note' => 'retried without MESSAGE_CONTENT; guild text arrives as mentions only',
            ]);
            $this->sessionId = null;
            $this->seq = null;
            $this->connect($this->gatewayUrl ?? self::DEFAULT_URL);

            return;
        }
        $this->scheduleReconnect("closed {$code} {$reason}");
    }

    private function scheduleReconnect(string $why): void
    {
        if ($this->stopping || $this->gaveUp) {
            return;
        }
        $this->attempts++;
        $delay = \min(0.5 * (2 ** \min(8, $this->attempts - 1)), 30.0);
        $this->log->info('discord_reconnect', ['attempt' => $this->attempts, 'delayMs' => (int) ($delay * 1000), 'why' => $why]);
        $this->loop->addTimer($delay, function (): void {
            if ($this->stopping || $this->gaveUp) {
                return;
            }
            // A resume goes to READY's resume_gateway_url; a fresh identify
            // re-dials the canonical endpoint.
            $this->connect($this->sessionId !== null ? $this->resumeUrl : ($this->gatewayUrl ?? self::DEFAULT_URL));
        });
    }
}

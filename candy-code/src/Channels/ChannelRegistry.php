<?php

declare(strict_types=1);

namespace SugarCraft\Code\Channels;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\LoopInterface;
use SugarCraft\Code\Bundles\Bundle;
use SugarCraft\Code\Host\SessionEvent;
use SugarCraft\Code\Server\ErrorLog;
use SugarCraft\Code\Server\Http\Responses;
use SugarCraft\Code\Support\Log;

/**
 * The register/start/dispose half of the plugin-channel lifecycle (code-chat
 * P1, spec §10.2) — the load half is {@see BundleChannelLoader}, and the
 * loader's own docblock states why collision refusal lives HERE instead.
 *
 * Keying is `bundle|channel-name` (the D6 granularity: a per-channel policy
 * layer added later addresses a channel by bundle and name), while the
 * routing index is the bare name, because ruling 2c requires names unique
 * ACROSS bundles: a plugin channel may never shadow a built-in or another
 * accepted bundle, and `/channels/<name>` must be unambiguous. The
 * {@see RESERVED_BUNDLES} namespace holds the door open for candy-code's own
 * future built-in channels — nothing a bundle installs may take those names.
 *
 * The registry carries NO rule state of its own (ruling D6, deferred
 * per-session tool policy): it holds instances and forwards events. Policy
 * decisions live inside channel instances, reachable through the
 * {@see ChannelContext} each gets at start, which already carries the
 * bundle/channel identity a per-session scope would extend. A later policy
 * layer therefore adds a door here without reshaping this class.
 *
 * Lifecycle law: boot() runs once per registry (a serve process has one
 * launch); dispose() is idempotent and makes the event seam and the HTTP
 * surface go dark, so a channel never hears traffic after teardown.
 */
final class ChannelRegistry
{
    /** The one URL namespace plugin webhook channels live under (never /api/). */
    public const HTTP_PREFIX = '/channels/';

    /**
     * Bundle names that may never register a channel: candy-code's own
     * built-in namespace, held by ruling 2c against plugin shadowing.
     */
    public const RESERVED_BUNDLES = ['candy-code', 'builtin'];

    /** @var array<string, ChannelInterface> started channels, keyed "bundle|name" */
    private array $channels = [];

    /** @var array<string, string> name => key, the routing/uniqueness index */
    private array $byName = [];

    /** @var list<SubscribesToSessionEvents> started subscribers, feed order */
    private array $subscribers = [];

    /** @var list<ChannelInterface> start order, for reverse-order dispose */
    private array $started = [];

    private bool $booted = false;

    private bool $disposed = false;

    private ?ChannelInboundLog $inboundLog = null;

    /**
     * A workspace SERVICE (registered in Bootstrap::workspace(), consumed by
     * ServeStartup and the ServerContext event seam — the WebhookDispatcher
     * pattern). Construction is inert: nothing is read until boot() runs.
     */
    public static function new(): self
    {
        return new self();
    }

    /**
     * Load trusted entrypoints, instantiate, register, and start.
     *
     * Returns the loader's buckets with the registration-time refusals
     * merged into `refusals`; the CALLER renders notices through Lang (the
     * loader's data-only law extends through here).
     *
     * @param list<Bundle>  $bundles
     * @param list<string>  $trustedNames
     *
     * @return array{loaded: list<array{bundle: string, path: string, class: string, kind: string}>, quarantined: list<array{bundle: string, path: string, class: string, kind: string}>, refusals: list<array{bundle: string, path?: string, class?: string, name?: string, reason: string}>}
     *
     * @throws \LogicException when called twice on one registry
     */
    public function boot(
        array $bundles,
        array $trustedNames,
        ChannelsStore $store,
        ?string $root,
        LoopInterface $loop,
        ?\Closure $log = null,
        ?ChannelInboundLog $inbound = null,
    ): array {
        if ($this->booted) {
            throw new \LogicException('ChannelRegistry::boot() runs once per launch.');
        }
        $this->booted = true;
        $this->inboundLog = $inbound;
        $log ??= static function (string $line): void {
        };

        $buckets = BundleChannelLoader::load($bundles, $trustedNames, $store, ['channel', 'subscriber']);

        foreach ($buckets['loaded'] as $entry) {
            $where = ['bundle' => $entry['bundle'], 'path' => $entry['path'], 'class' => $entry['class']];

            try {
                /** @var class-string $class */
                $class = $entry['class'];
                $instance = new $class();
            } catch (\Throwable $e) {
                $log(ErrorLog::line($e, 'channel ' . $entry['class']));
                $buckets['refusals'][] = $where + ['reason' => 'constructor-failed'];
                continue;
            }

            if (!$instance instanceof ChannelInterface) {
                // The manifest named a class that is not a channel; refusing
                // it is the registry's whole opinion of plugin constructors
                // that only "look like" channels.
                $buckets['refusals'][] = $where + ['reason' => 'not-a-channel'];
                continue;
            }

            if (!$this->register($entry['bundle'], $instance, $why)) {
                $buckets['refusals'][] = $where + ['name' => $instance->name(), 'reason' => $why];
                continue;
            }

            try {
                $instance->start(ChannelContext::for($entry['bundle'], $instance->name(), $root, $loop, $log, $inbound));
            } catch (\Throwable $e) {
                $log(ErrorLog::line($e, 'channel start ' . $entry['class']));
                $this->unregister($entry['bundle'], $instance->name());
                $buckets['refusals'][] = $where + ['name' => $instance->name(), 'reason' => 'start-failed'];
                continue;
            }

            $this->started[] = $instance;
            if ($instance instanceof SubscribesToSessionEvents) {
                $this->subscribers[] = $instance;
            }
        }

        $this->replayInbound($log);

        return $buckets;
    }

    /**
     * Spec §9.5 Track B restart replay: everything the ledger still holds as
     * pending is delivered again, in first-seen order, to started channels
     * that can take it. This is the REPLAY-vs-RESET law in action: an intact
     * ledger replays its exact pending set; a ledger that had to be
     * quarantined answers RESET, and we announce that loudly on the boot log
     * instead of silently skipping — a consumer that cannot trust the cursor
     * must know the hole is there (field fact: never cursor-skip on RESET).
     *
     * A row whose channel is not registered, or was started without the
     * capability, stays pending: delivering "nothing happened" would be the
     * silent loss the ledger exists to prevent. The attempt/age doors bound
     * any row that can never be acked, so a poison row can never hot-loop.
     */
    private function replayInbound(\Closure $log): void
    {
        if ($this->inboundLog === null) {
            return;
        }

        $state = $this->inboundLog->pending();
        if ($this->inboundLog->quarantinedAt() !== null) {
            $log('channel inbound log was unreadable and has been quarantined; '
                . 'this boot replays in the RESET shape — nothing before the '
                . 'quarantine point can be replayed, only re-delivered by the platform');
        }

        if ($state['records'] === []) {
            return;
        }

        $delivered = 0;
        $faulted = 0;

        foreach ($state['records'] as $row) {
            $channel = $this->channelNamed((string) $row['channel']);
            if (!$channel instanceof DispatchesInboundEvents) {
                continue;
            }

            try {
                if ($channel->dispatchInboundEvent($row['payload']) === true) {
                    $this->inboundLog->markAcked((string) $row['channel'], (string) $row['key']);
                    ++$delivered;
                    continue;
                }
                $this->inboundLog->noteFailure((string) $row['channel'], (string) $row['key']);
                ++$faulted;
            } catch (\Throwable $e) {
                $this->inboundLog->noteFailure((string) $row['channel'], (string) $row['key']);
                $log(ErrorLog::line($e, 'channel inbound replay ' . $row['channel']));
                ++$faulted;
            }
        }

        $log('channel inbound replay: ' . $delivered . ' acked, ' . $faulted
            . ' faulted, ' . (\count($state['records']) - $delivered - $faulted) . ' left pending');
    }

    /**
     * Accept one channel under $bundleName. A collision — reserved bundle,
     * unusable route slug, or a name another accepted channel already owns
     * — is REFUSED, never an override (ruling 2c). Returns false and names
     * the machine reason in &$reason when it refuses.
     */
    public function register(string $bundleName, ChannelInterface $channel, ?string &$reason = null): bool
    {
        $reason = null;
        $name = trim($channel->name());

        if (\in_array($bundleName, self::RESERVED_BUNDLES, true)) {
            $reason = 'reserved-namespace';

            return false;
        }

        if ($name === '' || \preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name) !== 1) {
            // The name is also the /channels/<name> route slug: a segment
            // this router could not address is a channel this registry
            // cannot accept.
            $reason = 'invalid-name';

            return false;
        }

        if (isset($this->byName[$name])) {
            $reason = 'collision';

            return false;
        }

        $key = $bundleName . '|' . $name;
        $this->channels[$key] = $channel;
        $this->byName[$name] = $key;

        return true;
    }

    /**
     * The `/channels/...` handler for the Router's optional channels lane.
     * Unknown names under the prefix answer 404 HERE rather than falling
     * through — the SPA would otherwise mask the route behind index.html
     * (the canvas/share branches' own stated reason; the prefix is disjoint
     * from both). Signature verification and rate limiting belong to the
     * channel that owns the route, per the HookWebhooks doctrine.
     */
    public function httpRouter(): \Closure
    {
        return function (ServerRequestInterface $request): ?ResponseInterface {
            if ($this->disposed) {
                return Responses::error(503, 'channels_stopped', 'the channel surface has shut down');
            }

            $path = $request->getUri()->getPath();
            $rest = \substr($path, \strlen(self::HTTP_PREFIX));
            $name = \explode('/', $rest, 2)[0] ?? '';

            $key = $this->byName[$name] ?? null;
            $channel = $key === null ? null : $this->channels[$key];

            if (!$channel instanceof AcceptsHttpRequests) {
                return Responses::error(404, 'channel_not_found', 'no webhook channel answers this route');
            }

            return $channel->handleRequest($request);
        };
    }

    /**
     * The GAP-3 event seam (spec §10.3 subscribers row): feed one served
     * session event, with its message text and deltas, to every started
     * subscriber. Called from the ServerContext feed closure — NOT from
     * `heard()`, which switches only on protocol-relevant types — mirroring
     * exactly how webhooksOut reads the same stream (C-AGT-5.f's precedent).
     *
     * The payload law is ENFORCED here: an envelope that does not survive a
     * JSON round-trip is refused at the door (logged), because delivering a
     * lossy array would hand a channel a lie about what the session said.
     * A fault inside onEvent() is logged and swallowed — the CWPD-9 law that
     * the feed's listener must outlive any one consumer.
     *
     * @throws never — this method cannot break the feed
     */
    public function handleEvent(string $sessionId, SessionEvent $event, ?\Closure $log = null): void
    {
        if ($this->disposed || $this->subscribers === []) {
            return;
        }

        $envelope = $event->toArray();
        // toArray() keeps `sessionId` present but nullable; the seam's
        // contract is a non-null identity, and field (i) of the serve
        // bridge notes is why consumers key on it.
        $envelope['sessionId'] = $sessionId;

        $json = \json_encode($envelope);
        if ($json === false || \json_decode($json, true) != $envelope) {
            $this->faultLog($log)('a channel event envelope failed the JSON round-trip law and was not delivered');

            return;
        }

        foreach ($this->subscribers as $subscriber) {
            try {
                $subscriber->onEvent($envelope);
            } catch (\Throwable $e) {
                $this->faultLog($log)(ErrorLog::line($e, 'channel subscriber ' . $subscriber->name()));
            }
        }
    }

    /**
     * Symmetric teardown: dispose started channels in reverse start order,
     * each shielded so one bad dispose() cannot strand the rest.
     * Idempotent; afterwards the HTTP surface answers 503 and the event
     * seam delivers nothing (disconnect safety).
     */
    public function disposeAll(?\Closure $log = null): void
    {
        if ($this->disposed) {
            return;
        }
        $this->disposed = true;

        foreach (\array_reverse($this->started) as $channel) {
            try {
                $channel->dispose();
            } catch (\Throwable $e) {
                $this->faultLog($log)(ErrorLog::line($e, 'channel dispose ' . $channel->name()));
            }
        }

        $this->started = [];
        $this->subscribers = [];
        $this->channels = [];
        $this->byName = [];
    }

    /** @return list<string> started channel names, start order (serve notice + tests) */
    public function startedNames(): array
    {
        return \array_map(static fn (ChannelInterface $c): string => $c->name(), $this->started);
    }

    public function channelNamed(string $name): ?ChannelInterface
    {
        $key = $this->byName[$name] ?? null;

        return $key === null ? null : ($this->channels[$key] ?? null);
    }

    /** The durable inbound ledger this launch boots with, if any (§9.5 Track B). */
    public function inboundLog(): ?ChannelInboundLog
    {
        return $this->inboundLog;
    }

    private function unregister(string $bundleName, string $name): void
    {
        unset($this->channels[$bundleName . '|' . $name], $this->byName[$name]);
    }

    /**
     * @param (\Closure(string): void)|null $log
     */
    private function faultLog(?\Closure $log): \Closure
    {
        return $log ?? static function (string $line): void {
            Log::channel(Log::APP, $line);
        };
    }
}

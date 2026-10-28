<?php

declare(strict_types=1);

namespace SugarCraft\Code\Automation;

use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Socket\Connector;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;

/**
 * The delivery leg of outbound webhooks (C-AGT-5.e): POST one signed event
 * body at every subscribed endpoint on the process's {@see Loop::get()},
 * retry the failures that deserve it, and never let any of that touch the
 * caller's stack.
 *
 * NEVER BLOCKS — THE WHOLE POINT. `dispatch()` writes nothing to a socket:
 * it renders the body once per endpoint, hands it to `Browser::request()`
 * and returns. A webhook waiting on a dead receiver inside `update()` is a
 * frozen TUI, and a frozen TUI for an unattended notification is a bug the
 * operator pays for twice. Same doctrine the OTLP leg learned in
 * C-SES-4.b, including its shape of honesty: like
 * {@see \SugarCraft\Code\Observability\AsyncOtlpTransport}, EVERY status
 * resolves to a Response (`withRejectErrorResponse(false)`) so the
 * accepted/refused decision lives in this class's boundary — `status < 300`
 * — and not in whichever reject default the injected browser carried.
 *
 * E646, spelled as a bound and not as a comment: this is a webhook, not an
 * LLM call, so a per-request network cap is REQUIRED here and legal under
 * the ban — {@see POST_TIMEOUT_SECONDS} rides `Browser::withTimeout()` (the
 * whole-POST bound react/http applies per request) and
 * {@see CONNECT_TIMEOUT_SECONDS} arms the `TimeoutConnector` inside
 * {@see fromConfig()}. Neither bound is a blanket total-turn timeout on a
 * model request, which is what the ban forbids; the retry ladder
 * ({@see BACKOFF_SECONDS}: 2 s, 8 s, 30 s, then give up) bounds how LONG a
 * delivery may still be trying, and it is checked between attempts, never
 * instead of one.
 *
 * REDIRECTS ARE NOT FOLLOWED, ever, whatever browser is injected — the
 * constructor applies `withFollowRedirects(false)` the way the status
 * boundary is applied: it belongs to this class's contract. Signature
 * headers are request headers; riding a 301 to a new host would hand a
 * valid signature for OUR endpoint to whoever that chain lands on. A 3xx
 * answer therefore reads as a refusal, and since a redirect re-redirects
 * forever it is a NON-retryable one.
 *
 * BOUNDED QUEUE, DROP-ON-OVERFLOW, ONE NOTICE PER EPISODE. At most
 * {@see DEFAULT_QUEUE_LIMIT} deliveries are in flight (an in-flight
 * delivery occupies its slot across its backoff wait — the retry is the
 * same delivery, not a new one). A dispatch that would exceed the bound
 * drops instead of growing memory, and the drop is announced ONCE per
 * saturation episode through {@see RuntimeNoticeSink::warn()} — the
 * transcript seam, never a raw write; the same one-per-episode discipline
 * the hook-matcher failure notice uses. Persistent failure of an endpoint
 * announces once per consecutive failure streak for the same reason: a
 * dead receiver must not wallpaper the transcript every 40 seconds forever.
 *
 * NOTHING HERE THROWS AT THE CALLER. A browser that explodes synchronously
 * (bad option, dead loop) settles as a failed delivery through the same
 * path as a rejection — an automation event is fire-and-forget by contract;
 * the operator learns of real trouble through notices, not through a crash
 * in the middle of a completed turn.
 */
final class WebhookDispatcher
{
    /** Whole-POST network cap; legal per E646 because a webhook is not an LLM call. */
    public const POST_TIMEOUT_SECONDS = 10.0;

    /** TCP connect bound, armed through react/socket's TimeoutConnector. */
    public const CONNECT_TIMEOUT_SECONDS = 2.0;

    /**
     * Wait before retry attempt N+1 (index = retries already spent). Three
     * retries per the design: 2 s, 8 s, 30 s, then the delivery is given up.
     *
     * @var list<float>
     */
    public const BACKOFF_SECONDS = [2.0, 8.0, 30.0];

    /** In-flight (including backoff-waiting) deliveries before a dispatch drops. */
    public const DEFAULT_QUEUE_LIMIT = 128;

    private Browser $browser;

    /** @var array<string,array{url:string,secret:string,events:list<string>}> */
    private readonly array $endpoints;

    /** @var list<float> */
    private readonly array $backoff;

    private readonly LoopInterface $loop;

    /** Slots currently occupied (in flight or waiting on a backoff timer). */
    private int $inFlight = 0;

    private bool $overflowAnnounced = false;

    /** @var array<string,bool> endpoints with an announced, still-broken streak */
    private array $failedAnnounced = [];

    /** Deliveries given up on (accepted, refused non-retryably, or exhausted). */
    private int $settledCount = 0;

    /**
     * @param array<string,array{url:string,secret:string,events:list<string>}> $endpoints parsed output of {@see OutboundWebhookConfig::parse()}
     * @param null|list<float> $backoffSeconds injectable ladder; {@see BACKOFF_SECONDS} by default (tests dial milliseconds)
     */
    public function __construct(
        Browser $browser,
        array $endpoints,
        ?array $backoffSeconds = null,
        private readonly int $queueLimit = self::DEFAULT_QUEUE_LIMIT,
        ?LoopInterface $loop = null,
    ) {
        $this->endpoints = $endpoints;
        $this->backoff = \array_values($backoffSeconds ?? self::BACKOFF_SECONDS);
        if ($this->backoff === []) {
            throw new \InvalidArgumentException('a webhook dispatcher with no backoff ladder is not a retry policy');
        }
        if ($this->queueLimit < 1) {
            throw new \InvalidArgumentException('the outbound webhook queue limit must be at least 1');
        }

        $this->loop = $loop ?? Loop::get();

        // The boundaries belong to THIS class, not to whatever assembly the
        // caller handed over: no redirect riding, and every status lands as
        // a Response so `< 300` is the only accept decision.
        $this->browser = $browser
            ->withFollowRedirects(false)
            ->withRejectErrorResponse(false)
            ->withTimeout(self::POST_TIMEOUT_SECONDS);
    }

    /**
     * The production assembly: a browser whose TCP connect carries
     * {@see CONNECT_TIMEOUT_SECONDS} (mirroring the OTLP leg's Connector
     * shape). The endpoint map is expected already parsed — parsing is
     * launch-time work ({@see OutboundWebhookConfig::parse()}), dialing is
     * per-event work, and this class never re-reads the user tier.
     */
    public static function fromConfig(array $endpoints, ?Browser $browser = null, ?LoopInterface $loop = null): self
    {
        $loop ??= Loop::get();
        $browser ??= new Browser(new Connector([
            'timeout' => self::CONNECT_TIMEOUT_SECONDS,
        ], $loop), $loop);

        return new self($browser, $endpoints, loop: $loop);
    }

    /**
     * Hand one event to every subscribed endpoint. Returns immediately —
     * before the loop has run a single byte.
     *
     * @return bool true when every subscribed endpoint got a slot; false
     *              when the queue was saturated and at least one copy was
     *              dropped (the drop is announced, once per episode)
     */
    public function dispatch(WebhookEvent $event): bool
    {
        $allAdmitted = true;
        foreach ($this->endpoints as $name => $endpoint) {
            if (!\in_array($event->type, $endpoint['events'], true)) {
                continue;
            }

            if ($this->inFlight >= $this->queueLimit) {
                $allAdmitted = false;
                $this->announceOverflow($name);

                continue;
            }

            ++$this->inFlight;
            $this->send($name, $endpoint, $event, 0);
        }

        return $allAdmitted;
    }

    /** Deliveries still unfinished (in flight or waiting a backoff timer). */
    public function pending(): int
    {
        return $this->inFlight;
    }

    /** Deliveries that reached any final outcome since construction. */
    public function settled(): int
    {
        return $this->settledCount;
    }

    private function send(string $name, array $endpoint, WebhookEvent $event, int $retriesSpent): void
    {
        $body = $event->json();
        $headers = ['Content-Type' => 'application/json'] + WebhookSigner::headers($body, $endpoint['secret']);

        try {
            $promise = $this->browser->request('POST', $endpoint['url'], $headers, $body);
        } catch (\Throwable) {
            // A browser that fails synchronously is a transport error like
            // any other — retried while the ladder says so, never raised.
            $this->afterAttempt($name, $endpoint, $event, $retriesSpent, retryable: true);

            return;
        }

        $promise->then(
            function (ResponseInterface $response) use ($name, $endpoint, $event, $retriesSpent): void {
                $status = $response->getStatusCode();
                // 3xx: refused redirect — failed, but a retry dials the same
                // chain, so re-dialling is pointless. 4xx: the receiver
                // understood us and said no; saying no again is its right.
                $this->afterAttempt($name, $endpoint, $event, $retriesSpent, retryable: $status >= 500, accepted: $status < 300);
            },
            function (\Throwable $transportError) use ($name, $endpoint, $event, $retriesSpent): void {
                // Connect refused, timeout, TLS failure — the ladder's reason to exist.
                $this->afterAttempt($name, $endpoint, $event, $retriesSpent, retryable: true);
            },
        );
    }

    private function afterAttempt(string $name, array $endpoint, WebhookEvent $event, int $retriesSpent, bool $retryable, bool $accepted = false): void
    {
        if ($retryable && $retriesSpent < \count($this->backoff)) {
            $delay = $this->backoff[$retriesSpent];
            // The slot stays occupied across the wait: a delivery retrying
            // is the same delivery, and queue bounds count deliveries.
            $this->loop->addTimer($delay, function () use ($name, $endpoint, $event, $retriesSpent): void {
                $this->send($name, $endpoint, $event, $retriesSpent + 1);
            });

            return;
        }

        --$this->inFlight;
        ++$this->settledCount;
        if ($this->inFlight < $this->queueLimit) {
            // The queue is no longer saturated: the next episode earns its
            // own notice, accepted outcome or not.
            $this->overflowAnnounced = false;
        }

        if ($accepted) {
            // The streak is over; the endpoint may announce again if it
            // breaks later.
            unset($this->failedAnnounced[$name]);

            return;
        }

        if (!isset($this->failedAnnounced[$name])) {
            $this->failedAnnounced[$name] = true;
            RuntimeNoticeSink::warn(\sprintf(
                'An outbound webhook delivery to "%s" (%s) failed after %d attempt%s and was dropped. Fix the endpoint or remove it from webhooksOut to stop this notice for this failure streak.',
                $name,
                $event->type,
                $retriesSpent + 1,
                $retriesSpent === 0 ? '' : 's',
            ));
        }
    }

    private function announceOverflow(string $name): void
    {
        if ($this->overflowAnnounced) {
            return;
        }

        $this->overflowAnnounced = true;
        RuntimeNoticeSink::warn(\sprintf(
            'The outbound webhook queue is full (%d deliveries in flight), so an event for "%s" was dropped. Later drops stay quiet until the queue drains; the receiver is behind — consider a faster endpoint or fewer events.',
            $this->queueLimit,
            $name,
        ));
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Observability;

/**
 * Ungrouped OTLP/HTTP exporter: one POST per render pass, each pass
 * carrying every span it closed in a single body.
 *
 * Production installs {@see BatchEventSink} on top of this class; using it
 * bare is the debugging shape (every turn hits the wire the moment it
 * settles). The transports are injected callables taking the encoded JSON
 * body — Bootstrap wires a bounded Guzzle POST plus a bounded
 * {@see AsyncOtlpTransport} leg, tests wire collectors.
 *
 * Like every sink, `consume()` never throws: a dead collector must be able
 * to silence nothing more than the telemetry itself. The batch layer's
 * retry works on the RENDERED bodies ({@see encodeAll()} /
 * {@see encodeDrained()} are transport-free), because encoding a span
 * closes it — replaying events against the encoder could never reproduce
 * a body a failed POST already lost.
 *
 * C-SES-4.b adds the second, OPTIONAL leg: an async transport
 * ({@see AsyncOtlpTransport} over `React\Http\Browser`) the batch layer
 * posts through while the event loop runs, so a batch export leaves the TUI's
 * `update()` path entirely. {@see fromConfig()} builds both legs from one
 * config; {@see post()} — the synchronous Guzzle leg — stays the shutdown
 * and no-loop shape, and the batch layer routes to it whenever the async leg
 * is absent or the sink is closing.
 */
final class OtelSink implements EventSink
{
    /**
     * @param \Closure(string): bool $transport takes one encoded body,
     *                                          returns whether the endpoint accepted it
     * @param ?\Closure(string): \React\Promise\PromiseInterface $asyncTransport
     *                                          takes one encoded body, returns a promise
     *                                          fulfilling bool accepted (C-SES-4.b)
     */
    private function __construct(
        private readonly \Closure $transport,
        private readonly OtlpEncoder $encoder,
        private readonly ?\Closure $asyncTransport = null,
    ) {
    }

    /**
     * @param \Closure(string): bool $transport
     */
    public static function withTransport(callable $transport, ?OtlpEncoder $encoder = null): self
    {
        return new self(\Closure::fromCallable($transport), $encoder ?? new OtlpEncoder());
    }

    /**
     * Both legs at once — the shape {@see fromConfig()} builds and the tests
     * dial against loopback collectors.
     *
     * @param \Closure(string): bool $transport
     * @param ?\Closure(string): \React\Promise\PromiseInterface $asyncTransport
     */
    public static function withTransports(
        callable $transport,
        ?callable $asyncTransport,
        ?OtlpEncoder $encoder = null,
    ): self {
        return new self(
            \Closure::fromCallable($transport),
            $encoder ?? new OtlpEncoder(),
            null === $asyncTransport ? null : \Closure::fromCallable($asyncTransport),
        );
    }

    public static function fromConfig(OtelConfig $config, \GuzzleHttp\ClientInterface $client): self
    {
        $endpoint = $config->endpoint;
        \assert(null !== $endpoint);

        // The async leg is built ONCE: its Browser holds the loop-bound
        // connector, so per-body construction would discard connection
        // reuse for no reason.
        $async = AsyncOtlpTransport::fromConfig($config);

        return new self(
            static function (string $body) use ($client, $endpoint, $config): bool {
                try {
                    $response = $client->post(rtrim($endpoint, '/') . '/v1/traces', [
                        'headers' => ['Content-Type' => 'application/json'] + $config->headers,
                        'body' => $body,
                        'timeout' => $config->exportTimeoutSeconds,
                        'connect_timeout' => \min(1.0, $config->exportTimeoutSeconds),
                    ]);

                    return $response->getStatusCode() < 300;
                } catch (\Throwable) {
                    return false;
                }
            },
            new OtlpEncoder(),
            static fn (string $body) => $async->post($body),
        );
    }

    public function consume(object $event): void
    {
        try {
            foreach ($this->encodeAll([[$event, null]]) as $body) {
                $this->post($body);
            }
        } catch (\Throwable) {
            // Telemetry never breaks the program it observes.
        }
    }

    public function flush(): void
    {
        try {
            $body = $this->encodeDrained();
            if (null !== $body) {
                $this->post($body);
            }
        } catch (\Throwable) {
            // Same law as consume().
        }
    }

    /**
     * Pure encode arm: renders every span the batch closes, touching no
     * transport. Order-preserving, and since OBS-1-P2 the whole batch's
     * closed spans travel together in ONE body, each carrying the
     * consume-time instant its events were stamped with (C-SES-4.a) — a
     * bare {@see consume()} hands one pair with a null stamp, where
     * observe and encode coincide anyway.
     *
     * @param list<array{object, ?int}> $stampedEvents event + observed unix nanos
     *
     * @return list<string> JSON bodies — at most one
     */
    public function encodeAll(array $stampedEvents): array
    {
        $spans = $this->encoder->encodeMany($stampedEvents);
        if ([] === $spans) {
            return [];
        }

        return [json_encode($this->encoder->exportBody($spans), \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION)];
    }

    /** Renders every still-open span (shutdown arm) without posting it. */
    public function encodeDrained(): ?string
    {
        $body = $this->encoder->drainOpen();

        return null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * One bounded POST. Throws when the endpoint refuses the body so the
     * batch layer can carry it; direct users swallow via consume/flush.
     */
    public function post(string $jsonBody): void
    {
        if (!($this->transport)($jsonBody)) {
            throw new \RuntimeException('OTLP export not accepted by the endpoint');
        }
    }

    /** Whether the async leg (C-SES-4.b) was built — the batch layer's switch. */
    public function hasAsyncTransport(): bool
    {
        return null !== $this->asyncTransport;
    }

    /**
     * Hand one encoded body to the async leg without waiting for it. The
     * returned promise fulfils with bool accepted and rejects on transport
     * errors; either way the caller never blocks — this is the arm that
     * takes the POST off the TUI's `update()` path. A transport that throws
     * SYNCHRONOUSLY (a malformed URL rejects only on connect, but a closure
     * seam can) is folded into a rejected promise so the one contract holds.
     *
     * @return \React\Promise\PromiseInterface<bool>
     */
    public function postAsync(string $jsonBody): \React\Promise\PromiseInterface
    {
        $transport = $this->asyncTransport;
        if (null === $transport) {
            return \React\Promise\reject(new \RuntimeException('OTLP async transport not built'));
        }

        try {
            return $transport($jsonBody);
        } catch (\Throwable $error) {
            return \React\Promise\reject($error);
        }
    }
}

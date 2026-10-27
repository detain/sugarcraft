<?php

declare(strict_types=1);

namespace SugarCraft\Code\Observability;

use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Promise\PromiseInterface;
use React\Socket\Connector;

/**
 * The non-blocking leg of the OTLP/HTTP export (C-SES-4.b): one
 * `React\Http\Browser` POST per rendered body on the process's
 * {@see Loop::get()}, so `BatchEventSink::send()` hands the body over and
 * returns — the export no longer runs inside the TUI's `update()` path.
 *
 * E646 does not apply to this leg and the class says so in its bounds rather
 * than by comment alone: OTLP export is telemetry, not an LLM call, so BOTH
 * a connect timeout and a whole-request timeout are the shape here —
 * `OTEL_EXPORTER_OTLP_ENDPOINT`'s `CANDY_CODE_OTEL_TIMEOUT` bound rides the
 * request, and the connect bound mirrors the Guzzle leg exactly
 * (`min(1.0, timeout)`), so one knob stays honest for the whole sink.
 *
 * Outcome contract: the promise FULFILS with `true` when the endpoint
 * accepted the body (status < 300, the same boundary the Guzzle leg
 * answers with), `false` for any refusal status, and REJECTS with the
 * transport error (connect refused, timeout) — callers carry the body on
 * every non-`true` outcome and never need to tell refusal classes apart.
 *
 * React\Http\Browser resolves the default loop once at construction; every
 * sugar-async arm in the process shares that loop, so a request armed during
 * a turn is written and answered by the same pump that drives the UI.
 */
final class AsyncOtlpTransport
{
    /**
     * @param array<string, string> $headers
     */
    private Browser $browser;

    public function __construct(
        Browser $browser,
        private readonly string $url,
        private readonly array $headers = [],
    ) {
        // The status boundary belongs to THIS class's outcome contract, not
        // to whatever browser the caller assembled: every HTTP status must
        // resolve to a Response so post()'s `status < 300` mapping — not the
        // react default rejection — is what decides accepted vs refused.
        $this->browser = $browser->withRejectErrorResponse(false);
    }

    /**
     * Build the production transport from the environment-read config. The
     * browser is injectable so tests can dial their own loopback collector;
     * the default carries the connect bound through react/socket's
     * `Connector` context (`timeout` arms its `TimeoutConnector` around the
     * TCP connect) and the request bound through `Browser::withTimeout()`.
     */
    public static function fromConfig(OtelConfig $config, ?Browser $browser = null): self
    {
        $endpoint = $config->endpoint;
        \assert(null !== $endpoint);

        $browser ??= new Browser(new Connector([
            'timeout' => \min(1.0, $config->exportTimeoutSeconds),
        ], Loop::get()));

        return new self(
            $browser->withTimeout($config->exportTimeoutSeconds),
            \rtrim($endpoint, '/') . '/v1/traces',
            ['Content-Type' => 'application/json'] + $config->headers,
        );
    }

    /**
     * Fire one encoded body at the endpoint. Returns immediately; the
     * promise settles when the response (or the timeout) arrives.
     *
     * @return PromiseInterface<bool> fulfils true/false per the outcome
     *                               contract above; rejects on transport errors
     */
    public function post(string $body): PromiseInterface
    {
        return $this->browser
            ->request('POST', $this->url, $this->headers, $body)
            ->then(static fn (ResponseInterface $response): bool => $response->getStatusCode() < 300);
    }
}

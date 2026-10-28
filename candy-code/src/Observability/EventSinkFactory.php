<?php

declare(strict_types=1);

namespace SugarCraft\Code\Observability;

/**
 * Workspace service that hands out one exporting {@see EventSink} per
 * session — the serve path's answer to a problem the TUI never has.
 *
 * The trap: {@see OtlpEncoder} keys open turn spans by the event's step
 * number, and a serve process hosts many sessions at once whose step
 * numbers march over identical values. One shared encoder would splice
 * session A's usage onto session B's open span. The fix is not a key
 * change (the encoder stays exactly as the single-session chat path
 * needs it) but an encoder PER SESSION, tagged with a `session.id`
 * resource attribute so an OTLP collector can tell the traces apart.
 *
 * All sessions still share BOTH transports — one Guzzle leg and one
 * async leg built once in {@see fromConfig()}, because the async
 * leg's Browser holds a loop-bound connector that must not be
 * re-minted per sink. Sinks are cheap; the wire identity is not.
 *
 * The factory registers exactly ONE shutdown flush for all the live
 * sinks it has handed out, PID-guarded the same way
 * {@see BatchEventSink::flush()} guards itself: a forked child
 * inherits the registration but must never flush its parent's sinks.
 *
 * Dark discipline mirrors {@see OtelConfig::buildSink()}: a dark
 * factory answers {@see NullEventSink} for every session, runs no
 * allocation, and never registers anything at shutdown.
 */
final class EventSinkFactory
{
    /**
     * Sinks handed out and not yet collected. Weak so a session that
     * releases its host (and drops its sink reference) stops being
     * flushed at shutdown instead of pinning memory forever.
     *
     * @var list<\WeakReference<EventSink>>
     */
    private array $live = [];

    private bool $shutdownArmed = false;

    private readonly int $ownerPid;

    /**
     * @param ?\Closure(string): bool $syncTransport
     * @param ?\Closure(string): \React\Promise\PromiseInterface $asyncTransport
     */
    private function __construct(
        private readonly ?OtelConfig $config,
        private readonly ?\Closure $syncTransport,
        private readonly ?\Closure $asyncTransport,
    ) {
        $this->ownerPid = \getmypid();
    }

    /** The unconfigured answer: every session gets the null sink. */
    public static function dark(): self
    {
        return new self(null, null, null);
    }

    /**
     * Lit factory over caller-supplied transport legs. Production uses
     * {@see fromConfig()}; tests dial the wire with closures here so no
     * Guzzle double has to imitate the OTLP status contract.
     */
    public static function forExport(
        OtelConfig $config,
        callable $syncTransport,
        ?callable $asyncTransport = null,
    ): self {
        return new self($config, \Closure::fromCallable($syncTransport), null === $asyncTransport ? null : \Closure::fromCallable($asyncTransport));
    }

    /**
     * Production shape: build the one template sink (sole copy of the
     * wire shape, shared by every session) and lift both of its legs
     * into closures the per-session sinks mount with their own encoder.
     */
    public static function fromConfig(OtelConfig $config, \GuzzleHttp\ClientInterface $client): self
    {
        $template = OtelSink::fromConfig($config, $client);

        $sync = static function (string $body) use ($template): bool {
            try {
                $template->post($body);

                return true;
            } catch (\Throwable) {
                return false;
            }
        };

        $async = $template->hasAsyncTransport()
            ? static fn (string $body): \React\Promise\PromiseInterface => $template->postAsync($body)
            : null;

        return new self($config, $sync, $async);
    }

    /**
     * One sink for one session. Deliberately NOT memoised by session
     * id: {@see \SugarCraft\Code\Host\SessionHub::close()} flushes and
     * drops the host, and a later open of the same id must get a live
     * (not closed-and-hence-deaf) sink. The caller — a SessionHost —
     * caches the answer for its own lifetime, which is the granularity
     * the encoder's open-span state actually wants.
     */
    public function forSession(string $sessionId): EventSink
    {
        $config = $this->config;
        $sync = $this->syncTransport;
        if (null === $config || null === $sync) {
            return new NullEventSink();
        }

        $sink = new BatchEventSink(
            OtelSink::withTransports(
                $sync,
                $this->asyncTransport,
                // The whole point: step numbers are only unique WITHIN a
                // session, so the span keying is only sound per session too.
                new OtlpEncoder(['session.id' => $sessionId]),
            ),
            $config->batchSize,
        );

        $this->live[] = \WeakReference::create($sink);
        if (!$this->shutdownArmed) {
            $this->shutdownArmed = true;
            \register_shutdown_function($this->flushLiveSinks(...));
        }

        return $sink;
    }

    /**
     * Export everything every still-live sink holds, once, at process
     * shutdown — the serve loop never blocks on a POST, so the tail of
     * a turn that never reached a batch boundary leaves here.
     */
    private function flushLiveSinks(): void
    {
        if (\getmypid() !== $this->ownerPid) {
            return;
        }

        // Prune dead entries so a long-lived serve process's shutdown
        // list costs one pass, not one per session ever served.
        $this->live = \array_values(\array_filter(
            $this->live,
            static fn (\WeakReference $ref): bool => null !== $ref->get(),
        ));

        foreach ($this->live as $ref) {
            $sink = $ref->get();
            if ($sink instanceof EventSink) {
                $sink->flush();
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Observability;

/**
 * Environment-only configuration for the OTLP event sink.
 *
 * There is deliberately NO settings-file tier here: an observability
 * endpoint is infrastructure plumbing (what `OTEL_*` means in every other
 * instrumented process), and it must be settable for a launch without ever
 * touching the user's or a repository's config — the same reasoning that
 * keeps the agent-worktree roots out of project settings.
 *
 * The two recognised names:
 * - `OTEL_EXPORTER_OTLP_ENDPOINT` (OTel-conventional; presence switches
 *   exporting on) and
 * - the `CANDY_CODE_OTEL_*` family below for the candy-code-specific knobs.
 *
 * Every value is read whole via `getenv()` — no assembled fragments, so the
 * names stay visible to the environment-read scanner and the roster guard.
 */
final class OtelConfig
{
    /** OTel-conventional exporter endpoint. A non-empty value switches exporting on. */
    public const ENDPOINT_ENV = 'OTEL_EXPORTER_OTLP_ENDPOINT';

    /** Comma-separated header pairs `key=value` (also honoured by the OTel spec under this name). */
    public const HEADERS_ENV = 'OTEL_EXPORTER_OTLP_HEADERS';

    /** Bounded seconds allowed to a single export POST. Default {@see DEFAULT_EXPORT_TIMEOUT_SECONDS}. */
    public const CANDY_CODE_OTEL_TIMEOUT_ENV = 'CANDY_CODE_OTEL_TIMEOUT';

    /** Spans buffered before a batch is exported. Default {@see DEFAULT_BATCH_SIZE}. */
    public const CANDY_CODE_OTEL_BATCH_ENV = 'CANDY_CODE_OTEL_BATCH_SIZE';

    public const DEFAULT_EXPORT_TIMEOUT_SECONDS = 3.0;
    public const DEFAULT_BATCH_SIZE = 64;

    /** Floor for the batch size: one event per POST is a legitimate (if noisy) choice. */
    public const MIN_BATCH_SIZE = 1;
    /** Ceiling for the batch size: guards the request body against an absurd setting. */
    public const MAX_BATCH_SIZE = 2048;

    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        public readonly ?string $endpoint,
        public readonly array $headers,
        public readonly float $exportTimeoutSeconds,
        public readonly int $batchSize,
    ) {
    }

    /**
     * Reads the environment. When no (usable) endpoint is configured the
     * result is exactly {@see dark()} — the zero-cost dark state.
     */
    public static function fromEnvironment(): self
    {
        $endpoint = self::readNonEmpty(self::ENDPOINT_ENV);
        if (null === $endpoint) {
            return self::dark();
        }

        return new self(
            $endpoint,
            self::readHeaders(self::readNonEmpty(self::HEADERS_ENV)),
            self::readPositiveFloat(self::CANDY_CODE_OTEL_TIMEOUT_ENV, self::DEFAULT_EXPORT_TIMEOUT_SECONDS),
            self::readBoundedInt(self::CANDY_CODE_OTEL_BATCH_ENV, self::DEFAULT_BATCH_SIZE, self::MIN_BATCH_SIZE, self::MAX_BATCH_SIZE),
        );
    }

    /** The dark configuration: no endpoint, every default. */
    public static function dark(): self
    {
        return new self(
            null,
            [],
            self::DEFAULT_EXPORT_TIMEOUT_SECONDS,
            self::DEFAULT_BATCH_SIZE,
        );
    }

    /**
     * Environment-reading shim for {@see buildSink()}: read the environment,
     * hand the config to the single gate. Production (Bootstrap's sink seam)
     * and tests reach the decision through exactly this path, so there is
     * only ever one copy of the dark-mode branch to drift.
     *
     * @param callable(self): ?EventSink $exporterFactory
     */
    public static function sinkFromEnvironment(callable $exporterFactory): EventSink
    {
        return self::fromEnvironment()->buildSink($exporterFactory);
    }

    /**
     * Whether this configuration should export events at all. A public
     * method rather than a private property read so a future settings tier
     * (or a test) gets one honest question to ask.
     */
    public function exportsEvents(): bool
    {
        return null !== $this->endpoint;
    }

    /**
     * THE gate: a dark config answers {@see NullEventSink} without the
     * exporter factory ever running, and a factory that declines (null)
     * still lands on the null sink. One branch, one truth — callers supply
     * the factory, never a second dark check.
     *
     * @param callable(self): ?EventSink $exporterFactory
     */
    public function buildSink(callable $exporterFactory): EventSink
    {
        if (!$this->exportsEvents()) {
            return new NullEventSink();
        }

        return $exporterFactory($this) ?? new NullEventSink();
    }

    /**
     * THE gate for the serve path's per-session factory, mirroring
     * {@see buildSink()} one-for-one: a dark config answers
     * {@see EventSinkFactory::dark()} without the factory builder ever
     * running (so no HTTP client is constructed and no shutdown hook is
     * registered), and a builder that declines still lands on the dark
     * factory. Callers ask this method, never a second endpoint read.
     *
     * @param callable(self): ?EventSinkFactory $factoryFactory
     */
    public function buildFactory(callable $factoryFactory): EventSinkFactory
    {
        if (!$this->exportsEvents()) {
            return EventSinkFactory::dark();
        }

        return $factoryFactory($this) ?? EventSinkFactory::dark();
    }

    /**
     * Environment-reading shim for {@see buildFactory()}: the one call
     * shape Bootstrap's workspace arm uses, so the factory path shares
     * the sink path's single copy of the dark branch.
     */
    public static function sinkFactoryFromEnvironment(): EventSinkFactory
    {
        return self::fromEnvironment()->buildFactory(
            static fn (self $config): EventSinkFactory => EventSinkFactory::fromConfig($config, new \GuzzleHttp\Client()),
        );
    }

    private static function readNonEmpty(string $name): ?string
    {
        $value = getenv($name);
        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    /**
     * Parses `key=value,key2=value2`. A pair without `=`, or with an empty
     * key, is dropped rather than poisoning the rest of the set.
     *
     * @return array<string, string>
     */
    private static function readHeaders(?string $raw): array
    {
        if (null === $raw) {
            return [];
        }

        $headers = [];
        foreach (explode(',', $raw) as $pair) {
            $pair = trim($pair);
            if ('' === $pair || !str_contains($pair, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $pair, 2);
            $key = trim($key);
            if ('' === $key) {
                continue;
            }

            $headers[$key] = trim($value);
        }

        return $headers;
    }

    private static function readPositiveFloat(string $name, float $default): float
    {
        $raw = self::readNonEmpty($name);
        if (null === $raw) {
            return $default;
        }

        if (!\is_numeric($raw)) {
            return $default;
        }

        $value = (float) $raw;

        return $value > 0.0 ? $value : $default;
    }

    private static function readBoundedInt(string $name, int $default, int $min, int $max): int
    {
        $raw = self::readNonEmpty($name);
        if (null === $raw || !\is_numeric($raw) || (string) (int) $raw !== $raw) {
            return $default;
        }

        return max($min, min($max, (int) $raw));
    }
}

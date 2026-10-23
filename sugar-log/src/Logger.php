<?php

declare(strict_types=1);

namespace SugarCraft\Log;

use SugarCraft\Log\Lang;
use SugarCraft\Log\Formatter\TextFormatter;
use SugarCraft\Log\PartsOrder;
use SugarCraft\ColorProfile\Probe;
use SugarCraft\Style\Style;

/**
 * Minimal colorful leveled logger.
 * Mirrors charmbracelet/log's Logger API.
 *
 * Output deviation: unlike charmbracelet/log (which writes everything to
 * os.Stderr), this logger writes EVERY level to its main stream — STDOUT by
 * default — because existing consumers treat that as the contract. Route
 * severe records elsewhere with the `errorStream` knob: levels at or above
 * `errorStreamAt` (default {@see Level::Error}) go to the error stream,
 * everything else to the main stream.
 *
 * Hooks: {@see Hook\HookRegistry} callbacks fire only when records pass
 * through a {@see PsrBridge}; the core Logger::log() path does not dispatch
 * hooks (deliberate — the emit path stays synchronous and allocation-free).
 */
final class Logger
{
    public const DEFAULT_FORMAT = 'Y/m/d H:i:s';

    private Formatter $formatter;
    private Level $minLevel;
    private bool $reportTimestamp;
    private ?string $timeFormat;
    private bool $reportCaller;
    private ?string $prefix;
    /** @var array<string,mixed> */
    private array $fields;
    /** @var resource */
    private mixed $stream;
    /** @var resource|null Secondary stream for records at/above $errorStreamAt. */
    private mixed $errorStream = null;
    /** Threshold for routing a record to $errorStream (null = never route). */
    private ?Level $errorStreamAt = null;

    /** Styles for text-formatter level coloring. */
    private Styles $styles;

    /** Whether colors are enabled based on terminal capabilities. */
    private bool $useColors;

    /** Parts order for text formatter. */
    private ?PartsOrder $partsOrder;

    /**
     * @param Formatter|null $formatter Output formatter (defaults to TextFormatter).
     * @param Level|null     $level     Minimum level to emit (defaults to Info).
     * @param string|null    $prefix    Prepended string on every line.
     * @param bool           $reportTimestamp Show a timestamp (main stream).
     * @param string|null    $timeFormat  PHP date format string (default: Y/m/d H:i:s).
     * @param bool           $reportCaller Show file:line of log call site.
     * @param resource|null  $stream    Main output stream (default STDOUT). Must be a
     *                                   stream resource; anything else throws.
     * @param resource|null  $errorStream Optional secondary stream: records at or
     *                                   above $errorStreamAt are written here instead
     *                                   of $stream (e.g. STDERR for errors+fatals).
     * @param Level|null     $errorStreamAt Routing threshold; defaults to
     *                                   {@see Level::Error} when $errorStream is given.
     *
     * The $reportTimestamp/$timeFormat/$reportCaller flags shape the library's
     * own TextFormatter (the default, and any formatter a later setReport*()
     * rebuild produces). A custom $formatter ignores them — pass your own
     * flags to JsonFormatter/LogfmtFormatter/etc.
     *
     * @throws \InvalidArgumentException If $stream or $errorStream is not a resource.
     */
    public function __construct(
        ?Formatter $formatter = null,
        ?Level $level = null,
        ?string $prefix = null,
        bool $reportTimestamp = true,
        ?string $timeFormat = null,
        bool $reportCaller = false,
        mixed $stream = null,
        ?PartsOrder $partsOrder = null,
        mixed $errorStream = null,
        ?Level $errorStreamAt = null,
    ) {
        // Probe-driven color decision: disable colors when terminal cannot render them
        $useColors = Probe::colorProfile()->allowsColor();

        $this->styles = Styles::default();
        $this->useColors = $useColors;
        $this->partsOrder = $partsOrder ?? PartsOrder::default();

        $this->formatter = $formatter ?? new TextFormatter(
            $reportTimestamp,
            $timeFormat,
            $reportCaller,
            $useColors,
            $this->styles,
            $this->partsOrder,
        );
        $this->minLevel = $level ?? Level::Info;
        $this->prefix = $prefix;
        $this->reportTimestamp = $reportTimestamp;
        $this->timeFormat = $timeFormat;
        $this->reportCaller = $reportCaller;
        $this->fields = [];
        $this->stream = self::requireStream($stream ?? \STDOUT);
        $this->errorStream = $errorStream === null ? null : self::requireStream($errorStream);
        $this->errorStreamAt = $this->errorStream === null ? null : ($errorStreamAt ?? Level::Error);
    }

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    /** Create a new logger with options object. */
    public static function new(
        ?Formatter $formatter = null,
        ?Level $level = null,
        ?string $prefix = null,
        bool $reportTimestamp = true,
        ?string $timeFormat = null,
        bool $reportCaller = false,
        mixed $stream = null,
        ?PartsOrder $partsOrder = null,
        mixed $errorStream = null,
        ?Level $errorStreamAt = null,
    ): self {
        return new self($formatter, $level, $prefix, $reportTimestamp, $timeFormat, $reportCaller, $stream, $partsOrder, $errorStream, $errorStreamAt);
    }

    // -------------------------------------------------------------------------
    // Instance methods
    //
    // The static / global-default facade lives on the sibling {@see Log} class
    // (`Log::debug(...)`, `Log::setLogger(...)`). PHP doesn't allow static and
    // instance methods to share a name on a single class, so the convenience
    // surface is split across two classes.
    // -------------------------------------------------------------------------

    public function log(Level $level, string $message, array $context = []): void
    {
        $this->emit($level, $message, $context, false);
    }

    /**
     * Emit a line at Info level, bypassing the minimum-level filter.
     *
     * The formatted output still carries the Info level label — "bypasses
     * the filter", not "prints bare".
     */
    public function print(string $message, array $context = []): void
    {
        $this->emit(Level::Info, $message, $context, true);
    }

    /**
     * Internal emit path — optionally bypasses the level filter.
     *
     * @param Level $level         Log level.
     * @param string $message      Message to emit.
     * @param array  $context       Context key-value pairs.
     * @param bool   $bypassFilter  If true, ignore minLevel and always emit.
     */
    private function emit(Level $level, string $message, array $context, bool $bypassFilter): void
    {
        if (!$bypassFilter && $level->value < $this->minLevel->value) {
            return;
        }

        // Merge child fields with call-site context
        $merged = \array_merge($this->fields, $context);

        $caller = $this->reportCaller ? CallerFormatter::find() : null;
        $time = new \DateTimeImmutable();
        $line = $this->formatter->format($level, $message, $merged, $time, $caller, $this->prefix);

        $target = $this->stream;
        if ($this->errorStream !== null && $level->value >= $this->errorStreamAt?->value) {
            $target = $this->errorStream;
        }
        \fwrite($target, $line);

        if ($level === Level::Fatal) {
            throw new \RuntimeException(Lang::t('logger.fatal', ['message' => $message]));
        }
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log(Level::Debug, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(Level::Info, $message, $context);
    }

    public function warn(string $message, array $context = []): void
    {
        $this->log(Level::Warn, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(Level::Error, $message, $context);
    }

    public function fatal(string $message, array $context = []): void
    {
        $this->log(Level::Fatal, $message, $context);
    }

    /**
     * Whether a message at $level would be emitted under the current minLevel.
     *
     * Lets callers (and the sprintf-formatting `*f` helpers below) skip
     * building an expensive message that would only be discarded by the level
     * filter. Mirrors the guard in {@see emit()}.
     */
    public function enabled(Level $level): bool
    {
        return $level->value >= $this->minLevel->value;
    }

    // Formatted variants. Each guards on enabled() BEFORE sprintf so a
    // filtered-out call never pays the formatting cost (nor triggers argument
    // side effects such as __toString()). `printf` is intentionally excluded —
    // like `print`, it bypasses the level filter and must always render.
    public function debugf(string $format, array $context = [], ...$args): void
    {
        if (!$this->enabled(Level::Debug)) {
            return;
        }
        $this->debug(\sprintf($format, ...$args), $context);
    }

    public function infof(string $format, array $context = [], ...$args): void
    {
        if (!$this->enabled(Level::Info)) {
            return;
        }
        $this->info(\sprintf($format, ...$args), $context);
    }

    public function warnf(string $format, array $context = [], ...$args): void
    {
        if (!$this->enabled(Level::Warn)) {
            return;
        }
        $this->warn(\sprintf($format, ...$args), $context);
    }

    public function errorf(string $format, array $context = [], ...$args): void
    {
        if (!$this->enabled(Level::Error)) {
            return;
        }
        $this->error(\sprintf($format, ...$args), $context);
    }

    public function fatalf(string $format, array $context = [], ...$args): void
    {
        if (!$this->enabled(Level::Fatal)) {
            return;
        }
        $this->fatal(\sprintf($format, ...$args), $context);
    }

    public function printf(string $format, array $context = [], ...$args): void
    {
        $this->print(\sprintf($format, ...$args), $context);
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    /** Create a child logger with additional persistent fields. */
    public function with(array $fields): self
    {
        $child = clone $this;
        $child->fields = \array_merge($this->fields, $fields);
        return $child;
    }

    public function withPrefix(string $prefix): self
    {
        $child = clone $this;
        $child->prefix = $prefix;
        return $child;
    }

    public function withFormatter(Formatter $formatter): self
    {
        $child = clone $this;
        $child->formatter = $formatter;
        return $child;
    }

    public function withMinLevel(Level $level): self
    {
        $child = clone $this;
        $child->minLevel = $level;
        return $child;
    }

    public function withPartsOrder(PartsOrder $partsOrder): self
    {
        $child = clone $this;
        $child->partsOrder = $partsOrder;
        if ($child->formatter instanceof TextFormatter) {
            $child->formatter = $child->formatter->withPartsOrder($partsOrder);
        }
        return $child;
    }

    public function setFormatter(Formatter $formatter): void
    {
        $this->formatter = $formatter;
    }

    public function setMinLevel(Level $level): void
    {
        $this->minLevel = $level;
    }

    public function setReportCaller(bool $on): void
    {
        $this->reportCaller = $on;
        // Only rebuild TextFormatter; leave non-text formatters (Json, Logfmt) untouched
        if ($this->formatter instanceof TextFormatter) {
            $this->formatter = new TextFormatter(
                $this->reportTimestamp,
                $this->timeFormat,
                $on,
                $this->useColors,
                $this->styles,
                $this->partsOrder,
            );
        }
    }

    public function setReportTimestamp(bool $on): void
    {
        $this->reportTimestamp = $on;
        // Only rebuild TextFormatter; leave non-text formatters (Json, Logfmt) untouched
        if ($this->formatter instanceof TextFormatter) {
            $this->formatter = new TextFormatter(
                $on,
                $this->timeFormat,
                $this->reportCaller,
                $this->useColors,
                $this->styles,
                $this->partsOrder,
            );
        }
    }

    public function setPrefix(?string $prefix): void
    {
        $this->prefix = $prefix;
    }

    /**
     * Set the main output stream in-place (the error stream, if any, is kept).
     *
     * @param resource $stream A valid output stream resource.
     * @throws \InvalidArgumentException If $stream is not a resource.
     */
    public function setOutput(mixed $stream): void
    {
        $this->stream = self::requireStream($stream);
    }

    /**
     * Create a child logger with a different main output stream.
     *
     * @param resource $stream A valid output stream resource.
     * @throws \InvalidArgumentException If $stream is not a resource.
     */
    public function withOutput(mixed $stream): self
    {
        $child = clone $this;
        $child->stream = self::requireStream($stream);
        return $child;
    }

    /**
     * Install (or replace) the secondary error stream in-place.
     *
     * Records whose level is at or above $errorStreamAt go to this stream
     * instead of the main one; pass null to disable routing again.
     *
     * @param resource|null $stream A stream resource, or null to disable.
     * @throws \InvalidArgumentException If a non-null $stream is not a resource.
     */
    public function setErrorStream(mixed $stream, ?Level $errorStreamAt = null): void
    {
        if ($stream === null) {
            $this->errorStream = null;
            $this->errorStreamAt = null;
            return;
        }
        $this->errorStream = self::requireStream($stream);
        $this->errorStreamAt = $errorStreamAt ?? Level::Error;
    }

    /**
     * Create a child logger routing severe records to a second stream.
     *
     * @see setErrorStream() for the semantics.
     * @throws \InvalidArgumentException If a non-null $stream is not a resource.
     */
    public function withErrorStream(mixed $stream, ?Level $errorStreamAt = null): self
    {
        $child = clone $this;
        $child->setErrorStream($stream, $errorStreamAt);
        return $child;
    }

    /**
     * Styles object for text-formatter styling.
     *
     * Caution: the default Styles instance is process-wide shared mutable
     * state ({@see Styles::default()}). Mutating what logger->styles()
     * returns changes every logger that never called setStyles(). For a
     * private palette use `$logger->setStyles(new Styles())` first.
     */
    public function styles(): Styles
    {
        return $this->styles;
    }

    public function setStyles(Styles $styles): void
    {
        $this->styles = $styles;
        if ($this->formatter instanceof TextFormatter) {
            $this->formatter = $this->formatter->withStyles($styles);
        }
    }

    public function setPartsOrder(PartsOrder $partsOrder): void
    {
        $this->partsOrder = $partsOrder;
        if ($this->formatter instanceof TextFormatter) {
            $this->formatter = $this->formatter->withPartsOrder($partsOrder);
        }
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Boundary guard: an output must be a stream resource before it is stored.
     *
     * @return resource The validated stream.
     * @throws \InvalidArgumentException If $stream is not a resource.
     */
    private static function requireStream(mixed $stream): mixed
    {
        if (!\is_resource($stream)) {
            throw new \InvalidArgumentException(Lang::t('logger.invalid_stream'));
        }
        return $stream;
    }
}

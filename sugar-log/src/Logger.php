<?php

declare(strict_types=1);

namespace SugarCraft\Log;

use SugarCraft\Log\Lang;
use SugarCraft\Log\Formatter\TextFormatter;
use SugarCraft\Log\PartsOrder;
use SugarCraft\Log\Support\Stdio;
use SugarCraft\ColorProfile\Probe;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Style\Style;

/**
 * Minimal colorful leveled logger.
 * Mirrors charmbracelet/log's Logger API.
 *
 * Output deviation: unlike charmbracelet/log (which writes everything to
 * os.Stderr), this logger writes EVERY level to its main stream — STDOUT by
 * default — chosen for pipe-and-file ergonomics: all levels land on one stream, severe records optionally split. Packagist-era consumers outside this tree may treat it as the contract. Route
 * severe records elsewhere with the `errorStream` knob: levels at or above
 * `errorStreamAt` (default {@see Level::Error}) go to the error stream,
 * everything else to the main stream.
 *
 * Color destination gate: ANSI styling is delivered only to destinations
 * that can render it. When a record's target stream is not a terminal, a
 * {@see TextFormatter} is transparently swapped for its plain (useColors:
 * false) mirror, so log files never bake SGR bytes. `CLICOLOR_FORCE=1`
 * overrides the gate; JSON and logfmt formatters emit no ANSI anyway and
 * pass through untouched.
 *
 * Hooks: {@see Hook\HookRegistry} callbacks fire only when records pass
 * through a {@see PsrBridge}; the core Logger::log() path does not dispatch
 * hooks (deliberate — the emit path stays synchronous and allocation-light).
 */
final class Logger
{
    /** Alias of the formatter-owned default ([SL-33] one literal, one owner). */
    public const DEFAULT_FORMAT = TextFormatter::DEFAULT_TIME_FORMAT;

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

    /**
     * Plain mirror (useColors:false) of the current TextFormatter, built once
     * per formatter identity and reused — the C2 destination gate hands file
     * and pipe targets this instead of the styled formatter.
     */
    private ?Formatter $plainVariant = null;
    private ?TextFormatter $plainSource = null;

    /** Parts order for text formatter. */
    private ?PartsOrder $partsOrder;

    /**
     * @param Formatter|null $formatter Output formatter (defaults to TextFormatter).
     *                                   A supplied TextFormatter is adopted with the
     *                                   logger's presentation knobs winning:
     *                                   timestamp, time format, caller reporting,
     *                                   styles, and parts order are re-seeded from
     *                                   this logger [PHP-02]. Non-text formatters
     *                                   carry none of that state and are adopted as-is.
     * @param Level|null     $level     Minimum level to emit (defaults to Info).
     * @param string|null    $prefix    Prepended string on every line.
     * @param bool           $reportTimestamp Show a timestamp (main stream).
     * @param string|null    $timeFormat  PHP date format string (default: Y/m/d H:i:s).
     * @param bool           $reportCaller Show file:line of log call site.
     * @param resource|string|null $stream Main output stream (default STDOUT). A
     *                                   string is a file path, opened append-mode.
     * @param resource|string|null $errorStream Secondary stream: records at or
     *                                   above $errorStreamAt are written here instead
     *                                   of $stream (e.g. STDERR, or a log path).
     * @param Level|null     $errorStreamAt Routing threshold; defaults to
     *                                   {@see Level::Error} when $errorStream is given.
     *
     * The $reportTimestamp/$timeFormat/$reportCaller flags shape the library's
     * own TextFormatter (the default, and any formatter a later setReport*()
     * rebuild produces). A custom $formatter ignores them — pass your own
     * flags to JsonFormatter/LogfmtFormatter/etc.
     *
     * @throws \InvalidArgumentException If either stream is neither a resource nor an openable path.
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

        // [SL-35] Independent snapshot, not the shared singleton.
        $this->styles = Styles::snapshot();
        $this->useColors = $useColors;
        $this->partsOrder = $partsOrder ?? PartsOrder::default();

        $this->reportTimestamp = $reportTimestamp;
        $this->timeFormat = $timeFormat;
        $this->reportCaller = $reportCaller;

        $this->formatter = $formatter === null ? new TextFormatter(
            $reportTimestamp,
            $timeFormat,
            $reportCaller,
            $useColors,
            $this->styles,
            $this->partsOrder,
        ) : $this->reseedTextFormatter($formatter); // [PHP-02] same adopt seam
                                                     // as withFormatter()
        $this->minLevel = $level ?? Level::Info;
        $this->prefix = $prefix;
        $this->fields = [];
        $this->stream = self::requireStream($stream ?? Stdio::out());
        $this->errorStream = $errorStream === null ? null : self::requireStream($errorStream);
        $this->errorStreamAt = $this->errorStream === null ? null : ($errorStreamAt ?? Level::Error);
    }

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    /**
     * Named-argument constructor — charm's option funcs rendered with PHP's
     * named args: `Logger::new(prefix: 'svc', level: Level::Warn)`.
     *
     * Forwards every argument to {@see __construct()} unchanged (the list IS
     * the constructor's; a hand-copied twin can silently drift out of it).
     */
    public static function new(mixed ...$args): self
    {
        return new self(...$args);
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

        $target = $this->stream;
        if ($this->errorStream !== null && $level->value >= ($this->errorStreamAt ?? Level::Error)->value) {
            // [N5] The setter normalizes the threshold, so the ?? arm keeps
            // the documented default honest even if the invariant were ever
            // broken by a future path: routing must never silently widen to
            // "everything at Info and above".
            $target = $this->errorStream;
        }

        // C2 destination gate: ANSI never bakes into file/pipe output. A
        // TextFormatter aimed at a destination that cannot render styling is
        // swapped for its cached plain mirror; CLICOLOR_FORCE=1 opts back in.
        $formatter = $this->formatter;
        if ($formatter instanceof TextFormatter && !ColorGate::allowsColor($target)) {
            $formatter = $this->plainMirrorOf($formatter);
        }

        $line = $formatter->format($level, $message, $merged, $time, $caller, $this->prefix);

        $this->writeRecord($target, $line);

        if ($level === Level::Fatal) {
            // [SEC-01] Sweep + flatten the raw caller message before it enters
            // the exception text: an uncaught fatal prints this string through
            // PHP's error output, where surviving ESC/OSC bytes could move the
            // cursor, set the window title, or forge extra lines.
            $swept = \str_replace(["\n", "\r", "\t"], ' ', Sanitize::untrusted($message));

            // [PHP-01] Typed throw: the PsrBridge detects the fatal signal by
            // instanceof, not by message equality. RuntimeException subclass
            // keeps every pre-existing catcher's contract.
            throw new FatalLoggedException(Lang::t('logger.fatal', ['message' => $swept]));
        }
    }

    /**
     * Best-effort single write of a formatted record.
     *
     * [M8] A discarded fwrite() result used to swallow lost records whole:
     * a closed handle, a broken pipe, or a short write simply vanished. A
     * failed write is retried once after flushing buffered bytes; if it
     * still cannot land, the loss is announced on STDERR as a sanitized
     * one-line internal notice. Logging never escalates I/O failure by
     * throwing — callers inside catch blocks must not gain new exceptions.
     */
    private function writeRecord(mixed $target, string $line): void
    {
        if (!\is_resource($target)) {
            // [m15] requireStream() validated the handle at assignment time;
            // the host may have closed it since. Degrade to a notice.
            $this->reportWriteFailure('stream is no longer a valid resource', $line);

            return;
        }

        $written = @\fwrite($target, $line);
        if ($written === \strlen($line)) {
            return;
        }

        @\fflush($target);
        $rest = $written === false ? $line : \substr($line, $written);
        $again = @\fwrite($target, $rest);
        if ($again === \strlen($rest)) {
            return;
        }

        $this->reportWriteFailure(
            $written === false && $again === false ? 'stream rejected the write' : 'partial write only',
            $line,
        );
    }

    /**
     * Announce a dropped record on STDERR — the logger's own error channel —
     * without throwing. Control bytes and line breaks are swept so a hostile
     * message riding inside the record cannot forge extra notice lines.
     */
    private function reportWriteFailure(string $why, string $line): void
    {
        // [PORT-01] Stdio::err() is SAPI-safe: under php-fpm \STDERR is not
        // even defined, so the bare constant read in the old guard crashed
        // the very path built to report failures.
        $stderr = Stdio::err();
        if (null === $stderr) {
            return;
        }

        $excerpt = \str_replace(["\n", "\r", "\t"], ' ', Sanitize::untrusted($line));
        @\fwrite($stderr, '[sugar-log] log write failed: ' . $why . ': ' . $excerpt . "\n");
    }

    /**
     * Push buffered bytes out to both destinations.
     *
     * PHP buffers file-stream writes, so a process killed with SIGKILL (or
     * any hard-exit path that skips shutdown) loses whatever still sits in
     * the buffer. Call flush() on critical paths before exiting.
     */
    public function flush(): void
    {
        if (\is_resource($this->stream)) {
            @\fflush($this->stream);
        }
        if ($this->errorStream !== null && \is_resource($this->errorStream)) {
            @\fflush($this->errorStream);
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
     * Cached useColors:false mirror of $source. Rebuilt only when the Logger's
     * formatter identity changes (a new setFormatter()/withFormatter() value),
     * so the gate costs one identity compare per emit.
     */
    private function plainMirrorOf(TextFormatter $source): Formatter
    {
        if ($this->plainSource !== $source || $this->plainVariant === null) {
            $this->plainVariant = $source->withUseColors(false);
            $this->plainSource = $source;
        }

        return $this->plainVariant;
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
    //
    // [M14] Slot two is `mixed`: an array there is context (the historic
    // behaviour), anything else is the first sprintf argument — so
    // infof('count %d', 7) formats the way the name promises instead of
    // TypeError-ing. Context alongside real arguments:
    // infof('count %d', ['id' => 1], 7).
    public function debugf(string $format, mixed $context = [], mixed ...$args): void
    {
        if (!$this->enabled(Level::Debug)) {
            return;
        }
        self::parseFormatted($context, $args);
        $this->debug(\sprintf($format, ...$args), $context);
    }

    public function infof(string $format, mixed $context = [], mixed ...$args): void
    {
        if (!$this->enabled(Level::Info)) {
            return;
        }
        self::parseFormatted($context, $args);
        $this->info(\sprintf($format, ...$args), $context);
    }

    public function warnf(string $format, mixed $context = [], mixed ...$args): void
    {
        if (!$this->enabled(Level::Warn)) {
            return;
        }
        self::parseFormatted($context, $args);
        $this->warn(\sprintf($format, ...$args), $context);
    }

    public function errorf(string $format, mixed $context = [], mixed ...$args): void
    {
        if (!$this->enabled(Level::Error)) {
            return;
        }
        self::parseFormatted($context, $args);
        $this->error(\sprintf($format, ...$args), $context);
    }

    public function fatalf(string $format, mixed $context = [], mixed ...$args): void
    {
        if (!$this->enabled(Level::Fatal)) {
            return;
        }
        self::parseFormatted($context, $args);
        $this->fatal(\sprintf($format, ...$args), $context);
    }

    public function printf(string $format, mixed $context = [], mixed ...$args): void
    {
        self::parseFormatted($context, $args);
        $this->print(\sprintf($format, ...$args), $context);
    }

    /**
     * Shared slot-two parse for the formatted helpers (see [M14] above):
     * non-array context becomes the first sprintf argument.
     *
     * @param mixed       $context
     * @param list<mixed> $args
     */
    private static function parseFormatted(mixed &$context, array &$args): void
    {
        if (!\is_array($context)) {
            \array_unshift($args, $context);
            $context = [];
        }
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
        $child->formatter = self::reseedTextFormatter($formatter);
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
        $this->formatter = self::reseedTextFormatter($formatter);
    }

    /**
     * [PHP-02] Adopt-seam re-seed. A bare `new TextFormatter()` carries default
     * presentation state — handing it to a Logger that had caller reporting, a
     * custom time format, styles, or parts order configured silently lost all
     * of it (the inverse of setStyles()/setPartsOrder(), which rewire the live
     * formatter). When the incoming formatter is a TextFormatter, the logger's
     * presentation knobs win. Non-text formatters (Json, Logfmt) carry none of
     * this state and are adopted untouched; on those the Logger-side knobs
     * simply do not apply (the constructor note says the same).
     */
    private function reseedTextFormatter(Formatter $formatter): Formatter
    {
        if (!$formatter instanceof TextFormatter) {
            return $formatter;
        }

        return $formatter
            ->withReportTimestamp($this->reportTimestamp)
            ->withTimeFormat($this->timeFormat)
            ->withReportCaller($this->reportCaller)
            ->withStyles($this->styles)
            ->withPartsOrder($this->partsOrder);
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

    /**
     * Change the timestamp format in place (parity with charm's SetTimeFormat).
     *
     * null reverts to the active formatter's default. A live TextFormatter is
     * re-seeded through its immutable wither, so its class and every other
     * formatter option survive; Json/Logfmt resolve their format at
     * construction and keep it. The Logger-side value is retained either way
     * and flows into any TextFormatter adopted later through the adopt seam.
     */
    public function setTimeFormat(?string $timeFormat): void
    {
        $this->timeFormat = $timeFormat;
        if ($this->formatter instanceof TextFormatter) {
            $this->formatter = $this->formatter->withTimeFormat($timeFormat);
        }
    }

    /**
     * Clone-with-time-format twin of setTimeFormat().
     */
    public function withTimeFormat(?string $timeFormat): self
    {
        $child = clone $this;
        $child->setTimeFormat($timeFormat);
        return $child;
    }

    public function setPrefix(?string $prefix): void
    {
        $this->prefix = $prefix;
    }

    /**
     * Set the main output stream in-place (the error stream, if any, is kept).
     *
     * @param resource|string $stream A stream resource or a file path (opened append-mode).
     * @throws \InvalidArgumentException If $stream is not a resource.
     */
    public function setOutput(mixed $stream): void
    {
        $this->stream = self::requireStream($stream);
    }

    /**
     * Create a child logger with a different main output stream.
     *
     * @param resource|string $stream A stream resource or a file path (opened append-mode).
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
     * @param resource|string|null $stream A resource, a file path (opened
     *                                      append-mode), or null to disable.
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
     * A logger built without styles owns an independent snapshot
     * ({@see Styles::snapshot()}), so mutating what logger->styles() returns
     * changes THIS logger only. The process-wide shared instance stays
     * opt-in: whoever passes {@see Styles::default()} to setStyles() rides
     * (and recolours) the singleton.
     */
    public function styles(): Styles
    {
        return $this->styles;
    }

    /**
     * The formatter currently rendering records. A bare `TextFormatter`
     * handed to the constructor or setFormatter() comes back re-seeded with
     * this logger's caller/timestamp/time-format state (SL-14 adopt seam),
     * so the returned object is the one actually on the write path.
     */
    public function formatter(): Formatter
    {
        return $this->formatter;
    }

    /**
     * Minimum level this logger writes. Named for the constructor parameter
     * (`level:`), not `withMinLevel()` — the ctor noun is the source of truth.
     */
    public function level(): Level
    {
        return $this->minLevel;
    }

    /** Prefix string, or null when none is set. */
    public function prefix(): ?string
    {
        return $this->prefix;
    }

    /**
     * Main output stream (mixed: resource or sink accepted by the ctor).
     * Errors at/above the setErrorStream() threshold go to that secondary
     * stream instead; this accessor returns the primary one.
     */
    public function output(): mixed
    {
        return $this->stream;
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
     * @return resource The validated (or freshly opened) stream.
     * @throws \InvalidArgumentException If $stream is neither a resource nor an openable path.
     */
    private static function requireStream(mixed $stream): mixed
    {
        if (\is_resource($stream)) {
            return $stream;
        }
        if (\is_string($stream) && $stream !== '') {
            // Path arm: append mode so a logger never truncates an existing
            // log. Each assignment opens its own handle (resources stay
            // caller-managed; we close only what we opened, never).
            $opened = @\fopen($stream, 'a');
            if (\is_resource($opened)) {
                return $opened;
            }
            throw new \InvalidArgumentException(Lang::t('logger.unopenable_stream', ['path' => $stream]));
        }
        throw new \InvalidArgumentException(Lang::t('logger.invalid_stream'));
    }
}

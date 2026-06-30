<?php

declare(strict_types=1);

namespace SugarCraft\Log;

use SugarCraft\Log\Lang;
use SugarCraft\Log\Formatter\TextFormatter;
use SugarCraft\Log\PartsOrder;
use SugarCraft\Palette\Probe;
use SugarCraft\Sprinkles\Style;

/**
 * Minimal colorful leveled logger.
 * Mirrors charmbracelet/log's Logger API.
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
    private $stream;

    /** Styles for text-formatter level coloring. */
    private Styles $styles;

    /** Whether colors are enabled based on terminal capabilities. */
    private bool $useColors;

    /** Parts order for text formatter. */
    private ?PartsOrder $partsOrder;

    /**
     * @param Formatter|null $formatter Output formatter (defaults to TextFormatter).
     * @param Level|null     $minLevel  Minimum level to emit (defaults to Info).
     * @param string|null    $prefix    Prepended string on every line.
     * @param bool           $reportTimestamp
     * @param string|null    $timeFormat  PHP date format string (default: Y/m/d H:i:s).
     * @param bool           $reportCaller Show file:line of log call site.
     */
    public function __construct(
        ?Formatter $formatter = null,
        ?Level $minLevel = null,
        ?string $prefix = null,
        bool $reportTimestamp = true,
        ?string $timeFormat = null,
        bool $reportCaller = false,
        $stream = null,
        ?PartsOrder $partsOrder = null,
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
        $this->minLevel = $minLevel ?? Level::Info;
        $this->prefix = $prefix;
        $this->reportTimestamp = $reportTimestamp;
        $this->timeFormat = $timeFormat;
        $this->reportCaller = $reportCaller;
        $this->fields = [];
        $this->stream = $stream ?? \STDOUT;
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
        $stream = null,
        ?PartsOrder $partsOrder = null,
    ): self {
        return new self($formatter, $level, $prefix, $reportTimestamp, $timeFormat, $reportCaller, $stream, $partsOrder);
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

    /** Print without a level prefix, ignoring level filters. */
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

        \fwrite($this->stream, $line);

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

    // Formatted variants
    public function debugf(string $format, array $context = [], ...$args): void
    {
        $this->debug(\sprintf($format, ...$args), $context);
    }

    public function infof(string $format, array $context = [], ...$args): void
    {
        $this->info(\sprintf($format, ...$args), $context);
    }

    public function warnf(string $format, array $context = [], ...$args): void
    {
        $this->warn(\sprintf($format, ...$args), $context);
    }

    public function errorf(string $format, array $context = [], ...$args): void
    {
        $this->error(\sprintf($format, ...$args), $context);
    }

    public function fatalf(string $format, array $context = [], ...$args): void
    {
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
     * Set the output stream in-place.
     *
     * @param resource $stream A valid output stream resource.
     * @throws \InvalidArgumentException If $stream is not a resource.
     */
    public function setOutput($stream): void
    {
        if (!\is_resource($stream)) {
            throw new \InvalidArgumentException(Lang::t('logger.invalid_stream'));
        }
        $this->stream = $stream;
    }

    /**
     * Create a child logger with a different output stream.
     *
     * @param resource $stream A valid output stream resource.
     * @throws \InvalidArgumentException If $stream is not a resource.
     */
    public function withOutput($stream): self
    {
        if (!\is_resource($stream)) {
            throw new \InvalidArgumentException(Lang::t('logger.invalid_stream'));
        }
        $child = clone $this;
        $child->stream = $stream;
        return $child;
    }

    /** Styles object for text-formatter level styling. */
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
}

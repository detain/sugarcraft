<?php

declare(strict_types=1);

namespace CandyCore\Log;

use CandyCore\Log\Formatter\TextFormatter;
use CandyCore\Sprinkles\Style;

/**
 * Minimal colorful leveled logger.
 * Mirrors charmbracelet/log's Logger API.
 */
final class Logger
{
    public const DEFAULT_FORMAT = '2006/01/02 15:04:05';

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

    /**
     * @param Formatter|null $formatter Output formatter (defaults to TextFormatter).
     * @param Level|null     $minLevel  Minimum level to emit (defaults to Info).
     * @param string|null    $prefix    Prepended string on every line.
     * @param bool           $reportTimestamp
     * @param string|null    $timeFormat  PHP date format string (default: 2006/01/02 15:04:05).
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
    ) {
        $this->formatter = $formatter ?? new TextFormatter($reportTimestamp, $timeFormat, $reportCaller);
        $this->minLevel = $minLevel ?? Level::Info;
        $this->prefix = $prefix;
        $this->reportTimestamp = $reportTimestamp;
        $this->timeFormat = $timeFormat;
        $this->reportCaller = $reportCaller;
        $this->fields = [];
        $this->stream = $stream ?? \STDOUT;
        $this->styles = Styles::default();
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
    ): self {
        return new self($formatter, $level, $prefix, $reportTimestamp, $timeFormat, $reportCaller, $stream);
    }

    // -------------------------------------------------------------------------
    // Global convenience methods (static level functions via staticLogger())
    // -------------------------------------------------------------------------

    private static ?Logger $staticLogger = null;

    private static function staticLogger(): Logger
    {
        return self::$staticLogger ??= self::new();
    }

    public static function debug(string $message, array $context = []): void
    {
        self::staticLogger()->log(Level::Debug, $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::staticLogger()->log(Level::Info, $message, $context);
    }

    public static function warn(string $message, array $context = []): void
    {
        self::staticLogger()->log(Level::Warn, $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::staticLogger()->log(Level::Error, $message, $context);
    }

    public static function fatal(string $message, array $context = []): void
    {
        self::staticLogger()->log(Level::Fatal, $message, $context);
    }

    public static function print(string $message, array $context = []): void
    {
        self::staticLogger()->log(Level::Info, $message, $context);
    }

    public static function setLogger(Logger $logger): void
    {
        self::$staticLogger = $logger;
    }

    // -------------------------------------------------------------------------
    // Instance methods
    // -------------------------------------------------------------------------

    public function log(Level $level, string $message, array $context = []): void
    {
        if ($level->value < $this->minLevel->value) {
            return;
        }

        // Merge child fields with call-site context
        $merged = \array_merge($this->fields, $context);

        $caller = $this->reportCaller ? $this->findCaller() : null;
        $time = new \DateTimeImmutable();
        $line = $this->formatter->format($level, $message, $merged, $time, $caller, $this->prefix);

        \fwrite($this->stream, $line);

        if ($level === Level::Fatal) {
            exit(1);
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

    /** Print without a level prefix. */
    public function print(string $message, array $context = []): void
    {
        $this->log(Level::Info, $message, $context);
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
        $this->formatter = new TextFormatter(
            $this->reportTimestamp,
            $this->timeFormat,
            $on,
        );
    }

    public function setReportTimestamp(bool $on): void
    {
        $this->reportTimestamp = $on;
        $this->formatter = new TextFormatter(
            $on,
            $this->timeFormat,
            $this->reportCaller,
        );
    }

    public function setPrefix(?string $prefix): void
    {
        $this->prefix = $prefix;
    }

    /** Styles object for text-formatter level styling. */
    public function styles(): Styles
    {
        return $this->styles;
    }

    public function setStyles(Styles $styles): void
    {
        $this->styles = $styles;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /** Walk back the call stack to find the first file outside the log package. */
    private function findCaller(): ?string
    {
        $traces = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 20);
        foreach ($traces as $t) {
            $file = $t['file'] ?? '';
            if (\strpos($file, __DIR__) === 0) {
                continue;
            }
            $line = $t['line'] ?? '?';
            $basename = \basename($file);
            return "{$basename}:{$line}";
        }
        return null;
    }
}

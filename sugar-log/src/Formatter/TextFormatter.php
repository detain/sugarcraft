<?php

declare(strict_types=1);

namespace SugarCraft\Log\Formatter;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Log\Formatter;
use SugarCraft\Log\Level;
use SugarCraft\Log\PartsOrder;
use SugarCraft\Log\Styles;
use SugarCraft\Sprinkles\Style;

/**
 * Human-readable text formatter with optional color styling.
 * Mirrors charmbracelet/log's TextFormatter.
 */
final class TextFormatter implements Formatter
{
    private const DEFAULT_TIME_FORMAT = 'Y/m/d H:i:s';

    private bool $reportTimestamp;
    private ?string $timeFormat;
    private bool $reportCaller;
    private bool $useColors;
    private Styles $styles;
    private PartsOrder $partsOrder;

    public function __construct(
        bool $reportTimestamp = true,
        ?string $timeFormat = null,
        bool $reportCaller = false,
        bool $useColors = true,
        ?Styles $styles = null,
        ?PartsOrder $partsOrder = null,
    ) {
        $this->reportTimestamp = $reportTimestamp;
        $this->timeFormat = $timeFormat;
        $this->reportCaller = $reportCaller;
        $this->useColors = $useColors;
        $this->styles = $styles ?? Styles::default();
        $this->partsOrder = $partsOrder ?? PartsOrder::default();
    }

    public function format(
        Level $level,
        string $message,
        array $context,
        \DateTimeImmutable $time,
        ?string $caller,
        ?string $prefix,
    ): string {
        $out = [];

        foreach ($this->partsOrder->parts as $part) {
            $item = match ($part) {
                PartsOrder::PART_TIMESTAMP => $this->reportTimestamp
                    ? ($this->timeFormat !== null
                        ? $time->format($this->timeFormat)
                        : $time->format(self::DEFAULT_TIME_FORMAT))
                    : null,

                PartsOrder::PART_LEVEL => $this->useColors
                    ? $this->styledLevel($level)
                    : $level->shortLabel(),

                PartsOrder::PART_PREFIX => ($prefix !== null && $prefix !== '')
                    ? ($this->useColors ? $this->styledPrefix($prefix) : $prefix)
                    : null,

                PartsOrder::PART_CALLER => ($this->reportCaller && $caller !== null)
                    ? ($this->useColors ? $this->styledCaller($caller) : "<{$caller}>")
                    : null,

                PartsOrder::PART_MESSAGE => $this->sanitize($message),

                PartsOrder::PART_FIELDS => \count($context) > 0
                    ? $this->formatContext($context)
                    : null,

                default => null,
            };

            if ($item !== null && $item !== '') {
                $out[] = $item;
            }
        }

        return \implode(' ', $out) . "\n";
    }

    private function styledLevel(Level $level): string
    {
        return $this->styles->levels[$level->value]->render($level->shortLabel());
    }

    private function styledPrefix(string $prefix): string
    {
        return $this->styles->prefix->render($prefix);
    }

    private function styledCaller(string $caller): string
    {
        return $this->styles->caller->render("<{$caller}>");
    }

    private function formatContext(array $context): string
    {
        $pairs = [];
        foreach ($context as $k => $v) {
            $key = $this->sanitize((string) $k);
            $val = $this->sanitize($this->formatValue($v));
            $pair = "{$key}={$val}";
            $pairs[] = $this->useColors
                ? $this->styles->keyStyle('key')->render($pair)
                : $pair;
        }
        return \implode(' ', $pairs);
    }

    private function formatValue(mixed $v): string
    {
        return ValueCoercion::stringify($v);
    }

    /**
     * Neutralize log-injection and terminal-control-sequence injection in
     * caller-supplied text.
     *
     * Untrusted message/context values can embed a raw newline to forge a
     * second log line, or a raw ESC to smuggle SGR/CSI sequences that recolor
     * or reposition the terminal. {@see Sanitize::untrusted()} strips ESC-based
     * ANSI and lone C1/C0 bytes; the trailing str_replace flattens any
     * surviving \n/\r/\t to a single space so one log call renders as exactly
     * one physical line. The library's own coloring (added by Style::render
     * around this value) is unaffected because it wraps the already-sanitized
     * text.
     */
    private function sanitize(string $s): string
    {
        return \str_replace(["\n", "\r", "\t"], ' ', Sanitize::untrusted($s));
    }

    /**
     * Create a new TextFormatter with different styles, preserving all other settings.
     */
    public function withStyles(Styles $styles): self
    {
        $child = clone $this;
        $child->styles = $styles;
        return $child;
    }

    /**
     * Create a new TextFormatter with different parts order, preserving all other settings.
     */
    public function withPartsOrder(PartsOrder $partsOrder): self
    {
        $child = clone $this;
        $child->partsOrder = $partsOrder;
        return $child;
    }
}

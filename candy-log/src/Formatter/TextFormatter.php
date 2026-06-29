<?php

declare(strict_types=1);

namespace SugarCraft\Log\Formatter;

use SugarCraft\Core\Util\Color;
use SugarCraft\Log\Formatter;
use SugarCraft\Log\Level;
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

    public function __construct(
        bool $reportTimestamp = true,
        ?string $timeFormat = null,
        bool $reportCaller = false,
        bool $useColors = true,
    ) {
        $this->reportTimestamp = $reportTimestamp;
        $this->timeFormat = $timeFormat;
        $this->reportCaller = $reportCaller;
        $this->useColors = $useColors;
    }

    public function format(
        Level $level,
        string $message,
        array $context,
        \DateTimeImmutable $time,
        ?string $caller,
        ?string $prefix,
    ): string {
        $parts = [];

        if ($this->reportTimestamp) {
            $ts = $this->timeFormat !== null
                ? $time->format($this->timeFormat)
                : $time->format(self::DEFAULT_TIME_FORMAT);
            $parts[] = $ts;
        }

        $label = $this->useColors
            ? $this->styledLevel($level)
            : $level->label();

        if ($prefix !== null && $prefix !== '') {
            $label = ($this->useColors ? $this->styledPrefix($prefix) : $prefix) . ' ' . $label;
        }

        $parts[] = $label;

        if ($this->reportCaller && $caller !== null) {
            $parts[] = $this->useColors ? $this->styledCaller($caller) : "<{$caller}>";
        }

        $parts[] = $message;

        if (\count($context) > 0) {
            $parts[] = $this->formatContext($context);
        }

        return \implode(' ', $parts) . "\n";
    }

    private function styledLevel(Level $level): string
    {
        $label = $level->shortLabel();
        return match ($level) {
            Level::Debug => Style::new()->foreground(Color::ansi(8))->render($label),   // grey
            Level::Info  => Style::new()->foreground(Color::ansi(4))->render($label),   // blue
            Level::Warn  => Style::new()->foreground(Color::ansi(3))->render($label),   // yellow
            Level::Error => Style::new()->foreground(Color::ansi(1))->render($label),   // red
            Level::Fatal => Style::new()->foreground(Color::ansi(7))->background(Color::ansi(1))->render($label),
        };
    }

    private function styledPrefix(string $prefix): string
    {
        return Style::new()->foreground(Color::ansi(5))->render($prefix); // magenta
    }

    private function styledCaller(string $caller): string
    {
        return Style::new()->foreground(Color::ansi(8))->render("<{$caller}>"); // grey
    }

    private function formatContext(array $context): string
    {
        $pairs = [];
        foreach ($context as $k => $v) {
            $val = $this->formatValue($v);
            $pairs[] = $this->useColors
                ? Style::new()->foreground(Color::ansi(8))->render("{$k}={$val}")
                : "{$k}={$val}";
        }
        return \implode(' ', $pairs);
    }

    private function formatValue(mixed $v): string
    {
        return ValueCoercion::stringify($v);
    }
}

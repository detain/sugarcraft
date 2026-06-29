<?php

declare(strict_types=1);

namespace SugarCraft\Log\Formatter;

use SugarCraft\Core\Util\Color;
use SugarCraft\Log\Formatter;
use SugarCraft\Log\Level;
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

    public function __construct(
        bool $reportTimestamp = true,
        ?string $timeFormat = null,
        bool $reportCaller = false,
        bool $useColors = true,
        ?Styles $styles = null,
    ) {
        $this->reportTimestamp = $reportTimestamp;
        $this->timeFormat = $timeFormat;
        $this->reportCaller = $reportCaller;
        $this->useColors = $useColors;
        $this->styles = $styles ?? Styles::default();
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
            $val = $this->formatValue($v);
            $pairs[] = $this->useColors
                ? $this->styles->keyStyle('key')->render("{$k}=") . $this->styles->keyStyle('value')->render($val)
                : "{$k}={$val}";
        }
        return \implode(' ', $pairs);
    }

    private function formatValue(mixed $v): string
    {
        return ValueCoercion::stringify($v);
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
}

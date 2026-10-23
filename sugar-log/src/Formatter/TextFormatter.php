<?php

declare(strict_types=1);

namespace SugarCraft\Log\Formatter;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Log\Formatter;
use SugarCraft\Log\Level;
use SugarCraft\Log\PartsOrder;
use SugarCraft\Log\Styles;
use SugarCraft\Style\Style;

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
                    ? $this->styledTimestamp($this->timeFormat !== null
                        ? $time->format($this->timeFormat)
                        : $time->format(self::DEFAULT_TIME_FORMAT))
                    : null,

                PartsOrder::PART_LEVEL => $this->styledLevel($level),

                PartsOrder::PART_PREFIX => ($prefix !== null && $prefix !== '')
                    ? ($this->useColors ? $this->styledPrefix($prefix) : $prefix)
                    : null,

                PartsOrder::PART_CALLER => ($this->reportCaller && $caller !== null)
                    ? ($this->useColors ? $this->styledCaller($caller) : "<{$caller}>")
                    : null,

                PartsOrder::PART_MESSAGE => $this->styledMessage($this->sanitize($message)),

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

    /**
     * First candidate style that actually paints something, or null when all
     * are the identity (empty) style — an empty `Style::new()` renders its
     * text byte-unchanged, which is what makes identity the safe default for
     * every previously-dead styling knob.
     */
    private function pick(Style ...$candidates): ?Style
    {
        foreach ($candidates as $style) {
            if ($style->render('x') !== 'x') {
                return $style;
            }
        }
        return null;
    }

    private function styledTimestamp(string $timestamp): string
    {
        if (!$this->useColors) {
            return $timestamp;
        }
        $style = $this->pick($this->styles->keyStyle('time'), $this->styles->timestamp);

        return $style?->render($timestamp) ?? $timestamp;
    }

    private function styledLevel(Level $level): string
    {
        $label = $this->styles->padLevelText
            ? Styles::padLevelText($level->shortLabel())
            : $level->shortLabel();

        if (!$this->useColors) {
            return $label;
        }
        $style = $this->pick($this->styles->keyStyle('level'), $this->styles->levels[$level->value]);

        return $style?->render($label) ?? $label;
    }

    private function styledPrefix(string $prefix): string
    {
        $style = $this->pick($this->styles->keyStyle('prefix'), $this->styles->prefix);

        return $style?->render($prefix) ?? $prefix;
    }

    private function styledCaller(string $caller): string
    {
        $style = $this->pick($this->styles->keyStyle('caller'), $this->styles->caller);

        return $style?->render("<{$caller}>") ?? "<{$caller}>";
    }

    private function styledMessage(string $message): string
    {
        if (!$this->useColors) {
            return $message;
        }
        $style = $this->pick($this->styles->keyStyle('message'), $this->styles->message);

        return $style?->render($message) ?? $message;
    }

    private function formatContext(array $context): string
    {
        $pairs = [];
        foreach ($context as $k => $v) {
            $key = $this->sanitize((string) $k);
            $val = $this->sanitize($this->formatValue($v));
            $pairs[] = $this->renderPair(\is_int($k) ? (string) $k : $k, $key, $val);
        }
        return \implode(' ', $pairs);
    }

    /**
     * Render one `key=value` pair. By default the whole pair carries
     * `keys['key']` (documented behavior, byte-compatible); configuring a
     * value style — globally via `keys['value']` or per context key via
     * `$values[$key]` — switches that pair to split styling, leaving the
     * '=' separator unstyled.
     *
     * @param string $rawKey the unsanitized context key, for $values lookup
     */
    private function renderPair(string $rawKey, string $key, string $val): string
    {
        if (!$this->useColors) {
            return "{$key}={$val}";
        }

        $valueStyle = $this->pick(
            $this->styles->values[$rawKey] ?? Style::new(),
            $this->styles->keyStyle('value'),
        );
        if ($valueStyle !== null) {
            return $this->styles->keyStyle('key')->render($key) . '=' . $valueStyle->render($val);
        }

        return $this->styles->keyStyle('key')->render("{$key}={$val}");
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

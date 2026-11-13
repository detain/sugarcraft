<?php

declare(strict_types=1);

namespace SugarCraft\Log\Formatter;

use SugarCraft\ColorProfile\Probe;
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
    /** Owner of the default time format; Logger::DEFAULT_FORMAT aliases this. */
    public const DEFAULT_TIME_FORMAT = 'Y/m/d H:i:s';

    private bool $reportTimestamp;
    private ?string $timeFormat;
    private bool $reportCaller;
    private bool $useColors;
    private Styles $styles;
    private PartsOrder $partsOrder;

    /**
     * @param ?bool $useColors Tri-state (M10): null = consult the terminal
     *                         profile ({@see Probe::colorProfile()}) at
     *                         construction — the documented "auto" behavior;
     *                         true/false = explicit opt-in/opt-out that the
     *                         probe never overrides. Note that a {@see Logger}
     *                         still degrades an opted-in formatter to plain
     *                         output when its destination cannot render
     *                         ANSI (file sinks, pipes — the C2 gate); only a
     *                         terminal (or CLICOLOR_FORCE=1) receives styling.
     */
    public function __construct(
        bool $reportTimestamp = true,
        ?string $timeFormat = null,
        bool $reportCaller = false,
        ?bool $useColors = null,
        ?Styles $styles = null,
        ?PartsOrder $partsOrder = null,
    ) {
        $this->reportTimestamp = $reportTimestamp;
        $this->timeFormat = $timeFormat;
        $this->reportCaller = $reportCaller;
        $this->useColors = $useColors ?? Probe::colorProfile()->allowsColor();
        // [SL-35] Default to an INDEPENDENT snapshot: riding the
        // process-wide mutable singleton meant one formatter's tint
        // recolored every other default-built formatter. Explicit
        // passers (incl. Styles::default() itself) are untouched.
        $this->styles = $styles ?? Styles::snapshot();
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

        // [M3] Prefix and caller join the message on the sanitizer's guest
        // list: they reach format() from host code (Logger::withPrefix, the
        // frame introspection), and a raw ESC sequence or a newline smuggled
        // into either one reshapes the physical line every consumer trusts.
        // Null/'' pass through as null so the part is skipped as before;
        // a purely control-character prefix sanitizes to '' and is skipped
        // too — it had no renderable content to begin with.
        $safePrefix = ($prefix === null || $prefix === '')
            ? null
            : $this->sanitize($prefix);
        $safeCaller = ($caller === null || $caller === '')
            ? null
            : $this->sanitize($caller);
        // Sanitizing to nothing is the same as having nothing to show: drop
        // the part entirely rather than style an empty token.
        if ($safePrefix === '') {
            $safePrefix = null;
        }
        if ($safeCaller === '') {
            $safeCaller = null;
        }

        foreach ($this->partsOrder->parts as $part) {
            $item = match ($part) {
                PartsOrder::PART_TIMESTAMP => $this->reportTimestamp
                    ? $this->styledTimestamp($this->timeFormat !== null
                        ? $time->format($this->timeFormat)
                        : $time->format(self::DEFAULT_TIME_FORMAT))
                    : null,

                PartsOrder::PART_LEVEL => $this->styledLevel($level),

                PartsOrder::PART_PREFIX => $safePrefix !== null
                    ? ($this->useColors ? $this->styledPrefix($safePrefix) : $safePrefix)
                    : null,

                PartsOrder::PART_CALLER => ($this->reportCaller && $safeCaller !== null)
                    ? ($this->useColors ? $this->styledCaller($safeCaller) : "<{$safeCaller}>")
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
    /** @var \WeakMap<Style,bool>|null render-identity verdicts by instance ([N6]). */
    private static ?\WeakMap $identityMemo = null;

    private function pick(Style ...$candidates): ?Style
    {
        // [N6] render('x') allocates a string per styled part per record just
        // to learn whether a Style instance is a no-op. The verdict is a
        // property of the instance, so memoize it against the object itself:
        // WeakMap entries die with the style, keeping this both effective
        // and free of staleness/leak concerns.
        $memo = self::$identityMemo ??= new \WeakMap();
        foreach ($candidates as $style) {
            $identity = $memo[$style] ??= ($style->render('x') === 'x');
            if (!$identity) {
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
        // ?? Style::new() parity with keyStyle(): a sparse custom palette that
        // omits a level falls back to the identity style instead of throwing
        // "Undefined array key" mid-emit.
        $style = $this->pick($this->styles->keyStyle('level'), $this->styles->levels[$level->value] ?? Style::new());

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

    /**
     * Create a new TextFormatter with the timestamp part switched, preserving
     * all other settings. Used by the Logger formatter-adopt seam [PHP-02].
     */
    public function withReportTimestamp(bool $reportTimestamp): self
    {
        $child = clone $this;
        $child->reportTimestamp = $reportTimestamp;
        return $child;
    }

    /**
     * Create a new TextFormatter with a different time format, preserving all
     * other settings. Used by the Logger formatter-adopt seam [PHP-02].
     */
    public function withTimeFormat(?string $timeFormat): self
    {
        $child = clone $this;
        $child->timeFormat = $timeFormat;
        return $child;
    }

    /**
     * Create a new TextFormatter with the caller part switched, preserving all
     * other settings. Used by the Logger formatter-adopt seam [PHP-02].
     */
    public function withReportCaller(bool $reportCaller): self
    {
        $child = clone $this;
        $child->reportCaller = $reportCaller;
        return $child;
    }

    /**
     * Create a new TextFormatter with coloring switched, preserving all other
     * settings. Used by {@see \SugarCraft\Log\Logger}'s destination gate to
     * deliver a plain mirror of the configured formatter when the target
     * stream cannot render ANSI (C2).
     */
    public function withUseColors(bool $useColors): self
    {
        $child = clone $this;
        $child->useColors = $useColors;
        return $child;
    }
}

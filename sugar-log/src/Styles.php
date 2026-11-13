<?php

declare(strict_types=1);

namespace SugarCraft\Log;

use SugarCraft\Core\Util\Color;
use SugarCraft\Style\Style;

/**
 * Styles for log level rendering in text output.
 *
 * Mirrors charmbracelet/log's Styles SHAPE (levels keyed by level, per-field
 * keys/values envelope map, copy/snapshot ergonomics); the default palette is
 * intentionally re-tuned high-contrast rather than copied 1:1 — upstream
 * ships purple-63/green-86/yellow-192/red-204/magenta-134 levels, this port
 * ships bright-black-8/blue-4/yellow-3/bold-red-1/white-on-bold-red-7
 * (see the README "High-contrast environments" section). Charm migrants
 * should expect that silent visual break.
 */
final class Styles
{
    /** @var array<int, Style> keyed by Level->value */
    public array $levels = [];

    /**
     * Per-field styling consulted by {@see \SugarCraft\Log\Formatter\TextFormatter}
     * for the record parts named `time`, `level`, `prefix`, `caller`, `message`,
     * plus the context pair conventions `key` (whole `k=v` by default) and
     * `value` (switches pairs to split key/value styling).
     *
     * Envelope entries default to the identity (empty) style so default output
     * stays byte-identical to the no-styles case; the fallback chain is
     * `keys[<part>]` (when configured) → dedicated property below (`time` falls
     * back to {@see self::$timestamp}) → raw bytes. Assign a real style to
     * make it live.
     *
     * @var array<string, Style>
     */
    public array $keys = [];

    /**
     * Per-CONTEXT-key value styling: `$values['request_id']` overrides the
     * global `keys['value']` for that one key. Empty by default.
     *
     * @var array<string|int, Style>
     */
    public array $values = [];

    public Style $timestamp;
    public Style $prefix;
    public Style $caller;
    public Style $message;

    /**
     * When true, level labels are right-padded to {@see self::PAD_LENGTH}
     * characters (see {@see self::padLevelText()}) for column-aligned output.
     * Disabled by default so standard short labels keep their exact bytes.
     */
    public bool $padLevelText = false;

    private const PAD_LENGTH = 5;

    /** @var Styles|null Cached default styles instance */
    private static ?Styles $defaultInstance = null;

    public function __construct()
    {
        // Envelope styling is opt-in: every fallback defaults to the identity
        // (empty) style so TextFormatter emits raw bytes until configured.
        $this->timestamp = Style::new();
        $this->prefix = Style::new()->foreground(Color::ansi(5));
        $this->caller = Style::new()->foreground(Color::ansi(8));
        $this->message = Style::new();

        foreach (Level::cases() as $level) {
            $this->levels[$level->value] = match ($level) {
                Level::Debug => Style::new()->foreground(Color::ansi(8)),
                Level::Info  => Style::new()->foreground(Color::ansi(4)),
                Level::Warn  => Style::new()->foreground(Color::ansi(3)),
                Level::Error => Style::new()->foreground(Color::ansi(1))->bold(),
                Level::Fatal => Style::new()->foreground(Color::ansi(7))->background(Color::ansi(1))->bold(),
            };
        }

        // Per-field key styles (e.g., "time", "level", "message", "caller").
        // Identity defaults keep previously-dead knobs honest: configuring one
        // now actually changes output; leaving it untouched changes nothing.
        $this->keys['time']    = Style::new();
        $this->keys['level']   = Style::new();
        $this->keys['prefix']  = Style::new();
        $this->keys['caller']  = Style::new();
        $this->keys['message'] = Style::new();
        $this->keys['key']     = Style::new()->foreground(Color::ansi(8));
        $this->keys['value']  = Style::new();
    }

    /**
     * The process-wide shared Styles instance, now OPT-IN: Loggers and
     * TextFormatters built without styles land on an independent
     * {@see self::snapshot()} ([SL-35]), so this singleton only couples the
     * callers that hand it over explicitly (`new TextFormatter(styles:
     * Styles::default())`).
     *
     * WARNING — whatever rides it shares one MUTABLE object: tinting
     * `$logger->setStyles(Styles::default())->styles()->keys['time'] = ...`
     * recolors time for every logger and formatter deliberately riding the
     * default. For a private palette take {@see self::snapshot()} or a fresh
     * `new Styles()`.
     */
    public static function default(): self
    {
        return self::$defaultInstance ??= new self();
    }

    /**
     * An INDEPENDENT copy of the shared defaults.
     *
     * {@see self::default()} is a process-wide mutable singleton — editing
     * it recolors every logger and formatter explicitly riding it. snapshot()
     * hands back a copy
     * whose public arrays (and scalar knobs) can be mutated freely:
     * `$s = Styles::snapshot(); $s->levels[Level::Warn->value] = $style; $log->setStyles($s)` never leaks.
     * The Style objects inside are value objects (every mutator clones), so
     * sharing them between copies stays safe.
     */
    public static function snapshot(): self
    {
        return self::default()->copy();
    }

    /** Copy of this instance (fresh arrays, same Style objects). */
    public function copy(): self
    {
        $copy = new self();
        $copy->levels      = $this->levels;
        $copy->keys        = $this->keys;
        $copy->values      = $this->values;
        $copy->timestamp   = $this->timestamp;
        $copy->prefix      = $this->prefix;
        $copy->caller      = $this->caller;
        $copy->message     = $this->message;
        $copy->padLevelText = $this->padLevelText;

        return $copy;
    }

    /**
     * Pad a level label to a fixed width for visual alignment.
     * Mirrors charmbracelet/log's padLevelText.
     */
    public static function padLevelText(string $label): string
    {
        return \str_pad($label, self::PAD_LENGTH, ' ', STR_PAD_RIGHT);
    }

    /**
     * Get style for a specific field key.
     */
    public function keyStyle(string $field): Style
    {
        return $this->keys[$field] ?? Style::new();
    }
}

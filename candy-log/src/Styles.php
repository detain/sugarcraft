<?php

declare(strict_types=1);

namespace SugarCraft\Log;

use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Style;

/**
 * Styles for log level rendering in text output.
 * Mirrors charmbracelet/log's Styles / DefaultStyles.
 */
final class Styles
{
    /** @var array<int, Style> keyed by Level->value */
    public array $levels = [];

    /** @var array<string, Style> per-field key styling */
    public array $keys = [];

    /** @var array<string, Style> */
    public array $values = [];

    public Style $timestamp;
    public Style $prefix;
    public Style $caller;
    public Style $message;

    private const PAD_LENGTH = 5;

    public function __construct()
    {
        $this->timestamp = Style::new()->foreground(Color::ansi(8));
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

        // Per-field key styles (e.g., "time", "level", "msg", "caller")
        $this->keys['time']    = Style::new()->foreground(Color::ansi(8));
        $this->keys['level']   = Style::new()->foreground(Color::ansi(8));
        $this->keys['prefix']  = Style::new()->foreground(Color::ansi(5));
        $this->keys['caller']  = Style::new()->foreground(Color::ansi(8));
        $this->keys['message'] = Style::new();
        $this->keys['key']     = Style::new()->foreground(Color::ansi(8));
        $this->keys['value']  = Style::new();
    }

    public static function default(): self
    {
        return new self();
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

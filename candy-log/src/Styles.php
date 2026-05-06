<?php

declare(strict_types=1);

namespace CandyCore\Log;

use CandyCore\Sprinkles\Style;

/**
 * Styles for log level rendering in text output.
 * Mirrors charmbracelet/log's Styles / DefaultStyles.
 */
final class Styles
{
    /** @var array<Level, Style> */
    public array $levels = [];

    /** @var array<string, Style> */
    public array $keys = [];

    /** @var array<string, Style> */
    public array $values = [];

    public Style $timestamp;
    public Style $prefix;
    public Style $caller;
    public Style $message;

    public function __construct()
    {
        $this->timestamp = Style::new()->foreground(8);
        $this->prefix = Style::new()->foreground(5);
        $this->caller = Style::new()->foreground(8);
        $this->message = Style::new();

        foreach (Level::cases() as $level) {
            $this->levels[$level] = match ($level) {
                Level::Debug => Style::new()->foreground(8),
                Level::Info  => Style::new()->foreground(4),
                Level::Warn  => Style::new()->foreground(3),
                Level::Error => Style::new()->foreground(1)->bold(),
                Level::Fatal => Style::new()->foreground(7)->background(1)->bold(),
            };
        }
    }

    public static function default(): self
    {
        return new self();
    }
}

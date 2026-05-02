<?php

declare(strict_types=1);

namespace CandyCore\Shell\Log;

use CandyCore\Core\Util\Color;
use CandyCore\Sprinkles\Style;

/**
 * Severity levels for {@see \CandyCore\Shell\Command\LogCommand}, with
 * the styled badge each one emits.
 */
enum LogLevel: string
{
    case Debug = 'debug';
    case Info  = 'info';
    case Warn  = 'warn';
    case Error = 'error';
    case Fatal = 'fatal';

    public static function fromString(string $name): self
    {
        return match (strtolower($name)) {
            'debug', 'dbg'           => self::Debug,
            'info', ''                => self::Info,
            'warn', 'warning', 'wrn' => self::Warn,
            'error', 'err'           => self::Error,
            'fatal', 'crit'          => self::Fatal,
            default => throw new \InvalidArgumentException("unknown log level: $name"),
        };
    }

    public function badge(): string
    {
        return strtoupper($this->value);
    }

    /**
     * Numeric ordering used by the `--min-level` filter: Debug=0,
     * Info=1, Warn=2, Error=3, Fatal=4.
     */
    public function order(): int
    {
        return match ($this) {
            self::Debug => 0,
            self::Info  => 1,
            self::Warn  => 2,
            self::Error => 3,
            self::Fatal => 4,
        };
    }

    public function style(): Style
    {
        $color = match ($this) {
            self::Debug => Color::ansi(8),   // bright black / grey
            self::Info  => Color::ansi(12),  // bright blue
            self::Warn  => Color::ansi(11),  // bright yellow
            self::Error => Color::ansi(9),   // bright red
            self::Fatal => Color::ansi(13),  // bright magenta
        };
        return Style::new()->bold()->foreground($color);
    }
}

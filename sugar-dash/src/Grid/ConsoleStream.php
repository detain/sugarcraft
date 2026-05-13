<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Color;

enum ConsoleStream: string
{
    case Stdout = 'stdout';
    case Stderr = 'stderr';
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Error = 'error';
    case Debug = 'debug';
    case Raw = 'raw';

    public function defaultColor(): Color
    {
        return match ($this) {
            self::Stdout => Color::hex('#F9FAFB'),
            self::Stderr => Color::hex('#F38BA8'),
            self::Info => Color::hex('#89B4FA'),
            self::Success => Color::hex('#A6E3A1'),
            self::Warning => Color::hex('#F9E2AF'),
            self::Error => Color::hex('#F38BA8'),
            self::Debug => Color::hex('#6C7086'),
            self::Raw => Color::hex('#CDD6F4'),
        };
    }

    public function prefix(): string
    {
        return match ($this) {
            self::Stdout => '',
            self::Stderr => '',
            self::Info => '[INFO]',
            self::Success => '[OK]',
            self::Warning => '[WARN]',
            self::Error => '[ERROR]',
            self::Debug => '[DEBUG]',
            self::Raw => '',
        };
    }

    public function isError(): bool
    {
        return match ($this) {
            self::Stderr, self::Error, self::Warning => true,
            default => false,
        };
    }

    public function sortOrder(): int
    {
        return match ($this) {
            self::Raw => 0,
            self::Stdout => 1,
            self::Debug => 2,
            self::Info => 3,
            self::Success => 4,
            self::Warning => 5,
            self::Stderr => 6,
            self::Error => 7,
        };
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Toast;

/**
 * A single notification DTO held by NotificationQueue.
 *
 * Mirrors the notification DTO pattern from Homedash.
 */
final class Notification
{
    public function __construct(
        public readonly string $message,
        public readonly Level $level = Level::Info,
        public readonly ?string $title = null,
    ) {}

    public static function info(string $message, ?string $title = null): self
    {
        return new self(message: $message, level: Level::Info, title: $title);
    }

    public static function warning(string $message, ?string $title = null): self
    {
        return new self(message: $message, level: Level::Warning, title: $title);
    }

    public static function error(string $message, ?string $title = null): self
    {
        return new self(message: $message, level: Level::Error, title: $title);
    }

    public static function success(string $message, ?string $title = null): self
    {
        return new self(message: $message, level: Level::Success, title: $title);
    }
}

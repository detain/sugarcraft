<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\System;

use SugarCraft\Core\Util\Color;

readonly class ConsoleEntry
{
    public function __construct(
        public string $message,
        public ConsoleStream $stream = ConsoleStream::Stdout,
        public ?Color $color = null,
    ) {}

    public static function create(
        string $message,
        ConsoleStream $stream = ConsoleStream::Stdout,
        ?Color $color = null,
    ): self {
        return new self(
            message: $message,
            stream: $stream,
            color: $color ?? $stream->defaultColor(),
        );
    }

    public static function info(string $message): self
    {
        return self::create($message, ConsoleStream::Info);
    }

    public static function success(string $message): self
    {
        return self::create($message, ConsoleStream::Success);
    }

    public static function warning(string $message): self
    {
        return self::create($message, ConsoleStream::Warning);
    }

    public static function error(string $message): self
    {
        return self::create($message, ConsoleStream::Error);
    }

    public static function debug(string $message): self
    {
        return self::create($message, ConsoleStream::Debug);
    }

    public static function raw(string $message): self
    {
        return self::create($message, ConsoleStream::Raw);
    }
}
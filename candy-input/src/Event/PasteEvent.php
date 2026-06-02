<?php

declare(strict_types=1);

namespace SugarCraft\Input\Event;

use SugarCraft\Input\Event;

/**
 * A bracketed paste event — content being pasted from the clipboard.
 *
 * Starts with CSI 200 ~ and ends with CSI 201 ~.
 * Content is capped at 1 MiB to prevent OOM from hostile input.
 *
 * @see Mirrors charmbracelet/bubbletea (input handling).
 * @readonly
 */
final readonly class PasteEvent implements Event
{
    public const MAX_SIZE = 1 << 20; // 1 MiB

    public function __construct(
        public string $content,
    ) {}

    public static function truncate(string $content): self
    {
        if (strlen($content) > self::MAX_SIZE) {
            $content = substr($content, 0, self::MAX_SIZE);
        }

        return new self($content);
    }
}

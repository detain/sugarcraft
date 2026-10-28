<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\MessagePicker;

/**
 * What a key in the message picker ({@see MessagePicker}) asks its host to
 * do: close the overlay, or rewind the conversation to the highlighted
 * checkpoint. Accept carries the rewind position — the same $stepsBack the
 * `--chat` form of `/rewind` counts, newest checkpoint first — because the
 * host feeds it straight to that command; Close carries nothing.
 *
 * Shaped like {@see \SugarCraft\Code\Tui\DirectoryPicker\DirectoryPickerAction}:
 * a closed set of named kinds, built only through the static factories, so
 * the host branches on {@see self::$kind} and never guesses at payloads.
 */
final class MessagePickerAction
{
    public const CLOSE = 'close';

    public const ACCEPT = 'accept';

    private function __construct(
        public readonly string $kind,
        public readonly int $stepsBack,
    ) {
    }

    /**
     * Rewind the conversation to the checkpoint at rewind position
     * $stepsBack (one-based, newest checkpoint first).
     */
    public static function accept(int $stepsBack): self
    {
        return new self(self::ACCEPT, $stepsBack);
    }

    /** Dismiss the overlay; the conversation stays where it is. */
    public static function close(): self
    {
        return new self(self::CLOSE, 0);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Input\Event;

use SugarCraft\Input\Event;
use SugarCraft\Input\Lang;

/**
 * A bracketed paste event — content being pasted from the clipboard.
 *
 * Starts with CSI 200 ~ and ends with CSI 201 ~.
 * Content is capped at 1 MiB to prevent OOM from hostile input.
 *
 * Truncation used to be silent: a clipped paste was byte-indistinguishable
 * from an intact one at exactly the cap. truncate() now records the clip in
 * {@see self::$truncated}, and {@see self::truncationNotice()} renders the
 * library's translated 'paste.truncated' message — wiring the lang key that
 * previously had no production consumer.
 *
 * @see Mirrors charmbracelet/bubbletea (input handling).
 * @readonly
 */
final readonly class PasteEvent implements Event
{
    public const MAX_SIZE = 1 << 20; // 1 MiB

    public function __construct(
        public string $content,
        /** True only when content was clipped to MAX_SIZE by truncate() */
        public bool $truncated = false,
    ) {}

    public static function truncate(string $content): self
    {
        if (strlen($content) > self::MAX_SIZE) {
            return new self(substr($content, 0, self::MAX_SIZE), true);
        }

        return new self($content);
    }

    /**
     * Translated notice describing the cap, or null when the paste is intact.
     */
    public function truncationNotice(): ?string
    {
        if (!$this->truncated) {
            return null;
        }

        return Lang::t('paste.truncated', ['bytes' => self::MAX_SIZE]);
    }
}

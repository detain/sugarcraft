<?php

declare(strict_types=1);

namespace SugarCraft\Input\Event;

use SugarCraft\Input\Event;
use SugarCraft\Input\Lang;

/**
 * A bracketed paste event — content being pasted from the clipboard.
 *
 * Starts with CSI 200 ~ and ends with CSI 201 ~.
 * Content is capped at 1 MiB to prevent OOM from hostile input, and the
 * cap is enforced in the constructor itself: no construction path, host
 * synthetic or decoder-emitted, can carry more.
 *
 * A clipped paste is never silent: the constructor records the clip in
 * $truncated, and truncationNotice() renders the library's translated
 * 'paste.truncated' one-liner suitable for display.
 *
 * Mirrors charmbracelet/bubbletea's input handling.
 * @readonly
 */
final readonly class PasteEvent implements Event
{
    public const MAX_SIZE = 1 << 20; // 1 MiB

    public string $content;

    /** True only when content was clipped to MAX_SIZE */
    public bool $truncated;

    public function __construct(string $content, bool $truncated = false)
    {
        if (strlen($content) > self::MAX_SIZE) {
            $content = substr($content, 0, self::MAX_SIZE);
            $truncated = true;
        }

        $this->content = $content;
        $this->truncated = $truncated;
    }

    /**
     * Clip a paste to MAX_SIZE. Since the cap binds in the constructor this
     * is a plain construction; the factory is kept for its name at call sites.
     */
    public static function truncate(string $content): self
    {
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

        return Lang::t('paste.truncated', ['bytes' => self::MAX_SIZE, 'mib' => self::MAX_SIZE >> 20]);
    }
}

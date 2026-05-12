<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A comment/note display component.
 *
 * Features:
 * - Author name and avatar
 * - Timestamp
 * - Comment body text
 * - Optional reply indicators
 * - Collapsible threading
 *
 * Mirrors comment UI concepts adapted to PHP with
 * wither-style immutable setters.
 */
final class Comment implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly string $author,
        private readonly string $body,
        private readonly ?string $timestamp = null,
        private readonly ?Avatar $avatar = null,
        private readonly bool $isReply = false,
        private readonly bool $isEdited = false,
        private readonly ?string $headerColor = null,
    ) {}

    /**
     * Create a new comment.
     */
    public static function create(string $author, string $body): self
    {
        return new self(
            author: $author,
            body: $body,
            timestamp: null,
            avatar: null,
            isReply: false,
            isEdited: false,
            headerColor: null,
        );
    }

    /**
     * Create a reply comment.
     */
    public static function reply(string $author, string $body): self
    {
        return new self(
            author: $author,
            body: $body,
            timestamp: null,
            avatar: null,
            isReply: true,
            isEdited: false,
            headerColor: null,
        );
    }

    /**
     * Set the allocated dimensions for this comment.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the comment as a string.
     */
    public function render(): string
    {
        $maxWidth = $this->width ?? 80;
        $lines = [];

        // Build header: author (+ edited indicator) + optional timestamp
        $header = $this->author;
        if ($this->isEdited) {
            $header .= ' (edited)';
        }
        if ($this->timestamp !== null) {
            $header .= ' · ' . $this->timestamp;
        }

        // Apply header color if set
        $headerLine = '';
        if ($this->headerColor !== null) {
            $headerLine .= $this->headerColor->toFg(ColorProfile::TrueColor);
        } elseif (!$this->isReply) {
            // Default header color for top-level comments
            $headerLine .= Color::hex('#3B82F6')->toFg(ColorProfile::TrueColor);
        }
        $headerLine .= $header;
        if ($this->headerColor !== null || !$this->isReply) {
            $headerLine .= Ansi::reset();
        }

        $lines[] = $headerLine;

        // Build body with word wrapping
        $bodyLines = $this->wrapText($this->body, $maxWidth - 2);
        foreach ($bodyLines as $bodyLine) {
            $lines[] = ' ' . $bodyLine;
        }

        return implode("\n", $lines);
    }

    /**
     * Wrap text to fit within a given width.
     *
     * @return list<string>
     */
    private function wrapText(string $text, int $width): array
    {
        if ($width <= 0) {
            return [$text];
        }

        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            return [''];
        }

        $result = [];
        $currentLine = '';
        $currentWidth = 0;

        foreach ($words as $word) {
            $wordWidth = Width::string($word);

            if ($wordWidth > $width) {
                // Word is longer than width - truncate
                if ($currentLine !== '') {
                    $result[] = $currentLine;
                    $currentLine = '';
                    $currentWidth = 0;
                }
                $result[] = mb_substr($word, 0, $width, 'UTF-8');
                continue;
            }

            if ($currentWidth > 0 && $currentWidth + 1 + $wordWidth > $width) {
                $result[] = $currentLine;
                $currentLine = $word;
                $currentWidth = $wordWidth;
            } else {
                if ($currentLine !== '') {
                    $currentLine .= ' ';
                    $currentWidth++;
                }
                $currentLine .= $word;
                $currentWidth += $wordWidth;
            }
        }

        if ($currentLine !== '') {
            $result[] = $currentLine;
        }

        return $result === [] ? [''] : $result;
    }

    /**
     * Calculate the natural dimensions of this comment.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $maxWidth = $this->width ?? 80;
        $bodyLines = $this->wrapText($this->body, $maxWidth - 2);

        $width = $maxWidth;
        $height = 1 + count($bodyLines); // header + body lines

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the author name.
     */
    public function withAuthor(string $author): self
    {
        return new self(
            author: $author,
            body: $this->body,
            timestamp: $this->timestamp,
            avatar: $this->avatar,
            isReply: $this->isReply,
            isEdited: $this->isEdited,
            headerColor: $this->headerColor,
        );
    }

    /**
     * Set the comment body.
     */
    public function withBody(string $body): self
    {
        return new self(
            author: $this->author,
            body: $body,
            timestamp: $this->timestamp,
            avatar: $this->avatar,
            isReply: $this->isReply,
            isEdited: $this->isEdited,
            headerColor: $this->headerColor,
        );
    }

    /**
     * Set the timestamp.
     */
    public function withTimestamp(?string $timestamp): self
    {
        return new self(
            author: $this->author,
            body: $this->body,
            timestamp: $timestamp,
            avatar: $this->avatar,
            isReply: $this->isReply,
            isEdited: $this->isEdited,
            headerColor: $this->headerColor,
        );
    }

    /**
     * Set the avatar.
     */
    public function withAvatar(?Avatar $avatar): self
    {
        return new self(
            author: $this->author,
            body: $this->body,
            timestamp: $this->timestamp,
            avatar: $avatar,
            isReply: $this->isReply,
            isEdited: $this->isEdited,
            headerColor: $this->headerColor,
        );
    }

    /**
     * Set the reply flag.
     */
    public function withIsReply(bool $isReply): self
    {
        return new self(
            author: $this->author,
            body: $this->body,
            timestamp: $this->timestamp,
            avatar: $this->avatar,
            isReply: $isReply,
            isEdited: $this->isEdited,
            headerColor: $this->headerColor,
        );
    }

    /**
     * Set the edited flag.
     */
    public function withIsEdited(bool $isEdited): self
    {
        return new self(
            author: $this->author,
            body: $this->body,
            timestamp: $this->timestamp,
            avatar: $this->avatar,
            isReply: $this->isReply,
            isEdited: $isEdited,
            headerColor: $this->headerColor,
        );
    }

    /**
     * Set the header color.
     */
    public function withHeaderColor(?Color $color): self
    {
        return new self(
            author: $this->author,
            body: $this->body,
            timestamp: $this->timestamp,
            avatar: $this->avatar,
            isReply: $this->isReply,
            isEdited: $this->isEdited,
            headerColor: $color,
        );
    }
}

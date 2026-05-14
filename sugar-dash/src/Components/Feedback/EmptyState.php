<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Feedback;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * An empty state placeholder component.
 *
 * Features:
 * - Centered icon/graphic
 * - Title message
 * - Optional description
 * - Optional action hint
 * - Customizable colors
 *
 * Mirrors empty state UI patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class EmptyState implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly string $icon = '📭',
        private readonly string $title = 'Nothing here yet',
        private readonly ?string $description = null,
        private readonly ?string $action = null,
        private readonly ?Color $iconColor = null,
        private readonly ?Color $titleColor = null,
        private readonly ?Color $descriptionColor = null,
    ) {}

    /**
     * Create a new empty state with default message.
     */
    public static function new(): self
    {
        return new self(
            icon: '📭',
            title: 'Nothing here yet',
            description: null,
            action: null,
            iconColor: Color::hex('#9CA3AF'),
            titleColor: Color::hex('#374151'),
            descriptionColor: Color::hex('#6B7280'),
        );
    }

    /**
     * Create an empty state for no results.
     */
    public static function noResults(): self
    {
        return new self(
            icon: '🔍',
            title: 'No results found',
            description: 'Try adjusting your search or filters',
            action: null,
            iconColor: Color::hex('#9CA3AF'),
            titleColor: Color::hex('#374151'),
            descriptionColor: Color::hex('#6B7280'),
        );
    }

    /**
     * Create an empty state for errors.
     */
    public static function error(): self
    {
        return new self(
            icon: '⚠️',
            title: 'Something went wrong',
            description: 'Please try again later',
            action: null,
            iconColor: Color::hex('#EF4444'),
            titleColor: Color::hex('#374151'),
            descriptionColor: Color::hex('#6B7280'),
        );
    }

    /**
     * Create an empty state for no data.
     */
    public static function noData(): self
    {
        return new self(
            icon: '📊',
            title: 'No data available',
            description: 'Data will appear here once available',
            action: null,
            iconColor: Color::hex('#9CA3AF'),
            titleColor: Color::hex('#374151'),
            descriptionColor: Color::hex('#6B7280'),
        );
    }

    /**
     * Set the allocated dimensions for this empty state.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the empty state as a string.
     */
    public function render(): string
    {
        $maxWidth = $this->width ?? 60;
        $lines = [];

        // Center and render icon
        $iconWidth = Width::string($this->icon);
        $iconPadding = (int) floor(($maxWidth - $iconWidth) / 2);
        $iconLine = str_repeat(' ', max(0, $iconPadding)) . $this->icon;
        $lines[] = $iconLine;

        // Center and render title
        $titleWidth = Width::string($this->title);
        $titlePadding = (int) floor(($maxWidth - $titleWidth) / 2);
        $titleLine = str_repeat(' ', max(0, $titlePadding)) . $this->title;
        if ($this->titleColor !== null) {
            $titleLine = $this->titleColor->toFg(ColorProfile::TrueColor) . $titleLine . Ansi::reset();
        }
        $lines[] = $titleLine;

        // Center and render description if present
        if ($this->description !== null && $this->description !== '') {
            $descLines = $this->wrapText($this->description, $maxWidth - 4);
            foreach ($descLines as $descLine) {
                $descWidth = Width::string($descLine);
                $descPadding = (int) floor(($maxWidth - $descWidth) / 2);
                $paddedLine = str_repeat(' ', max(0, $descPadding)) . $descLine;
                if ($this->descriptionColor !== null) {
                    $paddedLine = $this->descriptionColor->toFg(ColorProfile::TrueColor) . $paddedLine . Ansi::reset();
                }
                $lines[] = $paddedLine;
            }
        }

        // Center and render action hint if present
        if ($this->action !== null && $this->action !== '') {
            $actionWidth = Width::string($this->action);
            $actionPadding = (int) floor(($maxWidth - $actionWidth) / 2);
            $actionLine = str_repeat(' ', max(0, $actionPadding)) . $this->action;
            if ($this->descriptionColor !== null) {
                $actionLine = $this->descriptionColor->toFg(ColorProfile::TrueColor) . $actionLine . Ansi::reset();
            }
            $lines[] = $actionLine;
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
     * Calculate the natural dimensions of this empty state.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $maxWidth = $this->width ?? 60;

        $height = 2; // icon + title
        if ($this->description !== null && $this->description !== '') {
            $height += count($this->wrapText($this->description, $maxWidth - 4));
        }
        if ($this->action !== null && $this->action !== '') {
            $height += 1;
        }

        return [$maxWidth, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the icon.
     */
    public function withIcon(string $icon): self
    {
        return new self(
            icon: $icon,
            title: $this->title,
            description: $this->description,
            action: $this->action,
            iconColor: $this->iconColor,
            titleColor: $this->titleColor,
            descriptionColor: $this->descriptionColor,
        );
    }

    /**
     * Set the title.
     */
    public function withTitle(string $title): self
    {
        return new self(
            icon: $this->icon,
            title: $title,
            description: $this->description,
            action: $this->action,
            iconColor: $this->iconColor,
            titleColor: $this->titleColor,
            descriptionColor: $this->descriptionColor,
        );
    }

    /**
     * Set the description.
     */
    public function withDescription(?string $description): self
    {
        return new self(
            icon: $this->icon,
            title: $this->title,
            description: $description,
            action: $this->action,
            iconColor: $this->iconColor,
            titleColor: $this->titleColor,
            descriptionColor: $this->descriptionColor,
        );
    }

    /**
     * Set the action hint.
     */
    public function withAction(?string $action): self
    {
        return new self(
            icon: $this->icon,
            title: $this->title,
            description: $this->description,
            action: $action,
            iconColor: $this->iconColor,
            titleColor: $this->titleColor,
            descriptionColor: $this->descriptionColor,
        );
    }

    /**
     * Set the icon color.
     */
    public function withIconColor(?Color $color): self
    {
        return new self(
            icon: $this->icon,
            title: $this->title,
            description: $this->description,
            action: $this->action,
            iconColor: $color,
            titleColor: $this->titleColor,
            descriptionColor: $this->descriptionColor,
        );
    }

    /**
     * Set the title color.
     */
    public function withTitleColor(?Color $color): self
    {
        return new self(
            icon: $this->icon,
            title: $this->title,
            description: $this->description,
            action: $this->action,
            iconColor: $this->iconColor,
            titleColor: $color,
            descriptionColor: $this->descriptionColor,
        );
    }

    /**
     * Set the description color.
     */
    public function withDescriptionColor(?Color $color): self
    {
        return new self(
            icon: $this->icon,
            title: $this->title,
            description: $this->description,
            action: $this->action,
            iconColor: $this->iconColor,
            titleColor: $this->titleColor,
            descriptionColor: $color,
        );
    }
}

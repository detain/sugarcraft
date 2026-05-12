<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A group of avatars displayed in a row.
 *
 * Features:
 * - Display multiple avatars in a horizontal row
 * - Overlap avatars slightly for compact display
 * - Show a "+N" indicator when exceeding max display count
 * - Support size configuration for all avatars in group
 * - Optional stack direction (horizontal only for now)
 *
 * Mirrors avatar-group UI concepts adapted to PHP with wither-style immutable setters.
 */
final class AvatarGroup implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<Avatar> $avatars
     */
    public function __construct(
        private readonly array $avatars = [],
        private readonly int $overlap = 2,
        private readonly ?int $maxDisplay = null,
        private readonly ?string $overflowIndicator = null,
        private readonly ?Color $overflowBackgroundColor = null,
        private readonly ?Color $overflowForegroundColor = null,
    ) {}

    /**
     * Create a new avatar group from a list of names.
     *
     * @param list<string> $names
     */
    public static function fromNames(array $names): self
    {
        $avatars = array_map(
            fn(string $name): Avatar => Avatar::fromName($name),
            $names
        );

        return new self(
            avatars: $avatars,
            overlap: 2,
            maxDisplay: null,
            overflowIndicator: null,
            overflowBackgroundColor: Color::hex('#6B7280'),
            overflowForegroundColor: Color::hex('#FFFFFF'),
        );
    }

    /**
     * Create a compact avatar group showing max N avatars.
     *
     * @param list<string> $names
     */
    public static function compact(array $names, int $maxDisplay = 4): self
    {
        $avatars = array_map(
            fn(string $name): Avatar => Avatar::fromName($name),
            $names
        );

        $overflowCount = count($names) - $maxDisplay;
        $overflowIndicator = $overflowCount > 0 ? '+' . $overflowCount : null;

        return new self(
            avatars: $avatars,
            overlap: 2,
            maxDisplay: $maxDisplay,
            overflowIndicator: $overflowIndicator,
            overflowBackgroundColor: Color::hex('#6B7280'),
            overflowForegroundColor: Color::hex('#FFFFFF'),
        );
    }

    /**
     * Set the allocated dimensions for this avatar group.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the avatar group as a string.
     */
    public function render(): string
    {
        if ($this->avatars === []) {
            return '';
        }

        $displayAvatars = $this->avatars;
        $hasOverflow = false;

        if ($this->maxDisplay !== null && count($displayAvatars) > $this->maxDisplay) {
            $displayAvatars = array_slice($displayAvatars, 0, $this->maxDisplay);
            $hasOverflow = true;
        }

        $result = '';
        $avatarSize = $this->getAvatarSize();

        // Render avatars from left to right
        for ($i = 0; $i < count($displayAvatars); $i++) {
            $avatar = $displayAvatars[$i];
            if ($avatar instanceof Sizer) {
                $avatar = $avatar->setSize($avatarSize, 1);
            }
            $result .= $avatar->render();

            if ($i < count($displayAvatars) - 1) {
                $result .= str_repeat(' ', max(0, $this->overlap));
            }
        }

        // Render overflow indicator on the right if present
        if ($hasOverflow && $this->overflowIndicator !== null) {
            $result .= str_repeat(' ', max(0, $this->overlap));
            $result .= $this->renderOverflowIndicator();
        }

        return $result;
    }

    /**
     * Render the overflow indicator avatar.
     */
    private function renderOverflowIndicator(): string
    {
        $indicator = $this->overflowIndicator ?? '';
        $avatarSize = $this->getAvatarSize();

        $result = '';

        if ($this->overflowBackgroundColor !== null) {
            $result .= $this->overflowBackgroundColor->toBg(ColorProfile::TrueColor);
        }
        if ($this->overflowForegroundColor !== null) {
            $result .= $this->overflowForegroundColor->toFg(ColorProfile::TrueColor);
        }

        // Calculate padding to center the indicator
        $indicatorWidth = Width::string($indicator);
        $padding = $avatarSize - $indicatorWidth;
        $leftPad = (int) floor($padding / 2);
        $rightPad = $padding - $leftPad;

        $result .= str_repeat(' ', $leftPad);
        $result .= $indicator;
        $result .= str_repeat(' ', $rightPad);

        $result .= Ansi::reset();

        return $result;
    }

    /**
     * Get the common avatar size across all avatars.
     */
    private function getAvatarSize(): int
    {
        // All avatars created via Avatar::fromName() are medium size by default
        return Avatar::sizeToPixels(Avatar::SIZE_MEDIUM);
    }

    /**
     * Calculate the natural dimensions of this avatar group.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        if ($this->avatars === []) {
            return [0, 0];
        }

        $displayCount = $this->maxDisplay !== null
            ? min(count($this->avatars), $this->maxDisplay)
            : count($this->avatars);

        $avatarSize = $this->getAvatarSize();
        $width = $displayCount * ($avatarSize - $this->overlap) + $this->overlap;

        // Add space for overflow indicator
        if ($this->maxDisplay !== null && count($this->avatars) > $this->maxDisplay) {
            $overflowWidth = $this->getOverflowWidth();
            $width += $overflowWidth + $this->overlap;
        }

        $height = $avatarSize;

        return [$width, $height];
    }

    /**
     * Get the width of the overflow indicator.
     */
    private function getOverflowWidth(): int
    {
        if ($this->overflowIndicator === null) {
            return 0;
        }

        $avatarSize = $this->getAvatarSize();
        return $avatarSize;
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the avatars in this group.
     *
     * @param list<Avatar> $avatars
     */
    public function withAvatars(array $avatars): self
    {
        return new self(
            avatars: $avatars,
            overlap: $this->overlap,
            maxDisplay: $this->maxDisplay,
            overflowIndicator: $this->overflowIndicator,
            overflowBackgroundColor: $this->overflowBackgroundColor,
            overflowForegroundColor: $this->overflowForegroundColor,
        );
    }

    /**
     * Add an avatar to this group.
     */
    public function withAppended(Avatar $avatar): self
    {
        return new self(
            avatars: [...$this->avatars, $avatar],
            overlap: $this->overlap,
            maxDisplay: $this->maxDisplay,
            overflowIndicator: $this->overflowIndicator,
            overflowBackgroundColor: $this->overflowBackgroundColor,
            overflowForegroundColor: $this->overflowForegroundColor,
        );
    }

    /**
     * Set the overlap amount between avatars.
     */
    public function withOverlap(int $overlap): self
    {
        return new self(
            avatars: $this->avatars,
            overlap: max(0, $overlap),
            maxDisplay: $this->maxDisplay,
            overflowIndicator: $this->overflowIndicator,
            overflowBackgroundColor: $this->overflowBackgroundColor,
            overflowForegroundColor: $this->overflowForegroundColor,
        );
    }

    /**
     * Set the maximum number of avatars to display.
     */
    public function withMaxDisplay(?int $maxDisplay): self
    {
        return new self(
            avatars: $this->avatars,
            overlap: $this->overlap,
            maxDisplay: $maxDisplay,
            overflowIndicator: $this->overflowIndicator,
            overflowBackgroundColor: $this->overflowBackgroundColor,
            overflowForegroundColor: $this->overflowForegroundColor,
        );
    }

    /**
     * Set the overflow indicator text.
     */
    public function withOverflowIndicator(?string $indicator): self
    {
        return new self(
            avatars: $this->avatars,
            overlap: $this->overlap,
            maxDisplay: $this->maxDisplay,
            overflowIndicator: $indicator,
            overflowBackgroundColor: $this->overflowBackgroundColor,
            overflowForegroundColor: $this->overflowForegroundColor,
        );
    }

    /**
     * Set the overflow indicator colors.
     */
    public function withOverflowColors(?Color $background, ?Color $foreground): self
    {
        return new self(
            avatars: $this->avatars,
            overlap: $this->overlap,
            maxDisplay: $this->maxDisplay,
            overflowIndicator: $this->overflowIndicator,
            overflowBackgroundColor: $background,
            overflowForegroundColor: $foreground,
        );
    }
}

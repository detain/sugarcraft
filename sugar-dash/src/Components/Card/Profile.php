<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Card;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A user profile card component.
 *
 * Displays a user profile with avatar, name, role, bio, and optional
 * social links or stats. Supports various layout styles.
 *
 * Mirrors profile-card concepts adapted to PHP with wither-style immutable setters.
 */
final class Profile implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly string $name = '',
        private readonly string $role = '',
        private readonly string $bio = '',
        private readonly ?string $avatar = null,
        private readonly ?string $email = null,
        private readonly ?string $location = null,
        private readonly ?Color $avatarBgColor = null,
        private readonly ?Color $nameColor = null,
        private readonly ?Color $roleColor = null,
        private readonly ?Color $bioColor = null,
        private readonly ?Color $borderColor = null,
        private readonly string $layout = 'vertical',
    ) {}

    /**
     * Create a new profile card with default styling.
     */
    public static function new(
        string $name,
        string $role,
        string $bio = '',
        ?string $avatar = null,
    ): self {
        return new self(
            name: $name,
            role: $role,
            bio: $bio,
            avatar: $avatar,
            avatarBgColor: Color::hex('#7C3AED'),
            nameColor: Color::hex('#FAFAFA'),
            roleColor: Color::hex('#A78BFA'),
            bioColor: Color::hex('#A1A1AA'),
            borderColor: Color::hex('#27272A'),
            layout: 'vertical',
        );
    }

    /**
     * Create a horizontal layout profile card.
     */
    public static function horizontal(
        string $name,
        string $role,
        string $bio = '',
        ?string $avatar = null,
    ): self {
        return new self(
            name: $name,
            role: $role,
            bio: $bio,
            avatar: $avatar,
            avatarBgColor: Color::hex('#7C3AED'),
            nameColor: Color::hex('#FAFAFA'),
            roleColor: Color::hex('#A78BFA'),
            bioColor: Color::hex('#A1A1AA'),
            borderColor: Color::hex('#27272A'),
            layout: 'horizontal',
        );
    }

    /**
     * Create a compact profile card.
     */
    public static function compact(
        string $name,
        string $role,
        ?string $avatar = null,
    ): self {
        return new self(
            name: $name,
            role: $role,
            bio: '',
            avatar: $avatar,
            avatarBgColor: Color::hex('#7C3AED'),
            nameColor: Color::hex('#FAFAFA'),
            roleColor: Color::hex('#A78BFA'),
            bioColor: null,
            borderColor: Color::hex('#27272A'),
            layout: 'compact',
        );
    }

    /**
     * Set the allocated dimensions for this profile.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the profile card as a string.
     */
    public function render(): string
    {
        $useWidth = $this->getWidth();

        if ($this->layout === 'horizontal') {
            return $this->renderHorizontal($useWidth);
        }

        if ($this->layout === 'compact') {
            return $this->renderCompact($useWidth);
        }

        return $this->renderVertical($useWidth);
    }

    /**
     * Render vertical layout.
     */
    private function renderVertical(int $width): string
    {
        $lines = [];

        // Top border
        if ($this->borderColor !== null) {
            $lines[] = $this->borderColor->toFg(ColorProfile::TrueColor) . str_repeat('─', $width) . Ansi::reset();
        } else {
            $lines[] = str_repeat('─', $width);
        }

        // Avatar
        if ($this->avatar !== null) {
            $avatarDisplay = '[' . str_pad($this->avatar, 4, ' ', STR_PAD_BOTH) . ']';
            if ($this->avatarBgColor !== null) {
                $lines[] = str_repeat(' ', (int) floor(($width - 10) / 2))
                    . $this->avatarBgColor->toBg(ColorProfile::TrueColor)
                    . $avatarDisplay
                    . Ansi::reset();
            } else {
                $lines[] = str_repeat(' ', (int) floor(($width - 10) / 2)) . $avatarDisplay;
            }
            $lines[] = '';
        }

        // Name
        if ($this->name !== '') {
            if ($this->nameColor !== null) {
                $lines[] = $this->nameColor->toFg(ColorProfile::TrueColor);
            }
            $lines[] = str_pad($this->name, $width);
            $lines[] = Ansi::reset();
        }

        // Role
        if ($this->role !== '') {
            if ($this->roleColor !== null) {
                $lines[] = $this->roleColor->toFg(ColorProfile::TrueColor);
            }
            $lines[] = str_pad($this->role, $width);
            $lines[] = Ansi::reset();
        }

        // Bio
        if ($this->bio !== '') {
            $bioLines = $this->wordWrap($this->bio, $width - 2);
            foreach ($bioLines as $bioLine) {
                if ($this->bioColor !== null) {
                    $lines[] = $this->bioColor->toFg(ColorProfile::TrueColor);
                }
                $lines[] = ' ' . str_pad($bioLine, $width - 1);
                $lines[] = Ansi::reset();
            }
        }

        // Contact info
        $contactLines = $this->getContactLines($width);
        $lines = array_merge($lines, $contactLines);

        // Bottom border
        if ($this->borderColor !== null) {
            $lines[] = $this->borderColor->toFg(ColorProfile::TrueColor) . str_repeat('─', $width) . Ansi::reset();
        } else {
            $lines[] = str_repeat('─', $width);
        }

        return implode("\n", $lines);
    }

    /**
     * Render horizontal layout.
     */
    private function renderHorizontal(int $width): string
    {
        $lines = [];

        // Top border
        if ($this->borderColor !== null) {
            $lines[] = $this->borderColor->toFg(ColorProfile::TrueColor) . str_repeat('─', $width) . Ansi::reset();
        } else {
            $lines[] = str_repeat('─', $width);
        }

        // Avatar + info on same line
        $content = '';
        if ($this->avatar !== null) {
            $avatarDisplay = '[' . str_pad($this->avatar, 4, ' ', STR_PAD_BOTH) . ']';
            if ($this->avatarBgColor !== null) {
                $content .= $this->avatarBgColor->toBg(ColorProfile::TrueColor);
            }
            $content .= $avatarDisplay . ' ';
            $content .= Ansi::reset() . ' ';
        }

        // Name and role
        if ($this->nameColor !== null) {
            $content .= $this->nameColor->toFg(ColorProfile::TrueColor);
        }
        $content .= $this->name;
        $content .= Ansi::reset();

        if ($this->role !== '') {
            if ($this->roleColor !== null) {
                $content .= ' ';
                $content .= $this->roleColor->toFg(ColorProfile::TrueColor);
            }
            $content .= $this->role;
            $content .= Ansi::reset();
        }

        $lines[] = str_pad($content, $width);

        // Bio on second line
        if ($this->bio !== '') {
            $bioLines = $this->wordWrap($this->bio, $width - 2);
            foreach ($bioLines as $bioLine) {
                if ($this->bioColor !== null) {
                    $lines[] = $this->bioColor->toFg(ColorProfile::TrueColor);
                }
                $lines[] = ' ' . str_pad($bioLine, $width - 1);
                $lines[] = Ansi::reset();
            }
        }

        // Bottom border
        if ($this->borderColor !== null) {
            $lines[] = $this->borderColor->toFg(ColorProfile::TrueColor) . str_repeat('─', $width) . Ansi::reset();
        } else {
            $lines[] = str_repeat('─', $width);
        }

        return implode("\n", $lines);
    }

    /**
     * Render compact layout (single line).
     */
    private function renderCompact(int $width): string
    {
        $lines = [];

        // Avatar + name + role on one line
        $content = '';
        if ($this->avatar !== null) {
            $content .= '[' . $this->avatar . '] ';
        }

        if ($this->nameColor !== null) {
            $content .= $this->nameColor->toFg(ColorProfile::TrueColor);
        }
        $content .= $this->name;
        $content .= Ansi::reset();

        if ($this->role !== '') {
            if ($this->roleColor !== null) {
                $content .= ' ';
                $content .= $this->roleColor->toFg(ColorProfile::TrueColor);
            }
            $content .= $this->role;
            $content .= Ansi::reset();
        }

        if ($this->borderColor !== null) {
            $lines[] = $this->borderColor->toFg(ColorProfile::TrueColor)
                . str_repeat('─', $width)
                . Ansi::reset();
        }
        $lines[] = str_pad($content, $width);
        if ($this->borderColor !== null) {
            $lines[] = $this->borderColor->toFg(ColorProfile::TrueColor)
                . str_repeat('─', $width)
                . Ansi::reset();
        }

        return implode("\n", $lines);
    }

    /**
     * Get contact info lines.
     *
     * @return array<int, string>
     */
    private function getContactLines(int $width): array
    {
        $lines = [];

        if ($this->email !== null) {
            $lines[] = '📧 ' . $this->email;
        }

        if ($this->location !== null) {
            $lines[] = '📍 ' . $this->location;
        }

        // Word wrap contact lines
        $result = [];
        foreach ($lines as $line) {
            $result[] = str_pad($line, $width);
        }

        return $result;
    }

    /**
     * Word wrap text to fit within a given width.
     *
     * @return array<int, string>
     */
    private function wordWrap(string $text, int $width): array
    {
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $lines = [];
        $currentLine = '';

        foreach ($words as $word) {
            $wordLen = Width::string($word);

            if ($currentLine === '') {
                $currentLine = $word;
            } elseif (Width::string($currentLine) + 1 + $wordLen <= $width) {
                $currentLine .= ' ' . $word;
            } else {
                $lines[] = $currentLine;
                $currentLine = $word;
            }
        }

        if ($currentLine !== '') {
            $lines[] = $currentLine;
        }

        return $lines;
    }

    /**
     * Calculate the natural dimensions of this profile.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->getWidth();

        $height = 1; // top border
        if ($this->avatar !== null) {
            $height += 3; // avatar + empty + name
        } else {
            $height += 2; // name + role
        }
        if ($this->bio !== '') {
            $height += count($this->wordWrap($this->bio, $width - 2));
        }
        if ($this->email !== null || $this->location !== null) {
            $height += ($this->email !== null ? 1 : 0) + ($this->location !== null ? 1 : 0);
        }
        $height += 1; // bottom border

        return [$width, $height];
    }

    /**
     * Get the width to use for this profile.
     */
    private function getWidth(): int
    {
        if ($this->width !== null && $this->width > 0) {
            return $this->width;
        }

        $maxLen = 0;

        if ($this->name !== '') {
            $maxLen = max($maxLen, Width::string($this->name));
        }

        if ($this->role !== '') {
            $maxLen = max($maxLen, Width::string($this->role));
        }

        if ($this->bio !== '') {
            $maxLen = max($maxLen, Width::string($this->bio));
        }

        if ($this->avatar !== null) {
            $maxLen = max($maxLen, 10); // avatar takes ~10 chars
        }

        return max(30, $maxLen + 4);
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the name.
     */
    public function withName(string $name): self
    {
        return new self(
            name: $name,
            role: $this->role,
            bio: $this->bio,
            avatar: $this->avatar,
            email: $this->email,
            location: $this->location,
            avatarBgColor: $this->avatarBgColor,
            nameColor: $this->nameColor,
            roleColor: $this->roleColor,
            bioColor: $this->bioColor,
            borderColor: $this->borderColor,
            layout: $this->layout,
        );
    }

    /**
     * Set the role.
     */
    public function withRole(string $role): self
    {
        return new self(
            name: $this->name,
            role: $role,
            bio: $this->bio,
            avatar: $this->avatar,
            email: $this->email,
            location: $this->location,
            avatarBgColor: $this->avatarBgColor,
            nameColor: $this->nameColor,
            roleColor: $this->roleColor,
            bioColor: $this->bioColor,
            borderColor: $this->borderColor,
            layout: $this->layout,
        );
    }

    /**
     * Set the bio.
     */
    public function withBio(string $bio): self
    {
        return new self(
            name: $this->name,
            role: $this->role,
            bio: $bio,
            avatar: $this->avatar,
            email: $this->email,
            location: $this->location,
            avatarBgColor: $this->avatarBgColor,
            nameColor: $this->nameColor,
            roleColor: $this->roleColor,
            bioColor: $this->bioColor,
            borderColor: $this->borderColor,
            layout: $this->layout,
        );
    }

    /**
     * Set the avatar.
     */
    public function withAvatar(?string $avatar): self
    {
        return new self(
            name: $this->name,
            role: $this->role,
            bio: $this->bio,
            avatar: $avatar,
            email: $this->email,
            location: $this->location,
            avatarBgColor: $this->avatarBgColor,
            nameColor: $this->nameColor,
            roleColor: $this->roleColor,
            bioColor: $this->bioColor,
            borderColor: $this->borderColor,
            layout: $this->layout,
        );
    }

    /**
     * Set the email.
     */
    public function withEmail(?string $email): self
    {
        return new self(
            name: $this->name,
            role: $this->role,
            bio: $this->bio,
            avatar: $this->avatar,
            email: $email,
            location: $this->location,
            avatarBgColor: $this->avatarBgColor,
            nameColor: $this->nameColor,
            roleColor: $this->roleColor,
            bioColor: $this->bioColor,
            borderColor: $this->borderColor,
            layout: $this->layout,
        );
    }

    /**
     * Set the location.
     */
    public function withLocation(?string $location): self
    {
        return new self(
            name: $this->name,
            role: $this->role,
            bio: $this->bio,
            avatar: $this->avatar,
            email: $this->email,
            location: $location,
            avatarBgColor: $this->avatarBgColor,
            nameColor: $this->nameColor,
            roleColor: $this->roleColor,
            bioColor: $this->bioColor,
            borderColor: $this->borderColor,
            layout: $this->layout,
        );
    }

    /**
     * Set the avatar background color.
     */
    public function withAvatarBgColor(?Color $color): self
    {
        return new self(
            name: $this->name,
            role: $this->role,
            bio: $this->bio,
            avatar: $this->avatar,
            email: $this->email,
            location: $this->location,
            avatarBgColor: $color,
            nameColor: $this->nameColor,
            roleColor: $this->roleColor,
            bioColor: $this->bioColor,
            borderColor: $this->borderColor,
            layout: $this->layout,
        );
    }

    /**
     * Set the name color.
     */
    public function withNameColor(?Color $color): self
    {
        return new self(
            name: $this->name,
            role: $this->role,
            bio: $this->bio,
            avatar: $this->avatar,
            email: $this->email,
            location: $this->location,
            avatarBgColor: $this->avatarBgColor,
            nameColor: $color,
            roleColor: $this->roleColor,
            bioColor: $this->bioColor,
            borderColor: $this->borderColor,
            layout: $this->layout,
        );
    }

    /**
     * Set the role color.
     */
    public function withRoleColor(?Color $color): self
    {
        return new self(
            name: $this->name,
            role: $this->role,
            bio: $this->bio,
            avatar: $this->avatar,
            email: $this->email,
            location: $this->location,
            avatarBgColor: $this->avatarBgColor,
            nameColor: $this->nameColor,
            roleColor: $color,
            bioColor: $this->bioColor,
            borderColor: $this->borderColor,
            layout: $this->layout,
        );
    }

    /**
     * Set the bio color.
     */
    public function withBioColor(?Color $color): self
    {
        return new self(
            name: $this->name,
            role: $this->role,
            bio: $this->bio,
            avatar: $this->avatar,
            email: $this->email,
            location: $this->location,
            avatarBgColor: $this->avatarBgColor,
            nameColor: $this->nameColor,
            roleColor: $this->roleColor,
            bioColor: $color,
            borderColor: $this->borderColor,
            layout: $this->layout,
        );
    }

    /**
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        return new self(
            name: $this->name,
            role: $this->role,
            bio: $this->bio,
            avatar: $this->avatar,
            email: $this->email,
            location: $this->location,
            avatarBgColor: $this->avatarBgColor,
            nameColor: $this->nameColor,
            roleColor: $this->roleColor,
            bioColor: $this->bioColor,
            borderColor: $color,
            layout: $this->layout,
        );
    }

    /**
     * Set the layout.
     */
    public function withLayout(string $layout): self
    {
        return new self(
            name: $this->name,
            role: $this->role,
            bio: $this->bio,
            avatar: $this->avatar,
            email: $this->email,
            location: $this->location,
            avatarBgColor: $this->avatarBgColor,
            nameColor: $this->nameColor,
            roleColor: $this->roleColor,
            bioColor: $this->bioColor,
            borderColor: $this->borderColor,
            layout: $layout,
        );
    }
}

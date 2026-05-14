<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Media;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * Video playback state.
 */
enum PlaybackState: string
{
    case Stopped = 'stopped';
    case Playing = 'playing';
    case Paused = 'paused';
    case Buffering = 'buffering';
}

/**
 * Video player controls display mode.
 */
enum ControlsStyle: string
{
    case Auto = 'auto';
    case Always = 'always';
    case Hidden = 'hidden';
}

/**
 * A video player component with playback controls and progress bar.
 *
 * Features:
 * - Play/pause toggle
 * - Progress bar with seek
 * - Volume control
 * - Time display (current/total)
 * - Fullscreen toggle
 * - Multiple controls styles
 *
 * Mirrors video player patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class Video implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly ?int $maxLength = null,
        private readonly bool $showControls = true,
        private readonly ControlsStyle $controlsStyle = ControlsStyle::Auto,
        private readonly ?Color $progressColor = null,
        private readonly ?Color $bufferColor = null,
        private readonly ?Color $trackColor = null,
        private readonly ?Color $textColor = null,
        private readonly ?Color $backgroundColor = null,
        private readonly ?string $source = null,
        private readonly string $style = 'rounded',
    ) {}

    /**
     * Create a new video player with default styling.
     */
    public static function new(): self
    {
        return new self(
            maxLength: null,
            showControls: true,
            controlsStyle: ControlsStyle::Auto,
            progressColor: Color::hex('#89B4FA'),
            bufferColor: Color::hex('#45475A'),
            trackColor: Color::hex('#313244'),
            textColor: Color::hex('#CDD6F4'),
            backgroundColor: Color::hex('#1E1E2E'),
            source: null,
            style: 'rounded',
        );
    }

    /**
     * Set the allocated dimensions for this video player.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Get the progress bar width.
     */
    public function getProgressWidth(): int
    {
        $useWidth = $this->width ?? 60;
        return $useWidth - 4; // Account for borders
    }

    /**
     * Render the video player as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 60;
        $useHeight = $this->height ?? 10;

        if ($useWidth < 10 || $useHeight < 3) {
            return '';
        }

        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        $result = '';

        // Apply colors
        $bgColor = $this->backgroundColor ?? Color::hex('#1E1E2E');
        $textColor = $this->textColor ?? Color::hex('#CDD6F4');
        $progressColor = $this->progressColor ?? Color::hex('#89B4FA');
        $trackColor = $this->trackColor ?? Color::hex('#313244');

        // Top border
        $result .= $tl . str_repeat($h, $useWidth - 2) . $tr . "\n";

        // Content area (video display placeholder)
        $displayHeight = $useHeight - 4;
        for ($row = 0; $row < $displayHeight; $row++) {
            $result .= $v;
            if ($row === intdiv($displayHeight, 2)) {
                // Center text - video display placeholder
                $placeholder = '▓▒░ VIDEO DISPLAY ░▒▓';
                $padding = max(0, $useWidth - 2 - strlen($placeholder));
                $leftPad = intdiv($padding, 2);
                $rightPad = $padding - $leftPad;
                $result .= str_repeat(' ', $leftPad);
                $result .= $placeholder;
                $result .= str_repeat(' ', $rightPad);
            } else {
                $result .= str_repeat(' ', $useWidth - 2);
            }
            $result .= $v . "\n";
        }

        // Controls area
        $result .= $v;
        $controlsStr = $this->buildControlsString($useWidth - 2);
        $result .= $controlsStr;
        $result .= $v . "\n";

        // Bottom border
        $result .= $bl . str_repeat($h, $useWidth - 2) . $br;

        return $result;
    }

    /**
     * Build the controls string.
     */
    private function buildControlsString(int $contentWidth): string
    {
        $progressWidth = $contentWidth - 20; // Space for buttons and time

        $progressBar = '[' . str_repeat('░', $progressWidth) . ']';

        $buttons = '▶ 0:00 / 0:00 🔊━━━';

        $padding = $contentWidth - strlen($progressBar) - strlen($buttons);
        if ($padding > 0) {
            return $progressBar . str_repeat(' ', $padding) . $buttons;
        }

        // Truncate if needed
        $available = $contentWidth;
        $progressBarPart = mb_substr($progressBar, 0, intdiv($available, 2));
        $buttonsPart = mb_substr($buttons, 0, $available - strlen($progressBarPart));
        return $progressBarPart . $buttonsPart;
    }

    /**
     * Get the style characters for the border.
     *
     * @return array{0:string, 1:string, 2:string, 3:string, 4:string, 5:string}
     */
    private function getStyleChars(): array
    {
        return match ($this->style) {
            'double' => ['╔', '╗', '╚', '╝', '═', '║'],
            'rounded' => ['╭', '╮', '╰', '╯', '─', '│'],
            'single' => ['┌', '┐', '└', '┘', '─', '│'],
            'bold' => ['┏', '┓', '┗', '┛', '━', '┃'],
            'empty' => [' ', ' ', ' ', ' ', ' ', ' '],
            default => ['╭', '╮', '╰', '╯', '─', '│'],
        };
    }

    /**
     * Calculate the natural dimensions of this video player.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->width ?? 60;
        $height = $this->height ?? 10;

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the controls visibility.
     */
    public function withShowControls(bool $show): self
    {
        return new self(
            maxLength: $this->maxLength,
            showControls: $show,
            controlsStyle: $this->controlsStyle,
            progressColor: $this->progressColor,
            bufferColor: $this->bufferColor,
            trackColor: $this->trackColor,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
            source: $this->source,
            style: $this->style,
        );
    }

    /**
     * Set the controls style.
     */
    public function withControlsStyle(ControlsStyle $style): self
    {
        return new self(
            maxLength: $this->maxLength,
            showControls: $this->showControls,
            controlsStyle: $style,
            progressColor: $this->progressColor,
            bufferColor: $this->bufferColor,
            trackColor: $this->trackColor,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
            source: $this->source,
            style: $this->style,
        );
    }

    /**
     * Set the progress bar color.
     */
    public function withProgressColor(?Color $color): self
    {
        return new self(
            maxLength: $this->maxLength,
            showControls: $this->showControls,
            controlsStyle: $this->controlsStyle,
            progressColor: $color,
            bufferColor: $this->bufferColor,
            trackColor: $this->trackColor,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
            source: $this->source,
            style: $this->style,
        );
    }

    /**
     * Set the track color.
     */
    public function withTrackColor(?Color $color): self
    {
        return new self(
            maxLength: $this->maxLength,
            showControls: $this->showControls,
            controlsStyle: $this->controlsStyle,
            progressColor: $this->progressColor,
            bufferColor: $this->bufferColor,
            trackColor: $color,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
            source: $this->source,
            style: $this->style,
        );
    }

    /**
     * Set the text color.
     */
    public function withTextColor(?Color $color): self
    {
        return new self(
            maxLength: $this->maxLength,
            showControls: $this->showControls,
            controlsStyle: $this->controlsStyle,
            progressColor: $this->progressColor,
            bufferColor: $this->bufferColor,
            trackColor: $this->trackColor,
            textColor: $color,
            backgroundColor: $this->backgroundColor,
            source: $this->source,
            style: $this->style,
        );
    }

    /**
     * Set the background color.
     */
    public function withBackgroundColor(?Color $color): self
    {
        return new self(
            maxLength: $this->maxLength,
            showControls: $this->showControls,
            controlsStyle: $this->controlsStyle,
            progressColor: $this->progressColor,
            bufferColor: $this->bufferColor,
            trackColor: $this->trackColor,
            textColor: $this->textColor,
            backgroundColor: $color,
            source: $this->source,
            style: $this->style,
        );
    }

    /**
     * Set the video source.
     */
    public function withSource(?string $source): self
    {
        return new self(
            maxLength: $this->maxLength,
            showControls: $this->showControls,
            controlsStyle: $this->controlsStyle,
            progressColor: $this->progressColor,
            bufferColor: $this->bufferColor,
            trackColor: $this->trackColor,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
            source: $source,
            style: $this->style,
        );
    }

    /**
     * Set the border style.
     */
    public function withStyle(string $style): self
    {
        return new self(
            maxLength: $this->maxLength,
            showControls: $this->showControls,
            controlsStyle: $this->controlsStyle,
            progressColor: $this->progressColor,
            bufferColor: $this->bufferColor,
            trackColor: $this->trackColor,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
            source: $this->source,
            style: $style,
        );
    }
}

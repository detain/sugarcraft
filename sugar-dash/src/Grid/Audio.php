<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * Waveform visualization style.
 */
enum WaveformStyle: string
{
    case Bars = 'bars';
    case Line = 'line';
    case Blocks = 'blocks';
    case Dots = 'dots';
}

/**
 * An audio player component with waveform visualization.
 *
 * Features:
 * - Waveform visualization
 * - Play/pause toggle
 * - Progress bar with seek
 * - Volume control
 * - Time display (current/total)
 * - Multiple waveform styles
 *
 * Mirrors audio player patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class Audio implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /** @var list<float> */
    private array $waveformData = [];

    private int $currentPosition = 0;
    private int $duration = 180; // Default 3 minutes
    private bool $isPlaying = false;
    private int $volume = 75;

    public function __construct(
        private readonly ?int $maxLength = null,
        private readonly bool $showWaveform = true,
        private readonly WaveformStyle $waveformStyle = WaveformStyle::Bars,
        private readonly ?Color $waveformColor = null,
        private readonly ?Color $progressColor = null,
        private readonly ?Color $trackColor = null,
        private readonly ?Color $textColor = null,
        private readonly ?string $title = null,
        private readonly ?string $artist = null,
        private readonly string $style = 'rounded',
    ) {}

    /**
     * Create a new audio player with default styling.
     */
    public static function new(): self
    {
        return new self(
            maxLength: null,
            showWaveform: true,
            waveformStyle: WaveformStyle::Bars,
            waveformColor: Color::hex('#89B4FA'),
            progressColor: Color::hex('#A6E3A1'),
            trackColor: Color::hex('#313244'),
            textColor: Color::hex('#CDD6F4'),
            title: null,
            artist: null,
            style: 'rounded',
        );
    }

    /**
     * Set the allocated dimensions for this audio player.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Set the waveform data for visualization.
     *
     * @param list<float> $data Normalized values between 0.0 and 1.0
     */
    public function withWaveformData(array $data): self
    {
        $clone = clone $this;
        $clone->waveformData = $data;
        return $clone;
    }

    /**
     * Set the current playback position.
     */
    public function withPosition(int $seconds): self
    {
        $clone = clone $this;
        $clone->currentPosition = max(0, min($seconds, $clone->duration));
        return $clone;
    }

    /**
     * Set the duration.
     */
    public function withDuration(int $seconds): self
    {
        $clone = clone $this;
        $clone->duration = max(1, $seconds);
        return $clone;
    }

    /**
     * Set playing state.
     */
    public function withPlaying(bool $playing): self
    {
        $clone = clone $this;
        $clone->isPlaying = $playing;
        return $clone;
    }

    /**
     * Set volume.
     */
    public function withVolume(int $level): self
    {
        $clone = clone $this;
        $clone->volume = max(0, min(100, $level));
        return $clone;
    }

    /**
     * Render the audio player as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 60;
        $useHeight = $this->height ?? 6;

        if ($useWidth < 20 || $useHeight < 4) {
            return '';
        }

        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        $textColor = $this->textColor ?? Color::hex('#CDD6F4');
        $waveformColor = $this->waveformColor ?? Color::hex('#89B4FA');
        $progressColor = $this->progressColor ?? Color::hex('#A6E3A1');
        $trackColor = $this->trackColor ?? Color::hex('#313244');

        $result = '';

        // Top border
        $result .= $tl . str_repeat($h, $useWidth - 2) . $tr . "\n";

        // Title and artist
        $result .= $v;
        $titleStr = $this->title ?? 'Unknown Track';
        $artistStr = $this->artist ?? 'Unknown Artist';
        $infoStr = "{$titleStr} - {$artistStr}";
        $timeStr = $this->formatTime($this->currentPosition) . ' / ' . $this->formatTime($this->duration);
        $padding = $useWidth - 2 - strlen($infoStr) - strlen($timeStr);
        if ($padding > 0) {
            $result .= $infoStr . str_repeat(' ', $padding) . $timeStr;
        } else {
            $result .= mb_substr($infoStr, 0, $useWidth - 2 - strlen($timeStr)) . $timeStr;
        }
        $result .= $v . "\n";

        // Waveform or progress bar
        $result .= $v;
        $waveformStr = $this->buildWaveformOrProgress($useWidth - 2);
        $result .= $waveformStr;
        $result .= $v . "\n";

        // Controls
        $result .= $v;
        $controlsStr = $this->buildControlsString($useWidth - 2);
        $result .= $controlsStr;
        $result .= $v . "\n";

        // Bottom border
        $result .= $bl . str_repeat($h, $useWidth - 2) . $br;

        return $result;
    }

    /**
     * Build waveform or progress bar string.
     */
    private function buildWaveformOrProgress(int $contentWidth): string
    {
        if ($this->showWaveform && $this->waveformData !== []) {
            return $this->buildWaveform($contentWidth);
        }

        return $this->buildProgressBar($contentWidth);
    }

    /**
     * Build waveform visualization.
     */
    private function buildWaveform(int $width): string
    {
        $waveformColor = $this->waveformColor ?? Color::hex('#89B4FA');
        $progressColor = $this->progressColor ?? Color::hex('#A6E3A1');

        $progressRatio = $this->duration > 0 ? $this->currentPosition / $this->duration : 0;
        $progressPoint = intval($width * $progressRatio);

        $result = '';

        $blockCount = min(count($this->waveformData), $width);
        for ($i = 0; $i < $blockCount; $i++) {
            $value = $this->waveformData[$i];
            $isPlayed = $i < $progressPoint;
            $color = $isPlayed ? $progressColor : $waveformColor;

            $height = max(1, intval($value * 3)); // 1-3 bars height
            $result .= match ($this->waveformStyle) {
                WaveformStyle::Bars => $this->renderBar($height),
                WaveformStyle::Line => ($i % 3 === 0) ? '●' : '·',
                WaveformStyle::Blocks => str_repeat('█', $height),
                WaveformStyle::Dots => '•',
            };
        }

        // Pad if needed
        if (strlen($result) < $width) {
            $result .= str_repeat(' ', $width - strlen($result));
        }

        return mb_substr($result, 0, $width);
    }

    /**
     * Render a vertical bar for waveform.
     */
    private function renderBar(int $height): string
    {
        return match ($height) {
            1 => '▁',
            2 => '▂',
            3 => '▃',
            default => '▁',
        };
    }

    /**
     * Build progress bar.
     */
    private function buildProgressBar(int $width): string
    {
        $progressRatio = $this->duration > 0 ? $this->currentPosition / $this->duration : 0;
        $progressWidth = intval($width * $progressRatio);

        $bar = str_repeat('█', $progressWidth) . str_repeat('░', $width - $progressWidth);
        return mb_substr($bar, 0, $width);
    }

    /**
     * Build controls string.
     */
    private function buildControlsString(int $contentWidth): string
    {
        $playIcon = $this->isPlaying ? '⏸' : '▶';
        $volumeIcon = match (true) {
            $this->volume === 0 => '🔇',
            $this->volume < 33 => '🔈',
            $this->volume < 66 => '🔉',
            default => '🔊',
        };
        $volumeBars = str_repeat('━', intval($this->volume / 25)) . str_repeat('─', 4 - intval($this->volume / 25));

        $controls = "{$playIcon} {$volumeIcon}{$volumeBars}";
        $padding = $contentWidth - strlen($controls);
        if ($padding > 0) {
            return $controls . str_repeat(' ', $padding);
        }

        return mb_substr($controls, 0, $contentWidth);
    }

    /**
     * Format time in MM:SS.
     */
    private function formatTime(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);
        $secs = $seconds % 60;
        return sprintf('%d:%02d', $minutes, $secs);
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
     * Calculate the natural dimensions of this audio player.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->width ?? 60;
        $height = $this->height ?? 6;

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the waveform style.
     */
    public function withWaveformStyle(WaveformStyle $style): self
    {
        return new self(
            maxLength: $this->maxLength,
            showWaveform: $this->showWaveform,
            waveformStyle: $style,
            waveformColor: $this->waveformColor,
            progressColor: $this->progressColor,
            trackColor: $this->trackColor,
            textColor: $this->textColor,
            title: $this->title,
            artist: $this->artist,
            style: $this->style,
        );
    }

    /**
     * Set the waveform color.
     */
    public function withWaveformColor(?Color $color): self
    {
        return new self(
            maxLength: $this->maxLength,
            showWaveform: $this->showWaveform,
            waveformStyle: $this->waveformStyle,
            waveformColor: $color,
            progressColor: $this->progressColor,
            trackColor: $this->trackColor,
            textColor: $this->textColor,
            title: $this->title,
            artist: $this->artist,
            style: $this->style,
        );
    }

    /**
     * Set the progress color.
     */
    public function withProgressColor(?Color $color): self
    {
        return new self(
            maxLength: $this->maxLength,
            showWaveform: $this->showWaveform,
            waveformStyle: $this->waveformStyle,
            waveformColor: $this->waveformColor,
            progressColor: $color,
            trackColor: $this->trackColor,
            textColor: $this->textColor,
            title: $this->title,
            artist: $this->artist,
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
            showWaveform: $this->showWaveform,
            waveformStyle: $this->waveformStyle,
            waveformColor: $this->waveformColor,
            progressColor: $this->progressColor,
            trackColor: $color,
            textColor: $this->textColor,
            title: $this->title,
            artist: $this->artist,
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
            showWaveform: $this->showWaveform,
            waveformStyle: $this->waveformStyle,
            waveformColor: $this->waveformColor,
            progressColor: $this->progressColor,
            trackColor: $this->trackColor,
            textColor: $color,
            title: $this->title,
            artist: $this->artist,
            style: $this->style,
        );
    }

    /**
     * Set the title.
     */
    public function withTitle(?string $title): self
    {
        return new self(
            maxLength: $this->maxLength,
            showWaveform: $this->showWaveform,
            waveformStyle: $this->waveformStyle,
            waveformColor: $this->waveformColor,
            progressColor: $this->progressColor,
            trackColor: $this->trackColor,
            textColor: $this->textColor,
            title: $title,
            artist: $this->artist,
            style: $this->style,
        );
    }

    /**
     * Set the artist.
     */
    public function withArtist(?string $artist): self
    {
        return new self(
            maxLength: $this->maxLength,
            showWaveform: $this->showWaveform,
            waveformStyle: $this->waveformStyle,
            waveformColor: $this->waveformColor,
            progressColor: $this->progressColor,
            trackColor: $this->trackColor,
            textColor: $this->textColor,
            title: $this->title,
            artist: $artist,
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
            showWaveform: $this->showWaveform,
            waveformStyle: $this->waveformStyle,
            waveformColor: $this->waveformColor,
            progressColor: $this->progressColor,
            trackColor: $this->trackColor,
            textColor: $this->textColor,
            title: $this->title,
            artist: $this->artist,
            style: $style,
        );
    }
}

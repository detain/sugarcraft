<?php

declare(strict_types=1);

namespace SugarCraft\Gallery;

/**
 * One poster tile: a poster area (a placeholder until its rendered ANSI is
 * attached), a title, and an optional progress bar (e.g. continue-watching).
 *
 * Immutable and rendering-agnostic — the card holds *already-rendered* poster
 * bytes (produced however the app likes, e.g. candy-mosaic), so this widget
 * pulls in no image decoder. Attach the poster with {@see withPoster()} when an
 * async render resolves.
 */
final readonly class PosterCard
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $posterUrl = null,
        public ?float $progress = null,
        public ?string $poster = null,
    ) {
    }

    public function withPoster(string $ansi): self
    {
        return new self($this->id, $this->title, $this->posterUrl, $this->progress, $ansi);
    }

    public function withProgress(?float $progress): self
    {
        return new self($this->id, $this->title, $this->posterUrl, $progress, $this->poster);
    }

    public function hasPoster(): bool
    {
        return $this->poster !== null;
    }

    /**
     * Render a fixed-width block: poster (or a placeholder) rows, a title row,
     * and a progress row when set. Every row is exactly $width visual cells wide
     * so a rail or grid can stitch cards side by side.
     */
    public function render(bool $focused, int $width, int $posterHeight): string
    {
        $width = max(4, $width);
        $posterHeight = max(1, $posterHeight);

        $lines = $this->poster !== null
            ? explode("\n", $this->poster)
            : array_fill(0, $posterHeight, str_repeat('░', $width));

        $marker = $focused ? '▸' : ' ';
        $lines[] = $this->pad($marker . ' ' . self::truncate($this->title, $width - 2), $width);

        if ($this->progress !== null) {
            $lines[] = $this->pad(self::progressBar($this->progress, $width), $width);
        }

        return implode("\n", $lines);
    }

    private static function truncate(string $text, int $max): string
    {
        $max = max(1, $max);

        return mb_strlen($text) <= $max ? $text : mb_substr($text, 0, max(0, $max - 1)) . '…';
    }

    private function pad(string $text, int $width): string
    {
        $len = mb_strlen($text);

        return $len >= $width ? mb_substr($text, 0, $width) : $text . str_repeat(' ', $width - $len);
    }

    private static function progressBar(float $progress, int $width): string
    {
        $progress = max(0.0, min(1.0, $progress));
        $filled = (int) round($progress * $width);

        return str_repeat('▓', $filled) . str_repeat('░', max(0, $width - $filled));
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Gallery;

use SugarCraft\Core\Util\Width;

/**
 * One poster tile: a poster area (a placeholder until its rendered ANSI is
 * attached), a title, and an optional progress bar (e.g. continue-watching).
 *
 * Immutable and rendering-agnostic — the card holds *already-rendered* poster
 * bytes (produced however the app likes, e.g. candy-mosaic), so this widget
 * pulls in no image decoder. Attach the poster with {@see withPoster()} when an
 * async render resolves.
 *
 * Title sizing is ANSI-aware (via candy-core {@see Width}): an optional
 * pre-styled title (e.g. a fuzzy-highlighted one set with {@see withStyledTitle()})
 * keeps its escape sequences and still pads to the correct *visible* cell width.
 */
final readonly class PosterCard
{
    /**
     * @param string|null $styledTitle Optional ANSI-styled title rendered in place
     *                                  of the plain {@see $title}; the plain title
     *                                  is retained for identity/sort.
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $posterUrl = null,
        public ?float $progress = null,
        public ?string $poster = null,
        public ?string $styledTitle = null,
    ) {
    }

    public function withPoster(string $ansi): self
    {
        return new self($this->id, $this->title, $this->posterUrl, $this->progress, $ansi, $this->styledTitle);
    }

    public function withProgress(?float $progress): self
    {
        return new self($this->id, $this->title, $this->posterUrl, $progress, $this->poster, $this->styledTitle);
    }

    /**
     * Attach a pre-styled (ANSI) title to display in place of the plain
     * {@see $title} — e.g. a fuzzy-highlighted search result. The escapes are
     * preserved and the title cell is padded to the correct visible width. The
     * plain {@see $title} is kept for identity/sort.
     */
    public function withStyledTitle(string $ansi): self
    {
        return new self($this->id, $this->title, $this->posterUrl, $this->progress, $this->poster, $ansi);
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
        $title = $this->styledTitle !== null
            ? Width::truncateAnsi($this->styledTitle, $width - 2)
            : self::truncate($this->title, $width - 2);
        $lines[] = Width::padRight($marker . ' ' . $title, $width);

        if ($this->progress !== null) {
            $lines[] = Width::padRight(self::progressBar($this->progress, $width), $width);
        }

        return implode("\n", $lines);
    }

    /**
     * Truncate a plain title to $max visible cells, appending an ellipsis when
     * it overflows. ANSI-aware via {@see Width} so wide (CJK) titles count
     * correctly; styled titles take the ANSI-preserving {@see Width::truncateAnsi}
     * path in {@see render()} instead.
     */
    private static function truncate(string $text, int $max): string
    {
        $max = max(1, $max);

        return Width::string($text) <= $max ? $text : Width::truncate($text, max(0, $max - 1)) . '…';
    }

    private static function progressBar(float $progress, int $width): string
    {
        $progress = max(0.0, min(1.0, $progress));
        $filled = (int) round($progress * $width);

        return str_repeat('▓', $filled) . str_repeat('░', max(0, $width - $filled));
    }
}

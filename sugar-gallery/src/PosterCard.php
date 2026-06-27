<?php

declare(strict_types=1);

namespace SugarCraft\Gallery;

use SugarCraft\Core\ImageOverlay;
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
    /**
     * @param string|null $styledTitle  Optional ANSI-styled title rendered in place
     *                                   of the plain {@see $title}.
     * @param string|null $posterImage  Raw pixel-graphics bytes (sixel/kitty/iTerm2)
     *                                   for the poster, painted as an out-of-band
     *                                   overlay rather than inline cell text — see
     *                                   {@see withImage()}. Mutually exclusive with
     *                                   {@see $poster} (the inline cell rendering).
     * @param int|null    $imageId      Overlay id for {@see $posterImage}; the card
     *                                   draws a one-cell {@see ImageOverlay::marker()}
     *                                   at the poster's top-left and the runtime
     *                                   paints the bytes there.
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $posterUrl = null,
        public ?float $progress = null,
        public ?string $poster = null,
        public ?string $styledTitle = null,
        public ?string $posterImage = null,
        public ?int $imageId = null,
    ) {
    }

    public function withPoster(string $ansi): self
    {
        return new self($this->id, $this->title, $this->posterUrl, $this->progress, $ansi, $this->styledTitle, $this->posterImage, $this->imageId);
    }

    /**
     * Attach a pixel-graphics poster (sixel/kitty/iTerm2 bytes) to be painted as
     * an overlay at $id. Unlike {@see withPoster()} the bytes never enter the
     * text frame — the card renders a marker block reserving the poster area, and
     * the {@see \SugarCraft\Core\Program} paints the image on top.
     */
    public function withImage(string $bytes, int $id): self
    {
        return new self($this->id, $this->title, $this->posterUrl, $this->progress, $this->poster, $this->styledTitle, $bytes, $id);
    }

    public function withProgress(?float $progress): self
    {
        return new self($this->id, $this->title, $this->posterUrl, $progress, $this->poster, $this->styledTitle, $this->posterImage, $this->imageId);
    }

    /**
     * Attach a pre-styled (ANSI) title to display in place of the plain
     * {@see $title} — e.g. a fuzzy-highlighted search result. The escapes are
     * preserved and the title cell is padded to the correct visible width. The
     * plain {@see $title} is kept for identity/sort.
     */
    public function withStyledTitle(string $ansi): self
    {
        return new self($this->id, $this->title, $this->posterUrl, $this->progress, $this->poster, $ansi, $this->posterImage, $this->imageId);
    }

    public function hasPoster(): bool
    {
        return $this->poster !== null || $this->posterImage !== null;
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

        // Split on any line ending — a poster is produced by an external renderer
        // (e.g. candy-mosaic) and a stale cache entry or a different platform may
        // join rows with CRLF or a lone CR rather than LF. Exploding on "\n" alone
        // would leave a trailing "\r" on every row; once stitched side by side and
        // printed, those embedded carriage returns yank the cursor to column 0 and
        // collapse the whole rail to a single visible line. Splitting on every
        // separator (and normalising the row count below) keeps the tile exactly
        // $posterHeight rows tall regardless of how the poster bytes were encoded.
        if ($this->posterImage !== null && $this->imageId !== null) {
            // Overlay mode: reserve the poster area with blank cells and drop a
            // one-cell marker at the top-left; the runtime paints the graphics
            // bytes there on top of the text frame.
            $lines = self::imageRows($this->imageId, $width, $posterHeight);
        } else {
            $lines = $this->poster !== null
                ? self::posterRows($this->poster, $width, $posterHeight)
                : array_fill(0, $posterHeight, str_repeat('░', $width));
        }

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
     * Build the $posterHeight-row marker block for an overlay image: the
     * top-left cell is a one-cell {@see ImageOverlay::marker()} and every other
     * cell is a blank space, so the box reserves exactly $width × $posterHeight
     * cells for the runtime to paint the graphics bytes into.
     *
     * @return list<string>
     */
    private static function imageRows(int $imageId, int $width, int $posterHeight): array
    {
        $rows = [ImageOverlay::marker($imageId) . str_repeat(' ', $width - 1)];
        for ($i = 1; $i < $posterHeight; $i++) {
            $rows[] = str_repeat(' ', $width);
        }

        return $rows;
    }

    /**
     * Normalise raw poster bytes into exactly $posterHeight rows.
     *
     * Splits on any line ending (CRLF / CR / LF) so a poster encoded with a
     * separator other than "\n" — e.g. a stale cache entry written before an
     * upstream renderer fix — never leaves a stray carriage return mid-row.
     * The row count is then clamped to $posterHeight: a short poster is padded
     * with blank (width-wide) rows and a tall one is cropped, so the tile always
     * occupies the height the rail/grid reserved for it and cards stay aligned.
     *
     * @return list<string>
     */
    private static function posterRows(string $poster, int $width, int $posterHeight): array
    {
        $rows = preg_split('/\r\n|\r|\n/', $poster);
        if ($rows === false) {
            $rows = [$poster];
        }

        if (count($rows) > $posterHeight) {
            return array_slice($rows, 0, $posterHeight);
        }

        while (count($rows) < $posterHeight) {
            $rows[] = str_repeat(' ', $width);
        }

        return $rows;
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

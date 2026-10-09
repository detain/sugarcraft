<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media;

use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Mosaic;

/**
 * Turns one live-preview base64 frame into drawable cells (W2.4).
 *
 * BUDGET LAW (plan_crush_media W2.4): a preview never outshouts the chat -
 * at most 24 columns, or a quarter of the pane, whichever is smaller.
 *
 * CACHE LAW: frames use this class's own tiny bounded memo, NEVER
 * Renderer's transcript $imageCache. Progressive previews are churn - every
 * distinct frame would evict a settled picture from a 16-entry LRU built to
 * hold final images, and the transcript would visibly lose pictures mid-render
 * (crush_media.md §8.6-4). The memo here holds a handful of frames keyed by
 * content hash, and a superseded frame costs an entry, not a picture.
 */
final class PreviewPaint
{
    /** Hard ceiling on preview width in columns, per the W2.4 budget law. */
    public const MAX_COLS = 24;

    /** Memo entries kept: newest frames only - previews never repeat across polls. */
    private const MEMO_MAX = 4;

    /** @var array<string, array{body: string, cols: int, rows: int}> rendered-frame memo, oldest-first */
    private static array $memo = [];

    private function __construct()
    {
    }

    /**
     * Preview column budget for a pane $cols wide: quarter-panes, capped.
     */
    public static function budgetCols(int $paneCols): int
    {
        return max(1, min(self::MAX_COLS, intdiv($paneCols, 4)));
    }

    /**
     * Render one frame for the running row, or null when the frame cannot be
     * shown (bad base64, non-PNG payload, or a failing decode - a preview is
     * never worth an error row). The caller places the body per protocol:
     * inline modes concatenate it, blob modes feed it to ImageLayer::place()
     * with the returned dimensions, exactly like the transcript picture row.
     *
     * @return ?array{body: string, cols: int, rows: int}
     */
    public static function paint(string $frameB64, Mosaic $mosaic, int $paneCols, int $rowBudget): ?array
    {
        $bytes = base64_decode($frameB64, true);
        if ($bytes === false || !Png::isPng($bytes)) {
            return null;
        }

        $cols = self::budgetCols($paneCols);
        $rows = self::rowsFor($bytes, $cols, $rowBudget);
        $key = hash('xxh3', $bytes) . ':' . $cols . 'x' . $rows . ':' . $mosaic->protocol();

        if (isset(self::$memo[$key])) {
            $hit = self::$memo[$key];
            unset(self::$memo[$key]);
            self::$memo[$key] = $hit;

            return $hit;
        }

        try {
            $body = $mosaic->render(ImageSource::fromString($bytes), $cols, $rows);
        } catch (\Throwable) {
            return null;
        }

        $hit = ['body' => $body, 'cols' => $cols, 'rows' => $rows];
        self::$memo[$key] = $hit;
        if (\count(self::$memo) > self::MEMO_MAX) {
            array_shift(self::$memo);
        }

        return $hit;
    }

    public static function resetForTesting(): void
    {
        self::$memo = [];
    }

    /**
     * Cell height for a frame $cols wide, aspect-true and clamped, mirroring
     * the Renderer's transcript arithmetic (cells ~2:1, so the /2).
     */
    private static function rowsFor(string $bytes, int $cols, int $rowBudget): int
    {
        $size = @getimagesizefromstring($bytes);
        $aspect = \is_array($size) && $size[0] > 0 && $size[1] > 0 ? $size[0] / $size[1] : 1.0;
        $budget = max(1, $rowBudget);

        return max(1, min((int) round($cols / $aspect / 2), $budget));
    }
}

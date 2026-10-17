<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\MediaForm;

/**
 * The Wave-4 capability split (crush-media W4.1).
 *
 * W3.7 shipped the img2img and inpaint groups present-but-gated behind one
 * closed switch; W4 opens the halves as their lanes land. The gate exists so
 * a section can answer "rows or note row?" from ONE source of truth — tests
 * override per-section with the explicit `$enabled` argument of each
 * `forDraft()`, never by mutating this class.
 *
 * `img2img()` opened with W4.1 (local image ingress + clipboard paste).
 * `inpaint()` opened with W4.3 (mask ingress on lane b — {@see
 * \SugarCraft\Code\Media\MaskIngest}); the §4.3 mask rows arrive with it.
 */
final class Wave4Gate
{
    private function __construct()
    {
    }

    /** The img2img half: source rows are live (W4.1). */
    public static function img2img(): bool
    {
        return true;
    }

    /** The inpaint half: mask ingress landed with W4.3. */
    public static function inpaint(): bool
    {
        return true;
    }

    /** The whole Wave-4 surface — closed until BOTH halves have landed. */
    public static function enabled(): bool
    {
        return self::img2img() && self::inpaint();
    }
}

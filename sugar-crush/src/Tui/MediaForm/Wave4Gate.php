<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\MediaForm;

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
 * `inpaint()` waits for W4.2's mask editor on lane b — flipping it is that
 * lane's call, and only that lane's.
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

    /** The inpaint half: mask ingress has not landed yet. */
    public static function inpaint(): bool
    {
        return false;
    }

    /** The whole Wave-4 surface — closed until BOTH halves have landed. */
    public static function enabled(): bool
    {
        return self::img2img() && self::inpaint();
    }
}

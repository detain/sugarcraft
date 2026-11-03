<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Internal;

use SugarCraft\Core\Util\Width;

/**
 * Cut a plain-text run to a cell budget with the house `…` ellipsis — the
 * same dialect {@see \SugarCraft\Cli\Section::fill()} applies to over-long
 * labels, extracted so the single-line presenters (StatusLine, Stage) clip
 * their messages identically instead of inventing a second rule.
 *
 * The text must already be sanitized (see {@see SafeText}) and unstyled:
 * the cut happens before any style is applied, so it can never split an
 * escape sequence, and the returned string is measured in the same cells
 * the caller budgeted.
 *
 * @internal Not part of the public API; may change without notice.
 */
final class Clip
{
    /**
     * `$text` within `$cells` display cells: unchanged when it already fits,
     * otherwise `$cells - 1` cells plus one ellipsis cell. A budget below one
     * cell yields an empty string — the ellipsis would itself overflow.
     */
    public static function text(string $text, int $cells): string
    {
        if ($cells < 1) {
            return '';
        }
        if (Width::string($text) <= $cells) {
            return $text;
        }
        return Width::truncate($text, $cells - 1) . '…';
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\TreeList\Support;

/**
 * Shared glyph-field validator for the Default gutter classes.
 *
 * The C0/DEL/C1/malformed-UTF-8 refusal was hand-copied in DefaultPrefixer
 * and DefaultSuffixer; a widening fix (or a new refused class) applied to
 * one copy and missed the other — this single owner replaces both.
 *
 * The C0/DEL byte test is exact for well-formed UTF-8 (no continuation or
 * lead byte of a multi-byte sequence is < 0x20 or 0x7F); the C1 test runs
 * in codepoint mode and is preceded by an encoding check so malformed
 * input fails closed instead of making the /u pattern return false.
 */
final class AssertGlyph
{
    /**
     * Refuse a glyph field that smuggles terminal control codes.
     *
     * @param string $class Owner named in the refusal (FQCN is reduced to
     *                      its short name, so messages stay readable).
     * @param string $field Property name being validated.
     * @param string $glyph The candidate value; the empty string is legal —
     *                      it drops the decoration.
     *
     * @throws \InvalidArgumentException when $glyph carries C0, DEL, C1 or
     *         malformed UTF-8.
     */
    public static function field(string $class, string $field, string $glyph): void
    {
        if ($glyph === '') {
            return; // empty drops the decoration — legal
        }
        if (\preg_match('/[\x00-\x1F\x7F]/', $glyph) === 1
            || !\mb_check_encoding($glyph, 'UTF-8')
            || \preg_match('/[\x{80}-\x{9F}]/u', $glyph) === 1
        ) {
            $short = \substr($class, \strrpos($class, '\\') === false ? 0 : \strrpos($class, '\\') + 1);
            throw new \InvalidArgumentException(
                "{$short}:\${$field} must be a printable glyph string without control characters (C0/DEL/C1)."
            );
        }
    }

    private function __construct()
    {
    }
}

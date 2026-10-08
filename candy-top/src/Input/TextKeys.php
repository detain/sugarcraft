<?php

declare(strict_types=1);

namespace SugarCraft\Top\Input;

use SugarCraft\Bits\Input\TextEdit;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * btop's `Draw::TextEdit::command(key)` over sugar-bits' {@see TextEdit}
 * value object: the key vocabulary the proc filter bar and the options
 * menu's value editor share — left / right / home / end move the caret,
 * backspace / delete remove a cluster, space and one printable cluster
 * insert (digits only on a numeric editor).
 *
 * Mirrors aristocratos/btop Draw::TextEdit::command (src/btop_draw.cpp:185).
 */
final class TextKeys
{
    private function __construct()
    {
    }

    /**
     * The editor after `$key`, or null when the key is not an edit (btop
     * returns false and the caller drops it). A caret move at a bound or a
     * delete with nothing to delete is still an edit key and comes back as
     * the same (unchanged) editor.
     */
    public static function apply(TextEdit $edit, KeyMsg $key): ?TextEdit
    {
        if ($key->ctrl || $key->alt) {
            return null;
        }
        // btop names shift+arrows `shift_left` etc. (#1476) and its TextEdit
        // only moves on the bare names; shift+Home/End are unmapped escapes.
        if ($key->shift && in_array($key->type, [KeyType::Left, KeyType::Right, KeyType::Home, KeyType::End], true)) {
            return null;
        }

        return match ($key->type) {
            KeyType::Left => $edit->left(),
            KeyType::Right => $edit->right(),
            KeyType::Home => $edit->home(),
            KeyType::End => $edit->end(),
            KeyType::Backspace => $edit->backspace(),
            KeyType::Delete => $edit->delete(),
            KeyType::Space => $edit->numeric ? null : $edit->insert(' '),
            KeyType::Char => self::printable($key->rune) && (!$edit->numeric || ctype_digit($key->rune))
                ? $edit->insert($key->rune)
                : null,
            default => null,
        };
    }

    /** One grapheme cluster with no control characters — btop `ulen(key) == 1`. */
    private static function printable(string $rune): bool
    {
        if ($rune === '' || preg_match('/[\x00-\x1f\x7f]/', $rune) === 1) {
            return false;
        }
        $first = \SugarCraft\Core\Util\Width::nextCluster($rune, 0);

        return $first === $rune;
    }
}

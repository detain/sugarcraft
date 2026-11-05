<?php

declare(strict_types=1);

namespace SugarCraft\Top\Input;

use SugarCraft\Top\Lang;

/**
 * One line of the help overlay: the key column, the description, and the
 * key names ({@see KeyName}) the line documents — the machine-readable
 * part a docs drift test re-derives the key roster from. Most rows name the
 * btop key they mirror; a row with no btop counterpart sets `$candyTopOnly`,
 * and the generated README key table says so instead of naming btop keys.
 */
final class KeyRow
{
    /**
     * @param string       $keys          the key column: a literal key label, or a Lang key when `$keysLang`
     * @param string       $text          Lang key of the description ('' for a blank line)
     * @param list<string> $bindings      key names this row documents (`mouse_click`, `escape`, `m`, ...)
     * @param bool         $keysLang      `$keys` is a Lang key (a label with words in it: "Mouse 1", "Spacebar")
     * @param bool         $candyTopOnly  the row has no btop counterpart (candy-top's own surface: VM dashboard, ipmi box)
     */
    public function __construct(
        public readonly string $keys,
        public readonly string $text,
        public readonly array $bindings = [],
        public readonly bool $keysLang = false,
        public readonly bool $candyTopOnly = false,
    ) {
    }

    /** The key column as displayed. */
    public function keyLabel(): string
    {
        return $this->keysLang ? Lang::t($this->keys) : $this->keys;
    }

    /** The description as displayed. */
    public function description(): string
    {
        return $this->text === '' ? '' : Lang::t($this->text);
    }
}

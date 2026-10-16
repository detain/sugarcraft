<?php

declare(strict_types=1);

namespace SugarCraft\Forms\ItemList;

/**
 * One row in an {@see ItemList}.
 *
 * - {@see title()}        — primary text shown to the user.
 * - {@see description()}  — optional secondary text shown beneath the title.
 * - {@see filterValue()}  — string used by the built-in filter (case-
 *                           insensitive substring match). Defaults to the
 *                           title for {@see StringItem} but can return any
 *                           composite of fields for richer items.
 */
interface Item
{
    public function title(): string;

    public function description(): string;

    public function filterValue(): string;
}

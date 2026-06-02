<?php

declare(strict_types=1);

namespace SugarCraft\Query;

/**
 * Which of the three panes (tables list, rows preview, query editor)
 * has focus in the candy-query shell. Tab cycles through them in the
 * order returned by {@see next()}.
 *
 * When Pane::Admin is active, the admin dashboard page is shown
 * instead of the normal query browser.
 */
enum Pane: string
{
    case Tables = 'tables';
    case Rows   = 'rows';
    case Query  = 'query';
    case Admin  = 'admin';

    public function next(): self
    {
        return match ($this) {
            self::Tables => self::Rows,
            self::Rows   => self::Query,
            self::Query  => self::Admin,
            self::Admin  => self::Tables,
        };
    }
}

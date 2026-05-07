<?php

declare(strict_types=1);

namespace SugarCraft\Query;

/**
 * Which of the three panes (tables list, rows preview, query editor)
 * has focus in the candy-query shell. Tab cycles through them in the
 * order returned by {@see next()}.
 */
enum Pane: string
{
    case Tables = 'tables';
    case Rows   = 'rows';
    case Query  = 'query';

    public function next(): self
    {
        return match ($this) {
            self::Tables => self::Rows,
            self::Rows   => self::Query,
            self::Query  => self::Tables,
        };
    }
}

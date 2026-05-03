<?php

declare(strict_types=1);

namespace CandyCore\Query;

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

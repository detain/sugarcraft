<?php

declare(strict_types=1);

namespace SugarCraft\Query\App;

use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Db\Flavor;

/**
 * Connection/environment slice of the {@see \SugarCraft\Query\App} model:
 * which database the app talks to, its flavor, and the (optional) admin
 * server context override. Grouped out of App's constructor per plan 3.3.
 */
final class ConnectionState
{
    use Mutable;

    public function __construct(
        public readonly DatabaseInterface $db,
        public readonly Flavor $flavor = Flavor::Sqlite,
        public readonly ?ServerContextInterface $serverContext = null,
    ) {}

    public static function new(
        DatabaseInterface $db,
        Flavor $flavor = Flavor::Sqlite,
        ?ServerContextInterface $serverContext = null,
    ): self {
        return new self($db, $flavor, $serverContext);
    }

    public function withServerContext(?ServerContextInterface $serverContext): self
    {
        return $this->mutate(['serverContext' => $serverContext]);
    }
}

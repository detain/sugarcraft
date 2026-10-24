<?php

declare(strict_types=1);

namespace SugarCraft\Sql\App;

use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Sql\Admin\ServerContextInterface;
use SugarCraft\Sql\Db\DatabaseInterface;
use SugarCraft\Sql\Db\Flavor;

/**
 * Connection/environment slice of the {@see \SugarCraft\Sql\App} model:
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
        public readonly string $sslMode = '',
    ) {}

    public static function new(
        DatabaseInterface $db,
        Flavor $flavor = Flavor::Sqlite,
        ?ServerContextInterface $serverContext = null,
        string $sslMode = '',
    ): self {
        return new self($db, $flavor, $serverContext, $sslMode);
    }

    public function withServerContext(?ServerContextInterface $serverContext): self
    {
        return $this->mutate(['serverContext' => $serverContext]);
    }

    public function withSslMode(string $sslMode): self
    {
        return $this->mutate(['sslMode' => $sslMode]);
    }
}

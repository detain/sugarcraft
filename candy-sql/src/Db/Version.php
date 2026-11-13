<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Db;

/**
 * SQL-C25: `Db\Version` collided with the app-stamp `SugarCraft\Sql\Version`
 * (two public classes, one short name). The server-version value object is
 * now {@see ServerVersion}; this file declares no class of its own and only
 * keeps the old FQN resolvable through the autoloader, house alias seat as
 * in sugar-charts `OHLC\Bar`.
 *
 * @deprecated Use SugarCraft\Sql\Db\ServerVersion; REMOVED-IN: 2.0.0
 */
class_alias(ServerVersion::class, Version::class);

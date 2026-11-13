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
// The string form keeps the alias seat resolvable without naming a class
// that (by design) is never declared: the bare Version::class constant is
// byte-identical at runtime but phpstan's symbol resolver reads it as a
// missing class (gosql 2026-11-13 rider).
class_alias(ServerVersion::class, 'SugarCraft\\Sql\\Db\\Version');

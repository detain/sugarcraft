<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Dashboard;

use SugarCraft\Query\Db\Version;

/**
 * Widget kind constants and version-gated widget assembly.
 *
 * Provides constants for widget kinds (timeline, counter, round, level)
 * and a factory for assembling the correct widget set based on MySQL version.
 *
 * @see Mirrors mysql-workbench/wb_admin_performance_dashboard WidgetRegistry
 */
final class WidgetRegistry
{
    public const KIND_TIMELINE = 'timeline';
    public const KIND_COUNTER = 'counter';
    public const KIND_ROUND = 'round';
    public const KIND_LEVEL = 'level';

    public const VALID_KINDS = [
        self::KIND_TIMELINE,
        self::KIND_COUNTER,
        self::KIND_ROUND,
        self::KIND_LEVEL,
    ];

    /**
     * Assemble the full widget list for a given MySQL version.
     *
     * Combines NETWORK + (MYSQL_PRE80 or MYSQL_POST80) + INNODB widgets
     * based on whether the server version is at least 8.0.
     *
     * @param Version $version Parsed MySQL version
     * @return list<Widget>
     */
    public static function build(Version $version): array
    {
        $catalog = new WidgetCatalog();
        $mysqlWidgets = $version->isAtLeast(8, 0, 0)
            ? $catalog->mysqlPost80()
            : $catalog->mysqlPre80();

        return self::widgetsFromEntries(
            array_merge(
                $catalog->network(),
                $mysqlWidgets,
                $catalog->innodb(),
            ),
        );
    }

    /**
     * Get only the Network panel widgets.
     *
     * @return list<Widget>
     */
    public static function network(): array
    {
        return self::widgetsFromEntries((new WidgetCatalog())->network());
    }

    /**
     * Get only the MySQL panel widgets for a given version.
     *
     * @param Version $version
     * @return list<Widget>
     */
    public static function mysql(Version $version): array
    {
        $catalog = new WidgetCatalog();
        $entries = $version->isAtLeast(8, 0, 0)
            ? $catalog->mysqlPost80()
            : $catalog->mysqlPre80();

        return self::widgetsFromEntries($entries);
    }

    /**
     * Get only the InnoDB panel widgets.
     *
     * @return list<Widget>
     */
    public static function innodb(): array
    {
        return self::widgetsFromEntries((new WidgetCatalog())->innodb());
    }

    /**
     * Convert raw catalog entries into Widget objects.
     *
     * @param list<array{string,string,object,string,array{r:int,g:int,b:int},string,array<string,string>|null}> $entries
     * @return list<Widget>
     */
    private static function widgetsFromEntries(array $entries): array
    {
        $widgets = [];
        foreach ($entries as $entry) {
            [$caption, $kind, $calc, $format, $color, $tooltip, $serverVarsKeys] = $entry;
            $widgets[] = new Widget(
                caption: $caption,
                kind: $kind,
                calc: $calc,
                format: $format,
                color: $color,
                tooltip: $tooltip,
                serverVarsKeys: $serverVarsKeys,
            );
        }
        return $widgets;
    }

    /**
     * Validate that a kind string is a known widget kind.
     */
    public static function isValidKind(string $kind): bool
    {
        return in_array($kind, self::VALID_KINDS, true);
    }

    /**
     * Get all valid widget kind constants.
     *
     * @return list<string>
     */
    public static function validKinds(): array
    {
        return self::VALID_KINDS;
    }
}

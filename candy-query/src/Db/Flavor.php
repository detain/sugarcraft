<?php

declare(strict_types=1);

namespace SugarCraft\Query\Db;

/**
 * Database flavor enumeration for multi-driver support.
 *
 * Distinguishes between MySQL, MariaDB, Percona, PostgreSQL, and SQLite
 * to enable driver-specific behavior in the query browser.
 */
enum Flavor: string
{
    case MySQL = 'mysql';
    case MariaDB = 'mariadb';
    case Percona = 'percona';
    case Postgres = 'postgres';
    case Sqlite = 'sqlite';

    /**
     * Detect the database flavor from version string and optional version comment.
     *
     * Detection order:
     * 1. If $versionComment contains 'MariaDB' → MariaDB
     * 2. If $versionComment contains 'Percona' → Percona
     * 3. If $version starts with 'PostgreSQL' → Postgres
     * 4. If $versionComment or $version contains 'SQLite' → Sqlite
     * 5. If version looks like MySQL (e.g., '8.0.33') → MySQL
     * 6. Default → Sqlite (fallback)
     *
     * @param string $version        Raw version string from server
     * @param string $versionComment Optional version comment (e.g., from SELECT VERSION())
     * @return self Detected flavor
     */
    public static function detectFromVersionString(string $version, string $versionComment = ''): self
    {
        // Check version comment first (more specific)
        if ($versionComment !== '') {
            if (str_contains($versionComment, 'MariaDB')) {
                return self::MariaDB;
            }

            if (str_contains($versionComment, 'Percona')) {
                return self::Percona;
            }

            if (str_contains($versionComment, 'SQLite')) {
                return self::Sqlite;
            }
        }

        // Check version string patterns
        if (str_starts_with($version, 'PostgreSQL')) {
            return self::Postgres;
        }

        // Check for SQLite in version string (e.g., "SQLite version 3.41.0")
        if (str_contains($version, 'SQLite')) {
            return self::Sqlite;
        }

        // MySQL/MariaDB versions look like "8.0.33" or "5.5.5-10.11.4-MariaDB..."
        // If it looks like a MySQL-style version (numeric with dots), assume MySQL
        // unless it's been identified as MariaDB/Percona via versionComment above
        if (preg_match('/^\d+\.\d+\.\d+/', $version) === 1) {
            return self::MySQL;
        }

        // Fallback to SQLite for unknown/version-less drivers
        return self::Sqlite;
    }
}

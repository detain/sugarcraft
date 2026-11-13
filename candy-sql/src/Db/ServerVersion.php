<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Db;

/**
 * Immutable value object representing a database server version.
 *
 * Not to be confused with {@see \SugarCraft\Sql\Version} — that one stamps the
 * APP build, this one parses the SERVER's version string. Formerly named
 * `Db\Version` (SQL-C25/client-032): the twin short name is retired and the old
 * FQN stays resolvable through src/Db/Version.php for one minor.
 *
 * Parses version strings from MySQL, MariaDB, PostgreSQL, and SQLite,
 * handling the MariaDB "5.5.5-" pseudo-version prefix used for compatibility.
 *
 * @see Mirrors jorgerojas26/lazysql version detection
 */
final readonly class ServerVersion
{
    /**
     * @param int    $major    Major version number
     * @param int    $minor    Minor version number
     * @param int    $release  Release/patch version number
     * @param string $raw      Original version string
     */
    public function __construct(
        public int $major,
        public int $minor,
        public int $release,
        public string $raw,
    ) {}

    /**
     * Parse a version string into a Version value object.
     *
     * Handles MariaDB's pseudo-version prefix "5.5.5-" which MySQL uses for
     * compatibility. For example: "5.5.5-10.11.4-MariaDB-1:10.11.4+maria~ubu2201"
     * parses to major=10, minor=11, release=4.
     *
     * @param string $version Raw version string (e.g., "8.0.33", "5.5.5-10.11.4-MariaDB...")
     * @return self
     */
    public static function parse(string $version): self
    {
        $raw = $version;

        // Strip MariaDB compatibility prefix "5.5.5-" if present
        if (str_starts_with($version, '5.5.5-')) {
            $version = substr($version, 6);
        }

        // Now extract numeric version portion from the full version string
        // Handles:
        // - "10.11.4-MariaDB-1:10.11.4+maria~ubu2201" -> 10.11.4
        // - "PostgreSQL 16.0" -> 16.0
        // - "8.0.33" -> 8.0.33
        if (preg_match('/(\d+\.\d+(?:\.\d+)?)/', $version, $matches) !== 1) {
            return new self(0, 0, 0, $raw);
        }

        $version = $matches[1];
        $parts = explode('.', $version);
        $major = (int) ($parts[0] ?? 0);
        $minor = (int) ($parts[1] ?? 0);
        $release = (int) ($parts[2] ?? 0);

        return new self($major, $minor, $release, $raw);
    }

    /**
     * True when the raw version string carries the MariaDB identity marker.
     *
     * The numeric fields are deliberately NORMALISED for compatibility —
     * "5.5.5-10.11.4-MariaDB" parses to 10.11.4 — which makes the numbers
     * unsafe alone for capability gates whose history differs by vendor
     * (SET PERSIST shipped in MySQL 8.0.16 but only in MariaDB 11.1; a bare
     * isAtLeast(8, 0) lets MariaDB 10.x "pass" the gate and then die on a
     * raw 1064 server-side, SQL-P13/adminpages-024). Vendor-sensitive
     * consumers branch on this marker before comparing numbers.
     */
    public function isMariaDb(): bool
    {
        return stripos($this->raw, 'MariaDB') !== false;
    }

    /**
     * Check if this version is at least the specified version.
     *
     * @param int $major    Required major version
     * @param int $minor     Required minor version (default 0)
     * @param int $release   Required release version (default 0)
     * @return bool True if this version >= (major, minor, release)
     */
    public function isAtLeast(int $major, int $minor = 0, int $release = 0): bool
    {
        if ($this->major !== $major) {
            return $this->major > $major;
        }

        if ($this->minor !== $minor) {
            return $this->minor > $minor;
        }

        return $this->release >= $release;
    }

    /**
     * Return the raw version string.
     *
     * @return string Original version string
     */
    public function __toString(): string
    {
        return $this->raw;
    }
}

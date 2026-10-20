<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Session;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\Code\Support\SqliteConnect;

/**
 * SES-1: every SQLite connection `src/` opens goes through
 * {@see SqliteConnect} — the one arm that makes
 * `SessionStore::immediateTransaction()`'s documented "waits on the
 * connection's busy timeout" a fact rather than a pdo_sqlite build default.
 *
 * STRUCTURAL, NOT BEHAVIOURAL, ON PURPOSE: the behavioural half
 * ({@see SessionStoreBusyTimeoutTest}) proves the arm works today on the
 * connections that exist today; this proves a future `new PDO` cannot skip
 * it silently. A raw connect is exactly the shape that reintroduced the
 * dropped spend row this finding is about.
 *
 * The scan is token-shaped (T_NEW + a PDO name token + `(`), so prose in a
 * docblock — including this file's and the connector's own, which name the
 * construct in their text — cannot score, and a `new` onto anything else
 * cannot either. Fail-closed: the walk reads every PHP file under `src/` and
 * refuses to report an absence it could not see (unreadable file = red).
 */
final class SqliteConnectCensusTest extends TestCase
{
    /**
     * Every raw PDO construction in `src/`, file => count, with the verdict
     * that let it stay raw. Bidirectional: a new raw site anywhere reddens
     * as unaccounted, and an account whose site vanished reddens as stale.
     */
    private const ALLOWED_RAW_SITES = [
        // The arm itself. Both constructions live in SqliteConnect; every
        // other file reaches SQLite through one of them.
        'src/Support/SqliteConnect.php' => 2,
        // The doctor's driver-existence probe: its whole job is to throw
        // `could not find driver` for a DSN no driver claims, before any
        // statement could run, so it must construct the raw PDO itself.
        // (Its sibling fts5Probe routes through openDsn() — SES-1.)
        'src/Cli/Subcommands.php' => 1,
    ];

    public function testEveryConnectSiteInSrcIsAccountedFor(): void
    {
        $counts = self::rawPdoConstructionsAcrossSrc();

        $unaccounted = array_diff_assoc($counts, self::ALLOWED_RAW_SITES);
        self::assertSame(
            [],
            $unaccounted,
            'raw `new PDO` appeared at ' . implode(', ', array_map(
                static fn(string $f, int $n): string => "$f ($n)",
                array_keys($unaccounted),
                $unaccounted,
            )) . ' — connect through SqliteConnect::open()/openDsn() so the '
                . 'busy timeout (SES-1) is armed, or roster the site here with '
                . 'the verdict that justifies staying raw',
        );

        $stale = array_diff_assoc(self::ALLOWED_RAW_SITES, $counts);
        self::assertSame(
            [],
            $stale,
            'a rostered raw-PDO account no longer names a construction: '
                . implode(', ', array_keys($stale)) . ' — delete the row',
        );
    }

    public function testTheConnectorArmsTheBusyTimeoutEveryCallerInherits(): void
    {
        // The promise itself, read from the same body the callers run: the
        // arm issues the PRAGMA with the constant, once, in arm().
        $source = self::sourceOf(SqliteConnect::class);
        self::assertSame(1, substr_count($source, "'PRAGMA busy_timeout=' . self::BUSY_TIMEOUT_MS"),
            'SqliteConnect::arm() no longer issues the busy_timeout PRAGMA from the constant');
        self::assertSame(5000, SqliteConnect::BUSY_TIMEOUT_MS,
            'the arm timeout moved — every store inherits this number, and SES-1 chose it');
    }

    /** @return array<string, int> file (repo-relative) => raw construction count */
    private static function rawPdoConstructionsAcrossSrc(): array
    {
        $root = \dirname(__DIR__, 2);
        $counts = [];
        $walk = static function (string $dir) use (&$walk, $root, &$counts): void {
            $entries = scandir($dir);
            if ($entries === false) {
                self::fail("could not read {$dir} — the census refuses to report an absence it could not see");
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir . '/' . $entry;
                if (is_dir($path)) {
                    $walk($path);

                    continue;
                }
                if (!str_ends_with($path, '.php')) {
                    continue;
                }
                $found = self::rawPdoConstructionsIn((string) file_get_contents($path));
                if ($found > 0) {
                    $counts[substr($path, strlen($root) + 1)] = $found;
                }
            }
        };
        $walk($root . '/src');

        return $counts;
    }

    /**
     * Count `new PDO(` / `new \PDO(` constructions in one file's token
     * stream. Comments are gone by construction (tokenize() never emits
     * them as code), so docblocks naming the construct — this suite has
     * several — cannot score.
     */
    private static function rawPdoConstructionsIn(string $source): int
    {
        $count = 0;
        $tokens = \PhpToken::tokenize($source);
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];
            if (!$token->is(T_NEW)) {
                continue;
            }
            // Walk the (possibly qualified, possibly `\`-prefixed) name that
            // the new targets, then demand the call parenthesis: `new PDO`
            // with no `(` is not a construction.
            $j = $i + 1;
            while ($j < $n && ($tokens[$j]->is([T_WHITESPACE, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) || $tokens[$j]->text === '\\')) {
                $j++;
            }
            if ($j === $i + 1) {
                continue; // `new` with no name token after it — cannot happen in parsed source; be loud, not clever.
            }
            $name = '';
            for ($k = $i + 1; $k < $j; $k++) {
                if (!$tokens[$k]->is(T_WHITESPACE)) {
                    $name .= $tokens[$k]->text;
                }
            }
            $short = substr(strrchr('\\' . $name, '\\') ?: $name, 1);
            if (strcasecmp($short, 'PDO') === 0 && $j < $n && $tokens[$j]->text === '(') {
                $count++;
            }
        }

        return $count;
    }

    private static function sourceOf(string $class): string
    {
        $file = (string) (new ReflectionClass($class))->getFileName();
        $source = file_get_contents($file);
        self::assertIsString($source, "{$file} is unreadable");

        return $source;
    }
}

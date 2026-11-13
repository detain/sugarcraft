<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests;

use PHPUnit\Framework\TestCase;

/**
 * SQL-O05 ratchet: a user-facing English sentence belongs in lang/*.php
 * behind Lang::t(), not in a string literal under src/.
 *
 * The census is token-based (comments and docblocks are invisible to it):
 * every string literal holding two words separated by a space or dash, that
 * does not open with an SQL keyword, counts. What remains per file is
 * recorded in tests/Support/raw-literals-baseline.json — SQL fragments,
 * metric-catalog identifiers, driver-facing developer exceptions and
 * machine tokens the heuristic cannot tell apart from prose. The count may
 * only fall: a file growing past its baseline fails (a new sentence went in
 * raw), and a file shrinking below it fails too (tighten the baseline in the
 * same change so the ratchet never goes slack). A file missing from the
 * baseline is held to zero.
 *
 * Literals that are not prose at all (SQL fragments, protocol tokens, identity
 * keys, parsed-back version strings, programmer-error contracts) are listed
 * per file in tests/Support/raw-literals-exempt.json with a dated rationale.
 * An exemption is exact: the literal must still occur exactly as many times as
 * recorded, so a stale entry fails the same way a slack baseline does, and an
 * exempt string cannot quietly become user-facing copy (move it behind
 * Lang::t() and drop the entry).
 */
final class NoRawLiteralsGuardTest extends TestCase
{
    private const SQL_OPENER = '/^\W?\s*(SELECT|SHOW|SET|FROM|UPDATE|INSERT|DELETE|CREATE|DROP|ALTER|EXPLAIN|PRAGMA|WITH|GRANT|REVOKE|TRUNCATE|CALL|USE|BEGIN|COMMIT|ROLLBACK|KILL|RENAME|LOCK|UNLOCK|ANALYZE|OPTIMIZE|REPAIR|FLUSH|RESET|START|STOP|CHANGE|PURGE|DESCRIBE|DESC|REPLACE|LOAD|WHERE|ORDER|GROUP|JOIN|LEFT|AND|OR|ON)\b/i';

    /** @return array<string,array{reason:string,dated:string,literals:array<string,int>}> */
    private static function exemptions(): array
    {
        $raw = json_decode((string) file_get_contents(__DIR__ . '/Support/raw-literals-exempt.json'), true);
        self::assertIsArray($raw);
        return $raw;
    }

    /**
     * @param array<string,int>|null $exemptSeen filled with file => literal => occurrences of exempt literals
     * @return array<string,int>
     */
    private static function census(?array &$exemptSeen = null): array
    {
        $exempt = self::exemptions();
        $exemptSeen = [];
        $root = realpath(__DIR__ . '/../src');
        self::assertNotFalse($root);
        $counts = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $n = 0;
            $rel = substr($file->getPathname(), strlen($root) + 1);
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $tok) {
                if (!is_array($tok)) {
                    continue;
                }
                if ($tok[0] !== T_CONSTANT_ENCAPSED_STRING && $tok[0] !== T_ENCAPSED_AND_WHITESPACE) {
                    continue;
                }
                $s = $tok[1];
                if ($tok[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $s = substr($s, 1, -1);
                }
                if (!preg_match('/[A-Za-z]{2,}[ \-—][A-Za-z]{2,}/', $s)) {
                    continue;
                }
                if (preg_match(self::SQL_OPENER, $s) || preg_match('/^[a-z_.]+$/', $s)) {
                    continue;
                }
                if (isset($exempt[$rel]['literals'][$s])) {
                    $exemptSeen[$rel][$s] = ($exemptSeen[$rel][$s] ?? 0) + 1;
                    continue;
                }
                $n++;
            }
            if ($n > 0) {
                $counts[$rel] = $n;
            }
        }
        ksort($counts);
        return $counts;
    }

    /** @return array<string,int> */
    private static function baseline(): array
    {
        $raw = json_decode((string) file_get_contents(__DIR__ . '/Support/raw-literals-baseline.json'), true);
        self::assertIsArray($raw);
        return $raw;
    }

    public function testRawLiteralCountNeverGrowsPastTheBaseline(): void
    {
        $base = self::baseline();
        foreach (self::census() as $file => $count) {
            $this->assertLessThanOrEqual(
                $base[$file] ?? 0,
                $count,
                "src/{$file} holds {$count} raw English literal(s) (baseline " . ($base[$file] ?? 0)
                . ') — route user-facing text through Lang::t() and add the key to all 16 lang/*.php',
            );
        }
    }

    public function testBaselineIsTightNotSlack(): void
    {
        $now = self::census();
        foreach (self::baseline() as $file => $allowed) {
            $this->assertSame(
                $allowed,
                $now[$file] ?? 0,
                "src/{$file} now holds " . ($now[$file] ?? 0) . " literal(s), baseline says {$allowed} — "
                . 'lower tests/Support/raw-literals-baseline.json so the ratchet stays tight',
            );
        }
    }

    public function testEveryExemptionIsExactAndCarriesADatedRationale(): void
    {
        self::census($seen);
        foreach (self::exemptions() as $file => $entry) {
            $this->assertNotSame('', trim($entry['reason']), "{$file}: exemption needs a rationale");
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $entry['dated'], "{$file}: exemption needs an ISO date");
            foreach ($entry['literals'] as $literal => $count) {
                $this->assertSame(
                    $count,
                    $seen[$file][$literal] ?? 0,
                    "{$file}: exempt literal \"{$literal}\" occurs " . ($seen[$file][$literal] ?? 0) . " time(s), exemption says {$count} — drop or update the entry",
                );
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A NAKED `sleep()` IN A TEST IS AN UNBOUNDED WAIT DRESSED AS A DELAY.
 *
 * A fixed sleep guesses how long the thing under test takes: too short and the
 * test flakes, too long and every run pays for it. A bounded poll (deadline +
 * short tick, failing with a message naming what never happened) is the shape
 * this suite wants. The census polices the tree with the token stream (so
 * strings and comments never count) and a grandfather roster of per-file counts
 * in `Fixtures/naked-sleep-roster.php`: existing sites may not grow, may only be
 * retired, and a file not on the roster may carry none.
 *
 * Tick-sized sleeps INSIDE a poll loop are the legitimate use; those files are
 * on the roster too, and retiring a site means lowering its number here.
 */
final class BoundedPollCensusTest extends TestCase
{
    private const FUNCS = ['sleep', 'usleep', 'time_nanosleep', 'time_sleep_until'];

    public function testNoNewNakedSleepsBeyondTheRoster(): void
    {
        /** @var array<string,int> $roster */
        $roster = require __DIR__ . '/Fixtures/naked-sleep-roster.php';
        $root = dirname(__DIR__);
        $self = 'Support/BoundedPollCensusTest.php';
        $offenders = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $rel = substr($file->getPathname(), strlen($root) + 1);
            if ($rel === $self) {
                continue;
            }
            $found = self::nakedSleeps((string) file_get_contents($file->getPathname()));
            $allowed = $roster[$rel] ?? 0;
            if ($found > $allowed) {
                $offenders[] = sprintf('%s: %d naked sleep site(s), roster allows %d', $rel, $found, $allowed);
            }
        }
        $this->assertSame([], $offenders, "New naked sleep() in tests - use a bounded poll (deadline + tick) instead:\n" . implode("\n", $offenders));
    }

    public function testRosterHasNoStaleEntries(): void
    {
        /** @var array<string,int> $roster */
        $roster = require __DIR__ . '/Fixtures/naked-sleep-roster.php';
        $root = dirname(__DIR__);
        $stale = [];
        foreach ($roster as $rel => $count) {
            $path = $root . '/' . $rel;
            if (!is_file($path)) {
                $stale[] = $rel . ': file no longer exists';
                continue;
            }
            $found = self::nakedSleeps((string) file_get_contents($path));
            if ($found < $count) {
                $stale[] = sprintf('%s: roster says %d, tree has %d - lower the roster', $rel, $count, $found);
            }
        }
        $this->assertSame([], $stale, implode("\n", $stale));
    }

    public function testSyntheticNakedSleepIsCounted(): void
    {
        $src = "<?php\nfinal class T { public function t(): void { sleep(1); usleep(5); \$this->sleep(1); self::sleep(2); } }\n"
            . "// sleep(3)\n\$s = 'sleep(4)';\n";
        $this->assertSame(2, self::nakedSleeps($src));
        $this->assertSame(0, self::nakedSleeps("<?php\nfunction sleep(int \$n): void {}\n"));
    }

    private static function nakedSleeps(string $source): int
    {
        $t = token_get_all($source);
        $n = 0;
        foreach ($t as $i => $tok) {
            if (!is_array($tok) || $tok[0] !== T_STRING || !in_array(strtolower($tok[1]), self::FUNCS, true)) {
                continue;
            }
            $p = $i - 1;
            while ($p >= 0 && is_array($t[$p]) && in_array($t[$p][0], [T_WHITESPACE, T_COMMENT], true)) {
                $p--;
            }
            $q = $i + 1;
            while (is_array($t[$q] ?? null) && $t[$q][0] === T_WHITESPACE) {
                $q++;
            }
            if (($t[$q] ?? null) !== '(') {
                continue;
            }
            if (is_array($t[$p]) && in_array($t[$p][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                continue;
            }
            $n++;
        }
        return $n;
    }
}

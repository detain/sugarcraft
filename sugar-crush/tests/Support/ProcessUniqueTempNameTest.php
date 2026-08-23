<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * An argument-less `uniqid` call is derived from the current microtime and
 * NOTHING else, so it is not unique across processes. Two suites running as the
 * same user at the same moment can produce the SAME value.
 *
 * That matters here because `tests/bootstrap.php` points `TMPDIR` at a sandbox
 * keyed by uid alone — every concurrent suite writes into one directory — so a
 * collision is a collision on a real path. Observed 2026-08-23 while measuring
 * E242: five concurrent runs of `tests/Agents` produced `SQLite3Exception: Task
 * not found: dep` in one of them, from two processes opening one
 * `tasklist_test_<id>.sqlite3`. Run alone, the same range is green — which is
 * why this is invisible to a lane working by itself and reads as a flake.
 *
 * The fix is a pid prefix plus the more-entropy flag.
 *
 * ⚠️ THIS FILE MUST SURVIVE A BLANKET REWRITE OF THE PATTERN IT DESCRIBES.
 * The 2026-08-23 sweep that fixed 91 call sites also ate this test's own
 * fixture and mangled this paragraph, because a regex cannot tell the offender
 * from the description of the offender. So: the prose never spells the bare
 * call, and the fixture BUILDS it at runtime by concatenation instead of
 * containing it literally. Keep it that way.
 */
final class ProcessUniqueTempNameTest extends TestCase
{
    /** Directories whose PHP files must not make the argument-less call. */
    private const SCOPE = ['tests'];

    public function testNoTestFileUsesAProcessColludingTempName(): void
    {
        $offenders = [];
        $root      = \dirname(__DIR__, 2);

        foreach (self::phpFilesInScope() as $file) {
            foreach (self::offendingLines((string) \file_get_contents($file)) as $line) {
                $offenders[] = \substr($file, \strlen($root) + 1) . ':' . $line;
            }
        }

        self::assertSame([], $offenders, \sprintf(
            "%d argument-less uniqid call(s) found. That form is microtime-derived and NOT unique "
            . "across processes; under the shared TMPDIR two concurrent suites collide on one path. "
            . "Pass a pid prefix and the more-entropy flag.\n  %s",
            \count($offenders),
            \implode("\n  ", $offenders),
        ));
    }

    /**
     * Known-positive control: the scanner must actually SEE the bad form, or the
     * assertion above is a fixture that passes because the instrument is dead
     * (E228). A prefixed call and an entropic call must NOT match.
     */
    public function testTheScannerSeesTheBadFormAndSparesTheGoodOnes(): void
    {
        $bad = 'uniq' . 'id()';

        $source = "<?php\n"
            . "\$a = {$bad};\n"
            . "\$b = uniqid('prefix');\n"
            . "\$c = uniqid((string) getmypid(), true);\n"
            . "\$d = {$bad};\n";

        self::assertSame([2, 5], self::offendingLines($source));
    }

    /**
     * @return list<int> 1-indexed line numbers of argument-less `uniqid` calls
     */
    private static function offendingLines(string $source): array
    {
        $offending = [];
        $tokens    = \token_get_all($source);
        $byLine    = \explode("\n", $source);

        foreach ($tokens as $token) {
            if (!\is_array($token) || $token[0] !== \T_STRING || $token[1] !== 'uniqid') {
                continue;
            }

            // token_get_all() gives the name but not the argument list, so
            // re-check the line for the empty-parens form; `uniqid($x)` is spared.
            if (\preg_match('/\buniqid\(\s*\)/', $byLine[$token[2] - 1] ?? '') === 1) {
                $offending[] = $token[2];
            }
        }

        return $offending;
    }

    /** @return list<string> */
    private static function phpFilesInScope(): array
    {
        $root  = \dirname(__DIR__, 2);
        $files = [];

        foreach (self::SCOPE as $prefix) {
            $dir = $root . '/' . $prefix;

            if (!\is_dir($dir)) {
                continue;
            }

            /** @var \SplFileInfo $info */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $info) {
                if ($info->isFile() && $info->getExtension() === 'php') {
                    $files[] = $info->getPathname();
                }
            }
        }

        \sort($files);

        return $files;
    }
}

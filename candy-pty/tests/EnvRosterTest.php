<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;

/**
 * README's environment table IS what this package reads.
 *
 * FOUR VARIABLES CHANGE WHAT THIS LIBRARY DOES AND NOTHING WATCHED THEM.
 * `SUGARCRAFT_LIBC` picks the C library FFI binds against, `SUGARCRAFT_TERMIOS`
 * swaps the termios backend for a subprocess one, `SUGARCRAFT_PTY_BACKEND`
 * decides which `PtySystem` gets built, and `CANDY_PTY_HANG_BUDGET` arms or
 * disarms the suite's own hang watchdog. Three of them were mentioned in README
 * prose, in three separate sections, and the fourth was documented nowhere at
 * all — a contributor chasing a wedged test could learn about the opt-out only
 * by reading `tests/Support/HangWatchdog.php`.
 *
 * BOTH DIRECTIONS, because they are different defects. Read-but-untabulated is
 * a knob nobody can discover; tabulated-but-unread is a knob somebody sets with
 * no effect, which is what a rename leaves behind.
 *
 * THE ALPHABET IS EVERY NAME, NOT A PREFIX (rule 11). A scan keyed on
 * `SUGARCRAFT_` would have reported a clean package while `CANDY_PTY_HANG_BUDGET`
 * — the one variable here that no other library in the monorepo shares a prefix
 * with — went untabulated. That is not hypothetical: it is exactly what
 * happened, and a prefix-keyed census would have called it clean. The
 * exemptions are a NAMED list ({@see AMBIENT}) with a reason each.
 *
 * WHY THIS FILE AND NOT A MONOREPO-WIDE ONE. `candy-pty` is published
 * standalone as `sugarcraft/candy-pty`, so a guard for it that lived under the
 * monorepo's `tools/` would not ship with the package and would not run for
 * anyone who cloned the split repo. The scanner is therefore a COPY of the one
 * in `tools/tests/ToolsEnvRosterTest.php`, and the drift between them is pinned
 * — from THERE, not from here, because a `tools/` guard may read a package and
 * a published package may never read `tools/`, which does not exist in its
 * split repo. See `ToolsEnvRosterTest::testTheScannerHasNotDriftedFromTheCandyPtyCopy()`.
 *
 * MEASURED ON PHP 8.3.6. CI runs 8.3 and 8.4; this box has only 8.3.6 and 8.4
 * was NOT exercised. The one tokenisation fact the scanner turns on is version
 * evidence and so carries its version: `\getenv` is ONE token,
 * `T_NAME_FULLY_QUALIFIED`, with the backslash inside its text — not a
 * `T_NS_SEPARATOR` followed by a `T_STRING`. EVERY `getenv()` call in this
 * package is spelled with the leading backslash, so a scanner that got this
 * wrong would report the package as reading nothing at all and pass.
 *
 * @internal
 */
final class EnvRosterTest extends TestCase
{
    /**
     * Variables the environment supplies, rather than knobs this package owns.
     *
     * NAMED, NOT PATTERNED: an exemption a reviewer can count beats one they
     * have to simulate.
     *
     * - `PATH` — the executable search path, forwarded into the child
     *   environment by the integration tests so the spawned shell can find
     *   `sh`, `vim` and friends.
     *
     * @var array<string, string>
     */
    private const AMBIENT = [
        'PATH' => 'the executable search path, forwarded into spawned children',
    ];

    /**
     * Accesses that resolve to no NAME at all, and so roster nothing.
     *
     * `getenv()` with no argument returns the whole environment;
     * `$_ENV`/`$_SERVER` used without a subscript is the same shape. Neither
     * introduces a variable this package can be said to READ by name, so
     * neither can be tabulated — but the scanner still REPORTS them rather than
     * dropping them (rule 14), and the distinction is made here, deliberately,
     * where a reader can see it. `<not a literal>` is the genuinely
     * unresolvable one and it is asserted absent on its own below.
     *
     * @var list<string>
     */
    private const NAMES_NOTHING = ['<the whole environment>', '<the whole array>'];

    private const UNRESOLVED = '<not a literal>';

    // ── the roster ───────────────────────────────────────────────────────

    /**
     * Every variable this package reads has a row in README's table, and back.
     */
    public function testEveryVariableThePackageReadsHasARowInTheReadme(): void
    {
        $root = \dirname(__DIR__);
        $files = self::sourceRoster($root);

        // RULE 15: the roster is the other half of every assertion below — an
        // empty walk yields "nothing undocumented" exactly as convincingly as a
        // clean package does. Named because they exist, not counted: a
        // cardinality here is stale the moment anyone adds a file.
        $this->assertContains($root . '/src/Libc.php', $files);
        $this->assertContains($root . '/src/TermiosFactory.php', $files);
        $this->assertContains($root . '/src/PtySystemFactory.php', $files);
        $this->assertContains($root . '/tests/Support/HangWatchdog.php', $files);
        $this->assertContains(__FILE__, $files);

        $tabulated = self::readmeEnvNames((string) \file_get_contents($root . '/README.md'));
        $this->assertNotSame(
            [],
            $tabulated,
            "README.md's environment table parsed to no rows at all, so both comparisons "
            . 'below are a scan of the package against an empty table. The `## Environment '
            . 'variables` heading or the table shape has changed and this reader has to be '
            . 'taught it',
        );

        $read = [];
        $untabulated = [];
        $unresolved = [];
        foreach ($files as $file) {
            $source = \file_get_contents($file);
            // RULE 14: a roster entry this cannot read must go RED rather than
            // contribute a silent zero. MEASURED on PHP 8.3.6,
            // file_get_contents() on a DIRECTORY returns the EMPTY STRING
            // rather than false, so is_string() alone is not the check.
            $this->assertIsString($source, $file . ' is unreadable, so the scan over it is void');
            $this->assertNotSame('', $source, $file . ' read as empty, so the scan over it is void');

            foreach (self::envAccessesIn($source) as $access) {
                $name = $access['name'];
                $where = \substr($file, \strlen($root) + 1) . ':' . $access['line'];
                if ($name === self::UNRESOLVED) {
                    $unresolved[] = $where;

                    continue;
                }
                if (\in_array($name, self::NAMES_NOTHING, true) || isset(self::AMBIENT[$name])) {
                    continue;
                }
                $read[$name] = true;
                if (!isset($tabulated[$name])) {
                    $untabulated[] = $where . ' — ' . $name;
                }
            }
        }
        \sort($untabulated);
        \sort($unresolved);

        $this->assertSame(
            [],
            $unresolved,
            'These environment accesses have a name this scanner cannot resolve — a computed '
            . 'getenv() argument or a computed superglobal subscript. It refuses to guess '
            . 'rather than scoring them zero. Spell the variable as a literal, or add a row to '
            . 'EnvRosterTest::AMBIENT / NAMES_NOTHING saying deliberately why this access '
            . 'rosters nothing.',
        );

        $this->assertSame(
            [],
            $untabulated,
            'These environment variables are READ by candy-pty and have no row in the '
            . '`## Environment variables` table in README.md, so the only way to discover them '
            . 'is to read the source. Add a row — the table opens by claiming to be every '
            . 'variable this package reads, and that claim is what this asserts.',
        );

        $missingReads = [];
        foreach (\array_keys($tabulated) as $name) {
            if (!isset($read[$name])) {
                $missingReads[] = $name;
            }
        }
        \sort($missingReads);

        $this->assertSame(
            [],
            $missingReads,
            'These environment variables have a row in README.md\'s table and are read by '
            . 'nothing in src/, bin/ or tests/ — a knob a user can set with no effect, which '
            . 'is what a rename leaves behind. Delete the row, or restore the read.',
        );
    }

    // ── the instruments, against answers already known ───────────────────

    /**
     * Each shape the scanner claims to understand, as a source whose answer is
     * already known.
     *
     * NOT CEREMONY. The `\getenv` row is a defect the first draft of this
     * scanner's monorepo twin actually had, and every call in this package is
     * spelled that way — with that row failing, the guard reports a package
     * that reads nothing and goes green.
     *
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function shapesTheScannerUnderstands(): iterable
    {
        yield 'a bare getenv()' => ['<?php $x = getenv("A_ONE");', ['A_ONE']];
        yield 'a root-namespaced \\getenv()' => ['<?php $x = \\getenv("A_TWO");', ['A_TWO']];
        yield 'putenv(), a write and still a knob' => ['<?php \\putenv("A_THREE=1");', ['A_THREE']];
        yield 'a $_ENV subscript' => ['<?php $x = $_ENV["A_FOUR"];', ['A_FOUR']];
        yield 'a $_SERVER subscript' => ['<?php $x = $_SERVER["A_FIVE"] ?? "";', ['A_FIVE']];
        yield 'getenv() with no argument' => ['<?php $all = getenv();', ['<the whole environment>']];
        yield 'a computed getenv() argument' => ['<?php $x = getenv($n);', ['<not a literal>']];
        yield 'a computed subscript' => ['<?php $x = $_SERVER[$n];', ['<not a literal>']];
        yield 'a whole-superglobal read' => ['<?php foreach ($_ENV as $k => $v) {}', ['<the whole array>']];
        yield 'a method named getenv is not the function' => ['<?php $x = $o->getenv("NOPE");', []];
        yield 'a name only mentioned in a string' => ['<?php $x = "getenv(\\"NOPE\\")";', []];
        yield 'an array that is not a superglobal' => ['<?php $x = $server["NOPE"];', []];
    }

    /**
     * @param list<string> $expected
     *
     * @dataProvider shapesTheScannerUnderstands
     */
    public function testTheScannerReadsEveryShapeItClaimsTo(string $source, array $expected): void
    {
        $found = [];
        foreach (self::envAccessesIn($source) as $access) {
            $found[] = $access['name'];
        }
        \sort($found);
        \sort($expected);

        $this->assertSame($expected, $found);
    }

    /**
     * The README table reader reads the table it is pointed at, and only it.
     *
     * RULE 25. The roster comparison's two headline assertions are both `[]`,
     * and `[]` is what this reader returns when it has stopped matching. The
     * fixture surrounds the table with a `|`-delimited table in ANOTHER section
     * — because a reader that scrapes every backticked first cell in the file
     * would swallow those rows and report a table that documents everything.
     */
    public function testTheReadmeTableReaderReadsOnlyTheEnvironmentTable(): void
    {
        $readme = <<<'MD'
            # a package

            ## Mirrors

            | Charm symbol | candy-pty |
            |---|---|
            | `BEFORE_THE_TABLE` | not a variable |

            ## Environment variables

            | Variable | Read by | Effect |
            |---|---|---|
            | `R_ONE` | `A` | the first |
            | `R_TWO` | `B` | the second |

            ## Known limitations

            | Thing | Note |
            |---|---|
            | `AFTER_THE_TABLE` | also not a variable |
            MD;

        $this->assertSame(
            ['R_ONE' => true, 'R_TWO' => true],
            self::readmeEnvNames($readme),
            'the README table reader either cannot read the table it is pointed straight at, '
            . 'or is scraping rows from the tables around it',
        );

        $this->assertSame(
            [],
            self::readmeEnvNames("# a package\n\n## Install\n\nnothing here\n"),
            'the README table reader invented rows for a README with no environment table',
        );
    }

    // ── the readers ──────────────────────────────────────────────────────

    /**
     * Every `.php` file under `src/`, `bin/` and `tests/`, absolute and sorted.
     *
     * ALL THREE, because a knob is a knob wherever it is read. The suite's own
     * watchdog budget is read only from `tests/`, and it is the one variable
     * here that was documented nowhere.
     *
     * @return list<string>
     */
    private static function sourceRoster(string $root): array
    {
        $files = [];
        foreach (['src', 'bin', 'tests'] as $dir) {
            if (!\is_dir($root . '/' . $dir)) {
                continue;
            }
            $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $root . '/' . $dir,
                \FilesystemIterator::SKIP_DOTS,
            ));
            foreach ($walk as $entry) {
                if ($entry->isFile() && $entry->getExtension() === 'php') {
                    $files[] = $entry->getPathname();
                }
            }
        }
        \sort($files);

        return $files;
    }

    /**
     * The variable names README's environment table declares.
     *
     * KEYED ON STRUCTURE, NOT ON TEXT (rule 40): the section runs from the
     * `## Environment variables` heading to the next `##` heading, and a row is
     * a table line whose FIRST cell is a single backticked upper-case
     * identifier. A variable merely NAMED in a paragraph is not a row, and a
     * table in a neighbouring section is not this table.
     *
     * @return array<string, true>
     */
    private static function readmeEnvNames(string $readme): array
    {
        $names = [];
        $inSection = false;
        foreach (\explode("\n", $readme) as $line) {
            $line = \ltrim($line);
            if (\str_starts_with($line, '## ')) {
                $inSection = $line === '## Environment variables';

                continue;
            }
            if (!$inSection) {
                continue;
            }
            if (\preg_match('/^\|\s*`([A-Z][A-Z0-9_]*)`\s*\|/', $line, $m) === 1) {
                $names[$m[1]] = true;
            }
        }

        return $names;
    }

    /**
     * Every environment access in one PHP source, with its line.
     *
     * FOUR CALL SHAPES AND TWO SUPERGLOBALS, and an access it cannot resolve is
     * REPORTED WITH A PLACEHOLDER NAME rather than dropped (rule 14). The
     * placeholders are deliberately not valid variable names, so they can never
     * be satisfied by a help row and always reach a human.
     *
     * @return list<array{name: string, line: int}>
     */
    private static function envAccessesIn(string $source): array
    {
        $tokens = \token_get_all($source);
        $count = \count($tokens);
        $found = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token)) {
                continue;
            }

            // getenv() / putenv(), bare or root-namespaced. `\getenv` is ONE
            // token on PHP 8.3.6 (T_NAME_FULLY_QUALIFIED) whose text carries
            // the backslash, which is why both categories are here.
            if (\in_array($token[0], [\T_STRING, \T_NAME_FULLY_QUALIFIED], true)) {
                $name = \strtolower(\ltrim($token[1], '\\'));
                if ($name !== 'getenv' && $name !== 'putenv') {
                    continue;
                }
                // A METHOD OR PROPERTY OF THE SAME NAME IS NOT THE FUNCTION.
                $before = self::significantBefore($tokens, $i);
                if ($before !== null && \is_array($before)
                    && \in_array($before[0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION], true)
                ) {
                    continue;
                }
                $open = self::significantAfter($tokens, $i);
                if ($open === null || $tokens[$open] !== '(') {
                    continue;
                }
                $argAt = self::significantAfter($tokens, $open);
                $arg = $argAt === null ? null : $tokens[$argAt];
                if ($arg === ')') {
                    $found[] = ['name' => '<the whole environment>', 'line' => $token[2]];

                    continue;
                }
                if (\is_array($arg) && $arg[0] === \T_CONSTANT_ENCAPSED_STRING) {
                    $literal = \substr($arg[1], 1, -1);
                    // putenv() takes NAME=value; getenv() takes NAME.
                    $eq = \strpos($literal, '=');
                    $found[] = [
                        'name' => $eq === false ? $literal : \substr($literal, 0, $eq),
                        'line' => $token[2],
                    ];

                    continue;
                }
                $found[] = ['name' => '<not a literal>', 'line' => $token[2]];

                continue;
            }

            // $_ENV / $_SERVER, subscripted or whole.
            if ($token[0] === \T_VARIABLE && ($token[1] === '$_ENV' || $token[1] === '$_SERVER')) {
                $open = self::significantAfter($tokens, $i);
                if ($open === null || $tokens[$open] !== '[') {
                    $found[] = ['name' => '<the whole array>', 'line' => $token[2]];

                    continue;
                }
                $keyAt = self::significantAfter($tokens, $open);
                $key = $keyAt === null ? null : $tokens[$keyAt];
                if (\is_array($key) && $key[0] === \T_CONSTANT_ENCAPSED_STRING) {
                    $found[] = ['name' => \substr($key[1], 1, -1), 'line' => $token[2]];

                    continue;
                }
                $found[] = ['name' => '<not a literal>', 'line' => $token[2]];
            }
        }

        return $found;
    }

    /**
     * The index of the next token after `$i` that is not whitespace or a
     * comment, or `null`.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function significantAfter(array $tokens, int $i): ?int
    {
        $count = \count($tokens);
        for ($j = $i + 1; $j < $count; $j++) {
            $token = $tokens[$j];
            if (\is_array($token) && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            return $j;
        }

        return null;
    }

    /**
     * The token before `$i` that is not whitespace or a comment, or `null`.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private static function significantBefore(array $tokens, int $i)
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            $token = $tokens[$j];
            if (\is_array($token) && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }
}

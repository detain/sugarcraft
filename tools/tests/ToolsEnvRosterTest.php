<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

require_once __DIR__ . '/_bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * The repo-root `tools/` scripts read environment variables NOBODY watched.
 *
 * `SUGARCRAFT_CHECK_PATH_REPOS_ROOT` and `SUGARCRAFT_GEN_DOCS_ROOT` each
 * redirect the monorepo root their script resolves everything under, and both
 * scripts WRITE to what they resolve — `--fix` rewrites composer manifests,
 * the generator rewrites every page under `docs/lib/`. `--check` and
 * `--no-lib-path-repos` are hard CI gates. Until this round both variables were
 * documented only in a comment beside their own `getenv()` call.
 *
 * WHY NO EXISTING ROSTER COVERS THEM. `sugar-crush`'s `EnvRosterDriftTest`
 * walks `sugar-crush/src` and `sugar-crush/bin` under the
 * `SUGARCRUSH_`/`SUGAR_CRUSH_` alphabet. A `SUGARCRAFT_`-prefixed variable at
 * the monorepo root is outside both its population and its alphabet, and
 * reaching `tools/` from a `sugar-crush` test is the dependency inversion
 * `StackedDocCommentTest`'s doc-block measures and refuses: a guard HERE may
 * read `sugar-crush`, never the reverse.
 *
 * THE ALPHABET IS EVERY NAME, NOT A PREFIX, AND THAT IS THE WHOLE POINT.
 * A census keyed on `SUGARCRAFT_` would report a clean `tools/` on the day
 * somebody adds `TOOLS_DOCS_ROOT` or `GEN_DOCS_DEBUG` — a census cannot find
 * what its alphabet cannot spell (rule 11). So {@see envAccessesIn()} reports
 * EVERY environment access it can see under any name, and the exemptions are a
 * named list of ambient variables ({@see AMBIENT}) rather than a pattern.
 * Adding a row to that list is a deliberate act a reviewer can see; widening a
 * regex is not.
 *
 * BOTH DIRECTIONS, because they are different defects. Read-but-undocumented is
 * a knob a contributor cannot discover — which is what both of these were.
 * Documented-but-unread is a knob they set with no effect, which is what a
 * rename leaves behind.
 *
 * MEASURED ON PHP 8.3.6. This box has only 8.3.6 while CI runs 8.3 and 8.4;
 * nothing here is version-sensitive (it is `token_get_all()` over categories
 * that predate 8.0), but the tokenisation fact the scanner turns on is version
 * evidence and so carries its version: `\getenv` is ONE token,
 * `T_NAME_FULLY_QUALIFIED`, with the backslash in its text — NOT a `T_NS_SEPARATOR`
 * followed by a `T_STRING`. The first draft of the census behind this file
 * compared `T_STRING` text to `'getenv'` and reported
 * `SUGARCRAFT_CHECK_PATH_REPOS_ROOT` as absent from a file that reads it on
 * line 45 (rule 13: the harness carried the defect the claim was about). It was
 * caught only because `grep` had already answered that question. Both token
 * categories are in the scanner and both are pinned by a fixture below.
 *
 * @internal
 */
final class ToolsEnvRosterTest extends TestCase
{
    /**
     * Variables supplied by the environment rather than by this repo.
     *
     * NAMED, NOT PATTERNED (rule 40 in the small): an exemption a reviewer can
     * count is worth more than one a reviewer has to simulate. Each row says
     * why it is not a knob of ours.
     *
     * - `argv`   — `$_SERVER['argv']` is the command line, not configuration.
     * - `HOME`   — the composer global install location, probed in the test
     *              bootstrap so a PHAR run can find an autoloader.
     * - `PATH`   — the executable search path.
     *
     * @var array<string, string>
     */
    private const AMBIENT = [
        'argv' => 'the command line, not configuration',
        'HOME' => 'the composer global install location, probed for an autoloader',
        'PATH' => 'the executable search path',
    ];

    // ── the scanner, against answers already known (first) ───────────────

    /**
     * Every shape the scanner claims to understand, as a source whose answer
     * is already known.
     *
     * RUN BEFORE THE REAL SCAN, deliberately, and it is not ceremony here: the
     * `\getenv` row is a defect this scanner's own first draft had, and the
     * `<not a literal>` rows are the difference between a guard that goes red
     * on what it cannot parse and one that quietly scores zero (rule 14).
     *
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function shapesTheScannerUnderstands(): iterable
    {
        yield 'a bare getenv()' => ['<?php $x = getenv("A_ONE");', ['A_ONE']];

        // THE ROW THAT WAS A REAL DEFECT. On PHP 8.3.6 `\getenv` tokenises as a
        // single T_NAME_FULLY_QUALIFIED whose text INCLUDES the backslash, so a
        // scanner comparing T_STRING text to 'getenv' sees nothing at all —
        // and every call in tools/check-path-repos.php is spelled this way.
        yield 'a root-namespaced \\getenv()' => ['<?php $x = \\getenv("A_TWO");', ['A_TWO']];

        yield 'putenv(), which is a write and still a knob' => [
            '<?php \\putenv("A_THREE=1");',
            ['A_THREE'],
        ];

        yield 'a $_ENV subscript' => ['<?php $x = $_ENV["A_FOUR"];', ['A_FOUR']];
        yield 'a $_SERVER subscript' => ['<?php $x = $_SERVER["A_FIVE"] ?? "";', ['A_FIVE']];

        // NOT DROPPED, NAMED (rule 14). Each of these is a real access whose
        // name this scanner cannot resolve, and a guard that answers "no
        // variable here" to them has a hole shaped like the next defect.
        yield 'getenv() with no argument reads the whole environment' => [
            '<?php $all = getenv();',
            ['<the whole environment>'],
        ];
        yield 'a computed getenv() argument' => [
            '<?php $x = getenv($name);',
            ['<not a literal>'],
        ];
        yield 'a computed $_SERVER subscript' => [
            '<?php $x = $_SERVER[$name];',
            ['<not a literal>'],
        ];
        yield 'a whole-superglobal read' => ['<?php foreach ($_ENV as $k => $v) {}', ['<the whole array>']];

        // AND THE NEGATIVES, which are what stop the scanner reporting the
        // world. A method named getenv() on an object is not the function.
        yield 'a method call named getenv' => ['<?php $x = $o->getenv("NOPE");', []];
        yield 'a name merely mentioned in a string' => ['<?php $x = "getenv(\\"NOPE\\")";', []];
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

    // ── the roster ───────────────────────────────────────────────────────

    /**
     * Every variable a `tools/` script reads has a row in that script's help.
     *
     * THE POPULATION IS DERIVED, NOT LISTED (rule 18): every `.php` directly
     * under `tools/`, each asked for its own `--help`. The two that exist today
     * are asserted by NAME below — a cardinality here would be stale the moment
     * anyone adds a script, while a roster that had gone empty would make every
     * assertion in this method vacuous.
     */
    public function testEveryVariableAToolsScriptReadsIsDocumentedInItsHelp(): void
    {
        $root = \dirname(__DIR__, 2);
        $scripts = self::scriptRoster($root);

        // RULE 15: the roster is the other half of every assertion below. An
        // empty one produces "no undocumented variables" just as convincingly
        // as a clean tree does.
        $this->assertContains($root . '/tools/gen-docs.php', $scripts);
        $this->assertContains($root . '/tools/check-path-repos.php', $scripts);

        $undocumented = [];
        $unread = [];
        foreach ($scripts as $script) {
            $help = self::helpTextOf($script);
            $this->assertNotSame(
                '',
                \trim($help),
                \basename($script) . ' --help printed nothing, so the comparison below is a '
                . 'scan of a file against an empty document and it would pass on anything',
            );

            $documented = self::environmentBlockNames($help);
            $read = [];
            foreach (self::envAccessesIn((string) \file_get_contents($script)) as $access) {
                $name = $access['name'];
                if (isset(self::AMBIENT[$name])) {
                    continue;
                }
                $read[$name] = true;
                if (!isset($documented[$name])) {
                    $undocumented[] = \basename($script) . ':' . $access['line'] . ' — ' . $name;
                }
            }
            foreach (\array_keys($documented) as $name) {
                if (!isset($read[$name])) {
                    $unread[] = \basename($script) . ' — ' . $name;
                }
            }
        }
        \sort($undocumented);
        \sort($unread);

        $this->assertSame(
            [],
            $undocumented,
            'These environment variables are READ by a tools/ script and have no row in that '
            . "script's `Environment:` help block, so the only way to discover them is to read "
            . 'the source. Both of these scripts WRITE to whatever root they resolve and both '
            . 'gate CI. Add a row, or — if the name is supplied by the environment rather than '
            . 'by us — a row to ToolsEnvRosterTest::AMBIENT saying why. A name reported as '
            . '`<not a literal>` or `<the whole environment>` is the scanner refusing to guess: '
            . 'spell the variable as a literal, or exempt that access deliberately.',
        );

        $this->assertSame(
            [],
            $unread,
            'These environment variables have a row in a tools/ script\'s `Environment:` help '
            . 'block and are read by nothing in it — a knob a contributor can set with no '
            . 'effect, which is what a rename leaves behind. Delete the row, or restore the '
            . 'read.',
        );

        // AND THE KNOWN-POSITIVE FOR THE OTHER INSTRUMENT IN THIS METHOD
        // (rule 15/25). Both assertions above are `[]`, and `[]` is what an
        // environmentBlockNames() that had stopped matching returns for the
        // first and an envAccessesIn() that had stopped matching returns for
        // the second. Neither list being empty is evidence on its own.
        $this->assertSame(
            ['B_ONE' => true, 'B_TWO' => true],
            self::environmentBlockNames(
                // THE TWO SECTIONS AROUND THE BLOCK BOTH CARRY AN INDENTED ROW
                // WHOSE FIRST TOKEN WOULD MATCH, which is what makes this
                // fixture pin the section BOUNDARY rather than only the row
                // shape: a reader that never enters the block reports [], and
                // one that never leaves it reports four names.
                "usage: x\n\nOptions:\n  BEFORE_THE_BLOCK  prose in an earlier section\n\n"
                . "Environment:\n  B_ONE  the first\n  B_TWO\n         the second, wrapped\n\n"
                . "See also:\n  AFTER_THE_BLOCK  prose in a later section\n",
            ),
            'the Environment: block reader either cannot read a block it is pointed straight '
            . 'at, or is reading rows from the sections around it',
        );
        $this->assertSame(
            [],
            self::environmentBlockNames("usage: x\n\nOptions:\n  --flag  a flag\n"),
            'the Environment: block reader invented rows for a help text with no such block',
        );
    }

    /**
     * Nothing under `tools/tests/` reads a variable of its own.
     *
     * A SEPARATE POPULATION WITH A DIFFERENT RULE, stated rather than folded
     * into the roster above. The test files have no `--help` to document
     * anything in, so the rule for them is that every access is ambient. The
     * day one of them needs a knob, this reds and the answer is a row in
     * AMBIENT with a reason — not a silent extension.
     */
    public function testNoToolsTestFileIntroducesAKnobOfItsOwn(): void
    {
        $root = \dirname(__DIR__, 2);
        $files = [];
        foreach (\glob($root . '/tools/tests/*.php') ?: [] as $file) {
            $files[] = $file;
        }
        \sort($files);

        $this->assertContains(__FILE__, $files, 'the tools/tests/ walk cannot see its own files');

        $local = [];
        foreach ($files as $file) {
            foreach (self::envAccessesIn((string) \file_get_contents($file)) as $access) {
                if (isset(self::AMBIENT[$access['name']])) {
                    continue;
                }
                $local[] = \basename($file) . ':' . $access['line'] . ' — ' . $access['name'];
            }
        }
        \sort($local);

        $this->assertSame(
            [],
            $local,
            'A file under tools/tests/ reads an environment variable that is neither ambient '
            . 'nor documented anywhere — these files have no --help to document it in. Add a '
            . 'row to ToolsEnvRosterTest::AMBIENT with the reason it is not ours, or move the '
            . 'knob into the script it configures where the help block will cover it.',
        );
    }

    // ── the instruments ──────────────────────────────────────────────────

    /**
     * Every `.php` file directly under `tools/`, absolute and sorted.
     *
     * NOT RECURSIVE, deliberately: `tools/tests/` has its own rule
     * ({@see testNoToolsTestFileIntroducesAKnobOfItsOwn()}) because those files
     * have no `--help` to be documented in.
     *
     * @return list<string>
     */
    private static function scriptRoster(string $root): array
    {
        $files = \glob($root . '/tools/*.php') ?: [];
        \sort($files);

        return \array_values($files);
    }

    /** The `--help` output of one script, stdout and stderr together. */
    private static function helpTextOf(string $script): string
    {
        $cmd = \escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($script) . ' --help 2>&1';

        return (string) \shell_exec($cmd);
    }

    /**
     * The variable names an `Environment:` help block declares.
     *
     * KEYED ON THE BLOCK'S SHAPE, NOT ON THE NAME (rule 40): the section runs
     * from the `Environment:` heading to the next unindented line, and a row is
     * an indented line whose first token is a bare upper-case identifier. A
     * continuation line is indented too but starts with prose, so the leading
     * token settles it. This is why `--flag  SOMETHING` under `Options:` is not
     * a row and why a variable merely NAMED in a paragraph is not documented.
     *
     * @return array<string, true>
     */
    private static function environmentBlockNames(string $help): array
    {
        $names = [];
        $inBlock = false;
        foreach (\explode("\n", $help) as $line) {
            if (\preg_match('/^Environment:\s*$/', $line) === 1) {
                $inBlock = true;

                continue;
            }
            if (!$inBlock) {
                continue;
            }
            if (\trim($line) === '') {
                continue;
            }
            if ($line[0] !== ' ') {
                $inBlock = false;

                continue;
            }
            if (\preg_match('/^\s+([A-Z][A-Z0-9_]*)(\s|$)/', $line, $m) === 1) {
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

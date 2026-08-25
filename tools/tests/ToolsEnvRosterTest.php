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
    use PhpFunctionBodyTokens;

    /**
     * The copy of this file's environment scanner that ships with `candy-pty`.
     */
    private const CANDY_PTY_COPY = '/candy-pty/tests/EnvRosterTest.php';

    /**
     * The scanner methods the two implementations must agree about.
     *
     * @var list<string>
     */
    private const SHARED_SCANNER = ['envAccessesIn', 'significantAfter', 'significantBefore'];

    /**
     * Accesses that resolve to no NAME, and so can roster nothing.
     *
     * `getenv()` with no argument returns the whole environment, and a
     * superglobal used without a subscript is the same shape. Neither
     * introduces a variable a help block could name — but the scanner still
     * REPORTS them rather than dropping them (rule 14), and the decision to
     * exclude them is made HERE, where a reader can see it, rather than inside
     * the scanner where it would look like a blind spot. `<not a literal>` is
     * the genuinely unresolvable one and is asserted absent on its own.
     *
     * @var list<string>
     */
    private const NAMES_NOTHING = ['<the whole environment>', '<the whole array>'];

    private const UNRESOLVED = '<not a literal>';

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
        $unresolved = [];
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
                if ($name === self::UNRESOLVED) {
                    $unresolved[] = \basename($script) . ':' . $access['line'];

                    continue;
                }
                if (\in_array($name, self::NAMES_NOTHING, true) || isset(self::AMBIENT[$name])) {
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
        \sort($unresolved);

        $this->assertSame(
            [],
            $unresolved,
            'These environment accesses have a name the scanner cannot resolve — a computed '
            . 'getenv() argument or a computed superglobal subscript. It refuses to guess '
            . 'rather than scoring them zero (rule 14). Spell the variable as a literal, or '
            . 'say deliberately in ToolsEnvRosterTest why this access rosters nothing.',
        );

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
                //
                // THE FIRST DRAFT OF THIS FIXTURE DID NOT, and it looked like
                // it did — it wrapped the block in `Options:` and `Exit codes:`
                // sections whose rows begin `--flag` and `0`, neither of which
                // this reader's row pattern can match. MEASURED: with that
                // version in place and `$inBlock = false` deleted from the
                // section-boundary branch, so the block never ends at all, the
                // suite is GREEN — 45 tests, 175 assertions AT `d63c02c56`. The
                // count carries its sha because it rots and the GREEN does not
                // (rule 18). The mutation was
                // relevant; the fixture's window was wrong (rule 2).
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

        // AND THE SHAPE THE ROW PATTERN ALONE CANNOT TELL FROM A ROW, which is
        // ORDINARY WRAPPED PROSE. `[A-Z][A-Z0-9_]*` followed by a space is a
        // perfectly good English line opener, so a continuation beginning `AND`
        // or `A` used to be read as a variable. That is not hypothetical: a
        // re-wrap of gen-docs.php's own help with ZERO WORDS CHANGED, moving
        // `AND WRITE under, instead` to a line start, reported
        // `gen-docs.php - AND` as a documented-but-unread knob and told the
        // contributor to delete a row that does not exist (rule 33). The
        // block's indent COLUMN is what separates them, and both offending
        // openers are here because they fail differently: `AND` is all-caps
        // and `A` is a single capital.
        $this->assertSame(
            ['C_ONE' => true],
            self::environmentBlockNames(
                "Environment:\n  C_ONE  a real row whose prose wraps three times, and the wrap\n"
                . "         AND WRITE is where it lands, so this line opens all-caps\n"
                . "         A path that is invalid exits 2, so this one opens with a\n"
                . "         bare capital instead\n",
            ),
            'the Environment: block reader is reading wrapped PROSE as a variable row. A '
            . 'continuation line is not a row however it begins; only the block\'s own indent '
            . 'column says what a row is',
        );

        // THE OTHER POLARITY (rule 41): a phantom row is still caught. It sits
        // at the column like every real row, so narrowing to the column costs
        // the guard nothing it was actually for.
        $this->assertSame(
            ['C_ONE' => true, 'C_PHANTOM' => true],
            self::environmentBlockNames(
                "Environment:\n  C_ONE  a real row\n         wrapped once\n"
                . "  C_PHANTOM  a row naming a variable nothing reads\n",
            ),
            'the Environment: block reader stopped seeing a second row at the same column, so '
            . 'a knob documented and read by nothing would no longer be reported',
        );

        // AND WHAT THE COLUMN RULE CANNOT EXPRESS, pinned rather than promised
        // (rule 11). Rows indented DEEPER than an intro line in the same block
        // take their column from the intro, and are read as nothing. It reds
        // rather than passing -- with no documented names every variable the
        // script reads is reported undocumented -- so the direction is safe,
        // but it is a real limit and this is where a future widening starts.
        $this->assertSame(
            [],
            self::environmentBlockNames(
                "Environment:\n  everything below is read at startup:\n    C_DEEP  a row\n",
            ),
            'the Environment: block reader now reads rows indented deeper than an intro line '
            . 'in the same block. That is a WIDENING, not a bug fix: decide deliberately '
            . 'whether the column should be the first ROW-SHAPED line rather than the '
            . 'shallowest line, and pin whichever you choose in both directions',
        );
    }

    /**
     * Nothing under `tools/` that is not a script reads a variable of its own.
     *
     * A SECOND POPULATION WITH A DIFFERENT RULE, stated rather than folded into
     * the roster above: these files have no `--help` to document anything in,
     * so the rule for them is that every access is ambient. The day one of them
     * needs a knob, this reds and the answer is a row in AMBIENT with a reason
     * — not a silent extension.
     *
     * AND IT IS A PARTITION, NOT A SECOND GLOB. The first draft of this pair
     * was `tools/*.php` here and `tools/tests/*.php` there, which leaves a hole
     * exactly the shape of `tools/anything-else/script.php` — a file in neither
     * population and therefore scanned by nothing, in a guard whose entire
     * subject is a variable nobody was watching. The assertion below closes it
     * by construction: every `.php` file under `tools/` is either a script or
     * one of these, and a file that is somehow neither reds by name.
     */
    public function testNoOtherToolsFileIntroducesAKnobOfItsOwn(): void
    {
        $root = \dirname(__DIR__, 2);
        $scripts = self::scriptRoster($root);
        $everything = self::everyToolsFile($root);
        $files = \array_values(\array_diff($everything, $scripts));
        \sort($files);

        $this->assertContains(__FILE__, $files, 'the tools/ walk cannot see its own files');
        $this->assertContains(
            $root . '/tools/tests/_bootstrap.php',
            $files,
            'the walk is by *Test.php rather than by extension, so the helper that declares no '
            . 'test — and carries more prose than either test file — is invisible to it',
        );

        // THE PARTITION'S LOAD-BEARING HALF, and the first draft of this
        // assertion was the wrong one. It asserted that
        // `array_diff($everything, $scripts, $files)` is empty — which is a
        // TAUTOLOGY of how `$files` is built, and MEASURED: with `$files`
        // narrowed to `/tests/` paths only, so the partition really does have a
        // hole, the suite stayed GREEN at 46 tests / 188 assertions AT
        // `bbc69b15d` — sha attached because the count rots and the GREEN is
        // what the record is for (rule 18) — because
        // `tools/` happens to contain nothing outside those two shapes today.
        // An assertion of `[]` that a broken instrument also satisfies is not
        // evidence (rule 25).
        //
        // What actually makes the two populations cover `tools/` is that the
        // scripts are a SUBSET of the recursive walk: a script the walk cannot
        // see would make the difference below wrong in the other direction,
        // silently moving a file out of the population that scans it. That is
        // false today the moment either roster stops agreeing with the other,
        // and it is what the mutation kills.
        $this->assertSame(
            [],
            \array_values(\array_diff($scripts, $everything)),
            'the recursive tools/ walk cannot see a file the script roster can, so the two '
            . 'populations no longer partition tools/ and a file may be scanned by neither',
        );

        $local = [];
        foreach ($files as $file) {
            foreach (self::envAccessesIn((string) \file_get_contents($file)) as $access) {
                if (\in_array($access['name'], self::NAMES_NOTHING, true)
                    || isset(self::AMBIENT[$access['name']])
                ) {
                    continue;
                }
                $local[] = \basename($file) . ':' . $access['line'] . ' — ' . $access['name'];
            }
        }
        \sort($local);

        $this->assertSame(
            [],
            $local,
            'A file under tools/ that is not a script reads an environment variable that is '
            . 'neither ambient nor documented anywhere — these files have no --help to '
            . 'document it in. Add a row to ToolsEnvRosterTest::AMBIENT with the reason it is '
            . 'not ours, or move the knob into the script it configures, where that script\'s '
            . '`Environment:` help block will cover it.',
        );
    }

    /**
     * Every `.php` file anywhere under `tools/`, absolute and sorted.
     *
     * BY EXTENSION AND RECURSIVE, which is what makes the two populations above
     * a partition rather than two globs with a gap between them.
     *
     * @return list<string>
     */
    private static function everyToolsFile(string $root): array
    {
        $files = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $root . '/tools',
            \FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($walk as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        \sort($files);

        return $files;
    }

    /**
     * `candy-pty`'s copy of this scanner has not drifted from this one.
     *
     * WHY THERE IS A COPY AT ALL, AND WHY THE PIN LIVES HERE. `candy-pty` is
     * published standalone as `sugarcraft/candy-pty`; a guard for its four
     * environment knobs that lived under `tools/` would not ship with the
     * package and would not run for anyone who cloned the split repo. So the
     * package carries its own, and the copy is only defensible while divergence
     * is loud. The pin cannot live WITH the copy: `tools/` does not exist in
     * that split repo, and a published package reaching up into the monorepo is
     * the dependency inversion `StackedDocCommentTest`'s doc-block measures and
     * refuses. A `tools/` guard may read a package; never the reverse.
     *
     * COMPARED AS TOKENS, NOT TEXT, so comment edits and reformatting on either
     * side are free and only a change in what the code DOES reds. Read as text
     * rather than by reflection because the job that runs this file installs a
     * PHPUnit PHAR and does no `composer install` — there is no autoloader for
     * `SugarCraft\Pty\Tests\*` in it.
     */
    public function testTheScannerHasNotDriftedFromTheCandyPtyCopy(): void
    {
        $copy = \dirname(__DIR__, 2) . self::CANDY_PTY_COPY;

        $this->assertFileExists(
            $copy,
            "candy-pty's copy of the environment scanner has moved or been deleted. Update "
            . \basename(__FILE__) . '::CANDY_PTY_COPY to wherever it lives now — do not delete '
            . 'this guard, which would leave that copy unwatched',
        );

        $mine = (string) \file_get_contents(__FILE__);
        $theirs = (string) \file_get_contents($copy);

        foreach (self::SHARED_SCANNER as $method) {
            $ours = self::functionBodyTokens($mine, $method);
            $them = self::functionBodyTokens($theirs, $method);

            // RULE 14 IN BOTH DIRECTIONS: an extractor that found nothing would
            // answer [] === [] and pass. Neither side may be empty.
            $this->assertNotSame([], $ours, 'no ' . $method . '() body was found in this file');
            $this->assertNotSame([], $them, 'no ' . $method . '() body was found in ' . self::CANDY_PTY_COPY);

            $this->assertSame(
                $ours,
                $them,
                'candy-pty\'s copy of ' . $method . '() has drifted from the one in this file. '
                . 'Port the change across rather than letting the two diverge: one of the two '
                . 'packages would otherwise be scanned by an implementation that no longer '
                . 'agrees with the other about what an environment access IS. The copy lives '
                . 'in ' . self::CANDY_PTY_COPY,
            );
        }
    }

    // ── the instruments ──────────────────────────────────────────────────

    /**
     * Every `.php` file directly under `tools/`, absolute and sorted.
     *
     * NOT RECURSIVE, deliberately: everything under `tools/` that is not a
     * script has its own rule ({@see testNoOtherToolsFileIntroducesAKnobOfItsOwn()})
     * because those files have no `--help` to be documented in.
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
     * a line at the block's OWN INDENT COLUMN whose first token is a bare
     * upper-case identifier. This is why `--flag  SOMETHING` under `Options:`
     * is not a row and why a variable merely NAMED in a paragraph is not
     * documented.
     *
     * THE INDENT COLUMN IS THE FIX FOR A CLAIM THIS DOC-BLOCK GOT WRONG.
     * WHAT IT SAID: "a continuation line is indented too but starts with prose,
     * so the leading token settles it." WHAT IS TRUE: the leading token settles
     * NOTHING, because `[A-Z][A-Z0-9_]*` followed by a space matches ordinary
     * English at the start of a wrapped line -- `A `, `AND `, `NOT `, `CI `.
     * MEASURED, by re-wrapping one sentence of `gen-docs.php`'s own help with
     * ZERO WORDS ADDED OR REMOVED so that `AND WRITE under, instead` moved to a
     * line start: the suite went RED with `gen-docs.php - AND` reported as a
     * documented-but-unread knob. The text that ships today is one reflow away
     * from that.
     *
     * WHY THAT WAS WORTH FIXING RATHER THAN TOLERATING, AND IT IS RULE 33's
     * SHAPE EXACTLY. The failure a contributor would have seen blesses a
     * resolution -- "Delete the row, or restore the read" -- and BOTH halves
     * are wrong for a phantom: there is no row to delete and nothing to
     * restore, so the only way out is to re-flow the paragraph until the guard
     * stops complaining, with no idea why. A guard that red-flags correct prose
     * and then prescribes damage is worse than no guard.
     *
     * WHY IT STILL EARNS ITS PLACE: the true positive is unaffected. A real
     * phantom row sits at the block's indent column like every other row, and
     * is still caught -- pinned in both directions by the fixtures on
     * {@see testEveryVariableAToolsScriptReadsIsDocumentedInItsHelp()}.
     *
     * WHAT THIS SHAPE CANNOT EXPRESS, stated rather than left to be found
     * (rule 11). A block whose rows are indented DEEPER than some other line
     * in the same block -- an intro paragraph above the rows, say -- has its
     * column taken from that other line, and its rows are then read as nothing.
     * That direction FAILS CLOSED: with no documented names, every variable the
     * script reads is reported undocumented and the suite reds loudly. It is a
     * fixture below rather than a promise. Indent is counted in raw leading
     * spaces and tabs, so a block that mixed the two would be measuring
     * characters rather than columns; nothing here does.
     *
     * @return array<string, true>
     */
    private static function environmentBlockNames(string $help): array
    {
        // PASS ONE: the block's own lines. The column cannot be known until
        // they have all been seen, which is the whole reason this is not the
        // single streaming pass it used to be.
        $block = [];
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
            if ($line[0] !== ' ' && $line[0] !== "\t") {
                $inBlock = false;

                continue;
            }
            $block[] = $line;
        }

        if ($block === []) {
            return [];
        }

        $column = null;
        foreach ($block as $line) {
            $indent = \strlen($line) - \strlen(\ltrim($line, " \t"));
            $column = $column === null ? $indent : \min($column, $indent);
        }

        $names = [];
        foreach ($block as $line) {
            if (\strlen($line) - \strlen(\ltrim($line, " \t")) !== $column) {
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

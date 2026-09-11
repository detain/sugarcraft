<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentType;
use SugarCraft\Crush\Tests\Config\Support\DocumentParagraphs;
use SugarCraft\Crush\Tests\Support\RefusesAnUnreadableSourceTrait;

/**
 * THE FOUR CLAIM FAMILIES E111 FOUND UN-ORACLED — the flag list, the exit-code
 * table, the built-in skills, the agent presets — each pinned to the generator
 * in `src/` that owes it.
 *
 * WHAT THIS IS NOT, stated first because the item's name invites the wrong
 * file: E111 was filed against "SETTINGS.md's four claim families", and the
 * families have since MOVED — `docs/SETTINGS.md` today carries layer tables
 * guarded elsewhere, not these claims. Measured 2026-09-11, the claims live
 * in: `docs/SKILLS.md` (the twelve built-in directories), `README.md` (the
 * agent-preset bullet, the non-interactive synopsis, one exit-code table),
 * and `docs/TROUBLESHOOTING.md` (a second exit-code table). This oracle reads
 * THOSE pages; the docs are read-input, and this file edits none of them.
 *
 * THE FAMILY MAP, because "un-oracled" was never global — it meant "no
 * guard exists whose census covers this": the tools/slash-commands/
 * subcommands/permission-modes rosters are pinned by
 * `\SugarCraft\Crush\Tests\Config\ReadmeRosterDriftTest`, the JSON error
 * TYPES by `\SugarCraft\Crush\Tests\Config\ReadmeJsonErrorContractDriftTest`,
 * the environment variables by {@see EnvRosterDriftTest}. Each test here
 * stays INSIDE the complement: the exit-code tests assert CODES against
 * `NonInteractive`'s constants and never touch the `error.type` lists, which
 * the type census already owns.
 *
 * BOTH DIRECTIONS, everywhere. An oracle that only checks doc-claims-have-a-
 * generator goes green while a seventh preset, a thirteenth skill, or a third
 * exit code ships undocumented — the exact drift this file was minted for. So
 * each test diffs a generated set against a claimed set and calls
 * `assertSame` on the pair: additions red, removals red, renames red. Where a
 * claim is a NUMBER ("twelve directories", "6 sub-agent presets", "three exit
 * codes") the number is re-spelled from the generated count and demanded of
 * the page, because a roster that drifts by one keeps every name it had and
 * a set diff alone would not have caught the stale word.
 *
 * LOCATORS FAIL, NEVER SKIP: every section, row, and bullet this file reads
 * is found through {@see matchOrFail()} or a guarded scrape, so a rewrite
 * that moves a claim past the locator reddens with the locator named, rather
 * than passing on an empty claim set. That is the failure half of why
 * absence-asserting tests here always come with a present-asserting fixture:
 * each test asserts its scraped sets are non-empty before comparing them.
 *
 * @internal
 */
final class ClaimFamiliesDocumentationDriftTest extends TestCase
{
    use RefusesAnUnreadableSourceTrait;

    private const README = __DIR__ . '/../../README.md';
    private const SKILLS_DOC = __DIR__ . '/../../docs/SKILLS.md';
    private const TROUBLESHOOTING_DOC = __DIR__ . '/../../docs/TROUBLESHOOTING.md';
    private const BUILT_IN_SKILLS_DIR = __DIR__ . '/../../src/Skills/BuiltIn';
    private const NON_INTERACTIVE_SRC = __DIR__ . '/../../src/Cli/NonInteractive.php';
    private const ARGV_PARSER_SRC = __DIR__ . '/../../src/Cli/ArgvParser.php';

    /**
     * Number words long enough for any claim a doc can plausibly make about
     * these four families; out-of-range counts fail with an instruction
     * instead of guessing.
     *
     * @var array<int, string>
     */
    private const NUMBER_WORDS = [
        0 => 'no', 1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five',
        6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten',
        11 => 'eleven', 12 => 'twelve', 13 => 'thirteen', 14 => 'fourteen',
        15 => 'fifteen', 16 => 'sixteen', 17 => 'seventeen', 18 => 'eighteen',
        19 => 'nineteen', 20 => 'twenty',
    ];

    /**
     * The built-in skills table row in `docs/SKILLS.md` enumerates exactly the
     * directories under `src/Skills/BuiltIn/`, and spells their count.
     */
    public function testTheBuiltInSkillsClaimIsTheBuiltInSkillsDirectory(): void
    {
        $generated = $this->builtInSkillDirectories();
        $page = DocumentParagraphs::of(self::readOrFail(self::SKILLS_DOC));

        $row = null;
        foreach ($page as $unit) {
            if (str_contains($unit, 'directories in this checkout')) {
                $this->assertNull($row, 'two units of SKILLS.md claim the built-in enumeration — which is the census?');
                $row = $unit;
            }
        }
        $this->assertNotNull(
            $row,
            'no paragraph of SKILLS.md carries the locator "directories in this checkout" — the built-in '
            . 'claim moved or was reworded past this oracle, which E111 filed as un-oracled once already',
        );

        $this->assertMatchesRegularExpression(
            '/\b' . $this->word(\count($generated)) . '\b/',
            $row,
            'SKILLS.md no longer spells the live count (' . \count($generated)
            . ') of built-in skill directories — a skill was added or removed without the word moving with it',
        );

        $matched = preg_match_all('/`([a-z0-9][a-z0-9-]*)`/', $row, $claimed);
        $this->assertIsInt($matched, 'the name scrape failed to run, so its answer means nothing');
        $names = array_values(array_unique($claimed[1]));
        sort($names);

        $this->assertNotSame([], $names, 'the built-in row enumerates no names at all — vacuous census');
        $this->assertSame(
            $generated,
            $names,
            'SKILLS.md and src/Skills/BuiltIn/ disagree about which built-ins ship — the row is a promise',
        );
    }

    /**
     * The README agent bullet counts and names exactly the `AgentType` cases.
     */
    public function testTheAgentPresetClaimIsTheAgentTypeEnum(): void
    {
        $generated = array_map(
            static fn (AgentType $case): string => $case->value,
            AgentType::cases(),
        );
        sort($generated);

        $bullet = null;
        foreach (DocumentParagraphs::of(self::readOrFail(self::README)) as $unit) {
            if (str_contains($unit, 'sub-agent presets')) {
                $this->assertNull($bullet, 'two README units claim the preset roster — which is the census?');
                $bullet = $unit;
            }
        }
        $this->assertNotNull(
            $bullet,
            'no README paragraph carries "sub-agent presets" — the bullet E111 measured at :1060 moved or '
            . 'was reworded, and this oracle is blind to prose it cannot locate',
        );

        $claimed = $this->matchOrFail(
            '/(\d+) sub-agent presets \(([a-z\/]+)\)/',
            $bullet,
            'preset count-and-roster claim',
        );
        $names = explode('/', $claimed[2]);
        sort($names);

        $this->assertNotSame([], $names, 'the preset bullet enumerates no names — vacuous census');
        $this->assertSame(
            $generated,
            $names,
            'the README preset list and AgentType::cases() disagree — a preset was added, renamed, or removed',
        );
        $this->assertSame(
            (string) \count($generated),
            $claimed[1],
            'the numeral in the preset bullet no longer counts the enum cases',
        );
    }

    /**
     * Both exit-code tables — README's and TROUBLESHOOTING's — carry exactly
     * the codes `NonInteractive` defines, and both pages spell their count.
     *
     * THE CODES ONLY: the `error.type` vocabulary inside README's rows is
     * pinned by `ReadmeJsonErrorContractDriftTest`, and this census does not
     * re-parse what its sibling already reconciles.
     */
    public function testBothExitCodeTablesAreTheNonInteractiveConstants(): void
    {
        $constants = $this->exitCodeConstants();
        $codes = $constants['codes'];

        $readme = self::readOrFail(self::README);
        $troubleshooting = self::readOrFail(self::TROUBLESHOOTING_DOC);

        $this->assertMatchesRegularExpression(
            '/The same ' . $this->word(\count($codes)) . ' exit codes govern/',
            $readme,
            'README no longer spells the live count (' . \count($codes) . ') of exit codes in its '
            . 'load-bearing sentence above the table',
        );
        $this->assertMatchesRegularExpression(
            '/^' . ucfirst($this->word(\count($codes))) . ', and the distinction is load-bearing/m',
            $troubleshooting,
            'TROUBLESHOOTING no longer spells the live count (' . \count($codes) . ') of exit codes',
        );

        $this->assertSame(
            $codes,
            $this->tableCodes($readme, '| exit | meaning |', 'README'),
            'the README exit table and NonInteractive::EXIT_* disagree — a code was added, removed, or renumbered',
        );
        $this->assertSame(
            $codes,
            $this->tableCodes($troubleshooting, '| Code | Meaning |', 'TROUBLESHOOTING'),
            'the TROUBLESHOOTING exit table and NonInteractive::EXIT_* disagree',
        );
    }

    /**
     * The non-interactive synopsis in README names only flags the parser
     * accepts, and every long flag the parser accepts is named on the doc
     * surface — with the terminator `--` pinned in both directions.
     *
     * THE REQUIRED SIDE SPANS THE WHOLE CORPUS, not just the synopsis, and
     * that is measured rather than generous: `--prompt` is never spelled in
     * the README synopsis (the block demonstrates `-p`) and appears in full
     * exactly once, in TROUBLESHOOTING's exit-2 prose. A synopsis-only
     * requirement would red on a sentence nobody wrote wrong, so the claim
     * this test can honestly enforce is "a user who reads any shipped page
     * learns every flag exists", which is what E111's flag family is for.
     * SHORT flags are exempt from the required side: they are aliases, and no
     * doc is obligated to enumerate every spelling of a long option.
     */
    public function testTheFlagSynopsisAndArgvParserAgreeInBothDirections(): void
    {
        $block = $this->synopsisFence();

        $claimed = $this->synopsisFlags($block);
        $this->assertNotSame([], $claimed, 'the synopsis fence yields no flag tokens — vacuous census');

        $parser = $this->parserFlags();

        $forged = array_values(array_diff($claimed, $parser['all']));
        $this->assertSame(
            [],
            $forged,
            'the README synopsis demonstrates option tokens the parser does not accept: ' . implode(', ', $forged),
        );

        $corpus = self::readOrFail(self::README) . "\n" . self::readOrFail(self::TROUBLESHOOTING_DOC);
        $undocumented = [];
        foreach ($parser['long'] as $flag) {
            if (!\in_array($flag, $claimed, true) && !str_contains($corpus, $flag)) {
                $undocumented[] = $flag;
            }
        }
        $this->assertSame(
            [],
            $undocumented,
            'these long flags exist in ArgvParser and appear in NO doc surface: ' . implode(', ', $undocumented)
            . ' — a shipped option nobody documents is E111’s original defect',
        );

        // The terminator is a claim in both pages' prose, not only a fence
        // token: the demonstration line must exist AND the parser must
        // special-case `--`.
        $this->assertContains('--', $claimed, 'no line of the synopsis demonstrates the `--` terminator');
        $this->assertContains('--', $parser['all'], 'ArgvParser no longer treats `--` as an option terminator');
    }

    /**
     * The synopsis fence — the `bash` code block whose last line demonstrates
     * the `--` terminator.
     *
     * SPLIT ON FENCE MARKERS, NOT MATCHED ACROSS THEM, because the fence's own
     * comments contain backticks (`# `--` ends options…` is one of its lines):
     * a lazy regex excluding backticks cannot span the block it is trying to
     * capture, and this was measured against the page, not assumed.
     */
    private function synopsisFence(): string
    {
        $blocks = [];
        foreach (explode('```', self::readOrFail(self::README)) as $part) {
            if (str_starts_with($part, "bash\n") && str_contains($part, '-- --not-a-flag')) {
                $blocks[] = $part;
            }
        }
        if (\count($blocks) !== 1) {
            $this->fail(
                'the non-interactive synopsis fence should be exactly one bash block containing the '
                . '`--not-a-flag` terminator demo; found ' . \count($blocks)
                . ' — this oracle cannot vouch for prose it cannot uniquely locate',
            );
        }

        return $blocks[0];
    }

    /**
     * `src/Skills/BuiltIn/` directory names, sorted.
     *
     * @return list<string>
     */
    private function builtInSkillDirectories(): array
    {
        $found = glob(self::BUILT_IN_SKILLS_DIR . '/*', \GLOB_ONLYDIR);
        $this->assertIsArray($found, 'the built-in skills directory does not exist, so the claim has no generator');
        $names = array_map('basename', $found);
        sort($names);

        return $names;
    }

    /**
     * `NonInteractive::EXIT_*` constants: values and their names.
     *
     * Read from SOURCE TEXT, not reflection — the sibling guard
     * `ReadmeJsonErrorContractDriftTest` established the pattern: reflection
     * into a private-const host couples this oracle to the class’s loadability
     * and constant visibility, and a rename that keeps the doc honest should
     * not be able to pass by re-typing the same three numbers somewhere else.
     *
     * @return array{codes: list<string>, names: array<string, string>}
     */
    private function exitCodeConstants(): array
    {
        $matched = preg_match_all(
            '/const (EXIT_([A-Z_]+)) = (\d+);/',
            self::readOrFail(self::NON_INTERACTIVE_SRC),
            $matches,
        );
        $this->assertIsInt($matched, 'the constant scrape failed to run, so its answer means nothing');
        $this->assertGreaterThan(
            0,
            $matched,
            'NonInteractive defines no EXIT_* constants — the exit-code tables have no generator',
        );

        $codes = [];
        $names = [];
        foreach ($matches[1] as $i => $name) {
            $codes[] = $matches[3][$i];
            $names[$matches[2][$i]] = $matches[3][$i];
        }
        sort($codes);
        $codes = array_values(array_unique($codes));

        $this->assertSame(
            ['OK' => '0', 'FAILURE' => '1', 'CONFIG' => '2'],
            $names,
            'wait — the EXIT_* name-to-code map is not the one the docs describe (OK=0, FAILURE=1, CONFIG=2); '
            . 'if a code was genuinely renumbered, this test and both tables must move together',
        );

        return ['codes' => $codes, 'names' => $names];
    }

    /**
     * The `` `N` `` first-column codes of the table introduced by `$header`,
     * sorted and deduplicated.
     *
     * @return list<string>
     */
    private function tableCodes(string $text, string $header, string $which): array
    {
        $at = strpos($text, $header);
        $this->assertNotFalse(
            $at,
            $which . ' has no "' . $header . '" table header — the exit-code claim moved out of this '
            . 'oracle’s reach, and an absent table used to read as an empty one',
        );
        $table = substr($text, $at);
        $end = strpos($table, "\n\n");
        if ($end !== false) {
            $table = substr($table, 0, $end);
        }

        $matched = preg_match_all('/^\| `(\d+)` \|/m', $table, $rows);
        $this->assertIsInt($matched, 'the table scrape failed to run, so its answer means nothing');
        $codes = array_values(array_unique($rows[1]));
        sort($codes);
        $this->assertNotSame([], $codes, $which . ' exit table has no code rows — vacuous census');

        return $codes;
    }

    /**
     * Option tokens the synopsis demonstrates, per line, STOPPING AT the `--`
     * terminator — `--not-a-flag` lives after it and is the operand the
     * terminator demonstrates, not a flag claim.
     *
     * @return list<string>
     */
    private function synopsisFlags(string $block): array
    {
        $flags = [];
        foreach (preg_split('/\R/', $block) ?: [] as $line) {
            $line = (\explode('#', $line)[0]);
            $terminator = false;
            foreach (preg_split('/\s+/', trim($line)) ?: [] as $token) {
                if ($token === '--') {
                    $flags[] = '--';
                    $terminator = true;

                    break;
                }
                if ($terminator) {
                    break;
                }
                if (\strlen($token) > 1 && $token[0] === '-' && preg_match('/\A-{1,2}[A-Za-z][A-Za-z0-9-]*\z/', $token) === 1) {
                    $flags[] = $token;
                }
            }
        }
        $flags = array_values(array_unique($flags));
        sort($flags);

        return $flags;
    }

    /**
     * Flag literals `ArgvParser` matches against argv: every long form (with
     * the `=` stripped), every short form, and the bare terminator.
     *
     * @return array{all: list<string>, long: list<string>}
     */
    private function parserFlags(): array
    {
        $matched = preg_match_all('/\'(--|-{1,2}[A-Za-z][A-Za-z0-9-]*=?)\'/', self::readOrFail(self::ARGV_PARSER_SRC), $literals);
        $this->assertIsInt($matched, 'the literal scrape failed to run, so its answer means nothing');

        $long = [];
        $all = [];
        foreach ($literals[1] as $literal) {
            $name = rtrim($literal, '=');
            $all[$name] = true;
            if (str_starts_with($name, '--')) {
                $long[$name] = true;
            }
        }
        $this->assertGreaterThan(
            0,
            \count($all),
            'ArgvParser exposes no flag literals at all — either it stopped being a string-literal switch '
            . '(then this oracle must move to its real surface, do not delete it) or the parser is broken',
        );

        $out = array_keys($all);
        sort($out);
        $longOut = array_keys($long);
        sort($longOut);

        return ['all' => $out, 'long' => $longOut];
    }

    /**
     * The spelled form of `$n`, or a failure that says to extend the table.
     */
    private function word(int $n): string
    {
        if (!isset(self::NUMBER_WORDS[$n])) {
            $this->fail('a claim family grew past number ' . $n . '; extend NUMBER_WORDS deliberately');
        }

        return self::NUMBER_WORDS[$n];
    }

    /**
     * `preg_match` or a named failure — never an assertion per call, because
     * the locator runs inside set comparisons and a failed match already
     * names itself in `fail()`'s message. The precedent is
     * `\SugarCraft\Crush\Tests\Config\GlobFigureDriftTest`'s `matchOrFail()`.
     *
     * @return array<int, string>
     */
    private function matchOrFail(string $pattern, string $text, string $what): array
    {
        $matched = preg_match($pattern, $text, $m);
        if ($matched !== 1) {
            $this->fail('the ' . $what . ' is gone or reshaped; this oracle cannot vouch for prose it cannot find');
        }

        return $m;
    }
}

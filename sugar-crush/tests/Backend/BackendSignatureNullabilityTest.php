<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend;

/**
 * **E495 — the {@see Backend} contract family must not declare an
 * implicitly-nullable parameter.**
 *
 * `callable $onToken = null` and `?callable $onToken = null` mean the same
 * thing on PHP 8.3, which this box runs and which is the ONLY version anything
 * here was executed on. The first spelling is deprecated from PHP 8.4, and CI
 * runs 8.3 AND 8.4 — so on the 8.4 leg every one of these declarations emits
 * `Implicitly marking parameter $onToken as nullable is deprecated`. Nothing in
 * this test observes that deprecation and nothing here should be read as
 * claiming to: what it asserts is a SYNTACTIC property of the source, which is
 * the half of the problem a PHP 8.3 host can actually measure.
 *
 * ## Why this cannot be a behavioural test
 *
 * The two spellings compile to the same signature, and `ReflectionParameter`
 * cannot tell them apart either. MEASURED on PHP 8.3.6 rather than asserted:
 * for BOTH spellings `getType()` stringifies to `?callable`, both report
 * `allowsNull() === true` on the type AND on the parameter, and both have a
 * default of null. There is no runtime observation that separates them, so the
 * only honest instrument is the token stream and the only honest pin for the fix
 * is a scanner over it. {@see testTheTwoSpellingsAreIndistinguishableToReflection()}
 * keeps that claim from becoming folklore.
 *
 * ## Why the scanner has its own tests
 *
 * A guard that asserts an absence and nothing else passes just as green when it
 * is dead. {@see testTheScannerStillSeesAnImplicitlyNullableParameter()} and
 * {@see testTheScannerRejectsEveryNonOffendingSpelling()} push known answers
 * through the SAME scanner in the same file, in both polarities, so an empty
 * result from {@see testTheBackendContractFamilyHasNoImplicitlyNullableParameters()}
 * is evidence rather than a shrug.
 *
 * The fixtures build their offending declarations by CONCATENATION rather than
 * spelling them out. A future textual sweep of this pattern would otherwise
 * rewrite the very fixture that proves the sweep worked.
 *
 * ## The scope is now all of `src/`, and the two arguments against it are spent
 *
 * WHAT THIS SAID: "`src/Workflows/Workflow.php` carries two more of these and
 * is not this lane's file. Widening it now would only make this file red for
 * someone else's code", followed by a correction recording that widening ALSO
 * flagged `src/ToolRegistry.php`, whose `#[\SensitiveParameter]` parameter is
 * correct — the classifier was the defect there, not the code, and it is fixed
 * and pinned in both polarities by
 * {@see testTheScannerSeesPastAnAttributeToTheParameterBehindIt()} and the
 * attributed rows of {@see nonOffendingSpellings()}. That paragraph closed by
 * saying `Workflow.php` was the only thing left between this guard and `src/`,
 * and to re-derive that with the scanner rather than trust the sentence.
 *
 * WHAT IS TRUE NOW: re-derived, and the sentence was right — exactly those two,
 * `mutate()`'s `WorkflowStatus $workflowStatus` and `bool $stopOnFirstFailure`.
 * Both are spelled with a leading question mark, and the scan is
 * {@see testNoFileInSrcHasAnImplicitlyNullableParameter()}, which WALKS `src/`
 * rather than globbing one directory of it. {@see contractFamily()} stays as
 * the narrower guard because the contract family is a population worth naming
 * on its own — a new backend must not arrive outside a guard's view even if
 * the `src/` walk is later scoped differently.
 *
 * WHY THE REASONING STILL EARNS ITS PLACE: it is the record of a widening that
 * was attempted, went red on CORRECT code, and was rolled back to fix the
 * classifier instead of to write an exemption row (rule 33). Delete it and the
 * next reader who sees `#[\SensitiveParameter]` flagged concludes the code is
 * wrong.
 *
 * ## What the scanner does NOT look at, enumerated rather than assumed
 *
 * E531: a census that reports zero means nothing unless the reader can tell
 * "nothing offends" from "nothing was looked at". {@see PARAM_KINDS} names
 * every verdict {@see classifyParamKind()} can reach; the tree is checked
 * against it in one direction by
 * {@see testTheParameterAlphabetOverSrcIsEnumerated()} and the alphabet is
 * checked against fixtures in the other by
 * {@see testEveryDeclaredParameterKindIsReachable()}, so neither a shape the
 * scanner walks past nor a kind nothing can produce stays quiet.
 *
 * Widening the alphabet found two real misses, both MEASURED on PHP 8.3.6 and
 * both now closed: a fully-qualified `\null` default (one
 * `T_NAME_FULLY_QUALIFIED` token, so the literal compare read `\null` and
 * declined), and arrow functions, which declare parameters under `T_FN` and
 * were outside the keyword alphabet entirely. Rule 11: a census's alphabet is
 * part of its coverage, and it is usually written to match the cases already
 * known.
 */
final class BackendSignatureNullabilityTest extends TestCase
{
    /**
     * The tokens that introduce a parameter list this scanner will read.
     *
     * `T_FN` is here because an arrow function declares parameters exactly like
     * a `function` does and deprecates identically on 8.4 - and it was NOT here
     * until the alphabet was enumerated rather than assumed, which is E531's
     * whole point: a scanner that cannot say what it does not look at reports
     * an absence that means nothing. MEASURED on PHP 8.3.6 before the fix:
     * `fn (callable $a = null) => 1` came back clean from a guard whose entire
     * subject is that spelling. Both keywords are exercised in both polarities
     * by {@see parameterListKeywordSpellings()}.
     *
     * @var list<int>
     */
    private const PARAMETER_LIST_KEYWORDS = [T_FUNCTION, T_FN];

    /**
     * The contract and every implementation of it that ships in this package.
     * Derived rather than listed, so a new backend cannot be added outside the
     * guard's view.
     *
     * @return list<string>
     */
    private static function contractFamily(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $files = glob($root . '/Backend/*.php');
        self::assertIsArray($files, 'src/Backend/ did not glob — the guard is looking at nothing');

        return [$root . '/Backend.php', ...$files];
    }

    public function testTheBackendContractFamilyHasNoImplicitlyNullableParameters(): void
    {
        $family = self::contractFamily();
        // Rule 18: the count is DERIVED here, never written into prose. What
        // matters is that the list is non-trivial, not what its length is today.
        $this->assertGreaterThan(1, count($family), 'the family glob collapsed to the interface alone');

        $offenders = [];
        foreach ($family as $file) {
            $source = file_get_contents($file);
            $this->assertIsString($source, "unreadable: $file");
            foreach (self::implicitlyNullableParams($source) as $hit) {
                $offenders[] = basename($file) . ': ' . $hit;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Implicitly-nullable parameter(s) in the Backend contract family.\n"
            . "Spell the type nullable (a leading question mark) instead of relying on the\n"
            . "null default to do it; the two are identical on PHP 8.3 and the second is\n"
            . "deprecated from 8.4, which CI runs.",
        );
    }

    /**
     * **E526 — the census outside the contract family, which used to be two.**
     *
     * The class docblock's narrow-scope argument named `src/Workflows/Workflow.php`
     * as the only thing between this guard and all of `src/`, and said to
     * re-derive that with the scanner rather than trust the sentence. Derived:
     * it was exactly those two, `mutate()`'s `WorkflowStatus $workflowStatus`
     * and `bool $stopOnFirstFailure`, both now spelled with a leading question
     * mark. So the scope widens here instead of being argued again.
     *
     * WALKED, not globbed: a glob of `src/Backend/*.php` cannot see a file in a
     * subdirectory, and the population this guard is about is "everything this
     * package ships", not "everything one directory holds".
     *
     * The count of files is DERIVED and never written down (rule 18) — three
     * lanes edit `src/` at once and a literal here would merge clean and be
     * arithmetically wrong. What is asserted is that the walk is non-trivial,
     * which is the property an empty walk fails.
     */
    public function testNoFileInSrcHasAnImplicitlyNullableParameter(): void
    {
        $offenders = [];
        $scanned = 0;

        foreach (self::everySourceFile() as $relative => $path) {
            $scanned++;
            foreach (self::implicitlyNullableParams((string) file_get_contents($path)) as $hit) {
                $offenders[] = $relative . ': ' . $hit;
            }
        }

        // Rule 15: the assertion below is an absence, and a walk that found no
        // files satisfies it perfectly.
        $this->assertGreaterThan(
            count(self::contractFamily()),
            $scanned,
            'the src/ walk found no more files than the Backend family glob, so it is not '
                . 'walking and the absence below is a statement about nothing',
        );

        sort($offenders);
        $this->assertSame(
            [],
            $offenders,
            "Implicitly-nullable parameter(s) in src/.\n"
            . "Spell the type nullable (a leading question mark) instead of relying on the\n"
            . "null default to do it; the two are identical on PHP 8.3 and the second is\n"
            . "deprecated from 8.4, which CI runs.",
        );
    }

    /**
     * **E531, the half that is about the SCANNER rather than the tree.**
     *
     * A census that reports zero has two readings — nothing offends, or nothing
     * was looked at — and until {@see PARAM_KINDS} existed this file could not
     * tell them apart. This test names every verdict the classifier reached
     * over `src/` and fails on one that is not in the declared alphabet, so a
     * parameter shape the scanner silently walks past cannot stay silent.
     *
     * `unparsed` is asserted at zero AND is a declared kind that
     * {@see testEveryDeclaredParameterKindIsReachable()} proves reachable — the
     * pairing rule 25 asks for, because a zero that a dead instrument also
     * returns is not evidence.
     */
    public function testTheParameterAlphabetOverSrcIsEnumerated(): void
    {
        $histogram = [];
        foreach (self::everySourceFile() as $path) {
            foreach (self::parameterKinds((string) file_get_contents($path)) as $kind) {
                $histogram[$kind] = ($histogram[$kind] ?? 0) + 1;
            }
        }
        ksort($histogram);

        $this->assertSame(
            [],
            array_values(array_diff(array_keys($histogram), self::PARAM_KINDS)),
            'the classifier reached a verdict that PARAM_KINDS does not name. Add it there with '
                . 'the parameter shape it stands for AND a row in parameterKindFixtures(), or '
                . 'the census this guard reports is quietly incomplete in exactly that shape.',
        );

        $this->assertSame(0, $histogram['unparsed'] ?? 0, 'src/ holds a parameter this scanner cannot read');
        $this->assertSame(0, $histogram['offender'] ?? 0, 'src/ holds an implicitly-nullable parameter');

        // Rule 15/25: the two zeros above are also what a dead classifier
        // returns. This is the component that fails when it stops classifying.
        $this->assertGreaterThan(
            0,
            $histogram['explicit-nullable'] ?? 0,
            'not one `?T $x = null` parameter in src/ — that is the spelling E495 converted '
                . 'eight parameters INTO, so a zero here means the classifier is dead rather '
                . 'than that the tree is clean',
        );
        $this->assertGreaterThan(
            0,
            $histogram['no-default'] ?? 0,
            'not one parameter without a default in src/, which is not a believable tree',
        );
    }

    /**
     * **The other direction: an alphabet entry nothing can produce is dead.**
     *
     * {@see testTheParameterAlphabetOverSrcIsEnumerated()} fails on a kind the
     * tree reaches that {@see PARAM_KINDS} does not name. Without this one, the
     * cure for that failure would be to append the name and move on, and
     * {@see PARAM_KINDS} would decay into the comment it is written not to be
     * (rule 40: key the exemption on structure, and prove the structure).
     *
     * Every fixture is pushed through the SAME {@see classifyParamKind()} the
     * census uses, so this cannot pass against a classifier the census does not
     * run.
     */
    public function testEveryDeclaredParameterKindIsReachable(): void
    {
        $reached = [];
        foreach (self::parameterKindFixtures() as $kind => $source) {
            $seen = self::parameterKinds($source);
            $this->assertContains(
                $kind,
                $seen,
                "the fixture for '{$kind}' classified as " . implode('/', $seen) . " instead. "
                    . 'Either the fixture no longer expresses that shape or the classifier stopped '
                    . "reaching it.\n" . $source,
            );
            $reached[] = $kind;
        }

        sort($reached);
        $expected = self::PARAM_KINDS;
        sort($expected);
        $this->assertSame(
            $expected,
            $reached,
            'PARAM_KINDS and parameterKindFixtures() disagree. A kind with no fixture is a '
                . 'verdict nobody has shown the classifier can reach, and a fixture with no kind '
                . 'is a verdict the census would report as unknown.',
        );
    }

    /**
     * One source per entry of {@see PARAM_KINDS}, each expressing that shape.
     *
     * The offending spellings are built by CONCATENATION for the reason the
     * class docblock gives: a textual sweep of this pattern must not be able to
     * rewrite the fixtures that prove the sweep worked.
     *
     * @return array<string, string>
     */
    private static function parameterKindFixtures(): array
    {
        $null = 'nu' . 'll';
        $wrap = static fn (string $params): string => "<?php\nfinal class F { public function m({$params}) {} }\n";

        return [
            'offender' => $wrap("callable \$a = {$null}"),
            'unparsed' => $wrap("#[Foo callable \$a = {$null}"),
            'empty' => $wrap(''),
            'explicit-nullable' => $wrap("?callable \$a = {$null}"),
            'union-with-null' => $wrap("callable|{$null} \$a = {$null}"),
            'mixed' => $wrap("mixed \$a = {$null}"),
            'untyped' => $wrap("\$a = {$null}"),
            'no-default' => $wrap('callable $a'),
            'default-not-null' => $wrap('int $a = 3'),
        ];
    }

    /**
     * Every kind the classifier reaches in `$source`, in order.
     *
     * Shares {@see splitParams()} and {@see classifyParamKind()} with
     * {@see implicitlyNullableParams()} rather than re-walking, so the
     * enumeration cannot drift from the census it is describing — which is this
     * repository's own recurring defect and not a hypothetical one.
     *
     * @return list<string>
     */
    private static function parameterKinds(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $kinds = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || !in_array($token[0], self::PARAMETER_LIST_KEYWORDS, true)) {
                continue;
            }

            $open = $i + 1;
            while ($open < $count && $tokens[$open] !== '(') {
                if ($tokens[$open] === '{' || $tokens[$open] === ';') {
                    break;
                }
                $open++;
            }
            if ($open >= $count || $tokens[$open] !== '(') {
                continue;
            }

            foreach (self::splitParams($tokens, $open, $count) as $param) {
                $kinds[] = self::classifyParamKind($param)[0];
            }
        }

        return $kinds;
    }

    /**
     * Every `.php` file under `src/`, keyed by its path relative to `src/`.
     *
     * @return array<string, string>
     */
    private static function everySourceFile(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $found = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $found[substr($file->getPathname(), strlen($root) + 1)] = $file->getPathname();
        }
        ksort($found);

        return $found;
    }

    /**
     * Rule 15's known-positive: the same scanner, in the same test file, on a
     * source it MUST flag. Without this, a scanner mutated to return `[]`
     * unconditionally leaves the assertion above green.
     */
    public function testTheScannerStillSeesAnImplicitlyNullableParameter(): void
    {
        // Built by concatenation on purpose - see the class docblock.
        $offending = 'callable $onToken = ' . 'null';
        $source = "<?php\nfinal class F { public function m(array \$h, {$offending}): void {} }\n";

        $this->assertSame(
            ['$onToken (callable)'],
            self::implicitlyNullableParams($source),
            'the scanner is dead: it cannot see the very shape it exists to find',
        );
    }

    /**
     * The other polarity (rule 33): every spelling that is NOT an offender must
     * come back clean, or the guard is a licence to rewrite correct signatures.
     *
     * @dataProvider nonOffendingSpellings
     */
    public function testTheScannerRejectsEveryNonOffendingSpelling(string $label, string $param): void
    {
        $source = "<?php\nfinal class F { public function m({$param}): void {} }\n";

        $this->assertSame([], self::implicitlyNullableParams($source), $label);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function nonOffendingSpellings(): array
    {
        $null = 'null';

        return [
            'explicitly nullable'   => ['a leading ? is the fix, not the defect', '?callable $a = ' . $null],
            'union carrying null'   => ['a union with null is already explicit', 'callable|null $b = ' . $null],
            'union, null first'     => ['order in the union must not matter', 'null|string $c = ' . $null],
            'mixed'                 => ['mixed already includes null', 'mixed $d = ' . $null],
            'untyped'               => ['no type declaration, no deprecation', '$e = ' . $null],
            'non-null default'      => ['a non-null default is not this defect', 'string $f = \'x\''],
            'no default at all'     => ['a required parameter is not this defect', 'callable $g'],
            'constant default'      => ['a constant default is not a bare null', 'int $h = \PHP_INT_MAX'],
            'attributed + explicit' => ['an attribute is not part of the type', '#[\SensitiveParameter] ?callable $i = ' . $null],
            'attributed, args'      => ['an attribute argument list is not the type either', '#[Foo(1, 2)] ?string $j = ' . $null],
        ];
    }

    /**
     * **The offender polarity of the attribute case, which is where this
     * scanner was wrong in BOTH directions from one missing token.**
     *
     * `#[` arrives from `token_get_all()` as T_ATTRIBUTE — an ARRAY token, not
     * the string `[` — so {@see splitParams()}'s bracket comparison never saw
     * it open anything, while the group's closing `]` is a bare string and did
     * close something. Depth fell to 0 at the first attributed parameter and
     * the walk broke out of the entire parameter list.
     *
     * Measured on PHP 8.3.6 before the fix: `#[Foo] ?callable $a = null` came
     * back as `<unparsed>` (the guard reddening CORRECT code, and telling its
     * reader to add a question mark that is already there), and
     * `#[Foo] ?callable $a = null, callable $b = null` never reported `$b` at
     * all (a real offender invisible). `src/ToolRegistry.php` carries
     * `#[\SensitiveParameter]` today, so this was not hypothetical: it is what
     * a widened scan would have hit first.
     *
     * @dataProvider attributedSpellings
     */
    public function testTheScannerSeesPastAnAttributeToTheParameterBehindIt(string $label, string $params, array $expected): void
    {
        $source = "<?php\nfinal class F { public function m({$params}): void {} }\n";

        $this->assertSame($expected, self::implicitlyNullableParams($source), $label);
    }

    /** @return array<string, array{0: string, 1: string, 2: list<string>}> */
    public static function attributedSpellings(): array
    {
        // Built by concatenation on purpose - see the class docblock.
        $null = 'null';

        return [
            'attribute hides an offender' => [
                'an attribute must not hide the parameter behind it',
                '#[\SensitiveParameter] callable $a = ' . $null,
                ['$a (callable)'],
            ],
            'attribute with arguments' => [
                'an attribute argument list must not hide it either',
                '#[Foo(1, 2)] callable $a = ' . $null,
                ['$a (callable)'],
            ],
            'attribute with a nested array argument' => [
                'a `[` inside the attribute must not close the attribute',
                '#[Foo([1, 2])] callable $a = ' . $null,
                ['$a (callable)'],
            ],
            'the walk must continue past the attributed parameter' => [
                'an offender AFTER an attributed parameter must still be seen',
                '#[Foo] ?callable $a = ' . $null . ', callable $b = ' . $null,
                ['$b (callable)'],
            ],
            'two attributes, one group' => [
                'a comma inside the attribute group is not a parameter boundary',
                '#[Foo, Bar] callable $a = ' . $null,
                ['$a (callable)'],
            ],
        ];
    }

    /**
     * Rule 14, at the one place this scanner can genuinely lose its footing: an
     * attribute group that never closes must be REPORTED, not silently dropped.
     *
     * Reachability established rather than assumed — the first version of the
     * `null` branch in {@see stripAttributes()} could not be reached at all,
     * because {@see splitParams()} ran off the end of the token list without
     * ever emitting the parameter it had collected. A hand-built fixture with a
     * typo in it then looked exactly like a fixture that parses clean, which is
     * the failure mode this whole file exists to avoid.
     */
    public function testAnAttributeGroupThatNeverClosesIsReportedRatherThanSwallowed(): void
    {
        $null = 'null';
        $source = "<?php\nfinal class F { public function m(#[Foo callable \$a = {$null}): void {} }\n";

        $hits = self::implicitlyNullableParams($source);
        $this->assertCount(1, $hits, 'a parameter list that never closed vanished without a word');
        $this->assertStringStartsWith('<unparsed>', $hits[0], 'the unreadable parameter was classified as if it were readable');
    }

    /**
     * Every parameter that has a non-nullable type declaration AND a default of
     * exactly `null`.
     *
     * Token-level rather than a regular expression: a signature wraps across
     * lines, carries attributes, promoted-property modifiers, by-reference
     * markers and doc-comments, and a line-oriented match reads all of those
     * wrong. Anything it cannot resolve to a variable is reported as an
     * offender-shaped `<unparsed>` entry rather than dropped (rule 14) — a
     * guard that silently ignores what it cannot read has a hole in exactly the
     * shape of the next defect.
     *
     * @return list<string>
     */
    private static function implicitlyNullableParams(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $hits = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || !in_array($token[0], self::PARAMETER_LIST_KEYWORDS, true)) {
                continue;
            }

            $open = $i + 1;
            while ($open < $count && $tokens[$open] !== '(') {
                // A `function` keyword whose body starts before any '(' is not
                // a signature this scanner understands; stop rather than run on
                // into the next one.
                if ($tokens[$open] === '{' || $tokens[$open] === ';') {
                    break;
                }
                $open++;
            }
            if ($open >= $count || $tokens[$open] !== '(') {
                continue;
            }

            foreach (self::splitParams($tokens, $open, $count) as $param) {
                $hit = self::classifyParam($param);
                if ($hit !== null) {
                    $hits[] = $hit;
                }
            }
        }

        return $hits;
    }

    /**
     * Slice the balanced parameter list starting at the '(' at $open into one
     * token list per parameter.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return list<list<array{0:int,1:string,2:int}|string>>
     */
    private static function splitParams(array $tokens, int $open, int $count): array
    {
        $depth = 0;
        $params = [];
        $current = [];
        $closed = false;

        for ($k = $open; $k < $count; $k++) {
            $token = $tokens[$k];
            // `#[` is T_ATTRIBUTE - an ARRAY token, not the string '['. Before
            // this was handled, the opener was invisible to the comparison below
            // while its bare `]` still DECREMENTED, driving depth to 0 and
            // breaking out of the whole parameter list at the first attributed
            // parameter. Measured on PHP 8.3.6: `#[Foo] ?callable $a = null`
            // came back as an offender (a false positive on correct code) and
            // `#[Foo] ?callable $a = null, callable $b = null` never saw $b at
            // all (a false negative on a real one) - wrong in both polarities
            // from one missing case.
            if ($token === '(' || $token === '[' || (is_array($token) && $token[0] === T_ATTRIBUTE)) {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif ($token === ')' || $token === ']') {
                $depth--;
                if ($depth === 0) {
                    $params[] = $current;
                    $closed = true;
                    break;
                }
            } elseif ($token === ',' && $depth === 1) {
                $params[] = $current;
                $current = [];

                continue;
            }
            $current[] = $token;
        }

        // Rule 14: running off the end means the list never closed - malformed
        // input, or a hand-built fixture with a typo in it. Emitting what was
        // collected sends it to {@see classifyParam()}, which reports it as
        // `<unparsed>`; dropping it here would make a fixture that does not
        // parse look identical to one that parses clean. Keyed on $closed and
        // not on `$current !== []`, because a well-formed list leaves its last
        // parameter in $current after the break and that spelling appended
        // every final parameter TWICE.
        if (!$closed) {
            $params[] = $current;
        }

        return $params;
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $param
     */
    private static function classifyParam(array $param): ?string
    {
        [$kind, $detail] = self::classifyParamKind($param);

        return match ($kind) {
            'offender' => $detail,
            'unparsed' => '<unparsed> ' . $detail,
            default => null,
        };
    }

    /**
     * **E531 — every verdict this scanner can reach, named.**
     *
     * {@see classifyParam()} answers `null` for eight different reasons and a
     * string for two, so an empty census could mean "nothing offends" or
     * "nothing was looked at" and no reader could tell which. That is the shape
     * rule 14 is about, one level up from the `<unparsed>` case it already
     * handled: the scanner went quiet on whole classes of parameter without
     * saying which classes those were.
     *
     * The list is not a comment. {@see testEveryDeclaredParameterKindIsReachable()}
     * pushes a fixture through THIS function for every entry and fails on an
     * entry nothing can produce, and {@see testTheParameterAlphabetOverSrcIsEnumerated()}
     * fails on a kind the tree produces that is not listed. A dead alphabet
     * entry and a missing one are both red.
     *
     * @var list<string>
     */
    private const PARAM_KINDS = [
        'offender',           // `callable $a = null` — the thing this guard hunts
        'unparsed',           // rule 14: this scanner could not read it
        'empty',              // nothing left after filtering — a trailing comma, or `()`
        'explicit-nullable',  // `?callable $a = null` — already correct
        'union-with-null',    // `callable|null $a = null` — already correct
        'mixed',              // `mixed $a = null` — nullable by definition of the type
        'untyped',            // `$a = null` — no type, so no implicit nullability to mark
        'no-default',         // `callable $a` — nothing to be implicitly nullable ABOUT
        'default-not-null',   // `int $a = 3`, `array $a = []`, `int $a = self::X`
    ];

    /**
     * @param list<array{0:int,1:string,2:int}|string> $param
     * @return array{0: string, 1: string} the kind, and the detail the caller reports
     */
    private static function classifyParamKind(array $param): array
    {
        $param = array_values(array_filter(
            $param,
            static fn ($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        if ($param === []) {
            return ['empty', ''];
        }

        $stripped = self::stripAttributes($param);
        if ($stripped === null) {
            // Rule 14: an attribute group that does not close is something this
            // scanner cannot read, and saying so is the point.
            return ['unparsed', self::flatten($param)];
        }
        $param = $stripped;
        if ($param === []) {
            return ['empty', ''];
        }

        $varIndex = null;
        foreach ($param as $index => $token) {
            if (is_array($token) && $token[0] === T_VARIABLE) {
                $varIndex = $index;
                break;
            }
        }
        if ($varIndex === null) {
            // Rule 14: a parameter with no variable in it is something this
            // scanner does not understand, and saying so is the point.
            return ['unparsed', self::flatten($param)];
        }

        $default = array_slice($param, $varIndex + 1);
        if ($default === [] || $default[0] !== '=') {
            return ['no-default', ''];
        }
        $default = array_slice($default, 1);
        if (count($default) !== 1) {
            return ['default-not-null', ''];  // `= self::X`, `= [..]`, `= -1`
        }
        // `\null` is a legal spelling of the same constant and tokenises as ONE
        // T_NAME_FULLY_QUALIFIED, so it arrives here as the string '\null' and
        // used to fall through as "some other default". MEASURED on PHP 8.3.6:
        // `callable $a = \null` reflects as `?callable` with allowsNull() true
        // and a default of null - the same implicit nullability, and the same
        // 8.4 deprecation - so reading past the separator is a fix and not a
        // widening of what this guard claims to be about.
        $literal = ltrim(is_array($default[0]) ? $default[0][1] : $default[0], '\\');
        if (strtolower($literal) !== 'null') {
            return ['default-not-null', ''];
        }

        $type = self::flatten(array_slice($param, 0, $varIndex));
        if ($type === '') {
            return ['untyped', ''];  // no type, so no implicit nullability to mark
        }
        if (str_starts_with($type, '?')) {
            return ['explicit-nullable', ''];
        }
        if (strtolower($type) === 'mixed') {
            return ['mixed', ''];
        }
        foreach (explode('|', $type) as $member) {
            if (strtolower(trim($member)) === 'null') {
                return ['union-with-null', ''];
            }
        }

        return [
            'offender',
            (is_array($param[$varIndex]) ? $param[$varIndex][1] : '?') . ' (' . $type . ')',
        ];
    }

    /**
     * The same parameter with every attribute group (`#[...]`) removed.
     *
     * Attributes are not part of a parameter's TYPE, and leaving them in makes
     * both of {@see classifyParam()}'s decisions answer about the wrong string:
     * `#[Foo]?callable` does not start with `?`, so a correctly-spelled
     * parameter reads as an offender. Nesting is tracked rather than assumed,
     * because an attribute argument may itself contain `[` (`#[Foo([1, 2])]`).
     *
     * @param list<array{0:int,1:string,2:int}|string> $param
     * @return list<array{0:int,1:string,2:int}|string>|null null when a group
     *         never closes - the caller must report that, not swallow it.
     */
    private static function stripAttributes(array $param): ?array
    {
        $out = [];
        $depth = 0;

        foreach ($param as $token) {
            $isAttribute = is_array($token) && $token[0] === T_ATTRIBUTE;
            if ($depth === 0) {
                if ($isAttribute) {
                    $depth = 1;

                    continue;
                }
                $out[] = $token;

                continue;
            }
            if ($isAttribute || $token === '[') {
                $depth++;
            } elseif ($token === ']') {
                $depth--;
            }
        }

        return $depth === 0 ? $out : null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function flatten(array $tokens): string
    {
        $out = '';
        foreach ($tokens as $token) {
            if (is_array($token)) {
                // Modifiers, variadics and by-reference markers are not part of
                // the TYPE, and leaving them in would make `?` detection and the
                // union split answer about the wrong string.
                if (in_array($token[0], [T_PUBLIC, T_PRIVATE, T_PROTECTED, T_READONLY, T_ELLIPSIS], true)) {
                    continue;
                }
                if (defined('T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG')
                    && $token[0] === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG) {
                    continue;
                }
                $out .= $token[1];

                continue;
            }
            if ($token === '&') {
                continue;
            }
            $out .= $token;
        }

        return trim($out);
    }

    /**
     * The premise of this whole file, measured rather than asserted: reflection
     * genuinely cannot separate the two spellings, so a reflection-based guard
     * would be a dead instrument no matter how it was written.
     *
     * The two declarations are built by CONCATENATION and evaluated, so neither
     * spelling appears literally in this file — a future textual sweep of the
     * deprecated form would otherwise rewrite the fixture that proves why the
     * sweep is needed.
     */
    public function testTheTwoSpellingsAreIndistinguishableToReflection(): void
    {
        $class = 'NullabilityProbe' . bin2hex(random_bytes(6));
        $implicit = 'callable $a = ' . 'null';
        $explicit = '?' . 'callable $b = ' . 'null';
        eval("final class {$class} { public function implicit({$implicit}): void {} "
            . "public function explicit({$explicit}): void {} }");

        $seen = [];
        foreach (['implicit', 'explicit'] as $method) {
            $parameter = (new \ReflectionMethod($class, $method))->getParameters()[0];
            $type = $parameter->getType();
            $seen[$method] = [
                'type' => (string) $type,
                'typeAllowsNull' => $type?->allowsNull(),
                'paramAllowsNull' => $parameter->allowsNull(),
                'defaultIsNull' => $parameter->isDefaultValueAvailable() && $parameter->getDefaultValue() === null,
            ];
        }

        $this->assertSame(
            $seen['explicit'],
            $seen['implicit'],
            'reflection CAN separate the two spellings on this PHP - if so, prefer it over the token scan',
        );
        // Positive component (rule 25): an all-null reading would also compare
        // equal, and would prove nothing.
        $this->assertSame('?callable', $seen['implicit']['type']);
        $this->assertTrue($seen['implicit']['typeAllowsNull']);
        $this->assertTrue($seen['implicit']['defaultIsNull']);
    }

    /**
     * The interface itself is reachable from this namespace — asserted so a
     * rename cannot leave the glob above scanning a directory whose contents no
     * longer implement anything.
     */
    public function testTheContractItselfIsStillThere(): void
    {
        $this->assertTrue(interface_exists(Backend::class));
    }
}

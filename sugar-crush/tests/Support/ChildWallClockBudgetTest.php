<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * A CHILD BOUND AT THE TEST'S OWN CEILING IS A BOUND THAT NEVER REPORTS.
 *
 * This suite launches `bin/sugarcrush` as a real child in a dozen places, each
 * wrapped in `timeout -s KILL N` so a wedged child cannot stall the run. The
 * test then asserts on the child's exit status — 137 when the budget killed it,
 * something else when it did not — and that assertion is the whole point of the
 * wrapper.
 *
 * BUT THE PARENT HAS A BUDGET TOO. `phpunit.xml` sets `enforceTimeLimit` with a
 * `defaultTimeLimit`, enforced by `pcntl_alarm()`, and with `failOnRisky` an
 * abort is a red run. When the child's budget EQUALS the parent's, the two
 * alarms are racing over the same instant and the parent's wins in practice:
 * the child was started some milliseconds into the test, so it is still the
 * parent that reaches its limit first. What the reader then gets is "This test
 * was aborted after N seconds" — a message that names no child, sheds every
 * assertion below the `exec()`, and looks identical whatever the child was
 * doing.
 *
 * TWELVE SITES IN SIX FILES SAT AT EXACTLY THE CEILING, which is how this got
 * written: the number in the command and the number in `phpunit.xml` were the
 * same 60, and nothing connected them. `tests/Cli/BootstrapSkillSkipsTest.php`
 * had already reasoned it out in prose — its failure message warns that "at or
 * past the per-test limit in phpunit.xml, PHPUnit's own alarm wins the race
 * instead" — and had picked 20 seconds for exactly that reason. The prose was
 * right and unenforced; twelve later call sites were written without it.
 *
 * WHAT THIS ASSERTS, and it is a RELATION rather than a number: every child
 * wall-clock wrapper in `tests/` has a budget strictly under the
 * `defaultTimeLimit` that `phpunit.xml` actually declares, with headroom. Both
 * sides are READ from the tree, so editing either one is caught: raising a
 * child budget reds here, and so does lowering `defaultTimeLimit` under an
 * existing child budget. No count is asserted (rule 18) — the census
 * re-derives itself.
 *
 * WHAT THIS SAID, AND THE SENTENCE WAS THE DEFECT: "every literal
 * `<wrapper> -s KILL N`". That named the shape the scan could express, and the
 * scan could express two. {@see childBudgets()} carries the measurement — two
 * live sites used the plain unflagged form and neither census had ever seen
 * them, so raising one to 300 against a 60-second parent limit left the whole
 * guard green. The alphabet now covers the flags `timeout(1)` accepts, and a
 * budget token it recognises but cannot evaluate becomes a REPORTED row rather
 * than a silent absence.
 *
 * THE PARAMETRISED FORM IS EVALUATED NOW, AND FINDING THAT OUT COST THE CLAIM
 * THAT SAID IT NEED NOT BE. WHAT THIS PARAGRAPH SAID: a budget passed through
 * `sprintf()` as a placeholder rather than as a digit "is not EVALUATED", and
 * "those sites are at 20 by inspection, MEASURED at the time of writing".
 * WHAT IS TRUE NOW: {@see resolvedParametrisedIn()} follows the argument list
 * through the token stream, and the first thing it reported was that the
 * inspection had ALREADY ROTTED — the sites resolve to 20, 30, 20, 20 and,
 * through a parameter whose two callers pass two different constants, 6 and
 * 30. Not one of them was over the ceiling, so the TREE was fine and the
 * SENTENCE was not, which is the entire argument for deriving a load-bearing
 * number instead of remembering it (rule 3). WHY THE PARAGRAPH STILL EARNS ITS
 * PLACE: the reason the form went unevaluated for as long as it did — "a scan
 * that guesses is worse than one that says what it cannot see" — is still the
 * governing rule, and the resolver obeys it rather than escaping it. What it
 * cannot reduce to a number it REPORTS, with the expression it choked on, and
 * {@see testBothCensusesSeeTheSameParametrisedSites()} reds on a non-empty
 * report rather than passing over it.
 *
 * NO COUNT OF THEM IS WRITTEN HERE, and the earlier draft of this paragraph is
 * why. WHAT IT SAID: "which two files use". WHAT IS TRUE NOW: it was five
 * sites in four files when that sentence was written, so the number was wrong
 * in the commit that shipped it — and it was a cardinality over `tests/`,
 * which the next lane to add a launch helper invalidates anyway (rule 18).
 * WHY THE SENTENCE STILL EARNS ITS PLACE: the SHAPE is the coverage statement,
 * and a reader who does not know this form exists will read the empty verdict
 * below as covering it. The values recited above are provenance for a
 * FALSIFICATION, not a figure this guard asserts — the guard asserts the
 * relation, and re-derives every number in it on every run.
 *
 * TWO INSTRUMENTS WALK THE SAME POPULATION, AND THE CLAIM THAT THEY COVER EACH
 * OTHER WAS TOO STRONG. WHAT THIS SAID: "either going blind is invisible on its
 * own, and neither can go blind without disagreeing with the other." WHAT IS
 * TRUE NOW: measured by mutation, one shape took BOTH out at the same instant.
 * Respelling an existing budget as an interpolation — `"... KILL {$bound} ..."`
 * — leaves no digits for the text census and no `%d` for the token census, so
 * the site simply left the population: no row, no `unresolved` entry, and no
 * disagreement for {@see testBothCensusesSeeTheSameParametrisedSites()} to
 * catch, because two censuses that both see nothing agree perfectly. WHY THE
 * PAIRING STILL EARNS ITS PLACE: the cross-check is real and it does catch a
 * census going blind ONE AT A TIME, which is the likelier accident. What it
 * cannot catch is a site LEAVING the population, and that is now a separate
 * mechanism rather than a hope — {@see wallClockWrappersIn()} reports a
 * wrapper whose budget it cannot read as `unresolved` rather than as absent,
 * so the interpolated form reds instead of disappearing.
 *
 * AND THIS FILE DOES NOT SPELL EITHER FORM (rule 26, and rule 40 under it).
 * The census walks its own directory, so a wrapper-and-number written out in a
 * paragraph here is scraped as a real child budget and a wrapper-and-`%d` is
 * scraped as a real parametrised one — which is exactly how the liveness arm
 * below came to be satisfied by the sentence describing it. Every occurrence
 * in this file is assembled at run time, and
 * {@see testThisFileIsNotItsOwnEvidence()} pins that.
 *
 * MEASURED ON PHP 8.3.6, PHPUnit 10.5.64. `timeout(1)` is coreutils and is not
 * a PHP behaviour, so the stamp is provenance for the surrounding claims.
 *
 * @internal
 */
final class ChildWallClockBudgetTest extends TestCase
{
    // THE WALK AND THE READ ARE BORROWED RATHER THAN GROWN AGAIN. The first
    // draft had its own `realpath(__DIR__ . '/../..')`, which
    // `DuplicatedTestHelperDriftTest` reported as a one-token copy of the same
    // helper in `SymbolCitationDriftTest` — a private helper has no other
    // reader, so a copy fixed in one place stays green in both.
    use RefusesAnUnreadableSourceTrait;
    use TestFileWalkTrait;

    /**
     * How much of the parent's budget must remain unspent.
     *
     * A child bound one second under the parent's is still a race — the parent
     * started first and is always ahead. This is the margin that makes the
     * child's alarm the one that reports, and it is generous because the cost
     * of being wrong in the other direction is only a louder failure.
     */
    private const REQUIRED_HEADROOM_SECONDS = 10;

    /**
     * The wrapper command itself, spelled in halves.
     *
     * RULE 26/40, AND THIS FILE HAS PAID FOR IT ONCE ALREADY. The census walks
     * `tests/`, which includes this file. Every occurrence of the command in
     * this file's own STRING LITERALS goes through here, so no literal here
     * ever carries the word followed by a space — which is the shape the scan
     * below keys on. {@see testThisFileIsNotItsOwnEvidence()} pins that the
     * census sees nothing here at all, and it is a token-stream fact rather
     * than a promise about prose.
     */
    private const WRAPPER = 'time' . 'out';

    /**
     * The per-test limit `phpunit.xml` actually declares, read rather than
     * remembered.
     *
     * A GUARD THAT CANNOT READ ITS OWN REFERENCE MUST GO RED (rule 14): a
     * missing attribute here would otherwise make every comparison below
     * vacuous.
     */
    private function defaultTimeLimit(): int
    {
        $xml = self::readOrFail(\dirname(__DIR__, 2) . '/phpunit.xml');

        self::assertSame(
            1,
            preg_match('/\bdefaultTimeLimit="(\d+)"/', $xml, $m),
            'phpunit.xml declares no defaultTimeLimit, so nothing here is comparing against '
            . 'anything; this guard must be re-derived rather than left passing',
        );

        self::assertSame(
            1,
            preg_match('/\benforceTimeLimit="true"/', $xml),
            'enforceTimeLimit is off, so the parent alarm this guard reasons about does not '
            . 'fire; the reasoning has to be rewritten rather than the assertion relaxed',
        );

        return (int) $m[1];
    }

    /**
     * Every child wall-clock wrapper in `tests/`, in all three classifications.
     *
     * WHAT THIS SAID, AND WHY THE SENTENCE COST MORE THAN THE CODE: "every
     * literal child budget in `tests/`, and every parametrised one". WHAT IS
     * TRUE NOW: it was never every one. The scan was
     * `/<wrapper> -s KILL (\d+|%d)/` over raw source, and an alphabet is
     * coverage (rule 11) — that one could express exactly two shapes and
     * silently answered "no budget here" to everything else. MEASURED at the
     * commit that widened it, two LIVE sites were invisible to it:
     * `tests/Backend/CommandBackendTest.php` and
     * `tests/Backend/StreamingCommandBackendTest.php` both wrap a probe in the
     * plain `<wrapper> 10` form with no signal flag. Raising either to 300 —
     * five times the parent's whole limit — left the guard GREEN. A second
     * shape was worse: spelling an existing budget as an interpolation
     * (`"... KILL {$bound} ..."`) removed the site from BOTH censuses at once,
     * so it produced no `unresolved` row and no cross-census disagreement
     * either, which falsified this file's own claim that neither instrument
     * can go blind without the other noticing.
     *
     * WHAT THE ALPHABET IS NOW: the wrapper, optionally preceded by a printf
     * conversion (`%s<wrapper>` is how one real site builds its prefix, and
     * `\b` does not fire between `s` and `t`), then any run of the flags
     * `timeout(1)` accepts ahead of its duration, then one budget token. The
     * token is classified rather than required: a run of digits is `literal`,
     * `%d` is `parametrised` and goes to the resolver, and ANYTHING ELSE the
     * alphabet can recognise as a budget — another conversion, a `{$var}`, a
     * shell `$VAR`, a suffixed duration like `10s` — becomes an `unresolved`
     * row carrying the token it choked on (rule 14). So does a literal that
     * ENDS at the wrapper, which is what an interpolated or concatenated budget
     * looks like from inside the token stream.
     *
     * IT READS STRING LITERALS, NOT RAW TEXT, and that is the structural half
     * (rule 40). The old raw scan also matched prose: two of the rows it
     * reported at the widening commit were sentences in doc-blocks describing
     * the shape, counted as real child budgets. Reading only
     * `T_CONSTANT_ENCAPSED_STRING` and `T_ENCAPSED_AND_WHITESPACE` drops both
     * without needing to know anything about what the prose says.
     *
     * WHAT IT STILL CANNOT SEE, said plainly rather than left to be discovered:
     * a wrapper assembled ACROSS a concatenation in a way that puts no
     * whitespace at the literal's end — `'time' . 'out 5 sh'` is invisible, and
     * so is a budget built by `implode()` or read from a variable that never
     * appears in a string. The first of those is this file's own discipline and
     * would be a deliberate act anywhere else; the second lands in `unresolved`
     * whenever the wrapper and the whitespace are in the same literal, which is
     * every spelling seen in this tree.
     *
     * @return array{
     *     literal: list<array{0: string, 1: int, 2: int, 3: string}>,
     *     parametrised: list<string>,
     *     unresolved: list<string>,
     * }
     */
    private function childBudgets(): array
    {
        $literal = [];
        $parametrised = [];
        $unresolved = [];

        foreach (self::everyTestFile() as $relative => $path) {
            $label = 'tests/' . $relative;

            foreach (self::wallClockWrappersIn(self::readOrFail($path)) as [$line, $kind, $seconds, $why]) {
                if ($kind === 'literal') {
                    $literal[] = [$label, $line, $seconds, $why];

                    continue;
                }
                if ($kind === 'parametrised') {
                    $parametrised[] = $label . ':' . $line;

                    continue;
                }
                $unresolved[] = $label . ':' . $line . ' — ' . $why;
            }
        }
        sort($literal);
        sort($parametrised);
        sort($unresolved);

        return ['literal' => $literal, 'parametrised' => $parametrised, 'unresolved' => $unresolved];
    }

    /**
     * Every child wall-clock wrapper in ONE source, classified, from the tokens.
     *
     * The three classifications are `literal` (the budget is a run of digits,
     * carried in element 2), `parametrised` (it is `%d`, and
     * {@see resolvedParametrisedIn()} is what reads its value) and `unresolved`
     * (element 3 says what the scan choked on). A shape this cannot reduce is
     * NEVER dropped — a census that quietly ignores what it cannot parse has a
     * hole shaped exactly like the next defect (rule 14).
     *
     * @return list<array{0: int, 1: string, 2: int, 3: string}>
     */
    private static function wallClockWrappersIn(string $source): array
    {
        // A printf conversion binds tight against the wrapper (`%s<wrapper>`),
        // and `\b` does not fire between two word characters — so the left
        // boundary has to name that case rather than rely on `\b`.
        $before = '(?:^|[^A-Za-z0-9_%]|%[a-zA-Z]|%\d+\$[a-zA-Z])';
        $flags = '(?:[ \t]+(?:-[sk][ \t]+[^\s\'"]+|--(?:signal|kill-after)=[^\s\'"]+'
            . '|--(?:preserve-status|foreground|verbose)|-v))';
        $budget = '(?:\d+|%\d+\$[a-zA-Z]|%[a-zA-Z]|\{\$[^}]+\}|\$[A-Za-z_][A-Za-z0-9_]*|\d[\w.]*)';
        $whole = '/' . $before . self::WRAPPER . '\b(' . $flags . '*)[ \t]+(' . $budget . ')(?![\w.])/';
        $cut = '/' . $before . self::WRAPPER . '\b(' . $flags . '*)[ \t]+$/';

        $rows = [];

        foreach (token_get_all($source) as $token) {
            if (!\is_array($token)
                || !\in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            $text = $token[1];
            $start = $token[2];
            // A LITERAL CAN SPAN LINES, so the token's own line is where it
            // BEGINS and the row's line is that plus the newlines ahead of the
            // hit. The regex census used to derive this from a whole-file
            // offset; the two agree.
            $at = static fn (int $offset): int => $start + substr_count(substr($text, 0, $offset), "\n");

            if (preg_match_all($whole, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
                foreach ($matches as $hit) {
                    [$word, $offset] = $hit[2];
                    // THE COMMAND AS MATCHED, never a spelling assembled from
                    // the shape the scan was first written against. The row for
                    // an unflagged site used to be rendered with `-s KILL` in
                    // it because the renderer hard-coded the flags — a failure
                    // message quoting a command that is not in the file.
                    $as = rtrim(self::WRAPPER . preg_replace('/[ \t]+/', ' ', $hit[1][0]));
                    if (preg_match('/^\d+$/', $word) === 1) {
                        $rows[] = [$at($offset), 'literal', (int) $word, $as];

                        continue;
                    }
                    if ($word === '%d') {
                        $rows[] = [$at($offset), 'parametrised', 0, $as];

                        continue;
                    }
                    $rows[] = [
                        $at($offset),
                        'unresolved',
                        0,
                        'the budget is `' . $word . '`, which is neither an integer literal nor '
                        . 'the `%d` the token census knows how to follow. Spell it as a digit, '
                        . 'or teach the resolver this shape — do not leave it unread',
                    ];
                }
            }

            if (preg_match($cut, $text, $tail, PREG_OFFSET_CAPTURE) === 1) {
                $rows[] = [
                    $at($tail[0][1]),
                    'unresolved',
                    0,
                    'the wrapper is at the very END of a string literal, so its budget arrives '
                    . 'from an interpolation or a concatenation and is not in any text this '
                    . 'census can read. That shape is invisible to the token census too, which '
                    . 'is why it reds here rather than going quiet in both',
                ];
            }
        }

        return $rows;
    }

    /**
     * The needle both censuses look for, assembled rather than spelled.
     *
     * RULE 26/40, AND THIS FILE HAS PAID FOR IT ONCE ALREADY. The census walks
     * `tests/`, which includes this file, so a literal occurrence here is
     * scraped as a real parametrised site and the liveness arms below start
     * being satisfied by this file's own text. Every occurrence in this file
     * goes through here, and {@see testThisFileIsNotItsOwnEvidence()} pins that
     * BOTH censuses see nothing here at all.
     *
     * The regex census spells its own needle as a PATTERN, which is why it does
     * not match itself: what follows the wrapper there is `(`, not a digit.
     */
    private static function needle(): string
    {
        return self::WRAPPER . ' -s ' . 'KILL ' . '%d';
    }

    /**
     * Every parametrised child budget in one source, EVALUATED.
     *
     * This is the half {@see childBudgets()} reports but cannot read. The regex
     * census sees a placeholder and stops; this walks the token stream from the
     * `sprintf()` whose format carries it, counts conversions to find WHICH
     * argument the placeholder consumes, and resolves that argument to a number
     * through an integer literal, a `self::` constant, or — for the one site
     * that passes its budget down as a parameter — every argument its callers
     * pass at that position.
     *
     * RULE 14 IS THE WHOLE DESIGN. A shape this cannot resolve is returned in
     * `unresolved` WITH THE REASON, never dropped: a resolver that quietly
     * skipped what it could not read would report a clean tree over a roster it
     * had narrowed itself, which is exactly the hole the regex census left.
     *
     * @return array{
     *     resolved: list<array{0: string, 1: int, 2: int, 3: string}>,
     *     unresolved: list<string>,
     * }
     */
    private static function resolvedParametrisedIn(string $label, string $source): array
    {
        $tokens = token_get_all($source);
        $consts = self::integerConstantsIn($tokens);
        $resolved = [];
        $unresolved = [];

        foreach ($tokens as $i => $token) {
            if (!\is_array($token)
                || !\in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
                || !\in_array(strtolower($token[1]), ['sprintf', '\\sprintf'], true)) {
                continue;
            }
            // `$x->sprintf(` and `C::sprintf(` are not this function, and
            // `function sprintf(` is a declaration rather than a call.
            $before = self::previousSignificant($tokens, $i);
            if ($before !== null && \is_array($tokens[$before])
                && \in_array($tokens[$before][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                continue;
            }
            $open = self::nextSignificant($tokens, $i);
            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }
            $arguments = self::argumentSpans($tokens, $open);
            if ($arguments === null || $arguments === []) {
                continue;
            }
            $format = self::concatenatedLiteral($tokens, $arguments[0]);
            if ($format === null || !str_contains($format, self::needle())) {
                continue;
            }

            // THE LINE IS THE CALL'S, NOT THE PLACEHOLDER'S, and the two
            // censuses therefore disagree by a line on a multi-line call. That
            // is why {@see testBothCensusesSeeTheSameParametrisedSites()}
            // compares FILES AND COUNTS rather than `file:line` strings.
            $row = $label . ':' . $token[2];
            $ordinal = self::conversionOrdinalOf($format);
            if ($ordinal === null) {
                $unresolved[] = $row . ' — ' . (preg_match('/%\d+\$/', $format) === 1
                    ? 'the format uses POSITIONAL conversions (`%n$`), which decouple conversion '
                        . 'order from argument position — this resolver counts conversions and '
                        . 'would answer a confident wrong number. Spell the budget without a '
                        . 'positional conversion, or teach the resolver to read one'
                    : 'the placeholder is not among the format\'s conversions');

                continue;
            }
            if (!isset($arguments[$ordinal])) {
                $unresolved[] = $row . ' — the format consumes argument ' . $ordinal
                    . ' and the call passes ' . (\count($arguments) - 1);

                continue;
            }
            [$seconds, $why] = self::resolveArgument($tokens, $arguments[$ordinal], $consts);
            if ($seconds === null) {
                $unresolved[] = $row . ' — ' . $why;

                continue;
            }
            foreach ($seconds as [$value, $via]) {
                $resolved[] = [$label, $token[2], $value, $via];
            }
        }

        return ['resolved' => $resolved, 'unresolved' => $unresolved];
    }

    /**
     * One argument's value, as every integer it can be WITH WHERE EACH CAME
     * FROM, or `null` and a reason.
     *
     * THE PROVENANCE IS NOT DECORATION. A resolved row's `file:line` is the
     * `sprintf()` CALL, and the number frequently lives somewhere else
     * entirely — a `self::` constant near the top of the file, or an argument
     * two callers away. A failure message that sends the reader to the call
     * line and shows a number that is not written there is a message that
     * costs a search; {@see tooLooseIn()} prints this instead.
     *
     * @param array{0: int, 1: int}  $span
     * @param array<string, ?int>    $consts
     * @param array<string, true>    $seen
     *
     * @return array{0: ?list<array{0: int, 1: string}>, 1: string}
     */
    private static function resolveArgument(array $tokens, array $span, array $consts, array $seen = []): array
    {
        $significant = self::significantIn($tokens, $span);

        if (\count($significant) === 1 && \is_array($tokens[$significant[0]])) {
            $only = $tokens[$significant[0]];
            if ($only[0] === T_LNUMBER) {
                return [[[(int) $only[1], 'an integer literal at the call site']], ''];
            }
            if ($only[0] === T_VARIABLE) {
                return self::resolveThroughParameter($tokens, $significant[0], $consts, $seen);
            }
        }

        if (\count($significant) === 3
            && \is_array($tokens[$significant[0]]) && $tokens[$significant[0]][0] === T_STRING
            && strtolower($tokens[$significant[0]][1]) === 'self'
            && $tokens[$significant[1]] !== ',' && \is_array($tokens[$significant[1]])
            && $tokens[$significant[1]][0] === T_DOUBLE_COLON
            && \is_array($tokens[$significant[2]]) && $tokens[$significant[2]][0] === T_STRING) {
            $name = $tokens[$significant[2]][1];
            if (!\array_key_exists($name, $consts)) {
                return [null, 'self::' . $name . ' is not a constant declared in this file'];
            }
            if ($consts[$name] === null) {
                return [null, 'self::' . $name . ' is declared but its value is not an integer literal'];
            }

            return [[[$consts[$name], 'self::' . $name]], ''];
        }

        return [null, 'the argument is `' . self::textOf($tokens, $significant)
            . '`, which this resolver cannot reduce to a number — teach it that shape, or '
            . 'spell the budget as an integer literal at the call site'];
    }

    /**
     * A budget handed down as a function parameter, resolved through every call.
     *
     * THE ONE SITE THAT NEEDS THIS PASSES ITS BOUND TO A PRIVATE HELPER, and the
     * two callers hand it two DIFFERENT constants — so a resolver answering a
     * single number here would have to pick one and would be wrong about the
     * other. Every call's value is returned and every one of them is checked
     * against the ceiling.
     *
     * @param array<string, ?int> $consts
     * @param array<string, true> $seen
     *
     * @return array{0: ?list<array{0: int, 1: string}>, 1: string}
     */
    private static function resolveThroughParameter(
        array $tokens,
        int $variable,
        array $consts,
        array $seen = [],
    ): array {
        $name = $tokens[$variable][1];

        $function = null;
        for ($j = $variable; $j >= 0; $j--) {
            if (\is_array($tokens[$j]) && $tokens[$j][0] === T_FUNCTION) {
                $function = $j;

                break;
            }
        }
        if ($function === null) {
            return [null, $name . ' is not inside a function, so it has no parameter to resolve'];
        }

        $named = self::nextSignificant($tokens, $function);
        if ($named === null || !\is_array($tokens[$named]) || $tokens[$named][0] !== T_STRING) {
            return [null, $name . ' is a parameter of a closure, which has no call sites to read'];
        }
        $callee = $tokens[$named][1];

        $open = self::nextSignificant($tokens, $named);
        if ($open === null || $tokens[$open] !== '(') {
            return [null, $callee . '() has no parameter list this resolver can read'];
        }
        $parameters = self::argumentSpans($tokens, $open);
        if ($parameters === null) {
            return [null, $callee . '() has an unterminated parameter list'];
        }

        // A HELPER THAT PASSES ITS OWN PARAMETER BACK TO ITSELF WOULD RECURSE
        // UNTIL THE STACK DIES, and a PHP fatal is neither a kill nor a
        // survival — it wedges the guard instead of reddening it, which is the
        // one failure mode a mutation run cannot classify. Not present in this
        // tree; refused rather than left to be discovered.
        if (isset($seen[$callee])) {
            return [null, $callee . '() is reached from inside its own argument list, so '
                . 'resolving ' . $name . ' would recurse without end'];
        }
        $seen[$callee] = true;

        $position = null;
        foreach ($parameters as $index => $span) {
            foreach (self::significantIn($tokens, $span) as $j) {
                if (\is_array($tokens[$j]) && $tokens[$j][0] === T_VARIABLE && $tokens[$j][1] === $name) {
                    $position = $index;

                    break 2;
                }
            }
        }
        if ($position === null) {
            return [null, $name . ' is a local of ' . $callee . '(), not one of its parameters'];
        }

        $values = [];
        foreach ($tokens as $i => $token) {
            if ($i === $named || !\is_array($token) || $token[0] !== T_STRING || $token[1] !== $callee) {
                continue;
            }
            $callOpen = self::nextSignificant($tokens, $i);
            if ($callOpen === null || $tokens[$callOpen] !== '(') {
                continue;
            }
            $arguments = self::argumentSpans($tokens, $callOpen);
            if ($arguments === null || !isset($arguments[$position])) {
                return [null, 'a call to ' . $callee . '() passes nothing at position ' . $position];
            }
            [$resolved, $why] = self::resolveArgument($tokens, $arguments[$position], $consts, $seen);
            if ($resolved === null) {
                return [null, 'through ' . $callee . '() parameter ' . $name . ': ' . $why];
            }
            foreach ($resolved as [$value, $via]) {
                $values[] = [$value, $name . ', passed to ' . $callee . '() as ' . $via];
            }
        }

        if ($values === []) {
            return [null, $callee . '() is never called in this file, so ' . $name . ' has no value'];
        }

        return [$values, ''];
    }

    /**
     * Every `const NAME = <integer literal>;` in one token stream.
     *
     * A constant whose value is anything else is recorded as `null` rather than
     * omitted, so {@see resolveArgument()} can tell "no such constant" from
     * "a constant this cannot evaluate" and say which (rule 14).
     *
     * @return array<string, ?int>
     */
    private static function integerConstantsIn(array $tokens): array
    {
        $constants = [];
        foreach ($tokens as $i => $token) {
            if (!\is_array($token) || $token[0] !== T_CONST) {
                continue;
            }
            $named = self::nextSignificant($tokens, $i);
            if ($named === null || !\is_array($tokens[$named]) || $tokens[$named][0] !== T_STRING) {
                continue;
            }
            $equals = self::nextSignificant($tokens, $named);
            if ($equals === null || $tokens[$equals] !== '=') {
                continue;
            }
            $value = self::nextSignificant($tokens, $equals);
            $after = $value === null ? null : self::nextSignificant($tokens, $value);
            $constants[$tokens[$named][1]] = ($value !== null && \is_array($tokens[$value])
                && $tokens[$value][0] === T_LNUMBER && $after !== null && $tokens[$after] === ';')
                    ? (int) $tokens[$value][1]
                    : null;
        }

        return $constants;
    }

    /**
     * Which conversion the needle's placeholder is, 1-indexed, or `null`.
     *
     * `%%` IS AN ESCAPE AND NOT A CONVERSION, so a format carrying one before
     * the placeholder shifts every ordinal after it by one if that is missed —
     * which resolves the WRONG argument and answers a confident wrong number.
     *
     * AND A POSITIONAL CONVERSION BREAKS THE WHOLE PREMISE, so this refuses to
     * answer at all rather than answering wrongly. `%n$` decouples the ordinal
     * from the argument index: in `sprintf('%2$s <wrapper> -s KILL %d', 20, 300)`
     * the placeholder is the SECOND conversion but consumes the FIRST argument,
     * so counting conversions returns 300 where the truth is 20 — and inverted,
     * a real budget of 300 resolves to 20 and passes. Positional conversions do
     * already occur in this tree, just not in a wrapper format yet. Reporting
     * `null` here routes the site into `unresolved` with that reason, which is
     * rule 14: red on what it cannot parse, never a confident wrong number.
     */
    private static function conversionOrdinalOf(string $format): ?int
    {
        if (preg_match('/%\d+\$/', $format) === 1) {
            return null;
        }

        $placeholder = strpos($format, self::needle());
        if ($placeholder === false) {
            return null;
        }
        // The offset of the `%` that opens the needle's own conversion.
        $placeholder += \strlen(self::needle()) - 2;

        $ordinal = 0;
        for ($i = 0, $length = \strlen($format); $i < $length; $i++) {
            if ($format[$i] !== '%') {
                continue;
            }
            if (($format[$i + 1] ?? '') === '%') {
                $i++;

                continue;
            }
            $ordinal++;
            if ($i === $placeholder) {
                return $ordinal;
            }
            $i++;
        }

        return null;
    }

    /**
     * A run of single- or double-quoted literals joined by `.`, decoded, or
     * `null` for anything else.
     *
     * @param array{0: int, 1: int} $span
     */
    private static function concatenatedLiteral(array $tokens, array $span): ?string
    {
        $significant = self::significantIn($tokens, $span);
        if ($significant === []) {
            return null;
        }

        $out = '';
        foreach ($significant as $position => $j) {
            if ($position % 2 === 1) {
                if ($tokens[$j] !== '.') {
                    return null;
                }

                continue;
            }
            if (!\is_array($tokens[$j]) || $tokens[$j][0] !== T_CONSTANT_ENCAPSED_STRING) {
                return null;
            }
            $raw = $tokens[$j][1];
            $body = substr($raw, 1, -1);
            $out .= $raw[0] === "'"
                ? str_replace(['\\\\', "\\'"], ['\\', "'"], $body)
                : stripcslashes($body);
        }

        return $out;
    }

    /**
     * The `[from, to)` token spans of a bracketed argument list, or `null` when
     * the brackets never close.
     *
     * @return list<array{0: int, 1: int}>|null
     */
    private static function argumentSpans(array $tokens, int $open): ?array
    {
        $depth = 0;
        $spans = [];
        $start = $open + 1;
        for ($i = $open, $count = \count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if (\in_array($token, ['(', '[', '{'], true)) {
                $depth++;

                continue;
            }
            if (\in_array($token, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    $spans[] = [$start, $i];

                    return $spans;
                }

                continue;
            }
            // Only a comma at the list's OWN depth separates arguments; one
            // inside a nested call or an array literal belongs to that.
            if ($depth === 1 && $token === ',') {
                $spans[] = [$start, $i];
                $start = $i + 1;
            }
        }

        return null;
    }

    /**
     * @param array{0: int, 1: int} $span
     *
     * @return list<int>
     */
    private static function significantIn(array $tokens, array $span): array
    {
        $out = [];
        for ($i = $span[0]; $i < $span[1]; $i++) {
            if (\is_array($tokens[$i])
                && \in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out[] = $i;
        }

        return $out;
    }

    private static function nextSignificant(array $tokens, int $i): ?int
    {
        for ($j = $i + 1, $count = \count($tokens); $j < $count; $j++) {
            if (\is_array($tokens[$j])
                && \in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $j;
        }

        return null;
    }

    private static function previousSignificant(array $tokens, int $i): ?int
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (\is_array($tokens[$j])
                && \in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $j;
        }

        return null;
    }

    /**
     * @param list<int> $significant
     */
    private static function textOf(array $tokens, array $significant): string
    {
        $out = '';
        foreach ($significant as $j) {
            $out .= \is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
        }

        return $out;
    }

    /**
     * Every parametrised budget in `tests/`, resolved, and every one that is not.
     *
     * @return array{
     *     resolved: list<array{0: string, 1: int, 2: int, 3: string}>,
     *     unresolved: list<string>,
     * }
     */
    private function resolvedParametrisedBudgets(): array
    {
        $resolved = [];
        $unresolved = [];
        foreach (self::everyTestFile() as $relative => $path) {
            $found = self::resolvedParametrisedIn('tests/' . $relative, self::readOrFail($path));
            foreach ($found['resolved'] as $row) {
                $resolved[] = $row;
            }
            foreach ($found['unresolved'] as $row) {
                $unresolved[] = $row;
            }
        }
        sort($resolved);
        sort($unresolved);

        return ['resolved' => $resolved, 'unresolved' => $unresolved];
    }

    /**
     * The number every child budget in the tree has to come in at or under.
     *
     * EXTRACTED BECAUSE THE EXPRESSION AT THE CALL SITE WAS THE ONE THING
     * NOTHING WATCHED. It used to be spelled inline in the guard below, and
     * widening it there by a hundred seconds — the change that switches the
     * whole check off — passed the ENTIRE SUITE, byte-identical: the fixture
     * beside it drove {@see tooLooseIn()} with literal ceilings, so the derived
     * number was never the subject of any assertion. That is rule 2 one level
     * out from where it was first found: the mutation was relevant, the
     * assertion's window was in the wrong place.
     *
     * {@see testTheComparisonRejectsBudgetsWhoseAnswerIsKnown()} now drives
     * rows derived from THIS method and states the invariant it has to satisfy
     * as a relation, so a widened ceiling has nowhere left to hide.
     */
    private function ceiling(): int
    {
        return $this->defaultTimeLimit() - self::REQUIRED_HEADROOM_SECONDS;
    }

    /**
     * The rows in `$literal` whose budget is over `$ceiling`.
     *
     * EXTRACTED BECAUSE A MUTATION OF IT SURVIVED. Written inline in the guard
     * below, widening the comparison by a hundred seconds — which switches the
     * check off — passed the whole file, because no real row sat near the
     * boundary and none could tell a working comparison from a disabled one.
     *
     * WHAT THIS SAID about why: "every budget in the tree is 20 against a
     * ceiling of 50". WHAT IS TRUE NOW: that was not true even at the commit
     * that wrote it. The resolver below reads 6 and 30 as well as 20, and the
     * widened census reads 10 — the sentence described the shape the ceiling
     * comparison was ORIGINALLY written against, not the tree it shipped into.
     * WHY THE REASONING STILL EARNS ITS PLACE: the argument never depended on
     * the population being uniform, only on its being far from the boundary,
     * and it still is — the largest budget this census can see is 30 against a
     * ceiling of 50. A number in this paragraph is exactly the thing that rots;
     * {@see testTheComparisonRejectsBudgetsWhoseAnswerIsKnown()} derives the
     * property instead. That is rule 25 exactly:
     * a fixture whose expected value is what a DEAD instrument returns proves
     * nothing. {@see testTheComparisonRejectsBudgetsWhoseAnswerIsKnown()} drives
     * this with rows either side of the boundary.
     *
     * ELEMENT 3 IS WHERE THE NUMBER ACTUALLY LIVES, and it is the second reason
     * this method exists rather than the first. Rows folded in from the token
     * census carry a `file:line` that is the `sprintf()` CALL, while the value
     * is in a constant or in a caller's argument list — so a reader sent to a
     * parametrised site finds a placeholder there and no number at all. For a
     * literal row it is instead the command AS MATCHED. Both are printed;
     * neither is reconstructed. The first draft rendered every row with
     * `-s KILL` spliced in, so the two unflagged sites the widened alphabet
     * had just brought into scope were reported with a command that is not
     * written anywhere in the file the reader is being sent to.
     *
     * @param list<array{0: string, 1: int, 2: int, 3?: string}> $rows
     *
     * @return list<string>
     */
    private function tooLooseIn(array $rows, int $ceiling): array
    {
        $tooLoose = [];
        foreach ($rows as $row) {
            [$label, $line, $seconds] = $row;
            if ($seconds <= $ceiling) {
                continue;
            }
            // ASSEMBLED for the same reason the fixture's expectations are: a
            // literal here is a match for this census's own scan of this file.
            $report = $label . ':' . $line . ' — ' . $seconds . 's';
            $via = $row[3] ?? '';
            if ($via !== '') {
                $report .= ' — ' . $via;
            }
            $tooLoose[] = $report;
        }

        return $tooLoose;
    }

    /**
     * The comparison answers rows whose verdict is already known.
     *
     * FOUR ROWS STRADDLING THE BOUNDARY, because the interesting failures are
     * off-by-one in either direction: at the ceiling is fine, one over is not,
     * and a row far under must never be reported.
     */
    public function testTheComparisonRejectsBudgetsWhoseAnswerIsKnown(): void
    {
        // THE EXPECTED STRINGS ARE ASSEMBLED, NEVER SPELLED (rule 26). This file
        // is inside its own roster: a literal command-and-number written here
        // is scraped by the census as a real child budget, and the first draft
        // of this fixture reported ITSELF as two offenders.
        $shape = self::WRAPPER . ' -s ' . 'KILL';

        // AND THE DETAIL COLUMN IS PINNED IN BOTH ITS FLAVOURS. A literal row
        // carries the command AS MATCHED — the unflagged site below is the
        // shape a hard-coded `-s KILL` in the renderer got WRONG — and a
        // parametrised row carries the provenance of a value that is not
        // written at its own line.
        $rows = [
            ['fixture/Far.php', 1, 5, $shape],
            ['fixture/At.php', 2, 50, $shape],
            ['fixture/OneOver.php', 3, 51, $shape],
            ['fixture/AtTheLimit.php', 4, 60, self::WRAPPER],
            ['fixture/ViaConstant.php', 7, 52, 'self::TREATMENT_BOUND'],
        ];

        $this->assertSame(
            [
                'fixture/OneOver.php:3 — 51s — ' . $shape,
                'fixture/AtTheLimit.php:4 — 60s — ' . self::WRAPPER,
                'fixture/ViaConstant.php:7 — 52s — self::TREATMENT_BOUND',
            ],
            $this->tooLooseIn($rows, 50),
            'the comparison does not separate a budget over the ceiling from one at or under '
            . 'it, so the empty verdict over the real tree is satisfied by a disabled check',
        );

        $this->assertSame(
            [],
            $this->tooLooseIn($rows, 60),
            'a ceiling every row satisfies still produced findings, so the comparison reports '
            . 'rows for reasons of its own',
        );

        // AND A ROW WITH NO DETAIL AT ALL STILL RENDERS, because the literal
        // half of the census predates the column and a missing element 3 must
        // not become a notice or an empty trailing separator.
        $this->assertSame(
            ['fixture/Bare.php:8 — 61s'],
            $this->tooLooseIn([['fixture/Bare.php', 8, 61]], 60),
            'a three-element row no longer renders, so the detail column is required rather '
            . 'than optional',
        );

        // AND THE SAME TWO ROWS AGAIN, DERIVED FROM THE CEILING THE GUARD
        // ACTUALLY USES. The four rows above are literals, so they cannot tell
        // a correct `ceiling()` from one widened by a hundred seconds — the
        // mutation that survived the whole suite. These two straddle whatever
        // `ceiling()` answers, so the boundary moves with it and the pair below
        // is what stops it moving anywhere it likes.
        $ceiling = $this->ceiling();
        $this->assertSame(
            ['fixture/OverTheDerivedCeiling.php:6 — ' . ($ceiling + 1) . 's — ' . $shape],
            $this->tooLooseIn(
                [
                    ['fixture/AtTheDerivedCeiling.php', 5, $ceiling, $shape],
                    ['fixture/OverTheDerivedCeiling.php', 6, $ceiling + 1, $shape],
                ],
                $ceiling,
            ),
            'the comparison does not separate a budget one second over the DERIVED ceiling '
            . 'from one exactly at it',
        );

        // THE INVARIANT THE DERIVED NUMBER HAS TO SATISFY, STATED AS A RELATION
        // AND NOT AS A SECOND SPELLING OF THE SAME ARITHMETIC. A ceiling widened
        // by any amount leaves the parent less headroom than the constant
        // declares, and that is the property, not the subtraction.
        $limit = $this->defaultTimeLimit();
        $this->assertGreaterThanOrEqual(
            self::REQUIRED_HEADROOM_SECONDS,
            $limit - $ceiling,
            'the ceiling leaves the parent alarm less room than REQUIRED_HEADROOM_SECONDS '
            . 'declares, so a child budget this guard accepts can still win the race the '
            . 'headroom exists to lose',
        );
        $this->assertLessThan(
            $limit,
            $ceiling,
            'the ceiling is at or above the per-test limit it is derived from, so every child '
            . 'budget passes and this guard asserts nothing',
        );
    }

    /**
     * This file is not the evidence for its own liveness arms.
     *
     * RULE 40, AND IT WAS BOUGHT ONCE ALREADY. The census walks `tests/`, which
     * includes this file, and the paragraph explaining the parametrised form
     * used to SPELL that form — so `assertNotSame([], $parametrised)` below was
     * satisfied by the sentence describing the arm, and a mutation restricting
     * the parametrised scan to this one file survived the entire suite. An
     * exemption keyed on prose is bought with a sentence, and the fix's own
     * comment is what buys it.
     *
     * The resolution is structural rather than textual: every occurrence of
     * either form in this file is assembled at run time, and this asserts the
     * census sees nothing here at all. Spell either form in a comment and this
     * reds, in the file where that matters most.
     */
    public function testThisFileIsNotItsOwnEvidence(): void
    {
        $budgets = $this->childBudgets();
        $self = 'tests/Support/' . basename(__FILE__);

        $mine = array_merge(
            array_values(array_filter(
                array_map(static fn (array $row): string => $row[0] . ':' . $row[1], $budgets['literal']),
                static fn (string $row): bool => str_starts_with($row, $self . ':'),
            )),
            array_values(array_filter(
                $budgets['parametrised'],
                static fn (string $row): bool => str_starts_with($row, $self . ':'),
            )),
            // AND THE THIRD BUCKET, which is the one this file would land in if
            // its own assembly ever slipped: a literal here that ends at the
            // wrapper reports as `unresolved`, not as a budget, and would
            // otherwise red the guard from inside the file that defines it.
            array_values(array_filter(
                $budgets['unresolved'],
                static fn (string $row): bool => str_starts_with($row, $self . ':'),
            )),
        );

        $this->assertSame(
            [],
            $mine,
            'this file is inside its own census and is now contributing rows to it, so the '
            . 'liveness arms below are satisfied by this file\'s own text rather than by the '
            . 'tree. Assemble the occurrence from pieces instead of spelling it',
        );

        // AND THE TOKEN CENSUS TOO, which walks the same directory and would be
        // bought by the same sentence. It reads `sprintf()` CALLS rather than
        // raw text, so a needle written in a comment here cannot reach it — but
        // one written as a real call in a fixture-shaped helper could, and this
        // is the arm that would say so.
        $parametrised = $this->resolvedParametrisedBudgets();
        $this->assertSame(
            [],
            array_values(array_filter(
                array_map(static fn (array $row): string => $row[0] . ':' . $row[1], $parametrised['resolved']),
                static fn (string $row): bool => str_starts_with($row, $self . ':'),
            )),
            'this file now contains a real parametrised launch the token census resolves, so '
            . 'the arms below are evidence about this file rather than about the tree',
        );

        // AND THE KNOWN-POSITIVE IN THE SAME TEST (rule 15): an empty list here
        // is also what a dead scanner returns, so the scanner has to be shown
        // finding something somewhere.
        $this->assertNotSame([], $budgets['literal'], 'the census found no literal budget anywhere');
        $this->assertNotSame([], $budgets['parametrised'], 'the census found no parametrised budget anywhere');
        $this->assertNotSame([], $parametrised['resolved'], 'the token census resolved no parametrised budget anywhere');
    }

    /**
     * Every parametrised child budget resolves to a number, and both censuses
     * see the same sites.
     *
     * THIS IS THE ARM E553 EXISTS FOR. The regex census can see that a budget is
     * passed through a placeholder and cannot see what it is; until now the
     * answer was "20, by inspection" written into a doc-block, with nothing
     * re-checking it. MEASURED when this guard was written, that sentence was
     * ALREADY WRONG: the five sites resolve to 20, 30, 20, 20 and — through a
     * parameter with two callers — 6 and 30. Nothing was over the ceiling, so
     * the tree was fine and the CLAIM was not, which is the whole argument for
     * deriving it rather than remembering it.
     *
     * TWO INDEPENDENT INSTRUMENTS OVER ONE POPULATION, and they must agree
     * (rule 14). The text census scrapes string literals; the token census
     * walks `sprintf()` calls. Either one going blind is invisible on its own —
     * an empty verdict looks identical to a clean tree — and this is what says
     * so when only ONE of them goes blind.
     *
     * WHAT THIS SAID: "neither can go blind without the other disagreeing
     * here." WHAT IS TRUE NOW: that holds for a census being blinded and not
     * for a site LEAVING the population. Measured by mutation, respelling a
     * real budget as an interpolation removed it from both censuses in the same
     * edit — no rows either side, so the counts still matched and this arm
     * stayed green. WHY IT STILL EARNS ITS PLACE: one census going blind is the
     * likelier accident and this is the only thing that catches it; the shape
     * it cannot see is covered by {@see wallClockWrappersIn()}'s `unresolved`
     * bucket instead, which reds on a wrapper whose budget it cannot read.
     *
     * THEY ARE COMPARED ON FILES AND COUNTS, NOT ON `file:line`, and that is
     * deliberate rather than a weakening: the regex reports the line of the
     * PLACEHOLDER and the token census reports the line of the CALL, which
     * differ by one on every multi-line launch. Comparing the strings would red
     * on formatting.
     */
    public function testBothCensusesSeeTheSameParametrisedSites(): void
    {
        $parametrised = $this->resolvedParametrisedBudgets();

        $this->assertSame(
            [],
            $parametrised['unresolved'],
            'a parametrised child budget could not be reduced to a number, and it is REPORTED '
            . 'rather than dropped because a resolver that silently skipped it would be '
            . 'certifying a budget nobody has read. Teach the resolver this shape, or spell '
            . 'the budget as an integer literal at the call site',
        );

        $countBy = static function (array $rows): array {
            $out = [];
            foreach ($rows as $row) {
                $file = \is_array($row) ? $row[0] : substr($row, 0, (int) strrpos($row, ':'));
                $out[$file] = ($out[$file] ?? 0) + 1;
            }
            ksort($out);

            return $out;
        };

        // The token census emits one row per RESOLVED VALUE, and the site whose
        // budget arrives as a parameter has two callers — so it contributes two
        // rows for one occurrence. Counting distinct call lines is what makes
        // the two instruments comparable.
        $seen = [];
        foreach ($parametrised['resolved'] as $row) {
            $seen[$row[0] . ':' . $row[1]] = true;
        }

        $this->assertSame(
            $countBy($this->childBudgets()['parametrised']),
            $countBy(array_keys($seen)),
            'the regex census and the token census disagree about which files carry a '
            . 'parametrised child budget and how many. One of the two has gone blind, and an '
            . 'empty verdict from a blind census is indistinguishable from a clean tree',
        );
    }

    /**
     * The census classifies shapes whose answer is already known.
     *
     * RULE 15 AND RULE 25, AND THE ARM THAT PAID FOR THEM. The guard over the
     * real tree asserts `[]` for `unresolved`, and `[]` is also what a census
     * that matches NOTHING returns — so an empty verdict there is evidence
     * only if something in the same suite shows the scanner still classifying.
     * Every fixture here is a positive: each one names the classification it
     * must produce, so a blinded scanner reds on the fixture rather than
     * passing quietly over the tree.
     *
     * THE ALPHABET IS THE COVERAGE (rule 11), so the fixtures are chosen to be
     * the shapes the OLD alphabet could not express rather than the ones it
     * could. Two of them are the live sites it was measured to have missed —
     * the plain form with no signal flag, and a budget arriving by
     * interpolation — and the acceptance test for the widening is that these go
     * red without it, not that the tree stays green with it.
     *
     * EVERY FIXTURE ASSEMBLES THE WRAPPER (rule 26). This file is inside the
     * census's own roster; spelling the command here with a number after it
     * would make the file its own evidence, which is the defect
     * {@see testThisFileIsNotItsOwnEvidence()} exists to catch.
     */
    public function testTheCensusClassifiesShapesWhoseAnswerIsKnown(): void
    {
        $of = static function (string $body): array {
            $rows = [];
            foreach (self::wallClockWrappersIn("<?php\n" . $body . "\n") as [$line, $kind, $seconds, $why]) {
                // THE LINE IS IN EVERY ROW, and it was not in the first draft.
                // A row's line is the whole value of the report — it is what
                // the reader is sent to — and a fixture rendering only the
                // classification pins nothing about it. `$body` starts at
                // line 2, so every expectation below is 1-based from there.
                $rows[] = $line . ':' . $kind . ':' . $seconds . ':' . $why;
            }

            return $rows;
        };
        $w = self::WRAPPER;

        // THE PLAIN FORM, WITH NO SIGNAL FLAG. Two live sites spell it this way
        // and the old alphabet — which required `-s KILL` — reported neither.
        $this->assertSame(
            ['2:literal:10:' . $w],
            $of("shell_exec('" . $w . " 10 ' . PHP_BINARY);"),
            'the plain wrapper form is not seen, which is the exact hole two live sites sat in — '
            . 'or it is seen and reported with flags it does not have',
        );

        // THE FLAGGED FORM, in every spelling timeout(1) takes ahead of the
        // duration. If the flag run is not consumed, the FLAG is read as the
        // budget and each of these becomes an unresolved row.
        $this->assertSame(
            [
                '2:literal:20:' . $w . ' -s KILL',
                '3:literal:21:' . $w . ' -k 5 -s KILL',
                '4:literal:22:' . $w . ' --signal=KILL',
                '5:literal:23:' . $w . ' --foreground',
            ],
            $of(
                "exec('" . $w . " -s KILL 20 x');\n"
                . "exec('" . $w . " -k 5 -s KILL 21 x');\n"
                . "exec('" . $w . " --signal=KILL 22 x');\n"
                . "exec('" . $w . " --foreground 23 x');"
            ),
            'a flag run ahead of the duration is not consumed, so a flag is being read as the budget',
        );

        // A CONVERSION BOUND TIGHT AGAINST THE WRAPPER. One real site builds its
        // prefix that way, and `\b` does not fire between `s` and `t` — a left
        // boundary spelled `\b<wrapper>` misses it silently.
        $this->assertSame(
            ['2:literal:24:' . $w . ' -s KILL'],
            $of("exec(sprintf('%s" . $w . " -s KILL 24 %s', \$p, \$c));"),
            'a wrapper preceded by a printf conversion is invisible, so a `%s`-prefixed launch '
            . 'is certified as carrying no budget',
        );

        // THE PLACEHOLDER, which is the token census's half.
        $this->assertSame(
            ['2:parametrised:0:' . $w . ' -s KILL'],
            $of("exec(sprintf('" . $w . " -s KILL %d x', \$n));"),
            'the parametrised form is no longer routed to the resolver',
        );

        // AN INTERPOLATED BUDGET, which is the shape that used to vanish from
        // BOTH censuses at once: no digits for this one and no `%d` for the
        // token census, so it produced no row and no disagreement either.
        $interpolated = $of("exec(\"" . $w . " -s KILL {\$bound} x\");");
        $this->assertCount(1, $interpolated, 'an interpolated budget produced no row at all');
        $this->assertStringStartsWith('2:unresolved:0:', $interpolated[0]);
        $this->assertStringContainsString(
            'END of a string literal',
            $interpolated[0],
            'the row for an interpolated budget does not say why it could not be read',
        );

        // AND EVERY OTHER TOKEN THE ALPHABET RECOGNISES AS A BUDGET BUT CANNOT
        // EVALUATE lands in the same bucket WITH the token (rule 14).
        foreach (['$BUDGET' => 'a shell variable', '10s' => 'a suffixed duration', '%s' => 'a string conversion'] as $token => $what) {
            $row = $of("exec('" . $w . " -s KILL " . $token . " x');");
            $this->assertCount(1, $row, $what . ' produced no row at all');
            $this->assertStringStartsWith('2:unresolved:0:', $row[0], $what . ' was classified as a budget');
            $this->assertStringContainsString('`' . $token . '`', $row[0], $what . ' was reported without naming the token');
        }

        // A HEREDOC AND A NOWDOC, which tokenise as encapsed strings and are
        // therefore in scope — MEASURED rather than assumed, because "reads
        // string literals" is a claim about the tokeniser and not about the
        // language. The line numbers matter as much as the classification: a
        // heredoc's body starts a line BELOW the token PHP reports, so a scan
        // that used the token's own line would send the reader one line short.
        $this->assertSame(
            ['3:literal:44:' . $w . ' -s KILL', '6:literal:45:' . $w],
            $of("\$a = <<<SH\n" . $w . " -s KILL 44 x\nSH;\n\$b = <<<'SH'\n" . $w . " 45 y\nSH;"),
            'a heredoc- or nowdoc-built launch is invisible, or is reported at the line the '
            . 'tokeniser names rather than the line the command is written on',
        );

        // THE NEGATIVE HALF, and it is the reason this scan reads the TOKEN
        // STREAM rather than raw text (rule 40). Prose that happens to use the
        // word is not a launch, and neither is a doc-block describing one — the
        // old raw scan counted two such sentences as real child budgets.
        $this->assertSame(
            [],
            $of(
                "/** the " . $w . " 90 in this sentence is prose */\n"
                . "// and so is a " . $w . " -s KILL 91 in a line comment\n"
                . "\$x = 'the " . $w . " after the fact never fired';"
            ),
            'prose is being counted as a child budget, so the census reports offenders that do '
            . 'not exist and its empty verdict over the tree means less than it appears to',
        );

        // AND A BARE MENTION WITH NOTHING AFTER IT IS NOT A LAUNCH EITHER,
        // which is exactly the discipline this file applies to itself: the
        // wrapper at a literal's end with no trailing space is how every
        // occurrence here is spelled.
        $this->assertSame([], $of("\$s = '" . $w . "' . ' -s ' . 'KILL ' . 92;"));
    }

    /**
     * The resolver answers sources whose answer is already known.
     *
     * RULE 15 AND RULE 25 TOGETHER. `assertSame([], $unresolved)` over the real
     * tree passes just as well when the resolver matches nothing at all, and
     * `assertSame([], $resolved)` is what a DELETED resolver returns — so every
     * arm here has a positive component, and the negative ones are paired with
     * a reason string that a dead resolver could not produce.
     *
     * THE ORDINAL ARM IS THE ONE THAT MATTERS. A resolver that counted the
     * placeholder as conversion 1 regardless of what precedes it resolves the
     * WRONG argument and answers a confident wrong number — greener and more
     * dangerous than answering nothing. Two fixtures below put a `%s` and a
     * `%%` ahead of the placeholder for exactly that reason.
     *
     * EVERY FIXTURE ASSEMBLES THE NEEDLE (rule 26). Spelling it here would put
     * a real occurrence in a file both censuses walk.
     */
    public function testTheParametrisedResolverAnswersSourcesWhoseAnswerIsKnown(): void
    {
        $of = static function (string $body): array {
            return self::resolvedParametrisedIn(
                'fixture.php',
                str_replace('@BUDGET@', self::needle(), "<?php\nclass F {\n" . $body . "\n}\n"),
            );
        };

        // AN INTEGER LITERAL, and the placeholder is the only conversion.
        $plain = $of("function a() { sprintf('@BUDGET@', 21); }");
        $this->assertSame(
            [['fixture.php', 3, 21, 'an integer literal at the call site']],
            $plain['resolved'],
            'a literal budget did not resolve',
        );
        $this->assertSame([], $plain['unresolved']);

        // A `self::` CONSTANT, which is how four of the five real sites spell it.
        $const = $of("const B = 22;\nfunction a() { sprintf('@BUDGET@', self::B); }");
        $this->assertSame(
            [['fixture.php', 4, 22, 'self::B']],
            $const['resolved'],
            'a self:: constant budget did not resolve, or resolved without saying where the '
            . 'number actually lives',
        );

        // THE ORDINAL, with a conversion AHEAD of the placeholder. A resolver
        // that always took argument 1 answers 'first' here and this reds.
        $shifted = $of("function a() { sprintf('%s @BUDGET@ %s', 'first', 23, 'third'); }");
        $this->assertSame(
            [['fixture.php', 3, 23, 'an integer literal at the call site']],
            $shifted['resolved'],
            'the placeholder was counted as the wrong conversion',
        );

        // AND A POSITIONAL CONVERSION IS REFUSED RATHER THAN MISCOUNTED. Here
        // the placeholder is the SECOND conversion and consumes the FIRST
        // argument, so a resolver that counts conversions answers 300 where the
        // truth is 20 — greener than answering nothing, and wrong. MEASURED on
        // PHP 8.3.6 rather than reasoned about: this exact format renders as
        // `300 <wrapper> -s KILL 20`, because a positional conversion does not
        // advance the sequential counter. The budget really is 20, and 300 is
        // precisely the number the counting resolver would have certified.
        $positional = $of("function a() { sprintf('%2\$s @BUDGET@', 20, 300); }");
        $this->assertSame([], $positional['resolved'], 'a positional format was resolved by conversion order anyway');
        $this->assertCount(1, $positional['unresolved'], 'a positional format was dropped rather than reported');
        $this->assertStringContainsString(
            'POSITIONAL',
            $positional['unresolved'][0],
            'the report of a positional format does not say what it choked on, so the reader '
            . 'cannot tell it from any other unreadable budget',
        );

        // AND `%%` IS AN ESCAPE, NOT A CONVERSION. Counting it shifts every
        // ordinal after it by one, which resolves 'first' instead of 24.
        $escaped = $of("function a() { sprintf('100%% %s @BUDGET@', 'first', 24); }");
        $this->assertSame(
            [['fixture.php', 3, 24, 'an integer literal at the call site']],
            $escaped['resolved'],
            '%% was counted as a conversion',
        );

        // A CONCATENATED FORMAT, which is how the longest real site spells it.
        $joined = $of("function a() { sprintf('@BUDGET@ ' . '%s', 25, 'tail'); }");
        $this->assertSame(
            [['fixture.php', 3, 25, 'an integer literal at the call site']],
            $joined['resolved'],
            'a concatenated format string was not decoded',
        );

        // THROUGH A PARAMETER, WITH TWO CALLERS AND TWO ANSWERS — the real
        // shape, and the reason the resolver returns a list rather than a
        // number. A resolver taking only the first call answers [26] and reds.
        $viaParameter = $of(
            "const LOW = 26;\nconst HIGH = 27;\n"
            . "function a() { \$this->b(self::LOW); \$this->b(self::HIGH); }\n"
            . "function b(int \$bound) { sprintf('@BUDGET@', \$bound); }"
        );
        $this->assertSame(
            [
                ['fixture.php', 6, 26, '$bound, passed to b() as self::LOW'],
                ['fixture.php', 6, 27, '$bound, passed to b() as self::HIGH'],
            ],
            $viaParameter['resolved'],
            'a budget handed down as a parameter did not resolve to every value its callers '
            . 'pass, or resolved without naming the caller the value came from — which is the '
            . 'half that makes a failure at line 6 readable, since line 6 has no number on it',
        );

        // AND A HELPER THAT FEEDS ITSELF IS REFUSED, not recursed into. A PHP
        // stack fatal is neither a kill nor a survival: it wedges the guard
        // instead of reddening it, which is the one outcome a mutation run
        // cannot classify.
        $cyclic = $of(
            "function a() { \$this->b(3); }\n"
            . "function b(int \$n) { \$this->b(\$n); sprintf('@BUDGET@', \$n); }"
        );
        $this->assertSame([], $cyclic['resolved']);
        $this->assertStringContainsString(
            'recurse without end',
            $cyclic['unresolved'][0] ?? '',
            'a self-feeding helper was followed rather than refused',
        );

        // AND THE RULE-14 HALF: what it cannot read is REPORTED WITH A REASON,
        // never dropped. An empty `resolved` here is also what a dead resolver
        // returns, so the reason string is the half that cannot be faked.
        $opaque = $of("function a(int \$n) { sprintf('@BUDGET@', getenv('X')); }");
        $this->assertSame([], $opaque['resolved']);
        $this->assertCount(1, $opaque['unresolved'], 'an unreadable budget was dropped rather than reported');
        $this->assertStringContainsString(
            "getenv('X')",
            $opaque['unresolved'][0],
            'the report of an unresolvable budget does not name the expression it could not read',
        );

        // A CONSTANT THAT IS NOT AN INTEGER LITERAL is a different failure from
        // a constant that does not exist, and the resolver says which.
        $computed = $of("const B = 2 * 3;\nfunction a() { sprintf('@BUDGET@', self::B); }");
        $this->assertSame([], $computed['resolved']);
        $this->assertStringContainsString('not an integer literal', $computed['unresolved'][0] ?? '');

        $missing = $of("function a() { sprintf('@BUDGET@', self::NOPE); }");
        $this->assertStringContainsString('not a constant declared in this file', $missing['unresolved'][0] ?? '');

        // AND A FORMAT WITHOUT THE NEEDLE CONTRIBUTES NOTHING, so the resolver
        // is not simply reporting every sprintf() it meets.
        $unrelated = $of("function a() { sprintf('nothing to see %d', 99); }");
        $this->assertSame([], $unrelated['resolved']);
        $this->assertSame([], $unrelated['unresolved']);
    }

    /**
     * No child budget reaches the parent's own ceiling.
     */
    public function testEveryChildWallClockBudgetLeavesTheParentAlarmRoomToLose(): void
    {
        $limit = $this->defaultTimeLimit();
        $ceiling = $this->ceiling();

        // BOTH FORMS GO THROUGH THE SAME COMPARISON NOW. The parametrised half
        // used to be reported and left unevaluated; it is resolved through the
        // token stream and checked here exactly like a literal, so a `%d` site
        // whose constant is raised over the ceiling reds in the same breath as
        // a digit would. {@see resolvedParametrisedIn()}
        $budgets = $this->childBudgets();

        // AND A SHAPE THE CENSUS COULD NOT REDUCE IS A RED, NOT A SHRUG
        // (rule 14). This bucket is empty in this tree and the fixtures in
        // {@see testTheCensusClassifiesShapesWhoseAnswerIsKnown()} are what
        // stop that emptiness being the answer a dead scanner also gives.
        $this->assertSame(
            [],
            $budgets['unresolved'],
            'a child wall-clock wrapper was found whose budget this census cannot read, and it '
            . 'is REPORTED rather than dropped: an unreadable budget certified as absent is '
            . 'exactly the hole the old digits-or-%d alphabet left, and two live sites sat in '
            . 'it. Spell the budget as a digit at the wrapper, or widen the alphabet in '
            . 'wallClockWrappersIn() — do not delete the row',
        );

        $rows = array_merge($budgets['literal'], $this->resolvedParametrisedBudgets()['resolved']);

        $this->assertSame(
            [],
            $this->tooLooseIn($rows, $ceiling),
            sprintf(
                "this child's wall-clock budget leaves the parent's own alarm no room to lose. "
                . 'phpunit.xml declares defaultTimeLimit="%d" with enforceTimeLimit and '
                . 'failOnRisky, so a child bound at or near that number means PHPUnit aborts '
                . 'the TEST first: the run reads "aborted after %d seconds", names no child, '
                . 'and sheds every assertion about the exit status the wrapper exists to '
                . 'produce. Bring the child budget to %d or under, or argue for a larger '
                . 'defaultTimeLimit — not for a tighter margin here.',
                $limit,
                $limit,
                $ceiling,
            ),
        );
    }

    /**
     * The census is not vacuously empty, in either of its two forms.
     *
     * RULE 15. `assertSame([], $tooLoose)` above passes just as well when the
     * regex matches nothing at all — which is precisely what would happen if
     * someone reworded the launch helper. The literal population is the control;
     * the parametrised list is named separately so that form stays visible
     * rather than quietly missing (rule 14).
     *
     * WHAT THIS SAID: the parametrised list is named separately "so the form
     * this scan CANNOT evaluate is visible". WHAT IS TRUE NOW: it is evaluated.
     * {@see resolvedParametrisedBudgets()} walks the token stream and resolves
     * every one of those sites to a number, and
     * {@see testEveryChildWallClockBudgetLeavesTheParentAlarmRoomToLose()}
     * folds the results in beside the literal rows and compares both against
     * the same ceiling. WHY THE ARM STILL EARNS ITS PLACE: it is no longer
     * about what cannot be evaluated, it is the liveness control for the
     * REGEX census specifically. That census is what tells the token census
     * which files to look at, and a rewording that blinds it would leave the
     * resolver walking an empty roster and reporting a clean tree —
     * {@see testBothCensusesSeeTheSameParametrisedSites()} only catches a
     * disagreement, and two censuses that both see nothing agree perfectly.
     */
    public function testTheParametrisedFormIsSeenAndReported(): void
    {
        $budgets = $this->childBudgets();

        $this->assertGreaterThanOrEqual(
            8,
            \count($budgets['literal']),
            'the child-budget census found almost no literal budgets, so its verdict that none '
            . 'of them is too loose is worthless',
        );

        $this->assertNotSame(
            [],
            $budgets['parametrised'],
            'no `' . self::WRAPPER . ' -s ' . 'KILL %d` site is being reported. Either every file that passes '
            . 'its budget through sprintf() has been rewritten — in which case this arm should '
            . 'go — or the scan has stopped seeing that form, and this census is what hands '
            . 'the resolver its roster: blind it and the resolver walks an empty tree and '
            . 'reports it clean. NOTE the needle above is ASSEMBLED: spelling it '
            . 'here makes this file its own evidence, which is how this arm passed while the '
            . 'scan was blind to every other file '
            . '(see testThisFileIsNotItsOwnEvidence())',
        );

        // AND THE COMPARISON ITSELF, over an answer already known. A ceiling
        // computed from a limit this test reads must actually reject a number
        // above it; without this, a mis-signed comparison passes everything.
        $limit = $this->defaultTimeLimit();
        $this->assertGreaterThan(
            0,
            $limit - self::REQUIRED_HEADROOM_SECONDS,
            'the headroom is at or above the whole per-test limit, so the ceiling is not a '
            . 'number any child budget could satisfy',
        );
        $this->assertTrue(
            $limit > $limit - self::REQUIRED_HEADROOM_SECONDS,
            'the ceiling is not below the limit it is derived from',
        );
    }
}

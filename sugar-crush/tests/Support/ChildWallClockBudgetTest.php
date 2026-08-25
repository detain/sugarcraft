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
 * WHAT THIS ASSERTS, and it is a RELATION rather than a number: every literal
 * `timeout -s KILL N` in `tests/` has `N` strictly under the `defaultTimeLimit`
 * that `phpunit.xml` actually declares, with headroom. Both sides are READ from
 * the tree, so editing either one is caught: raising a child budget reds here,
 * and so does lowering `defaultTimeLimit` under an existing child budget. No
 * count is asserted (rule 18) — the census re-derives itself.
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
 * TWO INSTRUMENTS WALK THE SAME POPULATION, deliberately. The regex census
 * scrapes raw text and cannot read a value; the token census reads values and
 * only sees `sprintf()` calls. Either going blind is invisible on its own, and
 * neither can go blind without disagreeing with the other.
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
     * Every literal child budget in `tests/`, and every parametrised one.
     *
     * @return array{literal: list<array{0: string, 1: int, 2: int}>, parametrised: list<string>}
     */
    private function childBudgets(): array
    {
        $literal = [];
        $parametrised = [];

        foreach (self::everyTestFile() as $relative => $path) {
            $source = self::readOrFail($path);
            $label = 'tests/' . $relative;

            // THE ALPHABET IS BOTH FORMS AND THE UNPARSEABLE ONE IS REPORTED,
            // not dropped. `%d` is how the two files that got this right spell
            // it, and a scan that matched only digits would report those as
            // absent — the same clean-looking zero rule 14 is about.
            preg_match_all('/timeout -s KILL (\d+|%d)/', $source, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[1] as $hit) {
                $line = substr_count(substr($source, 0, (int) $hit[1]), "\n") + 1;
                if ($hit[0] === '%d') {
                    $parametrised[] = $label . ':' . $line;

                    continue;
                }
                $literal[] = [$label, $line, (int) $hit[0]];
            }
        }
        sort($literal);
        sort($parametrised);

        return ['literal' => $literal, 'parametrised' => $parametrised];
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
        return 'timeout -s ' . 'KILL ' . '%d';
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
     * @return array{resolved: list<array{0: string, 1: int, 2: int}>, unresolved: list<string>}
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
                $unresolved[] = $row . ' — the placeholder is not among the format\'s conversions';

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
            foreach ($seconds as $value) {
                $resolved[] = [$label, $token[2], $value];
            }
        }

        return ['resolved' => $resolved, 'unresolved' => $unresolved];
    }

    /**
     * One argument's value, as every integer it can be, or `null` and a reason.
     *
     * @param array{0: int, 1: int}  $span
     * @param array<string, ?int>    $consts
     *
     * @return array{0: ?list<int>, 1: string}
     */
    private static function resolveArgument(array $tokens, array $span, array $consts): array
    {
        $significant = self::significantIn($tokens, $span);

        if (\count($significant) === 1 && \is_array($tokens[$significant[0]])) {
            $only = $tokens[$significant[0]];
            if ($only[0] === T_LNUMBER) {
                return [[(int) $only[1]], ''];
            }
            if ($only[0] === T_VARIABLE) {
                return self::resolveThroughParameter($tokens, $significant[0], $consts);
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

            return [[$consts[$name]], ''];
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
     *
     * @return array{0: ?list<int>, 1: string}
     */
    private static function resolveThroughParameter(array $tokens, int $variable, array $consts): array
    {
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
            [$resolved, $why] = self::resolveArgument($tokens, $arguments[$position], $consts);
            if ($resolved === null) {
                return [null, 'through ' . $callee . '() parameter ' . $name . ': ' . $why];
            }
            foreach ($resolved as $value) {
                $values[] = $value;
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
     */
    private static function conversionOrdinalOf(string $format): ?int
    {
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
     * @return array{resolved: list<array{0: string, 1: int, 2: int}>, unresolved: list<string>}
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
     * check off — passed the whole file: every budget in the tree is 20 against
     * a ceiling of 50, so nothing sits near the boundary and no real row can
     * tell a working comparison from a disabled one. That is rule 25 exactly:
     * a fixture whose expected value is what a DEAD instrument returns proves
     * nothing. {@see testTheComparisonRejectsBudgetsWhoseAnswerIsKnown()} drives
     * this with rows either side of the boundary.
     *
     * @param list<array{0: string, 1: int, 2: int}> $literal
     *
     * @return list<string>
     */
    private function tooLooseIn(array $literal, int $ceiling): array
    {
        $tooLoose = [];
        foreach ($literal as [$label, $line, $seconds]) {
            if ($seconds > $ceiling) {
                // ASSEMBLED for the same reason the fixture's expectations are:
                // a literal here is a match for this census's own scan of this
                // file.
                $tooLoose[] = $label . ':' . $line . ' — ' . 'timeout -s ' . 'KILL ' . $seconds;
            }
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
        $rows = [
            ['fixture/Far.php', 1, 5],
            ['fixture/At.php', 2, 50],
            ['fixture/OneOver.php', 3, 51],
            ['fixture/AtTheLimit.php', 4, 60],
        ];

        // THE EXPECTED STRINGS ARE ASSEMBLED, NEVER SPELLED (rule 26). This file
        // is inside its own roster: a literal command-and-number written here
        // is scraped by the census as a real child budget, and the first draft
        // of this fixture reported ITSELF as two offenders.
        $shape = 'timeout -s ' . 'KILL ';
        $this->assertSame(
            [
                'fixture/OneOver.php:3 — ' . $shape . '51',
                'fixture/AtTheLimit.php:4 — ' . $shape . '60',
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

        // AND THE SAME TWO ROWS AGAIN, DERIVED FROM THE CEILING THE GUARD
        // ACTUALLY USES. The four rows above are literals, so they cannot tell
        // a correct `ceiling()` from one widened by a hundred seconds — the
        // mutation that survived the whole suite. These two straddle whatever
        // `ceiling()` answers, so the boundary moves with it and the pair below
        // is what stops it moving anywhere it likes.
        $ceiling = $this->ceiling();
        $this->assertSame(
            ['fixture/OverTheDerivedCeiling.php:6 — ' . $shape . ($ceiling + 1)],
            $this->tooLooseIn(
                [
                    ['fixture/AtTheDerivedCeiling.php', 5, $ceiling],
                    ['fixture/OverTheDerivedCeiling.php', 6, $ceiling + 1],
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
     * (rule 14). The regex census scrapes raw text; the token census walks
     * `sprintf()` calls. Either one going blind is invisible on its own — an
     * empty verdict looks identical to a clean tree — and neither can go blind
     * without the other disagreeing here.
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
        $this->assertSame([['fixture.php', 3, 21]], $plain['resolved'], 'a literal budget did not resolve');
        $this->assertSame([], $plain['unresolved']);

        // A `self::` CONSTANT, which is how four of the five real sites spell it.
        $const = $of("const B = 22;\nfunction a() { sprintf('@BUDGET@', self::B); }");
        $this->assertSame([['fixture.php', 4, 22]], $const['resolved'], 'a self:: constant budget did not resolve');

        // THE ORDINAL, with a conversion AHEAD of the placeholder. A resolver
        // that always took argument 1 answers 'first' here and this reds.
        $shifted = $of("function a() { sprintf('%s @BUDGET@ %s', 'first', 23, 'third'); }");
        $this->assertSame([['fixture.php', 3, 23]], $shifted['resolved'], 'the placeholder was counted as the wrong conversion');

        // AND `%%` IS AN ESCAPE, NOT A CONVERSION. Counting it shifts every
        // ordinal after it by one, which resolves 'first' instead of 24.
        $escaped = $of("function a() { sprintf('100%% %s @BUDGET@', 'first', 24); }");
        $this->assertSame([['fixture.php', 3, 24]], $escaped['resolved'], '%% was counted as a conversion');

        // A CONCATENATED FORMAT, which is how the longest real site spells it.
        $joined = $of("function a() { sprintf('@BUDGET@ ' . '%s', 25, 'tail'); }");
        $this->assertSame([['fixture.php', 3, 25]], $joined['resolved'], 'a concatenated format string was not decoded');

        // THROUGH A PARAMETER, WITH TWO CALLERS AND TWO ANSWERS — the real
        // shape, and the reason the resolver returns a list rather than a
        // number. A resolver taking only the first call answers [26] and reds.
        $viaParameter = $of(
            "const LOW = 26;\nconst HIGH = 27;\n"
            . "function a() { \$this->b(self::LOW); \$this->b(self::HIGH); }\n"
            . "function b(int \$bound) { sprintf('@BUDGET@', \$bound); }"
        );
        $this->assertSame(
            [['fixture.php', 6, 26], ['fixture.php', 6, 27]],
            $viaParameter['resolved'],
            'a budget handed down as a parameter did not resolve to every value its callers pass',
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
        $rows = array_merge($this->childBudgets()['literal'], $this->resolvedParametrisedBudgets()['resolved']);

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
     * the parametrised list is named separately so the form this scan CANNOT
     * evaluate is visible rather than quietly missing (rule 14).
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
            'no `timeout -s ' . 'KILL %d` site is being reported. Either every file that passes '
            . 'its budget through sprintf() has been rewritten — in which case this arm should '
            . 'go — or the scan has stopped seeing that form, and it is exactly the form whose '
            . 'value this guard cannot check. NOTE the needle above is ASSEMBLED: spelling it '
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

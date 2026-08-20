<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Permissions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Permissions\PermissionAction;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\Tools\Tool;

/**
 * {@see PermissionRule}'s pattern language — the half that did not exist.
 *
 * MEASURED ON THE UNFIXED BUILD, with `PermissionGate::ruleMatches()` still
 * comparing only `$call->name`: `Deny Bash(rm -rf *)` against
 * `Bash(command: "rm -rf /tmp/mine")` evaluated to `allow`, and so did
 * `Deny Read(./.env)` against `Read(file_path: "./.env")`. Both spellings are in
 * this package's own documentation. Every argument-scoped case below therefore
 * fails against that build — not by erroring, which is the point: it returned a
 * confident wrong answer in the permissive direction.
 *
 * These tests are deliberately about the TRUTH of each clause rather than its
 * presence: each one names a call that must match and a neighbouring call that
 * must not, so a matcher that answered "yes" to everything would fail as loudly
 * as the one that answered "no" to everything did.
 */
final class PermissionRulePatternTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Grammar: well-formedness
    // -------------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function wellFormedCases(): iterable
    {
        yield 'bare name' => ['Bash', true];
        yield 'prefix glob' => ['Bash*', true];
        yield 'mcp glob' => ['mcp__git__*', true];
        yield 'infix glob' => ['mcp__*__push', true];
        yield 'argument scoped' => ['Bash(rm -rf *)', true];
        yield 'nested parens in the argument' => ['Bash(echo (x))', true];
        yield 'empty argument' => ['Bash()', true];
        yield 'unclosed paren' => ['Bash(rm -rf', false];
        yield 'stray close paren' => ['Bash)', false];
        yield 'no name half' => ['(rm -rf *)', false];
        yield 'empty pattern' => ['', false];
    }

    #[DataProvider('wellFormedCases')]
    public function testWellFormednessIsBalancedParenthesesAndANonEmptyName(string $pattern, bool $expected): void
    {
        self::assertSame($expected, PermissionRule::isWellFormedPattern($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectionReasonCases(): iterable
    {
        yield 'empty pattern' => ['', 'is empty'];
        yield 'no name half' => ['(rm -rf *)', 'no tool-name half'];
        yield 'unclosed paren' => ['Bash(rm -rf', 'unterminated'];
        yield 'stray close paren' => ['Bash)', 'never opens one'];
    }

    /**
     * THE REJECTION REASON MUST BE THE REASON, and the first cut's was not.
     *
     * `Bootstrap::permissionRules()` warned "has an unbalanced parenthesis" for
     * every rejection the grammar made. Measured, that is false for two of the
     * four: `''` contains no parenthesis at all, and `'(rm *)'` contains a
     * BALANCED pair — both are refused for having no tool-name half, which
     * `isWellFormedPattern()`'s own doc-block already said. A diagnostic whose
     * job is telling a user what they typed wrong, naming the wrong thing.
     *
     * The assertion is deliberately on a distinguishing SUBSTRING rather than on
     * the whole sentence: pinning the exact prose would make every rewording a
     * test failure, while pinning nothing at all is how the wrong cause survived.
     */
    #[DataProvider('rejectionReasonCases')]
    public function testARejectedPatternReportsTheReasonTheGrammarActuallyGave(
        string $pattern,
        string $expectedFragment,
    ): void {
        $reason = PermissionRule::patternRejectionReason($pattern);

        self::assertIsString($reason, $pattern . ' must be rejected');
        self::assertStringContainsString($expectedFragment, $reason, $pattern);
        // The two reasons must be DISTINGUISHABLE, which is the whole point: an
        // unbalanced-parenthesis message for an empty pattern passed a
        // "reports something" check and still misled.
        self::assertStringNotContainsString('unbalanced', $reason, $pattern);
    }

    /**
     * The other direction: a well-formed pattern has no reason at all, and
     * {@see PermissionRule::isWellFormedPattern()} is exactly that `=== null`,
     * so the two cannot drift into two grammars.
     */
    #[DataProvider('wellFormedCases')]
    public function testTheRejectionReasonAndTheWellFormednessCheckAreOneGrammar(
        string $pattern,
        bool $wellFormed,
    ): void {
        self::assertSame(
            $wellFormed,
            PermissionRule::patternRejectionReason($pattern) === null,
            $pattern,
        );
    }

    /**
     * A malformed pattern degrades to a name that matches no real tool — it must
     * NOT become a deny-everything, because a typo must not be able to brick a
     * session, and it must not become an allow-everything either.
     */
    public function testAMalformedPatternMatchesNothingRatherThanEverything(): void
    {
        $rule = new PermissionRule('Bash(rm -rf', PermissionAction::Deny);

        self::assertFalse($rule->matches(new ToolCall('Bash', ['command' => 'rm -rf /'])));
        self::assertFalse($rule->matches(new ToolCall('Bash', ['command' => 'ls'])));
        self::assertFalse($rule->matches(new ToolCall('Read', ['file_path' => 'x'])));
    }

    // -------------------------------------------------------------------------
    // Grammar: the two halves
    // -------------------------------------------------------------------------

    public function testTheNameHalfIsEverythingBeforeTheFirstParen(): void
    {
        self::assertSame('Bash', (new PermissionRule('Bash(rm *)', PermissionAction::Deny))->toolNamePattern());
        self::assertSame('mcp__git__*', (new PermissionRule('mcp__git__*', PermissionAction::Deny))->toolNamePattern());
    }

    public function testTheArgumentHalfIsTheParenthesisedRemainderAndKeepsItsOwnParens(): void
    {
        self::assertSame('rm -rf *', (new PermissionRule('Bash(rm -rf *)', PermissionAction::Deny))->argumentPattern());
        self::assertSame('echo (x)', (new PermissionRule('Bash(echo (x))', PermissionAction::Deny))->argumentPattern());
    }

    /**
     * `Tool()` means "any arguments", not "the empty argument" — the alternative
     * reading makes a rule that can never fire, which is the whole defect this
     * class was rewritten for.
     */
    public function testAnEmptyArgumentGlobMeansTheBareToolName(): void
    {
        $rule = new PermissionRule('Bash()', PermissionAction::Deny);

        self::assertNull($rule->argumentPattern());
        self::assertTrue($rule->matches(new ToolCall('Bash', ['command' => 'anything at all'])));
        self::assertTrue($rule->matches(new ToolCall('Bash', [])));
    }

    /**
     * The old matcher's two behaviours, preserved by `fnmatch()` being a
     * superset of "trailing `*` is a prefix, otherwise exact".
     */
    public function testTheOldNameOnlySemanticsStillHold(): void
    {
        self::assertTrue(PermissionRule::matchesToolName('Bash', 'Bash'));
        self::assertFalse(PermissionRule::matchesToolName('Bash', 'BashSomething'));
        self::assertTrue(PermissionRule::matchesToolName('Bash*', 'Bash'));
        self::assertTrue(PermissionRule::matchesToolName('mcp__git__*', 'mcp__git__push'));
        self::assertFalse(PermissionRule::matchesToolName('mcp__git__*', 'mcp__jira__push'));
        // The addition: an infix glob, which the prefix matcher could not express.
        self::assertTrue(PermissionRule::matchesToolName('mcp__*__push', 'mcp__git__push'));
    }

    /**
     * `FNM_PATHNAME` is deliberately unset, so one `*` crosses `/` — the reading
     * a user writing `Read(./secrets/*)` expects.
     */
    public function testASingleStarCrossesPathSeparators(): void
    {
        $rule = new PermissionRule('Read(./secrets/*)', PermissionAction::Deny);

        self::assertTrue($rule->matches(new ToolCall('Read', ['file_path' => './secrets/deep/nested.key'])));
        self::assertFalse($rule->matches(new ToolCall('Read', ['file_path' => './public/readme.md'])));
    }

    // -------------------------------------------------------------------------
    // The subject argument
    // -------------------------------------------------------------------------

    /**
     * THE INSTRUMENT for {@see PermissionRule::SUBJECT_ARGUMENTS}: every mapped
     * argument name is re-derived from the tool's own `inputSchema()` at test
     * time. A schema rename therefore breaks a test instead of silently turning
     * an argument-scoped rule back into a no-op — which is precisely how the
     * whole grammar went unnoticed.
     *
     * `required`, not merely `properties`: a subject argument that a real call
     * may legitimately omit would send the common case down the
     * unknowable-subject branch, where a `Deny` over-blocks the tool entirely
     * and an `Allow` never fires.
     */
    public function testEverySubjectArgumentIsARequiredPropertyOfThatToolsSchema(): void
    {
        $byName = [];
        foreach (\SugarCraft\Crush\Cli\Bootstrap::tools(__DIR__) as $tool) {
            self::assertInstanceOf(Tool::class, $tool);
            $byName[$tool->name()] = $tool->inputSchema();
        }

        foreach (PermissionRule::SUBJECT_ARGUMENTS as $toolName => $argument) {
            self::assertArrayHasKey(
                $toolName,
                $byName,
                "SUBJECT_ARGUMENTS names '{$toolName}', which Bootstrap::tools() does not build",
            );

            $schema = $byName[$toolName];
            self::assertContains(
                $argument,
                array_keys($schema['properties'] ?? []),
                "{$toolName}'s subject '{$argument}' is not a property of its inputSchema()",
            );
            self::assertContains(
                $argument,
                $schema['required'] ?? [],
                "{$toolName}'s subject '{$argument}' is not required, so a real call may omit it",
            );
        }
    }

    /**
     * The complement of the census above: `doctor` is the one built-in with no
     * subject, and it is unmapped rather than mapped to something arbitrary.
     * Asserted so that "unmapped" stays a decision rather than becoming an
     * oversight nobody notices.
     */
    public function testDoctorHasNoSubjectArgumentBecauseItsSchemaHasNoProperties(): void
    {
        self::assertNull(PermissionRule::subjectArgumentName('doctor'));
        self::assertNull(PermissionRule::subjectArgumentName('mcp__git__push'));
        self::assertSame('command', PermissionRule::subjectArgumentName('Bash'));
    }

    /**
     * `Glob`/`Grep` are matched on the directory they REACH, not the pattern
     * they search with. Pinned because it is a choice a reader will want to
     * change, and the reason is on {@see PermissionRule::SUBJECT_ARGUMENTS}.
     */
    public function testGrepIsScopedByPathAndNotBySearchPattern(): void
    {
        $rule = new PermissionRule('Grep(/etc*)', PermissionAction::Deny);

        self::assertTrue($rule->matches(new ToolCall('Grep', ['pattern' => 'anything', 'path' => '/etc/shadow'])));
        self::assertFalse($rule->matches(new ToolCall('Grep', ['pattern' => '/etc*', 'path' => './src'])));
    }

    // -------------------------------------------------------------------------
    // The restrictive/permissive asymmetry
    // -------------------------------------------------------------------------

    /**
     * The SEGMENT half of the restrictive union: the cheap evasions a shell-text
     * deny must survive — whitespace runs, and the command hiding behind a
     * separator.
     *
     * RENAMED FROM a test that claimed the union of "whole command AND every
     * segment" and asserted only the segment half. Measured: deleting the
     * whole-command arm from {@see PermissionRule::matchesShellSubject()} left
     * this file green, because every case here is satisfied by segment matching
     * alone. {@see testTheWholeCommandArmIsWhatMakesASeparatorSpanningPatternWork()}
     * is the other half, and it is written so that the segment loop cannot
     * satisfy it.
     */
    public function testARestrictiveRuleFiresOnEverySegmentOfACommandChain(): void
    {
        $rule = new PermissionRule('Bash(rm -rf *)', PermissionAction::Deny);

        self::assertTrue($rule->matches(new ToolCall('Bash', ['command' => 'rm -rf /tmp/x'])));
        self::assertTrue($rule->matches(new ToolCall('Bash', ['command' => '  rm -rf /tmp/x  '])));
        self::assertTrue($rule->matches(new ToolCall('Bash', ['command' => 'rm   -rf  /tmp/x'])));
        self::assertTrue($rule->matches(new ToolCall('Bash', ['command' => 'echo hi && rm -rf /tmp/x'])));
        self::assertTrue($rule->matches(new ToolCall('Bash', ['command' => 'true; rm -rf /tmp/x; echo done'])));
        self::assertTrue($rule->matches(new ToolCall('Bash', ['command' => 'echo hi | rm -rf /tmp/x'])));
        // And it is still a rule about `rm -rf`, not about everything.
        self::assertFalse($rule->matches(new ToolCall('Bash', ['command' => 'git log --oneline'])));
    }

    /**
     * A NEWLINE IS A SHELL SEPARATOR, and the first cut of this class dropped
     * it — the one evasion in the "closed" list that was not actually closed.
     *
     * Mechanism, because the fix is an ORDER and not a new feature:
     * `collapseWhitespace()` ran BEFORE `preg_split('/[;&|]+/')`, so
     * `preg_replace('/\s+/', ' ')` had already turned every `\n` into a space
     * by the time the splitter looked. Measured through the gate before the fix:
     * `echo hi && rm -rf /tmp/x` -> Deny, `echo hi\nrm -rf /tmp/x` -> Ask.
     *
     * The `assertNotSame` at the end is the instrument: it asserts the two
     * spellings are DIFFERENT STRINGS, so a reader cannot mistake this for a
     * restatement of the `&&` case above.
     */
    public function testANewlineSeparatesCommandsExactlyAsASemicolonDoes(): void
    {
        $rule = new PermissionRule('Bash(rm -rf *)', PermissionAction::Deny);

        foreach (["echo hi\nrm -rf /tmp/x", "echo hi\r\nrm -rf /tmp/x", "true\n rm   -rf  /tmp/x \n"] as $command) {
            self::assertTrue(
                $rule->matches(new ToolCall('Bash', ['command' => $command])),
                json_encode($command) . ' hid the second command behind a newline',
            );
        }

        self::assertNotSame('echo hi && rm -rf /tmp/x', "echo hi\nrm -rf /tmp/x");
    }

    /**
     * THE WHOLE-COMMAND ARM OF THE RESTRICTIVE UNION, pinned by a case the
     * segment loop provably cannot answer.
     *
     * `Deny Bash(* && rm *)` is a pattern written AROUND a separator. The
     * splitter removes that separator, so no segment of any command can ever
     * match it — asserted below rather than argued, which is what makes this
     * test about the whole-command arm and not merely near it. Deleting that arm
     * therefore turns this deny into a no-op, and the doc-block clause claiming
     * the arm "is what makes a pattern that spans a separator work at all" had
     * ZERO coverage until this test: a mutation deleting it left the whole
     * permissions namespace green.
     */
    public function testTheWholeCommandArmIsWhatMakesASeparatorSpanningPatternWork(): void
    {
        $pattern = '* && rm *';
        $command = 'echo hi && rm -rf /tmp/x';

        // The premise, measured rather than assumed: the pattern contains `&&`,
        // and every segment the splitter can produce does not.
        foreach (preg_split('/[;&|\r\n]+/', $command) ?: [] as $segment) {
            self::assertFalse(
                fnmatch($pattern, trim($segment)),
                "segment '{$segment}' must not match, or this test would pass without the whole-command arm",
            );
        }

        self::assertTrue(
            (new PermissionRule("Bash({$pattern})", PermissionAction::Deny))
                ->matches(new ToolCall('Bash', ['command' => $command])),
        );

        // Still a rule about a chain ending in `rm`, not about everything.
        self::assertFalse(
            (new PermissionRule("Bash({$pattern})", PermissionAction::Deny))
                ->matches(new ToolCall('Bash', ['command' => 'echo hi && git log'])),
        );
    }

    /**
     * THE HOLE THE ASYMMETRY CLOSES. `fnmatch('git *', 'git log && rm -rf /')`
     * is TRUE — `*` is greedy — so a whole-command match cannot be what grants.
     * An `Allow` therefore requires EVERY segment to match.
     */
    public function testAPermissiveRuleFiresOnlyWhenEverySegmentMatches(): void
    {
        // The premise, asserted rather than assumed: this is why the Allow arm
        // may not consult the whole command.
        self::assertTrue(fnmatch('git *', 'git log && rm -rf /'));

        $rule = new PermissionRule('Bash(git *)', PermissionAction::Allow);

        self::assertTrue($rule->matches(new ToolCall('Bash', ['command' => 'git log'])));
        self::assertFalse($rule->matches(new ToolCall('Bash', ['command' => 'git log && rm -rf /'])));
        self::assertFalse($rule->matches(new ToolCall('Bash', ['command' => 'rm -rf / && git log'])));
    }

    /**
     * THE FAIL-OPEN GUARD IN THE ALLOW ARM, which had no test at all: a command
     * of nothing but separators produces an EMPTY segment list, and an
     * intersection over an empty set is vacuously true.
     *
     * Measured on a mutant that returned `true` there instead of `false`:
     * `Allow Bash(git *)` matched `;;;`, `&&`, `| |` and `; ;`, and both
     * permission test files stayed green. The clause "An empty segment list …
     * grants nothing" was present in the doc-block and pinned by nothing.
     *
     * The `assertSame([], …)` is the instrument: it establishes that these
     * subjects really do reach the empty-list branch, so the test cannot be
     * satisfied by the intersection loop simply finding a non-matching segment.
     */
    public function testASeparatorOnlyCommandGrantsNothingRatherThanVacuouslyEverything(): void
    {
        $rule = new PermissionRule('Bash(git *)', PermissionAction::Allow);

        foreach ([';;;', '&&', '| |', '; ;', ' ; ', "\n;\n"] as $command) {
            self::assertSame(
                [],
                array_values(array_filter(
                    array_map('trim', preg_split('/[;&|\r\n]+/', $command) ?: []),
                    static fn (string $segment): bool => $segment !== '',
                )),
                json_encode($command) . ' must reach the empty-segment branch for this test to be about it',
            );

            self::assertFalse(
                $rule->matches(new ToolCall('Bash', ['command' => $command])),
                json_encode($command) . ' must not be granted by an empty intersection',
            );
        }

        // The control: the same rule DOES grant a command it names, so the
        // assertions above are about the empty list and not about the rule
        // being inert.
        self::assertTrue($rule->matches(new ToolCall('Bash', ['command' => 'git log'])));
    }

    /**
     * `Ask` is restrictive for this purpose — it takes the decision away from
     * the model and gives it to a human — so it shares the union rule.
     */
    public function testAskSharesTheRestrictiveUnionRule(): void
    {
        $rule = new PermissionRule('Bash(rm *)', PermissionAction::Ask);

        self::assertTrue($rule->matches(new ToolCall('Bash', ['command' => 'echo hi && rm x'])));
    }

    /**
     * @return iterable<string, array{PermissionAction, bool}>
     */
    public static function unknowableSubjectCases(): iterable
    {
        yield 'deny fails closed and fires' => [PermissionAction::Deny, true];
        yield 'ask fails closed and fires' => [PermissionAction::Ask, true];
        yield 'allow fails closed and does not fire' => [PermissionAction::Allow, false];
    }

    /**
     * An UNKNOWABLE subject — an unmapped tool, or a mapped one whose subject is
     * absent or not a string. Both directions are "fail closed"; they differ
     * only in which answer that is.
     */
    #[DataProvider('unknowableSubjectCases')]
    public function testAnUnknowableSubjectFiresARestrictiveRuleAndNeverAPermissiveOne(
        PermissionAction $action,
        bool $expected,
    ): void {
        // Unmapped tool.
        self::assertSame(
            $expected,
            (new PermissionRule('doctor(anything)', $action))->matches(new ToolCall('doctor', [])),
        );
        // Mapped tool, subject absent.
        self::assertSame(
            $expected,
            (new PermissionRule('Bash(rm *)', $action))->matches(new ToolCall('Bash', [])),
        );
        // Mapped tool, subject present but not a string.
        self::assertSame(
            $expected,
            (new PermissionRule('Read(x)', $action))->matches(new ToolCall('Read', ['file_path' => ['x']])),
        );
    }

    /**
     * A rule whose NAME half misses is not a match whatever the subject does —
     * the fail-closed branch above must not leak into other tools.
     */
    public function testTheNameHalfIsCheckedBeforeTheSubjectSoADenyDoesNotSpread(): void
    {
        $rule = new PermissionRule('doctor(anything)', PermissionAction::Deny);

        self::assertFalse($rule->matches(new ToolCall('Bash', ['command' => 'rm -rf /'])));
        self::assertFalse($rule->matches(new ToolCall('Read', [])));
    }

    // -------------------------------------------------------------------------
    // Path subjects: spelling normalisation, and the restrictive depth reading
    // -------------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function pathSpellingCases(): iterable
    {
        // The same file, spelled differently. All must be caught: this is
        // normalisation, not policy.
        yield 'the advertised spelling' => ['./.env', true];
        yield 'the spelling a model actually emits' => ['.env', true];
        yield 'a doubled slash' => ['.//.env', true];
        yield 'a dot segment mid-path' => ['./././.env', true];
        yield 'a resolvable parent segment' => ['./foo/../.env', true];
        // A different file whose name merely ENDS the same way. Segment-aligned
        // suffixes are what keep these out; a substring reading would not.
        yield 'a different file with a matching tail' => ['./notes.env', false];
        yield 'a different file entirely' => ['./config.yaml', false];
    }

    /**
     * `Deny Read(./.env)` — the exact example this package's own documentation
     * advertises — used to catch ONE of these spellings, and not the likeliest.
     *
     * Measured before the fix: `./.env` -> Deny; `.env`, `.//.env`,
     * `./foo/../.env`, `proj/./.env` and `/home/u/proj/.env` all -> Ask. The
     * "HONEST LIMITS" block was scoped to `Bash` at the time, which by omission
     * implied a path deny was tight. It was not.
     */
    #[DataProvider('pathSpellingCases')]
    public function testAPathDenyCatchesEverySpellingOfTheSamePath(string $subject, bool $expected): void
    {
        $rule = new PermissionRule('Read(./.env)', PermissionAction::Deny);

        self::assertSame($expected, $rule->matches(new ToolCall('Read', ['file_path' => $subject])), $subject);
    }

    /**
     * Normalisation runs on BOTH sides, so the pattern's own spelling is just as
     * free as the call's. Otherwise the fix would only have moved the problem to
     * whoever writes the config.
     */
    public function testTheNormalisationIsSymmetricBetweenPatternAndSubject(): void
    {
        foreach (['Read(./.env)', 'Read(.env)', 'Read(.//.env)', 'Read(./foo/../.env)'] as $pattern) {
            $rule = new PermissionRule($pattern, PermissionAction::Deny);

            self::assertTrue(
                $rule->matches(new ToolCall('Read', ['file_path' => '.env'])),
                $pattern . ' must be the same rule as every other spelling of it',
            );
        }
    }

    /**
     * THE DEPTH READING, and its asymmetry — the one piece of path matching that
     * is a widening rather than a canonicalisation, so the class doc-block's
     * rule decides where it may go.
     *
     * A relative pattern maps to MANY DIFFERENT paths, not to one path spelled
     * many ways. That is safe for a rule that takes capability away and unsafe
     * for one that grants: without the split, `Allow Read(.env)` would have
     * granted `/etc/.env`. Both directions are asserted here, in one test,
     * because either alone reads as an accident.
     */
    public function testARelativePathPatternReadsAtAnyDepthForARestrictiveRuleOnly(): void
    {
        $deep = '/home/u/proj/.env';

        self::assertTrue(
            (new PermissionRule('Read(.env)', PermissionAction::Deny))
                ->matches(new ToolCall('Read', ['file_path' => $deep])),
        );
        self::assertTrue(
            (new PermissionRule('Read(.env)', PermissionAction::Ask))
                ->matches(new ToolCall('Read', ['file_path' => $deep])),
        );
        self::assertFalse(
            (new PermissionRule('Read(.env)', PermissionAction::Allow))
                ->matches(new ToolCall('Read', ['file_path' => $deep])),
            'a permissive relative pattern must not grant an unrelated absolute path',
        );

        // The control for the Allow arm: it still grants the path it names, so
        // the assertion above is about depth and not about Allow being inert.
        self::assertTrue(
            (new PermissionRule('Read(.env)', PermissionAction::Allow))
                ->matches(new ToolCall('Read', ['file_path' => './.env'])),
        );
    }

    /**
     * An ABSOLUTE pattern is anchored by the user's own leading `/`, so the
     * depth reading does not apply to it. Reading `Deny Read(/etc/passwd)` as
     * "any `etc/passwd` at any depth" would invent an intent the `/` denies.
     */
    public function testAnAbsolutePathPatternIsNotReadAtAnyDepth(): void
    {
        $rule = new PermissionRule('Read(/etc/passwd)', PermissionAction::Deny);

        self::assertTrue($rule->matches(new ToolCall('Read', ['file_path' => '/etc/./passwd'])));
        self::assertFalse($rule->matches(new ToolCall('Read', ['file_path' => './vendor/etc/passwd'])));
    }

    /**
     * The depth reading is SEGMENT-ALIGNED, which is the difference between "the
     * same file spelled differently" and "a different file". A substring reading
     * would make `Deny Read(.env)` over-block every `*.env` name.
     */
    public function testTheDepthReadingIsSegmentAlignedAndNotASubstring(): void
    {
        $rule = new PermissionRule('Read(.env)', PermissionAction::Deny);

        self::assertTrue($rule->matches(new ToolCall('Read', ['file_path' => '/a/b/.env'])));
        self::assertFalse($rule->matches(new ToolCall('Read', ['file_path' => '/a/b/prod.env'])));
        self::assertFalse($rule->matches(new ToolCall('Read', ['file_path' => '/a/.env/b'])));
    }

    /**
     * Every tool in {@see PermissionRule::PATH_SUBJECT_TOOLS} gets the treatment,
     * not just `Read` — asserted through the tools' OWN subject argument names,
     * which differ (`file_path` vs `path`), so a mapping change breaks this.
     */
    public function testEveryPathSubjectToolNormalisesItsPathAndNoneOfTheOthersDo(): void
    {
        foreach (['Read', 'Edit', 'Write', 'Glob', 'Grep', 'Lsp'] as $tool) {
            $argument = PermissionRule::subjectArgumentName($tool);
            self::assertIsString($argument, $tool . ' must have a mapped subject argument');

            self::assertTrue(
                (new PermissionRule("{$tool}(./secrets/key)", PermissionAction::Deny))
                    ->matches(new ToolCall($tool, [$argument => 'secrets/key'])),
                $tool . ' must normalise the `./` prefix away',
            );
        }

        // A NON-path subject is matched literally: a search query is one opaque
        // value, and `./` in it is a character, not a path prefix.
        self::assertFalse(
            (new PermissionRule('WebSearch(./secrets/key)', PermissionAction::Deny))
                ->matches(new ToolCall('WebSearch', ['query' => 'secrets/key'])),
            'a query is not a path and must not be normalised',
        );
    }

    /**
     * A non-shell subject gets no segment treatment: a path containing `&` or
     * `|` is one path, and splitting it would make `Deny Read(a&b)` unwritable.
     */
    public function testANonShellSubjectIsNeverSplitOnShellSeparators(): void
    {
        $rule = new PermissionRule('Read(*&*)', PermissionAction::Deny);

        self::assertTrue($rule->matches(new ToolCall('Read', ['file_path' => './a&b.txt'])));
    }

    /**
     * The declaration contract, stated at this level: `$argumentsKnown = false`
     * makes an argument-scoped rule inert for EVERY action, so an unknowable
     * subject and an unknowable ARGUMENT SET are not the same thing.
     * {@see PermissionGateArgumentRulesTest} pins the same decision through
     * `PermissionGate::refuses()`.
     */
    public function testUnknownArgumentsMakeAnArgumentScopedRuleInertForEveryAction(): void
    {
        foreach (PermissionAction::cases() as $action) {
            self::assertFalse(
                (new PermissionRule('Bash(rm -rf *)', $action))->matches(new ToolCall('Bash'), false),
                $action->value . ': an argument-scoped rule must not settle a declaration',
            );

            // The control: the NAME-only rule still applies to a declaration,
            // which is what makes the assertion above about arguments rather
            // than about rules being switched off wholesale.
            self::assertTrue(
                (new PermissionRule('Bash', $action))->matches(new ToolCall('Bash'), false),
                $action->value . ': a name-only rule must still apply to a declaration',
            );
        }
    }
}

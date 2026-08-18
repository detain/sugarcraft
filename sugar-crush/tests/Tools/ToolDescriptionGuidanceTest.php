<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Tools\BuiltIn\Bash;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Glob;
use SugarCraft\Crush\Tools\BuiltIn\Grep;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * The five most-used tools' `description()` is the model's ONLY first-contact
 * documentation for a tool it has not called yet in this session, so each of
 * them now carries the handful of facts a caller otherwise pays a wasted turn
 * to learn (crush_code.md Phase 5 item 2 / section 12 finding 2).
 *
 * Every test here asserts a PROPERTY — a stated fact, or a number read off the
 * instance that owns it — never a paragraph of prose. The wording will be
 * edited again; what must not silently change is whether the description still
 * tells the truth about the code.
 *
 * The one behavioural test in this file, {@see
 * testGrepReallyIsBasicRegularExpressionSyntaxAsItsDescriptionClaims()}, is
 * what keeps the regex-dialect clause honest: it exercises `Grep::execute()`
 * itself, so the claim and the command it describes cannot drift apart.
 *
 * @see Bash::description()
 * @see Edit::description()
 * @see Read::description()
 * @see Grep::description()
 * @see Glob::description()
 */
final class ToolDescriptionGuidanceTest extends TestCase
{
    /** @var list<string> */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach ($this->scratch as $dir) {
            foreach ((array) glob($dir . '/*') as $file) {
                if (is_string($file)) {
                    @unlink($file);
                }
            }
            @rmdir($dir);
        }
        $this->scratch = [];

        parent::tearDown();
    }

    private function scratchDir(): string
    {
        $dir = sys_get_temp_dir() . '/crush_tooldesc_' . uniqid('', true);
        mkdir($dir);
        $this->scratch[] = $dir;

        return $dir;
    }

    /**
     * Check a description against a table of {claim phrase => predicate over
     * the result the tool JUST produced}, and require that it makes at least
     * one of them.
     *
     * This is the shape that has power over a clause's TRUTH rather than its
     * presence, and it exists because presence had none: an adversarial round
     * mutated five clauses to their opposites and every keyword-presence
     * assertion stayed green. `assertStringContainsString('merged', ...)`
     * passes for "stderr is merged", for "stderr is never merged", and for
     * "merged" in a sentence about something else.
     *
     * So: drive the tool, then hold the description to what was observed. A
     * description containing a phrase whose predicate is false against that
     * observation fails. The at-least-one requirement is the other half — it
     * is what stops a description that says nothing about the behaviour from
     * passing vacuously, so deleting the clause is a failure too, not a pass.
     *
     * @param array<string, callable(string): bool> $claims
     */
    private function assertDescribesObservedResult(
        string $subject,
        string $description,
        string $observed,
        array $claims,
    ): void {
        $made = [];

        foreach ($claims as $phrase => $holds) {
            if (!str_contains($description, $phrase)) {
                continue;
            }

            $made[] = $phrase;
            $this->assertTrue(
                $holds($observed),
                sprintf(
                    '%s claims "%s", which is FALSE of the result it just produced: %s',
                    $subject,
                    $phrase,
                    var_export($observed, true),
                ),
            );
        }

        $this->assertNotEmpty(
            $made,
            sprintf(
                '%s makes none of the known claims about this result (%s). Either the '
                . 'behaviour changed and the table is stale, or the description stopped '
                . 'describing the result at all — both are failures, not passes.',
                $subject,
                implode(' / ', array_keys($claims)),
            ),
        );
    }

    // -------------------------------------------------------------------------
    // Bash — nothing persists between calls, and the output is bounded.
    // -------------------------------------------------------------------------

    /**
     * The non-persistence of the working directory is the single most
     * expensive thing to learn by experiment: a model that believes `cd` sticks
     * writes a two-call sequence whose second call silently runs somewhere
     * else. {@see Bash::execute()} builds a fresh `bash -c` every time, so the
     * description has to say so.
     */
    public function testBashDescriptionSaysNothingCarriesOverBetweenCalls(): void
    {
        $description = (new Bash())->description();

        $this->assertStringContainsString('fresh `bash -c`', $description);
        $this->assertStringContainsString('cd', $description);
        $this->assertStringContainsString('no effect on the next call', $description);
        $this->assertStringContainsString('environment variables', $description);
    }

    /**
     * A number in a description must belong to the instance that prints it.
     * Bash's cap is a constructor argument, so a hardcoded figure would be a
     * true statement about the default attached to an instance that does not
     * use it.
     */
    public function testBashDescriptionReportsItsOwnOutputCapNotTheDefault(): void
    {
        $this->assertStringContainsString('4,096 bytes', (new Bash(maxOutputBytes: 4096))->description());
        $this->assertStringContainsString('65,536 bytes', (new Bash())->description());
        $this->assertStringNotContainsString('65,536', (new Bash(maxOutputBytes: 4096))->description());
    }

    /** A caller that disabled the cap must not advertise one. */
    public function testBashDescriptionAdvertisesNoCapWhenTheCapIsDisabled(): void
    {
        $description = (new Bash(maxOutputBytes: 0))->description();

        $this->assertStringContainsString('no size cap on this instance', $description);
        $this->assertStringNotContainsString('clipped at', $description);
    }

    /**
     * The stderr rule, DRIVEN. This is the one the round-16 review caught: the
     * description said "stdout and stderr are merged" unconditionally, and
     * {@see \SugarCraft\Crush\Tools\Concerns\CapturesProcessOutput::mergeCapturedOutput()}
     * decides per branch — the three branches disagree, and the one a model
     * meets most often (a command that succeeds while warning on stderr) does
     * NOT merge. A model told otherwise reads a green `phpunit` or compiler run
     * as warning-free.
     *
     * All three branches are exercised here, so the description cannot be
     * written from one of them again without a red test.
     */
    public function testBashReallyRoutesStderrThreeDifferentWaysAsItsDescriptionSays(): void
    {
        $bash = new Bash();
        $run = static fn (string $command): ToolResult => $bash->execute([
            'command' => $command,
            'description' => 'probe',
        ]);

        // Branch 1 — failed, both streams: stderr is appended AFTER stdout.
        $failed = $run('echo out; echo errmsg >&2; exit 1');
        $this->assertTrue($failed->isError());
        $this->assertSame("out\nerrmsg", $failed->content());

        // Branch 2 — nothing on stdout: stderr IS the answer, and not an error.
        $silent = $run('echo errmsg >&2');
        $this->assertFalse($silent->isError());
        $this->assertSame('errmsg', $silent->content());

        // Branch 3 — succeeded with both: the stderr TEXT is not in the answer.
        $ok = $run('echo out; echo errmsg >&2');
        $this->assertFalse($ok->isError());
        $this->assertStringStartsWith('out', $ok->content());
        $this->assertStringNotContainsString(
            'errmsg',
            $ok->content(),
            'a successful command\'s stderr is NOT merged into the result',
        );

        // And the redirect the description tells the model to use has to work.
        $this->assertSame("out\nerrmsg", $run('{ echo out; echo errmsg >&2; } 2>&1')->content());
    }

    /**
     * The description held to branch 3's observed result rather than to a
     * keyword. `merged` as a substring is true of "are merged" and of "are
     * never merged" alike; a predicate over the bytes the tool just returned
     * is not.
     *
     * The table's keys are the phrasings this clause has actually had or could
     * plausibly be given. Whichever one the description carries has to be true
     * of the run, and it has to carry one of them.
     */
    public function testBashDescribesTheSuccessfulStderrBranchTheWayItBehaves(): void
    {
        $bash = new Bash();
        $observed = $bash->execute([
            'command' => 'echo out; echo errmsg >&2',
            'description' => 'probe',
        ])->content();

        $this->assertDescribesObservedResult(
            'Bash::description()',
            $bash->description(),
            $observed,
            [
                // The shipped defect: true of the FAILED branch, written as a
                // property of the tool.
                'stdout and stderr are merged' => static fn (string $c): bool => str_contains($c, 'errmsg'),
                'stderr is appended after it only when the command exits non-zero'
                    => static fn (string $c): bool => !str_contains($c, 'errmsg'),
                'replaced by a one-line marker'
                    => static fn (string $c): bool => !str_contains($c, 'errmsg')
                        && str_contains($c, 'stderr suppressed'),
            ],
        );
    }

    // -------------------------------------------------------------------------
    // Edit — the match contract, all three rejection branches.
    // -------------------------------------------------------------------------

    /**
     * Each clause asserted here is enforced in {@see Edit::execute()} and was
     * previously discoverable only from the error string it produces:
     * byte-exact matching, the >1-match rejection and its `replace_all`
     * escape, the 0-match rejection, and the file-must-exist requirement that
     * makes `Write` the route to a new file.
     */
    public function testEditDescriptionStatesTheWholeMatchContract(): void
    {
        $description = (new Edit())->description();

        $this->assertStringContainsString('bytes on disk exactly', $description);
        $this->assertStringContainsString('replace_all', $description);
        $this->assertStringContainsString('zero times is rejected', $description);
        $this->assertStringContainsString('left untouched', $description);
        $this->assertStringContainsString('must already exist', $description);
        $this->assertStringContainsString('Write', $description);
    }

    /**
     * Every rejection the description promises leaves the file untouched has
     * to actually leave it untouched, or the description is the defect.
     */
    public function testEditReallyLeavesTheFileUntouchedOnEveryRejectionItDescribes(): void
    {
        $dir = $this->scratchDir();
        $path = $dir . '/target.txt';
        $original = "one\ntwo\none\n";
        file_put_contents($path, $original);

        $edit = new Edit($dir);

        $ambiguous = $edit->execute(['file_path' => $path, 'old_string' => 'one', 'new_string' => 'X', 'description' => 'probe']);
        $this->assertTrue($ambiguous->isError(), 'a 2-match old_string must be rejected');
        $this->assertSame($original, file_get_contents($path));

        $missing = $edit->execute(['file_path' => $path, 'old_string' => 'nowhere', 'new_string' => 'X', 'description' => 'probe']);
        $this->assertTrue($missing->isError(), 'a 0-match old_string must be rejected');
        $this->assertSame($original, file_get_contents($path));

        $absent = $edit->execute(['file_path' => $dir . '/nope.txt', 'old_string' => 'one', 'new_string' => 'X', 'description' => 'probe']);
        $this->assertTrue($absent->isError(), 'Edit must refuse a file that does not exist');
    }

    /**
     * What the MODEL actually receives from a successful edit, held against
     * what the description says it receives.
     *
     * The shipped defect: the description promised "a unified diff of what
     * changed". `Edit::execute()` really does build one — and puts it on
     * {@see ToolResult::$diff}, deliberately OFF `$content`, where only the
     * renderer and the event stream read it. `Runtime::settle()` builds the
     * model's `ToolResultMessage` from `$result->content()` alone, so the diff
     * never reaches the conversation. A model told a diff is coming has no
     * reason to re-Read the file, which is the turn the false claim costs.
     *
     * The claim table is checked against `content()`, not against `diff()`,
     * because `content()` is the whole of what the model gets.
     */
    public function testEditDescribesWhatTheModelActuallyReceivesFromASuccessfulEdit(): void
    {
        $dir = $this->scratchDir();
        $path = $dir . '/target.txt';
        file_put_contents($path, "keep\nold one\nold two\n");

        $edit = new Edit($dir);
        $result = $edit->execute([
            'file_path' => $path,
            'old_string' => "old one\nold two",
            'new_string' => "new one",
            'description' => 'probe',
        ]);

        $this->assertFalse($result->isError());
        $body = (string) file_get_contents($path);

        // The diff exists, and it is NOT in the message the model gets. Both
        // halves matter: the first says the renderer's field is still
        // populated, the second is the claim the description used to make.
        $this->assertTrue($result->hasDiff());
        $this->assertStringContainsString('@@', (string) $result->diff());
        $this->assertStringNotContainsString('@@', $result->content());

        $this->assertDescribesObservedResult(
            'Edit::description()',
            $edit->description(),
            $result->content(),
            [
                'unified diff' => static fn (string $c): bool => str_contains($c, '@@'),
                'full copy of the new file' => static fn (string $c): bool => str_contains($c, $body),
                'counts the lines added and removed' => static fn (string $c): bool
                    => preg_match('/\(\+\d+ -\d+ lines\)/', $c) === 1,
                'does not echo the new file contents back' => static fn (string $c): bool
                    => !str_contains($c, $body),
            ],
        );

        // And the tally is the REAL one, not a constant: two lines out, one in.
        $this->assertStringEndsWith('(+1 -2 lines)', $result->content());
    }

    /**
     * A no-op-shaped edit (new_string identical to old_string) changes nothing,
     * so there is no diff and no tally to report — the summary must not invent
     * a `(+0 -0 lines)` for it.
     */
    public function testEditReportsNoTallyWhenTheWriteChangedNothing(): void
    {
        $dir = $this->scratchDir();
        $path = $dir . '/same.txt';
        file_put_contents($path, "alpha\n");

        $result = (new Edit($dir))->execute([
            'file_path' => $path,
            'old_string' => 'alpha',
            'new_string' => 'alpha',
            'description' => 'probe',
        ]);

        $this->assertFalse($result->isError());
        $this->assertFalse($result->hasDiff());
        $this->assertSame("File updated: $path", $result->content());
    }

    // -------------------------------------------------------------------------
    // Read — the cap comes back SHORT, not as an error.
    // -------------------------------------------------------------------------

    /**
     * A truncated read is the failure mode a terse description guarantees: the
     * model receives the head of a file, is told nothing, and reasons about it
     * as the whole thing. The marker is in the content; the warning that a
     * short result may be partial belongs in the description.
     */
    public function testReadDescriptionWarnsThatAnOversizeFileComesBackTruncatedNotFailed(): void
    {
        $description = (new Read())->description();

        $this->assertStringContainsString('... [truncated]', $description);
        $this->assertStringContainsString('rather than erroring', $description);
        $this->assertStringContainsString('head of a longer file', $description);
    }

    /** Same domain rule as Bash's cap: the figure is $maxBytes, not a literal. */
    public function testReadDescriptionReportsItsOwnByteCapNotTheDefault(): void
    {
        $this->assertStringContainsString('4,096 bytes', (new Read(maxBytes: 4096))->description());
        $this->assertStringContainsString('1,048,576 bytes', (new Read())->description());
        $this->assertStringNotContainsString('1,048,576', (new Read(maxBytes: 4096))->description());
    }

    /**
     * Containment and instruction-file surfacing come from two DIFFERENT
     * injected collaborators, so an instance holding neither must claim
     * neither — the exact defect class of a true statement written next to the
     * wrong subject.
     */
    public function testReadDescriptionClaimsOnlyTheAdvantagesTheInstanceActuallyHas(): void
    {
        $bare = (new Read())->description();
        $this->assertStringNotContainsString('confined to the workspace root', $bare);
        $this->assertStringNotContainsString('CLAUDE.md', $bare);

        $rooted = (new Read('/tmp'))->description();
        $this->assertStringContainsString('confined to the workspace root', $rooted);
        $this->assertStringNotContainsString('CLAUDE.md', $rooted, 'no loader was injected');

        $full = (new Read('/tmp', instructionLoader: new InstructionFileLoader('/tmp')))->description();
        $this->assertStringContainsString('confined to the workspace root', $full);
        $this->assertStringContainsString('CLAUDE.md', $full);
    }

    /** The cap the description names is the cap execute() actually applies. */
    public function testReadReallyTruncatesAtTheCapItsDescriptionNames(): void
    {
        $dir = $this->scratchDir();
        $path = $dir . '/big.txt';
        file_put_contents($path, str_repeat('x', 200));

        $result = (new Read($dir, maxBytes: 64))->execute(['file_path' => $path, 'description' => 'probe']);

        $this->assertFalse($result->isError(), 'an oversize file must not be an error');
        $this->assertStringEndsWith('... [truncated]', $result->content());
        $this->assertStringContainsString(
            '64 bytes',
            (new Read($dir, maxBytes: 64))->description(),
            'the description has to name the same cap execute() enforced',
        );
    }

    // -------------------------------------------------------------------------
    // Grep — dialect, scoping, and no-matches-is-not-an-error.
    // -------------------------------------------------------------------------

    /**
     * The regex dialect is the clause crush_code.md section 12 got wrong (it
     * says ERE). {@see Grep::execute()} passes neither `-E` nor `-P`, so it is
     * BASIC regular expression syntax, and the difference decides whether
     * `a|b` is alternation or three literal characters.
     */
    public function testGrepDescriptionNamesTheRegexDialectAndTheNoMatchRule(): void
    {
        $description = (new Grep())->description();

        $this->assertStringContainsString('GNU basic', $description);
        $this->assertStringContainsString('not PCRE', $description);
        $this->assertStringContainsString('backslash-escaped', $description);
        $this->assertStringContainsString('include', $description);
        $this->assertStringContainsString('Finding nothing is a normal result', $description);
    }

    /**
     * Expanding the description must not have dropped what Phase 8 item 7 put
     * there: the exclusion list and the `include_ignored` escape hatch.
     */
    public function testGrepDescriptionStillCarriesTheIgnoreGuidanceItAlreadyHad(): void
    {
        $description = (new Grep())->description();

        foreach (['.git', 'vendor', 'node_modules', '.phpunit.cache', '.gitignore'] as $token) {
            $this->assertStringContainsString($token, $description, $token);
        }
        $this->assertStringContainsString('include_ignored', $description);
    }

    /**
     * The behavioural half of the dialect claim. `alpha|beta` must match ONLY
     * the line containing those literal characters; under ERE it would also
     * match the `alpha`-only and `beta`-only lines. The escaped form `\|` must
     * match all three, which is BRE's alternation spelling.
     *
     * This is what stops the description from drifting: adding `-E` to
     * `execute()` reds this test, not just a prose review.
     */
    public function testGrepReallyIsBasicRegularExpressionSyntaxAsItsDescriptionClaims(): void
    {
        $dir = $this->scratchDir();
        file_put_contents($dir . '/subject.txt', "alpha\nbeta\nalpha|beta\n");

        $grep = new Grep($dir);
        $hits = static fn (string $pattern): array => array_values(array_filter(
            explode("\n", trim($grep->execute(['pattern' => $pattern, 'path' => $dir, 'description' => 'probe'])->content())),
        ));

        $this->assertCount(1, $hits('alpha|beta'), '`|` must be a literal, i.e. BRE not ERE');
        $this->assertCount(3, $hits('alpha\\|beta'), '`\\|` is BRE alternation');
        $this->assertCount(0, $hits('a+lpha'), '`+` must be a literal under BRE');
        $this->assertCount(0, $hits('alph(a)'), '`(` `)` must be literals under BRE');
    }

    /**
     * EVERY character the description lists as self-matching, probed against
     * the live `grep -rn` — one for one, read out of the description itself.
     *
     * The list used to be trusted. An adversarial round added `*` and `.` to
     * it, which are the two most consequential BRE OPERATORS there are, and
     * nothing went red: the assertion above spot-checks four fixed characters
     * and every other assertion only asked whether the sentence existed. A
     * model handed `*` as a literal writes `foo*bar` expecting to find that
     * string and silently gets zero-or-more-`o` matches instead.
     *
     * So the claimed set is PARSED, and the fixture is built from it: for each
     * claimed character `c`, a line `x<c>y` exists, and the pattern `x<c>y`
     * must match exactly that one line. Under a literal reading it does. Under
     * an operator reading it cannot — `x*y` matches every line with a `y` in
     * it, and `x.y` matches every `x<anything>y` line — so a character added to
     * the list that is really an operator lands as a failure with the count in
     * the message.
     */
    public function testEveryCharacterGrepCallsSelfMatchingReallyMatchesItself(): void
    {
        $grep = new Grep();
        $description = $grep->description();

        // The claim's own sentence, so backtick spans elsewhere in the
        // description (`grep -rn`, `*.php`) cannot be mistaken for members of
        // the list. Single-character spans only, which is what the list is
        // made of.
        $upToClaim = strstr($description, 'match themselves', true);
        $this->assertIsString($upToClaim, 'the description no longer states which characters match themselves');
        preg_match_all('/`(.)`/u', $upToClaim, $spans);
        $claimed = array_values(array_unique($spans[1]));
        $this->assertGreaterThanOrEqual(
            2,
            count($claimed),
            'the self-matching list parsed to fewer than two characters; the parse is probably stale',
        );

        $dir = $this->scratchDir();
        // The three lines the repetition operators need in order to be
        // distinguishable from literals: `x+y`-as-operator matches "xy" and
        // "xxy", `x?y`-as-operator matches "y" and "xy".
        $lines = ['y', 'xy', 'xxy'];
        foreach ($claimed as $char) {
            $lines[] = 'x' . $char . 'y';
        }
        file_put_contents($dir . '/subject.txt', implode("\n", $lines) . "\n");

        foreach ($claimed as $char) {
            $result = $grep->execute([
                'pattern' => 'x' . $char . 'y',
                'path' => $dir,
                'description' => 'probe',
            ]);
            $hits = array_values(array_filter(
                explode("\n", trim($result->content())),
                static fn (string $line): bool => str_contains($line, ':'),
            ));

            $this->assertCount(
                1,
                $hits,
                sprintf(
                    'the description says `%s` matches itself, but the pattern "x%sy" matched %d '
                    . 'line(s) of %s — it is being read as an operator, not a literal. Hits: %s',
                    $char,
                    $char,
                    count($hits),
                    var_export($lines, true),
                    implode(' | ', $hits),
                ),
            );
            $this->assertStringEndsWith(
                'x' . $char . 'y',
                $hits[0],
                sprintf('`%s` matched one line, but not the one containing it literally', $char),
            );
        }
    }

    /**
     * The other half of the same sentence: the ESCAPED spellings really do
     * reach the operators. This is why the dialect is named "GNU basic" and not
     * "POSIX basic" — `\|`, `\+` and `\?` are GNU extensions, and strict POSIX
     * BRE has no alternation operator at all, so "POSIX basic" plus an
     * instruction to escape your way to alternation describes a dialect that
     * does not exist.
     */
    public function testGrepsEscapedOperatorsAreTheGnuExtensionsTheDescriptionRecommends(): void
    {
        $dir = $this->scratchDir();
        // Disjoint fixtures per operator, so one probe's expected count cannot
        // be perturbed by a line another probe needed.
        file_put_contents($dir . '/subject.txt', "alpha\nbeta\npq\nppq\nut\nust\n");

        $grep = new Grep($dir);
        $count = static fn (string $pattern): int => count(array_filter(
            explode("\n", trim($grep->execute(['pattern' => $pattern, 'path' => $dir, 'description' => 'probe'])->content())),
            static fn (string $line): bool => str_contains($line, ':'),
        ));

        $this->assertSame(2, $count('alpha\\|beta'), '\\| must be alternation');
        $this->assertSame(2, $count('p\\+q'), '\\+ must be one-or-more');
        $this->assertSame(2, $count('us\\?t'), '\\? must be zero-or-one');
        // The control: unescaped, the same three are literals and match nothing.
        $this->assertSame(0, $count('alpha|beta'));
        $this->assertSame(0, $count('p+q'));
        $this->assertSame(0, $count('us?t'));
    }

    /** And the scoring rule: exit 1 (no matches) is a result, not a failure. */
    public function testGrepReallyReportsNoMatchesAsANonError(): void
    {
        $dir = $this->scratchDir();
        file_put_contents($dir . '/subject.txt', "alpha\n");

        $result = (new Grep($dir))->execute([
            'pattern' => 'nothing-here-at-all',
            'path' => $dir,
            'description' => 'probe',
        ]);

        $this->assertFalse($result->isError());
        $this->assertSame('', trim($result->content()));
    }

    /**
     * The schema's `pattern` text said only "regex", which a model reasonably
     * reads as PCRE. Two strings describing one argument must not disagree
     * about its syntax.
     */
    public function testGrepSchemaAgreesWithDescriptionAboutTheDialect(): void
    {
        $schema = (new Grep())->inputSchema();

        $this->assertStringContainsString('GNU basic regular expression', $schema['properties']['pattern']['description']);
        $this->assertStringContainsString('glob', $schema['properties']['include']['description']);
    }

    // -------------------------------------------------------------------------
    // Glob — the added guidance reaches BOTH branches of a computed string.
    // -------------------------------------------------------------------------

    /**
     * The description is computed from {@see Glob::prunedDirNames()}, and the
     * when-to-reach-for-this guidance is not conditional on pruning — so it has
     * to appear whether or not there is a prune list to warn about.
     */
    public function testGlobDescriptionCarriesTheSharedGuidanceInBothBranches(): void
    {
        foreach (['pruning on' => new Glob(), 'pruning off' => new Glob(prunedDirs: [])] as $label => $glob) {
            $description = $glob->description();

            $this->assertStringContainsString('`**/*.php`', str_replace('"', '`', $description), $label);
            $this->assertStringContainsString('find', $description, $label);
            $this->assertStringContainsString('`**` matches across directory levels', $description, $label);
            $this->assertStringContainsString('one path per line', $description, $label);
        }
    }

    /** The prune list itself is still conditional, and still named when present. */
    public function testGlobDescriptionStillNamesThePrunedDirectoriesAndTheOptOut(): void
    {
        $description = (new Glob())->description();

        foreach (['.git', 'vendor', 'node_modules', '.phpunit.cache'] as $dir) {
            $this->assertStringContainsString($dir, $description, $dir);
        }
        $this->assertStringContainsString('vendor/**/*.php', $description);
    }

    /**
     * Same domain rule as Read's two conditional clauses: the interleaved
     * instruction-file content only happens when a loader is injected, so only
     * an instance holding one may say the result is not purely a path list.
     */
    public function testGlobDescriptionClaimsInstructionSurfacingOnlyWithALoader(): void
    {
        $this->assertStringNotContainsString('CLAUDE.md', (new Glob())->description());
        $this->assertStringContainsString(
            'CLAUDE.md',
            (new Glob('/tmp', instructionLoader: new InstructionFileLoader('/tmp')))->description(),
        );
    }
}

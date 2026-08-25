<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * A HELPER COPIED FROM ONE TEST CLASS INTO ANOTHER AND THEN FIXED IN ONLY ONE
 * OF THEM IS INVISIBLE TO EVERY OTHER GUARD IN THIS SUITE.
 *
 * WHY THIS FILE EXISTS. `Support/ForkedChildTest::isRaw()` and
 * `Backend/EngineBackendTest::isRaw()` are the same helper: run `stty -a`
 * against a pty and substring-match `-icanon`/`-echo`. The device flag is `-F`
 * on GNU coreutils and `-f` on BSD, and the wrong one fails with an EMPTY
 * stdout - indistinguishable from "the terminal is cooked" - so the message on
 * fd 2 is the only thing telling those two apart, and it was going to
 * `/dev/null`. One copy was fixed. The other was in no lane's file list and
 * kept the bug for a full round, one directory away from its fixed twin.
 * Nothing in the tree could have said so: both suites were green, both helpers
 * were private, and no census looks at two files at once.
 *
 * THE LANE SPLIT MAKES THIS LIKELIER, NOT LESS LIKELY. Ownership is by
 * directory; a copied helper is not. A fix lands in whichever copy the round
 * happened to own, and the other copy's divergence is then indistinguishable
 * from a deliberate local difference - which is exactly what most of
 * {@see ACCEPTED_DIVERGENCE} really is.
 *
 * WHAT COUNTS AS DRIFT HERE, stated as a measurement and not as a feeling. Two
 * declarations of the same private method name, in DIFFERENT FILES, whose
 * normalised token bodies are not identical, and whose bodies agree except for
 * at most ONE token on each side after the common prefix and the common suffix
 * are trimmed. One token apart is the shape of the defect above: the whole
 * helper agrees and a single flag, literal or name does not.
 *
 * WHAT IT DELIBERATELY CANNOT SEE, because an alphabet is coverage and this
 * one was chosen rather than found:
 *
 *   * TWO TOKENS APART OR MORE. Every name inside the bound is a row of
 *     {@see ACCEPTED_DIVERGENCE} below, so that population is countable from
 *     this file and is not written into prose a merge invalidates. WHAT THIS
 *     SAID about the next token out: "sampled on PHP 8.3.6 those are mostly
 *     `self::` against `$this->`, which is noise, but nothing here proves they
 *     all are". WHAT IS TRUE NOW: it is no longer a sample and no longer a
 *     mostly. {@see DRIFT_BOUND} is a parameter of {@see driftReport()}, and
 *     {@see testRelaxingTheBoundToTwoTokensBringsInOnlyAReceiverSpelling()}
 *     runs the wider bound over the whole suite every time and checks each
 *     newcomer against a PREDICATE — is this core nothing but how the two files
 *     spell their receiver — rather than against a list of names a merge would
 *     invalidate. The first run of that measurement found one newcomer that was
 *     not: two copies of `significantTokens()`, one dropping `T_WHITESPACE` and
 *     one not, feeding a walk that reads token neighbours by index. WHY THE
 *     BOUND STILL EARNS ITS PLACE AT ONE: everything two brings in today is a
 *     receiver spelling, and the day it is not, that test names the pair and
 *     says to widen the bound rather than to exempt the name.
 *   * SAME NAME, TWO CLASSES, ONE FILE. Comparison is cross-FILE, so two
 *     classes in one file are never compared with each other. Measured on PHP
 *     8.3.6 at the commit that added this file, no file in `tests/` declared
 *     one private name twice; the generator is the same
 *     {@see declarationsIn()} this guard runs, so a reader re-derives
 *     it rather than trusting the sentence.
 *   * PROTECTED HELPERS. WHAT THIS SAID: "a protected helper is usually
 *     inherited rather than copied, which is a different failure; public ones
 *     are the subject of tests rather than their machinery". WHAT IS TRUE NOW:
 *     the protected half holds and was measured rather than felt, through the
 *     same {@see driftReport()} with the alphabet widened - `protected` adds NO
 *     helper name at all, every pair it brings in belonging to a PHPUnit
 *     lifecycle hook, which the framework declares protected and which no two
 *     classes come to share by copying. THE PUBLIC HALF OF THAT SENTENCE WAS
 *     WRONG, and E481 is what it cost: "the subject of tests rather than their
 *     machinery" describes a test METHOD exactly, and a test method copied
 *     between two suites and fixed in only one of them is this file's own
 *     subject with no reader at all to notice. It is now scanned, by
 *     {@see testNoCopiedTestMethodHasDriftedUnrecorded()}. WHY THE RESTRICTION
 *     ON `protected` STILL EARNS ITS PLACE: it is defended not by prose but by
 *     {@see testWideningTheVisibilityAlphabetToProtectedAddsNoHelperAtAll()},
 *     which reds on the day a protected helper really does drift and says so -
 *     the restriction deletes itself rather than being argued again.
 *   * PUBLIC METHODS THAT ARE NOT TESTS. Still out of reach, and this one is a
 *     limit of the SCANNER rather than a judgement about the population: the
 *     report keeps one body per file per name, and two anonymous test doubles
 *     in one file routinely declare the same interface method. Measured on PHP
 *     8.3.6 over `tests/`, the unrestricted `public` alphabet reports 520
 *     declarations it cannot place for that reason alone. The generator is
 *     {@see testNoCopiedTestMethodHasDriftedUnrecorded()}'s own doc-block,
 *     which states what the scanner would have to grow first.
 *   * A COPY THAT WAS RENAMED. Nothing here matches bodies across different
 *     names, so a helper copied and renamed is out of reach by construction.
 *   * THE SIGNATURE, IN THE BODY REPORT. WHAT THIS SAID: "{@see bodyOf()}
 *     starts at the body's opening brace, so a divergence in the PARAMETER LIST
 *     is invisible... including the signature would fold every promoted-property
 *     and named-argument spelling difference into the core and push most real
 *     pairs past {@see DRIFT_BOUND}, which is a different guard, not a wider
 *     one". WHAT IS TRUE NOW: the objection was right and the conclusion was
 *     too broad. Folding the signature into the CORE really is the wrong
 *     widening — it costs the body report pairs it catches today — but there
 *     was a third option nobody had measured: gate on body IDENTITY and compare
 *     the signatures then. Two byte-identical bodies are the same helper by
 *     this file's own definition, so no bound is needed and no spelling
 *     difference can crowd anything out.
 *     {@see testNoCopiedHelperHasDriftedInItsSignatureAlone()} does that, with
 *     {@see ACCEPTED_SIGNATURE_DIVERGENCE} carrying the rows. WHY THE
 *     RESTRICTION ON THE CORE STILL EARNS ITS PLACE: it is unchanged, for the
 *     reason it always gave; what has gone is the assumption that the core was
 *     the only place the signature could be looked at. The RETURN TYPE is still
 *     out of both — see {@see signatureOf()} for why.
 */
final class DuplicatedTestHelperDriftTest extends TestCase
{
    use TestFileWalkTrait;

    /**
     * The largest per-side divergence, in tokens, that still reads as "the
     * same helper, one edit apart" rather than as two unrelated helpers that
     * happen to share a name.
     */
    private const DRIFT_BOUND = 1;

    /**
     * Same-named private helpers that ARE one token apart and are meant to be,
     * each with the reason.
     *
     * NOT AN EXEMPTION LIST AND NOT A COMMENT, for the same reason as
     * {@see ChildStderrCaptureTest::OUT_OF_SCOPE}: every row is checked against
     * the tree in both directions by the two tests below. A row whose pair has
     * been consolidated fails and must be deleted; a drifted pair with no row
     * fails and must be argued or fixed. A deferral is a claim about the tree,
     * so the tree is asked.
     *
     * THE ROWS ARE KEYED BY HELPER NAME AND NOT BY FILE PAIR. A name with three
     * copies has three pairs, and keying by pair would make a row go stale the
     * moment one of the three moved directory - which is a rename, not a drift.
     *
     * MOST OF THESE ARE NOT BUGS, AND THAT IS THE POINT. The value of the map
     * is not its current contents; it is that the NEXT name - the next helper
     * somebody fixes in one copy and not the other - arrives as a red test
     * naming both files, instead of as a green suite. (An ordinal stood here
     * and was retired: it counted the rows below, so every row a later round
     * argues would have made the sentence wrong, and the count was never the
     * claim.)
     *
     * @var array<string,string>
     */
    private const ACCEPTED_DIVERGENCE = [
        'askAboutHook' =>
            'A leading `\\` on a call to a global function against no leading `\\`. Purely how '
            . 'each file spells the same call; nothing about behaviour differs.',
        'awaitPromise' =>
            'THE ONE ROW HERE THAT IS A REAL SEMANTIC DIFFERENCE: the bound the helper waits '
            . 'to, 10.0 seconds against 15.0. Both are deliberate - a streaming wiring test '
            . 'legitimately needs longer than an engine unit test - but they are also exactly '
            . 'the shape a drifted copy takes, which is why the row says so rather than '
            . 'calling it style. If a third copy appears, give it the bound its own suite '
            . 'needs and say why here.',
        'body' =>
            'The parameter is named `$app` in one copy and `$chat` in the other, matching the '
            . 'model each file drives. A local name, not behaviour.',
        'createAskHook' =>
            'A fully-qualified interface name against the imported short name. Same class, two '
            . 'spellings, decided by whether the file already imports it.',
        'isRaw' =>
            'THE HELPER THIS WHOLE FILE EXISTS BECAUSE OF, and what is left of the divergence '
            . 'is now deliberate: the two copies differ only in the PREFIX of the temp file '
            . 'each uses for the stderr it captures. Those prefixes must NOT be shared - a '
            . 'process-unique temp name per call site is what keeps two suites running at once '
            . 'from reading each other\'s file. The `-F`/`-f` defect that made the copies '
            . 'differ in BEHAVIOUR was fixed in both, one round apart, which is the event this '
            . 'guard exists to make visible next time.',
        'readOrFail' =>
            'The text of the failure message differs; the read and the refusal are identical. '
            . 'Each message names what its own census is void without, which is worth more '
            . 'than one shared sentence.',
        'readme' =>
            'One copy calls a helper named `document()`, the other one named `repoFile()`. Two '
            . 'different accessors in two different classes, reached by helpers that happen to '
            . 'share a name.',
        'resetTaskListConnectionCache' =>
            'A fully-qualified class name against the imported short name, as with '
            . '`createAskHook` above.',
        'reviewerAgent' =>
            'One copy takes the active flag as a parameter and the other hard-codes `true`, '
            . 'because only one of the two files needs both states. A narrower copy, not a '
            . 'drifted one.',
        'rewriteHook' =>
            'A leading `\\` on a call to a global function, as with `askAboutHook` above.',
        'runBounded' =>
            'The timeout message names what each caller was waiting for - a worker stop in one '
            . 'file, a child reap in the other. The bound and the wait are identical.',
        'writeScript' =>
            'Different temp-name prefixes, and they must stay different for the same reason as '
            . '`isRaw` above.',
    ];

    /**
     * Same-named private helpers whose BODIES are byte-identical and whose
     * PARAMETER LISTS are not, each with the reason.
     *
     * A SEPARATE MAP FROM {@see ACCEPTED_DIVERGENCE} BECAUSE IT ANSWERS A
     * DIFFERENT QUESTION, and merging them would make both rows lie. That map
     * says "these two bodies differ by one token and that is deliberate"; this
     * one says "these two bodies do not differ at all and their signatures do".
     * A name can legitimately be in both — with three copies, one pair can
     * disagree on a body and another on a signature.
     *
     * @var array<string,string>
     */
    private const ACCEPTED_SIGNATURE_DIVERGENCE = [
        'awaitPromise' =>
            'A fully-qualified parameter type against the imported short name. Same interface, '
            . 'two spellings, decided by whether the file already imports it — the signature '
            . 'twin of the `createAskHook` row above.',
        'block' =>
            'THE ROW THAT IS A REAL SEMANTIC DIFFERENCE AND THE REASON THIS MAP EXISTS: one '
            . 'copy gives the second parameter a default and the other requires it. The bodies '
            . 'are byte-identical, so every body-based check in this file compares them as one '
            . 'helper and reports nothing — which is exactly the hole E285 described. It is '
            . 'deliberate, and checked rather than assumed: PermissionsCommandTest passes no '
            . 'second argument at ANY of its call sites, so the default is the only value that '
            . 'copy ever uses, while PermissionGateReadOnlyInspectionTest passes one at every '
            . 'call site and at two of them passes a value that varies per iteration — a '
            . 'default there would be dead. If a third copy appears, give it the parameter its '
            . 'own suite needs and say so here.',
    ];

    /**
     * NO COPIED HELPER HAS DRIFTED IN ITS SIGNATURE ALONE without a row saying
     * so.
     *
     * WHY THIS IS NOT A WIDER {@see DRIFT_BOUND}, and the prescription it was
     * measured against said it should be. Folding the parameter list into the
     * divergence core was the obvious widening and it is the wrong one: every
     * promoted-property, default-value and type-spelling difference joins the
     * core, most real pairs go past the bound, and the body report LOSES pairs
     * it reports today. Measured on PHP 8.3.6 before writing this: gating on
     * body IDENTITY instead needs no bound at all, because two byte-identical
     * bodies are the same helper by this file's own definition, and it reports
     * two pairs — one a type spelling, one a defaulted parameter that no
     * body-based check in this file could ever see.
     */
    public function testNoCopiedHelperHasDriftedInItsSignatureAlone(): void
    {
        $this->assertTheSignatureReportIsAlive();

        $sources = [];
        foreach (self::everyTestFile() as $relative => $path) {
            $sources[$relative] = (string) file_get_contents($path);
        }

        [, , , , $signatureDrift] = self::driftReport($sources);

        $unrecorded = [];
        foreach ($signatureDrift as $name => $pairs) {
            if (isset(self::ACCEPTED_SIGNATURE_DIVERGENCE[$name])) {
                continue;
            }
            $unrecorded[] = $name . ': ' . implode('; ', $pairs);
        }

        self::assertSame(
            [],
            $unrecorded,
            'two files declare a private helper of the same name, their bodies are byte-'
                . 'identical, and their PARAMETER LISTS are not. Every other check in this file '
                . 'starts at the body\'s opening brace, so it compares these two as one helper '
                . 'and says nothing: an added parameter, a changed default or a widened type is '
                . 'invisible to all of them. Make the two copies agree — or extract the helper — '
                . 'or add the name to ACCEPTED_SIGNATURE_DIVERGENCE with the reason the '
                . 'difference is deliberate. The row is checked back against the tree, so it '
                . 'cannot become a rubber stamp.',
        );

        $overtaken = [];
        foreach (self::ACCEPTED_SIGNATURE_DIVERGENCE as $name => $reason) {
            self::assertNotSame('', trim($reason), $name . ' is accepted without a reason');

            if (!isset($signatureDrift[$name])) {
                $overtaken[] = $name;
            }
        }

        self::assertSame(
            [],
            $overtaken,
            'this name is recorded as a helper whose copies have identical bodies and different '
                . 'signatures, and they no longer do. Delete the row. AND IF YOU DID NOT TOUCH '
                . 'EITHER FILE THIS IS STILL NOT A BUG, for the reason the body map\'s own '
                . 'staleness check gives at length: the two files a row names routinely sit in '
                . 'two different lanes, and the fix here is a DATA edit rather than a change to '
                . 'either helper.',
        );
    }

    /**
     * KNOWN-ANSWER CONTROL FOR THE SIGNATURE REPORT, in the same test that uses
     * it to assert an absence.
     *
     * The positive is load-bearing (rule 15): a signature walk that returned
     * nothing, or one that never compared, satisfies "nothing drifted"
     * perfectly. The two negatives stop the predicate being stuck at yes — the
     * second especially, because a report that fired on every shared name would
     * be answered with exemptions.
     */
    private function assertTheSignatureReportIsAlive(): void
    {
        $original = <<<'PHP'
            <?php
            final class A
            {
                private function probe(string $device): bool
                {
                    return $device !== '';
                }
            }
            PHP;

        $defaulted = str_replace('string $device)', "string \$device = 'x')", $original);
        [, , , , $report] = self::driftReport(['a/A.php' => $original, 'b/B.php' => $defaulted]);
        self::assertArrayHasKey(
            'probe',
            $report,
            'two copies of one helper with identical bodies and a parameter default on only one '
                . 'were not reported. Until this passes, the absence asserted above is a '
                . 'statement about a walk that is not running.',
        );

        [, , , , $report] = self::driftReport(['a/A.php' => $original, 'b/B.php' => $original]);
        self::assertSame([], $report, 'two byte-identical copies were reported as signature drift');

        // RULE 14 FOR THE SIGNATURE HALF, and it needs its own input: the
        // truncated-body fixture in assertTheScannerIsAlive() trips the BODY
        // arm before the signature arm is ever reached, so dropping the
        // signature arm survives it. A declaration with no parameter list at
        // all is the shape that separates them — bodyOf() finds the brace and
        // returns a body, signatureOf() meets that same brace before any `(`
        // and cannot answer. Without this, a helper whose signature this walk
        // cannot read would be compared as though its parameter list were
        // empty, which is a hole shaped exactly like the next signature drift.
        $noParameterList = <<<'PHP'
            <?php
            final class G
            {
                private function probe { return 1; }
            }
            PHP;
        [, $unparseable] = self::driftReport(['g/G.php' => $noParameterList]);
        self::assertNotSame(
            [],
            $unparseable,
            'a declaration whose parameter list this scanner cannot find was accepted rather '
                . 'than reported, so its signature reaches the comparison as the empty list and '
                . 'any real divergence against it is silently cleared',
        );

        // A pair whose BODIES already differ belongs to the body report, not
        // this one, and reporting it in both would double every real drift.
        $bodyDrift = str_replace("!== ''", "=== ''", $original);
        [, , , , $report] = self::driftReport(['a/A.php' => $original, 'b/B.php' => $bodyDrift]);
        self::assertSame(
            [],
            $report,
            'a pair whose bodies differ was reported as signature drift, so every body drift '
                . 'will now be reported twice and the two maps will each be asked to carry it',
        );
    }

    /**
     * No same-named private helper has drifted without a row saying so.
     *
     * @return void
     */
    public function testNoCopiedTestHelperHasDriftedUnrecorded(): void
    {
        $this->assertTheScannerIsAlive();

        $sources = [];
        foreach (self::everyTestFile() as $relative => $path) {
            $sources[$relative] = (string) file_get_contents($path);
        }

        $this->assertNotSame([], $sources, 'no test file was read at all, so nothing was compared');

        [$drifted, $unparseable, $declarations, $cores] = self::driftReport($sources);
        [$drifted, , $unreadable] = self::partitionBySubject($drifted, $cores);

        $this->assertSame(
            [],
            $unreadable,
            'a reported pair could not be classified at all - its description could not be '
                . 'split back into two files, or it arrived without a divergence core. Such a '
                . 'pair is neither cleared nor reported, which is the one outcome this guard '
                . 'must never produce.',
        );

        // Rule 15: an assertion of `[]` below is worth nothing unless something
        // in the same test fails when the instrument stops producing. The
        // classifier is the newest moving part here and it SUBTRACTS, so a
        // classifier stuck at "everything is a subject spelling" would empty
        // the report and pass. This is the count that notices.
        $this->assertGreaterThan(
            0,
            \array_sum(\array_map('count', $drifted)),
            'not one private helper pair survived the subject-spelling classifier. Either every '
                . 'copied helper in the tree was consolidated at once, or the classifier is '
                . 'answering yes to everything - and an emptied report satisfies the '
                . '"nothing is unrecorded" assertion below perfectly.',
        );

        // RULE: A GUARD MUST GO RED ON WHAT IT CANNOT PARSE. A private
        // declaration whose name or whose closing brace this scanner cannot
        // find is not "clean" - it is a hole shaped exactly like the next
        // copied helper.
        $this->assertSame(
            [],
            $unparseable,
            'this scanner could not read a private method declaration, or could not place it - '
                . 'a body whose closing brace it cannot find, a declaration whose name it '
                . 'cannot read, or a second declaration of one name in one file, which the '
                . 'name => file => body report has nowhere to put. Either way it cannot say '
                . 'whether that helper has a twin anywhere. It is reported rather than skipped: '
                . 'a declaration silently dropped is a helper this guard has stopped covering, '
                . 'which is indistinguishable from one it has cleared.',
        );

        // The scan has to have found helpers at all, or "nothing drifted" is a
        // statement about a dead walk rather than about the tree.
        $this->assertGreaterThan(
            0,
            $declarations,
            'no private test helper was found anywhere under tests/ - the walk is dead',
        );

        $unrecorded = [];
        foreach ($drifted as $name => $pairs) {
            if (isset(self::ACCEPTED_DIVERGENCE[$name])) {
                continue;
            }
            $unrecorded[] = $name . ': ' . implode('; ', $pairs);
        }

        $this->assertSame(
            [],
            $unrecorded,
            'two files declare a private helper of the same name whose bodies agree except for '
                . 'one token. That is what a copied helper fixed in only one place looks like: '
                . 'the whole method matches and a single flag, literal or name does not, and '
                . 'both suites stay green because a private helper has no other reader. Either '
                . 'make the two copies agree - or extract the helper - or add the name to '
                . 'ACCEPTED_DIVERGENCE with the reason the difference is deliberate. The row '
                . 'is checked back against the tree, so it cannot become a rubber stamp.',
        );
    }

    /**
     * A row cannot outlive the divergence it was written for.
     *
     * Without this, {@see ACCEPTED_DIVERGENCE} decays into a list of helpers
     * somebody once looked at, and the check above keeps passing because a
     * stale row still matches the name. A row whose pair has been consolidated
     * means the copies are one helper now, and this is the one moment anybody
     * is likely to notice.
     */
    public function testEveryAcceptedDivergenceStillDescribesADriftedPair(): void
    {
        $this->assertTheScannerIsAlive();

        $sources = [];
        foreach (self::everyTestFile() as $relative => $path) {
            $sources[$relative] = (string) file_get_contents($path);
        }

        // BOTH WALKS, because a row may be written for either population.
        // WHAT THIS DID BEFORE: it read the `private` walk alone. WHAT IS TRUE
        // NOW: the public `test*` guard's own failure text offers a row in
        // ACCEPTED_DIVERGENCE as one of its two remedies, and with only the
        // private walk read here that advice could not be followed -- a row for
        // a public test method matched nothing, landed in $overtaken, and this
        // test then demanded its deletion. Two guards prescribing opposite
        // actions for one edit is worse than either rule on its own. WHY THIS
        // STILL EARNS ITS PLACE: a row must still die when its divergence does;
        // widening the population it is checked against is what makes that
        // true for every row rather than for the private ones only.
        [$drifted, , , $cores] = self::driftReport($sources);
        [$drifted, $subjectSpellings] = self::partitionBySubject($drifted, $cores);

        [$publicDrifted, , , $publicCores] = self::driftReport(
            $sources,
            [\T_PUBLIC],
            null,
            static fn (string $name): bool => \str_starts_with($name, 'test'),
        );
        [$publicDrifted, $publicSubjects] = self::partitionBySubject($publicDrifted, $publicCores);

        // Union. `+` keeps the left-hand entry on a name present in both, which
        // is all this needs: each map is only ever asked whether a name is IN
        // it.
        $drifted += $publicDrifted;
        $subjectSpellings += $publicSubjects;

        $overtaken = [];
        $classified = [];
        foreach (self::ACCEPTED_DIVERGENCE as $name => $reason) {
            $this->assertNotSame('', trim($reason), $name . ' is accepted without a reason');

            if (isset($drifted[$name])) {
                continue;
            }
            // A row whose every pair {@see isSubjectSpelling()} now explains is
            // not overtaken by a consolidation - it is overtaken by the
            // classifier, and it must go for a different reason and with
            // different advice. Kept apart so the message can say which.
            if (isset($subjectSpellings[$name])) {
                $classified[] = $name;

                continue;
            }
            $overtaken[] = $name;
        }

        $this->assertSame(
            [],
            $classified,
            'this name is recorded in ACCEPTED_DIVERGENCE, and every pair it names is now '
                . 'explained by isSubjectSpelling() instead - each copy differs only in naming '
                . 'the class its own file is about. The row is a licence keyed by name and the '
                . 'classifier is a property keyed by shape, so keeping both means the next '
                . 'helper of this shape still needs a row argued for it. DELETE THE ROW, and '
                . 'move anything its reason says that the classifier does not into the '
                . 'classifier\'s doc-block rather than dropping it.',
        );

        $this->assertSame(
            [],
            $overtaken,
            'this name is recorded in ACCEPTED_DIVERGENCE as a helper whose copies differ by '
                . 'one token, and they no longer do - the copies were consolidated, one was '
                . 'deleted, or the divergence grew past the bound. Delete the row. An accepted '
                . 'divergence that has been overtaken is how a helper silently stops being '
                . 'compared. '
                . 'AND IF YOU DID NOT TOUCH EITHER FILE, THIS IS STILL NOT A BUG. Ownership in '
                . 'this repo is by directory and a copied helper is not, so the two files a row '
                . 'names routinely sit in two different lanes: whichever lane edits one of them '
                . 'lands the change, and the row reds for whoever merges. Check `git log -p` for '
                . 'the helper in both files before assuming a defect - the fix is a DATA edit '
                . 'here (delete the row, or rewrite its reason for the divergence that is left), '
                . 'not a change to either helper. That the red arrives at all is the whole point '
                . 'of the map.',
        );
    }

    /**
     * NO COPIED TEST METHOD HAS DRIFTED UNRECORDED — the `public` half of this
     * file's subject, which until now it could not see at all.
     *
     * E481: THE ALPHABET WAS THE COVERAGE. Every check above runs the
     * `private` alphabet, and a test METHOD is public. A `testTryFromInvalid-
     * ReturnsNull()` copied from one enum's suite into another's and then
     * fixed in only one of them is the same defect as a copied private helper
     * and was invisible to every guard in this tree — more invisible, in fact,
     * because a private helper at least has one reader and a duplicated test
     * method has none.
     *
     * WHY `test*` AND NOT THE WHOLE `public` ALPHABET, measured rather than
     * argued — and measured by a TEST rather than by this sentence, in
     * {@see testTheWidePublicAlphabetIsUnreadableAndTheTestStarOneIsNot()}. The
     * whole alphabet cannot place a large number of the declarations it reads,
     * every one of them a name declared twice in one file by two anonymous test
     * doubles — so the wide run is a pile of reds about this scanner's
     * bookkeeping and nothing about drift, while the `test*` restriction places
     * everything it reads. Both halves of that are asserted there, and no count
     * is repeated here: the declaration total moves with every public method
     * added anywhere under `tests/`. It also brings in names that are not
     * helpers at all
     * ({@see complete}, {@see execute}, `name`, `description` and their
     * neighbours): interface methods that two test doubles both implement,
     * which is the same thing `setUp()` is in
     * {@see testWideningTheVisibilityAlphabetToProtectedAddsNoHelperAtAll()}
     * — a contract obligation, not a copy. Restricted to `test*` the scanner
     * reports ZERO unplaceable declarations, which is why that is the
     * population this guard runs and the wider one is recorded as a finding
     * rather than shipped.
     *
     * THE DAY THIS FILE SHOULD WIDEN FURTHER is the day the report keeps one
     * body per file per name AND anonymous-class declarations are attributed
     * to their enclosing anon class. Until then the wide alphabet cannot be
     * asked, and this test says so by measuring it rather than by asserting
     * it.
     */
    public function testNoCopiedTestMethodHasDriftedUnrecorded(): void
    {
        $this->assertTheScannerIsAlive();
        $this->assertTheSubjectClassifierIsAlive();

        $sources = [];
        foreach (self::everyTestFile() as $relative => $path) {
            $sources[$relative] = (string) file_get_contents($path);
        }

        $isTestMethod = static fn (string $name): bool => \str_starts_with($name, 'test');

        [$drifted, $unparseable, $declarations, $cores] = self::driftReport(
            $sources,
            [\T_PUBLIC],
            null,
            $isTestMethod,
        );

        $this->assertSame(
            [],
            $unparseable,
            'this scanner could not read or place a public method declaration. NOTE THE '
                . 'POPULATION: the name filter is applied AFTER readability, deliberately - a '
                . 'declaration this scanner could not read has no name to filter ON, and '
                . 'dropping it because it MIGHT have been out of scope is how a real hole gets '
                . 'called out of scope. So a public method that is NOT a test can be reported '
                . 'here, and the offender named below may well not begin with "test". It is '
                . 'reported rather than skipped for the same reason as in the private walk: a '
                . 'declaration silently dropped is a method this guard has stopped comparing, '
                . 'which is indistinguishable from one it has cleared.',
        );

        $this->assertGreaterThan(
            0,
            $declarations,
            'no public test method was found anywhere under tests/ - the walk is dead, and '
                . '"nothing drifted" is then a statement about the walk and not about the tree',
        );

        [$real, $subject, $unreadable] = self::partitionBySubject($drifted, $cores);

        $this->assertSame(
            [],
            $unreadable,
            'a reported pair could not be classified at all - neither cleared nor reported',
        );

        // RULE 15, AND THE REASON THIS ARM IS NOT DECORATION. The assertion
        // below is an absence, and the classifier SUBTRACTS from what it is an
        // absence over: a classifier stuck at yes empties the report and the
        // absence passes. Measured at the commit that added this test, the
        // tree carries pairs of exactly the shape the classifier exists for,
        // so if it stops finding any of them something has broken.
        $this->assertGreaterThan(
            0,
            \array_sum(\array_map('count', $subject)),
            'not one copied test method differing only in the class its own file tests was '
                . 'found. Those pairs are what this walk is mostly made of, so an empty '
                . 'classification means the walk or the classifier has stopped working - and '
                . 'either way the assertion below is measuring nothing.',
        );

        $unrecorded = [];
        foreach ($real as $name => $pairs) {
            if (isset(self::ACCEPTED_DIVERGENCE[$name])) {
                continue;
            }
            $unrecorded[] = $name . ': ' . implode('; ', $pairs);
        }

        $this->assertSame(
            [],
            $unrecorded,
            'two files declare a public test method of the same name whose bodies agree except '
                . 'for one token, and that token is NOT each file\'s own subject. That is a '
                . 'test copied from one suite into another and then fixed in only one of them: '
                . 'both files stay green, because a test method has no reader at all to notice '
                . 'the two have diverged. Either make the copies agree - or extract the shared '
                . 'assertion - or add the name to ACCEPTED_DIVERGENCE with the reason. Do not '
                . 'reach for a row before checking whether the differing token names the class '
                . 'under test; if it does, isSubjectSpelling() should already have cleared it '
                . 'and the classifier is what needs the fix.',
        );
    }

    /**
     * THE `test*` RESTRICTION IS A MEASUREMENT, NOT A SENTENCE.
     *
     * WHAT THIS SAID BEFORE, and it said it in prose only: that the
     * unrestricted `public` alphabet "reads 8,556 declarations and reports 520
     * it cannot place". WHAT IS TRUE NOW: nothing re-derived either number, and
     * the declaration count is a moving target — every commit adding a public
     * method anywhere under `tests/` changes it, and it had already moved twice
     * within the round that wrote it down. A justification nobody can check is
     * a justification nobody will check. WHY THIS STILL EARNS ITS PLACE: the
     * claim is load-bearing, since it is the entire reason this guard runs
     * `test*` instead of `public`, so it is asserted against the tree here and
     * the counts appear only in failure text, where they are generated at the
     * moment they are read.
     *
     * The SHAPE of the unplaceable declarations is asserted too, not merely
     * their number. "The wide run is noisy" would be satisfied by noise of any
     * kind; the specific claim is that every one of them is a name declared
     * twice in one file by two anonymous test doubles, which is a statement
     * about this scanner's bookkeeping rather than about drift — and that is
     * what makes widening a task for the scanner rather than a judgement call.
     */
    public function testTheWidePublicAlphabetIsUnreadableAndTheTestStarOneIsNot(): void
    {
        $sources = [];
        foreach (self::everyTestFile() as $relative => $path) {
            $sources[$relative] = (string) file_get_contents($path);
        }

        [, $wideUnparseable, $wideDeclarations] = self::driftReport($sources, [\T_PUBLIC]);
        [, $narrowUnparseable, $narrowDeclarations] = self::driftReport(
            $sources,
            [\T_PUBLIC],
            null,
            static fn (string $name): bool => \str_starts_with($name, 'test'),
        );

        // Liveness first: every assertion below is about counts, and a walk
        // that read nothing produces the most reassuring counts of all.
        $this->assertGreaterThan(
            0,
            $narrowDeclarations,
            'the test* walk read no declarations at all, so nothing below is a statement '
                . 'about this tree',
        );
        $this->assertGreaterThan(
            $narrowDeclarations,
            $wideDeclarations,
            'the unrestricted public alphabet read no more declarations than the test*-only '
                . 'one, which cannot be true while any test file has a public non-test method - '
                . 'the visibility argument is not reaching the scan',
        );

        $this->assertNotSame(
            [],
            $wideUnparseable,
            'the unrestricted public alphabet now places every declaration it reads. That is '
                . 'the day this guard should widen to it - see the doc-block on '
                . 'testNoCopiedTestMethodHasDriftedUnrecorded() - but confirm the scanner is '
                . 'still reporting before believing it, because a dead one reports exactly this',
        );

        $notDuplicates = [];
        foreach ($wideUnparseable as $problem) {
            if (!str_contains($problem, 'is declared twice in this file')) {
                $notDuplicates[] = $problem;
            }
        }
        $this->assertSame(
            [],
            $notDuplicates,
            'the wide public alphabet is unplaceable for a reason OTHER than a name declared '
                . 'twice in one file. The restriction to test* is justified by the unplaceable '
                . 'declarations all being this scanner\'s own bookkeeping; a different cause is '
                . 'a different argument and may well be a real defect. The wide run read '
                . $wideDeclarations . ' declarations and could not place '
                . \count($wideUnparseable) . '.',
        );

        $this->assertSame(
            [],
            $narrowUnparseable,
            'restricting the public alphabet to test* no longer produces a clean report - it '
                . 'read ' . $narrowDeclarations . ' declarations and could not place '
                . \count($narrowUnparseable) . '. That restriction is the only reason this '
                . 'guard can run the public half at all, so this is a change to what the guard '
                . 'covers and not a cosmetic failure.',
        );
    }

    /**
     * THE SUBJECT CLASSIFIER, PINNED IN BOTH POLARITIES.
     *
     * {@see isSubjectSpelling()} SUBTRACTS from every report it touches, and a
     * subtractor is the one kind of instrument a green suite cannot vouch for:
     * stuck at yes it empties three assertions of `[]` and they all pass. So it
     * is exercised here on cases whose answers are known before it is trusted
     * on the tree — a positive it must clear, and three negatives it must not,
     * each a different way the predicate could rot.
     */
    private function assertTheSubjectClassifierIsAlive(): void
    {
        $token = static fn (string $text): array => [\T_STRING . ':' . $text];

        // POSITIVE: each side names its own file's subject.
        $this->assertTrue(
            self::isSubjectSpelling(
                $token('Effort'),
                $token('TaskStatus'),
                'Agents/EffortTest.php',
                'Agents/TaskStatusTest.php',
            ),
            'the shape the classifier exists for was not recognised, so it is subtracting '
                . 'nothing and every report it filters is unfiltered',
        );

        // NEGATIVE, AND THE ONE THAT MATTERS: a helper naming the OTHER file's
        // subject is the copied-and-not-updated defect itself.
        $this->assertFalse(
            self::isSubjectSpelling(
                $token('TaskStatus'),
                $token('TaskStatus'),
                'Agents/EffortTest.php',
                'Agents/TaskStatusTest.php',
            ),
            'a copy that reflects the class the OTHER file is about was classified as naming '
                . 'its own subject. That is precisely a test copied and not updated, and the '
                . 'classifier would be hiding it',
        );

        // NEGATIVE: a differing token that is not a T_STRING at all.
        $this->assertFalse(
            self::isSubjectSpelling(
                [\T_LNUMBER . ':10'],
                [\T_LNUMBER . ':15'],
                'Agents/EffortTest.php',
                'Agents/TaskStatusTest.php',
            ),
            'a differing NUMBER was classified as a subject spelling - a changed bound, cap or '
                . 'timeout is a behaviour difference and must never be subtracted',
        );

        // NEGATIVE: more than one token on a side is not "the same helper
        // pointed elsewhere", whatever those tokens say.
        $this->assertFalse(
            self::isSubjectSpelling(
                [\T_STRING . ':Effort', \T_STRING . ':Effort'],
                $token('TaskStatus'),
                'Agents/EffortTest.php',
                'Agents/TaskStatusTest.php',
            ),
            'a two-token divergence was classified as a subject spelling',
        );

        // NEGATIVE, and the reason the predicate is not a substring test: a
        // single letter appears in almost every filename.
        $this->assertFalse(
            self::isSubjectSpelling(
                $token('t'),
                $token('t'),
                'Agents/EffortTest.php',
                'Agents/TaskStatusTest.php',
            ),
            'a one-letter token that merely occurs somewhere inside both filenames was '
                . 'classified as naming their subjects',
        );

        // NEGATIVE, AND THE ONE A PREFIX TEST GOT WRONG. `Task` is a real class
        // AND a prefix of `TaskStatus`, so a copy in TaskStatusTest reflecting
        // Task reads as "names its own subject" under any prefix rule -- while
        // being exactly the copied-and-not-updated defect. The families where
        // this bites are the specialised variants a test is copied FROM:
        // Agent/AgentManager, Team/TeamConfig, Task/TaskStatus.
        $this->assertFalse(
            self::isSubjectSpelling(
                $token('Effort'),
                $token('Task'),
                'Agents/EffortTest.php',
                'Agents/TaskStatusTest.php',
            ),
            'a copy in TaskStatusTest reflecting Task - the WRONG class, whose name merely '
                . 'begins the right one - was classified as naming its own subject. That is '
                . 'the defect this whole file exists to catch, cleared by its own classifier',
        );

        // POSITIVE, and the reason the rule is not simply `===`: a scenario-
        // suffixed file name has no type of its own, and the class it is about
        // is a proper prefix of it. This is the `maxStderrBytes` pair, which
        // used to need a prose row in ACCEPTED_DIVERGENCE.
        $this->assertTrue(
            self::isSubjectSpelling(
                $token('LspConnection'),
                $token('StdioMcpServer'),
                'LSP/LspConnectionStdinWedgeTest.php',
                'MCP/StdioMcpServerStderrDrainTest.php',
            ),
            'a scenario-suffixed test file naming the class it is actually about was NOT '
                . 'cleared, so retiring the maxStderrBytes row left a real pair reported as '
                . 'drift',
        );

        // THE TYPE SCAN IS ALIVE. Everything above rests on knowing which
        // subjects are declared types; a scan returning nothing would silently
        // relax the rule back to the prefix form that the negative above
        // exists to reject, and every assertion here would still pass except
        // that one. No count is asserted - a cardinality over src/ is wrong in
        // the next lane - only that it found something and can answer both ways.
        $types = self::declaredTypeNames();
        $this->assertNotSame([], $types, 'the src/ type scan found nothing, so every subject '
            . 'looks like a scenario description and the classifier has quietly relaxed to a '
            . 'prefix test');
        $this->assertArrayHasKey('TaskStatus', $types, 'the src/ type scan cannot see a class '
            . 'it must see for the negative above to mean anything');
        $this->assertArrayNotHasKey('LspConnectionStdinWedge', $types, 'a scenario description '
            . 'is being reported as a declared type, which would refuse the positive above');
    }

    /**
     * A HELPER WITH NO MODIFIER AT ALL IS PUBLIC, AND A THREE-KEYWORD ALPHABET
     * CANNOT SAY SO (E565).
     *
     * `carriesVisibility()` asks whether one of a list of keyword tokens sits
     * before the declaration. The one spelling it structurally cannot express
     * is the ABSENCE of a keyword — `function testFoo()`, which PHP means as
     * `public function testFoo()` — so a test method copied between two suites
     * and fixed in only one of them was invisible to T_PRIVATE, T_PROTECTED and
     * T_PUBLIC alike. That copied-and-half-fixed test method is this class's
     * own subject (E481), so the hole was in the arm added to close E481.
     *
     * IT IS UNTRIGGERED RATHER THAN LIVE, AND THAT IS WHY THIS TEST IS
     * SYNTHETIC. MEASURED on PHP 8.3.6 at the commit that added the arm: ZERO
     * named declarations anywhere under `tests/` carry no modifier. E363's
     * distinction applies exactly — a false-negative an empty population cannot
     * trigger is untriggered, not dead, and the honest answer to an unpinnable
     * clause is to make it pinnable rather than to write a paragraph excusing
     * it. The number is not asserted anywhere; it moves the day someone writes
     * one, and this test keeps answering.
     *
     * BOTH POLARITIES, because an arm that answers PUBLIC to everything is a
     * different defect from one that answers PUBLIC to nothing: the same pair
     * must be FOUND under a public alphabet and ABSENT under a private one.
     *
     * AND THE CLOSURE CONTROL, which is the mistake this fix made on its first
     * attempt and is worth a permanent reader. An anonymous function carries no
     * modifier either. With the visibility question asked before the name is
     * read, "absence means public" files every ANONYMOUS `function` in the
     * suite as a declaration whose name cannot be read — hundreds of rows, all
     * of them noise. The name is read first now, and the anonymous-function
     * discriminator asks `carriesVisibility()` about EXPLICIT keywords only.
     *
     * TWO CORRECTIONS TO HOW THAT USED TO BE WRITTEN, both measured. It said
     * "every closure", and an ARROW function is not in this population at all:
     * `fn` lexes as `T_FN`, and the walk below selects `T_FUNCTION`, so an
     * arrow never reaches it in either token order. And it carried the count as
     * a digit, which is a cardinality over `tests/` in prose and moves the day
     * any lane adds a closure (rule 18). Derive it instead: swap the two blocks
     * in {@see declarationsIn()} and read the `unparseable` list the public
     * alphabet returns.
     */
    public function testAnImplicitlyPublicHelperIsScannedAsPublic(): void
    {
        $bodyA = "{ \$x = 1; return \$x; }";
        $bodyB = "{ \$x = 2; return \$x; }";

        $sources = [
            'a/A.php' => "<?php\nclass A { public function testCopied() " . $bodyA . " }\n",
            'b/B.php' => "<?php\nclass B { function testCopied() " . $bodyB . " }\n",
        ];

        [$drifted, $unparseable] = self::driftReport($sources, [\T_PUBLIC]);
        $this->assertSame(
            [],
            $unparseable,
            'the implicitly-public declaration was reported as unreadable rather than scanned',
        );
        $this->assertArrayHasKey(
            'testCopied',
            $drifted,
            'a test method written WITHOUT a visibility modifier - which PHP means as public - '
                . 'was invisible to a public alphabet. The spelling of this one is the ABSENCE '
                . 'of a keyword, so no list of keywords can express it (E565)',
        );

        // THE OTHER POLARITY, AND BOTH SIDES OF THE PAIR MUST BE IMPLICIT FOR
        // IT TO SEE ANYTHING. The first version of this row reused $sources
        // above, whose A.php is EXPLICITLY public — so an arm answering yes to
        // every alphabet still matched only B.php, one declaration is not a
        // pair, and the report came back empty for the wrong reason. Measured:
        // the mutation making absence answer every alphabet SURVIVED that row
        // and is KILLED by this one (rule 2 — the window, not the mutation).
        $bothImplicit = [
            'a/A.php' => "<?php\nclass A { function testCopied() " . $bodyA . " }\n",
            'b/B.php' => "<?php\nclass B { function testCopied() " . $bodyB . " }\n",
        ];
        [$publicPair] = self::driftReport($bothImplicit, [\T_PUBLIC]);
        $this->assertArrayHasKey(
            'testCopied',
            $publicPair,
            'two implicitly-public copies of one test method are not a pair, so the row below '
                . 'asserts an emptiness that has nothing to do with the alphabet',
        );

        [$privateDrifted] = self::driftReport($bothImplicit, [\T_PRIVATE]);
        $this->assertSame(
            [],
            array_keys($privateDrifted),
            'an implicitly-public declaration answered a PRIVATE alphabet, so the arm is '
                . 'answering yes to every question rather than naming one visibility',
        );

        // AND ONE MORE: an EXPLICIT modifier still decides. A private helper is
        // not dragged in by the public alphabet.
        [$explicit] = self::driftReport(
            [
                'a/A.php' => "<?php\nclass A { private function copied() " . $bodyA . " }\n",
                'b/B.php' => "<?php\nclass B { private function copied() " . $bodyB . " }\n",
            ],
            [\T_PUBLIC],
        );
        $this->assertSame([], array_keys($explicit), 'an explicitly private helper answered the public alphabet');

        // THE CLOSURE CONTROL. Anonymous functions carry no modifier and are
        // not declarations; asking about visibility before reading the name
        // files all of them as unreadable.
        $closures = "<?php\nclass C { public function testA() { \$f = function () { return 1; }; \$g = fn () => 2; return [\$f, \$g]; } }\n";
        [, $closureUnparseable] = self::driftReport(['c/C.php' => $closures], [\T_PUBLIC]);
        $this->assertSame(
            [],
            $closureUnparseable,
            'a closure was filed as a declaration whose name could not be read. An anonymous '
                . 'function has no modifier for the same reason a bare `function foo()` has '
                . 'none, and the two are told apart by reading the NAME first',
        );
    }

    /**
     * WIDENING THE VISIBILITY ALPHABET TO `protected` ADDS NO HELPER AT ALL,
     * ONLY PHPUnit'S OWN LIFECYCLE HOOKS - and that is the argument for the
     * restriction, standing where a sentence asserting it used to.
     *
     * RULE: AN ALPHABET IS COVERAGE, and one chosen to match the cases already
     * known reports zero for everything it cannot express. The only honest way
     * to defend a narrow one is to run the WIDE one through the same report and
     * show what it brings in - so the defence moves when the tree does, instead
     * of being re-read as still true.
     *
     * WHAT THE WIDE ALPHABET BRINGS IN, derived rather than written down: pairs
     * whose name is a protected method PHPUnit declares on
     * {@see \PHPUnit\Framework\TestCase} itself. Two classes that both
     * override `setUp()` have not copied a helper, they have implemented the
     * same framework hook, and their bodies differing by one temp-name literal
     * is the framework working as designed. The hook roster is read off the
     * framework rather than listed here, so a PHPUnit upgrade that adds a hook
     * cannot turn this red for the wrong reason.
     *
     * THE DAY THIS REDS IS THE DAY THE RESTRICTION SHOULD GO. A protected name
     * that is NOT a framework hook, one token apart across two files, is this
     * file's own subject wearing a different modifier - so the message says to
     * widen the alphabet, not to exempt the name.
     *
     * THE PAIR COUNT BELOW IS NOT DECORATION, AND THE REASON IS NOT THE ONE
     * THIS PARAGRAPH FIRST GAVE. It claimed to be the assertion a dead report
     * cannot satisfy; measured, a {@see driftReport()} stubbed to return no
     * drift is caught by {@see assertTheScannerIsAlive()} three assertions
     * earlier, so that was a claim about the wrong failure. What the count
     * really covers is the hole in the assertion above it: an EMPTY wide report
     * satisfies "no name here is a non-hook" perfectly. Measured both ways -
     * with the alphabet parameter neutralised so the wide run yields nothing,
     * the diff assertion passes and only the count reds; with the count deleted,
     * the whole file stays green on that mutation. Rule 15, one level down: an
     * assertion of `[]` needs something in the same test that fails when the
     * instrument stops producing.
     */
    public function testWideningTheVisibilityAlphabetToProtectedAddsNoHelperAtAll(): void
    {
        $this->assertTheScannerIsAlive();

        $sources = [];
        foreach (self::everyTestFile() as $relative => $path) {
            $sources[$relative] = (string) file_get_contents($path);
        }

        [$narrow] = self::driftReport($sources);
        [$wide] = self::driftReport($sources, [\T_PROTECTED]);

        $hooks = [];
        foreach ((new \ReflectionClass(TestCase::class))->getMethods(\ReflectionMethod::IS_PROTECTED) as $method) {
            $hooks[] = $method->getName();
        }
        sort($hooks);

        $this->assertNotSame(
            [],
            $hooks,
            'PHPUnit\'s TestCase declares no protected method any more, so there is no roster '
                . 'to tell a framework hook from a copied helper and this comparison has lost '
                . 'its meaning. Re-derive the roster before trusting the assertion below',
        );

        $this->assertSame(
            [],
            array_values(array_diff(array_keys($wide), $hooks)),
            'a PROTECTED helper that is not a PHPUnit lifecycle hook is one token apart across '
                . 'two files. That is exactly what this file exists to catch, wearing a '
                . 'different modifier, and the guard is not looking at it: the real checks run '
                . 'the private alphabet only. THE FIX IS TO WIDEN THE ALPHABET - add T_PROTECTED '
                . 'to driftReport()\'s default and argue the rows the wide run brings with it - '
                . 'and to rewrite the PROTECTED AND PUBLIC HELPERS bullet on this class, whose '
                . 'whole argument is that this list stays empty. Do NOT exempt the name here; '
                . 'this test is the measurement the restriction rests on, not a roster of '
                . 'allowed protected helpers.',
        );

        $count = static fn (array $report): int => array_sum(array_map('count', $report));

        $this->assertGreaterThan(
            $count($narrow),
            $count($wide),
            'the wide alphabet no longer brings in more pairs than the narrow one. Either the '
                . 'framework hooks stopped diverging across the suite - in which case the '
                . '"widening would be answered with exemptions" half of the argument is gone '
                . 'and the bullet needs rewriting - or the report is returning nothing at all, '
                . 'which empties both sides and is the failure this comparison is shaped to '
                . 'catch',
        );
    }

    /**
     * WHAT A SECOND TOKEN OF SLACK BRINGS IN IS A RECEIVER SPELLING AND
     * NOTHING ELSE — derived from the tree, in the shape E287 established for
     * the visibility alphabet.
     *
     * WHAT THE CLASS DOC-BLOCK SAID: "sampled on PHP 8.3.6 those are mostly
     * `self::` against `$this->`, which is noise, but nothing here proves they
     * all are". WHAT IS TRUE NOW: the sample is gone and the whole population
     * is checked, every run, by a PREDICATE rather than a roster of names — a
     * roster would have to be re-argued at every merge, and a count taken in a
     * lane worktree is void at the next one. WHY THE RESTRICTION STILL EARNS
     * ITS PLACE: {@see DRIFT_BOUND} stays at one because everything two brings
     * in is how a file spells its own receiver, and the day that stops being
     * true this test names the pair and says to widen the bound.
     *
     * IT ALREADY PAID FOR ITSELF ONCE. The first run of this measurement
     * reported a fourth name that was NOT a receiver spelling:
     * `significantTokens()`, where one copy dropped `T_WHITESPACE` and the
     * copy it came from did not — and the copy that did not fed a walk that
     * reads the neighbours of a `::` BY INDEX, so a spaced call was skipped
     * before it was examined and an "there is no indirect call" assertion was
     * an assertion about the unspaced spelling alone. Bound one could not see
     * it. That is the whole argument for measuring rather than sampling.
     */
    public function testRelaxingTheBoundToTwoTokensBringsInOnlyAReceiverSpelling(): void
    {
        $this->assertTheScannerIsAlive();

        $sources = [];
        foreach (self::everyTestFile() as $relative => $path) {
            $sources[$relative] = (string) file_get_contents($path);
        }

        [$narrow] = self::driftReport($sources);
        [$wide, , , $wideCores] = self::driftReport($sources, [\T_PRIVATE], self::DRIFT_BOUND + 1);

        $unargued = [];
        foreach ($wideCores as $name => $pairs) {
            if (isset($narrow[$name])) {
                continue;
            }
            foreach ($pairs as [$left, $right]) {
                if (self::isReceiverSpelling($left) && self::isReceiverSpelling($right)) {
                    continue;
                }
                $unargued[] = $name . ': [' . implode(' ', $left) . '] vs [' . implode(' ', $right) . ']';
            }
        }

        self::assertSame(
            [],
            $unargued,
            'relaxing the bound by one token brings in a pair whose divergence is NOT how the '
                . 'two files spell their receiver. That is a copied helper two edits apart, '
                . 'which is this file\'s own subject sitting just outside its bound and '
                . 'invisible to every check above. Read the pair: if it is a real drift, fix it '
                . 'or record it, and if the shape recurs, raise DRIFT_BOUND and argue the rows '
                . 'the wide run brings with it. Do NOT add the name to ACCEPTED_DIVERGENCE — '
                . 'that map is checked against the NARROW report and a bound-two pair is not in '
                . 'it, so the row would be stale on arrival.',
        );

        // RULE 15, ONE LEVEL DOWN (E228): the assertion above is an absence, and
        // an EMPTY wide report satisfies it perfectly. This is the component
        // that fails when the bound parameter stops doing anything.
        $count = static fn (array $report): int => array_sum(array_map('count', $report));

        self::assertGreaterThan(
            $count($narrow),
            $count($wide),
            'the wider bound no longer brings in more pairs than the narrow one, so either the '
                . 'bound parameter has stopped being honoured — in which case the assertion '
                . 'above is measuring nothing — or every pair in this suite is now exactly one '
                . 'token apart, which would be a fact worth writing down rather than a green',
        );
    }

    /**
     * KNOWN-ANSWER CONTROL FOR THE BOUND PARAMETER, because the measurement
     * above is a negative and a bound that silently ignored its argument would
     * satisfy it.
     *
     * The fixture is a two-token divergence that is NOT a receiver spelling, so
     * it must be absent at bound one and present at bound two. Both directions
     * matter: absent at both means the parameter is dead, present at both means
     * the narrow report is not narrow.
     */
    public function testTheBoundParameterReallyChangesWhatIsReported(): void
    {
        $original = <<<'PHP'
            <?php
            final class A
            {
                private function probe(string $device): bool
                {
                    $slack = -1;

                    return str_contains((string) shell_exec('stty -F ' . $device), '-icanon')
                        && $slack < 0;
                }
            }
            PHP;

        // TWO CONTIGUOUS tokens per side, and neither is a receiver: the sign
        // and the magnitude of one literal. Contiguity matters — divergenceCore
        // trims the common prefix and suffix, so two edits in DIFFERENT places
        // give a core spanning everything between them, which is a far wider
        // divergence than the bound this fixture is built to probe.
        $drifted = str_replace('= -1;', '= +2;', $original);

        $sources = ['a/A.php' => $original, 'b/B.php' => $drifted];

        [$atOne] = self::driftReport($sources, [\T_PRIVATE], 1);
        self::assertArrayNotHasKey('probe', $atOne, 'a two-token divergence was reported at a bound of one');

        [$atTwo, , , $cores] = self::driftReport($sources, [\T_PRIVATE], 2);
        self::assertArrayHasKey('probe', $atTwo, 'a two-token divergence was not reported at a bound of two, '
            . 'so the bound argument is not reaching the comparison and the measurement beside '
            . 'this fixture is a statement about a constant');

        self::assertFalse(
            self::isReceiverSpelling($cores['probe'][0][0]),
            'the fixture meant to be a NON-receiver divergence is classified as a receiver one, '
                . 'so the predicate the measurement rests on would pass it either way',
        );
    }

    /**
     * Whether a divergence core is nothing but how one file spells the receiver
     * of a call.
     *
     * THE FOUR SPELLINGS ARE THE LANGUAGE'S, not a list of what the tree
     * happens to contain: an instance receiver is `$this` with `->`, and a
     * scoped one is `self`, `static` or `parent` with `::`. A core that is one
     * of those, opposite a core that is another, is the same call written two
     * ways. Anything else is a difference in what the code DOES.
     *
     * An EMPTY core is a receiver spelling too, and deliberately: it is the
     * side of a pair where the receiver is simply absent — `foo()` against
     * `self::foo()` — which is the third way the same call gets written.
     *
     * @param list<string> $core normalised `id:text` tokens
     */
    private static function isReceiverSpelling(array $core): bool
    {
        $text = [];
        foreach ($core as $token) {
            $at = strpos($token, ':');
            $text[] = $at === false ? $token : substr($token, $at + 1);
        }

        return \in_array($text, [
            [],
            ['$this', '->'],
            ['self', '::'],
            ['static', '::'],
            ['parent', '::'],
        ], true);
    }

    /**
     * THE SUBJECT OF A TEST FILE: its basename with a trailing `Test` removed.
     *
     * Derived from the path rather than from a roster, so it moves when a file
     * is renamed and cannot go stale in the way a written-down list of pairs
     * would.
     */
    private static function subjectOf(string $relative): string
    {
        return (string) \preg_replace('/Test$/', '', \basename($relative, '.php'));
    }

    /**
     * TWO COPIES OF ONE HELPER THAT DIFFER ONLY IN NAMING THEIR OWN FILE'S
     * SUBJECT ARE NOT DRIFT — they are the same helper pointed at the class
     * each file is about.
     *
     * WHAT THE TREE SAID BEFORE THIS PREDICATE EXISTED, kept because deleting
     * the reasoning is how the next reader deletes the guard. An
     * `ACCEPTED_DIVERGENCE` row for `maxStderrBytes` stood here and read, in
     * part: *"the differing token is the CLASS the constant is read off: one
     * copy reflects on `LspConnection::MAX_STDERR_BYTES`, the other on
     * `StdioMcpServer::MAX_STDERR_BYTES`, and each is the class its own file is
     * about. Making the two agree would be the bug."* WHAT IS TRUE NOW: that
     * sentence was correct about the code and wrong about where the fix
     * belonged. A row is a licence keyed by NAME, so it excuses that helper
     * for ever and excuses nothing else; the property it was really describing
     * — *each copy names its own subject* — is checkable, and once checked it
     * covers every helper of that shape without anybody arguing a row. WHY
     * THIS STILL EARNS ITS PLACE: the row's closing advice ("if a third stdio
     * class grows one, give it the same helper against its own constant")
     * needed a human to follow it. This predicate simply answers yes for the
     * third one.
     *
     * AND IT IS WHAT MAKES THE `public` ALPHABET AFFORDABLE, which is the
     * second half of the argument. Measured on PHP 8.3.6 over `tests/` at the
     * commit that added this: with the `test*` population scanned and this
     * predicate NOT applied, 12 names and 54 pairs are reported, each differing
     * in a single token naming the class its own file tests. MOST are
     * `tryFrom`/`from` enum tests — but NOT all of them, and the sentence here
     * used to say otherwise. Seven of the 54 are ordinary behavioural tests
     * duplicated across two suites ({@see testEvent} across two hook suites,
     * two `completeAsync` tests across two backend suites, two timeout-bound
     * tests across two config suites, and a constructor test across two parser
     * suites), which strengthens the case for the predicate rather than
     * weakening it: the shape is not peculiar to enums. Answering these with 12
     * prose rows would have been 12 licences bought to close one hole — and the
     * next test copied into the tree would have needed a thirteenth.
     *
     * EACH SIDE'S TOKEN MUST NAME ITS OWN FILE'S SUBJECT, and how strictly that
     * is enforced is {@see namesItsOwnSubject()}'s problem rather than this
     * one's -- see there for why an exact match is required of a subject that
     * is itself a declared type and a prefix is allowed of one that is not.
     * Reflecting the WRONG class is precisely the copied-and-not-updated defect
     * this file exists for, so the asymmetry is the point; an earlier prefix-
     * only form claimed that asymmetry and did not deliver it.
     *
     * @param list<string> $leftCore  normalised `id:text` tokens
     * @param list<string> $rightCore normalised `id:text` tokens
     */
    private static function isSubjectSpelling(
        array $leftCore,
        array $rightCore,
        string $leftFile,
        string $rightFile,
    ): bool {
        $names = static function (array $core): ?string {
            if (\count($core) !== 1) {
                return null;
            }
            $at = \strpos($core[0], ':');
            if ($at === false || (int) \substr($core[0], 0, $at) !== \T_STRING) {
                return null;
            }
            $text = \substr($core[0], $at + 1);

            return $text === '' ? null : $text;
        };

        $left = $names($leftCore);
        $right = $names($rightCore);
        if ($left === null || $right === null) {
            return false;
        }

        return self::namesItsOwnSubject(self::subjectOf($leftFile), $left)
            && self::namesItsOwnSubject(self::subjectOf($rightFile), $right);
    }

    /**
     * Does $token name the class $subject's file is ABOUT?
     *
     * EXACT, EXCEPT FOR SCENARIO-SUFFIXED FILE NAMES, and the exception is the
     * whole difficulty. `TaskStatusTest` is about `TaskStatus`, so its token
     * must be `TaskStatus`. But `LspConnectionStdinWedgeTest` is about
     * `LspConnection` -- the rest of the name says which scenario, not which
     * class -- so demanding an exact match there would report a pair that is
     * not drift.
     *
     * WHAT THIS SAID BEFORE, kept because deleting the reasoning is how the
     * next reader deletes the guard: a plain `str_starts_with`, defended as
     * "deliberately narrow in one direction -- a helper in `FooTest` that
     * reflects `Bar` is not excused, and that asymmetry is the point". WHAT IS
     * TRUE NOW: it was not narrow in that direction at all. A prefix test
     * excuses a copy that names the WRONG class whenever the wrong class's
     * name is a prefix of the right one's, and those are exactly the
     * specialised-variant families a test gets copied FROM -- `Task` against
     * `TaskStatus`, `Agent` against `AgentManager`, `Team` against
     * `TeamConfig`. Measured: a `test*` method in `Agents/TaskStatusTest`
     * changed to reflect `Task` -- the canonical copied-and-not-updated defect,
     * and the one this file exists for -- was cleared by the predicate and the
     * guard stayed green.
     *
     * WHY THIS STILL EARNS ITS PLACE, and why the answer is not simply `===`:
     * the latitude is only ever needed where the subject is NOT itself a type.
     * So it is granted only there. Measured on PHP 8.3.6 over `tests/`, this
     * rule clears every pair the prefix form cleared -- all 54 in the `test*`
     * population and the one `maxStderrBytes` pair in the `private` one -- while
     * rejecting the `Task`/`TaskStatus` shape that the prefix form let through.
     * Tightening to `===` would also have closed the hole, but at the price of
     * putting `maxStderrBytes` back into {@see ACCEPTED_DIVERGENCE} as a prose
     * row; this keeps the row retired.
     *
     * A DEAD TYPE SCAN FAILS SAFE. If {@see declaredTypeNames()} returned
     * nothing, every subject would look like a non-type and the rule would
     * relax to the prefix form -- so the scan being alive is asserted in
     * {@see assertTheSubjectClassifierIsAlive()} rather than assumed.
     */
    private static function namesItsOwnSubject(string $subject, string $token): bool
    {
        if ($subject === $token) {
            return true;
        }

        if (isset(self::declaredTypeNames()[$subject])) {
            return false;
        }

        return \str_starts_with($subject, $token);
    }

    /**
     * The short names of every type declared under `src/`, as a set.
     *
     * Read from FILE NAMES rather than by parsing or autoloading: this package
     * is PSR-4, so a type's short name is its file's basename, and the question
     * being asked -- "is `LspConnectionStdinWedge` a class in this package, or
     * a scenario description?" -- does not need the class to be loadable. No
     * count is written down anywhere; a cardinality over `src/` taken in one
     * lane is wrong in another.
     *
     * @return array<string,true>
     */
    private static function declaredTypeNames(): array
    {
        static $names = null;

        if ($names !== null) {
            return $names;
        }

        $names = [];
        $root = \dirname(__DIR__, 2) . '/src';

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $names[\basename($file->getFilename(), '.php')] = true;
        }

        return $names;
    }

    /**
     * Split a {@see driftReport()} report into the pairs that still count as
     * drift and the pairs {@see isSubjectSpelling()} explains.
     *
     * A description this cannot split goes to `unreadable` rather than to
     * either bucket. Rule: a guard must go red on what it cannot parse — a
     * pair quietly filed as "explained" because the classifier could not see
     * its files is a hole shaped exactly like the next copied helper.
     *
     * @param array<string,list<string>>                              $drifted
     * @param array<string,list<array{list<string>,list<string>}>>    $cores
     *
     * @return array{array<string,list<string>>, array<string,list<string>>, list<string>}
     *         name => still-drifted pairs, name => subject-spelling pairs,
     *         descriptions that could not be split
     */
    private static function partitionBySubject(array $drifted, array $cores): array
    {
        $real = [];
        $subject = [];
        $unreadable = [];

        foreach ($drifted as $name => $pairs) {
            foreach ($pairs as $index => $description) {
                $files = self::filesOf($description);
                if ($files === null) {
                    $unreadable[] = $name . ': ' . $description;

                    continue;
                }
                [$leftCore, $rightCore] = $cores[$name][$index] ?? [null, null];
                if (!\is_array($leftCore) || !\is_array($rightCore)) {
                    $unreadable[] = $name . ': no divergence core for ' . $description;

                    continue;
                }
                if (self::isSubjectSpelling($leftCore, $rightCore, $files[0], $files[1])) {
                    $subject[$name][] = $description;

                    continue;
                }
                $real[$name][] = $description;
            }
        }

        return [$real, $subject, $unreadable];
    }

    /**
     * The two files a {@see driftReport()} pair description names.
     *
     * Reading them back out of the description is a second reading of the
     * walk's answer, which this file argues against everywhere else — so the
     * pairing is asserted rather than assumed by every caller, and a
     * description this cannot split reaches the caller as `null` instead of as
     * a silently unclassified pair.
     *
     * @return array{string,string}|null
     */
    private static function filesOf(string $description): ?array
    {
        if (\preg_match('/^(\S+) \[.*\] vs (\S+) \[/', $description, $matched) !== 1) {
            return null;
        }

        return [$matched[1], $matched[2]];
    }

    /**
     * ONLY ONE OF {@see bodyOf()}'s TWO OPENER DISJUNCTS DOES ANY WORK, and
     * which one is a fact about the LEXER rather than about this file.
     *
     * WHY THIS IS A TEST AND NOT A COMMENT. Rule: never remove dormant code -
     * wire it, or document it as an intentional seam with a measured reason
     * and PIN THE DORMANCY. The `T_CURLY_OPEN` disjunct in that walk is
     * unreachable, because the arm beside it matches on the token's TEXT and
     * that token's text is the same one byte; measured, dropping it changes no
     * body and survives both real-tree tests in this file. A dormancy nobody
     * pinned is a line the next reader deletes as dead - and it is dead only
     * for as long as the text holds.
     *
     * BOTH DIRECTIONS, derived from the running interpreter rather than
     * written down, because the two disjuncts are dormant and live for
     * opposite reasons and either can flip on a language change alone.
     */
    public function testTheBodyWalkKeysOnTokenTextSoOnlyOneOpenerDisjunctDoesWork(): void
    {
        $textOf = static function (string $source): array {
            $found = [];
            foreach (\token_get_all($source) as $token) {
                if (\is_array($token) && str_ends_with($token[1], '{')) {
                    $found[\token_name($token[0])] = $token[1];
                }
            }

            return $found;
        };

        // The everyday spelling, written as code because it is not deprecated.
        $this->assertSame(
            ['T_CURLY_OPEN' => '{'],
            $textOf('<?php $b = 1; $s = "a{$b}c";'),
            'the `{` that opens an interpolation no longer reports its text as that one byte. '
                . 'bodyOf() increments on `$text === \'{\'`, so until now its T_CURLY_OPEN '
                . 'disjunct was dormant - this is the change that makes it load-bearing, and '
                . 'any walker that dropped it as dead code is now losing a level.',
        );

        // ...and the deprecated one, supplied as a nowdoc so this file never
        // compiles it and carries no occurrence of the syntax.
        $deprecated = <<<'PHP'
            <?php $b = 1; $s = "a${b}c";
            PHP;
        $openers = $textOf($deprecated);

        if (!\defined('T_DOLLAR_OPEN_CURLY_BRACES')) {
            // On the PHP that removed the syntax there is no second disjunct to
            // reason about, and the walk is correct with the text arm alone.
            $this->assertSame(['T_CURLY_OPEN' => '{'], $openers);

            return;
        }

        $this->assertArrayHasKey(
            'T_DOLLAR_OPEN_CURLY_BRACES',
            $openers,
            'this PHP still defines the deprecated opener but no longer lexes the spelling into '
                . 'one, so the fixture that pins the LIVE disjunct has stopped exercising it',
        );
        $this->assertNotSame(
            '{',
            $openers['T_DOLLAR_OPEN_CURLY_BRACES'],
            'the deprecated opener now reports its text as the same one byte T_CURLY_OPEN does, '
                . 'which would make bodyOf()\'s text arm cover it and its disjunct dormant too. '
                . 'The behavioural fixture in assertTheScannerIsAlive() would then be pinning '
                . 'nothing, and this file would have no live opener disjunct at all.',
        );
    }

    /**
     * THE SCANNER IS PUSHED THROUGH INPUTS WHOSE ANSWER IS KNOWN, in the same
     * test that uses it to assert an absence.
     *
     * WHY. Both real-tree assertions above are absences - "nothing drifted that
     * is not recorded", "no row is stale" - and an absence is satisfied
     * perfectly by an instrument that has been deleted. It is satisfied just as
     * perfectly by a fixture whose expected value is what a DEAD instrument
     * returns, which is why the load-bearing fixture here is the POSITIVE one:
     * with {@see driftReport()} stubbed to return nothing, the first fixture
     * below fails and the two negatives do not.
     *
     * THE FIXTURES ARE NOWDOCS, so their `private function` declarations are a
     * single `T_ENCAPSED_AND_WHITESPACE` token in THIS file and contribute
     * nothing to the real scan. That is the same discipline the file argues
     * for: a fixture that spelled its offender as real code would put this
     * guard's own test doubles into the population it measures.
     */
    private function assertTheScannerIsAlive(): void
    {
        $original = <<<'PHP'
            <?php
            final class A
            {
                private function probe(string $device): bool
                {
                    return str_contains((string) shell_exec('stty -F ' . $device), '-icanon');
                }
            }
            PHP;

        // ONE TOKEN APART, inside a string literal: the exact shape of the
        // defect this file exists for, and the fixture that fails if the
        // scanner is dead.
        $drifted = str_replace("'stty -F '", "'stty -f '", $original);
        [$report] = self::driftReport(['a/A.php' => $original, 'b/B.php' => $drifted]);
        $this->assertArrayHasKey(
            'probe',
            $report,
            'two copies of one helper differing by a single string literal were not reported. '
                . 'Until this passes, every "nothing drifted" answer above is a statement about '
                . 'a dead instrument.',
        );

        // IDENTICAL COPIES ARE NOT DRIFT. Without this the predicate could be
        // stuck at "every shared name is drift", which would put every
        // legitimately duplicated helper on the hook and be answered with an
        // exemption - which is where the next real drift would hide.
        [$report] = self::driftReport(['a/A.php' => $original, 'b/B.php' => $original]);
        $this->assertSame(
            [],
            $report,
            'two byte-identical copies of a helper were reported as drifted',
        );

        // A SHARED NAME OVER TWO UNRELATED BODIES IS NOT DRIFT EITHER. This is
        // the other way the predicate rots, and it is the common case rather
        // than the exotic one: most private names declared in more than one
        // file under `tests/` belong to unrelated helpers that merely agree on
        // a word - chat(), app(), context() - and a predicate stuck at "a
        // shared name means drift" would put every one of them on the hook.
        $unrelated = <<<'PHP'
            <?php
            final class B
            {
                private function probe(string $device): bool
                {
                    $rows = [];
                    foreach (explode("\n", $device) as $line) {
                        $rows[] = trim($line);
                    }

                    return $rows !== [];
                }
            }
            PHP;
        [$report] = self::driftReport(['a/A.php' => $original, 'b/B.php' => $unrelated]);
        $this->assertSame(
            [],
            $report,
            'two unrelated helpers that happen to share a name were reported as drifted copies',
        );

        // THE BODY WALK'S ONE LOAD-BEARING OPENER DISJUNCT, pinned by what it
        // does rather than by what it names. The helper interpolates in the
        // 8.2-deprecated spelling and the one-token divergence sits AFTER it,
        // so dropping `T_DOLLAR_OPEN_CURLY_BRACES` from {@see bodyOf()} ends
        // both bodies at that interpolation's bare `}` - and the two truncated
        // bodies are then IDENTICAL, which this report treats as "no drift".
        // Measured: without this fixture that mutation SURVIVED both tests in
        // this file, caught only by the spelling roster in another one.
        //
        // The source is a NOWDOC, so the deprecated spelling is a single
        // T_ENCAPSED_AND_WHITESPACE token in THIS file and costs the tree no
        // occurrence - which is what
        // {@see InterpolationOpenerTokenTest::testNoFileUsesTheDeprecatedInterpolationSyntax()}
        // checks, over this file too.
        $deprecatedLeft = <<<'PHP'
            <?php
            final class D
            {
                private function probe(string $device): string
                {
                    $label = "dev${device}end";

                    return $label . 'left';
                }
            }
            PHP;
        $deprecatedRight = str_replace("'left'", "'right'", $deprecatedLeft);
        [$report] = self::driftReport([
            'a/D.php' => $deprecatedLeft,
            'b/D.php' => $deprecatedRight,
        ]);
        $this->assertArrayHasKey(
            'probe',
            $report,
            'two copies of a helper that interpolates in the 8.2-deprecated spelling, one token '
                . 'apart AFTER the interpolation, were not reported as drifted. The body walk '
                . 'lost a level at that interpolation\'s bare closer, truncated both bodies to '
                . 'the same prefix, and a pair that agrees is not drift - so this guard goes '
                . 'quiet on every helper that interpolates, which is the failure it exists to '
                . 'catch in other scanners.',
        );

        // AND A SAME-NAMED PRIVATE HELPER DECLARED TWICE IN ONE FILE IS
        // REPORTED, not silently deduplicated. Comparison is by file, so the
        // report keeps one body per file per name: a second declaration would
        // overwrite the first and the guard would quietly compare the wrong
        // one. No file in `tests/` does this today, which is exactly why the
        // fixture is synthetic.
        $twice = <<<'PHP'
            <?php
            final class E
            {
                private function probe(): string { return 'a'; }
            }
            final class F
            {
                private function probe(): string { return 'b'; }
            }
            PHP;
        [, $collision] = self::driftReport(['e/E.php' => $twice]);
        $this->assertNotSame(
            [],
            $collision,
            'one file declared the same private helper name twice and the scanner kept only one '
                . 'of the two bodies without saying so. A declaration silently dropped is a '
                . 'helper this guard has stopped covering, which is indistinguishable from one '
                . 'it has cleared.',
        );

        // AND THE SCANNER SAYS SO WHEN IT CANNOT PARSE. A declaration whose
        // body never closes must reach the caller as a problem, not be dropped.
        $truncated = <<<'PHP'
            <?php
            final class C
            {
                private function probe(): bool
                {
                    return true;
            PHP;
        [, $unparseable] = self::driftReport(['c/C.php' => $truncated]);
        $this->assertNotSame(
            [],
            $unparseable,
            'a private declaration whose body never closes was silently dropped instead of '
                . 'being reported - a guard that quietly ignores the unparseable has a hole '
                . 'shaped exactly like the next defect',
        );
    }

    /**
     * The drift report over a map of path => source.
     *
     * Takes SOURCES rather than reading the tree itself, so the fixtures above
     * and the two real-tree tests go through exactly this code. A second
     * definition of "drifted" is the seam a copied helper would slip through.
     *
     * THE VISIBILITY ALPHABET IS A PARAMETER rather than a constant inside the
     * walk, so the restriction on it can be MEASURED by
     * {@see testWideningTheVisibilityAlphabetToProtectedAddsNoHelperAtAll()}
     * through this same report instead of argued in prose. A second walk with a
     * different alphabet would be a second definition of "drifted", which is
     * the seam this whole file is about.
     *
     * THE BOUND IS A PARAMETER FOR THE SAME REASON THE ALPHABET IS. E279 asked
     * what two tokens brings in and the honest answer was that nobody had run
     * it; a constant buried in the comparison cannot be asked. It is measured
     * through this same report by
     * {@see testRelaxingTheBoundToTwoTokensBringsInOnlyAReceiverSpelling()}.
     *
     * THE NAME FILTER IS A PARAMETER FOR THE THIRD TIME THE SAME REASON. The
     * `public` alphabet cannot be run whole -- measured on PHP 8.3.6 over
     * `tests/`, it reports 520 declarations it cannot place, every one of them
     * a method name declared twice in one file by two anonymous test doubles,
     * which this name => file => body report has nowhere to put. Restricting
     * the population is therefore part of asking the question, not a way of
     * dodging it, and it belongs where the alphabet and the bound already are:
     * in the parameter list, where a test can vary it and show what it costs.
     *
     * @param array<string,string> $sources
     * @param list<int>            $visibility token ids one of which must carry the declaration
     * @param int|null             $bound      per-side divergence bound; null means {@see DRIFT_BOUND}
     * @param \Closure|null         $accepts    name => bool; null accepts every readable declaration
     *
     * @return array{array<string,list<string>>, list<string>, int, array<string,list<array{list<string>,list<string>}>>, array<string,list<string>>}
     *         name => pair descriptions, unparseable declarations, declarations
     *         read, name => the raw divergence cores of each reported pair, and
     *         name => pairs whose BODIES are identical and whose SIGNATURES are
     *         not.
     *         The cores are returned rather than re-derived because
     *         {@see testRelaxingTheBoundToTwoTokensBringsInOnlyAReceiverSpelling()}
     *         has to ask WHAT a pair diverges on, and parsing that back out of
     *         the description string would be a second reading of this walk's
     *         answer — the seam this whole file exists to close.
     */
    private static function driftReport(
        array $sources,
        array $visibility = [\T_PRIVATE],
        ?int $bound = null,
        ?\Closure $accepts = null,
    ): array {
        $bound ??= self::DRIFT_BOUND;

        $declarations = [];
        $unparseable = [];
        $read = 0;

        $signatures = [];

        foreach ($sources as $relative => $source) {
            foreach (self::declarationsIn($source, $visibility) as $declaration) {
                if ($declaration['body'] === null || $declaration['signature'] === null) {
                    // The name filter is deliberately NOT consulted here. A
                    // declaration this scanner could not read has no name to
                    // filter ON, and dropping it because it MIGHT have been out
                    // of scope is exactly the silent narrowing rule 14 is
                    // about: it would be indistinguishable from a clean read.
                    $unparseable[] = $relative . ': ' . $declaration['problem'];

                    continue;
                }
                if ($accepts !== null && !$accepts($declaration['name'])) {
                    continue;
                }
                if (isset($declarations[$declaration['name']][$relative])) {
                    // RULE: GO RED ON WHAT YOU CANNOT EXPRESS. The report is
                    // keyed name => file => body, so a second declaration of
                    // one name in one file has nowhere to go: it would
                    // overwrite the first and every later comparison would be
                    // against whichever body happened to be last.
                    $unparseable[] = $relative . ': ' . $declaration['name']
                        . '() is declared twice in this file, and this report keeps one body '
                        . 'per file per name - so one of the two would be compared and the '
                        . 'other silently dropped';

                    continue;
                }

                $read++;
                $declarations[$declaration['name']][$relative] = $declaration['body'];
                $signatures[$declaration['name']][$relative] = $declaration['signature'];
            }
        }

        ksort($declarations);
        $drifted = [];
        $cores = [];
        $signatureDrift = [];

        foreach ($declarations as $name => $byFile) {
            $files = array_keys($byFile);
            $count = \count($files);

            for ($a = 0; $a < $count; $a++) {
                for ($b = $a + 1; $b < $count; $b++) {
                    $left = $byFile[$files[$a]];
                    $right = $byFile[$files[$b]];
                    if ($left === $right) {
                        // E285: THE BODIES AGREE EXACTLY, SO THE TWO ARE THE
                        // SAME HELPER BY THIS FILE'S OWN DEFINITION — and any
                        // divergence in the PARAMETER LIST is then invisible to
                        // every check above, because bodyOf() starts at the
                        // opening brace. Reported HERE rather than folded into
                        // the divergence core, which is what makes it a wider
                        // guard instead of a different one: folding the
                        // signature in would push every promoted-property and
                        // named-argument spelling past DRIFT_BOUND and take
                        // real pairs out of the body report with it. Gating on
                        // body IDENTITY needs no bound at all.
                        $leftSignature = $signatures[$name][$files[$a]] ?? [];
                        $rightSignature = $signatures[$name][$files[$b]] ?? [];
                        if ($leftSignature !== $rightSignature) {
                            $signatureDrift[$name][] = $files[$a] . ' ' . implode(' ', $leftSignature)
                                . ' vs ' . $files[$b] . ' ' . implode(' ', $rightSignature);
                        }

                        continue;
                    }

                    [$leftCore, $rightCore] = self::divergenceCore($left, $right);
                    if (\count($leftCore) > $bound || \count($rightCore) > $bound) {
                        continue;
                    }

                    $drifted[$name][] = $files[$a] . ' [' . implode(' ', $leftCore) . '] vs '
                        . $files[$b] . ' [' . implode(' ', $rightCore) . ']';
                    $cores[$name][] = [$leftCore, $rightCore];
                }
            }
        }

        return [$drifted, $unparseable, $read, $cores, $signatureDrift];
    }

    /**
     * What is left of two token bodies once the common prefix and the common
     * suffix are trimmed.
     *
     * WHY NOT `similar_text()` OR A PERCENTAGE. A percentage answers "how
     * alike are these" and the question here is "how many edits apart are
     * they" - and the two ORDER DIFFERENTLY, which is the part that matters.
     * Measured on PHP 8.3.6 over `tests/` at the commit that added this file:
     * the `isRaw()` pair, ONE token apart out of a hundred, scores 98.90%. A
     * `removeDirectory()` pair whose bodies share only their opening and
     * closing - 66 of 74 tokens in the divergence core, two different helpers
     * by any reading - scores 98.80%, one tenth of a point below it. And an
     * `unshrinkablePairs()` pair 22 tokens apart scores 99.42%, HIGHER than
     * the one-token pair. A threshold on the percentage would admit both and
     * a stricter one would exclude `isRaw()` itself. Trimming both ends
     * answers in tokens, is O(n), and is exactly the quantity
     * {@see DRIFT_BOUND} is stated in. Generator: the same
     * {@see divergenceCore()} this guard calls.
     *
     * @param list<string> $left
     * @param list<string> $right
     *
     * @return array{list<string>,list<string>}
     */
    private static function divergenceCore(array $left, array $right): array
    {
        $leftCount = \count($left);
        $rightCount = \count($right);
        $shortest = min($leftCount, $rightCount);

        $prefix = 0;
        while ($prefix < $shortest && $left[$prefix] === $right[$prefix]) {
            $prefix++;
        }

        $suffix = 0;
        while ($suffix < $shortest - $prefix
            && $left[$leftCount - 1 - $suffix] === $right[$rightCount - 1 - $suffix]) {
            $suffix++;
        }

        return [
            array_values(\array_slice($left, $prefix, $leftCount - $prefix - $suffix)),
            array_values(\array_slice($right, $prefix, $rightCount - $prefix - $suffix)),
        ];
    }

    /**
     * Every method a source declares that carries one of $visibility (a
     * `static` one included).
     *
     * The body is normalised to `id:text` per significant token - whitespace
     * and every kind of comment dropped - so that reindenting a helper, or
     * documenting one copy and not the other, is not drift. The token ID is
     * kept alongside the text because `'{'` the string and `{` the
     * interpolation opener are different tokens with the same text, and a
     * comparison on text alone would call them equal.
     *
     * @param list<int> $visibility token ids one of which must carry the declaration
     *
     * @return list<array{name:string, body:list<string>|null, signature:list<string>|null, problem:string}>
     */
    private static function declarationsIn(string $source, array $visibility): array
    {
        $tokens = \token_get_all($source);
        $count = \count($tokens);
        $found = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token) || $token[0] !== \T_FUNCTION) {
                continue;
            }

            // THE NAME IS READ BEFORE THE VISIBILITY, AND THE ORDER IS
            // LOAD-BEARING (E565). `carriesVisibility()` answers PUBLIC for a
            // declaration carrying no modifier at all, which is what PHP means
            // by one — but an anonymous `function () {}` and an arrow function
            // also carry no modifier, and asking about visibility first files
            // every ANONYMOUS `function` in the suite as an unreadable public
            // declaration — hundreds of rows. An ARROW function is not among
            // them: `fn` is `T_FN` and this walk selects `T_FUNCTION`, so it
            // never reaches here in either order. The count is deliberately not
            // written down (rule 18); swap these two blocks and read the
            // `unparseable` list under a `[\T_PUBLIC]` alphabet.
            $name = null;
            for ($j = $i + 1; $j < $count; $j++) {
                $candidate = $tokens[$j];
                if (\is_array($candidate) && $candidate[0] === \T_WHITESPACE) {
                    continue;
                }
                if (\is_array($candidate) && $candidate[0] === \T_STRING) {
                    $name = $candidate[1];
                }

                break;
            }

            // A `function` with no name is a closure, not a declaration this
            // guard has any business comparing — and it is excluded here rather
            // than reported, because `&` after `function` is a by-reference
            // NAMED declaration and reaches the arm below with its name read.
            if ($name === null && !self::carriesVisibility($tokens, $i, [\T_PRIVATE, \T_PROTECTED, \T_PUBLIC], false)) {
                continue;
            }

            if (!self::carriesVisibility($tokens, $i, $visibility)) {
                continue;
            }

            if ($name === null) {
                $found[] = [
                    'name' => '',
                    'body' => null,
                    'signature' => null,
                    'problem' => 'a private function declaration whose name this scanner cannot read',
                ];

                continue;
            }

            $body = self::bodyOf($tokens, $i);
            $signature = self::signatureOf($tokens, $i);
            $found[] = [
                'name' => $name,
                'body' => $body,
                'signature' => $signature,
                'problem' => match (true) {
                    $body === null => $name . '(): the scanner found no closing brace for this body',
                    $signature === null => $name . '(): the scanner found no closing parenthesis '
                        . 'for this parameter list',
                    default => '',
                },
            ];
        }

        return $found;
    }

    /**
     * Whether the `function` token at $at carries one of $visibility.
     *
     * @param list<array{int,string,int}|string> $tokens
     * @param list<int>                          $visibility
     * @param bool                               $anAbsentModifierIsPublic pass
     *        `false` to ask only about an EXPLICIT keyword. The one caller that
     *        does is the anonymous-function discriminator in
     *        {@see declarationsIn()}: an anonymous `function` carries no
     *        modifier either, so a question that treats absence as `public`
     *        cannot tell it from a bare `function foo()` and files every one of
     *        them as an unreadable declaration.
     */
    private static function carriesVisibility(
        array $tokens,
        int $at,
        array $visibility,
        bool $anAbsentModifierIsPublic = true,
    ): bool {
        $skippable = [
            \T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT,
            \T_STATIC, \T_FINAL, \T_ABSTRACT, \T_READONLY,
        ];

        for ($j = $at - 1; $j >= 0; $j--) {
            $token = $tokens[$j];
            if (!\is_array($token)) {
                // NO MODIFIER AT ALL, AND IN PHP THAT MEANS PUBLIC (E565).
                // The backwards walk has reached `{`, `}` or `;` — the end of
                // whatever came before the declaration — without meeting a
                // keyword, so this is `function foo()` written bare. It is
                // PUBLIC, and a three-keyword alphabet cannot express it,
                // because the spelling of this one is the ABSENCE of a keyword
                // (rule 11: an alphabet is coverage, and this one is made of
                // keywords).
                //
                // WHY IT MATTERS HERE RATHER THAN AS A CURIOSITY. This class's
                // public arm scans `test`-prefixed methods across the suite,
                // and a test method copied between two suites and fixed in only
                // one of them is precisely its subject (E481). Written
                // `function testFoo()` instead of `public function testFoo()`,
                // that copy was invisible to every alphabet the class passes —
                // T_PRIVATE, T_PROTECTED and T_PUBLIC alike — and nothing
                // reddened the day one arrived.
                //
                // MEASURED on PHP 8.3.6 at the commit that added this arm:
                // ZERO named declarations in `tests/` carry no modifier, so
                // this is an untriggered hole and not a live miss (E363's
                // shape — untriggered is not dead). The fixtures in
                // {@see testAnImplicitlyPublicHelperIsScannedAsPublic()} are
                // synthetic for exactly that reason, and they are what makes
                // the arm pinnable rather than argued.
                return $anAbsentModifierIsPublic && \in_array(\T_PUBLIC, $visibility, true);
            }
            if (\in_array($token[0], $skippable, true)) {
                continue;
            }

            return \in_array($token[0], $visibility, true);
        }

        return false;
    }

    /**
     * The normalised body of the method whose `function` token is at $at, or
     * null when this scanner cannot find where the body ends.
     *
     * WHAT THIS SAID: "the brace walk names every opener the running PHP
     * produces, not just `{`: an interpolation opens with an ARRAY token and
     * closes with a bare `}`, so a depth count matching only the bare string
     * goes one closer over". WHAT IS TRUE NOW, and it is why the paragraph was
     * rewritten rather than kept: that mechanism is real, but it is NOT the
     * mechanism at THIS site. This walk keys on the token's TEXT, not on the
     * bare one-byte string, and `T_CURLY_OPEN`'s text IS `{` - so the
     * `$text === '{'` arm already increments on it and the `T_CURLY_OPEN`
     * disjunct beside it does nothing at all. Measured, both ways: dropping
     * that disjunct leaves the body byte-identical on a modern-interpolation
     * helper, and the mutation SURVIVES this file's own two tests (it is
     * caught only by the SPELLING roster in
     * {@see InterpolationOpenerTokenTest::testEveryBraceWalkingScannerNamesEveryOpener()},
     * which reads what a walker NAMES and cannot tell whether the walk works).
     * `T_DOLLAR_OPEN_CURLY_BRACES` is the opposite: its text is `${`, the text
     * arm cannot see it, and dropping it truncates a body at the wrong brace.
     *
     * WHY THE DORMANT DISJUNCT STAYS. It is one token, it makes this walker's
     * roster identical to every other walker's, and it stops being dormant the
     * moment `T_CURLY_OPEN`'s text stops being that one byte - which is
     * precisely the kind of change a future PHP makes without telling anyone.
     * The dormancy is not left to a reader to rediscover: it is derived from
     * the running interpreter and pinned by
     * {@see testTheBodyWalkKeysOnTokenTextSoOnlyOneOpenerDisjunctDoesWork()},
     * which reds if the text ever changes. The LIVE disjunct is pinned
     * behaviourally instead, by the deprecated-spelling fixture in
     * {@see assertTheScannerIsAlive()}.
     *
     * A `;` at parameter-list depth zero before any `{` means the declaration
     * has no body. PHP does not allow a private abstract method, so in this
     * tree that is a shape the scanner does not understand rather than a real
     * answer, and it is reported as such.
     *
     * @param list<array{int,string,int}|string> $tokens
     *
     * @return list<string>|null
     */
    private static function bodyOf(array $tokens, int $at): ?array
    {
        $count = \count($tokens);
        $parentheses = 0;
        $i = $at;

        for (; $i < $count; $i++) {
            $text = \is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            if ($text === '(') {
                $parentheses++;
            } elseif ($text === ')') {
                $parentheses--;
            } elseif ($parentheses === 0 && $text === '{') {
                break;
            } elseif ($parentheses === 0 && $text === ';') {
                return null;
            }
        }

        if ($i >= $count) {
            return null;
        }

        $depth = 0;
        $body = [];

        for (; $i < $count; $i++) {
            $token = $tokens[$i];
            $id = \is_array($token) ? $token[0] : null;
            $text = \is_array($token) ? $token[1] : $token;

            if ($text === '{'
                || $id === \T_CURLY_OPEN
                || $id === \T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
                if ($depth === 0) {
                    return $body;
                }
            }

            if ($id !== null && \in_array($id, [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            $body[] = $id === null ? $text : $id . ':' . $text;
        }

        return null;
    }

    /**
     * The normalised parameter list of the method whose `function` token is at
     * $at, from its `(` to the matching `)` inclusive, or null when this
     * scanner cannot find where it ends.
     *
     * THE RETURN TYPE IS DELIBERATELY OUT. A widened `?string` against `string`
     * is a real divergence and a `: static` against `: self` is not, and this
     * guard has no way to tell them apart; including it would put every
     * spelling difference on the hook and be answered with exemptions, which is
     * where the next real one hides. Say so rather than let a reader assume the
     * signature means the whole declaration.
     *
     * @param list<array{int,string,int}|string> $tokens
     *
     * @return list<string>|null
     */
    private static function signatureOf(array $tokens, int $at): ?array
    {
        $count = \count($tokens);
        $open = null;

        for ($i = $at; $i < $count; $i++) {
            $text = \is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            if ($text === '(') {
                $open = $i;

                break;
            }
            if ($text === '{' || $text === ';') {
                return null;
            }
        }

        if ($open === null) {
            return null;
        }

        $depth = 0;
        $out = [];

        for ($i = $open; $i < $count; $i++) {
            $token = $tokens[$i];
            $id = \is_array($token) ? $token[0] : null;
            $text = \is_array($token) ? $token[1] : $token;

            if ($text === '(') {
                $depth++;
            } elseif ($text === ')') {
                $depth--;
                if ($depth === 0) {
                    $out[] = $text;

                    return $out;
                }
            }

            if ($id !== null && \in_array($id, [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            $out[] = $id === null ? $text : $id . ':' . $text;
        }

        return null;
    }
}

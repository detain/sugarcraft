<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * THE SUITE'S SENTENCES ABOUT `O_NONBLOCK` SAY THE OPPOSITE OF WHAT ITS CODE
 * DOES, AND EVERY MEASUREMENT IN EVERY ONE OF THOSE FILES IS CORRECT (E319).
 *
 * That combination is the dangerous one. A wrong measurement fails; a wrong
 * SENTENCE beside a right measurement survives re-reading, and the reader who
 * eventually "corrects" the code to match the prose deletes the repair. The
 * repair in question is the one `tests/bootstrap.php` makes to descriptor 0,
 * so the cost of that edit is a suite that parks a spawned binary on the
 * runner's stdin.
 *
 * THE DIRECTION, MEASURED HERE RATHER THAN ASSERTED. `stream_set_blocking($s,
 * false)` puts the flag ON the open file description and
 * `stream_set_blocking($s, true)` takes it off — the reverse of what the word
 * "blocking" suggests to a reader skimming for it, which is the whole reason
 * the sentences went the way they did. MEASURED on this box (Linux 6.8.0, PHP
 * 8.3.6) by reading `/proc/self/fdinfo/<fd>` either side of the call, three
 * takes each on a pipe, a regular file and a unix socket pair: `1 → 4001 → 1`,
 * `100000 → 104000 → 100000`, `2 → 4002 → 2`. It is
 * {@see testTheFlagDirectionIsWhatTheVocabularyHereSaysItIs()}, so the fact is
 * re-derived on every run rather than quoted from this paragraph.
 *
 * THE CONVENTION THIS FILE ENFORCES, in one line: the FLAG is only ever *set*
 * or *cleared*, and the DESCRIPTOR is only ever *blocking* or *non-blocking*.
 * A stream `stream_get_meta_data()` reports as `blocked === false` is
 * non-blocking, which means the flag is set.
 *
 * WHAT THE SCANNER READS, and why it is an assertion and not a `grep`. The
 * offending shape is a message paired with an assertion that contradicts it,
 * so neither half can be read alone: `assertFalse($meta['blocked'], …)` demands
 * a set flag and `assertTrue($meta['blocked'], …)` demands a cleared one. A
 * `grep` for the token sees ten sites and can rank none of them.
 *
 * AND THE DEMANDED DIRECTION IS STILL NOT THE ANSWER, WHICH IS WHERE THE FIRST
 * VERSION OF THIS FILE WAS WRONG. A failure message is read at the moment the
 * assertion FAILS, so a NEGATED sentence ("did not clear it") names the
 * demanded act and reports it missing, while an AFFIRMATIVE one ("the bootstrap
 * cleared it") describes the state at failure — the OPPOSITE of the demand.
 * Grading both against the demand alone is not blind to the affirmative form,
 * it is exactly backwards on it, and it false-cleaned the tree's one
 * affirmative site. {@see rank()} carries the derivation and
 * {@see vocabularyCases()} carries all four affirmative combinations, which the
 * table did not when the defect shipped: every polarity row in it was negated,
 * so the shape it mis-ranked was outside its own alphabet (rule 11).
 *
 * RULE 14: A SITE WHOSE DIRECTION THIS CANNOT DECIDE IS `unreadable`, NOT
 * CLEAN. One real site names BOTH directions in one sentence, which is a
 * different defect from naming the wrong one and is rostered as its own kind
 * rather than being quietly counted as fine. A sentence carrying a negation
 * that this cannot attach to its verb ({@see negatesTheVerb()}) takes the same
 * exit rather than being resolved by a coin flip.
 *
 * WHAT IT DELIBERATELY CANNOT SEE, and the second exclusion is much the larger
 * of the two.
 *
 * (1) Prose that discusses the flag without an assertion beside it —
 * `tests/bootstrap.php`'s measured table uses "clear" to mean *non-blocking*,
 * two paragraphs after using the flag sense, and no assertion pairs with it. A
 * sentence with no assertion has no polarity to contradict, so it is outside
 * this alphabet by construction rather than by oversight; that one wants a
 * reader, not a scanner.
 *
 * (2) EVERY BLOCKING-STATE ASSERTION WHOSE MESSAGE DOES NOT SPELL THE FLAG. The
 * gate is `blocked` AND the flag name, so a message that says "the stream is
 * blocking and it should not be" is not ranked at all — and there are more of
 * those in `tests/` than there are ranked sites. A read of the near-miss ones
 * found none currently inverted (they are `must report blocked` controls beside
 * the rostered pairs, which are correct), so this is coverage and not a live
 * defect, but "the census is clean" means clean over the narrower population.
 * That exclusion is encoded as a provider row rather than only described here
 * ({@see vocabularyCases()}, "an assertion that never names the flag is out of
 * scope"), and widening the gate to `blocked` alone would be a real widening:
 * it must be paid for by rostering whatever falls out, not by deleting rows.
 * No count is written down for either group — a cardinality over `tests/` is
 * stale at the next merge (rule 18) and this file already derives the one
 * number it actually enforces.
 *
 * THE ROSTER IS NOT AN EXEMPTION LIST. Every row is checked back against the
 * tree in both directions, so a file whose sentences are fixed reds and its row
 * must be deleted. **THAT WILL HAPPEN AT A MERGE AND IT IS NOT A BUG**: every
 * rostered file belongs to a different lane from this one, the fix there is a
 * prose edit, and the answer here is a DATA edit — delete the row, do not
 * weaken the check.
 */
final class NonBlockingVocabularyTest extends TestCase
{
    use DropsInsignificantTokensTrait;
    use RefusesAnUnreadableSourceTrait;

    /** Directories scanned. */
    private const SCOPE = ['tests', 'src'];

    /** `O_NONBLOCK` on Linux/x86-64. */
    private const FLAG = 04000;

    /**
     * Files whose paired sentences contradict their own assertions, with the
     * count and the reason it is recorded rather than fixed.
     *
     * THE COUNT IS EXACT so a ninth site cannot arrive unremarked in a file
     * that already has some. Rows are keyed by FILE and not by file:line —
     * a line number rots inside one round (rule 4), and every one of these
     * files is owned by another lane, so a row keyed on a line would go stale
     * on an edit that has nothing to do with this.
     *
     * @var array<string,array{sites:int,why:string}>
     */
    private const INVERTED_ROSTER = [
        'tests/Backend/EngineBackendTest.php' => [
            'sites' => 2,
            'why' => 'The pair around enableRawMode()/restore(). Both assertions are RIGHT and '
                . 'both sentences name the other direction. Out of this lane\'s file list.',
        ],
        'tests/ChatTest.php' => [
            'sites' => 2,
            'why' => 'The same pair, copied. That it is a COPY is the reason a census beats a '
                . 'fix here: the sentence travelled with the helper it describes.',
        ],
        'tests/Support/ForkedChildTest.php' => [
            'sites' => 4,
            'why' => 'TWO copies of the pair, and E319 recorded this file as carrying ONE — the '
                . 'entry says "(x2)" where the tree has four. Derived here rather than quoted, '
                . 'which is why the discrepancy is visible at all. Out of this lane.',
        ],
    ];

    /**
     * Files with a site this scanner refuses to rank, with the reason.
     *
     * SEPARATE FROM {@see INVERTED_ROSTER} BECAUSE IT ANSWERS A DIFFERENT
     * QUESTION. That map says "these sentences are backwards"; this one says
     * "this sentence cannot be read either way, and a scanner that guessed
     * would be reporting its guess".
     *
     * @var array<string,array{sites:int,why:string}>
     */
    private const UNREADABLE_ROSTER = [
    ];

    /**
     * THE MEANING, EXECUTED — so the vocabulary cannot drift back by argument.
     *
     * Three stream kinds because the flag lives on the open file DESCRIPTION
     * and a reader could reasonably suspect the answer differs between a pipe,
     * a regular file and a socket. It does not. The control is that the same
     * fd's flags must return to their starting value, which is what separates
     * "the call set the flag" from "the call happened to be a no-op and the fd
     * always looked like that".
     *
     * ⚠️ THIS TEST NEVER SKIPS, DELIBERATELY. Its first version carried both an
     * OS-Linux requires-annotation — the only one in the suite — and a
     * `markTestSkipped` on `/proc/self/fdinfo`. Neither fires here or in CI
     *
     * (AND THE NAME OF THAT ANNOTATION IS NOT SPELLED IN THIS FILE, which is
     * rule 26 arriving one level down: the first draft of this very paragraph
     * WROTE the annotation while describing it, PHPUnit read it out of the
     * doc-block, and the test skipped — inside the change whose entire purpose
     * was to stop it skipping. A doc-comment is not an inert place to quote a
     * doc-comment directive.)
     * (`sugar-crush` is in neither `WINDOWS_LIBS` nor `MACOS_LIBS` in
     * `scripts/affected-libs.php`), but a round whose central sanity check is
     * "skips are exactly one" should not hand itself two new ways to break it
     * on a runner-pool change. The split instead is by CAPABILITY: the
     * `stream_get_meta_data()` half is portable and always runs, the
     * descriptor-flag half needs `/proc`, and a box that has no `/proc` must
     * prove it is not Linux — which is a red on the case actually worth
     * catching (a Linux runner that lost `/proc`) rather than a silent skip.
     */
    public function testTheFlagDirectionIsWhatTheVocabularyHereSaysItIs(): void
    {
        $canReadFlags = is_dir('/proc/self/fdinfo');
        if (!$canReadFlags) {
            self::assertNotSame('Linux', \PHP_OS_FAMILY, 'this is Linux and /proc/self/fdinfo is '
                . 'missing, so the descriptor-level half of this measurement went away on a box '
                . 'where it should work');
        }

        // THE PIPE COMES FROM proc_open() AND NOT FROM popen(), and the first
        // draft used popen. `ChildStderrCaptureTest` reddened on it, correctly:
        // popen() gives the child the SUITE's fd 2, where nobody running
        // phpunit wants its noise and no test can read it. The spec below hands
        // fd 2 a pipe of its own, which is what that guard asks for — and it is
        // worth recording that the guard caught a spawn added by the very
        // round that was writing another guard beside it.
        $spec = [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $child = proc_open('cat', $spec, $pipes);
        self::assertIsResource($child, 'could not spawn the child whose stdin is the pipe under test');

        $socketPair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, 0);
        $file = fopen('/dev/null', 'r');
        self::assertIsResource($file);

        $kinds = [
            'a pipe' => $pipes[0],
            'a regular file' => $file,
            'a unix socket pair' => $socketPair[0],
        ];

        foreach ($kinds as $why => $stream) {

            $before = $canReadFlags ? self::flagSnapshot() : [];
            stream_set_blocking($stream, false);
            $nonBlocking = $canReadFlags ? self::flagSnapshot() : [];
            $metaNonBlocking = stream_get_meta_data($stream)['blocked'];
            stream_set_blocking($stream, true);
            $blocking = $canReadFlags ? self::flagSnapshot() : [];
            $metaBlocking = stream_get_meta_data($stream)['blocked'];

            if ($canReadFlags) {
                // THE FAILURE MODE HERE IS A HARD RED AND NOT A SKIP, and the
                // message has to say which of the two ways it went. This asks a
                // WHOLE-PROCESS question — "exactly one descriptor in
                // /proc/self/fd moved" — to answer a local one, with a live
                // `cat` child and PHPUnit's own descriptors in the same table.
                // Nothing in-process should move another fd's flags across
                // these two lines and nothing has in any run, but a reader
                // chasing a one-off red needs to know whether it found none or
                // several before they go looking at the stream.
                $moved = self::movedDescriptors($before, $nonBlocking);
                self::assertCount(1, $moved, $why . ': ' . \count($moved) . ' descriptors changed '
                    . 'flags across the call (' . implode(',', $moved) . '), not one, so this '
                    . 'measurement cannot say which descriptor it is about and every claim below '
                    . 'it is void. Zero means the call was a no-op; more than one means something '
                    . 'else in this process moved a flag between the two snapshots');
                $moved = $moved[0];

                self::assertSame(
                    self::FLAG,
                    $nonBlocking[$moved] & self::FLAG,
                    $why . ': making the stream non-blocking did not SET the flag on its descriptor, '
                        . 'so the convention this file enforces is wrong about this box',
                );
                self::assertSame(
                    0,
                    $blocking[$moved] & self::FLAG,
                    $why . ': making the stream blocking again did not CLEAR the flag',
                );
                self::assertSame(
                    $before[$moved],
                    $blocking[$moved],
                    $why . ': the descriptor did not come back to the flags it started with, so the '
                        . 'pair of calls is not the round trip this measurement assumes',
                );
            }

            // AND THE BRIDGE TO WHAT THE ROSTERED ASSERTIONS ACTUALLY READ.
            // Every site the census ranks reads `blocked`, not the descriptor,
            // so the equivalence has to be measured and not asserted by
            // definition: `blocked === false` is a set flag.
            self::assertFalse($metaNonBlocking, $why . ': a stream whose descriptor has the flag '
                . 'SET is not reported as non-blocking, so the census cannot read polarity off '
                . 'the assertion at all');
            self::assertTrue($metaBlocking, $why . ': a stream whose descriptor has the flag '
                . 'CLEARED is not reported as blocking');

        }

        fclose($pipes[0]);
        $childStderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($child);

        self::assertSame('', $childStderr, 'the child wrote to fd 2; its pipe is read here rather '
            . 'than discarded so that anything it says arrives as a failure and not as noise');

        fclose($file);
        fclose($socketPair[0]);
        fclose($socketPair[1]);
    }

    /**
     * NO FILE CONTRADICTS ITSELF WITHOUT A ROW SAYING SO, and no row outlives
     * the sentences it was written for.
     */
    public function testEveryContradictorySentenceIsOnTheRoster(): void
    {
        $this->assertTheScannerIsAlive();

        $inverted = [];
        $unreadable = [];

        foreach (self::filesInScope() as $relative => $absolute) {
            foreach (self::vocabularySites(self::readOrFail($absolute)) as [$line, $verdict]) {
                if ($verdict === 'inverted') {
                    $inverted[$relative][] = $line;
                }
                if ($verdict === 'unreadable') {
                    $unreadable[$relative][] = $line;
                }
            }
        }

        $unrostered = [];
        foreach ($inverted as $relative => $lines) {
            if (!isset(self::INVERTED_ROSTER[$relative])) {
                $unrostered[] = $relative . ':' . implode(',', $lines);
            }
        }

        self::assertSame([], $unrostered, 'a message beside an assertion on a stream\'s blocking '
            . 'state names the opposite flag direction from the one the assertion demands, and no '
            . 'row here says so. The convention is that the FLAG is set or cleared and the '
            . 'DESCRIPTOR is blocking or non-blocking; a stream reported as not blocked has the '
            . 'flag SET. Fix the sentence — never the assertion, which is correct in every known '
            . 'case — or add the file with the reason it cannot be fixed from your lane.');

        foreach (self::INVERTED_ROSTER as $relative => $row) {
            self::assertNotSame('', trim($row['why']), $relative . ' is rostered without a reason');
            self::assertSame(
                $row['sites'],
                \count($inverted[$relative] ?? []),
                $relative . ' no longer has the number of contradictory sentences its row claims. '
                    . 'IF THEY HAVE BEEN FIXED THIS IS THE SUCCESS CASE and the row must be '
                    . 'DELETED — every rostered file is owned by another lane, so this reds at a '
                    . 'merge rather than at an edit of this file, and the answer is a data edit '
                    . 'here, not a weaker check.',
            );
        }

        $unrosteredAmbiguous = [];
        foreach ($unreadable as $relative => $lines) {
            if (!isset(self::UNREADABLE_ROSTER[$relative])) {
                $unrosteredAmbiguous[] = $relative . ':' . implode(',', $lines);
            }
        }

        self::assertSame([], $unrosteredAmbiguous, 'a message beside a blocking-state assertion '
            . 'names BOTH flag directions, so it cannot be ranked. That is reported rather than '
            . 'skipped (rule 14): a sentence this scanner cannot read is a sentence it has '
            . 'stopped covering, which is indistinguishable from one it has cleared.');

        foreach (self::UNREADABLE_ROSTER as $relative => $row) {
            self::assertNotSame('', trim($row['why']), $relative . ' is rostered without a reason');
            self::assertSame(
                $row['sites'],
                \count($unreadable[$relative] ?? []),
                $relative . ' no longer has the number of unrankable sentences its row claims. '
                    . 'Delete or re-count the row; see the note on the inverted roster above for '
                    . 'why this reds at a merge.',
            );
        }
    }

    /**
     * KNOWN-ANSWER TABLE FOR THE SCANNER, in all three polarities.
     *
     * Rule 15: the assertions above are absences over a population, and an
     * absence is satisfied perfectly by a scanner that has stopped working.
     * Rule 25 one level down: a fixture expecting `[]` is satisfied by a
     * DELETED scanner too, so the table's load-bearing rows are the ones
     * expecting a verdict.
     *
     * ⚠️ EVERY FIXTURE IS BUILT BY CONCATENATION AND THE DIRECTION WORDS ARE
     * SPLIT (rule 26). A later sweep that rewrites the inverted vocabulary
     * across the suite must not be able to rewrite the fixtures that prove the
     * scanner can still see it — a regex cannot tell an offender from a
     * specimen of one, and this file is nothing but specimens.
     *
     * @dataProvider vocabularyCases
     */
    public function testTheScannerAnswersCorrectlyOnSentencesWhoseAnswerIsKnown(
        string $why,
        string $source,
        array $expected,
    ): void {
        $verdicts = [];
        foreach (self::vocabularySites($source) as [, $verdict]) {
            $verdicts[] = $verdict;
        }

        self::assertSame($expected, $verdicts, $why);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: list<string>}> */
    public static function vocabularyCases(): iterable
    {
        $flag = 'O_NON' . 'BLOCK';
        $clear = 'cle' . 'ar';
        $set = 's' . 'et';
        $back = 'ba' . 'ck';
        $meta = '$m[\'blo' . 'cked\']';

        $call = static fn (string $assert, string $message): string => "<?php\n"
            . 'self::' . $assert . '(' . $meta . ", '" . $message . "');\n";

        yield 'the demand-set assertion with a clearing verb is inverted' => [
            'the shape this whole file exists for was ranked as anything but inverted',
            $call('assertFalse', 'did not ' . $clear . ' ' . $flag),
            ['inverted'],
        ];
        yield 'the demand-cleared assertion with a restoring verb is inverted' => [
            'the other half of the pair was ranked as anything but inverted',
            $call('assertTrue', 'did not put ' . $flag . ' ' . $back),
            ['inverted'],
        ];
        yield 'the demand-set assertion with a setting verb is consistent' => [
            'a correct sentence was reported as a contradiction, so the fix has nowhere to land',
            $call('assertFalse', 'did not ' . $set . ' ' . $flag),
            ['consistent'],
        ];
        yield 'the demand-cleared assertion with a clearing verb is consistent' => [
            'the other correct pairing was reported as a contradiction',
            $call('assertTrue', 'did not ' . $clear . ' ' . $flag),
            ['consistent'],
        ];
        yield 'a sentence naming both directions is unreadable, not clean' => [
            'an unrankable sentence was ranked, so the scanner is guessing',
            $call('assertFalse', 'stopped ' . $clear . 'ing ' . $flag . ', or something ' . $clear . 'ed it ' . $back),
            ['unreadable'],
        ];
        yield 'a sentence naming no direction at all is unreadable' => [
            'a sentence with no direction word was passed as clean',
            $call('assertTrue', 'the ' . $flag . ' probe says nothing about which way'),
            ['unreadable'],
        ];
        yield 'an assertion that never names the flag is out of scope' => [
            'an assertion with no mention of the flag was ranked anyway',
            $call('assertFalse', 'the stream is blocking and it should not be'),
            [],
        ];
        // A CAPABILITY, AND IT WAS WRITTEN HERE AS A BOUND FIRST. The row
        // originally asserted `[]` — that a flag name split across a
        // concatenation is invisible — on the reasoning that the walk matches
        // literals as written. It is not: the walk joins every token's text
        // before matching, so the two halves meet. The fixture reddened on its
        // first run and the claim was corrected, which is the only reason a
        // paragraph does not now say the wrong thing (rule 8).
        yield 'a flag name split across a concatenation is still read' => [
            'a message whose flag name is split across two literals is no longer ranked, so any '
                . 'sentence can hide from this census by breaking one word in half',
            "<?php\nself::assertFalse(" . $meta . ", 'did not " . $clear . " ' . '" . $flag . "');\n",
            ['inverted'],
        ];
        yield 'a mention of the flag with no blocking assertion is out of scope' => [
            'a bare mention with no assertion around it was ranked, so prose is being graded',
            "<?php\n/** " . $flag . " is " . $clear . "ed here. */\n",
            [],
        ];
        // ⚠️ THIS ROW DOES NOT PIN THE COMMENT STRIP, AND ITS MESSAGE ONCE SAID
        // IT DID. The walk's only index read is `$tokens[$i + 1] === '('`;
        // everything after that is text concatenation, which a comment inside
        // the parens cannot break. Measured: with the strip mutated to keep
        // comments, and again with the whole strip disabled, this file stayed
        // green — the E228 shape, re-created two commits after the lane fixed
        // it elsewhere. The row is kept because reading past an interior
        // comment IS a capability worth a positive, and the row below is the
        // one that actually needs the strip.
        yield 'the flag named in a COMMENT inside the call is still read' => [
            'a comment between the parens hid the flag from the argument walk, so any message can '
                . 'leave this census by growing an inline comment',
            "<?php\nself::assertFalse(/* c */ " . $meta . ", 'did not " . $clear . ' ' . $flag . "');\n",
            ['inverted'],
        ];
        // THE STRIP'S OWN KNOWN-POSITIVE, in the position where it is
        // load-bearing: between the call name and its paren. Without the strip
        // `$tokens[$i + 1]` is the comment, the adjacency test fails and the
        // site is never found at all. This file is the SIXTH consumer of
        // DropsInsignificantTokensTrait and was the only one with no row that
        // reds when the strip stops working.
        yield 'a comment between the call name and its paren does not hide the site' => [
            'the site vanished when a comment sat between the call name and its paren, which is '
                . 'the one position where the shared token strip is what makes this walk work',
            "<?php\nself::assertFalse /* c */ (" . $meta . ", 'did not " . $clear . ' ' . $flag . "');\n",
            ['inverted'],
        ];

        // THE ADJACENCY GUARD, which nothing pinned. `$tokens[$i + 1] === '('`
        // is what stops the body walk starting from a `T_STRING` that is not a
        // call; mutating it to never skip SURVIVED the whole of
        // tests/Support tests/Cli tests/Config, because no such token exists in
        // the tree. It is a false-positive guard, so its survival was a
        // coverage gap and not a live defect — but an unpinned guard is one
        // refactor from being deleted as dead. The shape below is synthetic for
        // exactly that reason: without the guard the walk starts at the `;`,
        // runs on until the NEXT call's closing paren, and reports the real
        // site twice under two different line numbers.
        yield 'a bare mention of the call name is not itself a site' => [
            'the walk started a body scan from a token that is not a call, so the one real site '
                . 'below it is reported twice under two different line numbers',
            "<?php\n\$n = \$this->assertFalse;\nself::assertFalse(" . $meta
                . ", 'did not " . $clear . ' ' . $flag . "');\n",
            ['inverted'],
        ];

        // ── POLARITY. The four rows above are all NEGATED ("did not …"), and
        // that alphabet is what let rank() ship inverted in both directions for
        // the affirmative form (rule 11). All four affirmative combinations are
        // here, and each one reds if rank() stops consulting the polarity.
        yield 'an AFFIRMATIVE message with a clearing verb beside a demand-cleared assertion is inverted' => [
            'the shape of the tree\'s one affirmative site was ranked as anything but inverted. An '
                . 'affirmative message describes the state AT FAILURE, so beside an assertion '
                . 'demanding a cleared flag the correct verb is the SETTING one',
            $call('assertTrue', 'the bootstrap ' . $clear . 'ed ' . $flag . ' on a TERMINAL'),
            ['inverted'],
        ];
        yield 'an AFFIRMATIVE message with a setting verb beside a demand-cleared assertion is consistent' => [
            'the CORRECTED form of the tree\'s one affirmative site was still reported as a '
                . 'contradiction, so the fix for it has nowhere to land',
            $call('assertTrue', 'the bootstrap ' . $set . ' ' . $flag . ' on a TERMINAL'),
            ['consistent'],
        ];
        yield 'an AFFIRMATIVE message with a setting verb beside a demand-set assertion is inverted' => [
            'the other affirmative polarity was mis-ranked: beside an assertion demanding a SET '
                . 'flag, failure means the flag is cleared, so a message saying it was ' . $set . ' '
                . 'is naming the wrong direction',
            $call('assertFalse', 'the tty restore put ' . $flag . ' ' . $back),
            ['inverted'],
        ];
        yield 'an AFFIRMATIVE message with a clearing verb beside a demand-set assertion is consistent' => [
            'a correct affirmative sentence in the remaining polarity was reported as a contradiction',
            $call('assertFalse', 'something ' . $clear . 'ed ' . $flag . ' mid-run'),
            ['consistent'],
        ];
        yield 'a negation too far from the verb to govern it is unreadable, not guessed' => [
            'a sentence that is affirmative about the verb and negated about something else was '
                . 'ranked anyway. A bare search for a negation anywhere in the message calls this '
                . 'one negated and inverts its verdict silently (rule 14)',
            $call('assertTrue', 'the bootstrap ' . $clear . 'ed ' . $flag
                . ' on a tty, which the policy in this file does not allow'),
            ['unreadable'],
        ];
        yield 'a negation within reach of the verb still governs it across a few words' => [
            'the negation stopped reaching its verb across the words between them, so the tree\'s '
                . 'own `did not put ' . $flag . ' ' . $back . '` shape would stop being read',
            $call('assertTrue', 'did not ever once actually ' . $clear . ' ' . $flag),
            ['consistent'],
        ];
        yield 'a negation beyond NEGATION_REACH words of the verb is unreadable' => [
            'a negation five words from its verb was read as governing it, so the reach is not the '
                . 'bound this file says it is and the rule-14 branch is unreachable',
            $call('assertTrue', 'did not ever once really truly finally ' . $clear . ' ' . $flag),
            ['unreadable'],
        ];

        // ── WORD BOUNDARIES. Both rows red with their `\b` removed; before
        // they existed, removing either boundary SURVIVED the whole of
        // tests/Support tests/Cli tests/Config. See directionPatterns().
        yield 'a message that quotes stream_' . $set . '_blocking still names one direction' => [
            'the setting verb was matched inside the name of the call the message quotes, so a '
                . 'real inverted sentence was downgraded to unrankable and left the census',
            $call('assertFalse', 'did not ' . $clear . ' ' . $flag
                . ', though stream_' . $set . '_blocking(STDIN, false) ran'),
            ['inverted'],
        ];
        // THE ALPHABET'S OWN ROW. The setting side matched `set|sets|back|
        // restore[sd]?` while the clearing side matched all four of
        // `clear|clears|cleared|clearing`, so a message using the GERUND named
        // no direction at all and left the census as `unreadable`. Widening it
        // and then not fixturing it would be the same defect one level up
        // (rule 11): an alphabet element nothing exercises is indistinguishable
        // from one that was never added.
        yield 'the gerund of the setting verb names a direction, as the clearing gerund always did' => [
            'a message using the -ing form of the setting verb named NO direction, so it left the '
                . 'census as unrankable while the same sentence with the clearing gerund is ranked',
            $call('assertTrue', 'the bootstrap is ' . $set . 'ting ' . $flag . ' on a TERMINAL'),
            ['consistent'],
        ];
        yield 'a message containing the word un' . $clear . ' still names one direction' => [
            'the clearing verb was matched inside a longer word, with the same effect in the other '
                . 'direction',
            $call('assertTrue', 'did not ' . $set . ' ' . $flag . ', and the reason is un' . $clear),
            ['inverted'],
        ];
    }

    /**
     * KNOWN-POSITIVE CONTROL FOR THE WALK, in the same test that uses it to
     * assert an absence (rule 15).
     *
     * The table above proves the RANKING. This proves the walk still finds a
     * site at all when handed the real shape, which is the half a mutation of
     * the token loop would take out without touching the ranking.
     */
    private function assertTheScannerIsAlive(): void
    {
        $flag = 'O_NON' . 'BLOCK';
        $whole = "<?php\nself::assertFalse(\$m['blocked'], 'did not cle" . "ar {$flag}');\n";
        $found = self::vocabularySites($whole);

        self::assertCount(1, $found, 'the walk found no site in a source that carries one, so '
            . 'every "nothing is contradictory" answer above is a statement about a dead scanner');
        self::assertSame('inverted', $found[0][1]);
    }

    /**
     * Every blocking-state assertion in $source that names the flag, with its
     * verdict.
     *
     * @return list<array{0: int, 1: string}> 1-indexed line, verdict
     */
    private static function vocabularySites(string $source): array
    {
        $flag = 'O_NON' . 'BLOCK';
        $tokens = self::significantTokens($source);
        $count = \count($tokens);
        $found = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token) || $token[0] !== \T_STRING) {
                continue;
            }
            if ($token[1] !== 'assertTrue' && $token[1] !== 'assertFalse') {
                continue;
            }
            if (($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }

            $depth = 0;
            $body = '';
            for ($j = $i + 1; $j < $count; $j++) {
                $text = \is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                if ($text === '(' || $text === '[') {
                    $depth++;
                }
                if ($text === ')' || $text === ']') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
                $body .= $text . ' ';
            }

            if (!str_contains($body, $flag) || !str_contains($body, 'blocked')) {
                continue;
            }

            $found[] = [$token[2], self::rank($token[1] === 'assertFalse', $body)];
        }

        return $found;
    }

    /**
     * Whether $body's direction words agree with the flag state the assertion
     * demands.
     *
     * `$wantsSet` is true for the assertion that demands a NON-blocking stream,
     * because non-blocking is the flag set.
     *
     * 🔴 THE DEMANDED DIRECTION IS NOT THE CORRECT VERB — THE MESSAGE'S
     * GRAMMATICAL POLARITY DECIDES WHICH IS. A failure message is read at the
     * moment the assertion FAILS, so the two shapes point opposite ways:
     *
     *  - NEGATED — `assertFalse($m['blocked'], 'did not <verb> the flag')`.
     *    The sentence names the act the assertion demanded and reports it
     *    missing, so the correct verb IS the demanded direction.
     *  - AFFIRMATIVE — `assertTrue($m['blocked'], 'X <verb>ed the flag')`.
     *    The sentence describes the state AT FAILURE, which is the OPPOSITE of
     *    what the assertion demanded, so the correct verb is the opposite one.
     *
     * The first version of this method graded every message against the
     * demanded direction alone. That is right for the negated form and exactly
     * backwards for the affirmative one — wrong in BOTH polarities, not merely
     * blind to one — and it false-cleaned the tree's one affirmative site
     * (`tests/SuiteChildStdinIsolationTest.php`, the TERMINAL arm), reporting
     * `consistent` for a sentence that is inverted and reddening if anybody
     * fixed it. It survived its own known-answer table because all four
     * polarity rows there were negated: the shape it mis-ranked was outside
     * the table's alphabet by construction (rule 11).
     *
     * @see negatesTheVerb() for what happens when the polarity cannot be read
     */
    private static function rank(bool $wantsSet, string $body): string
    {
        $named = [];
        foreach (self::directionPatterns() as $direction => $pattern) {
            if (preg_match($pattern, $body) === 1) {
                $named[$direction] = $pattern;
            }
        }

        if (\count($named) !== 1) {
            return 'unreadable';
        }

        $direction = array_key_first($named);
        $negated   = self::negatesTheVerb($body, $named[$direction]);

        if ($negated === null) {
            return 'unreadable';
        }

        // NEGATED: the correct verb is the demanded direction. AFFIRMATIVE: the
        // opposite. Which is exactly `$negated === $wantsSet`.
        $correct = $negated === $wantsSet ? 'set' : 'cleared';

        return $direction === $correct ? 'consistent' : 'inverted';
    }

    /**
     * The two direction vocabularies, keyed by the flag state each names.
     *
     * WORD BOUNDARIES — REWRITTEN AGAINST A MEASUREMENT (rule 7).
     *
     * WHAT IT SAID: "The verbs are matched on word boundaries: without them the
     * setting verb matches inside `offset` and every message in the suite would
     * name both directions."
     *
     * WHAT IS TRUE NOW: that was never measured and it is false. Pushing the
     * real population (`tests` + `src`, 10 ranked sites, PHP 8.3.6) through a
     * byte-copy of this pair with every `\b` removed changes 0 of 10 verdicts,
     * and mutating each `\b` pair out of this file individually SURVIVED the
     * whole of `tests/Support tests/Cli tests/Config`. The word `offset`
     * appears in no ranked message.
     *
     * WHY THEY STILL EARN THEIR PLACE: the shape that needs them has not
     * arrived yet but is one edit away — a message that quotes the call it is
     * about. `stream_set_blocking(STDIN, false)` contains the setting verb and
     * `unclear` contains the clearing one, so a sentence naming either would,
     * without the boundaries, name both directions and be downgraded from
     * `inverted` to `unreadable` — a real site quietly leaving the census. That
     * is no longer an argument: two rows of {@see vocabularyCases()} are those
     * two sentences, and each one reds if its boundary is removed.
     *
     * The setting side also spells `setting`, which it did not: it matched
     * `set|sets|back|restore[sd]?` while the clearing side matched all four of
     * `clear|clears|cleared|clearing`. Nothing in the population turns on it —
     * the asymmetry is closed because a reader would not expect it, not
     * because a site was escaping.
     *
     * @return array<string,string> flag state => pattern that names it
     */
    private static function directionPatterns(): array
    {
        return [
            'cleared' => '/\b' . 'cle' . 'ar(?:s|ed|ing)?\b/i',
            'set'     => '/\b(?:' . 's' . 'et(?:s|ting)?|ba' . 'ck|restore[sd]?)\b/i',
        ];
    }

    /**
     * At most this many words may sit between a negation and the verb it is
     * read as governing.
     *
     * MEASURED, not chosen: the widest gap in today's population is TWO words,
     * in `restore() did not put O_NONBLOCK back` — the negation and the verb
     * are separated by `put O_NONBLOCK`. Every other ranked site has a gap of
     * zero. Four is that plus headroom, and a wider gap is reported as
     * `unreadable` rather than guessed at.
     */
    private const NEGATION_REACH = 4;

    /**
     * Whether a negation governs the direction verb $verbPattern found in
     * $body: true for a negated message, false for an affirmative one, and
     * NULL when $body carries a negation this cannot attach to the verb.
     *
     * RULE 14 LIVES IN THE NULL. "There is a negation somewhere in the sentence
     * but not near the verb" is precisely the shape a positional rule gets
     * wrong — `the bootstrap cleared the flag, which is not allowed` is
     * affirmative about the verb and negated about the consequence — so it is
     * reported as unrankable rather than resolved by whichever rule happened to
     * be written first. A bare `str_contains($body, 'not')` would have called
     * that sentence negated and inverted its verdict silently.
     *
     * The reach is deliberately forward-only and short {@see NEGATION_REACH}. A
     * marker AFTER the verb never negates it here, because the two orderings
     * that would need are `never <verb>` (already forward) and a trailing
     * clause about something else.
     */
    private static function negatesTheVerb(string $body, string $verbPattern): ?bool
    {
        $negation = '/\b(?:(?:did|does|do|is|are|was|were|has|have|had|can|could|will|would|should|must)'
            . '\s*n(?:o|\')t|never|no\s+longer|fail(?:s|ed)?\s+to|stopped)\b/i';

        if (preg_match_all($negation, $body, $markers, \PREG_OFFSET_CAPTURE) < 1) {
            return false;
        }

        foreach ($markers[0] as [$text, $at]) {
            $tail = substr($body, $at + \strlen($text));
            if (preg_match($verbPattern, $tail, $hit, \PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $between = preg_split('/\s+/', trim(substr($tail, 0, $hit[0][1])), -1, \PREG_SPLIT_NO_EMPTY);
            if (\count($between ?: []) <= self::NEGATION_REACH) {
                return true;
            }
        }

        return null;
    }

    /** @return array<int,int> descriptor => flags */
    private static function flagSnapshot(): array
    {
        $out = [];
        foreach (scandir('/proc/self/fd') ?: [] as $entry) {
            if (!ctype_digit($entry)) {
                continue;
            }
            $text = @file_get_contents('/proc/self/fdinfo/' . $entry);
            if (!\is_string($text)) {
                continue;
            }
            foreach (explode("\n", $text) as $line) {
                if (str_starts_with($line, 'flags:')) {
                    $out[(int) $entry] = (int) octdec(trim(substr($line, 6)));
                }
            }
        }

        return $out;
    }

    /**
     * Every descriptor whose flags differ between two snapshots.
     *
     * Returns the LIST and not "the sole one or null" so the caller can say how
     * many it found: zero and several are different failures and the message
     * that told a reader only "no descriptor's flags changed" was wrong in the
     * second case.
     *
     * @param array<int,int> $before
     * @param array<int,int> $after
     *
     * @return list<int>
     */
    private static function movedDescriptors(array $before, array $after): array
    {
        $moved = [];
        foreach ($after as $fd => $flags) {
            if (($before[$fd] ?? null) !== null && $before[$fd] !== $flags) {
                $moved[] = $fd;
            }
        }

        return $moved;
    }

    /** @return array<string,string> relative path => absolute path */
    private static function filesInScope(): array
    {
        $root = \dirname(__DIR__, 2);
        $found = [];

        foreach (self::SCOPE as $entry) {
            /** @var \SplFileInfo $info */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $entry)) as $info) {
                if ($info->isFile() && $info->getExtension() === 'php') {
                    $found[substr($info->getPathname(), \strlen($root) + 1)] = $info->getPathname();
                }
            }
        }
        ksort($found);

        return $found;
    }
}

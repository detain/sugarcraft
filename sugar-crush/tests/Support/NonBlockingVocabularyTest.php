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
 * a set flag and `assertTrue($meta['blocked'], …)` demands a cleared one, and
 * the verdict is whether the message names the other direction. A `grep` for
 * the token sees ten sites and can rank none of them.
 *
 * RULE 14: A SITE WHOSE DIRECTION THIS CANNOT DECIDE IS `unreadable`, NOT
 * CLEAN. One real site names BOTH directions in one sentence, which is a
 * different defect from naming the wrong one and is rostered as its own kind
 * rather than being quietly counted as fine.
 *
 * WHAT IT DELIBERATELY CANNOT SEE. Prose that discusses the flag without an
 * assertion beside it — `tests/bootstrap.php`'s measured table uses "clear" to
 * mean *non-blocking*, two paragraphs after using the flag sense, and no
 * assertion pairs with it. A sentence with no assertion has no polarity to
 * contradict, so it is outside this alphabet by construction rather than by
 * oversight; that one wants a reader, not a scanner.
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
        'tests/SuiteChildStdinIsolationTest.php' => [
            'sites' => 1,
            'why' => 'One sentence names BOTH directions: it asks whether the bootstrap stopped '
                . 'doing one thing OR whether something in the run undid it, and the two verbs '
                . 'point opposite ways. The wording IS in the inverted vocabulary — it uses the '
                . 'clearing verb for the act that sets the flag — but a direction-word scanner '
                . 'that picked one of two would be reporting a coin flip, so it reports the '
                . 'ambiguity instead. Out of this lane.',
        ],
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
     * @requires OS Linux
     */
    public function testTheFlagDirectionIsWhatTheVocabularyHereSaysItIs(): void
    {
        if (!is_dir('/proc/self/fdinfo')) {
            self::markTestSkipped('no /proc/self/fdinfo: the flag cannot be read on this box');
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

            $before = self::flagSnapshot();
            stream_set_blocking($stream, false);
            $nonBlocking = self::flagSnapshot();
            $metaNonBlocking = stream_get_meta_data($stream)['blocked'];
            stream_set_blocking($stream, true);
            $blocking = self::flagSnapshot();
            $metaBlocking = stream_get_meta_data($stream)['blocked'];

            $moved = self::soleMovedDescriptor($before, $nonBlocking);
            self::assertNotNull($moved, $why . ': no descriptor\'s flags changed, so this '
                . 'measurement is about a descriptor it never found and every claim below is void');

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
        yield 'the flag named in a COMMENT inside the call is still read' => [
            'the comment strip is what makes the argument walk see past a comment; it did not',
            "<?php\nself::assertFalse(/* c */ " . $meta . ", 'did not " . $clear . ' ' . $flag . "');\n",
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
     * because non-blocking is the flag set. The verbs are matched on word
     * boundaries: without them the setting verb matches inside `offset` and
     * every message in the suite would name both directions.
     */
    private static function rank(bool $wantsSet, string $body): string
    {
        $says = [];
        if (preg_match('/\b' . 'cle' . 'ar(?:s|ed|ing)?\b/i', $body) === 1) {
            $says['cleared'] = true;
        }
        if (preg_match('/\b(?:' . 's' . 'et|sets|ba' . 'ck|restore[sd]?)\b/i', $body) === 1) {
            $says['set'] = true;
        }

        if (\count($says) !== 1) {
            return 'unreadable';
        }

        return isset($says['set']) === $wantsSet ? 'consistent' : 'inverted';
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
     * The one descriptor whose flags differ between two snapshots, or null when
     * that is not exactly one.
     *
     * @param array<int,int> $before
     * @param array<int,int> $after
     */
    private static function soleMovedDescriptor(array $before, array $after): ?int
    {
        $moved = [];
        foreach ($after as $fd => $flags) {
            if (($before[$fd] ?? null) !== null && $before[$fd] !== $flags) {
                $moved[] = $fd;
            }
        }

        return \count($moved) === 1 ? $moved[0] : null;
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

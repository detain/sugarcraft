<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * A FIXTURE PROGRAMMED TO OUTLIVE THE SUITE MUST HAVE ITS LIFETIME ARGUED.
 *
 * E505, and the sentence the row started from is the alarm math: a fixture
 * whose lifetime is 120 s while `phpunit.xml` caps each test at
 * `defaultTimeLimit="60"` can only be collected by the reaper path, never by
 * the clock - and `pcntl_alarm` is not inherited across `pcntl_fork()`
 * ({@see ReapsForkedChildrenTrait}), so the alarm that bounds the TEST does
 * not bound the CHILD. Done in-file once already (the wedge tests' own
 * `FIXTURE_LIFETIME = 8.0` conversion); what this guard adds is the census
 * the fix needed and never got: the seven sites that DO sit past the limit,
 * each with the mechanism that bounds it, checked in both directions so a
 * new un-argued fixture of any size reds here at the author's own run rather
 * than as an orphan on a CI box.
 *
 * WHY THE ALPHABET IS NARROW ON PURPOSE. This is a census, not a proof:
 * it sees a literal `sleep(<n>)` or `<...sleep(n)...>` in a fixture STRING
 * or heredoc, and a `*LIFETIME` / `*_SECONDS` constant with a literal value
 * at or over the limit. It deliberately does not see arithmetic
 * (`sleep(60 * 20)`), `usleep` (which no test uses as a lifetime - the
 * sub-second ones are pumps, and misfiring on them would train people to
 * ignore this guard), env-var-fed lifetimes, or `while` loops written in
 * CODE rather than in fixture text (parent-side busy-waits belong to
 * {@see ChildWallClockBudgetTest}, which watches the runtime of every test
 * against this same limit; a census over code-time would double-count that
 * and red the wrong file). Each hole was weighed against false positives on
 * the live tree before being kept; the three fixtures at `sleep(30)` that
 * triage E505 names are below the limit by design and not roster rows -
 * their files carry the argument inline.
 *
 * THE DIRECTION THE FIRST PROBE GOT WRONG, recorded because it is the exact
 * mistake a reader of this file is most likely to repeat: word-boundary
 * `\bsleep` in a fixture STRING does not match the `\n`-escaped heredoc
 * bodies the probes were grepping, and `sleep` alone matches `usleep`
 * everywhere. The token walk below uses the literal-substring test PHP
 * applies inside string tokens and the `u`-prefix guard only where the
 * alphabet says it matters.
 *
 * THE SAME RULES AS THE OTHER ROSTERS: the derived set must EQUAL the
 * licensed set - a site that shrank or disappeared makes its row a stale
 * claim (delete it, celebrate, or re-argue upward), and a row that a future
 * grep could pass without the file changing would license the next fixture.
 * The value is pinned as well as the existence, so `sleep(120)` silently
 * becoming `sleep(3600)` is as red as a brand-new fixture, and
 * {@see testTheWalkIsAliveOnKnownPositives()} re-proves the instrument on a
 * synthetic tree whose answers are written down - a census that quietly
 * stopped matching is the failure mode all of these guards fear most.
 */
final class FixtureLifetimeCensusTest extends TestCase
{
    /**
     * Every fixture lifetime at or over the suite time limit: keyed by
     * `<tests-relative-path>::<label>`, where the label is the function
     * holding the literal (code or fixture-text alike), the nearest
     * preceding `const` for fixture text outside a function, or the constant
     * name for a `*LIFETIME` / `*_SECONDS` constant declaration.
     *
     * Each row says what bounds the fixture in practice, because the whole
     * point of the row is that the per-test alarm does not: the killer is
     * the mechanism named here.
     *
     * @var array<string,array{value:float,reason:string}>
     */
    private const LICENSED_LIFETIMES = [
        'Agents/AgentWorkerPoolTest.php::testDestructorStopsItsWorkersBeforeDeletingTheDirectoryTheyWriteInto' => [
            'value' => 120.0,
            'reason' =>
                'A child that sleeps past the 60 s alarm BY DESIGN: the test exists to prove the '
                . 'pool destructor reaps workers it cannot wait for, and a child that finished on '
                . 'time would prove nothing. The wall-clock bound is the test\'s own '
                . 'stream_select(10 s) + SIGTERM/KILL escalation ladder, not the alarm.',
        ],
        'Agents/AgentWorkerPoolTest.php::forkSleepingChild' => [
            'value' => 120.0,
            'reason' =>
                'The same construction shared by the signal-attribution rows: the helper names '
                . 'itself, its callers reapevery child through the pool destructor - and the '
                . 'adoption guard ({@see ForkedChildReaperAdoptionTest}) is what keeps those '
                . 'callers holding a reaper.',
        ],
        'LSP/LspConnectionStdinWedgeTest.php::longLivedDeafServerScript' => [
            'value' => 120.0,
            'reason' =>
                'Fixture TEXT written to disk and run as its own process - the point of a '
                . 'stdin-wedge server is to outlive every handshake budget in the test, and the '
                . 'file\'s own kill path (`killServer()` + BOUND_SECONDS watches) stops it long '
                . 'before the sleep returns. E505 converted the neighbouring servers to '
                . 'FIXTURE_LIFETIME = 8.0; this one keeps the long number because the test '
                . 'asserts the wedge while it is sleeping.',
        ],
        'LSP/LspConnectionStdinWedgeTest.php::STORM_PROBE_TEMPLATE' => [
            'value' => 120.0,
            'reason' =>
                'The probe-hammer loop ceiling inside the template - a monotonic-clock bound of '
                . '120.0 on a busy loop, not a sleep. The parent stops reading, the probe exits '
                . 'when the pipe dies; STORM_BOUND_SECONDS = 45 is the in-test read deadline. '
                . 'Same file, so the two numbers are one argument: the ceiling is 45 + margin '
                . 'for the slowest box, not a fixture that must be reaped.',
        ],
        'MCP/StdioMcpServerHandshakeTest.php::SILENT_SERVER' => [
            'value' => 3600.0,
            'reason' =>
                'The silent server must still be alive at the end of the handshake window to '
                . 'prove the timeout is what ended it; it is killed by the test\'s '
                . 'startTimeoutSeconds teardown long before the hour. An hour-long sleep here is '
                . 'the idiom for "sleep until killed", and its row exists to keep the idiom '
                . 'from spreading unargued.',
        ],
        'MCP/StdioMcpServerWriteBoundsTest.php::STORM_PROBE_TEMPLATE' => [
            'value' => 120.0,
            'reason' =>
                'The write-bounds file\'s own copy of the probe-hammer ceiling (`while (... < '
                . '120.0)`), the same construction as its LSP sibling above - the census caught '
                . 'this one precisely by refusing to trust the measurement that missed it. '
                . 'Bounded in the file that runs it: runBounded(..., STORM_BOUND_SECONDS), 45 s.',
        ],
        'MCP/StdioMcpServerWriteBoundsTest.php::REACHABILITY_PROBE' => [
            'value' => 60.0,
            'reason' =>
                'A GRANDCHILD sleeping a minute while the test measures the child\'s write '
                . 'bounds; the recorded E539 disposition - this tree stops the child via '
                . 'proc_terminate, and the grandchild rides the same process group. One of the '
                . 'three fixtures E539 kept rather than tightened.',
        ],
        'MCP/StdioMcpServerWriteBoundsTest.php::DEAF_SERVER_LIFETIME_SECONDS' => [
            'value' => 90.0,
            'reason' =>
                'The named-constant sibling of the probe above - deliberately twice '
                . 'STORM_BOUND_SECONDS again so a server can only die deaf if deafness is what '
                . 'killed it. E539 recorded the mutation that proves the shorter value '
                . 'red-falsifies the test, which is why the row pins 90 rather than waiving '
                . 'the constant.',
        ],
    ];

    /**
     * The per-test wall-clock limit, as an assertion target for
     * {@see testTheCensusThresholdIsTheSuiteTimeLimit()} - see
     * {@see suiteTimeLimitSeconds()} for the reader.
     */
    public function testTheCensusThresholdIsTheSuiteTimeLimit(): void
    {
        $limit = $this->suiteTimeLimitSeconds();

        $this->assertSame(
            60.0,
            $limit,
            'phpunit.xml moved defaultTimeLimit off 60 - re-measure LICENSED_LIFETIMES against '
                . 'the new threshold before trusting either number.',
        );
    }

    public function testEveryFixtureLifetimeOverTheSuiteLimitIsLicensed(): void
    {
        $derived = self::lifetimesAtOrOver(
            \dirname(__DIR__),
            $this->suiteTimeLimitSeconds(),
        );

        $offenders = [];
        foreach ($derived as $key => $hit) {
            $licensed = self::LICENSED_LIFETIMES[$key] ?? null;
            if ($licensed === null) {
                $offenders[] = $key . ' = ' . $hit['value'] . 's is unlicensed';
            } elseif ($licensed['value'] !== $hit['value']) {
                $offenders[] = $key . ' is licensed at ' . $licensed['value']
                    . 's but measured ' . $hit['value'] . 's - a lifetime that moved was not re-argued';
            }
        }

        $rotten = [];
        foreach (self::LICENSED_LIFETIMES as $key => $licensed) {
            if (!isset($derived[$key])) {
                $rotten[] = $key . ' is licensed at ' . $licensed['value'] . 's and the walk finds nothing';
            }
            $this->assertNotSame(
                '',
                trim($licensed['reason']),
                $key . ' is licensed without a reason',
            );
        }

        $this->assertSame(
            [],
            $offenders,
            'an unlicensed fixture (or a moved value) sits at or over the per-test time limit: a '
                . 'child that outlives defaultTimeLimit is collected only by whatever reaper the '
                . 'owning test wired, so new here means unbounded the day that test forgets to '
                . 'kill. Either bound the fixture, or add its site to LICENSED_LIFETIMES with the '
                . 'mechanism that actually stops it. ' . print_r($offenders, true),
        );

        $this->assertSame(
            [],
            $rotten,
            'a licensed lifetime no longer exists - delete the row so the reason does not become '
                . 'a claim about code that has left the tree. ' . print_r($rotten, true),
        );
    }

    /**
     * THE INSTRUMENT IS ALIVE, proved on a synthetic tree whose answer is
     * written down - including the polarities: below-limit sleeps, `usleep`,
     * non-lifetime `*_SECONDS` (the VIRTUAL_SECONDS_PER_REAL_SECOND false
     * positive the first probe produced and the name rule excludes) and
     * arithmetic lifetimes are all ASSERTED INVISIBLE, so the day the
     * alphabet widens, this test reds and says so rather than silently
     * drafting somebody with a new offender list.
     */
    public function testTheWalkIsAliveOnKnownPositives(): void
    {
        $root = sys_get_temp_dir() . '/sc_fixture_lifetime_census_' . bin2hex(random_bytes(6));
        $dir = $root . '/Fixture';
        if (!mkdir($dir, 0o700, true) && !is_dir($dir)) {
            self::fail('could not create the known-positive fixture tree at ' . $dir);
        }

        $write = static function (string $name, string $body) use ($dir): void {
            file_put_contents($dir . '/' . $name, "<?php\n\nfinal class F\n{\n" . $body . "\n}\n");
        };

        // THE VALUES ARE BUILT, NOT WRITTEN, and the reason is this very
        // guard: a literal `sleep(4242)` inside a string token of THIS file
        // is fixture text by the census's own definition, and the census
        // would be right to flag it. Concatenation keeps the digits in the
        // generated files - which is what the walk reads - and out of this
        // source, which is what the walk must not read.
        $overCode = 4242;
        $overText = 999;
        $overConst = 7777;
        $arithmetic = '100 * 100';

        // POSITIVES - all three sources of the alphabet.
        $write('OverLimitCode.php', '    public function testOne(): void' . "\n"
            . "    {\n        \$pid = pcntl_fork();\n        if (\$pid === 0) { sleep("
            . $overCode . "); }\n    }\n");
        $write('OverLimitText.php', '    private const LOUD_SERVER = <<<' . "'PHP'\n"
            . "        <?php\n        sleep(" . $overText . ");\n        PHP;\n");
        $write('OverLimitConst.php', '    private const WORKER_LIFETIME_SECONDS = ' . $overConst . ";\n");

        // NEGATIVES - each the hole the class doc-block names, pinned.
        $write('UnderLimit.php', "    public function testTwo(): void\n"
            . "    {\n        sleep(5);\n    }\n");
        $write('NotSleep.php', "    public function testThree(): void\n"
            . "    {\n        usleep(40000000);\n    }\n");
        $write('NotALifetime.php', "    private const VIRTUAL_SECONDS_PER_REAL_SECOND = 500000.0;\n");
        $write('Arithmetic.php', "    public function testFour(): void\n"
            . '    {' . "\n" . '        sleep(' . $arithmetic . ");\n    }\n");

        try {
            $found = self::lifetimesAtOrOver($root, 60.0);
        } finally {
            foreach (glob($dir . '/*.php') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
            @rmdir($root);
        }

        $this->assertSame(
            [
                'Fixture/OverLimitCode.php::testOne',
                'Fixture/OverLimitConst.php::WORKER_LIFETIME_SECONDS',
                'Fixture/OverLimitText.php::LOUD_SERVER',
            ],
            array_keys($found),
            'the fixture-lifetime walk did not find exactly the known positives - the census above '
                . 'is only as trustworthy as this list',
        );
        $this->assertSame((float) $overCode, $found['Fixture/OverLimitCode.php::testOne']['value']);
        $this->assertSame((float) $overText, $found['Fixture/OverLimitText.php::LOUD_SERVER']['value']);
        $this->assertSame(
            (float) $overConst,
            $found['Fixture/OverLimitConst.php::WORKER_LIFETIME_SECONDS']['value'],
        );
    }

    /**
     * The per-test wall-clock limit, READ from `phpunit.xml` rather than
     * repeated as a literal - this census is only true while it agrees with
     * the alarm that actually fires. Named differently from
     * {@see ChildWallClockBudgetTest}'s own reader because the guard family
     * Read rather than hard-coded, but pinned by
     * {@see testTheCensusThresholdIsTheSuiteTimeLimit()} so the day the alarm
     * moves, the census re-measures instead of silently mis-licensing. (Named
     * differently from {@see ChildWallClockBudgetTest}'s own reader because
     * the guard family shares no helper: the sibling guard measures runtime,
     * this one censuses source text.)
     */
    private function suiteTimeLimitSeconds(): float
    {
        $xml = (string) file_get_contents(\dirname(__DIR__, 2) . '/phpunit.xml');
        if (!\preg_match('/defaultTimeLimit="([0-9]+)"/', $xml, $m)) {
            self::fail('phpunit.xml carries no plain-integer defaultTimeLimit to read');
        }

        return (float) $m[1];
    }

    /**
     * Every fixture lifetime at or over $limit under $root, keyed as
     * {@see LICENSED_LIFETIMES}.
     *
     * @return array<string,array{value:float,line:int}>
     */
    private static function lifetimesAtOrOver(string $root, float $limit): array
    {
        $hits = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen($root) + 1);
            foreach (self::lifetimesInSource((string) file_get_contents($file->getPathname()), $limit) as $key => $hit) {
                $hits[$relative . '::' . $key] = $hit;
            }
        }

        ksort($hits);

        return $hits;
    }

    /**
     * One file's over-limit lifetimes, keyed by the LABEL of the site that
     * carries the number: the function holding the literal, else the nearest
     * preceding `const` (fixture text written as a class constant), else the
     * constant's own name, else `<top>`.
     *
     * @return array<string,array{value:float,line:int}>
     */
    private static function lifetimesInSource(string $source, float $limit): array
    {
        $tokens = \token_get_all($source);
        $functions = TokenFunctionRanges::scan($tokens);

        /** @var array<int,array{name:string,value:?float,line:int}> $consts */
        $consts = [];
        /** @var array<int,int> $constAt maps token index -> consts index */
        $constAt = [];
        for ($i = 0, $n = \count($tokens); $i < $n; ++$i) {
            $token = $tokens[$i];
            if (!\is_array($token) || $token[0] !== \T_CONST) {
                continue;
            }
            $nameIndex = self::nextMeaningful($tokens, $i);
            if ($nameIndex === null || !\is_array($tokens[$nameIndex]) || $tokens[$nameIndex][0] !== \T_STRING) {
                continue;
            }
            // The VALUE is parsed past the `=` when there is a plain literal
            // there; a const whose value is an expression still gets a name,
            // because fixture TEXT inside that const's heredoc is labelled BY
            // the const name (the script IS the constant) regardless of
            // whether the declaration itself is a lifetime.
            $assign = self::nextMeaningful($tokens, $nameIndex);
            $value = $assign !== null && self::text($tokens[$assign]) === '='
                ? self::numericValueAfter($tokens, $assign)
                : null;
            $consts[$i] = ['name' => $tokens[$nameIndex][1], 'value' => $value, 'line' => $token[2]];
            $constAt[$i] = $i;
        }

        /** @var array<string,array{value:float,line:int}> $hits */
        $hits = [];

        $record = static function (string $key, float $value, int $line) use (&$hits): void {
            // One row per key; the LONGEST lifetime is the row's value, so
            // the roster argues against the worst case rather than the
            // accident of token order.
            if (!isset($hits[$key]) || $hits[$key]['value'] < $value) {
                $hits[$key] = ['value' => $value, 'line' => $line];
            }
        };

        $nearestConst = static function (int $index) use ($consts): ?string {
            $best = null;
            foreach ($consts as $at => $const) {
                if ($at <= $index && ($best === null || $at > $best)) {
                    $best = $at;
                }
            }

            return $best === null ? null : $consts[$best]['name'];
        };

        foreach ($tokens as $i => $token) {
            if (!\is_array($token)) {
                continue;
            }

            // LITERAL SLEEP IN CODE. `usleep` is a single different T_STRING,
            // so the exact-name test is the whole u-prefix guard here; in
            // strings, below, it is a lookbehind because there are no tokens.
            if ($token[0] === \T_STRING && $token[1] === 'sleep') {
                $call = self::nextMeaningful($tokens, $i);
                if ($call !== null && self::text($tokens[$call]) === '(') {
                    $value = self::numericValueAfter($tokens, $call);
                    if ($value !== null && $value >= $limit) {
                        $fn = TokenFunctionRanges::enclosing($functions, (int) $i)['name'] ?? null;
                        $record($fn ?? ($nearestConst((int) $i) ?? '<top>'), $value, $token[2]);
                    }
                }

                continue;
            }

            // FIXTURE TEXT: the literal lives inside a string/heredoc token a
            // server script is assembled from. Two alphabets share this
            // branch: a written `sleep(<n>)`, and a written `while (... < n)`
            // ceiling - the probe-hammer idiom, which carries the same "runs
            // past the alarm" hazard as a long sleep. A `while` ceiling is a
            // `while` token in CODE = a busy-wait = ChildWallClockBudgetTest's
            // runtime domain; here it is only ever text.
            if (\in_array($token[0], [\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE, \T_START_HEREDOC], true)) {
                $matched = \preg_match_all('/(?<!u)sleep\s*\(\s*([0-9]+(?:\.[0-9]+)?)/', $token[1], $a) > 0
                    || \preg_match_all('/while[^;{]*?<=?\s*([0-9]+(?:\.[0-9]+)?)/', $token[1], $a) > 0;
                if (!$matched) {
                    continue;
                }
                $value = null;
                foreach ($a[1] as $captured) {
                    $candidate = (float) $captured;
                    if ($candidate >= $limit && ($value === null || $candidate > $value)) {
                        $value = $candidate;
                    }
                }
                if ($value === null) {
                    continue;
                }

                // Label rule, stated here because the doc-block's version is
                // easy to misread: text INSIDE a function is that function's
                // (it is what builds the script); text outside any function
                // belongs to the constant being declared (the script IS the
                // constant), and a bare top-level string gets `<top>`.
                $fn = TokenFunctionRanges::enclosing($functions, (int) $i)['name'] ?? null;
                $record($fn ?? ($nearestConst((int) $i) ?? '<top>'), $value, $token[2]);

                continue;
            }

            // NAMED LIFETIME CONSTANT: `*LIFETIME` / `*_SECONDS` with a
            // literal value. The suffix is the alphabet's claim - a name like
            // VIRTUAL_SECONDS_PER_REAL_SECOND ENDS in `_SECOND`, which the
            // anchored pattern refuses, and that refusal is pinned as a
            // NEGATIVE in the alive-test rather than argued in prose.
            if (isset($constAt[$i])) {
                $const = $consts[$i];
                if ($const['value'] !== null
                    && \preg_match('/(LIFETIME|_SECONDS)$/', $const['name']) === 1
                    && $const['value'] >= $limit) {
                    $record($const['name'], $const['value'], $const['line']);
                }
            }
        }

        return $hits;
    }

    /**
     * The next token that carries text, skipping whitespace and comments.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function nextMeaningful(array $tokens, int $index): ?int
    {
        for ($i = $index + 1, $n = \count($tokens); $i < $n; ++$i) {
            $token = $tokens[$i];
            if (\is_array($token) && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * The literal number at the next meaningful position, or null when the
     * argument is not a PLAIN numeric literal - a literal followed by
     * anything but a terminator is an expression (`sleep(60 * 20)`,
     * `sleep($x + 1)`): the walk declines to GUESS rather than
     * half-evaluate it, and the alive-test pins that the guess is never
     * counted silently.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function numericValueAfter(array $tokens, int $index): ?float
    {
        $next = self::nextMeaningful($tokens, $index);
        if ($next === null || !\is_array($tokens[$next])
            || !\in_array($tokens[$next][0], [\T_LNUMBER, \T_DNUMBER], true)) {
            return null;
        }

        // The number must STAND ALONE: `)` or `,` for a call argument, `;`
        // for a constant. `100 * 100` continues with `*` and is refused, so
        // the first operand of an expression is never mistaken for the
        // whole value - which is exactly how the arithmetic hole stays a
        // documented hole rather than a random mis-read.
        $after = self::nextMeaningful($tokens, $next);
        if ($after === null || !\in_array(self::text($tokens[$after]), [')', ',', ';'], true)) {
            return null;
        }

        return (float) $tokens[$next][1];
    }

    /** @param array{0:int,1:string,2:int}|string $token */
    private static function text(array|string $token): string
    {
        return \is_array($token) ? $token[1] : $token;
    }
}

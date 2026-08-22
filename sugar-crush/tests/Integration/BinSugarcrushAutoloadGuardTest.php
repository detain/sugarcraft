<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\ArgvParser;
use SugarCraft\Crush\Cli\NonInteractive;

/**
 * The ONE launch warning in this project that provably cannot be migrated onto
 * the transcript seam, and — until round 42 (E78) — the one with nothing
 * standing behind it either way.
 *
 * `bin/sugarcrush` opens with an IIFE that hunts for `vendor/autoload.php`
 * across three candidates and, failing all three, writes
 * `sugarcrush: cannot find composer autoload.php` to stderr and exits 2. It is
 * structurally exempt from
 * {@see \SugarCraft\Crush\Cli\Bootstrap::warnPermissionConfigInTranscript()},
 * because the seam is a static method on a class the missing autoloader was
 * supposed to supply and no Chat exists on this path to hold a transcript row.
 *
 * WHAT THIS DOC-BLOCK USED TO SAY: "Every other stderr write in this codebase
 * was re-examined against `Bootstrap::warnPermissionConfigInTranscript()`".
 * WHAT IS TRUE NOW, and what round 42's review measured: that was false. Only
 * `Bootstrap`'s writes and this one had been looked at. The real census of raw
 * `fwrite(STDERR, …)` call sites across `src/` and `bin/` is TWELVE:
 *
 *  - {@see \SugarCraft\Crush\Cli\NonInteractive}, seven —
 *    `run()` twice (a thrown backend error, and an answer that would not encode
 *    as JSON), `failUsage()`, `failUnusableProvider()`,
 *    `noticeOfflineDefault()`, `readStdinIfPiped()`, and — added by E219 —
 *    `noticeRefusal()`, one line per tool call the turn refused. That last one
 *    is the only site on this list whose routing decision is written up
 *    elsewhere: see its own doc-block for why it is on stderr rather than on
 *    the transcript seam, and why it could not be put in `Runtime`.
 *  - {@see \SugarCraft\Crush\Cli\Subcommands}, two — `sessionDelete()`'s "no
 *    such session" and `mcp()`'s inventory error.
 *  - {@see \SugarCraft\Crush\Cli\Bootstrap}, two —
 *    `warnPermissionConfig()`, which IS the stderr channel the seam delegates
 *    to and so cannot be a migration target, and `reportPrunedSessions()`'s
 *    per-session id rows, which stay raw on purpose.
 *  - `bin/sugarcrush`, one — this branch.
 *
 * WHY THE EXEMPTION CLAIM STILL EARNS ITS PLACE, narrowed to what was checked:
 * the nine in `NonInteractive` and `Subcommands` are all on the ONE-SHOT and
 * SUBCOMMAND paths, and {@see \SugarCraft\Crush\Cli\Bootstrap::launchNotices()}
 * is read by `Bootstrap::chat()` and `Bootstrap::app()` and by nothing else —
 * so on those paths the seam records into a static the process discards. That
 * is a reachability fact, not a judgement that the messages do not qualify, and
 * two of them do qualify under the rule: `noticeOfflineDefault()` (the session
 * cannot reach a real provider) and `readStdinIfPiped()` (10 MB of piped stdin
 * is silently truncated and the model is never told it is answering half a
 * question). Both are recorded in `docs/plans/crush_code_hardening_backlog.md`;
 * neither is in this item's scope, and neither is structurally exempt the way
 * THIS one is. What is unique here is that the seam's class cannot even be
 * loaded.
 *
 * WHAT THIS FILE COSTS, MEASURED, AND WHY IT IS NOT FOLDED (E102, round 44;
 * figures re-derived in that round's review because the first version of this
 * paragraph did not state its generator and could not be reproduced from what
 * it did say).
 *
 * THE GENERATOR, IN FULL, because every number below is worthless without it.
 * PHP 8.3.6, this box, 48 cores, `/proc/loadavg` 1-minute figure between 5.1
 * and 5.8 across the run with a sibling lane running its own suite, three
 * takes each, `/usr/bin/time -f 'wall=%e user=%U sys=%S'`, and the command is
 * always `php vendor/bin/phpunit --filter <Class>` — the explicit `php`
 * matters, see the census note below. RAW, not net of anything:
 *
 *   - this file:                   wall 3.33 / 3.30 / 3.28 s, user+sys ~3.27 s
 *   - a phpunit run that selects
 *     NO tests (`--filter ZzzNoSuchTestClassXyz`):
 *                                  wall 1.71 / 1.72 / 1.73 s, user+sys ~1.71 s
 *   - 33 bare `php -r 'exit(0);'`: wall 1.39 / 1.38 / 1.35 s
 *
 * So this file's OWN cost is ~1.58 s wall and ~1.56 s user+sys — CPU-bound,
 * not waiting — and the ~1.72 s underneath it is phpunit booting, which every
 * file in the suite pays once and no change here can move.
 *
 * WHAT THE FIRST VERSION OF THIS PARAGRAPH SAID: "wall 1.54 / 1.59 / 1.64 s,
 * user+sys 1.61 s". WHAT IS TRUE NOW: those are the SUBTRACTED numbers and the
 * paragraph called them wall, so a reader who re-measured as instructed got
 * roughly double and would have "corrected" a correct figure to a wrong one.
 * The subtraction is right and it is kept — the interesting quantity is what
 * this file adds — but it is now stated, and both terms are given so it can be
 * checked. (PHPUnit's own reported `Time:` matches neither: it excludes some of
 * the boot and includes some of the teardown. Use `/usr/bin/time`.)
 *
 * `strace -f -qq -e trace=execve php vendor/bin/phpunit --filter <Class>`
 * counts 100 `execve`, and they factor exactly: 33 `/bin/sh`, 33
 * `/usr/bin/timeout`, 33 `/usr/bin/php8.3`, 1 `/usr/bin/php`. So THIRTY-THREE
 * child interpreters — the lone `php` is phpunit itself — each reached through
 * the `/bin/sh -c` that `exec()` implies and the `timeout -s KILL 60` this file
 * wraps every spawn in. Three processes per row, one of which is the
 * interpreter that costs.
 *
 * THE EXPLICIT `php` IN THAT COMMAND IS LOAD-BEARING and its absence is why
 * the review could not reproduce the census either. Run as `strace …
 * vendor/bin/phpunit …`, the shebang wrapper is itself an `execve` and the
 * kernel's `PATH` search adds six ENOENT probes: 107 lines, 101 of them
 * successful. Same run, same file, different number, because the thing being
 * traced is a different program.
 *
 * The control that makes those numbers trustworthy: the same census against a
 * file that spawns nothing (`--filter BootstrapTranscriptSeamCallSiteCensusTest`)
 * reports exactly 1 — and, run the other way, 8 lines of which 2 succeed, which
 * is the same +1/+6 offset and confirms the offset is the invocation and not
 * the file. The control is worth having twice over, because an earlier attempt
 * at this census — a `php` shim placed first on `PATH` — reported 0 for THIS
 * file, and 0 was wrong: the spawns use `PHP_BINARY`, an absolute path, and
 * never consult `PATH` at all. That broken harness agreed with its control and
 * disagreed only with reality.
 *
 * Against 33 bare startups at 1.37 s, ~87% of this file's own 1.58 s is
 * interpreter startup and the test bodies are ~0.2 s of it. (The earlier
 * ~91% came from dividing 1.45 by 1.60; the ratio is the claim, and it is
 * ~87% on this round's numbers. Neither is precise: the bare-startup loop
 * pays no `sh` and no `timeout`, so it under-counts the per-row overhead this
 * file actually carries.)
 *
 * THE OBVIOUS FIX IS NOT AVAILABLE, and this is the part worth writing down
 * because it looks available. Folding the differential table into one child
 * that reads the rows off stdin cannot work here: the child under test is
 * `bin/sugarcrush` running in a checkout with NO `vendor/`, and its whole job
 * is to hit the autoload IIFE at the top of the file and `exit(2)` before any
 * class, autoloader or harness code exists. It cannot loop, because it is dead
 * inside that IIFE — the first statement after `declare(strict_types=1);`, and
 * the last one it reaches. And the independent variable across rows is its own
 * `$argv`, which is fixed for the life of a process. One child could `exec()`
 * the copied binary seventeen times, but that is seventeen grandchildren and
 * exactly the same interpreter startups — the cost is irreducible because a
 * fresh process per argv IS the thing under test.
 *
 * WHAT WOULD CHANGE THE ANSWER, so "fine for now" is not what this says. The
 * threshold is the ROW COUNT, since cost is ~48 ms per row (1.58 s / 33) and
 * nothing else moves: at today's 33 children this file is 1.6 s of a roughly
 * four-minute suite (~0.7%). At ~110 children it would be 5 s, which is where a single file
 * starts being felt in a local edit-test loop, and that is the point to stop
 * adding rows and start asking whether the marginal argv vector is buying
 * coverage or reassurance. Nothing about the STRUCTURE will have improved by
 * then — the fold will still be unavailable for the reason above — so the lever
 * is which rows earn a process, not how they are dispatched.
 *
 * "Structurally exempt" is a claim about behaviour, so it is asserted rather
 * than asserted-in-prose. What makes that cheap is that a checkout with no
 * `vendor/` is one `copy()` away, and {@see guardCandidates()} shows why:
 * the script resolves every candidate against its own `__DIR__`, so a copy
 * placed three directories deep inside a fresh temporary root puts ALL THREE
 * candidates inside that root, which was created empty a moment earlier.
 *
 * THE DEPTH IS THE POINT, and it is round 42's review that forced it. The first
 * version of this file copied the script to `<tmp>/bin/sugarcrush`, where the
 * third candidate (`__DIR__ . '/../../../autoload.php'`) resolves to
 * `/autoload.php` — but ONLY because `sys_get_temp_dir()` is `/tmp` on this
 * box. A host with a deeper TMPDIR (`/var/folders/xy/z`, or a CI runner
 * pointing it inside a project) sent that candidate somewhere unrelated, so the
 * file guarded itself with `markTestSkipped()` on `is_file('/autoload.php')` —
 * an environment-conditional skip that could take the suite's skip count from
 * 1 to 4. Nesting the copy makes the resolution self-contained, the skip is
 * gone, and {@see testTheGuardsThreeCandidatesAllLandInsideTheEmptyFixture()}
 * asserts the containment instead of assuming it.
 *
 * NOT A DUPLICATE OF {@see BinSugarcrushDispatchTest}: every case there runs
 * the real script out of the real checkout, where the autoloader is present by
 * construction and this branch is unreachable.
 */
final class BinSugarcrushAutoloadGuardTest extends TestCase
{
    private string $tmpDir = '';

    /**
     * Three levels below {@see $tmpDir}, so the script's `../../../` candidate
     * cannot escape the fixture. See the class doc-block.
     */
    private string $binDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/crush_autoload_guard_' . bin2hex(random_bytes(6));
        $this->binDir = $this->tmpDir . '/nest/deep/bin';
        mkdir($this->binDir, 0o755, true);
    }

    /**
     * Recursive, and unconditional. The first version cleaned the `vendor/`
     * fixture up at the FOOT of the test that created it, below the assertion —
     * so every failing run left a tree behind, and two were observed surviving
     * a mutation run. Cleanup belongs where a failure cannot skip it.
     */
    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);

        parent::tearDown();
    }

    /**
     * The reachability argument, asserted rather than assumed.
     *
     * If a fourth candidate is ever added to the script, or an existing one is
     * respelled so it resolves outside the fixture, this reds here with the
     * path — instead of the two cases below quietly starting to test a launch
     * that found a real autoloader. Restating the list is safe in the direction
     * that matters: a candidate this method does not know about, which found
     * something, would also red `testACheckoutWithNoAutoloaderFailsOnStderr…`,
     * because the guard would not fire at all.
     */
    public function testTheGuardsThreeCandidatesAllLandInsideTheEmptyFixture(): void
    {
        $candidates = $this->guardCandidates();

        self::assertCount(3, $candidates);

        foreach ($candidates as $candidate) {
            self::assertStringStartsWith(
                $this->tmpDir . '/',
                $candidate,
                'a candidate resolving outside the fixture makes this file depend on what the host '
                . 'happens to have at that path — which is exactly how the first version depended on '
                . 'sys_get_temp_dir() being one directory below /',
            );
            self::assertFileDoesNotExist($candidate);
        }
    }

    public function testACheckoutWithNoAutoloaderFailsOnStderrWithExitTwo(): void
    {
        [$status, $stdout, $stderr] = $this->runCopiedBinary();

        self::assertSame(2, $status, "expected exit 2; stderr was:\n" . $stderr);
        self::assertSame("sugarcrush: cannot find composer autoload.php\n", $stderr);
        self::assertSame('', $stdout);
    }

    /**
     * WHAT THIS TEST USED TO PIN: stdout stays empty, "the documented exception
     * rather than an oversight" — this was the one exit that could not honour
     * the `--output-format json` contract, because the class owning the
     * document shape ({@see \SugarCraft\Crush\Cli\NonInteractive}) is behind
     * the autoloader that is missing.
     *
     * WHAT IS TRUE NOW (E84): the shape's OWNER is unreachable here; the shape
     * itself never was. `json_encode()` is core. So the guard emits the
     * document, and the hole this test used to nail shut is closed instead —
     * a consumer of `| jq` on a broken checkout could not previously tell
     * "empty because the binary died before it could speak" from "empty because
     * there was nothing to say", and those two want opposite handling.
     *
     * WHY THE TEST STILL EARNS ITS PLACE, with its subject inverted: the
     * dangerous direction is now the other one. A guard that printed JSON at a
     * human is a regression, so
     * {@see testTheGuardStaysSilentOnStdoutWithoutOutputFormatJson()} below
     * holds the negative case, and this one holds the positive. Exit code stays
     * 2 in both.
     */
    public function testTheGuardEmitsTheContractDocumentUnderOutputFormatJson(): void
    {
        [$status, $stdout, $stderr] = $this->runCopiedBinary('--output-format json -p hi');

        self::assertSame(2, $status, "stderr was:\n" . $stderr);

        // EXACTLY ONE JSON OBJECT AND A NEWLINE, which is the contract's own
        // wording — asserted on the raw bytes, because a consumer's `jq` reads
        // bytes and not a decoded array.
        self::assertSame(
            '{"result":null,"error":{"type":"installation",'
            . '"message":"sugarcrush: cannot find composer autoload.php"}}' . "\n",
            $stdout,
        );

        // The human line is unchanged and still on stderr: the JSON document is
        // an ADDITION, not a redirection, exactly as it is on every other
        // exit-2 cause that routes through NonInteractive::failUsage().
        self::assertSame("sugarcrush: cannot find composer autoload.php\n", $stderr);
    }

    /**
     * Both spellings the parser accepts, because the guard cannot use the
     * parser — {@see \SugarCraft\Crush\Cli\ArgvParser} is behind the missing
     * autoloader too, so the guard rescans raw argv and the two scans have to
     * agree. `--output-format=json` is the one a Makefile writes.
     */
    public function testTheGuardHonoursTheEqualsSpellingToo(): void
    {
        [$status, $stdout] = $this->runCopiedBinary('--output-format=json -p hi');

        self::assertSame(2, $status);
        self::assertStringContainsString('"type":"installation"', $stdout);
    }

    /**
     * THE REGRESSION THIS FIX COULD HAVE INTRODUCED. A human running a broken
     * checkout must get the stderr line and NOTHING on stdout; a guard that
     * printed a JSON object at them would be worse than the hole it closed.
     *
     * `--output-format xml` is in the table on purpose rather than as padding:
     * the parser rejects it as unsupported and then renders the failure as
     * TEXT, so the guard agreeing that it is not json is what keeps the broken
     * checkout's behaviour identical to the working one's. `JSON` in capitals
     * is there for the same reason — the parser is case-sensitive and rejects
     * it, so a guard that lower-cased would make the broken binary MORE
     * permissive than the working one.
     *
     * THE `--` ROW IS THE ONE THAT WAS MISSING, and it is why this file no
     * longer states the agreement and leaves it there: round 43's review
     * measured `-- --output-format=json -p hi` emitting the document while
     * {@see ArgvParser::parse()} on the same argv reported `text`. The rows
     * below are the human-readable half; the machine half — this scan against
     * the real parser, decision for decision — is
     * {@see testTheGuardsFormatScanAgreesWithArgvParser()}.
     *
     * @dataProvider nonJsonInvocations
     */
    public function testTheGuardStaysSilentOnStdoutWithoutOutputFormatJson(string $arguments): void
    {
        [$status, $stdout, $stderr] = $this->runCopiedBinary($arguments);

        self::assertSame(2, $status, "stderr was:\n" . $stderr);
        self::assertSame('', $stdout, "`{$arguments}` printed a JSON document at a human");
    }

    /** @return iterable<string, array{0: string}> */
    public static function nonJsonInvocations(): iterable
    {
        yield 'no format flag at all' => ['--version'];
        yield 'explicit text' => ['--output-format text -p hi'];
        yield 'equals spelling, text' => ['--output-format=text -p hi'];
        yield 'a format nothing implements' => ['--output-format xml -p hi'];
        yield 'wrong case' => ['--output-format JSON -p hi'];
        yield 'the flag with no value' => ['--output-format'];
        yield 'after the POSIX end-of-options separator' => ['-- --output-format=json -p hi'];
        yield 'swallowed by --root' => ['--root --output-format json -p hi'];
        yield 'after -- with a subcommand open' => ['session delete -- --output-format=json'];
    }

    /**
     * THE DRIFT GUARD, and the reason the duplication in the binary is
     * acceptable at all.
     *
     * The guard hand-rolls a document because
     * {@see \SugarCraft\Crush\Cli\NonInteractive} is behind the autoloader it
     * could not find. That is a real second definition of the failure shape, so
     * something has to hold the two together: this test takes the REAL
     * document {@see \SugarCraft\Crush\Cli\NonInteractive::failUsage()}
     * produces and compares its STRUCTURE — key order included, since a
     * consumer reading the raw bytes sees that too — against the one the guard
     * printed. Change either side's keys or flags and this reds, naming both.
     *
     * The VALUES differ by design: `error.type` is `installation` there and
     * `usage` here, which is the whole point of the field. So the comparison is
     * on the key tree, and the one value that must NOT differ — `result` being
     * present and null — is asserted directly.
     */
    public function testTheGuardsDocumentHasTheSameShapeAsNonInteractives(): void
    {
        [, $guardStdout] = $this->runCopiedBinary('--output-format json -p hi');
        $guard = json_decode($guardStdout, true, 512, JSON_THROW_ON_ERROR);

        $owner = json_decode($this->documentFromNonInteractive(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(array_keys($owner), array_keys($guard));
        self::assertSame(array_keys($owner['error']), array_keys($guard['error']));
        self::assertArrayHasKey('result', $guard);
        self::assertNull($guard['result']);
        self::assertNull($owner['result']);

        // The trailing newline is part of the contract on both sides — a
        // consumer streaming NDJSON depends on it.
        self::assertStringEndsWith("}\n", $guardStdout);
    }

    /**
     * THE MACHINE HALF OF "MIRRORS ArgvParser'S OWN SCAN", and the reason that
     * sentence is allowed to stay in `bin/sugarcrush` at all.
     *
     * The guard cannot call {@see ArgvParser} — it is behind the autoloader that
     * is missing — so it rescans raw argv, and the whole specification of that
     * rescan is "decide `json` exactly when the parser would have". Round 43's
     * review measured three argv vectors where it did not: after a POSIX `--`,
     * and after a flag that swallows the token following it, the guard read a
     * `--output-format` the parser had already spent on something else, and the
     * BROKEN checkout answered a question the WORKING one refuses. That is the
     * precise failure the case-sensitivity choice in that comment exists to
     * prevent, arriving through a door nobody had checked.
     *
     * So this compares the two DECISIONS rather than restating either: the
     * parser runs in-process on the argv, the guard runs in the bare fixture on
     * the same argv, and "parser said json" must equal "guard printed
     * something". A prose claim about agreement cannot red; this can, from
     * either side — a new value-taking flag in {@see ArgvParser::parse()} that
     * the guard does not know about reds here the moment a row exercises it.
     *
     * @dataProvider formatDecisionVectors
     *
     * @param list<string> $tokens
     */
    public function testTheGuardsFormatScanAgreesWithArgvParser(array $tokens): void
    {
        // $argv[0] is the script name on both sides: the parser documents that
        // it skips it, and the guard now starts at 1 for the same reason.
        $parsed = ArgvParser::parse(array_merge(['sugarcrush'], $tokens));
        $parserSaysJson = $parsed->outputFormat === NonInteractive::FORMAT_JSON;

        [$status, $stdout, $stderr] = $this->runCopiedBinary(
            implode(' ', array_map('escapeshellarg', $tokens)),
        );

        self::assertSame(2, $status, "stderr was:\n" . $stderr);
        self::assertSame(
            $parserSaysJson,
            $stdout !== '',
            sprintf(
                'ArgvParser read `%s` as outputFormat "%s", but the guard %s. The broken checkout '
                . 'must decide the format exactly as the working one does.',
                implode(' ', $tokens),
                $parsed->outputFormat,
                $stdout === '' ? 'printed nothing on stdout' : 'printed a document',
            ),
        );
    }

    /**
     * Every row is an argv the two scans have to agree on. The three the review
     * measured as DIVERGING are named as such, so a future reader can tell the
     * regression rows from the coverage rows.
     *
     * @return iterable<string, array{0: list<string>}>
     */
    public static function formatDecisionVectors(): iterable
    {
        yield 'space spelling' => [['--output-format', 'json', '-p', 'hi']];
        yield 'equals spelling' => [['--output-format=json', '-p', 'hi']];
        yield 'wrong case' => [['--output-format', 'JSON', '-p', 'hi']];
        yield 'unsupported value' => [['--output-format', 'xml', '-p', 'hi']];
        yield 'no value at all' => [['--output-format']];
        yield 'last occurrence wins, json then text' => [
            ['--output-format', 'json', '--output-format', 'text', '-p', 'hi'],
        ];
        yield 'last occurrence wins, text then json' => [
            ['--output-format', 'text', '--output-format', 'json', '-p', 'hi'],
        ];

        // REGRESSION ROW 1 (measured diverging): `--` is the POSIX
        // end-of-options separator, and past it the parser routes every token to
        // operands.
        yield 'after the end-of-options separator' => [['--', '--output-format=json', '-p', 'hi']];
        // REGRESSION ROW 2 (measured diverging): `--root` takes `$argv[++$i]`
        // with no test on the value, so it eats the flag itself.
        yield 'swallowed by --root' => [['--root', '--output-format', 'json', '-p', 'hi']];
        // REGRESSION ROW 3 (measured diverging): the same separator with a
        // subcommand open, where the tokens go to the subcommand's operands.
        yield 'after the separator with a subcommand open' => [
            ['session', 'delete', '--', '--output-format=json'],
        ];

        // The other side of the value-swallowing rule: these flags REFUSE a
        // flag-shaped value and leave it for the loop, so the format IS read.
        yield 'a flag-shaped value -p refuses' => [['-p', '--output-format=json']];
        yield 'a flag-shaped value --config refuses' => [['--config', '--output-format=json']];
        yield '--root with a real value' => [['--root', '/tmp', '--output-format', 'json', '-p', 'hi']];
        yield '--model with a real value' => [['--model', 'x', '--output-format', 'json', '-p', 'hi']];
        yield '--permission-mode with a real value' => [
            ['--permission-mode', 'plan', '--output-format', 'json', '-p', 'hi'],
        ];
        yield 'a subcommand with a format' => [['mcp', 'list', '--output-format', 'json']];
        // `run` is deliberately NOT modelled by the guard, because it consumes
        // its value only when that value is not flag-shaped — which means both
        // sides leave this `--output-format` for the loop.
        yield 'run handed a flag-shaped prompt' => [['run', '--output-format=json']];
    }

    /**
     * THE ENCODE FLAGS, pinned by source because they cannot be pinned by
     * output.
     *
     * {@see testTheGuardsDocumentHasTheSameShapeAsNonInteractives()} decodes
     * both documents, so every encode flag is gone before it looks; and the
     * guard's only payload is an ASCII literal with no slash and no non-ASCII
     * byte, so its flag set changes no byte anywhere in this suite. Round 43's
     * review dropped `JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE` from the
     * guard, and separately from {@see NonInteractive::encodeDocument()}, and
     * the suite stayed green both times — a guard that passes whether or not the
     * thing it guards is present. This compares the two flag EXPRESSIONS
     * instead, token for token, which is the only comparison that reds.
     *
     * `JSON_THROW_ON_ERROR` is expected on the owner's side ONLY, and the
     * asymmetry is asserted rather than tolerated: the owner throws and catches
     * a `JsonException`, while the guard has no handler to reach for and tests
     * the documented `string|false` return directly. Add it to the guard, or
     * drop it from the owner, and this reds.
     */
    public function testTheGuardsEncodeFlagsAreEncodeDocumentsMinusThrowOnError(): void
    {
        $guardFlags = self::jsonFlagTokensIn((string) file_get_contents($this->realBinary()));
        $ownerFlags = self::jsonFlagTokensIn("<?php\n" . self::sourceOfEncodeDocument());

        self::assertNotSame([], $guardFlags, 'no JSON_* flag survives in bin/sugarcrush at all');
        self::assertSame(
            ['JSON_THROW_ON_ERROR'],
            array_values(array_diff($ownerFlags, $guardFlags)),
            'encodeDocument() carries a flag the guard does not, beyond the documented JSON_THROW_ON_ERROR',
        );
        self::assertSame(
            [],
            array_values(array_diff($guardFlags, $ownerFlags)),
            'the guard carries a flag encodeDocument() does not',
        );
    }

    /**
     * THE UNREACHABLE ARM, pinned anyway.
     *
     * The guard's `json_encode() === false` branch echoes a hand-written copy of
     * the whole document — a THIRD copy of the shape, after the owner's and the
     * guard's own array literal. It is genuinely unreachable (every field is an
     * ASCII literal the file owns), which is exactly why nothing exercised it:
     * round 43's review replaced it with `{"BROKEN":true}` and the suite stayed
     * green. Reading it out of the source and comparing it against the bytes the
     * REACHABLE arm printed is the pin that does not need the arm to run.
     */
    public function testTheUnreachableFallbackLiteralIsTheDocumentItReplaces(): void
    {
        $literal = null;

        foreach (token_get_all((string) file_get_contents($this->realBinary())) as $token) {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            if (str_starts_with(substr($token[1], 1), '{"result"')) {
                $literal = $token[1];
            }
        }

        self::assertNotNull($literal, 'the guard no longer carries a hand-written fallback document');
        self::assertStringNotContainsString(
            '\\',
            $literal,
            'the fallback literal grew an escape sequence; unquoting it by trimming is no longer safe',
        );

        [, $stdout] = $this->runCopiedBinary('--output-format json -p hi');

        self::assertSame(
            rtrim($stdout, "\n"),
            substr($literal, 1, -1),
            'the unreachable fallback no longer matches the document the reachable arm emits',
        );
    }

    /** The real script, not the fixture copy. */
    private function realBinary(): string
    {
        return dirname(__DIR__, 2) . '/bin/sugarcrush';
    }

    /**
     * {@see NonInteractive::encodeDocument()}'s body, by reflection — the
     * declaration line onward, so the doc-block above it (which NAMES two of
     * these flags in prose) cannot be mistaken for the code.
     */
    private static function sourceOfEncodeDocument(): string
    {
        $method = new \ReflectionMethod(NonInteractive::class, 'encodeDocument');
        $file = (string) $method->getFileName();
        $lines = file($file) ?: [];

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }

    /**
     * Every `JSON_*` constant this PHP source references AS CODE, sorted, unique
     * and with any leading root-namespace backslash stripped. `token_get_all()` rather than a regex,
     * because both files discuss these flags at length in comments and a regex
     * would read the prose.
     *
     * BOTH TOKEN KINDS, and it took a red to notice: `bin/sugarcrush` writes the
     * bare `JSON_UNESCAPED_SLASHES` (T_STRING) while
     * {@see NonInteractive::encodeDocument()} writes the root-qualified
     * `\JSON_UNESCAPED_SLASHES`, which PHP 8 lexes as T_NAME_FULLY_QUALIFIED —
     * so a T_STRING-only scan read the owner as having NO flags at all and the
     * comparison passed for the wrong reason.
     *
     * @return list<string>
     */
    private static function jsonFlagTokensIn(string $source): array
    {
        $found = [];

        foreach (token_get_all($source) as $token) {
            if (!is_array($token) || ($token[0] !== T_STRING && $token[0] !== T_NAME_FULLY_QUALIFIED)) {
                continue;
            }
            $name = ltrim($token[1], '\\');
            if (preg_match('/^JSON_[A-Z_]+$/', $name) === 1) {
                $found[$name] = true;
            }
        }

        $names = array_keys($found);
        sort($names);

        return $names;
    }

    /**
     * One real `NonInteractive::failUsage()` document, captured out of a child
     * `php` so nothing here has to reimplement the shape it is checking. Run in
     * a child rather than in-process because that method writes its human half
     * to STDERR, which a PHPUnit run cannot redirect.
     */
    private function documentFromNonInteractive(): string
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $script = $this->tmpDir . '/owner_document.php';
        $outFile = $this->tmpDir . '/owner_out.txt';
        $errFile = $this->tmpDir . '/owner_err.txt';

        file_put_contents($script, "<?php\n"
            . 'require ' . var_export($autoload, true) . ";\n"
            . 'SugarCraft\Crush\Cli\NonInteractive::failUsage("sugarcrush: probe", "json");' . "\n");

        // fd 2 goes to a FILE, not to /dev/null. It has to leave the suite's
        // own stderr either way - `failUsage()` writes a human half there that
        // has nothing to do with this comparison - but a sink would also throw
        // away the only evidence available when this probe produces nothing,
        // which is the failure the assertion below actually has to diagnose.
        // {@see \SugarCraft\Crush\Tests\Support\ChildStderrCaptureScanner}.
        exec(sprintf(
            'timeout -s KILL 60 %s %s >%s 2>%s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg($outFile),
            escapeshellarg($errFile),
        ));

        $document = is_file($outFile) ? (string) file_get_contents($outFile) : '';
        self::assertNotSame(
            '',
            $document,
            'the owner probe produced no document to compare against; its stderr said: '
                . (is_file($errFile) ? (string) file_get_contents($errFile) : '(nothing)'),
        );

        return $document;
    }

    /**
     * The guard must not be reachable from a checkout that DOES have a
     * `vendor/autoload.php` — otherwise the two tests above would pass against
     * a script whose candidate list had silently stopped working, and every
     * real install would print this line.
     */
    public function testTheGuardIsNotReachedWhenAnAutoloaderIsInPlace(): void
    {
        $vendor = $this->tmpDir . '/nest/deep/vendor';
        mkdir($vendor, 0o755, true);
        // Enough of an autoloader to satisfy `is_file()` and `require`; the
        // script's next statement is `use`, then ArgvParser, so it will fail
        // afterwards — the assertion is only that it failed SOMEWHERE ELSE.
        file_put_contents($vendor . '/autoload.php', "<?php\n");

        [, , $stderr] = $this->runCopiedBinary();

        // tearDown() removes the tree whether or not this holds.
        self::assertStringNotContainsString('cannot find composer autoload.php', $stderr);
    }

    /**
     * The three paths `bin/sugarcrush`'s IIFE will test, resolved the way the
     * script resolves them: relative spellings against `__DIR__ . '/../'`,
     * absolute ones as they stand.
     *
     * @return list<string>
     */
    private function guardCandidates(): array
    {
        $resolved = [];

        foreach ([
            'vendor/autoload.php',
            $this->binDir . '/../vendor/autoload.php',
            $this->binDir . '/../../../autoload.php',
        ] as $candidate) {
            $path = str_starts_with($candidate, '/') ? $candidate : $this->binDir . '/../' . $candidate;
            $resolved[] = self::collapse($path);
        }

        return $resolved;
    }

    /**
     * Textual `..` collapse — `realpath()` is no use here, because the whole
     * question is about paths that do not exist.
     */
    private static function collapse(string $path): string
    {
        $out = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $segment;
        }

        return '/' . implode('/', $out);
    }

    /**
     * `--version` BY DEFAULT, and the default is load-bearing rather than
     * cosmetic. Every case here expects the IIFE to exit before argv is ever
     * parsed, so the argument is irrelevant when the test passes — but under a
     * mutation that makes the guard find an autoloader, an empty argv sends the
     * copied script into `Program::run()` and the alt screen, where it sits
     * until `timeout -s KILL 60` reaps it. Measured while pinning this file:
     * that turned one mutation run into a >2-minute hang. `--version` makes the
     * same mutation fail in milliseconds with the wrong exit code, which is the
     * answer the assertion wanted anyway.
     *
     * @return array{0: int, 1: string, 2: string} exit status, stdout, stderr
     */
    private function runCopiedBinary(string $arguments = '--version'): array
    {
        $binary = $this->binDir . '/sugarcrush';
        copy(dirname(__DIR__, 2) . '/bin/sugarcrush', $binary);

        $outFile = $this->tmpDir . '/out.txt';
        $errFile = $this->tmpDir . '/err.txt';

        exec(sprintf(
            'timeout -s KILL 60 %s %s %s >%s 2>%s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($binary),
            $arguments,
            escapeshellarg($outFile),
            escapeshellarg($errFile),
        ), $ignored, $status);

        $stdout = is_file($outFile) ? (string) file_get_contents($outFile) : '';
        $stderr = is_file($errFile) ? (string) file_get_contents($errFile) : '';

        return [$status, $stdout, $stderr];
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}

<?php

declare(strict_types=1);

/**
 * Guard for `tools/check-child-lifetimes.php` (E486, closing E448).
 *
 * THE POINT OF THIS FILE is that the repo-wide child-lifetime gate is a hard
 * CI step, and a hard step whose classifier stopped matching would report a
 * clean tree from every branch forever — the exact failure `--unused` was
 * called out for in CheckPathReposTest's doc-block. So the polarity that
 * matters most here is RED: a fixture tree with an unaccounted exposed spawn
 * must exit 1 and name the site, because that is the assertion proving the
 * scanner is still wired to the verdict. The green-against-the-real-repo arm
 * is the drift pin: if the scanner's finding shape changes, or a site moves
 * without its roster row following, this file reddens the `tools/ guards`
 * job instead of the manifest job silently losing an arm.
 *
 * N08 (2026-11-11) gave the verdict a second red mode on top of UNACCOUNTED,
 * STALE and COUNT DRIFT: a rostered key whose row vouches for the WRONG
 * dimension — a `reap-bounded` licence meeting an `exposed` derivation, or a
 * `fd-open` licence meeting an `unclassified` one — accuses as KIND MISMATCH,
 * naming both sides. The dimension pins below hold each direction red with
 * its exact message, so a future row that quietly licenses the other shape
 * cannot pass as covered.
 *
 * WHY THE GREEN POLARITIES CALL derive() RATHER THAN THE CLI: the default
 * roster describes THIS repository. A throwaway fixture tree under /tmp can
 * never satisfy it — every sugar-pty/sugar-core/sugar-dashboard/sugar-video row
 * would read STALE there — so asserting exit 0 on a fixture would be
 * asserting the wrong contract. The red polarities still go through the CLI,
 * because what they prove is the wiring from scanner to exit code, and the
 * planted UNACCOUNTED lines are what they grep, stale rows alongside.
 *
 * HOW TO RUN: `phpunit --no-configuration tools/tests/` (CI), or locally
 * `sugar-core/vendor/bin/phpunit --no-configuration tools/tests/` — see the
 * long note at the top of CheckPathReposTest.php about which of these two
 * has actually been exercised on a dev box.
 */

namespace SugarCraft\Tools\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../check-child-lifetimes.php';

final class ChildLifetimesToolTest extends TestCase
{
    use TmpTree;

    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = $this->makeTmpTree('child_lifetimes_tool_');
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '') {
            $this->removeDir($this->tmpDir);
        }
    }

    /**
     * The real repo must be green today: every derived finding has its row.
     *
     * This is also the known-positive census — it asserts the tool still
     * derives the measured figures, so a scanner that quietly starts
     * reporting nothing (the silent-`null` failure mode this suite has seen
     * before) cannot pass the red-polarity fixtures by going blind.
     */
    public function testTheRepositoryItselfPassesTheGate(): void
    {
        [$out, $err, $code] = $this->runTool(\dirname(__DIR__, 2));

        $this->assertSame(0, $code, "tool was red on its own repo:\n$out$err");
        $this->assertStringContainsString('0 problems', $out);
        // 9 -> 13: the 0bcc4346b double-walk minted four UNACCOUNTED findings
        // (StatelessRpc advertise/negotiate, each walked twice under the lib's
        // nested psr-4 prefixes) that were never rostered — a standing red the
        // 2026-11-04 child-lifetime accounting lane cleared with honest
        // ACCOUNTED rows. The pin was stale-but-honest: findings count all
        // derivations, accounted or not.
        // 13 -> 11 re-derived live with the tool on 2026-11-10: fd6900382
        // flattened the candy-git-server manifest to a single psr-4 prefix and
        // rewrote StatelessRpc, ending the nested double-walk that had counted
        // advertise/negotiate twice each (2+2), so those four derivations became
        // two. Findings now equal the 11 accounted rows exactly, tool rc=0.
        // 11 -> 12 re-derived live with the tool on 2026-11-12: scriptui-01
        // consolidated the spin lifecycle into candy-scriptui's own
        // RealProcess::spawn, minting one proc_open site — one more exposed
        // finding, met by its own honest ACCOUNTED row (fd-open, the same
        // descriptor judgement as sugar-pty's PosixProcess::spawn sibling).
        // 12 -> 13 re-derived live with the tool on 2026-11-12: S15 of the
        // candy-git-server reap-ladder wave parked BoundedCommand::run's
        // KILL-expiry survivors in a WNOHANG registry — the call can now
        // end with a dying child still registered, so the scanner reads
        // the site as long-lived/exposed; its own honest fd-open row went
        // into ACCOUNTED with this change (the candy-top AsyncCommand
        // precedent shape), and the site count stayed 30 (no new spawn).
        $this->assertStringContainsString('13 findings', $out);
        // Frozen literal: counts every @proc_open site tree-wide — flip it in the SAME commit that adds a site.
        // 33 -> 30 re-derived live with the tool on 2026-11-04: net -3 of tree
        // drift since 0bcc4346b (the pin predates later spawn consolidation,
        // incl. candy-git-server's ab9d2b147 argv-only BoundedCommand hygiene
        // wave, which retired raw sites without minting new ones). The lib's
        // own census of its current 4 spawn functions lives in
        // candy-git-server/tests/ChildProcessLifetimeCensusTest::SPAWN_SITES_BY_FILE.
        // 30 -> 29 re-derived live with the tool on 2026-11-10: the Step-8
        // StatelessRpc rewrite in fd6900382 retired one raw @proc_open site
        // without minting a replacement.
        // 29 -> 30 re-derived live with the tool on 2026-11-12: scriptui-01
        // minted the candy-scriptui RealProcess::spawn site described above.
        $this->assertStringContainsString('30 proc_open sites', $out);
    }

    /**
     * RED polarity: a fresh exposed spawn no roster row covers must fail,
     * naming the exact key a future author pastes into ACCOUNTED.
     */
    public function testAnUnknownExposedSpawnRedsWithItsExactKey(): void
    {
        $this->writeLib('lib-x', 'Wicked', <<<'PHP'
            {
                /** @var resource|null */
                private $child = null;

                public function start(): void
                {
                    $this->child = proc_open(
                        ['true'],
                        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                        $pipes
                    );
                }
            }
            PHP);

        [$out, $err, $code] = $this->runTool($this->tmpDir);

        $this->assertSame(1, $code, "an unaccounted exposure passed the gate:\n$out");
        $this->assertStringContainsString('UNACCOUNTED exposed at lib-x/src/Wicked.php::start', $err);
    }

    /**
     * RED polarity, rule-14 arm: the NAME appearing as a string is not a
     * call site, and dropping it silently is how the alphabet grows a hole
     * that matches the next defect exactly.
     */
    public function testAnUnresolvedAppearanceOfTheNameReds(): void
    {
        $this->writeLib('lib-y', 'Indirect', <<<'PHP'
            {
                public function run(): string
                {
                    $name = 'proc_open';

                    return $name;
                }
            }
            PHP);

        [, $err, $code] = $this->runTool($this->tmpDir);

        $this->assertSame(1, $code, 'a droppable-looking occurrence went unseen');
        $this->assertStringContainsString('unresolved:string reference', $err);
    }

    /**
     * The other polarity of the same door: a child the scanner can PROVE is
     * reaped inside its own function is short, not a finding.
     */
    public function testAShortLivedSpawnIsNotAFinding(): void
    {
        $this->writeLib('lib-z', 'Brief', <<<'PHP'
            {
                public function run(): int
                {
                    $proc = proc_open(
                        ['true'],
                        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                        $pipes
                    );
                    foreach ($pipes as $pipe) {
                        fclose($pipe);
                    }

                    return proc_close($proc);
                }
            }
            PHP);

        $report = \CheckChildLifetimes::derive($this->tmpDir);

        $this->assertSame(1, $report['libs']);
        $this->assertGreaterThanOrEqual(1, $report['sites'], 'the fixture spawn was not seen');
        $this->assertSame([], $report['findings'], 'a reaped child was flagged');
    }

    /**
     * A spec that DOES say something above fd 2 is not exposed either — the
     * highFds door, so the roster does not silently grow on a fix that only
     * adds an extra descriptor.
     */
    public function testASpecNamingHigherFdsIsNotExposed(): void
    {
        $this->writeLib('lib-w', 'Wide', <<<'PHP'
            {
                /** @var resource|null */
                private $child = null;

                public function start(): void
                {
                    $this->child = proc_open(
                        ['true'],
                        [
                            0 => ['pipe', 'r'],
                            1 => ['pipe', 'w'],
                            2 => ['pipe', 'w'],
                            3 => ['file', '/dev/null', 'w'],
                        ],
                        $pipes
                    );
                }
            }
            PHP);

        $report = \CheckChildLifetimes::derive($this->tmpDir);

        // Sight-pins FIRST: without them an analyzer regression that sees
        // zero sites passes assertSame([], findings) vacuously — the door
        // this arm exists to guard would silently close.
        $this->assertSame(1, $report['libs']);
        $this->assertGreaterThanOrEqual(1, $report['sites'], 'the fd-3 fixture spawn was not seen');
        $this->assertSame([], $report['findings']);
    }

    /**
     * Stale rows red on their own — the roster is a claim about the tree,
     * and a claim that outlives its subject is the same drift this tool
     * exists to catch in the sites, caught here in the rows.
     */
    public function testRowsThatDeriveNothingAreStale(): void
    {
        $problems = \CheckChildLifetimes::verdict([]);

        $this->assertCount(\count(\CheckChildLifetimes::ACCOUNTED), $problems);
        foreach ($problems as $problem) {
            $this->assertStringStartsWith('STALE ROW:', $problem);
        }
    }

    /**
     * Count drift is its own failure, distinct from stale and unaccounted.
     */
    public function testASecondSiteUnderAnExistingKeyDriftsTheCount(): void
    {
        $key = (string) \array_key_first(\CheckChildLifetimes::ACCOUNTED);
        $licensed = \CheckChildLifetimes::KIND_LICENSES[\CheckChildLifetimes::ACCOUNTED[$key]['kind']];
        $findings = [
            ['key' => $key, 'kind' => $licensed, 'line' => 1, 'detail' => 'a'],
            ['key' => $key, 'kind' => $licensed, 'line' => 2, 'detail' => 'b'],
        ];

        $problems = \CheckChildLifetimes::verdict($findings);

        // The other rows read STALE in the same verdict — correct, this
        // fixture tree holds none of them. The door being pinned here is
        // that DOUBLED sites read as drift, never as silence.
        $drift = \array_values(\array_filter(
            $problems,
            static fn (string $p): bool => \str_starts_with($p, 'COUNT DRIFT at ' . $key),
        ));
        $this->assertCount(1, $drift);
        $this->assertStringContainsString('roster says 1, the tree derives 2', $drift[0]);
    }

    /**
     * A finding set that exactly matches the roster passes — the pure
     * verdict door, so the repo-green assertion cannot be an artifact of the
     * CLI wrapper rather than the judgement.
     */
    public function testTheRosterJudgesItsOwnFindingsClean(): void
    {
        $findings = [];
        foreach (\CheckChildLifetimes::ACCOUNTED as $key => $row) {
            for ($n = 0; $n < $row['count']; $n++) {
                $findings[] = [
                    'key' => $key,
                    'kind' => \CheckChildLifetimes::KIND_LICENSES[$row['kind']],
                    'line' => $n,
                    'detail' => 'x',
                ];
            }
        }

        $this->assertSame([], \CheckChildLifetimes::verdict($findings));
    }

    /**
     * Roster hygiene after n08: every row names a kind, every kind is in the
     * licensed vocabulary, and the split of the twelve rows across the two
     * dimensions is a measured census — a row drifting dimension must be
     * re-adjudicated here, not silently re-labelled.
     */
    public function testEveryRowKindIsLicensedAndCensusHolds(): void
    {
        $this->assertSame(
            ['reap-bounded' => 'unclassified', 'fd-open' => 'exposed'],
            \CheckChildLifetimes::KIND_LICENSES,
        );

        $counts = [];
        foreach (\CheckChildLifetimes::ACCOUNTED as $key => $row) {
            $this->assertIsString($row['kind'], $key);
            $this->assertArrayHasKey($row['kind'], \CheckChildLifetimes::KIND_LICENSES, $key);
            $this->assertIsInt($row['count'], $key);
            $this->assertGreaterThan(0, $row['count'], $key);
            $this->assertIsString($row['reason'], $key);
            $this->assertNotSame('', $row['reason'], $key);
            $counts[$row['kind']] = ($counts[$row['kind']] ?? 0) + 1;
        }

        // 5/6 measured 2026-11-11 at the n08 split (rows: reap-bounded =
        // WorkerPool::spawnWorker, ChafaRenderer::render, AsyncCommand::spawn,
        // StatelessRpc::advertise, StatelessRpc::negotiate; fd-open =
        // Spawn::proc, PosixProcess::spawn, ExternalModule::startProcess,
        // AudioPlayer::start, FfmpegDecoder::open, StdioMcpServer::start).
        // 6 -> 7 fd-open on 2026-11-12: scriptui-01 rostered
        // candy-scriptui/src/Process/RealProcess.php::spawn beside its
        // sugar-pty PosixProcess::spawn twin (same descriptor judgement).
        // 7 -> 8 fd-open on 2026-11-12: the S15 reap-ladder wave parked
        // BoundedCommand::run's KILL-expiry survivors in a static WNOHANG
        // registry (the candy-top AsyncCommand shape), making the runner
        // long-lived by construction — its fd-open row vouches for the
        // bounded dying interval, not for a reaped exit path.
        // Re-derive with the tool if a row changes dimension.
        $this->assertSame(['reap-bounded' => 5, 'fd-open' => 8], $counts);
    }

    /**
     * RED polarity, n08 leg one: the rostered WorkerPool key vouches for
     * reap-bounded only; feed it an exposed derivation and it accuses as
     * KIND MISMATCH naming both dimensions — NOT as UNACCOUNTED, which would
     * tell a future author to add a row rather than re-adjudicate the one
     * that exists.
     */
    public function testAReapBoundedLicenceOnAnExposedShapeAccusesPrecisely(): void
    {
        $problems = \CheckChildLifetimes::verdict([[
            'key' => 'sugar-core/src/WorkerPool.php::spawnWorker',
            'kind' => 'exposed',
            'line' => 350,
            'detail' => 'child stored beyond the call',
        ]]);

        $mismatch = \array_values(\array_filter(
            $problems,
            static fn (string $problem): bool => \str_starts_with($problem, 'KIND MISMATCH at '),
        ));

        $this->assertCount(1, $mismatch);
        $this->assertStringStartsWith('KIND MISMATCH at sugar-core/src/WorkerPool.php::spawnWorker', $mismatch[0]);
        $this->assertStringContainsString('reap-bounded', $mismatch[0]);
        $this->assertStringContainsString('unclassified', $mismatch[0]);

        // The ten rows this fixture derives nothing from read STALE in the
        // same verdict — correct here; the door is the accusation CLASS, not
        // the problem count. WorkerPool itself matches its own row, so no
        // UNACCOUNTED may appear anywhere.
        foreach ($problems as $problem) {
            $this->assertStringNotContainsString('UNACCOUNTED', $problem);
        }
    }

    /**
     * RED polarity, n08 leg two: the mirror direction — Spawn::proc vouches
     * for fd-open (descriptor) accounting; an unclassified derivation under
     * it must accuse precisely, naming fd-open and the exposed shape its
     * licence carries.
     */
    public function testAFdOpenLicenceOnAnUnclassifiedShapeAccusesPrecisely(): void
    {
        $problems = \CheckChildLifetimes::verdict([[
            'key' => 'sugar-pty/src/Spawn.php::proc',
            'kind' => 'unclassified',
            'line' => 103,
            'detail' => 'lifetime not classifiable from the body',
        ]]);

        $mismatch = \array_values(\array_filter(
            $problems,
            static fn (string $problem): bool => \str_starts_with($problem, 'KIND MISMATCH at '),
        ));

        $this->assertCount(1, $mismatch);
        $this->assertStringStartsWith('KIND MISMATCH at sugar-pty/src/Spawn.php::proc', $mismatch[0]);
        $this->assertStringContainsString('fd-open', $mismatch[0]);
        $this->assertStringContainsString('exposed', $mismatch[0]);
    }

    /**
     * A directory is a lib only when its manifest says so: the walk keys on
     * the autoload section, not on directory names — and a src/ hiding a
     * spawn under a manifest-less directory is not silently scanned.
     */
    public function testDirectoriesWithoutAutoloadAreNotWalked(): void
    {
        \mkdir($this->tmpDir . '/not-a-lib/src', 0777, true);
        \file_put_contents(
            $this->tmpDir . '/not-a-lib/composer.json',
            (string) \json_encode(['name' => 'sugarcraft/not-a-lib']),
        );
        \file_put_contents(
            $this->tmpDir . '/not-a-lib/src/Nothing.php',
            "<?php\n\$p = proc_open(['true'], [0 => ['pipe', 'r']], \$pipes);\n",
        );

        $report = \CheckChildLifetimes::derive($this->tmpDir);

        $this->assertSame(0, $report['libs']);
        $this->assertSame(0, $report['sites']);
    }

    public function testTheShippedRosterPassesItsOwnBootValidation(): void
    {
        // RT-37: every ACCOUNTED row kind is licensed today.
        $this->assertSame([], \CheckChildLifetimes::rosterProblems());
    }

    public function testAMisspelledRowKindRefusesAtBootNotMidVerdict(): void
    {
        // The refusal must name the row and die before any verdict
        // formatting - the blank-licence mis-accusation it prevents is
        // untestable without this CLI leg.
        // The copy lives in tools/ so its __DIR__-relative require of
        // candy-code's TokenFunctionRanges resolves exactly like the shipped
        // file's does; it is unlinked before this test returns.
        $copy = \dirname(__DIR__) . '/.roster-typo-test.php';
        $tool = \dirname(__DIR__) . '/check-child-lifetimes.php';
        $source = (string) \file_get_contents($tool);
        $typos = \preg_replace(
            "/'kind' => 'fd-open',/",
            "'kind' => 'licencse',",
            $source,
            1,
        );
        $this->assertNotSame($source, $typos, 'the fixture anchor drifted');
        \file_put_contents($copy, $typos);

        $process = \proc_open(
            [\PHP_BINARY, $copy, '--root=' . $this->tmpDir],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        $rc = \proc_close($process);
        \unlink($copy);

        $this->assertSame(2, $rc, $out . $err);
        $this->assertStringContainsString("has kind 'licencse'", $err);
        $this->assertStringContainsString('ACCOUNTED row', $err);
    }

    public function testFileFormAutoloadEntryIsALoudRefusal(): void
    {
        // RT-12 (013): a classmap entry pointing at a FILE used to be
        // dropped in silence by array_filter('is_dir') - the autoloaded
        // code and every spawn in it vanished from the census while the
        // docblock advertised classmap support. Now it refuses.
        \mkdir("$this->tmpDir/filemap-lib/src", 0777, true);
        \file_put_contents(
            "$this->tmpDir/filemap-lib/composer.json",
            (string) \json_encode([
                'name' => 'sugarcraft/filemap-lib',
                'autoload' => ['classmap' => ['src/File.php']],
            ]),
        );
        \file_put_contents(
            "$this->tmpDir/filemap-lib/src/File.php",
            "<?php\n\$p = proc_open(['true'], [0 => ['pipe', 'r']], \$pipes);\n",
        );

        [$out, $err, $rc] = $this->runTool($this->tmpDir);

        $this->assertSame(2, $rc, $out . $err);
        $this->assertStringContainsString('autoloads a file', $err);
        $this->assertStringContainsString('src/File.php', $err);
    }

    public function testNestedRootsArePrunedToTheOutermost(): void
    {
        // RT-12 (059): two roots of one manifest where one nests inside the
        // other walked the inner files twice - dedupe keys on root paths,
        // never on containment. Each .php must tokenize exactly once.
        \mkdir("$this->tmpDir/nested-lib/src/Sub", 0777, true);
        \file_put_contents(
            "$this->tmpDir/nested-lib/composer.json",
            (string) \json_encode([
                'name' => 'sugarcraft/nested-lib',
                'autoload' => ['psr-4' => [
                    'SugarCraft\\ToolFixture\\Outer\\' => 'src/',
                    'SugarCraft\\ToolFixture\\Inner\\' => 'src/Sub/',
                ]],
            ]),
        );
        foreach (['src/Up.php', 'src/Sub/Down.php'] as $rel) {
            \file_put_contents(
                "$this->tmpDir/nested-lib/$rel",
                "<?php\n\$p = proc_open(['true'], [0 => ['pipe', 'r']], \$pipes);\n",
            );
        }

        $report = \CheckChildLifetimes::derive($this->tmpDir);

        $this->assertSame(2, $report['files'], 'the inner root walked Down.php a second time');
        $this->assertSame(2, $report['sites'], 'nested double-walk tokenized a spawn twice');
    }

    /**
     * @return array{0:string,1:string,2:int} stdout, stderr, exit code
     */
    private function runTool(string $root): array
    {
        $process = \proc_open(
            [\PHP_BINARY, \dirname(__DIR__) . '/check-child-lifetimes.php', '--root=' . $root],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return [$out, $err, \proc_close($process)];
    }

    /**
     * The smallest tree the walk reads: one lib, one class body.
     */
    private function writeLib(string $lib, string $class, string $body): void
    {
        \mkdir("$this->tmpDir/$lib/src", 0777, true);
        \file_put_contents(
            "$this->tmpDir/$lib/composer.json",
            (string) \json_encode([
                'name' => "sugarcraft/$lib",
                'autoload' => ['psr-4' => ['SugarCraft\\ToolFixture\\' => 'src/']],
            ]),
        );
        \file_put_contents(
            "$this->tmpDir/$lib/src/$class.php",
            "<?php\n\ndeclare(strict_types=1);\n\nfinal class $class\n" . $body . "\n",
        );
    }
}

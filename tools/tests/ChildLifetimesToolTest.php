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
 * WHY THE GREEN POLARITIES CALL derive() RATHER THAN THE CLI: the default
 * roster describes THIS repository. A throwaway fixture tree under /tmp can
 * never satisfy it — every candy-pty/candy-core/sugar-dash/sugar-reel row
 * would read STALE there — so asserting exit 0 on a fixture would be
 * asserting the wrong contract. The red polarities still go through the CLI,
 * because what they prove is the wiring from scanner to exit code, and the
 * planted UNACCOUNTED lines are what they grep, stale rows alongside.
 *
 * HOW TO RUN: `phpunit --no-configuration tools/tests/` (CI), or locally
 * `candy-core/vendor/bin/phpunit --no-configuration tools/tests/` — see the
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
        $this->assertStringContainsString('6 findings', $out);
        $this->assertStringContainsString('24 proc_open sites', $out);
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
        $findings = [
            ['key' => $key, 'kind' => 'exposed', 'line' => 1, 'detail' => 'a'],
            ['key' => $key, 'kind' => 'exposed', 'line' => 2, 'detail' => 'b'],
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
                $findings[] = ['key' => $key, 'kind' => 'exposed', 'line' => $n, 'detail' => 'x'];
            }
        }

        $this->assertSame([], \CheckChildLifetimes::verdict($findings));
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

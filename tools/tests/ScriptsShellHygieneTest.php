<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

require_once __DIR__ . '/_bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Every tracked shell script under `scripts/` parses, is listed here with a
 * reason, and — when the host has shellcheck — passes it at error severity.
 *
 * WHY THIS ARM EXISTS. RT-08 retired the Codacy shell and RT-06 guarded the
 * org-provisioning pair with data tests, but what was LEFT of the shell four
 * had no harness at all: a syntax error in `scripts/parallel-tests.sh` only
 * surfaces at the next CI shard — inside the shard runner, on the critical
 * path of the biggest suite in the repo, which is precisely where you do not
 * want to learn that a here-doc lost its terminator. And a NEW shell file that
 * nobody looked at has no arm either way until it runs.
 *
 * THE ROSTER IS DERIVED, THE REASONS ARE NOT (house rule 18 + 40): the walk of
 * `scripts/*.sh` is recomputed every run, so a file that appears without a row
 * here goes red BY NAME before it can go unnoticed; the reason column is what
 * a reviewer argues with, and a stale row goes red in the other direction so
 * the list cannot rot into a museum.
 *
 * shellcheck IS AN OPTIONAL INSTRUMENT, NOT AN OPTIONAL STANDARD: this box and
 * ubuntu-latest carry it; the arm runs it at `-S error` when present and says
 * so in its own output. Warning severity is deliberately NOT enforced — a
 * shellcheck upgrade adding style findings must not red a suite whose actual
 * promise is "parses, and nothing at error level" (a style wave is its own
 * commit, not a stolen red).
 *
 * @internal
 */
final class ScriptsShellHygieneTest extends TestCase
{
    /**
     * Every shell file under scripts/, with the reason it exists.
     *
     * @var array<string, string>
     */
    private const ROSTER_WITH_REASON = [
        'bootstrap-org-repos.sh' => 'one-shot org provisioning; data shape guarded by ProvisioningRosterTest',
        'packagist.sh' => 'manual release helper (create/update node mirror entries)',
        'parallel-tests.sh' => 'the candy-code shard runner; semantics guarded by the trio + RT-26 tuning pins',
        'rename-remote.sh' => 'one-shot remote rename helper',
        'set-org-default-master.sh' => 'one-shot default-branch flip; data shape guarded by ProvisioningRosterTest',
    ];

    public function testEveryShellScriptParsesAndTheRosterNeitherGainsNorRots(): void
    {
        $scriptsDir = \dirname(__DIR__, 2) . '/scripts';
        $found = [];
        foreach ((array) \glob($scriptsDir . '/*.sh') as $path) {
            $found[\basename((string) $path)] = true;
        }

        $names = \array_keys($found);
        $this->assertGreaterThanOrEqual(5, \count($names), 'the scripts/ walk went near-empty — this test is vacuous');
        $this->assertArrayHasKey('parallel-tests.sh', $found, 'the shard runner left scripts/ — update every quote of it');

        $unlisted = \array_values(\array_diff($names, \array_keys(self::ROSTER_WITH_REASON)));
        $stale = \array_values(\array_diff(\array_keys(self::ROSTER_WITH_REASON), $names));
        \sort($unlisted);
        \sort($stale);
        $this->assertSame([], $unlisted, 'New shell under scripts/ with no row in ScriptsShellHygieneTest::ROSTER_WITH_REASON — list it with the reason a reviewer can argue with.');
        $this->assertSame([], $stale, 'ROSTER_WITH_REASON keeps rows for shell files that left scripts/ — delete the row with the file, not after it.');

        $unparsed = [];
        foreach ($names as $name) {
            $lines = [];
            $rc = 0;
            \exec('bash -n ' . \escapeshellarg($scriptsDir . '/' . $name) . ' 2>&1', $lines, $rc);
            if ($rc !== 0) {
                $unparsed[] = $name . ': ' . \implode(' | ', $lines);
            }
        }
        $this->assertSame(
            [],
            $unparsed,
            'These shell scripts do not parse — CI shards through parallel-tests.sh and any of '
            . 'them can be the next step anyone runs; fix the syntax, do not exempt the file.',
        );
    }

    public function testShellcheckPassesAtErrorSeverityWhenTheHostHasIt(): void
    {
        $probe = [];
        $rc = 0;
        \exec('command -v shellcheck 2>/dev/null', $probe, $rc);
        if ($rc !== 0 || $probe === []) {
            $this->markTestSkippedAsDesigned();

            return;
        }

        $scriptsDir = \dirname(__DIR__, 2) . '/scripts';
        $findings = [];
        foreach (\array_keys(self::ROSTER_WITH_REASON) as $name) {
            $lines = [];
            $code = 0;
            \exec('shellcheck -S error ' . \escapeshellarg($scriptsDir . '/' . $name) . ' 2>&1', $lines, $code);
            if ($code !== 0) {
                $findings[] = $name . ': ' . \implode(' | ', $lines);
            }
        }
        $this->assertSame(
            [],
            $findings,
            'shellcheck reports ERROR-severity defects — these are correctness findings (misused '
            . 'quoting, unbound expansion under set -u, dead redirections), not style; fix them.',
        );
    }

    /**
     * The degraded path must be LOUD and HONEST, so the decision to take it
     * lives in one place: this box proves the probe works (below), and a host
     * without the binary gets a skipped test — never a silently greener suite.
     *
     * (PHPUnit's own skip is a passthrough here, not a dodge: the roster +
     * bash -n arm above runs unconditionally.)
     */
    private function markTestSkippedAsDesigned(): void
    {
        $this->markTestSkipped('host has no shellcheck; the bash -n + roster arm above is the enforced floor');
    }

    public function testTheShellcheckProbeItselfIsReal(): void
    {
        // Rule 25 for the OPTIONAL arm: an exec probe that always returned
        // "absent" would make the shellcheck test permanently skipped and
        // permanently green. On a host WITH shellcheck this pins that the
        // probe FOUND it; on a host without, the sibling skips with the
        // designed reason. Either way the instrument reports what it sees.
        $probe = [];
        $rc = 0;
        \exec('command -v shellcheck 2>/dev/null', $probe, $rc);
        if ($rc !== 0) {
            $this->markTestSkipped('no shellcheck on this host — the skip arm above is the true state');
        }
        $this->assertStringEndsWith('shellcheck', (string) ($probe[0] ?? ''));
    }
}

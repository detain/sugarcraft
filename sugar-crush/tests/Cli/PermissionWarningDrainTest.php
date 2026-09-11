<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * E653 (round 65): the narrowed-grant flood reaches the launch transcript as
 * an AGGREGATE, never as one row per agent-tool grant.
 *
 * `Bootstrap::drainNarrowedGrantWarnings()` fans
 * {@see \SugarCraft\Crush\Agents\AgentManager::narrowedGrantWarnings()} onto
 * the transcript seam — stderr keeps the complete record (every warning whole,
 * once per process), the transcript gets at most two rows: a header packing as
 * many compact grant pairs as fit inside `LAUNCH_NOTICE_MAX_CHARS`, and an
 * "and M more" tail. Nothing here hardcodes the roster of agents or tools a
 * launch may name (E191): the unit cases feed the aggregator SYNTHETIC
 * sentences of known length so packing is arithmetically pinned, and the
 * end-to-end case reads N, K and M back out of the child launch's own
 * collector output and checks that they add up.
 */
final class PermissionWarningDrainTest extends TestCase
{
    use HomeSandboxTrait;

    private string $probeDir = '';
    private string $probeHome = '';
    private string $probeConfigDir = '';
    private string $probeRoot = '';

    /** @var array{warnings: list<string>, warnings2: list<string>, notices1: list<string>, notices2: list<string>, stderr: string}|null */
    private ?array $floodedLaunch = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->probeDir = sys_get_temp_dir() . '/perm_drain_' . uniqid('', true);
        $this->probeHome = $this->probeDir . '/home';
        $this->probeConfigDir = $this->probeHome . '/.sugar-crush';
        $this->probeRoot = $this->probeDir . '/repo';

        mkdir($this->probeConfigDir, 0o700, true);
        mkdir($this->probeRoot . '/' . LayeredSettings::dir(), 0o700, true);

        $this->useHomeSandbox($this->probeHome);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();

        exec('rm -rf ' . escapeshellarg($this->probeDir));

        parent::tearDown();
    }

    public function testTheEmptyFloodProducesNoAggregateRows(): void
    {
        self::assertSame([], $this->aggregateRows([]));
    }

    /**
     * ONE grant, ONE row, said whole: the header is the constant rendered at
     * the real count and the singular forms, followed by the compact pair —
     * the tail row is absent because the pack held everything.
     */
    public function testASingleNarrowedGrantRendersOneWholeHeaderRow(): void
    {
        $rows = $this->aggregateRows([self::grantSentence('solo', 'Read')]);

        self::assertSame(
            [
                sprintf(Bootstrap::NARROWED_GRANT_NOTICE_FORMAT, 1, '', 'was')
                    . ': agent "solo" declares "Read" in tools',
            ],
            $rows,
        );
    }

    /**
     * A pack that runs out of room closes the header where the budget says and
     * spends exactly ONE more row counting what it left behind. K is read back
     * out of the rendered row (each pair carries one ' declares '), M is parsed
     * out of the tail through the public format — and the pair that did NOT
     * fit is asserted to genuinely not have fit, so "greedy" is measured, not
     * trusted.
     */
    public function testAPackThatOverflowsAddsOneCountedTailRow(): void
    {
        $warnings = [];
        $pairs = [];
        // Long enough that only a couple fit: pair length is
        // strlen('agent "" declares "" in tools') + name + tool.
        foreach (range(1, 5) as $i) {
            $name = str_repeat('a', 120) . $i;
            $warnings[] = self::grantSentence($name, 'Read');
            $pairs[] = 'agent "' . $name . '" declares "Read" in tools';
        }

        $rows = $this->aggregateRows($warnings);
        $budget = $this->noticeCharBudget();

        self::assertCount(2, $rows);
        [$header, $tail] = $rows;

        self::assertLessThanOrEqual($budget, mb_strlen($header, 'UTF-8'));
        self::assertGreaterThanOrEqual(1, substr_count($header, ' declares '));
        self::assertStringStartsWith(
            sprintf(Bootstrap::NARROWED_GRANT_NOTICE_FORMAT, 5, 's', 'were') . ': ',
            $header,
        );

        $k = substr_count($header, ' declares ');
        self::assertMatchesRegularExpression($this->tailRegex(), $tail);
        $m = (int) preg_match($this->tailRegex(), $tail, $mm);
        self::assertSame(1, $m);
        self::assertSame(5, $k + (int) $mm[1], 'every grant is either packed or counted — never vanished');

        // Maximality: the next pair in collector order was refused, not skipped.
        self::assertGreaterThan(
            $budget,
            mb_strlen($header . '; ' . $pairs[$k], 'UTF-8'),
        );
    }

    /**
     * The aggregate never arrives clipped: clipping is the seam's last-resort
     * truncation, and a row that needed it would mean the pack miscounted its
     * own budget. Pairs far longer than the whole allowance force the K=0
     * shape — a header said ALONE (no dangling ': ') plus a tail that still
     * accounts for every grant.
     */
    public function testTheAggregateRowsNeverReachTheClipEvenWhenNothingFits(): void
    {
        $warnings = [
            self::grantSentence(str_repeat('u', 500), 'Read'),
            self::grantSentence(str_repeat('v', 500), 'Grep'),
        ];

        $rows = $this->aggregateRows($warnings);
        $budget = $this->noticeCharBudget();
        $clip = (string) (new ReflectionClass(Bootstrap::class))
            ->getConstant('LAUNCH_NOTICE_CLIP_SUFFIX');

        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertLessThanOrEqual($budget, mb_strlen($row, 'UTF-8'));
            self::assertStringNotContainsString($clip, $row);
        }

        self::assertSame(
            sprintf(Bootstrap::NARROWED_GRANT_NOTICE_FORMAT, 2, 's', 'were'),
            $rows[0],
        );
        self::assertMatchesRegularExpression($this->tailRegex(), $rows[1]);
        preg_match($this->tailRegex(), $rows[1], $mm);
        self::assertSame(2, (int) $mm[1]);
    }

    /**
     * THE KEYSTONE, end to end in a child launch (a `chat()` in-process would
     * hand the shared statics to every later test — the reason every sibling
     * harness here forks): a project that disables ten of the eleven tools
     * floods the collector, and the launch still puts TWO rows — not the flood
     * — in the transcript; stderr carries every original plus the aggregate
     * once; and a SECOND `chat()` in the same process re-seeds its transcript
     * (per-LAUNCH list) without writing any of it to stderr twice (the
     * per-process Once-guard covers originals, header and tail alike).
     *
     * N, K and M are read back out of the child's own collector dump, so this
     * pins the arithmetic of the aggregate, not a roster of agent names.
     */
    public function testAFloodedLaunchCarriesTheAggregateNotTheFloodTwicePerProcess(): void
    {
        $probe = $this->floodedLaunchProbe();

        $n = count($probe['warnings']);
        self::assertGreaterThanOrEqual(1, $n, 'the fixture must actually flood the collector');

        $headerPrefix = sprintf(
            Bootstrap::NARROWED_GRANT_NOTICE_FORMAT,
            $n,
            $n === 1 ? '' : 's',
            $n === 1 ? 'was' : 'were',
        );

        $headers = array_values(array_filter(
            $probe['notices1'],
            static fn (string $row): bool => str_starts_with($row, $headerPrefix),
        ));
        $tails = array_values(array_filter(
            $probe['notices1'],
            fn (string $row): bool => preg_match($this->tailRegex(), $row) === 1,
        ));

        self::assertCount(1, $headers, 'one aggregate header per launch');
        self::assertCount(1, $tails, 'one counted tail per launch');

        $k = substr_count($headers[0], ' declares ');
        preg_match($this->tailRegex(), $tails[0], $mm);
        self::assertSame($n, $k + (int) $mm[1]);

        // The transcript holds the aggregate, never the flood: no row carries
        // the sentence tail that only the full warnings have.
        foreach ($probe['notices1'] as $row) {
            self::assertStringNotContainsString(' if that is not the configuration', $row);
        }

        // stderr is the complete record: all N originals whole, plus the
        // packed pairs echoed inside the header line — and, after TWO chats,
        // still exactly one header and one tail (the Once-guard is per process).
        self::assertSame($n + $k, substr_count($probe['stderr'], ' declares '));
        self::assertSame(1, substr_count($probe['stderr'], 'narrowed by this session'));
        self::assertSame(1, substr_count($probe['stderr'], ' more narrowed grant'));

        // The second launch seeds an IDENTICAL transcript — the per-launch
        // list is rebuilt, so a user starting crush twice sees the notice
        // twice, and pays for it only in one launch's rows.
        self::assertSame($probe['warnings'], $probe['warnings2']);
        self::assertSame($probe['notices1'], $probe['notices2']);
    }

    /**
     * COUPLING. The packer extracts each pair through
     * `Bootstrap::NARROWED_GRANT_PAIR_PATTERN`; the collector OWNS its sentence
     * wording over in `AgentManagerTest` and no test there knows this pattern
     * exists. Re-word the collector and the extractor would silently fall back
     * to whole sentences (fail-soft by design) — this turns that quiet decay
     * into a named failure, driven by the REAL collector output from the same
     * child launch the keystone uses.
     */
    public function testEveryCollectorSentenceCarriesThePairPrefixThePackerExtracts(): void
    {
        $probe = $this->floodedLaunchProbe();
        $pattern = (string) (new ReflectionClass(Bootstrap::class))
            ->getConstant('NARROWED_GRANT_PAIR_PATTERN');

        foreach ($probe['warnings'] as $warning) {
            self::assertSame(
                1,
                preg_match($pattern, $warning),
                'AgentManager::narrowedGrantWarnings() produced a sentence whose first clause no longer '
                    . 'matches Bootstrap::NARROWED_GRANT_PAIR_PATTERN — the packer will spend transcript '
                    . 'characters on whole sentences; re-point the pattern (deliberately) in the same commit.',
            );
        }
    }

    /**
     * Run the flooded fixture through TWO `Bootstrap::chat()` calls in one
     * child process and return both transcripts, the collector's own words,
     * and everything stderr said. One child for both consumer tests: the
     * property under test is what a process that launched twice said, so the
     * two halves must come from the same stderr.
     *
     * @return array{warnings: list<string>, warnings2: list<string>, notices1: list<string>, notices2: list<string>, stderr: string}
     */
    private function floodedLaunchProbe(): array
    {
        if ($this->floodedLaunch !== null) {
            return $this->floodedLaunch;
        }

        $root = var_export((string) realpath($this->probeRoot), true);

        file_put_contents(
            $this->probeConfigDir . '/config.json',
            json_encode([LayeredSettings::PROJECT_SETTINGS_TRUST_KEY => [(string) realpath($this->probeRoot)]]),
        );
        chmod($this->probeConfigDir . '/config.json', 0o600);
        file_put_contents(
            $this->probeRoot . '/' . LayeredSettings::SHARED_PATH,
            json_encode(['disabledTools' => ['[!B]*']]),
        );

        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $script = $this->probeDir . '/drain-child.php';
        $outFile = $this->probeDir . '/drain-out.json';
        $errFile = $this->probeDir . '/drain-err.txt';

        file_put_contents($script, <<<PHP
            <?php
            require {$autoload};
            \SugarCraft\Crush\Cli\Bootstrap::useProjectRootForSettings({$root});
            \$first = \SugarCraft\Crush\Cli\Bootstrap::chat({$root});
            \$notices1 = \SugarCraft\Crush\Cli\Bootstrap::launchNotices();
            \$second = \SugarCraft\Crush\Cli\Bootstrap::chat({$root});
            \$notices2 = \SugarCraft\Crush\Cli\Bootstrap::launchNotices();
            echo json_encode([
                'warnings' => \$first->agentManager()->narrowedGrantWarnings(),
                'warnings2' => \$second->agentManager()->narrowedGrantWarnings(),
                'notices1' => \$notices1,
                'notices2' => \$notices2,
            ]);

            PHP);

        exec(sprintf(
            'HOME=%s SUGARCRUSH_PERMISSION_MODE= timeout -s KILL 60 %s %s >%s 2>%s',
            escapeshellarg($this->probeHome),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg($outFile),
            escapeshellarg($errFile),
        ));

        $decoded = json_decode((string) file_get_contents($outFile), true, 512, JSON_THROW_ON_ERROR);
        $decoded['stderr'] = (string) file_get_contents($errFile);

        /** @var array{warnings: list<string>, warnings2: list<string>, notices1: list<string>, notices2: list<string>, stderr: string} */
        $this->floodedLaunch = $decoded;

        return $this->floodedLaunch;
    }

    /**
     * The aggregator is private, and per this project's rule for private
     * seams a test reaches it by reflection rather than widening the API for
     * no src-side reader.
     *
     * @param list<string> $warnings
     *
     * @return list<string>
     */
    private function aggregateRows(array $warnings): array
    {
        $method = (new ReflectionClass(Bootstrap::class))->getMethod('narrowedGrantNoticeRows');

        /** @var list<string> $rows */
        $rows = $method->invoke(null, $warnings);

        return $rows;
    }

    /**
     * One collector-shaped sentence, built here from the same shape the pin
     * test above watches — synthetic on purpose, so the packing arithmetic is
     * under the test's control and no agent roster leaks in from src.
     */
    private static function grantSentence(string $agent, string $tool, string $field = 'tools'): string
    {
        return sprintf(
            'agent "%s" declares "%s" in %s, which this session\'s allowedTools/disabledTools removed '
                . 'from the model-facing tool set — the grant survives, narrowed; if that is not the '
                . 'configuration you meant, check disabledTools/allowedTools.',
            $agent,
            $tool,
            $field,
        );
    }

    /**
     * A matcher for a rendered tail row derived from the public overflow
     * format: `%d` the left-over count (exposed as a capture group for the
     * tests that need M), `%s` the plural marker.
     */
    private function tailRegex(): string
    {
        return '/^'
            . str_replace(['%d', '%s'], ['(\d+)', 's?'], preg_quote(Bootstrap::NARROWED_GRANT_OVERFLOW_FORMAT, '/'))
            . '$/';
    }

    private function noticeCharBudget(): int
    {
        return (int) (new ReflectionClass(Bootstrap::class))->getConstant('LAUNCH_NOTICE_MAX_CHARS');
    }
}

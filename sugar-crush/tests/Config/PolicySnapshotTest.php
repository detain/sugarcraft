<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Config\PolicySnapshot;

/**
 * rv-sec-3 (fix 8): the state file the next-launch policy-freshness notice
 * and the one-launch project-hook quarantine read and write. This file owns
 * the RECORD contract — round-trip, 0600, fail-open on corruption, and the
 * fingerprint's absence semantics; the launch-side decision (compare →
 * notice → quarantine → rewrite) is behavioural and lives with the hook
 * machinery in tests/Cli/PolicyFreshnessQuarantineTest.
 */
final class PolicySnapshotTest extends TestCase
{
    private string $dir = '';

    /** @var \Closure(): string */
    private \Closure $configDir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/policy_snapshot_' . uniqid('', true);
        mkdir($this->dir, 0700, true);
        $this->configDir = fn (): string => $this->dir;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testTheStateFileSitsNamedInsideTheConfigDirectory(): void
    {
        $this->assertSame(
            $this->dir . '/policy-snapshot.json',
            PolicySnapshot::path($this->configDir)
        );
    }

    public function testARecordSurvivesTheRoundTrip(): void
    {
        $record = [
            'version' => PolicySnapshot::VERSION,
            'userConfig' => str_repeat('a', 64),
            'userHooks' => null,
            'trustedRoots' => ['/srv/project'],
            'projectHooks' => ['/srv/project' => ['guard|PreToolUse|true']],
        ];
        PolicySnapshot::write($this->configDir, $record);

        $this->assertSame($record, PolicySnapshot::read($this->configDir));
    }

    public function testTheStateFileIsOwnerOnly(): void
    {
        PolicySnapshot::write($this->configDir, [
            'version' => PolicySnapshot::VERSION,
            'userConfig' => null,
            'userHooks' => null,
            'projectHooks' => [],
        ]);

        $mode = fileperms(PolicySnapshot::path($this->configDir)) & 0777;
        $this->assertSame(0600, $mode, 'a policy record — even hashed — is not world-readable');
    }

    public function testNothingWrittenMeansNothingToCompare(): void
    {
        $this->assertNull(PolicySnapshot::read($this->configDir), 'the first launch after install must quarantine nothing');
    }

    /**
     * Freshness is a notice, not a gate: every unreadable shape answers null
     * — the same as an absent file — rather than a crash or a permanent
     * quarantine.
     */
    public function testCorruptionAndForeignVersionsDegradeToAbsent(): void
    {
        $path = PolicySnapshot::path($this->configDir);
        $good = [
            'version' => PolicySnapshot::VERSION,
            'userConfig' => null,
            'userHooks' => null,
            'projectHooks' => [],
        ];

        $cases = [
            'not json' => '{not json',
            'a list, not an object' => '[]',
            'foreign version' => (string) json_encode(['version' => 99] + $good),
            'missing userConfig' => (string) json_encode(['version' => 1, 'userHooks' => null, 'projectHooks' => []]),
            'projectHooks not array' => (string) json_encode(['version' => 1, 'userConfig' => null, 'userHooks' => null, 'projectHooks' => 'nope']),
        ];

        foreach ($cases as $label => $bytes) {
            file_put_contents($path, $bytes);
            $this->assertNull(PolicySnapshot::read($this->configDir), $label);
        }

        // Explicit JSON null in the hashed slots is the LEGITIMATE shape
        // (file absent at snapshot time) and must parse — assert the good
        // record right after proves the loop above refused shapes, not nulls.
        PolicySnapshot::write($this->configDir, $good);
        $this->assertSame($good, PolicySnapshot::read($this->configDir));
    }

    public function testARecordFromASiblingBuildWithoutTheRootsFieldStillLoads(): void
    {
        $path = PolicySnapshot::path($this->configDir);
        file_put_contents($path, (string) json_encode([
            'version' => PolicySnapshot::VERSION,
            'userConfig' => str_repeat('b', 64),
            'userHooks' => null,
            'projectHooks' => [],
        ]));

        $record = PolicySnapshot::read($this->configDir);

        $this->assertIsArray($record);
        $this->assertArrayNotHasKey('trustedRoots', $record, 'read() must not invent the key — Bootstrap treats absent as entry-level-only quarantine');
    }

    public function testTheFingerprintIsTheBytesAndAbsenceIsNull(): void
    {
        $file = $this->dir . '/hooks.yaml';
        $this->assertNull(PolicySnapshot::fingerprint($file), 'no file yet is a state, not a failure');
        $this->assertNull(PolicySnapshot::fingerprint(null));

        file_put_contents($file, "hooks:\n");
        $first = PolicySnapshot::fingerprint($file);
        $this->assertSame(64, strlen((string) $first));
        $this->assertSame(hash('sha256', "hooks:\n"), $first);

        file_put_contents($file, "hooks:\n  changed: yes\n");
        $this->assertNotSame($first, PolicySnapshot::fingerprint($file), 'the whole point: an edit between launches moves the hash');
    }
}

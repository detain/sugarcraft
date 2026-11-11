<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Config\PolicySnapshot;

/**
 * rv-sec-3 (fix 8): the state file the next-launch policy-freshness notice
 * and the one-launch project-hook quarantine read and write. This file owns
 * the RECORD contract — round-trip, 0600, loud refusal on corruption (quiet
 * only for true absence, m19), and the
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
            'userClaudeSettings' => str_repeat('c', 64),
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
            'userClaudeSettings' => null,
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
     * m19 polarity: this is a tamper-evidence cache, so corrupt bytes are an
     * ACCUSATION, not an absence — read() refuses them loudly at the seam and
     * {@see PolicySnapshot::readOrAccuse()} folds the refusal into the launch
     * posture. The one legitimate null besides true absence is the documented
     * version-1 baseline reset, which stays quiet.
     */
    public function testTheVersionOneRecordStillResetsTheBaselineQuietly(): void
    {
        $path = PolicySnapshot::path($this->configDir);
        // C-EXT-1.b: the user's own `.claude/settings.json` joined the
        // fingerprint set and the record bumped to version 2 in the same
        // commit, so a v1 record (no such field) is the PREVIOUS schema —
        // the intended one-time reset, not corruption.
        file_put_contents($path, (string) json_encode([
            'version' => 1,
            'userConfig' => null,
            'userHooks' => null,
            'projectHooks' => [],
        ]));

        $this->assertNull(
            PolicySnapshot::read($this->configDir),
            'a v1 record answers the documented baseline reset, not an accusation'
        );
    }

    public function testCorruptionThrowsAtTheReadSeam(): void
    {
        $path = PolicySnapshot::path($this->configDir);
        $good = [
            'version' => PolicySnapshot::VERSION,
            'userConfig' => null,
            'userHooks' => null,
            'userClaudeSettings' => null,
            'projectHooks' => [],
        ];

        $cases = [
            'not json' => '{not json',
            'a list, not an object' => '[]',
            'an empty object' => '{}',
            'top-level null' => 'null',
            'foreign version' => (string) json_encode(['version' => 99] + $good),
            // A v2 record missing a required fingerprint slot could never have
            // been produced by write() — truncation or forgery; both accuse.
            'missing userConfig' => (string) json_encode(['version' => PolicySnapshot::VERSION, 'userHooks' => null, 'userClaudeSettings' => null, 'projectHooks' => []]),
            'missing userClaudeSettings' => (string) json_encode(['version' => PolicySnapshot::VERSION, 'userConfig' => null, 'userHooks' => null, 'projectHooks' => []]),
            'projectHooks not array' => (string) json_encode(['version' => PolicySnapshot::VERSION, 'userConfig' => null, 'userHooks' => null, 'userClaudeSettings' => null, 'projectHooks' => 'nope']),
        ];

        foreach ($cases as $label => $bytes) {
            file_put_contents($path, $bytes);
            $thrown = null;
            try {
                PolicySnapshot::read($this->configDir);
            } catch (\RuntimeException $e) {
                $thrown = $e;
            }

            $this->assertNotNull(
                $thrown,
                $label . ': corrupt bytes must accuse, never degrade to a quiet no-snapshot'
            );
            $this->assertStringContainsString(
                PolicySnapshot::FILE_NAME,
                (string) $thrown?->getMessage(),
                $label . ': the refusal names the file it refused'
            );
        }

        // Explicit JSON null in the hashed slots is the LEGITIMATE shape
        // (file absent at snapshot time) and must parse — asserting the good
        // record right after proves the loop above refused shapes, not nulls.
        PolicySnapshot::write($this->configDir, $good);
        $this->assertSame($good, PolicySnapshot::read($this->configDir));
    }

    public function testReadOrAccuseSeparatesAbsenceFromAccusation(): void
    {
        $good = [
            'version' => PolicySnapshot::VERSION,
            'userConfig' => null,
            'userHooks' => null,
            'userClaudeSettings' => null,
            'projectHooks' => [],
        ];

        [$record, $suspicious] = PolicySnapshot::readOrAccuse($this->configDir);
        $this->assertNull($record);
        $this->assertFalse($suspicious, 'a first launch is not tamper: true absence stays quiet');

        PolicySnapshot::write($this->configDir, $good);
        [$record, $suspicious] = PolicySnapshot::readOrAccuse($this->configDir);
        $this->assertSame($good, $record);
        $this->assertFalse($suspicious);

        file_put_contents(PolicySnapshot::path($this->configDir), '{not json');
        [$record, $suspicious] = PolicySnapshot::readOrAccuse($this->configDir);
        $this->assertNull($record);
        $this->assertTrue(
            $suspicious,
            'a corrupt record is an accusation the launch posture must fold in, not a silent skip'
        );
    }

    public function testARecordFromASiblingBuildWithoutTheRootsFieldStillLoads(): void
    {
        $path = PolicySnapshot::path($this->configDir);
        file_put_contents($path, (string) json_encode([
            'version' => PolicySnapshot::VERSION,
            'userConfig' => str_repeat('b', 64),
            'userHooks' => null,
            'userClaudeSettings' => null,
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

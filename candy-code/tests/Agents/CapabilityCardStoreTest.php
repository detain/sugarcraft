<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\CapabilityCard;
use SugarCraft\Code\Agents\CapabilityCardStore;
use SugarCraft\Code\Agents\Live\AgentRunCards;
use SugarCraft\Code\Agents\Live\SubAgentTranscriptLog;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Skills\SkillSource;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

use function escapeshellarg;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function is_dir;
use function is_file;
use function mkdir;
use function array_column;
use function symlink;
use function sys_get_temp_dir;

/**
 * The store is the whole cross-session surface: what it writes must be
 * private and byte-stable, and what it reads must survive strangers.
 *
 * Three laws, each pinned from both sides. PRIVACY: manifests carry agent
 * capability profiles from many projects, so files land 0600 and buckets
 * carry no group/other bits — set by the atomic writer's pre-rename chmod,
 * never by a hand-rolled fix-up after the bytes are already visible.
 * CONTAINMENT: a planted symlink at either level (bucket or manifest) is
 * refused, so a hostile writer with access to the home cannot point the
 * publish or discover path at content it controls. IDENTITY: every manifest
 * must name itself exactly like its file — the same shape as the run-card
 * rule that a card whose runId disagrees with its directory is junk — and a
 * manifest that fails any read gate (bad schema bytes, oversized body) is
 * skipped, never half-believed.
 *
 * The absent store must also cost nothing: discovery on a missing root is an
 * empty list, publish without a scope writes nothing, and a session that
 * never runs the verb sees every pre-existing path byte-identical.
 */
final class CapabilityCardStoreTest extends TestCase
{
    use HomeSandboxTrait;

    private const STORE_STAMP = 1_700_000_000;

    private string $sandbox = '';
    private string $home = '';
    private string $root = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-cap-store-' . bin2hex(random_bytes(6));
        $this->home = $this->useHomeSandbox($this->sandbox . '/home');
        $this->root = $this->sandbox . '/caps';
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox) . ' 2>&1');
        }
    }

    // ------------------------------------------------------------------- privacy

    public function testPublishedManifestIsPrivateBytesInAPrivateBucket(): void
    {
        $written = CapabilityCardStore::publish('sess-a', [self::capsCard('alpha')], $this->root);

        self::assertSame(['alpha'], $written);
        $path = $this->root . '/' . AgentRunCards::segment('sess-a') . '/alpha.json';
        self::assertFileExists($path);
        self::assertSame(0600, fileperms($path) & 0777, 'the mode rides the pre-rename chmod, never a visible window');
        self::assertSame(
            0,
            fileperms(dirname($path)) & 0077,
            'the bucket carries no group or other bits',
        );
    }

    public function testPublishWithoutAScopeWritesNothing(): void
    {
        self::assertSame([], CapabilityCardStore::publish('', [self::capsCard('alpha')], $this->root));
    }

    public function testRePublishOverwritesTheManifestWholly(): void
    {
        CapabilityCardStore::publish('sess-a', [self::capsCard('alpha', 'first profile')], $this->root);
        CapabilityCardStore::publish('sess-a', [self::capsCard('alpha', 'second profile')], $this->root);

        $rows = CapabilityCardStore::discover('sess-a', [], $this->root);
        self::assertCount(1, $rows);
        self::assertSame('second profile', $rows[0]['card']->description);
    }

    public function testDefaultRootFollowsTheSamePinnedHomeAsTheCards(): void
    {
        $logs = SubAgentTranscriptLog::defaultRoot();
        self::assertNotNull($logs);
        self::assertSame(
            dirname($logs) . '/capabilities',
            CapabilityCardStore::defaultRoot(),
            'the store is the mailbox root top-level neighbor, never inside mailboxes/',
        );
    }

    public function testPublishToTheDefaultRootFollowsThePinnedTranscriptNeighbour(): void
    {
        // The suite bootstrap pins the transcript root tree-wide (see
        // tests/bootstrap.php), so the honest way to pin the HOME-derived
        // spelling is to re-pin deliberately and restore what we found.
        $suitePin = SubAgentTranscriptLog::defaultRoot();
        self::assertNotNull($suitePin);
        SubAgentTranscriptLog::pinDefaultRoot($this->home . '/.candy-code/subagents');
        try {
            $root = CapabilityCardStore::defaultRoot();
            self::assertNotNull($root, 'an owned sandboxed home gives the store a default root');
            self::assertStringEndsWith('/.candy-code/capabilities', $root);

            self::assertSame(['alpha'], CapabilityCardStore::publish('sess-home', [self::capsCard('alpha')]));
            self::assertFileExists($root . '/' . AgentRunCards::segment('sess-home') . '/alpha.json');
        } finally {
            SubAgentTranscriptLog::pinDefaultRoot($suitePin);
        }
    }

    // -------------------------------------------------------------- containment

    public function testAPlantedSymlinkBucketRefusesTheWholePublish(): void
    {
        $elsewhere = $this->sandbox . '/elsewhere';
        mkdir($elsewhere, 0700, true);
        self::assertTrue(symlink($elsewhere, $this->root . '/' . AgentRunCards::segment('sess-a')));

        self::assertSame(
            [],
            CapabilityCardStore::publish('sess-a', [self::capsCard('alpha')], $this->root),
            'publish never writes through a stand-in bucket',
        );
        self::assertFileDoesNotExist($elsewhere . '/alpha.json');
    }

    public function testAPlantedSymlinkManifestIsSkippedByPublishAndDiscovery(): void
    {
        $bucket = $this->root . '/' . AgentRunCards::segment('sess-a');
        mkdir($bucket, 0700, true);
        $outside = $this->sandbox . '/forged.json';
        file_put_contents($outside, self::capsCard('alpha')->serialize());
        self::assertTrue(symlink($outside, $bucket . '/alpha.json'));

        self::assertSame(
            [],
            CapabilityCardStore::publish('sess-a', [self::capsCard('alpha')], $this->root),
            'a link under a manifest name is not ours to overwrite',
        );
        self::assertSame([], CapabilityCardStore::discover('sess-a', [], $this->root), 'and not ours to believe');
        self::assertSame(self::capsCard('alpha')->serialize(), file_get_contents($outside), 'untouched');
    }

    // ------------------------------------------------------------------ identity

    public function testAManifestMustNameItselfLikeItsFile(): void
    {
        $bucket = $this->root . '/' . AgentRunCards::segment('sess-a');
        mkdir($bucket, 0700, true);
        // right bytes under a wrong name, and wrong self-name under a right name
        file_put_contents($bucket . '/impostor.json', self::capsCard('alpha')->serialize());
        $drifted = self::capsCard('worker');
        $bytes = $drifted->serialize();
        file_put_contents($bucket . '/worker.json', $bytes);

        $rows = CapabilityCardStore::discover('sess-a', [], $this->root);
        self::assertSame(['worker'], array_column($rows, 'name'));
    }

    public function testAnOversizedManifestIsSkippedEvenWhenItParses(): void
    {
        $bucket = $this->root . '/' . AgentRunCards::segment('sess-a');
        mkdir($bucket, 0700, true);
        // Valid JSON, valid schema, valid identity — padded past the size cap
        // with whitespace json_decode happily ignores. Without the size gate
        // this would be believed; the cap is the gate being pinned.
        $padded = str_replace("\n", "\n" . str_repeat(' ', 4096), self::capsCard('alpha')->serialize());
        self::assertGreaterThan(CapabilityCardStore::MAX_MANIFEST_BYTES, strlen($padded));
        self::assertNotNull(CapabilityCard::fromJson($padded), 'the content itself would parse — only size refuses it');
        file_put_contents($bucket . '/alpha.json', $padded);

        self::assertSame([], CapabilityCardStore::discover('sess-a', [], $this->root));
    }

    public function testJunkBytesInAManifestSlotAreSkippedNotFatal(): void
    {
        $bucket = $this->root . '/' . AgentRunCards::segment('sess-a');
        mkdir($bucket, 0700, true);
        file_put_contents($bucket . '/alpha.json', '{not json');
        file_put_contents($bucket . '/notes.txt', 'ignored by extension');
        CapabilityCardStore::publish('sess-a', [self::capsCard('beta')], $this->root);

        $rows = CapabilityCardStore::discover('sess-a', [], $this->root);
        self::assertSame(['beta'], array_column($rows, 'name'));
    }

    // ----------------------------------------------------------------- semantics

    public function testDiscoveryFlagsCurrentStaleAndForeignSeparately(): void
    {
        CapabilityCardStore::publish('sess-a', [self::capsCard('alpha'), self::capsCard('ghost')], $this->root);
        CapabilityCardStore::publish('sess-b', [self::capsCard('gamma')], $this->root);

        $rows = CapabilityCardStore::discover('sess-a', ['alpha'], $this->root);

        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['name']] = $row;
        }

        self::assertSame(['alpha', 'ghost', 'gamma'], array_column($rows, 'name'), 'sorted by (session, name): own bucket first, names within it');
        self::assertTrue($byName['alpha']['current']);
        self::assertFalse($byName['alpha']['stale']);
        self::assertTrue($byName['ghost']['current'], 'a vanished own capability is surfaced, not deleted');
        self::assertTrue($byName['ghost']['stale']);
        self::assertFalse($byName['gamma']['current'], 'another session bucket is foreign by definition');
        self::assertFalse($byName['gamma']['stale'], 'we are never anyone else\'s staleness judge');
    }

    public function testAnEmptyLiveRosterSuppressesStaleness(): void
    {
        CapabilityCardStore::publish('sess-a', [self::capsCard('alpha')], $this->root);

        $rows = CapabilityCardStore::discover('sess-a', [], $this->root);
        self::assertTrue($rows[0]['current']);
        self::assertFalse($rows[0]['stale'], 'no roster means no evidence of removal — never accuse');
    }

    public function testNullScopeDiscoversEverythingAsForeign(): void
    {
        CapabilityCardStore::publish('sess-a', [self::capsCard('alpha')], $this->root);

        $rows = CapabilityCardStore::discover(null, [], $this->root);
        self::assertCount(1, $rows);
        self::assertFalse($rows[0]['current'], 'with no scope, nothing is claimed as ours');
    }

    public function testAnAbsentRootDiscoversNothing(): void
    {
        self::assertSame([], CapabilityCardStore::discover(null, [], $this->root . '/missing'));
    }

    public function testSegmentCollisionIsTheRunCardsSpellingExactly(): void
    {
        $odd = 'weird/session name:x';
        CapabilityCardStore::publish($odd, [self::capsCard('alpha')], $this->root);

        $rows = CapabilityCardStore::discover($odd, ['alpha'], $this->root);
        self::assertCount(1, $rows);
        self::assertTrue($rows[0]['current'], 'the same id normalizes to the same bucket both ways');
        self::assertSame(AgentRunCards::segment($odd), $rows[0]['session']);
    }

    // ------------------------------------------------------------------ fixtures

    private static function capsCard(string $name, string $description = 'd'): CapabilityCard
    {
        return new CapabilityCard(
            name: $name,
            description: $description,
            model: 'm',
            inheritsModel: false,
            tools: [],
            disallowedTools: [],
            mcpServers: [],
            skills: [],
            background: false,
            isolation: null,
            permissionMode: PermissionMode::Default->value,
            memory: null,
            effort: null,
            maxTurns: null,
            source: SkillSource::Native->value,
            derivedAt: self::STORE_STAMP,
        );
    }
}

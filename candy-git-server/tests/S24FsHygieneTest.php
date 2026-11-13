<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\Config;
use SugarCraft\GitServer\Git\GitDaemon;
use SugarCraft\GitServer\Repo;
use SugarCraft\GitServer\Support\AtomicWriter;
use SugarCraft\GitServer\Visibility;

/**
 * S24 — the FS-hygiene wave (findings 038, 042, 089, 090, 091, 107,
 * 116, 136): atomic publication of state files, an explicit-mode
 * directory discipline, a real lock role on the pidfile, a boot-time
 * sweep for crash residue, readable failure at the repo-root scan,
 * a scoped linked-sibling fingerprint, and entropy-named example
 * scratch instead of clock-derived paths.
 */
final class S24FsHygieneTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/gitserver-s24-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach ((array) \scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            @\chmod($path, 0700);
            \is_dir($path) && !\is_link($path) ? $this->rrmdir($path) : @\unlink($path);
        }
        @\rmdir($dir);
    }

    /** @return array{0:int,1:string,2:string} rc + stdout + stderr of a bin run */
    private function runBin(array $args, array $env = []): array
    {
        $cmd   = \array_merge([\PHP_BINARY, \dirname(__DIR__) . '/bin/candy-git-server'], $args);
        $proc  = \proc_open(
            $cmd,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            null,
            \array_merge(\getenv(), ['CANDY_GIT_SERVER_LANG' => 'en'], $env)
        );
        \fclose($pipes[0]);
        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return [\proc_close($proc), (string) $stdout, (string) $stderr];
    }

    // ---- 042: the shared atomic-write discipline ----

    public function testAtomicWriteLandsContentModeAndLeavesNoResidue(): void
    {
        $target = $this->tmpDir . '/state.yaml';
        AtomicWriter::write($target, "secret: 'yes'\n", 0600);

        $this->assertSame("secret: 'yes'\n", \file_get_contents($target));
        $this->assertSame(0600, \fileperms($target) & 0777, 'the mode is explicit, never umask-derived');
        foreach ((array) \scandir($this->tmpDir) as $entry) {
            $this->assertFalse(\str_ends_with($entry, '.tmp'), 'no temp residue after success');
        }
    }

    public function testAtomicWriteFailureKeepsOldBytesAndMintsNoTemp(): void
    {
        $dir    = $this->tmpDir . '/locked';
        \mkdir($dir, 0700, true);
        AtomicWriter::write($dir . '/state', 'OLD', 0600);

        // Directory without write permission: the temp inode itself
        // cannot be opened — the destination must not even be touched.
        \chmod($dir, 0500);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/cannot open temp/');
            AtomicWriter::write($dir . '/state', 'NEW', 0600);
        } finally {
            \chmod($dir, 0700);
        }

        $this->assertSame('OLD', \file_get_contents($dir . '/state'));
        $names = \array_values(\array_filter((array) \scandir($dir), static fn (string $n): bool => $n[0] === '.'));
        $this->assertSame(['.', '..'], $names, 'a failed exchange leaves no hidden temp');
    }

    public function testEnsureDirModeSurvivesAHostileUmask(): void
    {
        $before = \umask(0077);
        try {
            $path = $this->tmpDir . '/plane';
            AtomicWriter::ensureDir($path, 0750);
            // mkdir(0750) under umask 0077 would roll 0700 — the explicit
            // chmod after creation is what makes the law real.
            $this->assertSame(0750, \fileperms($path) & 0777);
        } finally {
            \umask($before);
        }
    }

    public function testVisibilityMarkerRoundTripsThroughTheAtomicPath(): void
    {
        $path = $this->tmpDir . '/marker.git';
        $repo = Repo::new('marker', $path);
        \mkdir($path . '/.git/objects', 0700, true);
        // A stale PUBLIC marker: replacing it must be all-or-nothing —
        // the old bare write could truncate to zero bytes and silently
        // un-publish.
        \file_put_contents($path . '/.git/' . Repo::VISIBILITY_MARKER, "public\n");

        $repo->withVisibility(Visibility::Private)->writeVisibilityMarker();

        $this->assertSame("private\n", \file_get_contents($path . '/.git/' . Repo::VISIBILITY_MARKER));
        $this->assertSame([], \glob($path . '/.git/.*.tmp') ?: [], 'the marker exchange leaves no temp');
    }

    public function testInitDescriptionSeatLeavesNoTempResidue(): void
    {
        // 042 fourth seat: the .git/description write in init went through
        // the same bare file_put_contents; the helper route must keep the
        // existing bytes contract (the 'Unnamed repository' default).
        if (!\is_executable('/usr/bin/git')) {
            $this->markTestSkipped('e2e pins require the system git binary');
        }
        $path = $this->tmpDir . '/desc.git';
        Repo::new('desc', $path)->init();

        $this->assertSame('Unnamed repository', \file_get_contents($path . '/.git/description'));
        $this->assertSame([], \glob($path . '/.git/.*.tmp') ?: []);
    }

    // ---- 090: the pidfile as a lock, with results checked ----

    private function s24Daemon(): GitDaemon
    {
        $configPath = $this->tmpDir . '/daemon.yaml';
        \file_put_contents(
            $configPath,
            "name: \"S24\"\ngit: { listen_addr: \"127.0.0.1:0\", idle_timeout: 3, allow_anonymous_read: true }\n"
        );

        return new GitDaemon(Config::load($configPath));
    }

    public function testPidfileRefusesWhileALiveDaemonHoldsTheLock(): void
    {
        $pidFile = $this->tmpDir . '/run/git-daemon.pid';
        \mkdir(\dirname($pidFile), 0700, true);
        $holder  = \fopen($pidFile, 'c');
        $this->assertIsResource($holder);
        $this->assertTrue(\flock($holder, \LOCK_EX | \LOCK_NB));
        \fwrite($holder, "424242\n");
        \fflush($holder);

        $daemon = $this->s24Daemon();
        $prop   = (new \ReflectionClass($daemon))->getProperty('pidFile');
        $prop->setAccessible(true);
        $prop->setValue($daemon, $pidFile);

        $write = new \ReflectionMethod($daemon, 'writePidFile');
        $write->setAccessible(true);
        try {
            $write->invoke($daemon);
            $this->fail('a live lock holder must refuse the claim');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('locked by a running daemon', $e->getMessage());
            $this->assertStringContainsString('424242', $e->getMessage(), 'the refusal names the holder');
        }
        $this->assertSame("424242\n", \file_get_contents($pidFile), 'a refusal never scribbles the incumbent bytes');

        \flock($holder, \LOCK_UN);
        \fclose($holder);
    }

    public function testPidfileClaimAdoptsStaleBytesThenHandsBackOnRemoval(): void
    {
        $pidFile = $this->tmpDir . '/run/git-daemon.pid';
        // A stale file from a SIGKILLed run: no live flock exists, so the
        // claim proceeds and overwrites (the 090 staleness guard).
        \mkdir(\dirname($pidFile), 0700, true);
        \file_put_contents($pidFile, "999999\n");

        $daemon = $this->s24Daemon();
        $prop   = (new \ReflectionClass($daemon))->getProperty('pidFile');
        $prop->setAccessible(true);
        $prop->setValue($daemon, $pidFile);

        $write = new \ReflectionMethod($daemon, 'writePidFile');
        $write->setAccessible(true);
        $write->invoke($daemon);
        $this->assertSame((string) \getmypid(), \file_get_contents($pidFile));

        // The file carries a live lock for the daemon's lifetime — a
        // second claimer must be refused NOW (second pid would corrupt).
        $probe = \fopen($pidFile, 'c');
        $this->assertFalse(\flock($probe, \LOCK_EX | \LOCK_NB), 'the lock rides the file while the daemon lives');

        $remove = new \ReflectionMethod($daemon, 'removePidFile');
        $remove->setAccessible(true);
        $remove->invoke($daemon);
        $this->assertFalse(\file_exists($pidFile), 'stop takes its claim off the disk');
        \fclose($probe);
    }

    // ---- 107: the boot sweep for crash-stranded temps ----

    public function testBootSweepRemovesOnlyStaleTempsOfOurs(): void
    {
        $dir = $this->tmpDir . '/plane';
        \mkdir($dir . '/lfs/ab/cd', 0700, true);
        $stale = static function (string $path): string {
            \file_put_contents($path, 'x');
            \touch($path, \time() - 7200);

            return $path;
        };
        $oldConfigTemp = $stale($dir . '/.config.yaml.abcdef123456.tmp');
        $oldUsersTemp  = $stale($dir . '/users.json.deadbeefcafe.tmp');
        $oldLfsTemp    = $stale($dir . '/lfs/ab/cd/.0123456789abcdef.tmp-deadbeefcafe0123');
        $freshTemp     = $dir . '/.config.yaml.999988887777.tmp';
        \file_put_contents($freshTemp, 'x'); // NOW in flight — must survive
        $notOurs   = $stale($dir . '/notes.txt.tmp');       // not our signature
        $farNest   = \mkdir($dir . '/lfs/ab/cd/deep', 0700, true)
            ? $stale($dir . '/lfs/ab/cd/deep/.0123456789abcdef.tmp-cafebabecafebabe')
            : $dir . '/lfs/ab/cd/deep/missing';

        $removed = AtomicWriter::sweepStaleTemps($dir, AtomicWriter::STALE_TEMP_AGE_SECONDS, 3);

        $this->assertSame(3, $removed);
        $this->assertFalse(\file_exists($oldConfigTemp));
        $this->assertFalse(\file_exists($oldUsersTemp));
        $this->assertFalse(\file_exists($oldLfsTemp));
        $this->assertTrue(\file_exists($freshTemp), 'an in-flight temp is never swept');
        $this->assertTrue(\file_exists($notOurs), 'operator scratch is not a sweep candidate');
        $this->assertTrue(\file_exists($farNest), 'the walk is depth-capped, not a catalogue');
    }

    // ---- 089: unreadable repo root is an error line, not a TypeError ----

    public function testServeRefusesAnUnreadableRepoRootWithALineNotATrace(): void
    {
        $root = $this->tmpDir . '/unreadable';
        \mkdir($root, 0700, true);
        \chmod($root, 0000);
        try {
            [$rc, $stdout, $stderr] = $this->runBin(
                ['serve', '--http', '--repo-root', $root, '--port', '0'],
                ['CANDY_GIT_SERVER_DATA_PATH' => $this->tmpDir . '/plane']
            );
            $this->assertSame(1, $rc, "expected the clean refusal, got rc={$rc} out={$stdout} err={$stderr}");
            $this->assertStringContainsString('cannot read repository root', $stderr);
            $this->assertStringNotContainsString('TypeError', $stderr, 'the (array)false scan must die as a line, not a trace');
        } finally {
            \chmod($root, 0700);
        }
    }

    // ---- 116: the linked-sibling fingerprint is scoped ----

    public function testBuildPharRefusalIsScopedToSiblingSeats(): void
    {
        $build = \file_get_contents(\dirname(__DIR__) . '/tools/build-phar.php');
        $this->assertNotFalse($build);
        // The false-accusation shape (any symlink under vendor/) is gone;
        // the refusal now fires only for vendor/sugarcraft/... links.
        $this->assertStringContainsString("preg_match('#(^|/)vendor/sugarcraft(/|\$)#', \$path)", $build);
        $this->assertStringContainsString('is_link($path) && preg_match', $build);
    }

    // ---- 136: examples carry entropy, not clock names ----

    public function testExamplesScratchIsEntropyNamed(): void
    {
        $repoExample = (string) \file_get_contents(\dirname(__DIR__) . '/examples/repo.php');
        $cfgExample  = (string) \file_get_contents(\dirname(__DIR__) . '/examples/config.php');

        $this->assertStringNotContainsString('uniqid(', $repoExample);
        $this->assertStringContainsString('random_bytes(', $repoExample);
        $this->assertStringNotContainsString("candy-git-server-test-config.yaml", $cfgExample);
        $this->assertStringContainsString('tempnam(', $cfgExample);
    }

    public function testInitBuildsAPlaneThatIsNotWorldAccessible(): void
    {
        $data = $this->tmpDir . '/plane-init';
        [$rc, $stdout, $stderr] = $this->runBin(['init', $data]);

        $this->assertSame(0, $rc, "init failed: {$stderr}");
        $this->assertStringContainsString('temp directory', $stderr, 'the /tmp default is admitted out loud');
        $this->assertSame(0700, \fileperms($data) & 0777);
        $this->assertSame(0750, \fileperms($data . '/repositories') & 0777);
        $this->assertSame(0700, \fileperms($data . '/ssh') & 0777);
        $this->assertSame(0750, \fileperms($data . '/hooks') & 0777);
        $this->assertSame(0700, \fileperms($data . '/tmp') & 0777);
        $this->assertSame(0600, \fileperms($data . '/config.yaml') & 0777);
        $this->assertSame([], \glob($data . '/.*.tmp') ?: []);
        $this->assertStringContainsString('fileowner', \file_get_contents(\dirname(__DIR__) . '/bin/candy-git-server'));
    }
}

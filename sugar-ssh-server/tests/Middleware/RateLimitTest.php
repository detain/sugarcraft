<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Tests\Middleware;

use SugarCraft\Core\I18n\T;
use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Middleware\RateLimit;
use SugarCraft\SshServer\Session;
use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase
{
    private string $statePath = '';

    /** @var list<string> extra files a test planted, removed in tearDown */
    private array $planted = [];

    protected function setUp(): void
    {
        $this->statePath = sys_get_temp_dir() . '/wish-ratelimit-' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        foreach ($this->planted as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->planted = [];

        if ($this->statePath !== '' && is_file($this->statePath)) {
            unlink($this->statePath);
        }

        // Publishing keeps a lock sidecar beside the payload (see the class
        // docblock); it is a created file like the payload and must not
        // outlive the test. AtomicJsonFile keeps a hidden lock of its own
        // (`.<basename>.lock`, which the writer deliberately never deletes)
        // and both sit beside every state path the suite touches.
        $lock = $this->statePath . '.lock';
        if (is_file($lock)) {
            unlink($lock);
        }
        $hidden = \dirname($this->statePath) . '/.' . \basename($this->statePath) . '.lock';
        if (is_file($hidden)) {
            unlink($hidden);
        }
    }

    /**
     * A path whose parent chain cannot exist for any user: an ancestor is a
     * regular file, so every mkdir/open on the way to it fails ENOTDIR.
     */
    private function unwritablePath(string $tag): string
    {
        $blocker = sys_get_temp_dir() . '/wish-ratelimit-' . $tag . '-' . uniqid() . '.blocked';
        $this->assertNotFalse(file_put_contents($blocker, ''));
        $this->planted[] = $blocker;

        return $blocker . '/nested/state.json';
    }

    private function session(string $ip): Session
    {
        return new Session(
            user: 'alice', clientHost: $ip, clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: null,
            command: null, lang: 'C.UTF-8',
        );
    }

    public function testBucketRefillsOverTime(): void
    {
        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        // burst=2, refill 0.5/s
        $rl = new RateLimit($this->statePath, 2, 0.5, $err);

        $ok = 0;
        $rl->handle(Context::background(), $this->session('1.2.3.4'), function () use (&$ok): void { $ok++; });
        $rl->handle(Context::background(), $this->session('1.2.3.4'), function () use (&$ok): void { $ok++; });
        // Third connect within tens of microseconds — should be rejected.
        $rl->handle(Context::background(), $this->session('1.2.3.4'), function () use (&$ok): void { $ok++; });

        $this->assertSame(2, $ok);
        rewind($err);
        $this->assertStringContainsString('Rate limit', (string) stream_get_contents($err));
        fclose($err);
    }

    public function testIndependentBucketsPerIp(): void
    {
        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        $rl = new RateLimit($this->statePath, 1, 0.5, $err);
        $ok = 0;
        $rl->handle(Context::background(), $this->session('1.1.1.1'), function () use (&$ok): void { $ok++; });
        $rl->handle(Context::background(), $this->session('2.2.2.2'), function () use (&$ok): void { $ok++; });
        // Each IP got its single token.
        $this->assertSame(2, $ok);
        // Now both buckets are empty.
        $rl->handle(Context::background(), $this->session('1.1.1.1'), function () use (&$ok): void { $ok++; });
        $rl->handle(Context::background(), $this->session('2.2.2.2'), function () use (&$ok): void { $ok++; });
        $this->assertSame(2, $ok);
        fclose($err);
    }

    public function testStateFilePersistedAcrossInstances(): void
    {
        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        // Drain the bucket
        (new RateLimit($this->statePath, 1, 0.0, $err))
            ->handle(Context::background(), $this->session('5.6.7.8'), function () {});
        // New instance reads back empty bucket — should reject.
        $ok = 0;
        (new RateLimit($this->statePath, 1, 0.0, $err))
            ->handle(Context::background(), $this->session('5.6.7.8'), function () use (&$ok): void { $ok++; });
        $this->assertSame(0, $ok);
        fclose($err);
    }

    private function session2(string $ip, string $user): Session
    {
        return new Session(
            user: $user, clientHost: $ip, clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: null,
            command: null, lang: 'C.UTF-8',
        );
    }

    public function testFailClosedDeniesWhenStateFileUnreadable(): void
    {
        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        // An ancestor of the path is a regular file: mkdir and fopen on the
        // way to the state file fail for every privilege level, forcing the
        // state-error branch deterministically.
        $rl = new RateLimit($this->unwritablePath('failclosed'), 5, 0.5, $err, failClosed: true);
        $ok  = 0;
        $rl->handle(Context::background(), $this->session('9.9.9.9'), function () use (&$ok): void { $ok++; });
        $this->assertSame(0, $ok, 'failClosed=true must DENY when the state file cannot be opened');
        rewind($err);
        $out = (string) stream_get_contents($err);
        $this->assertStringContainsString('denying connection', $out);
        fclose($err);
    }

    public function testSymlinkedLockSidecarDegradesAndNeverFollowsTheTarget(): void
    {
        // Audit 038: a planted symlink at the .lock name must not make the
        // gate create through it or chmod the target — the degradation
        // rides the ordinary state-error path, loudly, policy-decided.
        if (!\function_exists('symlink') || \DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('symlink arms need a platform that honours POSIX symlinks');
        }
        $victim = sys_get_temp_dir() . '/wish-ratelimit-victim-' . uniqid();
        $this->assertNotFalse(file_put_contents($victim, 'pre-existing'));
        $this->assertNotFalse(chmod($victim, 0640));
        $this->assertTrue(symlink($victim, $this->statePath . '.lock'));
        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        try {
            $rl = new RateLimit($this->statePath, 5, 0.5, $err);
            $ok = 0;
            $rl->handle(Context::background(), $this->session('9.9.9.9'), function () use (&$ok): void {
                $ok++;
            });
            rewind($err);
            $out = (string) stream_get_contents($err);
            $this->assertSame(1, $ok, 'fail-open policy still decides when the lock is poisoned');
            $this->assertStringContainsString('cannot open the lock file', $out, 'the degradation is announced');
            $this->assertStringContainsString('fail-open', $out);
            $this->assertSame('pre-existing', file_get_contents($victim), 'the target is untouched');
            \clearstatcache(true, $victim);
            $this->assertSame(0640, fileperms($victim) & 0777, 'and its mode was never chmodded through the link');
        } finally {
            @unlink($this->statePath . '.lock');
            @unlink($victim);
            @unlink($this->statePath);
        }
    }

    public function testDefaultFailsOpenButLogsWhenStateFileUnreadable(): void
    {
        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        // Same ENOTDIR shape as the fail-closed case above.
        // Default: failClosed omitted → fail-open, but now logged (no
        // longer silent).
        $rl = new RateLimit($this->unwritablePath('failopen'), 5, 0.5, $err);
        $ok = 0;
        $rl->handle(Context::background(), $this->session('9.9.9.9'), function () use (&$ok): void { $ok++; });
        $this->assertSame(1, $ok, 'default must ALLOW when the state file cannot be opened (fail-open)');
        rewind($err);
        $out = (string) stream_get_contents($err);
        $this->assertStringContainsString('fail-open', $out);
        fclose($err);
    }

    public function testPerUsernameThrottleTriggersIndependentlyOfIp(): void
    {
        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        // Per-IP is generous (never the limiter here), per-username burst=1.
        // Same account 'alice' from two DIFFERENT source IPs: the second
        // connect must be throttled by the username bucket even though its
        // IP bucket is fresh.
        $rl = new RateLimit($this->statePath, 100, 0.0, $err, userBurst: 1, userRatePerSec: 0.0);
        $ok = 0;
        $rl->handle(Context::background(), $this->session2('10.0.0.1', 'alice'), function () use (&$ok): void { $ok++; });
        $rl->handle(Context::background(), $this->session2('10.0.0.2', 'alice'), function () use (&$ok): void { $ok++; });
        $this->assertSame(1, $ok, 'username bucket must throttle alice across IPs');

        // A different account from the second IP is unaffected.
        $rl->handle(Context::background(), $this->session2('10.0.0.2', 'bob'), function () use (&$ok): void { $ok++; });
        $this->assertSame(2, $ok, 'bob has his own username bucket');
        fclose($err);
    }

    public function testPenaltyDrainsUsernameBucket(): void
    {
        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        // burst 3 on the username bucket, no refill.
        $rl = new RateLimit($this->statePath, 100, 0.0, $err, userBurst: 3, userRatePerSec: 0.0);
        // Simulate three auth failures for 'carol' — each drains a token.
        $rl->penalize($this->session2('10.0.0.9', 'carol'), 1.0);
        $rl->penalize($this->session2('10.0.0.9', 'carol'), 1.0);
        $rl->penalize($this->session2('10.0.0.9', 'carol'), 1.0);
        // Now the username bucket is empty → next connect is rejected.
        $ok = 0;
        $rl->handle(Context::background(), $this->session2('10.0.0.10', 'carol'), function () use (&$ok): void { $ok++; });
        $this->assertSame(0, $ok, 'auth-failure penalties must exhaust the username bucket');
        fclose($err);
    }

    public function testStateFileIsOwnerReadableOnly(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX file mode bits are not meaningful on Windows.');
        }
        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        (new RateLimit($this->statePath, 2, 0.5, $err))
            ->handle(Context::background(), $this->session('7.7.7.7'), function () {});
        clearstatcache();
        $this->assertFileExists($this->statePath);
        $mode = fileperms($this->statePath) & 0777;
        $this->assertSame(0600, $mode, sprintf('state file must be 0600, got %04o', $mode));
        fclose($err);
    }

    public function testPenalizeWithZeroTokensDoesNothing(): void
    {
        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        $rl = new RateLimit($this->statePath, 3, 0.0, $err);

        // Penalize with 0 tokens - should be a no-op
        $rl->penalize($this->session('10.0.0.1'), 0.0);

        // Session should still be allowed (3 tokens, 0 used = 3 left)
        $ok = 0;
        $rl->handle(Context::background(), $this->session('10.0.0.1'), function () use (&$ok): void { $ok++; });
        $this->assertSame(1, $ok, 'zero penalty should not consume tokens');
        fclose($err);
    }

    public function testPenalizeWithNegativeTokensDoesNothing(): void
    {
        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        $rl = new RateLimit($this->statePath, 3, 0.0, $err);

        // First use one token
        $rl->handle(Context::background(), $this->session('10.0.0.2'), function () {});

        // Penalize with negative - should be a no-op (tokens <= 0.0 check)
        $rl->penalize($this->session('10.0.0.2'), -1.0);

        // Should still have 2 tokens left
        $ok = 0;
        $rl->handle(Context::background(), $this->session('10.0.0.2'), function () use (&$ok): void { $ok++; });
        $this->assertSame(1, $ok, 'negative penalty should not consume tokens');
        fclose($err);
    }

    public function testTheDecisionLockRidesASidecarInodeThePayloadRenamesPast(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX inode identity is not meaningful on Windows.');
        }
        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        $rl = new RateLimit($this->statePath, 2, 0.0, $err);
        $rl->handle(Context::background(), $this->session('4.4.4.4'), function () {});

        $lock = $this->statePath . '.lock';
        clearstatcache();
        $this->assertFileExists($lock, 'the limiter must keep a lock beside the payload, not on it');

        $lockBefore = stat($lock);
        $payloadBefore = stat($this->statePath);
        $this->assertIsArray($lockBefore);
        $this->assertIsArray($payloadBefore);
        $this->assertNotSame(
            $lockBefore['ino'],
            $payloadBefore['ino'],
            'locking the payload inode is the defect: publishing renames that inode away',
        );
        $this->assertSame(0600, (int) (fileperms($lock) & 0777), 'the sidecar lives in a shared /tmp and must not be world-visible');

        // A second connect publishes again. The payload is replaced by the
        // rename (new inode); the sidecar — the thing that carries mutual
        // exclusion — must be the SAME inode, so a sibling blocked in flock
        // wakes to the lock the next writer is about to take.
        $rl->handle(Context::background(), $this->session('4.4.4.4'), function () {});
        clearstatcache();
        $lockAfter = stat($lock);
        $payloadAfter = stat($this->statePath);
        $this->assertIsArray($lockAfter);
        $this->assertIsArray($payloadAfter);
        $this->assertSame($lockBefore['ino'], $lockAfter['ino'], 'the lock inode must be stable across publishes');
        $this->assertNotSame($payloadBefore['ino'], $payloadAfter['ino'], 'publishing must still be an atomic rename');
        fclose($err);
    }

    public function testSiblingsThatContendAtOnceShareOneBurstInsteadOfEachGettingOne(): void
    {
        $dir = sys_get_temp_dir() . '/wish-ratelimit-conc-' . uniqid('', true);
        $this->assertNotFalse(mkdir($dir . '/ready', 0700, true));
        $state = $dir . '/state.json';
        $fixture = __DIR__ . '/_fixtures/ratelimit-child.php';
        $siblingCount = 6;

        try {
            /** @var array<int,\resource> $procs */
            $procs = [];
            /** @var array<int,array<int,resource>> $pipes */
            $pipes = [];
            for ($i = 0; $i < $siblingCount; $i++) {
                $pipes[$i] = [];
                $proc = proc_open(
                    [PHP_BINARY, $fixture, $state, (string) $siblingCount, $dir . '/ready', (string) $i],
                    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes[$i],
                );
                $this->assertIsResource($proc, 'proc_open of the sibling fixture failed');
                $procs[$i] = $proc;
                $this->assertNotFalse(stream_set_blocking($pipes[$i][1], false));
            }

            $reports = array_fill(0, $siblingCount, '');
            $pending = array_keys($procs);
            $deadline = microtime(true) + 15.0;
            while ($pending !== [] && microtime(true) < $deadline) {
                foreach ($pending as $position => $index) {
                    $byte = fread($pipes[$index][1], 1);
                    if ($byte === '' || $byte === false) {
                        continue;
                    }
                    $reports[$index] .= $byte;
                    unset($pending[$position]);
                }
                if ($pending !== []) {
                    usleep(2000);
                }
            }

            $diagnostics = '';
            foreach ($procs as $index => $proc) {
                $stderr = (string) stream_get_contents($pipes[$index][2]);
                if ($stderr !== '') {
                    $diagnostics .= "child {$index}: {$stderr}\n";
                }
                fclose($pipes[$index][1]);
                fclose($pipes[$index][2]);
                $status = proc_get_status($proc);
                if ($status['running']) {
                    proc_terminate($proc, 9);
                }
                proc_close($proc);
            }

            $this->assertSame(
                array_fill(0, $siblingCount, 1),
                array_map('strlen', $reports),
                'every sibling must report exactly one decision byte: ' . $diagnostics,
            );
            $allowed = substr_count(implode('', $reports), 'A');
            $this->assertSame(
                3,
                $allowed,
                'burst is 3 and refill is 0: six siblings released together may take three tokens between them, '
                    . 'not three each. Reports: ' . implode('/', $reports) . ' ' . $diagnostics,
            );
        } finally {
            // Sweep every file the run produced — the payload, this class's
            // sidecar, and AtomicJsonFile's hidden lock — before the two
            // directories can go.
            $handle = opendir($dir);
            if ($handle !== false) {
                while (($entry = readdir($handle)) !== false) {
                    if ($entry !== '.' && $entry !== '..' && $entry !== 'ready'
                        && is_file($dir . '/' . $entry)) {
                        unlink($dir . '/' . $entry);
                    }
                }
                closedir($handle);
            }
            $handle = opendir($dir . '/ready');
            if ($handle !== false) {
                while (($entry = readdir($handle)) !== false) {
                    if ($entry !== '.' && $entry !== '..') {
                        unlink($dir . '/ready/' . $entry);
                    }
                }
                closedir($handle);
            }
            $this->assertNotFalse(rmdir($dir . '/ready'));
            $this->assertNotFalse(rmdir($dir));
        }
    }

    public function testCorruptStateIsAnnouncedInsteadOfRestartingEveryoneAtFullBurst(): void
    {
        $this->assertNotFalse(file_put_contents($this->statePath, '{ this is not json'));
        $this->planted[] = $this->statePath;

        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        $ok = 0;
        (new RateLimit($this->statePath, 5, 0.5, $err, failClosed: true))
            ->handle(Context::background(), $this->session('6.6.6.6'), function () use (&$ok): void { $ok++; });

        $this->assertSame(0, $ok, 'a corrupt bucket map must not hand out a fresh full burst');
        rewind($err);
        $out = (string) stream_get_contents($err);
        $this->assertStringContainsString('state file unreadable', $out);
        $this->assertStringContainsString('denying connection', $out);
        // Audit K3: the announcement travels on a stream the SSH client can
        // read under ForceCommand, so it must not name the state file.
        $this->assertStringNotContainsString($this->statePath, $out);
        fclose($err);
    }

    public function testMalformedConsultedEntryIsAnnouncedInsteadOfSilentlyRefilling(): void
    {
        // Audit 039: the file decodes cleanly, so the corrupt-file guard
        // never fires — and refill()'s absent-means-full default read the
        // garbage entry as a freshly refilled bucket. The M5 policy has to
        // hold at entry granularity too: LOUD, decided by failClosed.
        $this->assertNotFalse(file_put_contents($this->statePath, '{"ip:6.6.6.6":"dead"}'));
        $this->planted[] = $this->statePath;

        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        $ok = 0;
        (new RateLimit($this->statePath, 5, 0.5, $err, failClosed: true))
            ->handle(Context::background(), $this->session('6.6.6.6'), function () use (&$ok): void { $ok++; });

        $this->assertSame(0, $ok, 'a malformed consulted bucket must not grant a silent full burst');
        rewind($err);
        $out = (string) stream_get_contents($err);
        $this->assertStringContainsString('malformed state entry', $out);
        $this->assertStringContainsString('denying connection', $out);
        // Path-free, same law as the corrupt-file announcement (audit K3).
        $this->assertStringNotContainsString($this->statePath, $out);
        fclose($err);
    }

    public function testMalformedConsultedEntryFollowsTheFailOpenPolicyUnderDefault(): void
    {
        // An array entry whose fields are not numeric is just as malformed
        // as the string arm — and under the DEFAULT policy the documented
        // outcome is allow-plus-notice, matching what an unreadable file
        // already means here (a filesystem problem must not lock everyone
        // out; the announcement is the operator's cue to look).
        $this->assertNotFalse(file_put_contents(
            $this->statePath,
            '{"ip:6.6.6.6":{"tokens":"lots","last":1.0}}',
        ));
        $this->planted[] = $this->statePath;

        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        $ok = 0;
        (new RateLimit($this->statePath, 5, 0.5, $err))
            ->handle(Context::background(), $this->session('6.6.6.6'), function () use (&$ok): void { $ok++; });

        $this->assertSame(1, $ok, 'fail-open policy decides a malformed entry, same as a corrupt file');
        rewind($err);
        $out = (string) stream_get_contents($err);
        $this->assertStringContainsString('malformed state entry', $out);
        $this->assertStringContainsString('degraded, allowing connection', $out);
        fclose($err);
    }

    public function testNonNumericLastFieldIsMalformedEvenWithNumericTokens(): void
    {
        // refill() reads `last` too: a garbage stamp (float)"last" casts to
        // 0 and inflates elapsed time to refill-everything — the same
        // silent restart through the other field. The token is only
        // CONSUMED in fail-open, so the distinguishing proof here is the
        // announcement itself.
        $this->assertNotFalse(file_put_contents(
            $this->statePath,
            '{"ip:6.6.6.6":{"tokens":2,"last":"never"}}',
        ));
        $this->planted[] = $this->statePath;

        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        $ok = 0;
        (new RateLimit($this->statePath, 5, 0.5, $err))
            ->handle(Context::background(), $this->session('6.6.6.6'), function () use (&$ok): void { $ok++; });

        $this->assertSame(1, $ok);
        rewind($err);
        $this->assertStringContainsString(
            'malformed state entry',
            (string) stream_get_contents($err),
            'a garbage `last` stamp must be announced, not silently refilled',
        );
        fclose($err);
    }

    public function testMalformedEntryUnderANeverConsultedKeyStaysPrunedOrPreserved(): void
    {
        // The guard speaks only for buckets the decision consults. Under
        // any other key, a garbage entry is pruneIdle()'s business exactly
        // as before: its is_array skip preserves it (an entry refill never
        // reads cannot be judged full, so it round-trips untouched).
        $this->assertNotFalse(file_put_contents($this->statePath, '{"ip:9.9.9.9":"dead"}'));
        $this->planted[] = $this->statePath;

        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        $ok = 0;
        (new RateLimit($this->statePath, 5, 0.5, $err, failClosed: true))
            ->handle(Context::background(), $this->session('6.6.6.6'), function () use (&$ok): void { $ok++; });

        // Own bucket absent (legitimately) = full burst; the stranger's
        // garbage neither grants nor refuses anything.
        $this->assertSame(1, $ok);
        rewind($err);
        $this->assertStringNotContainsString('malformed', (string) stream_get_contents($err));
        fclose($err);

        $stored = json_decode((string) file_get_contents($this->statePath), true);
        $this->assertIsArray($stored);
        $this->assertSame('dead', $stored['ip:9.9.9.9'] ?? null, 'never-consulted garbage round-trips untouched');
    }

    public function testPenalizeAnnouncesAMalformedConsultedEntryWithoutThrowing(): void
    {
        // The penalty path consults the same buckets, so it carries the
        // same loud policy — and stays best-effort: it announces through
        // the state-error path and never throws (its documented contract).
        $this->assertNotFalse(file_put_contents($this->statePath, '{"ip:6.6.6.6":"dead"}'));
        $this->planted[] = $this->statePath;

        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        (new RateLimit($this->statePath, 5, 0.5, $err))
            ->penalize($this->session('6.6.6.6'), 2.0);

        rewind($err);
        $out = (string) stream_get_contents($err);
        $this->assertStringContainsString('malformed state entry', $out);
        $this->assertStringContainsString('degraded, allowing connection', $out);
        fclose($err);
    }

    public function testIdleBucketsFromOtherSessionsArePrunedWhileLiveOnesSurvive(): void
    {
        $now = microtime(true);
        $seed = [
            // Refilled back to burst an hour ago: indistinguishable from a
            // bucket that was never written, so it goes.
            'ip:8.8.8.8' => ['tokens' => 0.0, 'last' => $now - 3600.0, 'burst' => 2, 'rate' => 0.5],
            // Drained and no time has passed: still constraining, so it stays.
            'ip:7.7.7.7' => ['tokens' => 0.0, 'last' => $now, 'burst' => 2, 'rate' => 0.5],
        ];
        $this->assertNotFalse(file_put_contents($this->statePath, (string) json_encode($seed)));

        $err = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        (new RateLimit($this->statePath, 2, 0.5, $err))
            ->handle(Context::background(), $this->session('5.5.5.5'), function () {});

        $stored = json_decode((string) file_get_contents($this->statePath), true);
        $this->assertIsArray($stored);
        $this->assertArrayNotHasKey('ip:8.8.8.8', $stored, 'a fully-refilled bucket must not accumulate forever');
        $this->assertArrayHasKey('ip:7.7.7.7', $stored, 'a bucket that still constrains someone must survive the prune');
        $this->assertArrayHasKey('ip:5.5.5.5', $stored, 'the connect that just happened is by definition live');
        fclose($err);
    }

    public function testTheStateNoticeIsTranslatedWhenTheOperatorPinsALocale(): void
    {
        // Audit MAJOR 15: the catalogues ship, but nothing selects them.
        // This is the proof that selection is possible at all - the operator
        // calls T::setLocale(), and the degradation notice follows.
        T::setLocale('de');
        try {
            $err = fopen('php://memory', 'w+');
            $this->assertNotFalse($err);
            $served = 0;
            (new RateLimit($this->unwritablePath('locale'), 5, 0.5, $err))
                ->handle(Context::background(), $this->session('9.9.9.9'), function () use (&$served): void {
                    $served++;
                });

            rewind($err);
            $notice = (string) stream_get_contents($err);
            fclose($err);

            $this->assertSame(1, $served, 'the default posture still fails open');
            $this->assertStringContainsString('Ratenbegrenzer degradiert', $notice, $notice);
            $this->assertStringContainsString('(fail-open)', $notice, 'the technical fragment survives the translation');
            $this->assertStringNotContainsString('degraded, allowing', $notice);
        } finally {
            T::setLocale('en');
        }
    }

    /**
     * An address-less session lands in the shared `ip:_unknown` bucket. The
     * docblock on `bucketsFor()` now says plainly that this is strict rather
     * than safe (audit MINOR 8); this is the behaviour that sentence stands
     * on, so that a future change to the key cannot quietly turn a shared
     * switch into per-session throttling (or the reverse) without a red.
     */
    public function testSessionsWithNoClientAddressContendOnOneBucket(): void
    {
        $served = [];
        $limiter = new RateLimit($this->statePath, 1, 0.0);

        foreach (['', '', '198.51.100.9'] as $index => $ip) {
            $who = 'session-' . $index;
            $limiter->handle(
                Context::background(),
                $this->session($ip),
                function () use (&$served, $who): void {
                    $served[] = $who;
                },
            );
        }

        $this->assertSame(
            ['session-0', 'session-2'],
            $served,
            'the second address-less session should have spent the shared bucket, the addressed one should not',
        );
    }
}

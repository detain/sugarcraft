<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;
use SugarCraft\Crush\Support\Daemonize;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Support\PrivateDir;

/**
 * `Support\Daemonize` (roadmap O-4a): ONE daemonize sequence in the two shapes
 * its callers need — the `php -r` source the `/bg` supervisor spawns, and the
 * in-process detach `serve --detach` runs — plus the pid + start-time process
 * identity, and `Support\PrivateDir`, the owner-private directory rule the
 * supervisor and the server state directory share.
 */
final class DaemonizeTest extends TestCase
{
    use ReapsForkedChildrenTrait;

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/daemonize-' . \bin2hex(\random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();
        foreach ((array) \glob($this->dir . '/*') as $file) {
            @\unlink((string) $file);
        }
        @\chmod($this->dir, 0o700);
        @\rmdir($this->dir);
    }

    public function testTheSupervisorEmbedsTheSharedSequenceVerbatim(): void
    {
        $code = Daemonize::code();

        self::assertStringContainsString('umask(0o077);', $code);
        self::assertSame(2, \substr_count($code, 'pcntl_fork()'), 'both forks are load-bearing');
        self::assertStringContainsString('posix_setsid() >= 0 || posix_setpgid(0, 0);', $code);
        self::assertLessThan(\strpos($code, 'posix_setsid'), (int) \strpos($code, 'pcntl_fork'), 'setsid sits between the forks');

        $daemon = (new BackgroundSupervisor())->buildSessionDaemonCode('/s', '/b', '/t', 'id', 'task', '/w', 'p', 'm', 5);
        self::assertStringStartsWith($code, $daemon, 'the /bg launcher runs the one sequence, not a copy');
        self::assertStringContainsString('BackgroundSessionRunner::main', $daemon);
    }

    public function testTheGeneratedSequenceIsValidPhp(): void
    {
        $tokens = \token_get_all('<?php ' . Daemonize::code());

        self::assertNotEmpty($tokens);
        self::assertSame(0, \preg_match('/\bexitNow\b/', Daemonize::code()), 'the launcher has no autoloader yet; it may only exit()');
    }

    public function testStartTimeIsTheProcessIdentityTheSupervisorUses(): void
    {
        $pid = (int) \getmypid();
        $start = Daemonize::startTime($pid);
        if ($start === null) {
            self::markTestSkipped('procfs cannot report a start time here');
        }

        self::assertSame($start, BackgroundSupervisor::procStartTime($pid));
        self::assertTrue(Daemonize::isSameProcess($pid, $start));
        self::assertTrue(Daemonize::isSameProcess($pid, null));
        self::assertFalse(Daemonize::isSameProcess($pid, $start + 1), 'a different start time is a different process');
        self::assertFalse(Daemonize::isSameProcess(0, null));
        self::assertNull(Daemonize::startTime(0));
    }

    public function testAZombieAndAReapedChildAreGone(): void
    {
        if (!\function_exists('pcntl_fork') || Daemonize::startTime((int) \getmypid()) === null) {
            self::markTestSkipped('needs pcntl and procfs');
        }

        $pid = $this->forkTracked();
        if ($pid === 0) {
            ForkedChild::exitNow(0);
        }
        self::assertGreaterThan(0, $pid);

        // Exited but not yet waited: a zombie, which has done all it will do.
        $deadline = \microtime(true) + 5.0;
        while ((\SugarCraft\Crush\Support\ProcessTree::stat($pid)['state'] ?? 'Z') !== 'Z' && \microtime(true) < $deadline) {
            \usleep(10_000);
        }
        self::assertFalse(Daemonize::isSameProcess($pid, null), 'a zombie counts as gone');

        $this->reapTrackedForkedChildren();
        self::assertFalse(Daemonize::isSameProcess($pid, null));
        self::assertNull(Daemonize::startTime($pid));
    }

    public function testDetachLeavesADaemonInItsOwnSessionWithItsStdioMoved(): void
    {
        if (Daemonize::unavailableReason() !== null || !\is_dir('/proc/self/fd')) {
            self::markTestSkipped('needs pcntl, posix, ffi and procfs: ' . (Daemonize::unavailableReason() ?? 'no /proc'));
        }

        PrivateDir::ensure($this->dir, 'test');
        $log = $this->dir . '/daemon.log';
        $ourSession = \posix_getsid(0);

        // The detach runs in a forked child so the daemon is a copy of a
        // process that leaves through exitNow(), not of PHPUnit itself.
        $child = $this->forkTracked();
        if ($child === 0) {
            Daemonize::detach($log, static function (): int {
                $stat = \SugarCraft\Crush\Support\ProcessTree::stat((int) \getmypid());
                echo \json_encode([
                    'pid' => \getmypid(),
                    'ppid' => $stat['ppid'] ?? null,
                    'sid' => \posix_getsid(0),
                    'stdin' => @\readlink('/proc/self/fd/0'),
                    'umask' => \umask(),
                ]) . "\n";
                \fwrite(\STDERR, "to-stderr\n");
                ForkedChild::exitNow(0);
            });
            ForkedChild::exitNow(0);
        }
        self::assertGreaterThan(0, $child);
        // The child returned from detach() once the intermediate was reaped.
        \pcntl_waitpid($child, $status);
        $this->forgetForkedChild($child);

        $facts = null;
        $deadline = \microtime(true) + 10.0;
        while (\microtime(true) < $deadline) {
            $text = (string) @\file_get_contents($log);
            if (\str_contains($text, 'to-stderr')) {
                $facts = \json_decode(\strtok($text, "\n"), true);
                break;
            }
            \usleep(20_000);
        }

        self::assertIsArray($facts, 'the daemon never wrote to its log: ' . (string) @\file_get_contents($log));
        self::assertNotSame($child, $facts['pid']);
        self::assertNotSame($child, $facts['ppid'], 'the daemon was reparented, not left a child of its spawner');
        self::assertNotSame($ourSession, $facts['sid'], 'setsid() did not take');
        self::assertNotSame($facts['pid'], $facts['sid'], 'the daemon leads its session: the second fork did not happen');
        self::assertSame('/dev/null', $facts['stdin']);
        self::assertSame(Daemonize::UMASK, $facts['umask']);
        self::assertSame(0o600, \fileperms($log) & 0o777, 'the log is created owner-only');
    }

    public function testPrivateDirCreatesExactlyOwnerPrivateAndRefusesAnythingElse(): void
    {
        self::assertSame($this->dir, PrivateDir::ensure($this->dir, 'test'));
        self::assertSame(0o700, \fileperms($this->dir) & 0o777);
        self::assertTrue(PrivateDir::isPrivate($this->dir));

        \chmod($this->dir, 0o750);
        self::assertFalse(PrivateDir::isPrivate($this->dir));
        self::assertStringContainsString('chmod 700', (string) PrivateDir::refusal($this->dir, 'test'));
        \chmod($this->dir, 0o700);

        $link = $this->dir . '/link';
        \symlink($this->dir, $link);
        self::assertStringContainsString('symbolic link', (string) PrivateDir::refusal($link, 'test'));

        $file = $this->dir . '/file';
        \touch($file);
        self::assertStringContainsString('not a directory', (string) PrivateDir::refusal($file, 'test'));

        if (\function_exists('posix_geteuid')) {
            self::assertStringContainsString('owned by uid', (string) PrivateDir::refusal($this->dir, 'test', \posix_geteuid() + 1));
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('test directory');
        PrivateDir::ensure($link, 'test');
    }
}

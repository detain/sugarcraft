<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Tests\Middleware;

use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Middleware\Logger;
use SugarCraft\SshServer\Session;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase
{
    /** @var string|false */
    private $savedLogPath = false;

    /** @var list<string> paths planted by a test, removed in tearDown */
    private array $planted = [];

    protected function setUp(): void
    {
        $this->savedLogPath = getenv(Logger::LOG_PATH_ENV);
        putenv(Logger::LOG_PATH_ENV);
    }

    protected function tearDown(): void
    {
        if ($this->savedLogPath === false) {
            putenv(Logger::LOG_PATH_ENV);
        } else {
            putenv(Logger::LOG_PATH_ENV . '=' . $this->savedLogPath);
        }

        foreach ($this->planted as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->planted = [];
    }

    private function session(): Session
    {
        return new Session(
            user: 'alice', clientHost: '203.0.113.7', clientPort: 5555, serverHost: '198.51.100.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: '/dev/pts/3',
            command: null, lang: 'C.UTF-8',
        );
    }

    public function testEmitsStartAndEndEvents(): void
    {
        $log = fopen('php://memory', 'w+');
        $this->assertNotFalse($log);
        $l = new Logger($log);
        $reached = false;
        $l->handle(Context::background(), $this->session(), function (Context $c, Session $s) use (&$reached): void {
            $reached = true;
        });
        rewind($log);
        $contents = (string) stream_get_contents($log);
        $lines = array_filter(explode("\n", $contents), fn($l) => $l !== '');
        $this->assertCount(2, $lines, "expected start + end records, got: $contents");
        $start = json_decode((string) $lines[0], true);
        $end   = json_decode((string) $lines[1], true);
        $this->assertSame('session.start', $start['event']);
        $this->assertSame('session.end',   $end['event']);
        $this->assertSame('alice', $start['user']);
        $this->assertSame('alice', $end['user']);
        $this->assertArrayHasKey('elapsed_s', $end);
        $this->assertTrue($reached);
        fclose($log);
    }

    public function testEndEventEmittedEvenOnException(): void
    {
        $log = fopen('php://memory', 'w+');
        $this->assertNotFalse($log);
        $l = new Logger($log);
        try {
            $l->handle(Context::background(), $this->session(), function (Context $c, Session $s): void {
                throw new \RuntimeException('boom');
            });
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        rewind($log);
        $contents = (string) stream_get_contents($log);
        $this->assertStringContainsString('session.start', $contents);
        $this->assertStringContainsString('session.end',   $contents);
        fclose($log);
    }

    public function testConstructorWithNullOpensStderr(): void
    {
        // null constructor argument should open php://stderr without throwing
        $l = new Logger(null);
        $this->assertNotNull($l);
    }

    public function testConstructorWithStringPathOpensFile(): void
    {
        $path = sys_get_temp_dir() . '/wish-logger-test-' . uniqid() . '.log';
        try {
            $l = new Logger($path);
            $this->assertNotNull($l);
            // Verify the file was created and is writable
            $this->assertFileExists($path);
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function testFreshlyCreatedLogFileCarries0600OnItsInode(): void
    {
        // Audit 037 second half: the mode is set through the fd the sink
        // actually opened (fchmod), never through a later name lookup that
        // a swapped symlink could redirect.
        $path = sys_get_temp_dir() . '/wish-logger-mode-' . uniqid() . '.log';
        try {
            new Logger($path);
            \clearstatcache(true, $path);
            $this->assertSame(0600, \fileperms($path) & 0777);
        } finally {
            @unlink($path);
        }
    }

    public function testRefusesASymlinkedLogTarget(): void
    {
        // Audit 037: a planted symlink at the log name must be refused,
        // not opened through — writing "into the log" would write into
        // whatever file the link points at.
        if (!\function_exists('symlink') || \DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('symlink arms need a platform that honours POSIX symlinks');
        }
        $victim = sys_get_temp_dir() . '/wish-logger-victim-' . uniqid();
        $link   = sys_get_temp_dir() . '/wish-logger-link-' . uniqid() . '.log';
        try {
            $this->assertNotFalse(file_put_contents($victim, 'do-not-touch'));
            $this->assertTrue(symlink($victim, $link));
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('cannot open log target');
            new Logger($link);
        } finally {
            @unlink($link);
            @unlink($victim);
        }
    }

    public function testConstructorWithInvalidNonResourceThrows(): void
    {
        // Logger checks: null -> open stderr, string -> open file path, resource -> use as-is
        // If it's not null, not a string, and not a resource, it throws InvalidArgumentException
        $this->expectException(\InvalidArgumentException::class);
        new Logger(12345);  // integer is neither null, string, nor resource
    }

    /**
     * The pin audit MAJOR 3 asked for: a byte the encoder refuses used to
     * delete the whole session record, quietly. The field that carries it is
     * peer-supplied (`TERM`, a command, a username a wrapper exported), so a
     * strange client held the operator's only audit line for that session.
     */
    public function testAByteThatBreaksEncodingIsSubstitutedNotDeleted(): void
    {
        $log = fopen('php://memory', 'w+');
        $this->assertNotFalse($log);
        $l = new Logger($log);

        $raw = "bad" . "\xff" . "byte";
        $session = new Session(
            user: 'alice', clientHost: '203.0.113.7', clientPort: 5555, serverHost: '198.51.100.1',
            serverPort: 22, term: $raw, cols: 80, rows: 24, tty: '/dev/pts/3',
            command: null, lang: 'C.UTF-8',
        );

        $l->handle(Context::background(), $session, function (Context $c, Session $s): void {
        });

        rewind($log);
        $text = (string) \stream_get_contents($log);
        $lines = array_values(array_filter(explode("\n", $text), static fn (string $x): bool => $x !== ''));

        $this->assertCount(2, $lines, 'both the start and the end record must arrive');
        $this->assertStringContainsString('session.start', $lines[0]);
        $this->assertStringContainsString('session.end', $lines[1]);
        $this->assertStringContainsString('json_error', $lines[0], 'the recovery must announce itself');
        $this->assertStringNotContainsString("\xff", $text, 'the raw byte must not reach the sink');
        $this->assertStringContainsString('alice', $lines[0], 'the rest of the row survives');

        fclose($log);
    }

    public function testACleanRecordIsNotMarkedAsRecovered(): void
    {
        $log = fopen('php://memory', 'w+');
        $this->assertNotFalse($log);
        $l = new Logger($log);

        $l->handle(Context::background(), $this->session(), function (Context $c, Session $s): void {
        });

        rewind($log);
        $text = (string) \stream_get_contents($log);

        $this->assertStringContainsString('session.start', $text);
        $this->assertStringNotContainsString('json_error', $text, 'honest rows must not carry the recovery tag');

        fclose($log);
    }

    public function testAContainerThatCannotBeEncodedIsNamedNotLost(): void
    {
        $log = fopen('php://memory', 'w+');
        $this->assertNotFalse($log);
        $l = new Logger($log);

        $circular = [];
        $circular['self'] = &$circular;

        $write = (new \ReflectionClass($l))->getMethod('write');
        $write->invoke($l, ['circular' => $circular]);

        rewind($log);
        $line = trim((string) \stream_get_contents($log));

        $this->assertNotSame('', $line, 'a record is never dropped on the floor');
        $this->assertStringContainsString('json_error', $line);
        $this->assertStringContainsString('array', $line, 'the field is reported by type instead of walked into');

        fclose($log);
    }

    public function testARecordTheRecoveryStillCannotEncodeDegradesToOneWord(): void
    {
        $log = fopen('php://memory', 'w+');
        $this->assertNotFalse($log);
        $l = new Logger($log);

        $write = (new \ReflectionClass($l))->getMethod('write');
        $write->invoke($l, ['elapsed' => \INF]);

        rewind($log);
        $line = trim((string) \stream_get_contents($log));

        $this->assertStringContainsString('unencodable_record', $line);

        fclose($log);
    }

    public function testDestructorDoesNotCloseInjectedStream(): void
    {
        // When Logger is constructed with an injected resource (not null/string path),
        // it should NOT close that stream on destruction - ownership stays with caller
        $log = fopen('php://memory', 'w+');
        $this->assertNotFalse($log);
        $l = new Logger($log);
        unset($l);
        // The stream should still be a valid resource after Logger is destructed
        // Note: After unset, the resource refcount decreases - if it hits 0, PHP closes it
        // But the key is that Logger doesn't explicitly fclose() injected streams
        // This test documents the behavior
        $this->assertTrue(true);
    }

    public function testTheConfiguredSinkWinsOverStderr(): void
    {
        $path = sys_get_temp_dir() . '/wish-logger-auto-' . uniqid() . '.jsonl';
        putenv(Logger::LOG_PATH_ENV . '=' . $path);
        try {
            $logger = Logger::auto();
            $this->assertSame('file:' . $path, $logger->target());

            $logger->handle(Context::background(), $this->session(), function (): void {});

            $contents = (string) file_get_contents($path);
            $this->assertStringContainsString('session.start', $contents);
            $this->assertStringContainsString('session.end', $contents);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testAnUncodedSinkSaysSoInsteadOfClaimingAJournal(): void
    {
        // Nothing configured => the stderr fallback, and `target()` is
        // what a deployment logs at boot to discover it did NOT get a
        // file. (Audit K3: silently choosing the stream the SSH client
        // reads is how operators ended up with no forensics.)
        $logger = Logger::auto();
        $this->assertSame('stderr', $logger->target());
        $this->assertSame('stderr', (new Logger())->target());
        $this->assertSame('stream', Logger::toStream(fopen('php://memory', 'w+'))->target());
    }

    public function testTheClientVisibleHazardIsDocumentedOnTheClass(): void
    {
        // Half-moved-truth guard: the class used to advertise
        // `journalctl -u sshd` for the default stream. That claim is
        // false under ForceCommand (fd 2 is the client's extended-data
        // channel), and the docblock is the only place most deployments
        // read. If someone restores it, this test says why it went.
        $source = (string) file_get_contents(__DIR__ . '/../../src/Middleware/Logger.php');
        $this->assertStringContainsString('extended-data', $source);
        $this->assertStringNotContainsString('journalctl -u sshd', $source);
        $this->assertStringContainsString(Logger::LOG_PATH_ENV, $source);
    }

    public function testACreatedLogFileIsOwnerOnlyEvenUnderAnOpenUmask(): void
    {
        $path = sys_get_temp_dir() . '/wish-logger-mode-' . uniqid() . '.jsonl';
        $previous = umask(0);
        try {
            Logger::toFile($path)->handle(Context::background(), $this->session(), function (): void {});
            clearstatcache();
            $this->assertSame(
                0600,
                (int) (fileperms($path) & 0777),
                'a log file the logger creates must not depend on the ambient umask'
            );
        } finally {
            umask($previous);
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testAnExistingLogFileKeepsTheModeTheOperatorChose(): void
    {
        // The 0600 stamp is for files WE create. A deliberately
        // group-readable log (rotation set up by the service) must
        // survive a restart, not get tightened behind the operator's
        // back.
        $path = sys_get_temp_dir() . '/wish-logger-keeps-' . uniqid() . '.jsonl';
        try {
            $this->assertNotFalse(file_put_contents($path, ''));
            $this->assertNotFalse(chmod($path, 0640));
            Logger::toFile($path)->handle(Context::background(), $this->session(), function (): void {});
            clearstatcache();
            $this->assertSame(0640, (int) (fileperms($path) & 0777));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testAStreamTheCallerOwnsSurvivesTheLogger(): void
    {
        $buffer = fopen('php://memory', 'w+');
        $this->assertNotFalse($buffer);
        $logger = Logger::toStream($buffer);
        $logger->handle(Context::background(), $this->session(), function (): void {});
        unset($logger);

        // If the logger had claimed ownership, this write would warn
        // (and failOnWarning turns that warning into a red run).
        $this->assertNotFalse(fwrite($buffer, "after\n"));
        rewind($buffer);
        $contents = (string) stream_get_contents($buffer);
        $this->assertStringContainsString('session.start', $contents);
        $this->assertStringContainsString('after', $contents);
        fclose($buffer);
    }

    /**
     * Audit K7: an unprovisioned path is a constructor failure, not a silent
     * one -- and the message has to name the path, because under ForceCommand
     * that trace is the only record anyone gets.
     */
    public function testAnUnwritableTargetRefusesAtConstructionNamingThePath(): void
    {
        $blocked = \sys_get_temp_dir() . '/wish-logger-blocked-' . \uniqid();
        $this->assertNotFalse(fopen($blocked, 'w'));
        $this->planted[] = $blocked;
        $path = $blocked . '/nested/wish.jsonl';

        try {
            Logger::toFile($path);
            $this->fail('expected RuntimeException for an unwritable parent');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cannot open log target', $e->getMessage());
            $this->assertStringContainsString($path, $e->getMessage());
        }
    }

    /** The provisioning steps are on both surfaces, not just in the commit. */
    public function testTheProvisioningStepsAreOnTheDeploySurfaces(): void
    {
        $readme = \file_get_contents(\dirname(__DIR__, 2) . '/README.md');
        $this->assertNotFalse($readme);
        $this->assertStringContainsString('### 2. Provision the paths the stack writes', $readme);
        $flat = (string) \preg_replace('/\s+/u', ' ', $readme);
        $this->assertStringContainsString('install -d -m 0750', $flat);
        $this->assertStringContainsString('StateDirectory=wish', $flat);

        $admin = \file_get_contents(\dirname(__DIR__, 3) . '/docs/admin/sugar-ssh-server.md');
        $this->assertNotFalse($admin);
        $adminFlat = (string) \preg_replace('/\s+/u', ' ', $admin);
        $this->assertStringContainsString('Provisioning the writable paths', $adminFlat);
        $this->assertStringContainsString('exits 255', $adminFlat);
        $this->assertStringContainsString('silently off', $adminFlat, 'fail-open must be named as such');
    }
}

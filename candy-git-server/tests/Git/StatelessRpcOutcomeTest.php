<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\Git;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\AccessControl;
use SugarCraft\GitServer\Config;
use SugarCraft\GitServer\Git\PktLine;
use SugarCraft\GitServer\Git\StatelessRpc;
use SugarCraft\GitServer\HttpSmartProtocol\Server;
use SugarCraft\GitServer\Repo;

/**
 * The negotiation outcome is TYPED: a wedged child (timeout), a response
 * past the cap (overflow) and a child that died (exit) must never share a
 * wire verdict again — the old ?string return answered all three with 413
 * "Packfile too large", lying about slow or broken children.
 */
final class StatelessRpcOutcomeTest extends TestCase
{
    private string $tmpDir;
    private string $binDir;
    private ?string $savedPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/srpc-outcome-' . \uniqid();
        \mkdir($this->binDir = $this->tmpDir . '/bin', 0700, true);
        \mkdir($this->tmpDir . '/repositories', 0755, true);
        $this->savedPath = (string) \getenv('PATH');
    }

    protected function tearDown(): void
    {
        AccessControl::instance()->setAllowAnonymousRead(false);
        if ($this->savedPath !== null) {
            \putenv('PATH=' . $this->savedPath);
        }
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testAWedgedChildIsTypedTimeoutNotOverflow(): void
    {
        $this->fakeGit('exec sleep 30');

        $result = StatelessRpc::negotiate('git-upload-pack', $this->tmpDir, "junk\n", 1 << 20, 0.4);

        self::assertSame(StatelessRpc::REASON_TIMEOUT, $result['reason'], 'a stalled child is a timeout, never a size verdict');
        self::assertSame('', $result['bytes']);
    }

    public function testAOversizedResponseIsTypedOverflowNotTimeout(): void
    {
        $this->fakeGit('head -c 4096 /dev/zero');

        $result = StatelessRpc::negotiate('git-upload-pack', $this->tmpDir, "junk\n", 64, 30.0);

        self::assertSame(StatelessRpc::REASON_OVERFLOW, $result['reason']);
        self::assertGreaterThan(64, \strlen($result['bytes']), 'the cap trips only after bytes were actually collected');
    }

    /**
     * S15 (finding 111): advertise() used to pass PHP_INT_MAX to its
     * drain — a pathological advertisement child streamed forever into the
     * parent's memory. The cap now exists and the refusal is NAMED,
     * mirroring negotiate()'s typed overflow arm (there a 413 by the
     * caller; here the info/refs route turns it into a 500 — an oversized
     * advertisement is the server's condition, not the client's payload).
     */
    public function testAdvertiseOverflowIsANamedRefusalNotAnUnboundedString(): void
    {
        $this->fakeGit('head -c 4096 /dev/zero');

        try {
            StatelessRpc::advertise('git-upload-pack', $this->tmpDir, 30.0, 64);
            self::fail('an over-cap advertisement must refuse, not accumulate');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('exceeded', $e->getMessage());
            self::assertStringContainsString('64', $e->getMessage());
        }
    }

    public function testAdvertiseStopsAtTheProductionCapConstant(): void
    {
        // The constant is a real ceiling, not PHP_INT_MAX dressed up.
        self::assertLessThan(1 << 30, StatelessRpc::ADVERTISE_MAX_RESPONSE_BYTES);
    }

    /**
     * S15 (finding 025): the reap ladder is service-weighted — a
     * receive-pack child mid-hook gets the longer TERM window before the
     * unconditional KILL. Structural pin (timing out a hook honestly in CI
     * would be a flake generator): negotiate()'s reapBounded call must
     * branch on the binary.
     */
    public function testTheReapLadderIsWeightedByService(): void
    {
        $src = (string) \file_get_contents(\dirname(__DIR__, 2) . '/src/Git/StatelessRpc.php');
        self::assertMatchesRegularExpression(
            '/self::reapBounded\(\$proc, \$pipes, \$binary === \'receive-pack\'/',
            $src,
            'negotiate() must hand receive-pack the weighted grace'
        );
        $rc = new \ReflectionClass(StatelessRpc::class);
        self::assertGreaterThan(
            (float) $rc->getConstant('TERM_GRACE_SECONDS'),
            (float) $rc->getConstant('RECEIVE_PACK_TERM_GRACE_SECONDS'),
            'the push grace must be the LONGER one'
        );
    }

    public function testADeadChildReportsExitWithItsStderrTail(): void
    {
        $this->fakeGit('echo boom from git >&2; exit 3');

        $result = StatelessRpc::negotiate('git-upload-pack', $this->tmpDir, "junk\n", 1 << 20, 10.0);

        self::assertSame(StatelessRpc::REASON_EXITED, $result['reason']);
        // PHP reports a dead child's exit code once; the reaper must latch
        // it at the first observation, not read -1 after proc_close.
        self::assertSame(3, $result['exitCode']);
        self::assertStringContainsString('boom from git', $result['stderrTail'], 'the child error must reach the operator, not the bit bucket');
    }

    /**
     * The service-header strip is a byte-slice: everything after the
     * header (and its flush) rides through at the same offset, raw. The
     * old decode-reshape threw on payloads past the per-packet cap and
     * then fed the child the UNSTRIPPED body — guaranteed protocol death
     * on exactly the real requests.
     */
    public function testStripServiceHeaderRelocatesTheRestOfTheWireByOffset(): void
    {
        // A tail that no pkt decoder would survive: 70 KiB of raw bytes,
        // NULs included, no valid packet prefixes.
        $tail = \str_repeat("\x00\x01\xfe\xff", 17500);
        self::assertGreaterThan(65520, \strlen($tail));
        $body = PktLine::encode("# service=git-upload-pack\n") . PktLine::FLUSH . $tail;

        $stripped = $this->strip($body, 'git-upload-pack');

        self::assertSame($tail, $stripped, 'strip must return the tail byte-identical, un-decoded');
    }

    public function testStripServiceHeaderLeavesForeignBodiesAlone(): void

    {
        foreach (["junk\n", '', "\x00not hex at all", PktLine::encode("# service=git-receive-pack\n") . 'x'] as $body) {
            // A receive-pack header under the upload-pack service is NOT
            // this caller's header — passing it through lets git itself
            // produce the in-band protocol error.
            self::assertSame($body, $this->strip($body, 'git-upload-pack'));
        }
    }

    /**
     * Wire half: the HTTP surface answers the two failure reasons with
     * two different statuses. 413 keeps its meaning (too large); a wedged
     * child now answers 504 instead of the old false 413.
     */
    public function testTheHttpSurfaceSeparates504FromThe413(): void
    {
        $this->fakeGit('exec sleep 30');
        \file_put_contents(
            $this->tmpDir . '/config.yaml',
            "name: \"Outcome\"\ngit: { max_timeout: 1, allow_anonymous_read: true }\n"
        );
        $config = Config::load($this->tmpDir . '/config.yaml');

        AccessControl::instance()->setAllowAnonymousRead(true);
        $server = new Server($config);
        $server->registerRepo(Repo::new('outcome.git', $this->tmpDir . '/repositories/outcome.git')->withPublic(true));

        $response = $server->handleRequest('POST', '/outcome.git/git-upload-pack', '', [], 'junk-body');

        self::assertSame(504, $response['status'], 'a stalled negotiation is a gateway timeout, not a size refusal');
        self::assertStringContainsString('timed out', $response['body']);
    }

    /**
     * A stalled advertisement child must raise, never leak truncated
     * bytes: the pre-fix null-check missed the typed timeout and would
     * hand the client a mid-frame partial as if it were complete.
     */
    public function testAdvertisementNeverReturnsTruncatedBytesOnTimeout(): void
    {
        $this->fakeGit("printf junk\nexec sleep 30");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('timed out');

        StatelessRpc::advertise('git-upload-pack', $this->tmpDir, 0.5);
    }

    private function strip(string $body, string $service): string
    {
        return (new \ReflectionMethod(StatelessRpc::class, 'stripServiceHeader'))->invoke(null, $service, $body);
    }

    private function fakeGit(string $body): void
    {
        \file_put_contents($this->binDir . '/git', "#!/bin/sh\n{$body}\n");
        \chmod($this->binDir . '/git', 0755);
        \putenv('PATH=' . $this->binDir . ':' . (string) \getenv('PATH'));
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach ((array) \scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            \is_dir($path) ? $this->removeDirectory($path) : @\unlink($path);
        }
        @\rmdir($dir);
    }
}

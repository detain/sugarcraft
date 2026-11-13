<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\SshServer\Channel\DefaultChannelHandler;
use SugarCraft\SshServer\Channel\Msg\ExecMsg;
use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Middleware;
use SugarCraft\SshServer\Middleware\Auth;
use SugarCraft\SshServer\Middleware\RateLimit;
use SugarCraft\SshServer\Server;
use SugarCraft\SshServer\Session;
use SugarCraft\SshServer\Transport\HostSshdTransport;

/**
 * The three answers a session can give, and which one it gives when
 * (audit MAJOR 9).
 *
 * Before this file existed, every refusal answered `0`. `ssh host backup.sh`
 * against a server that turned the user away therefore reported a successful
 * backup to the cron entry and the CI step that ran it, which is the class of
 * bug that outlives any amount of log reading because nothing looks wrong.
 * The contract now: the child's own code when a child ran,
 * `Server::EXIT_DENIED` when the chain stopped before serving anyone, and `0`
 * only when there genuinely was nothing to run.
 */
final class ExitStatusTest extends TestCase
{
    /** @var list<string> */
    private array $planted = [];

    protected function tearDown(): void
    {
        foreach ($this->planted as $path) {
            if (\is_file($path)) {
                @\unlink($path);
            }
        }

        $this->planted = [];
    }

    public function testTheDeniedCodeSitsInTheSignalBandAwayFromProgramAnswers(): void
    {
        // 129 is 128 + SIGHUP: the band shells use for 'this did not finish
        // normally'. It cannot collide with a program's own exit(), so a
        // refusal is never mistaken for somebody else's `1`.
        $this->assertSame(129, Server::EXIT_DENIED);
        $this->assertGreaterThan(127, Server::EXIT_DENIED);
    }

    public function testResolveStatusReportsNothingRanWhenNothingWasDecided(): void
    {
        $this->assertSame(0, Server::resolveStatus($this->someone('alice', '198.51.100.7')));
    }

    public function testResolveStatusTurnsARefusalIntoTheDeniedCode(): void
    {
        $session = $this->someone('bob', '198.51.100.7');
        $session->markRefused();

        $this->assertTrue($session->refused());
        $this->assertSame(Server::EXIT_DENIED, Server::resolveStatus($session));
    }

    public function testAChildThatAlreadyAnsweredOutranksALaterRefusal(): void
    {
        // A spawner that recorded `3` served the session and the program
        // failed. A gate downstream marking a refusal must not rewrite that
        // into 'nothing ran': the run happened, and 3 is the news.
        $session = $this->someone('alice', '198.51.100.7');
        $session->recordExitStatus(3);
        $session->markRefused();

        $this->assertSame(3, Server::resolveStatus($session));
    }

    public function testARefusedLoginReportsARefusalInsteadOfASuccessfulBackup(): void
    {
        [$err, $read] = $this->stderr();

        $status = Server::new()
            ->withTransport(new HostSshdTransport())
            ->use(new Auth(users: ['alice'], stderr: $err))
            ->serve($this->someone('bob', '203.0.113.9'));

        $this->assertSame(Server::EXIT_DENIED, $status);
        $this->assertStringContainsString('Unauthorized', $read());
    }

    public function testARefusedExecReportsARefusalAndNotASuccessfulNothing(): void
    {
        // The exec door closes through the channel handler rather than a
        // stack gate; the refusal latch is what carries the answer there
        // too (audit 004). Before the mark, `ssh host whoami` against a
        // server that refused the program reported `0` to the caller.
        $session = $this->someone('alice', '198.51.100.7');
        $stderr = \fopen('php://memory', 'w+');
        $this->assertIsResource($stderr);
        $handler = new DefaultChannelHandler(stderr: $stderr);

        $handler->handleExec(new ExecMsg('/bin/whoami'), $session);

        $this->assertSame(Server::EXIT_DENIED, Server::resolveStatus($session));
        \fclose($stderr);
    }

    public function testAnEmptyExecRequestIsNothingToRunAndStaysASuccess(): void
    {
        // The sibling pin: an empty request is NOT a refusal — zero tokens
        // decodes to "nothing to run", which the exit contract resolves to
        // success. If these two ever share a branch, this is the test that
        // notices.
        $session = $this->someone('alice', '198.51.100.7');
        $stderr = \fopen('php://memory', 'w+');
        $this->assertIsResource($stderr);
        $handler = new DefaultChannelHandler(stderr: $stderr);

        $handler->handleExec(new ExecMsg(''), $session);

        $this->assertFalse($session->refused());
        $this->assertSame(0, Server::resolveStatus($session));
        \fclose($stderr);
    }

    public function testTheSecondThrottledConnectionIsRefusedNotCompleted(): void
    {
        $state = $this->statePath();
        [$err, $read] = $this->stderr();
        $seen = (object) ['runs' => 0];

        $serve = function () use ($state, $err, $seen): int {
            return Server::new()
                ->withTransport(new HostSshdTransport())
                ->use(new RateLimit($state, burst: 1, ratePerSec: 0.0, stderr: $err))
                ->use(new class($seen) implements Middleware {
                    public function __construct(private readonly \stdClass $seen)
                    {
                    }

                    public function handle(Context $ctx, Session $session, callable $next)
                    {
                        $this->seen->runs++;
                        $next($ctx, $session);
                    }
                })
                ->serve($this->someone('alice', '203.0.113.10'));
        };

        $this->assertSame(0, $serve(), 'the first connection is served, and a served stack with no child answers 0');
        $this->assertSame(Server::EXIT_DENIED, $serve(), 'the throttled second one is a refusal, not a success');
        $this->assertSame(1, $seen->runs, 'the throttle stopped the chain before the terminal');
        $this->assertStringContainsString('Rate limit exceeded', $read());
    }

    public function testADeadContextRefusesBeforeAnythingRuns(): void
    {
        $ctx = Context::background()->withCancelable();
        $ctx->cancel();

        $seen = (object) ['ran' => false];
        $status = Server::new()
            ->withTransport(new HostSshdTransport())
            ->use(new class($seen) implements Middleware {
                public function __construct(private readonly \stdClass $seen)
                {
                }

                public function handle(Context $ctx, Session $session, callable $next)
                {
                    $this->seen->ran = true;
                    $next($ctx, $session);
                }
            })
            ->serve($this->someone('alice', '203.0.113.11'), $ctx);

        $this->assertFalse($seen->ran, 'a walk that starts dead must not run the stack');
        $this->assertSame(Server::EXIT_DENIED, $status, 'and the caller reading $? must be able to tell');
    }

    public function testWhatWasSettledUpstreamSurvivesTheRebuildsDownstream(): void
    {
        // Transports hand ONE Session down the stack, and every hop may
        // refresh geometry or protocol metadata on the way. Neither rebuild
        // is allowed to silently return the answer to 'undecided'.
        $session = $this->someone('alice', '198.51.100.7');
        $session->recordExitStatus(7);

        $resized = $session->withSize(132, 43);
        $this->assertSame(7, Server::resolveStatus($resized));
        $this->assertSame(132, $resized->cols, 'the rebuild still rebuilds the wire fields');

        $annotated = $resized->withProtocolMetadata('abc123', 'publickey', null, null, null);
        $this->assertSame(7, Server::resolveStatus($annotated));

        $refused = $this->someone('bob', '198.51.100.7');
        $refused->markRefused();
        $this->assertSame(Server::EXIT_DENIED, Server::resolveStatus($refused->withSize(80, 24)));
    }

    private function statePath(): string
    {
        $path = \sys_get_temp_dir() . '/wish-exitstatus-' . \uniqid('', true) . '.json';
        $this->planted[] = $path;
        // Both lock sidecars live in the same directory and are deliberately
        // never removed by their owners: our own `<path>.lock` and
        // AtomicJsonFile's hidden `.<basename>.lock`.
        $this->planted[] = $path . '.lock';
        $this->planted[] = \dirname($path) . '/.' . \basename($path) . '.lock';

        return $path;
    }

    /**
     * @return array{0: resource, 1: callable(): string}
     */
    private function stderr(): array
    {
        $handle = \fopen('php://memory', 'w+');
        $this->assertNotFalse($handle);

        $read = static function () use ($handle): string {
            \rewind($handle);

            return (string) \stream_get_contents($handle);
        };

        return [$handle, $read];
    }

    private function someone(string $user, string $clientHost): Session
    {
        return new Session(
            user: $user, clientHost: $clientHost, clientPort: 4141, serverHost: '192.0.2.1',
            serverPort: 22, term: 'xterm-256color', cols: 80, rows: 24, tty: null,
            command: null, lang: 'C.UTF-8',
        );
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Server;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Crush\Server\Listener;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;

/**
 * o0-spikes (c), as a regression test: a forked child that keeps the server's
 * listener holds the port after the server closes it and accepts into a
 * backlog nobody reads; one that runs
 * {@see ForkedChild::closeInheritedServerFds()} first does neither.
 *
 * Both arms run, so the test proves it can see the hazard: the control child
 * (no close) must make the re-bind fail and the late connect succeed, or the
 * fixed arm's green would mean nothing.
 */
final class ForkFdHygieneTest extends TestCase
{
    use ReapsForkedChildrenTrait;

    /** How long a child holds whatever it inherited before it leaves. */
    private const CHILD_HOLD_SECONDS = 3;

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\extension_loaded('ffi') || !\is_dir('/proc/self/fd')) {
            self::markTestSkipped('needs pcntl, ffi and /proc');
        }
    }

    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();
    }

    public function testAChildThatClosesTheRegisteredListenerReleasesThePort(): void
    {
        [$port, $held] = $this->closeServerWhileAChildHoldsIt(closeInChild: true);

        self::assertFalse($held['rebindFailed'], 'the port could not be re-bound: ' . $held['rebindError']);
        self::assertFalse($held['lateConnectAccepted'], 'a client of the closed server was accepted into a dead backlog');
        self::assertGreaterThan(0, $port);
    }

    public function testAChildThatKeepsTheListenerHoldsThePort(): void
    {
        [, $held] = $this->closeServerWhileAChildHoldsIt(closeInChild: false);

        self::assertTrue($held['rebindFailed'], 'the control child did not hold the port — this test cannot see the hazard');
        self::assertTrue($held['lateConnectAccepted'], 'the control child did not keep accepting — this test cannot see the hazard');
    }

    public function testTheChildClosesOnlyRegisteredDescriptorsAndEmptiesTheRegistry(): void
    {
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        $listener = Listener::bind('tcp://127.0.0.1:0', Loop::get());

        try {
            $pid = $this->forkTracked();
            if ($pid === 0) {
                $closed = ForkedChild::closeInheritedServerFds();
                // The unregistered socketpair end must still work; report both
                // facts on it and leave without running any shutdown.
                @\fwrite($pair[1], \sprintf('%d:%d', $closed, ForkedChild::registeredServerStreams()));
                ForkedChild::exitNow();
            }

            \stream_set_timeout($pair[0], 5);
            $report = (string) \fread($pair[0], 64);
            \pcntl_waitpid($pid, $status);
            $this->forgetForkedChild($pid);

            self::assertSame('1:0', $report, 'one listener closed, registry emptied, the unregistered pipe untouched');
            self::assertGreaterThanOrEqual(1, ForkedChild::registeredServerStreams(), 'the parent keeps its registry');
        } finally {
            $listener->close();
            \fclose($pair[0]);
            \fclose($pair[1]);
        }
    }

    public function testANeverServingProcessHasNothingToClose(): void
    {
        $before = ForkedChild::registeredServerStreams();
        $listener = Listener::bind('tcp://127.0.0.1:0', Loop::get());
        self::assertSame($before + 1, ForkedChild::registeredServerStreams());
        $listener->close();

        self::assertSame($before, ForkedChild::registeredServerStreams(), 'closing forgets');
    }

    /**
     * The turn child is where it matters, and order matters there: the close
     * must run before anything else the child does, so nothing it spawns or
     * forks first inherits the sockets. Pinned on the source because the
     * window it guards is a moment inside a forked child.
     */
    public function testTheTurnChildClosesServerDescriptorsFirst(): void
    {
        $method = new \ReflectionMethod(\SugarCraft\Crush\Backend\EngineBackend::class, 'completeAsync');
        $lines = \array_slice(
            (array) \file((string) $method->getFileName()),
            (int) $method->getStartLine() - 1,
            (int) $method->getEndLine() - (int) $method->getStartLine() + 1,
        );
        $body = \implode('', $lines);

        $branch = \strpos($body, 'if ($pid === 0) {');
        self::assertNotFalse($branch, 'completeAsync() lost its child branch');
        $statements = \preg_replace('#^\s*//.*$#m', '', \substr($body, $branch + \strlen('if ($pid === 0) {')));
        self::assertMatchesRegularExpression(
            '/^\s*\\\\?(?:SugarCraft\\\\Crush\\\\Support\\\\)?ForkedChild::closeInheritedServerFds\(\);/',
            (string) $statements,
            'the turn child does something before dropping the server sockets',
        );
    }

    /**
     * Bind a listener, fork a child that holds it (closing it first when
     * $closeInChild), close it in the parent, then try to re-bind the port
     * and connect to it while the child is still alive.
     *
     * @return array{0: int, 1: array{rebindFailed: bool, rebindError: string, lateConnectAccepted: bool}}
     */
    private function closeServerWhileAChildHoldsIt(bool $closeInChild): array
    {
        $listener = Listener::bind('tcp://127.0.0.1:0', Loop::get());
        $port = (int) $listener->port();
        $ready = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($ready);

        $pid = $this->forkTracked();
        if ($pid === 0) {
            if ($closeInChild) {
                ForkedChild::closeInheritedServerFds();
            }
            @\fwrite($ready[1], 'r');
            \sleep(self::CHILD_HOLD_SECONDS);
            ForkedChild::exitNow();
        }

        \stream_set_timeout($ready[0], 5);
        \fread($ready[0], 1);
        $listener->close();

        $rebind = @\stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
        $result = ['rebindFailed' => $rebind === false, 'rebindError' => (string) $errstr, 'lateConnectAccepted' => false];
        if ($rebind !== false) {
            \fclose($rebind);
        } else {
            $client = @\stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 1.0);
            $result['lateConnectAccepted'] = $client !== false;
            if ($client !== false) {
                \fclose($client);
            }
        }

        if ($rebind !== false) {
            // Nothing holds the port: a connect must be refused, not parked.
            $client = @\stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 1.0);
            $result['lateConnectAccepted'] = $client !== false;
            if ($client !== false) {
                \fclose($client);
            }
        }

        \posix_kill($pid, \SIGKILL);
        \pcntl_waitpid($pid, $status);
        $this->forgetForkedChild($pid);
        \fclose($ready[0]);
        \fclose($ready[1]);

        return [$port, $result];
    }
}

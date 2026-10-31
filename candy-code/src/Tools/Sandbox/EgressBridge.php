<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Sandbox;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\ServerInterface;
use React\Socket\SocketServer;
use RuntimeException;
use SugarCraft\Code\Support\ForkedChild;
use Throwable;

/**
 * In-jail half of the egress path: a TCP front-end that relays to the host
 * side's unix-socket {@see EgressProxy}.
 *
 * Sandboxed commands talk to proxies over TCP (their env conventions carry no
 * unix-socket form), while the proxy deliberately lives outside the jail on a
 * private socket. The bridge runs INSIDE the jail, binds `127.0.0.1:<port>`
 * FIRST — before forking — so the command's very first connect lands on the
 * listening backlog and can never race the listener into existence; then
 * forks, and the child `pcntl_exec`s the real command, inheriting the session
 * and controlling terminal unchanged. The parent stays behind relaying
 * TCP↔unix traffic, reaps the command, and exits with its status.
 *
 * An exec failure is the ONLY way the child branch reaches an exit call in
 * this process image — {@see ForkedChild::exitNow}(127) on that path keeps a
 * silent fork child from ever masquerading as the command (and satisfies the
 * repo's fork-exit convention).
 */
final class EgressBridge
{
    /**
     * Connect ceiling for dialling the host-side unix socket (a per-connect
     * bound, never a total-request bound — E646).
     */
    public const DIAL_TIMEOUT_SECONDS = 5.0;

    /**
     * Binds the jail-local front-end. Exposed separately from {@see run()}
     * so the bind-before-fork ordering itself is testable.
     */
    public static function bind(int $tcpPort = 0, ?LoopInterface $loop = null): ServerInterface
    {
        $loop ??= Loop::get();
        return new SocketServer('127.0.0.1:' . $tcpPort, [], $loop);
    }

    /**
     * Attaches the TCP→unix relay to a bound listener (non-blocking).
     *
     * Each inbound TCP connection dials the unix target; bytes arriving
     * before the dial lands are buffered and flushed in order, so the
     * command's pipelined first write survives the handshake.
     */
    public static function relay(ServerInterface $tcpListener, string $unixPath, ?LoopInterface $loop = null): void
    {
        $loop ??= Loop::get();
        $connector = new Connector(['timeout' => self::DIAL_TIMEOUT_SECONDS], $loop);
        $tcpListener->on('connection', static function (ConnectionInterface $tcp) use ($connector, $unixPath): void {
            $pending = '';
            $unix = null;
            $tcpClosed = false;
            $onBuffer = null;
            $onBuffer = static function (string $chunk) use (&$pending, &$unix): void {
                if (is_object($unix)) {
                    $unix->write($chunk);
                    return;
                }
                $pending .= $chunk;
            };
            $tcp->on('data', $onBuffer);
            $tcp->on('close', static function () use (&$unix, &$tcpClosed): void {
                $tcpClosed = true;
                if (is_object($unix)) {
                    $unix->close();
                }
            });
            $connector
                ->connect('unix://' . $unixPath)
                ->then(
                    static function (ConnectionInterface $dial) use ($tcp, &$unix, &$pending, &$tcpClosed, &$onBuffer): void {
                        if ($tcpClosed) {
                            $dial->close();
                            return;
                        }
                        $unix = $dial;
                        if ($pending !== '') {
                            $dial->write($pending);
                            $pending = '';
                        }
                        $dial->on('data', static function (string $chunk) use ($tcp): void {
                            $tcp->write($chunk);
                        });
                        $dial->on('close', static function () use ($tcp): void {
                            $tcp->close();
                        });
                        // Swap the buffering listener out only once the direct
                        // forward is wired — no chunk is processed twice and
                        // none is lost between the two states.
                        $tcp->removeListener('data', $onBuffer);
                        $tcp->on('data', static function (string $chunk) use ($dial): void {
                            $dial->write($chunk);
                        });
                    },
                    static function (Throwable $error) use ($tcp): void {
                        $tcp->close();
                    },
                );
        });
    }

    /**
     * Full bridge: bind first, fork, exec the command in the child, and in
     * the parent relay egress until the command exits — returning its exit
     * status (128+signal when it died on one).
     *
     * @param list<string> $programArgv
     */
    public static function run(string $unixPath, string $program, array $programArgv = [], int $tcpPort = 0, ?LoopInterface $loop = null): int
    {
        $loop ??= Loop::get();
        $listener = self::bind($tcpPort, $loop);
        $pid = pcntl_fork();
        if ($pid === -1) {
            $listener->close();
            throw new RuntimeException('Egress bridge fork failed');
        }
        if ($pid === 0) {
            @pcntl_exec($program, array_values($programArgv));
            // exec failed only — never run past this line otherwise.
            ForkedChild::exitNow(127);
        }
        self::relay($listener, $unixPath, $loop);
        $exitCode = 0;
        $timer = null;
        $timer = $loop->addPeriodicTimer(0.05, static function () use ($loop, $pid, &$exitCode, &$timer, &$listener): void {
            $status = 0;
            $done = pcntl_waitpid($pid, $status, WNOHANG);
            if ($done === 0) {
                return;
            }
            if ($done === $pid) {
                $exitCode = pcntl_wifexited($status)
                    ? pcntl_wexitstatus($status)
                    : (pcntl_wifsignaled($status) ? 128 + pcntl_wtermsig($status) : 1);
            } else {
                // ECHILD: reaped elsewhere — treat as gone.
                $exitCode = 1;
            }
            $loop->cancelTimer($timer);
            $loop->stop();
        });
        $loop->run();
        $listener->close();
        return $exitCode;
    }
}

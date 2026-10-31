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
     * C-TOOL-4.g: the loopback port the in-jail bridge answers on, and the
     * single source for the `http://127.0.0.1:<port>` proxy URLs the jail's
     * `--setenv HTTP_PROXY` family carries — the argv builder, this constant
     * and {@see run()}'s bind must agree on one port, so it is a constant,
     * not a negotiation. Fixed ports carry a theoretical collision risk with
     * a foreign listener on the same loopback port; the jail's fresh network
     * namespace means a sandboxed command can only ever reach THIS bridge at
     * it, and a host process that had bound 50774 first makes the bridge's
     * own bind fail — refusing the command loudly rather than silently
     * double-routing it.
     */
    public const JAIL_TCP_PORT = 50774;

    /**
     * The `php -r` program that starts the bridge from the jail argv tail
     * (C-TOOL-4.g). The bridge process is spawned by the jail's own argv,
     * which cannot carry structured data, so the path to the autoloader is
     * baked into the code at build time — the single source being
     * {@see \SugarCraft\Code\Background\BackgroundSupervisor::autoloadPath()}
     * at the call site. argv after the option separator is `<unixPath>
     * <tcpPort> -- <program> <args...>` — the shape {@see
     * Bubblewrap::argv()} appends; the FIRST `--` is consumed by PHP itself
     * (`php -r` reports its code string as argv[0]), the second separates
     * the bridge's own arguments from the command's, because the command may
     * legitimately contain `--` of its own. {@see runFromArgv()} owns that
     * contract, unpicked apart in tests.
     */
    public static function stub(string $autoload): string
    {
        return 'require ' . var_export($autoload, true) . '; '
            . 'exit(\SugarCraft\Code\Tools\Sandbox\EgressBridge::runFromArgv($_SERVER["argv"] ?? []));';
    }

    /**
     * The argv contract of {@see stub()}, as a method so the parse and its
     * fail-closed refusals are testable without spawning anything. A shape
     * mismatch exits 2 WITHOUT running the program: a mis-built jail tail
     * must never degrade into "command ran, no relay" (the command would
     * believe the network is reachable when nothing was forwarded) nor into
     * executing an unintended argv slot.
     *
     * @param list<string> $argv
     */
    public static function runFromArgv(array $argv): int
    {
        $sock = (string) ($argv[1] ?? '');
        $port = (string) ($argv[2] ?? '');
        if ($sock === '' || !is_numeric($port) || ($argv[3] ?? null) !== '--' || ($argv[4] ?? '') === '') {
            fwrite(STDERR, "candy-egress-bridge: expected -- <unixPath> <tcpPort> -- <program> [args...]\n");

            return 2;
        }

        return self::run($sock, (string) $argv[4], array_slice($argv, 5), (int) $port);
    }

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
                            // end() not close(): flushes bytes the relay just
                            // wrote to the client before FIN (close would
                            // discard react's userspace write buffer).
                            $tcp->end();
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
                        $tcp->end();
                    },
                );
        });
    }

    /**
     * Turn the command's first word into an executable path for
     * {@see run()}'s `pcntl_exec`. A path is a path — returned untouched.
     * A bare word is searched along PATH, the way `execvp` would, because
     * `pcntl_exec` is only `execv`. A word nothing on PATH can find comes
     * back UNCHANGED on purpose: the child's exec failure then still lands
     * on the bridge's honest 127, and no new exit code enters the jail's
     * vocabulary.
     */
    public static function resolveProgram(string $program): string
    {
        if ($program === '' || str_contains($program, '/')) {
            return $program;
        }

        $path = (string) (getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');

        foreach (explode(':', $path) as $dir) {
            $candidate = rtrim($dir, '/') . '/' . $program;
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return $program;
    }

    /**
     * Full bridge: bind first, fork, exec the command in the child, and in
     * the parent relay egress until the command exits — returning its exit
     * status (128+signal when it died on one).
     *
     * The program is resolved through {@see resolveProgram()} before the
     * fork: the command arrives exactly as the shell would have seen it
     * (`curl -s http://…` — a bare first word), and `pcntl_exec` is `execv`,
     * which searches no PATH at all. Without this leg every ordinary
     * command in an `allowlist` jail would die at 127 — a mode-wiring
     * defect, not a network one, and the direct contradiction of the
     * promise that `allowlist` changes only the network path.
     *
     * @param list<string> $programArgv
     */
    public static function run(string $unixPath, string $program, array $programArgv = [], int $tcpPort = 0, ?LoopInterface $loop = null): int
    {
        $program = self::resolveProgram($program);
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

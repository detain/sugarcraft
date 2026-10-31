<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Sandbox;

use RuntimeException;
use SugarCraft\Code\Background\BackgroundSupervisor;
use SugarCraft\Code\Support\ProcessContainment;
use SugarCraft\Code\Support\ProcessReaper;

/**
 * Launch-scoped runner for the egress proxy child process.
 *
 * Mirrors the live-worker shape of {@see \SugarCraft\Code\Agents\ProcessExecutor}:
 * `php -r <stub>` under {@see ProcessContainment::spawnSpec()} so the child is
 * group-ownable, `bypass_shell`, fail-fast environment. The stub itself is a
 * one-frame bootstrap over STDIN: it loads THIS installation's autoloader (the
 * one already executing the parent — the worker never guesses at a vendor
 * tree) and hands control to {@see EgressProxy::serve()}.
 *
 * Liveness contract: the child watches its stdin pipe and its parent pid. The
 * parent keeps `$pipes[0]` open for the whole launch (its EOF therefore means
 * the parent died or called stop()) and marks its inherited descriptors
 * close-on-exec, so a later fork+exec of a sandboxed command can never leave a
 * descendant holding the write-end open behind the parent's back. stderr goes
 * to a 0600 log file BESIDE the socket — never /dev/null — so a child that
 * dies at boot leaves a diagnosable trace instead of silence.
 *
 * Paths stay deliberately short: the unix socket lives at `<tmp>/cceXXXXXX/s`
 * (mkdtemp-style name under sys_get_temp_dir()), because unix socket paths cap
 * at ~104–108 bytes and a deep `$HOME`-based directory would fail to bind on
 * macOS.
 */
final class EgressProxyProcess
{
    /**
     * Bootstrap stub run via `php -r` (executed WITHOUT an opening tag).
     * Reads one JSON frame from STDIN, then serves the proxy.
     */
    public const STUB = <<<'PHP'
        $line = fgets(STDIN);
        $config = $line === false ? null : json_decode($line, true);
        if (!is_array($config) || !isset($config['autoload'], $config['socket'])) {
            fwrite(STDERR, "egress proxy: no configuration frame on stdin\n");
            exit(1);
        }
        $autoload = (string) $config['autoload'];
        if (!is_file($autoload)) {
            fwrite(STDERR, 'Egress proxy autoloader is not readable: ' . $autoload . "\n");
            exit(1);
        }
        require $autoload;
        \SugarCraft\Code\Tools\Sandbox\EgressProxy::serve(
            (string) $config['socket'],
            is_array($config['entries'] ?? null) ? $config['entries'] : []
        );
        PHP;

    /** @var resource|false|null */
    private mixed $process = null;

    /** @var array<int, mixed> */
    private array $pipes = [];

    private ?int $groupPid = null;

    private string $directory = '';

    private string $socketPath = '';

    private string $logPath = '';

    /**
     * @param list<string> $entries sandboxAllowedDomains-shaped grant entries
     */
    public function __construct(private readonly array $entries = [])
    {
    }

    /**
     * Spawns the proxy child and returns once its unix socket path exists.
     *
     * The bind happens in the child; callers that must issue their first
     * request should poll {@see socketPath()} with a bounded loop (the path
     * appears the instant the listener is up, which is exactly the readiness
     * signal).
     */
    public function start(): void
    {
        if ($this->process !== null) {
            return;
        }
        $base = rtrim(sys_get_temp_dir(), '/');
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = $base . '/cce' . substr(bin2hex(random_bytes(4)), 0, 6);
            if (@mkdir($candidate, 0700) && is_dir($candidate)) {
                $this->directory = $candidate;
                break;
            }
        }
        if ($this->directory === '') {
            throw new RuntimeException('Failed to create the egress proxy socket directory');
        }
        // Keep the FULL path short (design risk): socket 's', log 'e.log'.
        $this->socketPath = $this->directory . '/s';
        $this->logPath = $this->directory . '/e.log';
        $logSeed = @fopen($this->logPath, 'ab');
        if (is_resource($logSeed)) {
            fclose($logSeed);
            @chmod($this->logPath, 0600);
        }

        $autoload = BackgroundSupervisor::autoloadPath();
        $frame = json_encode([
            'autoload' => $autoload,
            'socket' => $this->socketPath,
            'entries' => array_values($this->entries),
        ]) . "\n";

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin — frame, then held open for EOF liveness
            1 => ['pipe', 'w'],  // stdout — drained by nobody, closed on stop
            2 => ['file', $this->logPath, 'a'],
        ];

        // E672 parity with the live worker: under the setsid wrapper an
        // unspawnable PHP_BINARY surfaces only as the child's exit status, so
        // the executable pre-check keeps "failed to spawn" loud at the call.
        if (ProcessContainment::detachedSpawnBinary() !== '' && !is_executable(PHP_BINARY)) {
            $this->discardScaffold();
            throw new RuntimeException('Failed to spawn egress proxy process');
        }

        $process = @proc_open(
            ProcessContainment::spawnSpec([PHP_BINARY, '-r', self::STUB]),
            $descriptors,
            $pipes,
            null,
            ProcessContainment::env(),
            ['bypass_shell' => true]
        );

        if ($process === false || !is_resource($process)) {
            if (isset($pipes) && is_array($pipes)) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
            }
            $this->discardDirectory();
            throw new RuntimeException('Failed to spawn egress proxy process');
        }

        $this->process = $process;
        $this->pipes = $pipes;
        // ISS-014 discipline: measure the group at spawn, before the child
        // can exec anything further, so stop() can signal the whole tree.
        $this->groupPid = ProcessContainment::groupId($process);

        // The write-end must not survive into any later fork+exec of ours:
        // a grandchild holding it would keep the proxy alive past the launch
        // that made it (the EOF liveness signal would never arrive).
        foreach ([0, 1] as $index) {
            if (is_resource($pipes[$index] ?? null)) {
                ProcessContainment::closeOnExec($pipes[$index]);
            }
        }

        $written = @fwrite($this->pipes[0], $frame);
        if ($written === false) {
            $this->stop();
            throw new RuntimeException('Failed to feed the egress proxy configuration frame');
        }
    }

    /**
     * The unix socket the child serves, valid once {@see start()} returned.
     */
    public function socketPath(): string
    {
        return $this->socketPath;
    }

    /**
     * @return string The 0600 stderr log beside the socket (never discarded).
     */
    public function logPath(): string
    {
        return $this->logPath;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function running(): bool
    {
        return is_resource($this->process);
    }

    /**
     * Signals the recorded group, reaps, and removes the socket directory.
     * Idempotent: safe after {@see __destruct()} already cleaned up.
     */
    public function stop(): void
    {
        if (is_resource($this->process)) {
            ProcessReaper::terminateAndClose($this->process, $this->groupPid);
        }
        $this->process = null;
        $this->groupPid = null;
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        $this->pipes = [];
        $this->discardScaffold();
    }

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * Removes the socket, log and directory; the directory only goes once
     * both files are gone, so every failure path runs the same ladder.
     */
    private function discardScaffold(): void
    {
        if ($this->directory !== '') {
            @unlink($this->socketPath);
            @unlink($this->logPath);
            @rmdir($this->directory);
        }
        $this->directory = '';
        $this->socketPath = '';
        $this->logPath = '';
    }
}

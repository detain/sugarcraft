<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchNotices;
use SugarCraft\Code\Lang;

/**
 * `${keychain:<name>}` lookups for provider keys held in the OS keychain.
 *
 * C-SEC-8.e: a provider config value may spell `${keychain:candy-openai}` and
 * resolve against the operator's secret store — `secret-tool lookup` on
 * freedesktop Linux, `security find-generic-password -w` on macOS. Both
 * spellings carry only the service and the entry name in argv and print the
 * secret on stdout, so the secret never appears in the child's process-listing
 * entry the way an argv-borne token would. Entries live under the
 * {@see SERVICE} name.
 *
 * Every lookup is memoised for the process — hits AND misses — so a config
 * that references one entry in several fields pays one spawn per launch. A
 * miss never aborts the launch: it resolves to the empty string, the shape an
 * unset `${VAR}` already takes through {@see \SugarCraft\Code\Providers\ProviderFactory::resolveEnv()},
 * and the reason surfaces as a launch notice naming the entry. Windows (and
 * any other family) has no backend here; the notice says so plainly instead of
 * pretending a spawn happened.
 *
 * The bound on one backend call is {@see TIMEOUT_SECONDS}. E646's ban on a
 * blanket total-request timeout targets LLM calls; a keychain helper answers in
 * milliseconds or is wedged behind a dead daemon, so a generous wall here
 * protects the launch rather than truncating work.
 *
 * Storing entries (`Keychain::store()`, secret on stdin) is C-SEC-8.f's scope;
 * this step only reads.
 */
final class Keychain
{
    /**
     * The keychain service attribute CandyCode's entries live under —
     * `secret-tool lookup service candy-code` / `security -s candy-code`.
     */
    public const SERVICE = 'candy-code';

    /** Wall bound on one backend call; see the class note on E646. */
    public const TIMEOUT_SECONDS = 5.0;

    /** Runaway-output guard: a secret is one line, this is not that. */
    private const MAX_STDOUT_BYTES = 65536;

    /**
     * Poll slice for the exit-await loop, mirroring
     * {@see ProcessReaper::POLL_INTERVAL_US}.
     */
    private const POLL_INTERVAL_US = 5000;

    /** @var array<string,string|null> memoised hits and misses by entry name */
    private static array $memo = [];

    /** @var (Closure(list<string>):array{status:string,stdout:string,exitCode:int})|null */
    private static ?\Closure $spawnSeam = null;

    /** Test-only OS family override; null reads the live {@see PHP_OS_FAMILY}. */
    private static ?string $osOverride = null;

    /** Test-only shrink of {@see TIMEOUT_SECONDS} for the reaper-path pin. */
    private static ?float $timeoutOverride = null;

    private function __construct()
    {
    }

    /**
     * Read one entry, memoised for the process.
     *
     * @return string|null the secret, or null for every miss shape — an
     *                     unset entry, a missing binary, a timeout, an empty
     *                     name, or a family with no backend. Each miss has
     *                     already raised its launch notice by the time this
     *                     returns; callers resolve null to `''` exactly where
     *                     an unset `${VAR}` resolves to `''` today.
     */
    public static function lookup(string $name): ?string
    {
        if (\array_key_exists($name, self::$memo)) {
            return self::$memo[$name];
        }

        return self::$memo[$name] = self::resolve($name);
    }

    /**
     * The backend argv for one OS family — the entry name rides argv, the
     * secret never does.
     *
     * Public for the shared spelling C-SEC-8.f's `store()` will reuse and
     * pinned directly by tests; production reads it through {@see lookup()}.
     *
     * @return list<string>|null null when the family has no backend at all
     */
    public static function argvFor(string $osFamily, string $name): ?array
    {
        return match ($osFamily) {
            'Linux' => ['secret-tool', 'lookup', 'service', self::SERVICE, 'account', $name],
            'Darwin' => ['security', 'find-generic-password', '-s', self::SERVICE, '-a', $name, '-w'],
            default => null,
        };
    }

    /**
     * Replace the whole backend call with a recorder for tests.
     *
     * The seam receives the exact argv the real path would have spawned and
     * returns the normalized {@see spawn()} shape; null restores the real one.
     *
     * @param (Closure(list<string>):array{status:string,stdout:string,exitCode:int})|null $seam
     */
    public static function armSpawnSeamForTesting(?\Closure $seam): void
    {
        self::$spawnSeam = $seam;
    }

    /**
     * Pretend to run on another OS family, for the no-backend branch.
     */
    public static function armOsForTesting(?string $family): void
    {
        self::$osOverride = $family;
    }

    /**
     * Shrink the wall bound for the real-spawn timeout pin.
     */
    public static function armTimeoutForTesting(?float $seconds): void
    {
        self::$timeoutOverride = $seconds;
    }

    /**
     * Forget every memo and seam — the standard inter-test reset.
     */
    public static function resetForTesting(): void
    {
        self::$memo = [];
        self::$spawnSeam = null;
        self::$osOverride = null;
        self::$timeoutOverride = null;
    }

    /**
     * One uncached lookup: name the backend, fetch, announce the miss shapes.
     */
    private static function resolve(string $name): ?string
    {
        if ($name === '') {
            self::announce('cli.keychain.empty_name', []);

            return null;
        }

        $family = self::$osOverride ?? \PHP_OS_FAMILY;
        $argv = self::argvFor($family, $name);
        if ($argv === null) {
            self::announce('cli.keychain.no_backend', ['entry' => $name, 'os' => $family]);

            return null;
        }

        $backend = $argv[0];
        $result = self::$spawnSeam !== null
            ? (self::$spawnSeam)($argv)
            : self::spawn($argv);

        if ($result['status'] === 'hit' && $result['stdout'] !== '') {
            return $result['stdout'];
        }

        match ($result['status']) {
            'missing' => self::announce('cli.keychain.missing_binary', ['entry' => $name, 'backend' => $backend]),
            'timeout' => self::announce('cli.keychain.timeout', [
                'entry' => $name,
                'backend' => $backend,
                'seconds' => (string) (self::$timeoutOverride ?? self::TIMEOUT_SECONDS),
            ]),
            default => self::announce('cli.keychain.miss', ['entry' => $name, 'os' => $family]),
        };

        return null;
    }

    /**
     * Record one launch notice; see the class note for why this is the
     * transcript shelf and not a direct stderr write.
     *
     * @param array<string,string> $params
     */
    private static function announce(string $key, array $params): void
    {
        LaunchNotices::record(Lang::t($key, $params), Bootstrap::launchNoticeLimit());
    }

    /**
     * Spawn the backend, drain it under a wall bound, reap it here.
     *
     * The handle never escapes this method: pipes close and
     * {@see ProcessReaper::terminateAndClose()} runs on every exit path, so
     * the scanner classifies the site short and no inheritance licence is
     * owed. argv carries only service and entry names
     * ({@see argvFor()}); the secret rides stdout alone.
     *
     * @param list<string> $argv
     * @return array{status:string,stdout:string,exitCode:int}
     */
    private static function spawn(array $argv): array
    {
        $binary = ProcessContainment::locateOnPath($argv[0]);
        if ($binary === '') {
            return ['status' => 'missing', 'stdout' => '', 'exitCode' => 127];
        }

        $spec = ProcessContainment::spawnSpec([$binary, ...\array_slice($argv, 1)]);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        \error_clear_last();
        $process = @\proc_open($spec, $descriptors, $pipes, null, ProcessContainment::env());
        if (!\is_resource($process)) {
            return ['status' => 'missing', 'stdout' => '', 'exitCode' => 127];
        }

        // stdin is never written — close it immediately so the child that
        // would read (secret-tool waits for nothing, but `security -w` in a
        // wrong spelling could) sees EOF and the drain loop below stays the
        // only thing gating this call.
        \fclose($pipes[0]);
        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);

        $deadline = \microtime(true) + (self::$timeoutOverride ?? self::TIMEOUT_SECONDS);
        $stdout = '';
        $overflowed = false;
        $timedOut = false;
        while (!\feof($pipes[1]) || !\feof($pipes[2])) {
            $remainingUs = (int) (($deadline - \microtime(true)) * 1000000);
            if ($remainingUs <= 0) {
                $timedOut = true;
                break;
            }
            $read = [$pipes[1], $pipes[2]];
            $writing = null;
            $except = null;
            if (@\stream_select($read, $writing, $except, intdiv($remainingUs, 1000000), $remainingUs % 1000000) === false) {
                continue;
            }
            foreach ($read as $stream) {
                $chunk = \fread($stream, 8192);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($stream === $pipes[1]) {
                    if (\strlen($stdout) >= self::MAX_STDOUT_BYTES) {
                        $overflowed = true;
                    } else {
                        $stdout .= $chunk;
                    }
                } else {
                    // stderr belongs to the backend's own diagnostics; the
                    // exit code is the verdict, the text is not.
                    continue;
                }
            }
        }

        $exitCode = null;
        if (!$timedOut) {
            while (true) {
                $status = \proc_get_status($process);
                if (!$status['running']) {
                    $exitCode = (int) ($status['exitcode'] ?? -1);
                    break;
                }
                if (\microtime(true) >= $deadline) {
                    $timedOut = true;
                    break;
                }
                \usleep(self::POLL_INTERVAL_US);
            }
        }

        // Recorded while the handle is alive: after the reap there is no
        // group to target should the ladder need one.
        $groupPid = $timedOut ? ProcessContainment::groupId($process) : null;
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $closedExit = ProcessReaper::terminateAndClose($process, $groupPid);

        if ($timedOut) {
            return ['status' => 'timeout', 'stdout' => '', 'exitCode' => 124];
        }
        $exitCode ??= $closedExit ?? -1;
        if ($exitCode !== 0) {
            return ['status' => 'miss', 'stdout' => '', 'exitCode' => $exitCode];
        }
        $secret = \rtrim($stdout, "\r\n");
        if ($overflowed || $secret === '') {
            // exit 0 with nothing (or far too much) to say is not a hit.
            return ['status' => 'miss', 'stdout' => '', 'exitCode' => $exitCode];
        }

        return ['status' => 'hit', 'stdout' => $secret, 'exitCode' => $exitCode];
    }
}

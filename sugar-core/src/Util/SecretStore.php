<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

use SugarCraft\Core\Util\Executable\Locator;
use SugarCraft\Core\Util\Proc\BoundedShutdown;

/**
 * Read-only lookups against the operator's OS secret store — `secret-tool
 * lookup` on freedesktop Linux, `security find-generic-password -w` on macOS.
 * Extracted from candy-code's `Support/Keychain.php`; beneficiaries are any
 * app resolving `${keychain:...}`-style references (candy-mail,
 * candy-ssh-launcher, credential helpers, sugar-mcp-client).
 *
 * Only the service and entry names ride argv; the secret comes back on
 * stdout, so it never appears in a process listing.
 *
 * MEMOISED FOR THE PROCESS — hits AND misses, keyed by service+entry — so a
 * config that references one entry in several fields pays one spawn. A miss
 * never throws: {@see lookup()} answers null, and {@see lookupDetailed()}
 * says why so the caller can raise its own, localised notice (this library
 * has no notice shelf). Statuses: `hit`, `miss` (absent entry, empty value,
 * non-zero exit, runaway output), `missing` (no backend binary), `timeout`,
 * `refused` (a name a backend could misread — empty, leading `-`, control
 * bytes — rejected before any spawn), `no_backend` (OS family with none).
 *
 * The bound on one backend call is {@see TIMEOUT_SECONDS}: a keychain helper
 * answers in milliseconds or is wedged behind a dead daemon, so the wall
 * protects the caller's launch rather than truncating work.
 */
final class SecretStore
{
    public const TIMEOUT_SECONDS = 5.0;

    /** Runaway-output guard: a secret is one line, this is not that. */
    private const MAX_STDOUT_BYTES = 65536;

    /** @var array<string,array{secret:?string,status:string}> */
    private static array $memo = [];

    /** @var (\Closure(list<string>):array{status:string,stdout:string,exitCode:int})|null */
    private static ?\Closure $spawnSeam = null;

    private static ?string $osOverride = null;

    private static ?float $timeoutOverride = null;

    private function __construct()
    {
    }

    /**
     * Whether this OS family has a backend at all (says nothing about whether
     * the backend binary is installed — that surfaces as `missing`).
     */
    public static function supported(): bool
    {
        return self::argvFor(self::osFamily(), 'service', 'entry') !== null;
    }

    /**
     * The secret for $service/$entry, or null for every miss shape.
     */
    public static function lookup(string $service, string $entry): ?string
    {
        return self::lookupDetailed($service, $entry)['secret'];
    }

    /**
     * {@see lookup()} plus the verdict, memoised together.
     *
     * @return array{secret:?string,status:string}
     */
    public static function lookupDetailed(string $service, string $entry): array
    {
        $key = $service . "\0" . $entry;

        return self::$memo[$key] ??= self::resolve($service, $entry);
    }

    /**
     * Drop the memoised verdict for $service/$entry — for a caller that has
     * just WRITTEN the entry, so a stale miss is not served for the rest of
     * the process.
     */
    public static function forget(string $service, string $entry): void
    {
        unset(self::$memo[$service . "\0" . $entry]);
    }

    /**
     * The backend argv for one OS family, or null when it has no backend.
     * Public so tests can pin that the secret-bearing words stay out of argv.
     *
     * @return list<string>|null
     */
    public static function argvFor(string $osFamily, string $service, string $entry): ?array
    {
        return match ($osFamily) {
            'Linux' => ['secret-tool', 'lookup', 'service', $service, 'account', $entry],
            'Darwin' => ['security', 'find-generic-password', '-s', $service, '-a', $entry, '-w'],
            default => null,
        };
    }

    public static function osFamily(): string
    {
        return self::$osOverride ?? \PHP_OS_FAMILY;
    }

    /** @param (\Closure(list<string>):array{status:string,stdout:string,exitCode:int})|null $seam */
    public static function armSpawnSeamForTesting(?\Closure $seam): void
    {
        self::$spawnSeam = $seam;
    }

    public static function armOsForTesting(?string $family): void
    {
        self::$osOverride = $family;
    }

    public static function armTimeoutForTesting(?float $seconds): void
    {
        self::$timeoutOverride = $seconds;
    }

    /** Forget every memo and seam — the standard inter-test reset. */
    public static function resetForTesting(): void
    {
        self::$memo = [];
        self::$spawnSeam = null;
        self::$osOverride = null;
        self::$timeoutOverride = null;
    }

    /**
     * @return array{secret:?string,status:string}
     */
    private static function resolve(string $service, string $entry): array
    {
        $refuse = ['secret' => null, 'status' => 'refused'];
        foreach ([$service, $entry] as $word) {
            // A leading `-` is option-parseable (secret-tool's GLib parser);
            // a control byte reaches proc_open's argv as a ValueError.
            if ($word === '' || preg_match('/^-|[\x00-\x1f\x7f]/', $word) === 1) {
                return $refuse;
            }
        }

        $argv = self::argvFor(self::osFamily(), $service, $entry);
        if ($argv === null) {
            return ['secret' => null, 'status' => 'no_backend'];
        }

        $result = self::$spawnSeam !== null ? (self::$spawnSeam)($argv) : self::spawn($argv);
        if ($result['status'] === 'hit' && $result['stdout'] !== '') {
            return ['secret' => $result['stdout'], 'status' => 'hit'];
        }

        return ['secret' => null, 'status' => $result['status'] === 'hit' ? 'miss' : $result['status']];
    }

    /**
     * @param list<string> $argv
     *
     * @return array{status:string,stdout:string,exitCode:int}
     */
    private static function spawn(array $argv): array
    {
        $binary = Locator::locate($argv[0]);
        if ($binary === null) {
            return ['status' => 'missing', 'stdout' => '', 'exitCode' => 127];
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open([$binary, ...\array_slice($argv, 1)], $descriptors, $pipes);
        if (!\is_resource($process)) {
            return ['status' => 'missing', 'stdout' => '', 'exitCode' => 127];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + (self::$timeoutOverride ?? self::TIMEOUT_SECONDS);
        $stdout = '';
        $overflowed = false;
        $timedOut = false;
        while (!feof($pipes[1]) || !feof($pipes[2])) {
            $remainingUs = (int) (($deadline - microtime(true)) * 1000000);
            if ($remainingUs <= 0) {
                $timedOut = true;
                break;
            }
            $read = [$pipes[1], $pipes[2]];
            $w = null;
            $e = null;
            if (@stream_select($read, $w, $e, intdiv($remainingUs, 1000000), $remainingUs % 1000000) === false) {
                continue;
            }
            foreach ($read as $stream) {
                $chunk = fread($stream, 8192);
                // stderr is drained and discarded: the exit code is the verdict.
                if ($chunk === false || $chunk === '' || $stream !== $pipes[1]) {
                    continue;
                }
                if (\strlen($stdout) >= self::MAX_STDOUT_BYTES) {
                    $overflowed = true;
                } else {
                    $stdout .= $chunk;
                }
            }
        }

        $exitCode = null;
        if (!$timedOut) {
            while (true) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exitCode = (int) $status['exitcode'];
                    break;
                }
                if (microtime(true) >= $deadline) {
                    $timedOut = true;
                    break;
                }
                usleep(5000);
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = BoundedShutdown::terminateBounded($process);

        if ($timedOut) {
            return ['status' => 'timeout', 'stdout' => '', 'exitCode' => 124];
        }
        $exitCode ??= $closed ?? -1;
        $secret = rtrim($stdout, "\r\n");
        if ($exitCode !== 0 || $overflowed || $secret === '') {
            return ['status' => 'miss', 'stdout' => '', 'exitCode' => $exitCode];
        }

        return ['status' => 'hit', 'stdout' => $secret, 'exitCode' => 0];
    }
}

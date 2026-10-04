<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server;

use SugarCraft\Crush\Support\Daemonize;
use SugarCraft\Crush\Support\PrivateRetainedDir;

/**
 * `server.json`: the running server's discovery record (Appendix O §4.6;
 * roadmap O-4a). What `serve status|stop|logs|url` read to find the server,
 * and what `sugarcrush attach` and editor plugins will (cline's
 * discovery-record pattern):
 *
 *     {pid, procStartTime, version, protocol: {min, max}, url, host, port,
 *      root, startedAt, detached, log}
 *
 * Written 0600 through a write-then-rename into the 0700 state directory, so
 * a reader never sees a torn record. It never carries the token.
 *
 * A RECORD IS A CLAIM, NOT A FACT. The kernel recycles pids, and a server that
 * was SIGKILLed never removed its record. So {@see isLive()} requires the pid
 * to still be the process that started at `procStartTime` before anything is
 * signalled or reported as running; a record that fails it is stale.
 */
final class DiscoveryFile
{
    private const LABEL = 'server state';

    /**
     * @param array{min: int, max: int} $protocol
     */
    private function __construct(
        public readonly int $pid,
        public readonly ?int $procStartTime,
        public readonly string $version,
        public readonly array $protocol,
        public readonly string $url,
        public readonly string $host,
        public readonly int $port,
        public readonly ?string $root,
        public readonly int $startedAt,
        public readonly bool $detached,
        public readonly ?string $log,
    ) {
    }

    /**
     * The record for THIS process, serving at $url.
     */
    public static function forThisProcess(
        string $version,
        string $url,
        string $host,
        int $port,
        ?string $root,
        bool $detached,
        ?string $log,
        ?int $now = null,
    ): self {
        $pid = (int) \getmypid();

        return new self(
            $pid,
            Daemonize::startTime($pid),
            $version,
            ['min' => ServerConfig::PROTOCOL_MAJOR, 'max' => ServerConfig::PROTOCOL_MAJOR],
            $url,
            $host,
            $port,
            $root,
            $now ?? \time(),
            $detached,
            $log,
        );
    }

    /**
     * The record in $dir, or null when there is none or it is not a record
     * (a torn or hand-edited file is no server).
     */
    public static function read(StateDir $dir): ?self
    {
        $path = $dir->discoveryPath();
        clearstatcache(true, $path);
        $stat = @\lstat($path);
        if ($stat === false || ((int) $stat['mode'] & 0o170000) !== 0o100000) {
            return null;
        }

        try {
            $data = \json_decode((string) @\file_get_contents($path), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($data) ? self::fromArray($data) : null;
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $pid = $data['pid'] ?? null;
        $url = $data['url'] ?? null;
        if (!\is_int($pid) || $pid <= 0 || !\is_string($url) || $url === '') {
            return null;
        }
        $protocol = $data['protocol'] ?? null;
        $min = \is_array($protocol) && \is_int($protocol['min'] ?? null) ? $protocol['min'] : ServerConfig::PROTOCOL_MAJOR;
        $max = \is_array($protocol) && \is_int($protocol['max'] ?? null) ? $protocol['max'] : $min;

        return new self(
            $pid,
            \is_int($data['procStartTime'] ?? null) ? $data['procStartTime'] : null,
            \is_string($data['version'] ?? null) ? $data['version'] : 'unknown',
            ['min' => $min, 'max' => $max],
            $url,
            \is_string($data['host'] ?? null) ? $data['host'] : '',
            \is_int($data['port'] ?? null) ? $data['port'] : 0,
            \is_string($data['root'] ?? null) ? $data['root'] : null,
            \is_int($data['startedAt'] ?? null) ? $data['startedAt'] : 0,
            ($data['detached'] ?? false) === true,
            \is_string($data['log'] ?? null) ? $data['log'] : null,
        );
    }

    /**
     * @return array{pid: int, procStartTime: int|null, version: string, protocol: array{min: int, max: int}, url: string, host: string, port: int, root: string|null, startedAt: int, detached: bool, log: string|null}
     */
    public function toArray(): array
    {
        return [
            'pid' => $this->pid,
            'procStartTime' => $this->procStartTime,
            'version' => $this->version,
            'protocol' => $this->protocol,
            'url' => $this->url,
            'host' => $this->host,
            'port' => $this->port,
            'root' => $this->root,
            'startedAt' => $this->startedAt,
            'detached' => $this->detached,
            'log' => $this->log,
        ];
    }

    /**
     * Write this record into $dir, atomically and 0600.
     *
     * @throws \RuntimeException when it cannot be written
     */
    public function write(StateDir $dir): void
    {
        PrivateRetainedDir::write(
            $dir->path,
            StateDir::DISCOVERY_FILE,
            (string) \json_encode($this->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n",
            self::LABEL,
        );
    }

    /**
     * Remove the record in $dir, but only when it is still THIS one: a server
     * that lost a race to a newer start must not delete the winner's record.
     */
    public function removeFrom(StateDir $dir): void
    {
        $current = self::read($dir);
        if ($current !== null && $current->pid === $this->pid && $current->procStartTime === $this->procStartTime) {
            @\unlink($dir->discoveryPath());
        }
    }

    /** Whether the process this record names is still that process. */
    public function isLive(): bool
    {
        return Daemonize::isSameProcess($this->pid, $this->procStartTime);
    }

    /** Seconds since the server started, as of $now. */
    public function uptime(?int $now = null): int
    {
        return \max(0, ($now ?? \time()) - $this->startedAt);
    }
}

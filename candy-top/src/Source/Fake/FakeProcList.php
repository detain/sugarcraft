<?php

declare(strict_types=1);

namespace SugarCraft\Top\Source\Fake;

use SugarCraft\Top\Collect\Process;
use SugarCraft\Top\Collect\ProcSnapshot;
use SugarCraft\Top\Source\Source;

/**
 * Deterministic {@see ProcSnapshot}s: a fixed cast of processes whose cpu
 * and memory wander on their own phases.
 */
final class FakeProcList implements Source
{
    private const CAST = [
        [1, 0, 'systemd', '/sbin/init splash', 'root'],
        [412, 1, 'sshd', 'sshd: /usr/sbin/sshd -D', 'root'],
        [880, 1, 'postgres', 'postgres -D /var/lib/postgresql', 'postgres'],
        [1203, 1, 'nginx', 'nginx: worker process', 'www-data'],
        [2210, 412, 'bash', '-bash', 'joe'],
        [2290, 2210, 'php', 'php bin/candy-top', 'joe'],
        [3105, 1, 'redis-server', 'redis-server 127.0.0.1:6379', 'redis'],
        [4410, 1, 'node', 'node server.js', 'joe'],
    ];

    private function __construct(
        private readonly int $cores,
        private readonly int $step,
    ) {
    }

    public static function new(int $cores = 8): self
    {
        return new self(max(1, $cores), 0);
    }

    public function sample(): array
    {
        $procs = [];
        foreach (self::CAST as $i => [$pid, $ppid, $name, $cmd, $user]) {
            $cpu = round(Wave::percent($this->step, $i * 1.37) / (2 + $i), 1);
            $mem = (int) ((40 + $i * 70 + Wave::percent($this->step, $i)) * 1024 * 1024);
            $procs[] = new Process($pid, $ppid, $name, $cmd, $user, 1000 + $i, 'S', 1 + $i % 4, 0, $mem, $cpu, $cpu * $this->step);
        }

        return [new ProcSnapshot($procs, $this->cores), new self($this->cores, $this->step + 1)];
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Top\Source\Fake;

use SugarCraft\Top\Collect\ContainerRef;
use SugarCraft\Top\Collect\ProcDetail;
use SugarCraft\Top\Collect\ProcList;
use SugarCraft\Top\Collect\Process;
use SugarCraft\Top\Collect\ProcSnapshot;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Collect\VmInfo;
use SugarCraft\Top\Source\Source;

/**
 * Deterministic {@see ProcSnapshot}s: a fixed cast of processes whose cpu
 * and memory wander on their own phases.
 *
 * {@see new()} is the frozen P-A cast the placeholder roster (and its
 * chrome goldens) render; {@see demo()} is what the real proc panel shows
 * under `--fake`: the same cast plus a docker container, a KVM guest
 * (Wave U1b), io rates (#1823), basename offsets (#1859), a kernel thread
 * and — for the R4 benchmark — `$extra` synthetic processes. It honours
 * the same opt-ins as the live ProcList ({@see withPerCore()},
 * {@see withIo()}, {@see withFilterKernel()}, {@see withDetail()}).
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

    /** demo() additions: pid, ppid, name, cmd, user, argv0 basename offset, state. */
    private const DEMO_CAST = [
        [2, 0, 'kthreadd', '', 'root', 0, 'S'],
        [5120, 1, 'containerd-shim', '/usr/bin/containerd-shim-runc-v2 -namespace moby', 'root', 9, 'S'],
        [5133, 5120, 'python3', '/usr/local/bin/python3 /app/worker.py', 'root', 15, 'R'],
        [6969, 1, 'qemu-system-x86', '/usr/bin/qemu-system-x86_64 -name guest=web01,debug-threads=on -smp 4', 'libvirt-qemu', 9, 'S'],
        [7001, 2210, 'vim', '/usr/bin/vim notes.md', 'joe', 9, 'S'],
    ];

    /** 16 GiB — the fake host's MemTotal. */
    public const MEM_TOTAL = 16 * 1024 * 1024 * 1024;

    private function __construct(
        private readonly int $cores,
        private readonly int $step,
        private readonly bool $demo = false,
        private readonly int $extra = 0,
        private readonly bool $perCore = false,
        private readonly bool $io = false,
        private readonly bool $filterKernel = false,
        private readonly ?int $detailPid = null,
    ) {
    }

    public static function new(int $cores = 8): self
    {
        return new self(max(1, $cores), 0);
    }

    /**
     * The proc panel's fake host: the P-A cast plus containers, a VM, a
     * kernel thread and `$extra` synthetic busy-ish processes (benchmarks).
     */
    public static function demo(int $cores = 8, int $extra = 0): self
    {
        return new self(max(1, $cores), 0, true, max(0, $extra));
    }

    public function withPerCore(bool $on): self
    {
        return $this->copy(['perCore' => $on]);
    }

    public function withIo(bool $on): self
    {
        return $this->copy(['io' => $on]);
    }

    public function withFilterKernel(bool $on): self
    {
        return $this->copy(['filterKernel' => $on]);
    }

    public function withDetail(?int $pid): self
    {
        return $this->copy(['detailPid' => $pid]);
    }

    public function sample(): array
    {
        $procs = [];
        foreach (self::CAST as $i => [$pid, $ppid, $name, $cmd, $user]) {
            $cpu = round(Wave::percent($this->step, $i * 1.37) / (2 + $i), 1);
            $mem = (int) ((40 + $i * 70 + Wave::percent($this->step, $i)) * 1024 * 1024);
            if (!$this->demo) {
                $procs[] = new Process($pid, $ppid, $name, $cmd, $user, 1000 + $i, 'S', 1 + $i % 4, 0, $mem, $cpu, $cpu * $this->step);
                continue;
            }
            $procs[] = $this->process($i, $pid, $ppid, $name, $cmd, $user, ProcList::basenameOffset(explode(' ', $cmd)[0]), 'S', $cpu, $mem, null);
        }
        if (!$this->demo) {
            return [new ProcSnapshot($procs, $this->cores), $this->copy(['step' => $this->step + 1])];
        }

        foreach (self::DEMO_CAST as $j => [$pid, $ppid, $name, $cmd, $user, $argv0, $state]) {
            $i = count(self::CAST) + $j;
            if ($this->filterKernel && ($pid === 2 || $ppid === 2)) {
                continue;
            }
            $cpu = $pid === 2 ? 0.0 : round(Wave::percent($this->step, $i * 0.91) / (1 + $j), 1);
            $mem = $pid === 2 ? 0 : (int) ((25 + $j * 310 + Wave::percent($this->step, $i)) * 1024 * 1024);
            $container = match ($pid) {
                5133 => new ContainerRef('docker', '3f2a1b9c0d1e', '3f2a1b9c0d1e', '/system.slice/docker-3f2a1b9c0d1e.scope'),
                6969 => new ContainerRef('kvm', 'web01', 'web01', '/machine.slice/machine-qemu\x2d1\x2dweb01.scope', new VmInfo(1, 'web01', 'web01', Sentinel::UNAVAILABLE, 4, 4 * 1024 * 1024 * 1024)),
                default => null,
            };
            $procs[] = $this->process($i, $pid, $ppid, $name, $cmd, $user, $argv0, $state, $cpu, $mem, $container);
        }
        for ($k = 0; $k < $this->extra; $k++) {
            $i = 100 + $k;
            $pid = 10_000 + $k;
            $cpu = round(Wave::percent($this->step, $k * 0.37) / (1 + $k % 23), 1);
            $mem = (int) ((5 + ($k * 37) % 900) * 1024 * 1024);
            $procs[] = $this->process($i, $pid, $k % 7 === 0 ? 1 : 10_000 + intdiv($k, 7) * 7, 'worker-' . $k, '/usr/bin/worker --id=' . $k, 'u' . ($k % 13), 9, 'S', $cpu, $mem, null);
        }
        usort($procs, static fn (Process $a, Process $b): int => $a->pid <=> $b->pid);

        $detail = null;
        foreach ($procs as $p) {
            if ($p->pid === $this->detailPid) {
                $detail = new ProcDetail(
                    $p->pid,
                    $p->pid === 6969 ? null : '/home/' . $p->user,
                    3600.0 + 61.0 * $this->step + $p->pid,
                    $p->ioReadTotal >= 0 ? $p->ioReadTotal : 4096 * $p->pid,
                    $p->ioWriteTotal >= 0 ? $p->ioWriteTotal : 1024 * $p->pid,
                );
            }
        }

        return [new ProcSnapshot($procs, $this->cores, self::MEM_TOTAL, $detail), $this->copy(['step' => $this->step + 1])];
    }

    private function process(int $i, int $pid, int $ppid, string $name, string $cmd, string $user, int $argv0, string $state, float $cpu, int $mem, ?ContainerRef $container): Process
    {
        $cpu = $this->perCore ? round($cpu * $this->cores, 1) : $cpu;
        $ioR = $ioW = Sentinel::UNMEASURED;
        $ioRT = $ioWT = Sentinel::UNMEASURED_INT;
        // A root-owned pid other than 1 reads like EACCES (another uid): "-".
        if ($this->io && !($user === 'root' && $pid !== 1)) {
            $ioR = round(Wave::percent($this->step, $i * 0.7) * 1024 * ($i % 5));
            $ioW = round(Wave::percent($this->step, $i * 1.9) * 512 * ($i % 3));
            $ioRT = (int) ($ioR * 100 + $pid);
            $ioWT = (int) ($ioW * 100 + $pid);
        }

        return new Process(
            $pid,
            $ppid,
            $name,
            $cmd,
            $user,
            1000 + $i,
            $state,
            $pid === 2 ? 1 : 1 + $i % 4 + ($pid === 6969 ? 6 : 0),
            $i % 3 === 0 ? 0 : -5 + $i % 11,
            $mem,
            $cpu,
            round($cpu * 0.8 + ($i * 7) % 13, 1),
            $argv0,
            $ioR,
            $ioW,
            $ioRT,
            $ioWT,
            $container,
        );
    }

    /**
     * @param array<string, mixed> $o
     */
    private function copy(array $o): self
    {
        return new self(
            $this->cores,
            $o['step'] ?? $this->step,
            $this->demo,
            $this->extra,
            $o['perCore'] ?? $this->perCore,
            $o['io'] ?? $this->io,
            $o['filterKernel'] ?? $this->filterKernel,
            array_key_exists('detailPid', $o) ? $o['detailPid'] : $this->detailPid,
        );
    }
}

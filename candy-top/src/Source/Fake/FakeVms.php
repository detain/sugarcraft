<?php

declare(strict_types=1);

namespace SugarCraft\Top\Source\Fake;

use SugarCraft\Top\Collect\VmFleetSnapshot;
use SugarCraft\Top\Collect\VmGuest;
use SugarCraft\Top\Source\Source;

/**
 * The `--fake` VM fleet for the VM dashboard: seven guests with
 * deterministic, varied load ({@see Wave}) — a busy build runner starved
 * for cpu, a database moving a lot of disk, a backup guest stalled on io,
 * idle mail — so every card state shows in a demo. `web01` is the ctr
 * box's demo guest (same scope path), so Enter on it filters the fake
 * proc box to its qemu process.
 */
final class FakeVms implements Source
{
    /**
     * name => [domain id, vCPUs, GiB, cpu scale, mem share, disk B/s, net B/s, psi channel, psi scale, phase]
     */
    private const FLEET = [
        'web01' => [1, 4, 4, 0.45, 0.62, 2.0e5, 3.2e6, 'cpu', 0.0, 0.3],
        'db-primary' => [3, 8, 16, 0.55, 0.88, 2.4e7, 9.0e5, 'io', 0.06, 1.1],
        'build-runner' => [4, 16, 32, 1.0, 0.71, 6.0e6, 4.0e5, 'cpu', 0.55, 2.3],
        'mail' => [5, 2, 2, 0.06, 0.41, 1.5e4, 2.0e4, 'cpu', 0.0, 3.7],
        'win11-desk' => [6, 4, 8, 0.32, 0.77, 1.1e6, 6.0e5, 'mem', 0.03, 4.9],
        'k8s-node1' => [7, 6, 12, 0.5, 0.58, 3.0e6, 1.4e6, 'cpu', 0.1, 5.5],
        'backup' => [8, 2, 4, 0.18, 0.33, 4.5e7, 2.5e5, 'io', 0.5, 0.9],
    ];

    /** The fake host's MemTotal: 128 GiB. */
    public const HOST_MEM = 128 * 1024 ** 3;

    private function __construct(
        private readonly int $step,
    ) {
    }

    public static function new(): self
    {
        return new self(0);
    }

    public function sample(): array
    {
        $guests = [];
        foreach (self::FLEET as $name => [$id, $vcpus, $gib, $cpuScale, $memShare, $disk, $net, $psiKind, $psiScale, $phase]) {
            $s = $this->step;
            $cpu = min(100.0, Wave::percent($s, $phase) * $cpuScale + ($cpuScale >= 1.0 ? 20.0 : 0.0));
            $mem = (int) ($gib * 1024 ** 3);
            $memUsed = (int) ($mem * min(0.99, $memShare + Wave::percent($s, $phase + 0.7) / 1000));
            $psi = $psiScale * Wave::percent($s, $phase + 1.9);
            $guests[] = new VmGuest(
                '/machine.slice/machine-qemu\x2d' . $id . '\x2d' . str_replace('-', '\x2d', $name) . '.scope',
                $name,
                $id,
                $vcpus,
                $mem,
                round($cpu, 1),
                $memUsed,
                round($disk * Wave::percent($s, $phase + 2.6) / 100),
                round($disk * 0.6 * Wave::percent($s, $phase + 3.3) / 100),
                round($net * Wave::percent($s, $phase + 0.4) / 100),
                round($net * 0.35 * Wave::percent($s, $phase + 1.4) / 100),
                round($psiKind === 'cpu' ? $psi : $psi * 0.05, 2),
                round($psiKind === 'mem' ? $psi : 0.0, 2),
                round($psiKind === 'io' ? $psi : $psi * 0.05, 2),
            );
        }

        return [new VmFleetSnapshot($guests, self::HOST_MEM), new self($this->step + 1)];
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\ContainerEngine;
use SugarCraft\Top\Collect\FreeBsd\HostDetect;
use SugarCraft\Top\Collect\Paths;
use SugarCraft\Top\HostInfo;
use SugarCraft\Top\Tests\Collect\FreeBsd\Support\FixtureProbe;

/**
 * btop `detect_container()` plus candy-top's extra markers, against
 * fixture roots (every check is a file under the injected Paths root or a
 * key of the injected environment).
 */
final class ContainerEngineTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/candy-top-engine-' . bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->root);
        }
    }

    public function testAHostIsInNoContainer(): void
    {
        $this->put('proc/self/cgroup', "0::/user.slice/user-0.slice/session-101282.scope\n");
        $this->put('proc/1/environ', "HOME=/\0TERM=linux\0");
        $this->put('proc/self/mountinfo', "26 1 259:2 / / rw,relatime shared:1 - ext4 /dev/nvme0n1p2 rw\n");
        $this->assertSame('', $this->detect());
        $this->assertSame('', ContainerEngine::detect(Paths::under($this->root . '/missing'), []));
    }

    public function testBtopsMarkersInBtopsOrder(): void
    {
        $this->put('run/systemd/container', "lxc\n");
        $this->assertSame('lxc', $this->detect(), '/run/systemd/container: its first word');
        $this->put('.dockerenv', '');
        $this->assertSame('docker', $this->detect(), '/.dockerenv beats the systemd file');
        $this->put('run/.containerenv', "engine=\"podman-4.9.3\"\n");
        $this->assertSame('podman', $this->detect(), '/run/.containerenv is checked first, as in btop');
    }

    public function testKubernetesBeatsTheRuntimeMarkers(): void
    {
        $this->put('.dockerenv', '');
        $this->assertSame('k8s', $this->detect(['KUBERNETES_SERVICE_HOST' => '10.96.0.1']));
        $this->assertSame('docker', $this->detect(['KUBERNETES_SERVICE_HOST' => '']));
    }

    public function testSystemdNamesAreShortened(): void
    {
        $this->put('run/systemd/container', "systemd-nspawn\n");
        $this->assertSame('nspawn', $this->detect());
        $this->put('run/systemd/container', "lxc-libvirt\n");
        $this->assertSame('lxc', $this->detect());
        $this->put('run/systemd/container', "wsl\n");
        $this->assertSame('wsl', $this->detect(), 'systemd\'s own word passes through');
        $this->put('run/systemd/container', "\x1b[31mevil\x07engine-name-that-is-long\n");
        $this->assertSame('??31mevil?en', $this->detect(), 'terminal-safe and capped');
    }

    public function testPid1EnvironCatchesAnInitWithoutSystemd(): void
    {
        $this->put('proc/1/environ', "PATH=/bin\0container=lxc\0TERM=xterm\0");
        $this->assertSame('lxc', $this->detect());
        $this->put('proc/1/environ', "container=systemd-nspawn\0");
        $this->assertSame('nspawn', $this->detect());
        $this->assertSame('', ContainerEngine::environValue("xcontainer=lxc\0", 'container'));
    }

    public function testAHostVisibleCgroupPathNamesTheEngine(): void
    {
        // cgroup v1 (or v2 without a cgroup namespace): the host's view of the path.
        $this->put('proc/self/cgroup', "12:pids:/docker/" . str_repeat('ab', 32) . "\n0::/\n");
        $this->assertSame('docker', $this->detect());
        $this->put('proc/self/cgroup', "0::/kubepods.slice/kubepods-pod1.slice/cri-containerd-" . str_repeat('cd', 32) . ".scope\n");
        $this->assertSame('k8s', $this->detect());
        $this->put('proc/self/cgroup', "0::/machine.slice/machine-qemu\\x2d1\\x2dvm.scope/libvirt/emulator\n");
        $this->assertSame('', $this->detect(), 'a VM scope is not a container — and a guest never sees it anyway');
    }

    public function testTheOverlayRootRevealsTheRuntime(): void
    {
        $docker = '1201 1100 0:312 / / rw,relatime master:1 - overlay overlay rw,lowerdir=/var/lib/docker/overlay2/l/ABC:/var/lib/docker/overlay2/l/DEF,upperdir=/var/lib/docker/overlay2/x/diff,workdir=/var/lib/docker/overlay2/x/work';
        $this->put('proc/self/mountinfo', "1300 1201 0:5 / /dev rw - tmpfs tmpfs rw\n" . $docker . "\n");
        $this->assertSame('docker', $this->detect());
        $this->assertSame('podman', ContainerEngine::fromMountinfo('1 0 0:1 / / rw - overlay overlay rw,lowerdir=/home/u/.local/share/containers/storage/overlay/l/A,upperdir=/x'));
        $this->assertSame('containerd', ContainerEngine::fromMountinfo('1 0 0:1 / / rw - overlay overlay rw,lowerdir=/var/lib/containerd/io.containerd.snapshotter.v1.overlayfs/snapshots/1/fs'));
        $this->assertSame('', ContainerEngine::fromMountinfo('1 0 0:1 / / rw - overlay overlay rw,lowerdir=/run/live/rootfs'), 'a live-CD overlay root is no container');
        $this->assertSame('', ContainerEngine::fromMountinfo('1 0 0:1 / /mnt rw - overlay overlay rw,lowerdir=/var/lib/docker/x'), 'only the root mount counts');
        $this->assertSame('', ContainerEngine::fromMountinfo(null));
    }

    public function testHostInfoCarriesTheEngine(): void
    {
        $this->put('.dockerenv', '');
        $this->assertSame('docker', HostInfo::detect(Paths::under($this->root))->containerEngine);
        $this->assertSame('', HostInfo::new()->containerEngine);
        $this->assertSame('nspawn', HostInfo::new(containerEngine: 'systemd-nspawn')->containerEngine);
    }

    public function testAFreeBsdJailIsDetectedFromTheSameSysctlCall(): void
    {
        $probe = new FixtureProbe("hw.model: Intel(R) Xeon(R) CPU E5-2680 v4 @ 2.40GHz\nhw.ncpu: 4\nsecurity.jail.jailed: 1\n");
        $this->assertSame(ContainerEngine::JAIL, HostDetect::detect($probe)->containerEngine);
        $this->assertContains('security.jail.jailed', $probe->sysctls[0], 'asked in the one host-facts sysctl call, no extra process');
        $this->assertSame('', HostDetect::detect(new FixtureProbe("hw.model: x\nhw.ncpu: 4\nsecurity.jail.jailed: 0\n"))->containerEngine);
        $this->assertSame('', HostDetect::detect(new FixtureProbe("hw.ncpu: 4\n"))->containerEngine, 'OID absent: no jail');
    }

    /** @param array<string, string> $env */
    private function detect(array $env = []): string
    {
        return ContainerEngine::detect(Paths::under($this->root), $env);
    }

    private function put(string $relative, string $content): void
    {
        $file = $this->root . '/' . $relative;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $content);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Cgroup;
use SugarCraft\Top\Collect\ContainerRef;

/**
 * Cases ported from aristocratos/btop PR #1873 tests/container.cpp.
 */
final class CgroupTest extends TestCase
{
    private const string ID = '3f2a9c1b04de5a7788990011223344556677889900aabbccddeeff0011223344';

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function containers(): iterable
    {
        $id = self::ID;
        $docker = "/system.slice/docker-{$id}.scope";
        yield 'docker scope' => [$docker, 'docker', '3f2a9c1b04de', $docker];
        yield 'cgroup inside the container' => ["{$docker}/system.slice/nginx.service", 'docker', '3f2a9c1b04de', $docker];
        yield 'docker v1 bare id' => ["/docker/{$id}", 'docker', '3f2a9c1b04de', "/docker/{$id}"];
        $podman = "/user.slice/user-1000.slice/user@1000.service/user.slice/libpod-{$id}.scope";
        yield 'rootless podman' => ["{$podman}/container", 'podman', '3f2a9c1b04de', $podman];
        $k8s = "/kubepods.slice/kubepods-burstable.slice/kubepods-burstable-pod1234.slice/cri-containerd-{$id}.scope";
        yield 'k8s systemd driver' => [$k8s, 'k8s', '3f2a9c1b04de', $k8s];
        yield 'k8s cgroupfs driver' => ["/kubepods/burstable/pod1234/{$id}", 'k8s', '3f2a9c1b04de', "/kubepods/burstable/pod1234/{$id}"];
        yield 'nerdctl' => ["/system.slice/nerdctl-{$id}.scope", 'nerdctl', '3f2a9c1b04de', "/system.slice/nerdctl-{$id}.scope"];
        yield 'unknown parent' => ["/default/{$id}", 'container', '3f2a9c1b04de', "/default/{$id}"];
        yield 'lxc payload' => ['/lxc.payload.web/system.slice/nginx.service', 'lxc', 'web', '/lxc.payload.web'];
        yield 'proxmox lxc' => ['/lxc/101/ns/init.scope', 'lxc', '101', '/lxc/101'];
        yield 'nspawn unescaped' => ['/machine.slice/machine-my\x2dbox.scope/payload', 'nspawn', 'my-box', '/machine.slice/machine-my\x2dbox.scope'];
        yield 'nspawn named like qemu' => ['/machine.slice/machine-qemubox.scope/payload', 'nspawn', 'qemubox', '/machine.slice/machine-qemubox.scope'];
        yield 'nested is outermost' => ["/lxc.payload.host/system.slice/docker-{$id}.scope", 'lxc', 'host', '/lxc.payload.host'];
    }

    #[DataProvider('containers')]
    public function testRecognisesContainer(string $path, string $engine, string $name, string $root): void
    {
        $ref = Cgroup::parse($path);

        $this->assertInstanceOf(ContainerRef::class, $ref);
        $this->assertSame($engine, $ref->engine);
        $this->assertSame($name, $ref->name);
        $this->assertSame($name, $ref->id);
        $this->assertSame($root, $ref->cgroupPath);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notContainers(): iterable
    {
        foreach ([
            '', '/', '/init.scope', '/user.slice/user-1000.slice/session-3.scope', '/system.slice/docker.service',
            '/lxc.monitor.web', '/lxc.monitor/101', '/machine.slice/machine-qemu\x2d1\x2dvm.scope',
            '/machine.slice/libpod-conmon-' . self::ID . '.scope', '/system.slice/docker-' . substr(self::ID, 1) . '.scope',
            '/system.slice/docker-' . str_repeat('g', 64) . '.scope',
        ] as $path) {
            yield $path === '' ? '(empty)' : $path => [$path];
        }
    }

    #[DataProvider('notContainers')]
    public function testHostCgroupsAreNotContainers(string $path): void
    {
        $this->assertNull(Cgroup::parse($path));
    }

    public function testDisplayNameIsTerminalSafe(): void
    {
        $ref = Cgroup::parse("/lxc.payload.evil\x1b[31mbox");

        $this->assertSame('evil??31mbox', $ref?->name);
        $this->assertSame("evil\x1b[31mbox", $ref?->id, 'id stays raw for grouping');
    }

    public function testProcFileV1FirstContainerLineWins(): void
    {
        $raw = "12:pids:/user.slice\n11:memory:/docker/" . self::ID . "\n1:name=systemd:/docker/" . self::ID . "\n";

        $this->assertSame('docker', Cgroup::fromProcFile($raw)?->engine);
        $this->assertNull(Cgroup::fromProcFile("0::/init.scope\n"));
        $this->assertNull(Cgroup::fromProcFile(null));
        $this->assertNull(Cgroup::fromProcFile("garbage\n"));
    }
}

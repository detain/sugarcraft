<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Cgroup;
use SugarCraft\Top\Collect\ContainerRef;
use SugarCraft\Top\Collect\Paths;
use SugarCraft\Top\Collect\Process;
use SugarCraft\Top\Collect\ProcList;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Collect\Vm;
use SugarCraft\Top\Collect\VmInfo;

/**
 * Wave U1b VM awareness, against tests/fixtures/linux/kvm (shaped after
 * prompt_kit/findings/kvm-reference.md).
 */
final class VmTest extends TestCase
{
    private static function kvm(): Paths
    {
        return Paths::under(dirname(__DIR__) . '/fixtures/linux/kvm');
    }

    private static function argv(string ...$argv): string
    {
        return implode("\0", $argv) . "\0";
    }

    public function testUnescapeIsSystemdUnitNameUnescaping(): void
    {
        $this->assertSame('qemu-1-vps3458844', Vm::unescape('qemu\x2d1\x2dvps3458844'));
        $this->assertSame('a b/c', Vm::unescape('a\x20b\x2Fc'), 'any \xNN, either hex case');
        $this->assertSame('x\x2', Vm::unescape('x\x2'), 'truncated escape kept verbatim');
        $this->assertSame("caf\u{e9}", Vm::unescape('caf\xc3\xa9'), 'multi-byte escapes reassemble');
        $this->assertSame('x\\y', Vm::unescape('x\\y'));
    }

    /**
     * @return iterable<string, array{string, int, string, string}>
     */
    public static function scopes(): iterable
    {
        $v2 = '/machine.slice/machine-qemu\x2d1\x2dvps3458844.scope';
        yield 'v2 libvirt emulator' => ["{$v2}/libvirt/emulator", 1, 'vps3458844', $v2];
        yield 'v2 vcpu sibling' => ["{$v2}/libvirt/vcpu0", 1, 'vps3458844', $v2];
        yield 'v2 scope itself' => [$v2, 1, 'vps3458844', $v2];
        yield 'dashes in the name' => ['/machine.slice/machine-qemu\x2d159\x2dweb\x2d01.scope/libvirt', 159, 'web-01', '/machine.slice/machine-qemu\x2d159\x2dweb\x2d01.scope'];
        yield 'session libvirt' => [
            '/user.slice/user-1000.slice/user@1000.service/machine.slice/machine-qemu\x2d2\x2dlab.scope/libvirt/emulator', 2, 'lab',
            '/user.slice/user-1000.slice/user@1000.service/machine.slice/machine-qemu\x2d2\x2dlab.scope',
        ];
        yield 'pre-1.3 libvirt, no id' => ['/machine.slice/machine-qemu\x2dlegacy.scope', Sentinel::UNMEASURED_INT, 'legacy', '/machine.slice/machine-qemu\x2dlegacy.scope'];
        yield 'v1 cgroupfs layout' => ['/machine/qemu-2-old_guest.libvirt-qemu/emulator', 2, 'old_guest', '/machine/qemu-2-old_guest.libvirt-qemu'];
        yield 'v1 partition + old name' => ['/machine/prod.partition/guest7.libvirt-qemu/vcpu1', Sentinel::UNMEASURED_INT, 'guest7', '/machine/prod.partition/guest7.libvirt-qemu'];
        yield 'v1 escaped leading _' => ['/machine/_qemu-4-_hidden.libvirt-qemu', 4, '_hidden', '/machine/_qemu-4-_hidden.libvirt-qemu'];
    }

    #[DataProvider('scopes')]
    public function testScope(string $path, int $id, string $name, string $root): void
    {
        $this->assertSame(['domainId' => $id, 'name' => $name, 'path' => $root], Vm::scope($path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notScopes(): iterable
    {
        foreach ([
            '', '/', '/init.scope', '/machine.slice', '/machine.slice/machine-my\x2dbox.scope',
            '/machine.slice/machine-qemu.scope', '/machine.slice/machine-qemu\x2d.scope',
            '/system.slice/libvirtd.service', '/machine/box.libvirt-lxc', '/.libvirt-qemu',
        ] as $path) {
            yield $path === '' ? '(empty)' : $path => [$path];
        }
    }

    #[DataProvider('notScopes')]
    public function testNotAVmScope(string $path): void
    {
        $this->assertNull(Vm::scope($path));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function smpForms(): iterable
    {
        yield 'libvirt' => ['2,sockets=2,cores=1,threads=1', 2];
        yield 'bare count' => ['16', 16];
        yield 'cpus=' => ['cpus=4,maxcpus=8', 4];
        yield 'topology only' => ['sockets=2,cores=4,threads=2', 16];
        yield 'cpus=0 → topology' => ['0,sockets=1,dies=2,cores=3', 6];
        yield 'garbage' => ['lots', Sentinel::UNMEASURED_INT];
        yield 'empty' => ['', Sentinel::UNMEASURED_INT];
        yield 'bad topology' => ['sockets=x,cores=2', Sentinel::UNMEASURED_INT];
    }

    #[DataProvider('smpForms')]
    public function testSmp(string $value, int $vcpus): void
    {
        $this->assertSame($vcpus, Vm::smp($value));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function memoryForms(): iterable
    {
        yield 'libvirt size=KiB' => ['size=6291456k', 6291456 * 1024];
        yield 'legacy MiB' => ['2048', 2048 << 20];
        yield 'G suffix' => ['4G', 4 << 30];
        yield 'lower-case g' => ['8g', 8 << 30];
        yield 'fraction' => ['1.5G', (int) (1.5 * (1 << 30))];
        yield 'M with hotplug opts' => ['size=1024M,slots=4,maxmem=8G', 1024 << 20];
        yield 'T' => ['1T', 1 << 40];
        yield 'bytes' => ['size=536870912B', 536870912];
        yield 'zero' => ['0', Sentinel::UNMEASURED_INT];
        yield 'garbage' => ['size=lots', Sentinel::UNMEASURED_INT];
        yield 'unknown suffix' => ['4X', Sentinel::UNMEASURED_INT];
        yield 'overflow' => ['99999999E', Sentinel::UNMEASURED_INT];
        yield 'empty' => ['', Sentinel::UNMEASURED_INT];
    }

    #[DataProvider('memoryForms')]
    public function testMemory(string $value, int $bytes): void
    {
        $this->assertSame($bytes, Vm::memory($value));
    }

    public function testCmdlineFromTheCapture(): void
    {
        $info = Vm::fromCmdline((string) file_get_contents(dirname(__DIR__) . '/fixtures/linux/kvm/proc/6969/cmdline'));

        $this->assertInstanceOf(VmInfo::class, $info);
        $this->assertSame('vps3485059', $info->guestName);
        $this->assertSame('d5d6df06-930b-499f-82e7-8012eab8cc99', $info->uuid, 'lower-cased');
        $this->assertSame(2, $info->vcpus);
        $this->assertSame(6291456 * 1024, $info->memBytes);
        $this->assertSame(Sentinel::UNMEASURED_INT, $info->domainId, 'cmdline carries no domain id');
    }

    public function testCmdlineNameForms(): void
    {
        $name = static fn (string ...$args): string => Vm::fromCmdline(self::argv('/usr/bin/kvm', ...$args))?->guestName ?? '(null)';

        $this->assertSame('legacy', $name('-name', 'legacy'));
        $this->assertSame('a,b', $name('-name', 'guest=a,,b,debug-threads=on'), ',, is a literal comma');
        $this->assertSame('vm', $name('-name', 'vm,process=qemu:vm'));
        $this->assertSame('second', $name('-name', 'first', '-name', 'guest=second'), 'last occurrence wins');
        $this->assertSame('dd', $name('--name', 'dd'), '--opt spelling');
        $this->assertSame('', $name('-name', 'process=x'), 'no guest key');
        $this->assertSame('', $name('-m', '1G'), 'qemu binary without -name: facts but no name');
    }

    public function testCmdlineSentinels(): void
    {
        $info = Vm::fromCmdline(self::argv('qemu-system-aarch64', '-uuid', 'not-a-uuid', '-name'));

        $this->assertNotNull($info);
        $this->assertSame(Sentinel::UNAVAILABLE, $info->uuid);
        $this->assertSame(Sentinel::UNMEASURED_INT, $info->vcpus);
        $this->assertSame(Sentinel::UNMEASURED_INT, $info->memBytes);
        $this->assertSame('', $info->guestName, 'a trailing -name with no value is ignored');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notQemuCmdlines(): iterable
    {
        yield 'find -name' => [self::argv('find', '/', '-name', 'core')];
        yield 'find -name -m' => [self::argv('find', '/', '-name', 'core', '-mmin', '5')];
        yield 'qemu-img' => [self::argv('/usr/bin/qemu-img', 'convert', '-O', 'qcow2', 'a', 'b')];
        yield 'kernel thread' => [''];
        yield 'swtpm' => [self::argv('/usr/bin/swtpm', 'socket', '--ctrl', 'type=unixio')];
    }

    #[DataProvider('notQemuCmdlines')]
    public function testNotQemu(string $cmdline): void
    {
        $this->assertNull(Vm::fromCmdline($cmdline));
        $this->assertNull(Vm::fromBareCmdline($cmdline));
    }

    public function testFindWithNameIsNotAVmEvenThoughItHasAName(): void
    {
        $this->assertNull(Cgroup::fromProcFile("0::/user.slice\n", self::argv('find', '/', '-name', 'core')));
        // A custom emulator path is qemu by its options; still not a bare-qemu VM
        // outside a VM scope (the bare fallback demands a qemu binary name)…
        $custom = self::argv('/opt/emu/run-vm', '-name', 'guest=c', '-m', '1G');
        $this->assertSame('c', Vm::fromCmdline($custom)?->guestName);
        $this->assertSame('c', Vm::fromCmdline(self::argv('/opt/emu/run-vm', '-uuid', 'd5d6df06-930b-499f-82e7-8012eab8cc99', '-name', 'c'))?->guestName);
        $this->assertNull(Cgroup::fromProcFile("0::/user.slice\n", $custom));
        // …but inside a libvirt scope its cmdline facts are used.
        $this->assertSame(1 << 30, Cgroup::fromProcFile("0::/machine.slice/machine-qemu\\x2d1\\x2dc.scope\n", $custom)?->vm?->memBytes);
    }

    public function testQemuBinaryBasenames(): void
    {
        foreach (['/usr/bin/kvm', 'kvm', '/usr/libexec/qemu-kvm', 'qemu-system-x86_64', '/opt/q/bin/qemu-system-aarch64'] as $yes) {
            $this->assertTrue(Vm::isQemuBinary($yes), $yes);
        }
        foreach (['/usr/bin/qemu-img', 'qemu-system-', '/usr/bin/kvm-ok', 'kvmtool', '/usr/bin/'] as $no) {
            $this->assertFalse(Vm::isQemuBinary($no), $no);
        }
    }

    public function testLibvirtV2ProcFile(): void
    {
        $ref = Cgroup::fromProcFile(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/linux/kvm/proc/6969/cgroup'),
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/linux/kvm/proc/6969/cmdline'),
        );

        $this->assertInstanceOf(ContainerRef::class, $ref);
        $this->assertTrue($ref->isVm());
        $this->assertSame(Vm::ENGINE, $ref->engine);
        $this->assertSame('vps3485059', $ref->name);
        $this->assertSame('vps3485059', $ref->id);
        $this->assertSame('/machine.slice/machine-qemu\x2d6\x2dvps3485059.scope', $ref->cgroupPath);
        $this->assertSame(6, $ref->vm?->domainId);
        $this->assertSame('vps3485059', $ref->vm?->scopeName);
        $this->assertTrue($ref->vm?->nameAgrees());
        $this->assertTrue($ref->vm?->libvirt());
    }

    public function testCrossCheckPrefersGuestName(): void
    {
        $cgroup = '0::/machine.slice/machine-qemu\x2d9\x2dshort.scope/libvirt/emulator' . "\n";

        $mismatch = Cgroup::fromProcFile($cgroup, self::argv('/usr/bin/kvm', '-name', 'guest=renamed'));
        $this->assertSame('renamed', $mismatch?->name, 'guest= wins');
        $this->assertSame('short', $mismatch?->vm?->scopeName);
        $this->assertFalse($mismatch?->vm?->nameAgrees());

        // libvirt truncates and sanitises the machine name: still consistent.
        $truncated = new VmInfo(3, 'very.longname-of-a', 'very..long_name--of-a-guest-that-is-long');
        $this->assertTrue($truncated->nameAgrees());
        $this->assertSame('very.longname-of-a-guest-that-is-long', Vm::machineName('very..long_name--of-a-guest-that-is-long'));
        $this->assertSame('vm1', Vm::machineName('-.vm_1.-'));

        $scopeOnly = Cgroup::fromProcFile($cgroup, null);
        $this->assertSame('short', $scopeOnly?->name, 'no cmdline → the scope name');
        $this->assertNull($scopeOnly?->vm?->nameAgrees());
        $this->assertSame(Sentinel::UNAVAILABLE, $scopeOnly?->vm?->uuid);
    }

    public function testV1LayoutsAndFirstVmLineWins(): void
    {
        $v1 = (string) file_get_contents(dirname(__DIR__) . '/fixtures/linux/kvm/proc/7100/cgroup');
        $ref = Cgroup::fromProcFile($v1, (string) file_get_contents(dirname(__DIR__) . '/fixtures/linux/kvm/proc/7100/cmdline'));

        $this->assertSame('old_guest', $ref?->name);
        $this->assertSame('/machine/qemu-2-old_guest.libvirt-qemu', $ref?->cgroupPath);
        $this->assertSame(2, $ref?->vm?->domainId);
        $this->assertSame(4, $ref?->vm?->vcpus, 'sockets × cores × threads');
        $this->assertSame(2048 << 20, $ref?->vm?->memBytes, 'legacy -m MiB');

        $systemd = Cgroup::fromProcFile(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/linux/kvm/proc/7150/cgroup'),
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/linux/kvm/proc/7150/cmdline'),
        );
        $this->assertSame('web-01,eu', $systemd?->id);
        $this->assertSame('web-01?eu', $systemd?->name, 'display name whitelisted like Cgroup::safe');
        $this->assertSame('web-01', $systemd?->vm?->scopeName);
        $this->assertTrue($systemd?->vm?->nameAgrees());
        $this->assertSame(3, $systemd?->vm?->domainId);
    }

    public function testOutermostWinsBetweenContainerAndVm(): void
    {
        $id = '3f2a9c1b04de5a7788990011223344556677889900aabbccddeeff0011223344';
        $qemuInLxc = "0::/lxc.payload.host/machine.slice/machine-qemu\\x2d1\\x2dvm.scope/libvirt/emulator\n";
        $this->assertSame('lxc', Cgroup::fromProcFile($qemuInLxc)?->engine);

        $dockerInVm = "0::/machine.slice/machine-qemu\\x2d1\\x2dvm.scope/docker-{$id}.scope\n";
        $this->assertSame(Vm::ENGINE, Cgroup::fromProcFile($dockerInVm)?->engine);

        $this->assertSame('docker', Cgroup::fromProcFile("0::/system.slice/docker-{$id}.scope\n", self::argv('qemu-system-x86_64', '-name', 'x'))?->engine, 'qemu in docker is the container');
    }

    public function testDisplayNameIsTerminalSafe(): void
    {
        $ref = Cgroup::fromProcFile("0::/user.slice\n", self::argv('qemu-system-x86_64', '-name', "guest=evil\x1b[31mvm"));

        $this->assertSame('evil??31mvm', $ref?->name);
        $this->assertSame("evil\x1b[31mvm", $ref?->id, 'id stays raw');
        $this->assertSame('', $ref?->cgroupPath, 'bare qemu owns no VM cgroup');
        $this->assertFalse($ref?->vm?->libvirt());
    }

    public function testParseStaysContainersOnly(): void
    {
        $this->assertNull(Cgroup::parse('/machine.slice/machine-qemu\x2d1\x2dvm.scope'));
    }

    public function testVcpuThreadsByCommAndByCgroup(): void
    {
        $this->assertSame([6980 => 0, 6981 => 1], Vm::vcpuThreads(self::kvm(), 6969), 'debug-threads comms');
        $this->assertSame([5368 => 0], Vm::vcpuThreads(self::kvm(), 5361), 'no debug-threads: libvirt vcpuN cgroup');
        $this->assertSame([], Vm::vcpuThreads(self::kvm(), 7300), 'no task dir');
        $this->assertSame([], Vm::vcpuThreads(self::kvm(), 99999), 'vanished pid');
    }

    public function testProcListTagsVmProcesses(): void
    {
        [$snap] = ProcList::new(self::kvm(), pageSize: 4096, userLookup: static fn (int $uid): ?string => null)->sample();
        $by = [];
        foreach ($snap->processes as $p) {
            $by[$p->pid] = $p;
        }
        $container = static fn (int $pid): ?ContainerRef => ($by[$pid] ?? null) instanceof Process ? $by[$pid]->container : null;

        $this->assertSame([1, 5361, 6969, 7001, 7100, 7150, 7200, 7300, 7400, 7401, 7402], array_keys($by));

        $vm = $container(6969);
        $this->assertSame(Vm::ENGINE, $vm?->engine);
        $this->assertSame('vps3485059', $vm?->name);
        $this->assertSame('d5d6df06-930b-499f-82e7-8012eab8cc99', $vm?->vm?->uuid);
        $this->assertSame(2, $vm?->vm?->vcpus);
        $this->assertSame(6291456 * 1024, $vm?->vm?->memBytes);
        $this->assertStringStartsWith('kvm -name guest=vps3485059', $by[6969]->cmdBasename());

        $this->assertSame('vps3458844', $container(5361)?->name);
        $this->assertSame(4194304 * 1024, $container(5361)?->vm?->memBytes);

        $helper = $container(7001);
        $this->assertSame('vps3485059', $helper?->name, 'swtpm in the scope belongs to the VM');
        $this->assertSame($vm?->cgroupPath, $helper?->cgroupPath, 'groups with its VM');
        $this->assertSame('', $helper?->vm?->guestName);
        $this->assertSame(Sentinel::UNMEASURED_INT, $helper?->vm?->vcpus);

        $this->assertSame('old_guest', $container(7100)?->name);
        $this->assertSame('web-01?eu', $container(7150)?->name);

        $bare = $container(7200);
        $this->assertSame('devbox', $bare?->name);
        $this->assertSame(4, $bare?->vm?->vcpus);
        $this->assertSame((int) (1.5 * (1 << 30)), $bare?->vm?->memBytes);
        $this->assertSame(Sentinel::UNMEASURED_INT, $bare?->vm?->domainId);

        $this->assertSame('docker', $container(7300)?->engine, 'containers detected as before');
        $this->assertNull($container(7300)?->vm);
        foreach ([1, 7400, 7401, 7402] as $host) {
            $this->assertNull($container($host), "pid {$host} is a host process");
        }
    }
}

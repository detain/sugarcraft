<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Cgroup;
use SugarCraft\Top\Collect\ContainerInfo;
use SugarCraft\Top\Collect\ContainerRef;
use SugarCraft\Top\Collect\Containers;
use SugarCraft\Top\Collect\DockerSocket;
use SugarCraft\Top\Collect\Platform;
use SugarCraft\Top\Collect\Process;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Collect\VmInfo;

/**
 * btop PR #1873 Ctr::collect / docker_name / docker_containers.
 *
 * tests/fixtures/cgroup/kvm521/ holds read-only captures from a production
 * libvirt/KVM host (Ubuntu, kernel 6.8, cgroup v2, 55 qemu guests, no
 * docker or podman), taken 2026-10-08: `*.cgroup` are verbatim
 * /proc/<pid>/cgroup lines (a qemu emulator thread, PID 1, an ssh
 * session); cpu.stat / memory.current / memory.max / memory.stat are the
 * files of `machine.slice/machine-qemu\x2d1\x2dvps3458844.scope`
 * (memory.stat cut to its first keys plus that scope's inactive_file), used
 * here as real-format input for the cgroup v2 reader.
 */
final class ContainersTest extends TestCase
{
    private const string ID = '3f2a9c1b04de5a7788990011223344556677889900aabbccddeeff0011223344';

    private const string REAL = __DIR__ . '/../fixtures/cgroup/kvm521';

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/candy-top-ctr-' . bin2hex(random_bytes(4));
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

    public function testTheLibvirtHostHasNoContainers(): void
    {
        // btop parse_cgroup: machine-qemu scopes are VMs, never containers.
        $line = (string) file_get_contents(self::REAL . '/qemu-emulator.cgroup');
        $this->assertNull(Cgroup::parse(explode(':', trim($line), 3)[2]));
        foreach (['init', 'session'] as $f) {
            $this->assertNull(Cgroup::fromProcFile((string) file_get_contents(self::REAL . "/{$f}.cgroup")));
        }
        // candy-top tags the emulator as a KVM guest (Wave U1b) — the ctr box still leaves it out.
        $vm = Cgroup::fromProcFile($line, "qemu-system-x86_64\0-name\0guest=vps3458844\0");
        $this->assertNotNull($vm);
        $this->assertTrue($vm->isVm());
        [$ctrs] = Containers::group([], [self::proc(1, 50.0, 100, $vm)]);
        $this->assertSame([], $ctrs);
    }

    public function testGroupSumsLiveProcessesPerContainer(): void
    {
        $web = self::docker('/system.slice/docker-' . self::ID . '.scope');
        $lxc = Cgroup::parse('/lxc.payload.build/init.scope');
        [$ctrs, $newDocker] = Containers::group([], [
            self::proc(10, 12.5, 100, $web),
            self::proc(11, Sentinel::UNMEASURED, 50, $web),
            self::proc(12, 3.0, 7, $lxc),
            self::proc(13, 99.0, 1, null),
            self::proc(14, 40.0, 9, $web, 'X'),
        ]);
        $this->assertTrue($newDocker);
        $this->assertSame(['3f2a9c1b04de', 'build'], array_map(static fn (ContainerInfo $c): string => $c->name, $ctrs));
        $this->assertSame([2, 12.5, 150], [$ctrs[0]->procs, $ctrs[0]->cpu, $ctrs[0]->mem], 'UNMEASURED cpu counts as 0; dead processes are skipped');
        $this->assertSame(['lxc', 1, 3.0, 7], [$ctrs[1]->engine, $ctrs[1]->procs, $ctrs[1]->cpu, $ctrs[1]->mem]);

        [$again, $newAgain] = Containers::group($ctrs, [self::proc(12, 1.0, 2, $lxc)]);
        $this->assertFalse($newAgain, 'a known docker container is not new');
        $this->assertSame(['build'], array_map(static fn (ContainerInfo $c): string => $c->name, $again), 'a container without processes is dropped');
    }

    public function testCgroupV2FiguresReplaceTheProcessSums(): void
    {
        $path = '/system.slice/docker-' . self::ID . '.scope';
        $this->cgroup($path, 'usage_usec 1000000', '200', "anon 1\ninactive_file 50\n", '1000');
        $now = 10_000_000;
        $calls = 0;
        $c = Containers::new($this->root, static function () use (&$calls): string {
            $calls++;

            return '';
        }, static function () use (&$now): int {
            return $now;
        });
        $procs = [self::proc(1, 7.0, 999, self::docker($path))];
        [$snap, $c] = $c->collect($procs, 4096, 4, false, 100);
        $first = $snap->containers[0];
        $this->assertSame(7.0, $first->cpu, 'no baseline yet: the process sum stays');
        $this->assertSame(150, $first->mem, 'memory.current minus inactive_file (docker stats)');
        $this->assertSame(1000, $first->memLimit);
        $this->assertSame(1_000_000, $first->cpuTime);
        $this->assertSame([7], $first->history, 'without proc_per_core cpu is already percent of total power');

        // 2 s later the cgroup used 4 s of cpu: 200 % of one core, 50 % of 4.
        $now += 2_000_000;
        file_put_contents($this->root . $path . '/cpu.stat', "usage_usec 5000000\nuser_usec 1\n");
        [$snap, $c] = $c->collect($procs, 4096, 4, false, 100);
        $this->assertSame(50.0, $snap->containers[0]->cpu);
        $this->assertSame([7, 50], $snap->containers[0]->history);

        $now += 1_000_000;
        file_put_contents($this->root . $path . '/cpu.stat', "usage_usec 6000000\n");
        [$snap] = $c->collect($procs, 4096, 4, true, 100);
        $this->assertSame(100.0, $snap->containers[0]->cpu, 'proc_per_core: percent of one core');
        $this->assertSame([7, 50, 25], $snap->containers[0]->history, 'the graph stays in percent of total power');
        $this->assertSame(1, $calls, 'the docker socket is asked only when a docker container appears');
    }

    public function testRealHostFileFormatsParse(): void
    {
        $path = '/machine.slice/demo.scope';
        mkdir($this->root . $path, 0777, true);
        foreach (['cpu.stat', 'memory.current', 'memory.max', 'memory.stat'] as $f) {
            copy(self::REAL . '/' . $f, $this->root . $path . '/' . $f);
        }
        $ref = new ContainerRef('lxc', 'demo', 'demo', $path);
        [$snap] = Containers::new($this->root, static fn (): string => '', static fn (): int => 1)->collect([self::proc(1, 1.0, 5, $ref)], 0, 48, false, 10);
        $c = $snap->containers[0];
        $this->assertSame(335096323396, $c->cpuTime);
        $this->assertSame(1389207552, $c->mem, 'inactive_file 0');
        $this->assertSame(0, $c->memLimit, '"max" is unlimited');
        $this->assertSame(335096323396, Containers::cpuUsage((string) file_get_contents(self::REAL . '/cpu.stat')));
        $this->assertSame(2217099264, Containers::statValue((string) file_get_contents(self::REAL . '/memory.stat'), 'anon'));
    }

    public function testCgroupV1OrUnreadableKeepsTheProcessSums(): void
    {
        $ref = Cgroup::parse('/lxc.payload.web/init.scope');
        [$snap] = Containers::new($this->root, static fn (): string => '', static fn (): int => 5)->collect([self::proc(1, 3.5, 77, $ref)], 0, 2, false, 10);
        $this->assertSame([3.5, 77, 0, 0], [$snap->containers[0]->cpu, $snap->containers[0]->mem, $snap->containers[0]->memLimit, $snap->containers[0]->cpuTime]);
    }

    public function testATraversingCgroupPathIsNeverRead(): void
    {
        mkdir($this->root . '/secret', 0777, true);
        file_put_contents($this->root . '/secret/memory.current', '12345');
        $ref = new ContainerRef('lxc', 'x', 'x', '/lxc.payload.x/../../secret');
        [$snap] = Containers::new($this->root . '/sub', static fn (): string => '', static fn (): int => 1)->collect([self::proc(1, 1.0, 9, $ref)], 0, 1, false, 10);
        $this->assertSame(9, $snap->containers[0]->mem);
    }

    public function testDockerNamesAndSortOrder(): void
    {
        $a = '/system.slice/docker-' . self::ID . '.scope';
        $bId = 'aa' . substr(self::ID, 2);
        $b = '/system.slice/docker-' . $bId . '.scope';
        $response = "HTTP/1.0 200 OK\r\n\r\n[{\"Id\":\"" . self::ID . '","Names":["/zeta"]},{"Id":"' . $bId . '","Names":["/alpha"]}]';
        $calls = 0;
        $c = Containers::new($this->root, static function () use (&$calls, $response): string {
            $calls++;

            return $response;
        }, static fn (): int => 1);
        [$snap, $c] = $c->collect([self::proc(1, 1.0, 1, self::docker($a)), self::proc(2, 1.0, 1, self::docker($b))], 0, 1, false, 10);
        $this->assertSame(['alpha', 'zeta'], array_map(static fn (ContainerInfo $x): string => $x->name, $snap->containers), 'sorted by name');
        // A renamed container keeps its name until it restarts (btop).
        [$snap] = $c->collect([self::proc(1, 1.0, 1, self::docker($a)), self::proc(2, 1.0, 1, self::docker($b))], 0, 1, false, 10);
        $this->assertSame(['alpha', 'zeta'], array_map(static fn (ContainerInfo $x): string => $x->name, $snap->containers));
        $this->assertSame(1, $calls);
    }

    public function testDockerNameParsingMirrorsThePrTests(): void
    {
        $id = self::ID;
        $response = "HTTP/1.0 200 OK\r\n\r\n[{\"Id\":\"{$id}\",\"Names\":[\"/web-1\"],\"Image\":\"nginx\","
            . '"Labels":{"Id":"3f2a"}},{"Id":"aa' . substr($id, 2) . '","Names":["/bad' . "\\u001b[31m" . '"]}]';
        $this->assertSame('web-1', DockerSocket::name($response, '3f2a9c1b04de'));
        $this->assertSame('', DockerSocket::name($response, 'aa2a9c1b04de'), 'names docker could not have are rejected');
        $this->assertSame('', DockerSocket::name($response, 'ffffffffffff'));
        $this->assertSame('', DockerSocket::name('', '3f2a9c1b04de'));
        $this->assertSame('', DockerSocket::name('{"Id":"3f2a9c1b04de', '3f2a9c1b04de'));
    }

    public function testDockerSocketPathAndDegradation(): void
    {
        $this->assertSame('/var/run/docker.sock', DockerSocket::path(null));
        $this->assertSame('/run/user/1000/docker.sock', DockerSocket::path('unix:///run/user/1000/docker.sock'));
        $this->assertSame('/var/run/docker.sock', DockerSocket::path('tcp://10.0.0.1:2375'), 'only a unix:// DOCKER_HOST is honoured');
        $this->assertSame('', DockerSocket::fetch($this->root . '/missing.sock'));
        $this->assertSame('', DockerSocket::fetch('/' . str_repeat('a', 120)), 'longer than sun_path');
        file_put_contents($this->root . '/plain', 'x');
        $this->assertSame('', DockerSocket::fetch($this->root . '/plain'), 'not a socket');
    }

    public function testASilentDaemonIsBoundedByTheTimeout(): void
    {
        $sock = $this->root . '/d.sock';
        $server = @stream_socket_server('unix://' . $sock, $errno, $error);
        if ($server === false) {
            $this->markTestSkipped('unix sockets unavailable: ' . $error);
        }
        try {
            $t = hrtime(true);
            $this->assertSame('', DockerSocket::fetch($sock), 'connected, never answered');
            $elapsed = (hrtime(true) - $t) / 1e9;
            $this->assertGreaterThan(0.9, $elapsed);
            $this->assertLessThan(1.2, $elapsed, 'connect + write + read share one 1 s deadline');
        } finally {
            fclose($server);
        }
    }

    public function testATricklingDaemonIsCutAtTheDeadline(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            $this->markTestSkipped('needs pcntl + posix');
        }
        $sock = $this->root . '/t.sock';
        $server = @stream_socket_server('unix://' . $sock, $errno, $error);
        if ($server === false) {
            $this->markTestSkipped('unix sockets unavailable: ' . $error);
        }
        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($server);
            $this->markTestSkipped('fork failed');
        }
        if ($pid === 0) {
            // Child: a byte at once, then one every 0.6 s — each read alone
            // would beat a per-call 1 s timeout, so only a whole-exchange
            // deadline stops it.
            $conn = @stream_socket_accept($server, 5);
            for ($i = 0; $conn !== false && $i < 8; $i++) {
                @fwrite($conn, 'H');
                usleep(600_000);
            }
            posix_kill(getmypid(), SIGKILL); // never run PHPUnit's shutdown in the child
        }
        try {
            $t = hrtime(true);
            $reply = DockerSocket::fetch($sock);
            $elapsed = (hrtime(true) - $t) / 1e9;
            $this->assertStringStartsWith('H', $reply, 'what arrived in time is kept');
            $this->assertLessThan(1.2, $elapsed, 'a trickle cannot stretch the exchange past its deadline');
        } finally {
            if ($pid > 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
            fclose($server);
        }
    }

    public function testRebasedCountsTheNextSampleAtOnce(): void
    {
        $ref = Cgroup::parse('/lxc.payload.web/init.scope');
        $path = '/lxc.payload.web';
        $this->cgroup($path, 'usage_usec 1000000', '10', '', 'max');
        $now = 5_000_000;
        $c = Containers::new($this->root, static fn (): string => '', static function () use (&$now): int {
            return $now;
        });
        [, $c] = $c->collect([self::proc(1, 2.0, 1, $ref)], 0, 1, false, 10);
        $this->assertFalse($c->due(1_000_000), 'just sampled');
        $now += 100_000;
        $re = $c->rebased();
        $this->assertTrue($re->due(1_000_000), 'a re-shown box counts its first sample');
        file_put_contents($this->root . $path . '/cpu.stat', "usage_usec 1050000\n");
        [$snap] = $re->collect([self::proc(1, 2.0, 1, $ref)], 0, 1, false, 10);
        $this->assertSame(2.0, $snap->containers[0]->cpu, 'no baseline after the rebase: the process sum, never a delta over the whole uptime');
        $this->assertSame(1_050_000, $snap->containers[0]->cpuTime);
        $this->assertCount(2, $snap->containers[0]->history, 'history is kept');
    }

    public function testHistoryIsCappedAtTheTerminalWidth(): void
    {
        $ref = Cgroup::parse('/lxc.payload.web/init.scope');
        $c = Containers::new($this->root, static fn (): string => '', static fn (): int => 1);
        for ($i = 0; $i < 5; $i++) {
            [$snap, $c] = $c->collect([self::proc(1, (float) $i, 1, $ref)], 0, 1, false, 3);
        }
        $this->assertSame([2, 3, 4], $snap->containers[0]->history);
    }

    public function testDisabledCollectorIsAnEmptyBox(): void
    {
        $ref = Cgroup::parse('/lxc.payload.web/init.scope');
        [$snap, $next] = Containers::disabled()->collect([self::proc(1, 1.0, 1, $ref)], 123, 4, false, 10);
        $this->assertSame([], $snap->containers);
        $this->assertSame(123, $snap->memTotal);
        $this->assertFalse($next->enabled());
        $this->assertInstanceOf(Containers::class, Platform::for('FreeBSD')->containers());
        $this->assertFalse(Platform::for('FreeBSD')->containers()->enabled(), 'FreeBSD: btop fills the box on Linux only');
        $this->assertTrue(Platform::for('Linux')->containers()->enabled());
    }

    private function cgroup(string $path, string $cpu, string $current, string $stat, string $max): void
    {
        mkdir($this->root . $path, 0777, true);
        file_put_contents($this->root . $path . '/cpu.stat', $cpu . "\n");
        file_put_contents($this->root . $path . '/memory.current', $current . "\n");
        file_put_contents($this->root . $path . '/memory.stat', $stat);
        file_put_contents($this->root . $path . '/memory.max', $max . "\n");
    }

    private static function docker(string $path): ContainerRef
    {
        $ref = Cgroup::parse($path);
        self::assertNotNull($ref);

        return $ref;
    }

    private static function proc(int $pid, float $cpu, int $mem, ?ContainerRef $ref, string $state = 'S'): Process
    {
        return new Process($pid, 1, 'p' . $pid, 'p' . $pid, 'root', 0, $state, 1, 0, $mem, $cpu, 0.0, container: $ref);
    }

    /** Keeps the VmInfo import honest for the VM fixture case. */
    public function testVmRefsCarryVmInfo(): void
    {
        $vm = new ContainerRef('kvm', 'g', 'g', '/machine.slice/x.scope', new VmInfo(1, 'g', 'g', Sentinel::UNAVAILABLE, 1, 1));
        [$ctrs] = Containers::group([], [self::proc(1, 1.0, 1, $vm)]);
        $this->assertSame([], $ctrs);
    }
}

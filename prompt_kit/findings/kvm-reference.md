# KVM/libvirt host reference output — kvm521 (captured 2026-10-08)

Ubuntu 24.04.5, kernel 6.8.0-134, libvirt/QEMU (binary `/usr/bin/kvm`), cgroup v2, run as root.
Reference for a candy-top VM-awareness feature (plan_top.md Wave U: U1b VM tag, U4 VM box).

## Notes for the collector
- **Binary name is `/usr/bin/kvm`**, not `qemu-system-*` — `pgrep -f 'qemu-(system|kvm)'` matched nothing.
  Detect VMs by cgroup path + cmdline, never by exe name alone.
- **cgroup v2 path** (unprivileged-readable `/proc/<pid>/cgroup`):
  `0::/machine.slice/machine-qemu\x2d<domid>\x2d<name>.scope/libvirt/emulator` — `\x2d` is systemd's
  escaped `-`; unescape, then `machine-qemu-<id>-<name>.scope` → domain id + name. vCPU threads live in
  sibling `libvirt/vcpuN` cgroups (per-thread `/proc/<pid>/task/<tid>/cgroup`).
- **cmdline** (`/proc/<pid>/cmdline`, NUL-separated): `-name guest=<name>,debug-threads=on`,
  `-uuid <uuid>`, `-smp <n>,sockets=…`, `-m size=<KiB>k` (older qemu: `-m <MiB>`). `debug-threads=on`
  names threads `CPU 0/KVM` etc. in `/proc/<pid>/task/*/comm` → per-vCPU utilisation without libvirt.
- **libvirt pid files**: `/run/libvirt/qemu/<name>.pid` (+ `<name>.xml` live domain XML; root-only).
- **/proc/<pid>/io** readable as root: rchar/wchar/syscr/syscw (+ read_bytes/write_bytes, truncated here).
- **virsh domstats** (needs libvirt socket access, i.e. root or libvirt group): cpu.time/user/system (ns),
  balloon.* (KiB: current/maximum/unused/available/usable/rss), vcpu.N.time/wait/delay, block/net counters
  via `domblkstat`/`domifstat` (vnet0 rx/tx bytes). Optional enrichment only — shelling out per VM per tick
  is too slow on a host with 100+ domains; if used, one `virsh domstats --raw` for all domains per cadence.
- Scale: ~160 domains on this host (domain ids up to 159+), 8–20 threads each.

## Raw output
```
# uname -a; head -3 /etc/os-release
Linux kvm521.is.cc 6.8.0-134-generic #134-Ubuntu SMP PREEMPT_DYNAMIC Fri Jun 26 18:43:11 UTC 2026 x86_64 x86_64 x86_64 GNU/Linux
PRETTY_NAME="Ubuntu 24.04.5 LTS"
NAME="Ubuntu"
VERSION_ID="24.04"

# ps -eo pid,ppid,user,nlwp,rss,%cpu,etime,cmd --sort=-%cpu | grep -E '[q]emu|[k]vm' | head -5 | cut -c1-600
  11541       1 libvirt+   17 6115636 67.8 91-13:28:16 /usr/bin/kvm -name guest=vps3369571,debug-threads=on -S -object {"qom-type":"secret","id":"masterKey0","format":"raw","file":"/var/lib/libvirt/qemu/domain-23-vps3369571/master-key.aes"} -machine pc-i440fx-noble,usb=off,dump-guest-core=off,memory-backend=pc.ram,acpi=on -accel kvm -cpu host,migratable=on,host-cache-info=on,l3-cache=off -m size=6291456k -object {"qom-type":"memory-backend-ram","id":"pc.ram","size":6442450944} -overcommit mem-lock=off -smp 2,sockets=2,cores=1,threads=1 -uuid d5d6df06-930b-499f-82e7-8012eab8cc99 -no-user-config
 600839       1 libvirt+    8 1272812 53.7 61-22:06:00 /usr/bin/kvm -name guest=vps3540603,debug-threads=on -S -object {...} -m size=2097152k ... -smp 1,sockets=1,cores=1,threads=1 -uuid f5152686-fc4a-4f7f-a063-0cd2d767c045 -no-user-config
  14703       1 libvirt+   20 2157508 49.9 91-13:26:22 /usr/bin/kvm -name guest=vps3369704,debug-threads=on -S ... -m size=2097152k ... -smp 1,... -uuid d4fd26d9-dd0d-41d8-b850-9b15f1024079 -no-user-config
   8848       1 libvirt+   17 6708736 40.6 91-13:30:05 /usr/bin/kvm -name guest=vps3368451,debug-threads=on -S ... -m size=8388608k ... -smp 2,... -uuid e037c2e5-917f-49ca-b075-997dbedc3dfb -no-user-config
3800804       1 libvirt+   10 1754272 38.8 31-21:25:03 /usr/bin/kvm -name guest=vps3607236,debug-threads=on -S ... -m size=2097152k ... -smp 1,... -uuid 677bae50-c5a6-4c6a-b99a-7f955fdc1cb3 -no-user-confi

# for p in $(pgrep -f 'kvm.*qemu' | head -2); do cat /proc/$p/cgroup; <cmdline flags>; ls /proc/$p/task | wc -l; head -4 /proc/$p/io; done
== 5361
0::/machine.slice/machine-qemu\x2d1\x2dvps3458844.scope/libvirt/emulator
-name guest=vps3458844,debug-threads=on
-m size=4194304k
-smp 1,sockets=1,cores=1,threads=1
-uuid c38c67e2-64c9-4006-ab8a-d8906ad96a02
12
rchar: 17082676865
wchar: 138722811833
syscr: 538782969
syscw: 827363799
== 6192
0::/machine.slice/machine-qemu\x2d3\x2dvps3470511.scope/libvirt/emulator
-name guest=vps3470511,debug-threads=on
-m size=2097152k
-smp 1,sockets=1,cores=1,threads=1
-uuid 8e314fad-c538-4a13-95ed-0bf51a259cf3
9
rchar: 11225952693
wchar: 80080592169
syscr: 535646675
syscw: 816839883

# systemd-cgls -u machine.slice --no-pager | head -20
Unit machine.slice (/machine.slice):
├─machine-qemu\x2d6\x2dvps3485059.scope …
│ └─libvirt
│   ├─6969 /usr/bin/kvm -name guest=vps3485059,debug-threads=on -S -object {"qo…
│   ├─vcpu1
│   ├─vcpu0
│   └─emulator
├─machine-qemu\x2d13\x2dvps3368451.scope …
│ └─libvirt
│   ├─8848 /usr/bin/kvm -name guest=vps3368451,debug-threads=on -S -object {"qo…
│   ├─vcpu1
│   ├─vcpu0
│   └─emulator
├─machine-qemu\x2d142\x2dvps3605557.scope …
│ └─libvirt
│   ├─718511 /usr/bin/kvm -name guest=vps3605557,debug-threads=on -S -object {"…
│   ├─vcpu1
│   ├─vcpu0
│   └─emulator
├─machine-qemu\x2d159\x2dvps3691433.scope …

# ls /sys/fs/cgroup/machine.slice/ | head
cgroup.controllers
cgroup.events
cgroup.freeze
cgroup.kill
cgroup.max.depth
cgroup.max.descendants
cgroup.pressure
cgroup.procs
cgroup.stat
cgroup.subtree_control

# virsh domstats vps3458844 | head -60
Domain: 'vps3458844'
  state.state=1
  state.reason=3
  cpu.time=334246652788000
  cpu.user=272634074881000
  cpu.system=61612577906000
  cpu.cache.monitor.count=0
  cpu.haltpoll.success.time=101860087548
  cpu.haltpoll.fail.time=640842567464
  balloon.current=4194304
  balloon.maximum=4194304
  balloon.swap_in=0
  balloon.swap_out=0
  balloon.major_fault=0
  balloon.minor_fault=0
  balloon.unused=3310832
  balloon.available=3411636
  balloon.usable=3192772
  balloon.last-update=1783561698
  balloon.disk_caches=12528
  balloon.hugetlb_pgalloc=0
  balloon.hugetlb_pgfail=0
  balloon.rss=3233044
  vcpu.current=1
  vcpu.maximum=1
  vcpu.0.state=1
  vcpu.0.time=134767410000000
  vcpu.0.wait=0
  vcpu.0.delay=427212450478
  vcpu.0.halt_exits.sum=994911212
  vcpu.0.blocking.cur=yes
  (… further vcpu.0.* kvm stat counters …)

# virsh dominfo vps3458844
Id:             1
Name:           vps3458844
UUID:           c38c67e2-64c9-4006-ab8a-d8906ad96a02
OS Type:        hvm
State:          running
CPU(s):         1
CPU time:       334229.7s
Max memory:     4194304 KiB
Used memory:    4194304 KiB
Persistent:     yes
Autostart:      enable
Managed save:   no
Security model: apparmor
Security DOI:   0
Security label: libvirt-c38c67e2-64c9-4006-ab8a-d8906ad96a02 (enforcing)
Messages:       tainted: potentially unsafe use of host CPU passthrough

# virsh domifstat vps3458844 vnet0
vnet0 rx_bytes 5121502585
vnet0 rx_packets 29404966
vnet0 rx_errs 0
vnet0 rx_drop 0
vnet0 tx_bytes 3810900460
vnet0 tx_packets 22036678
vnet0 tx_errs 0
vnet0 tx_drop 0

# virsh domblkstat vps3458844
 rd_req 209671
 rd_bytes 6500410384
 wr_req 13379927
 wr_bytes 127810465792
 flush_operations 1268669
 rd_total_times 300103822455
 wr_total_times 44551159508066
 flush_total_times 404419689522143

# ls /run/libvirt/qemu/ | head; ls /var/run/libvirt/qemu/*.pid | head -3
autostarted
channel
dbus
driver.pid
passt
slirp
vps3368339.pid
vps3368339.xml
vps3368444.pid
vps3368444.xml
/var/run/libvirt/qemu/driver.pid
/var/run/libvirt/qemu/vps3368339.pid
/var/run/libvirt/qemu/vps3368444.pid
```

## Per-VM data for a VM dashboard (kvm521, 2026-10-08, no subprocess needed)
- Running domains: `/var/run/libvirt/qemu/<name>.xml` (root-readable live XML, 55 on kvm521): `<vcpu>N</vcpu>`,
  `<memory unit='KiB'>`, `<currentMemory>`, disks `<source file=…>`/`<target dev='sda'>`, NICs `<target dev='vnet125'/>`.
- cgroup v2 scope `machine.slice/machine-qemu\x2dN\x2d<name>.scope` (+ `libvirt/{emulator,vcpu0..}` children):
  `cpu.stat` usage_usec (÷ vcpus for % of allocation), `cpu.max` (quota), `memory.current`/`memory.stat`/`memory.peak`,
  `io.stat` per-device `rbytes/wbytes/rios/wios` (sum for disk rate), `cpu.pressure`/`memory.pressure`/`io.pressure` (PSI).
- Network: `/sys/class/net/vnetN/statistics/{rx,tx}_bytes` — host-side tap, so host rx = guest upload (swap them).

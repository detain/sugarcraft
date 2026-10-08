# FreeBSD reference output — tech.trouble-free.net (captured 2026-10-08)

FreeBSD 14.4-RELEASE-p10 GENERIC amd64, Intel Atom D525 (4 threads), 4 GiB, UFS on gmirror (no ZFS),
no cpufreq driver, no battery, ACPI thermal tz0 only. Captured as an unprivileged user (detain).
Reference fixtures for a future candy-top FreeBSD collector set (post-v1; plan_top.md §1 non-goal,
Wave U4 FreeBSD PRs #1851/#1830/#1787/#1728 fold in then).

## Notes for the collector port
- **cpu**: `kern.cp_time` = 5 longs (user nice sys intr idle); `kern.cp_times` = 5×ncpu, per core in order.
- **mem**: page counts × `hw.pagesize`; `v_cache_count` is 0 on modern FreeBSD (laundry replaced cache);
  ARC sysctls absent without ZFS → must sentinel, not fail (#1728/#1851 territory).
- **swap**: `swapinfo -k` text (sysctl `vm.swap_info` is opaque/binary — prints nothing); btop uses kvm_getswapinfo.
- **freq**: `dev.cpu.0.freq` absent without cpufreq/est driver → sentinel.
- **temp**: no `dev.cpu.N.temperature` (coretemp not loaded); `hw.acpi.thermal.tzN.temperature` = "40.1C" string.
- **battery**: no `hw.acpi.battery` sysctl tree on a battery-less host; `acpiconf -i 0` needs privilege (EPERM).
- **net**: `netstat -ibn` Link# rows carry byte counters (Ibytes/Obytes); address rows repeat per-AF.
- **disk**: `iostat -x` first block = since-boot average, second = interval; `pass*` are SCSI passthrough (filter);
  `/dev/mirror/root` is a gmirror provider over ada0+ada1.
- **procs**: unprivileged `ps` showed only the user's own processes → `security.bsd.see_other_uids=0` likely set;
  a collector must degrade gracefully. procfs IS mounted at /proc but is the FreeBSD layout (not Linux's);
  `kern.proc.all` is readable — btop uses kvm_getprocs. `procstat -f` gives cwd (#1546 equivalent).

## Raw output

```
$ uname -a; freebsd-version -ku
FreeBSD tech.trouble-free.net 14.4-RELEASE-p10 FreeBSD 14.4-RELEASE-p10 releng/14.4-n273778-8b3713b7410d GENERIC amd64
14.4-RELEASE-p10
14.4-RELEASE-p10

$ sysctl hw.ncpu hw.physmem hw.pagesize hw.model kern.cp_time kern.cp_times kern.boottime vm.loadavg
hw.ncpu: 4
hw.physmem: 4248858624
hw.pagesize: 4096
hw.model: Intel(R) Atom(TM) CPU D525   @ 1.80GHz
kern.cp_time: 12473173 1 3448733 167285 326340387
kern.cp_times: 1840157 0 909396 149224 82708621 2745303 0 894166 7601 81960328 3929268 1 819893 5578 80852646 3958445 0 825278 4882 80818793
kern.boottime: { sec = 1790798684, usec = 725700 } Wed Sep 30 16:04:44 2026
vm.loadavg: { 0.32 0.33 0.25 }

$ sysctl vm.stats.vm.v_page_count vm.stats.vm.v_free_count vm.stats.vm.v_active_count vm.stats.vm.v_inactive_count vm.stats.vm.v_wire_count vm.stats.vm.v_cache_count vm.stats.vm.v_laundry_count
vm.stats.vm.v_page_count: 1005423
vm.stats.vm.v_free_count: 518559
vm.stats.vm.v_active_count: 25110
vm.stats.vm.v_inactive_count: 253899
vm.stats.vm.v_wire_count: 208051
vm.stats.vm.v_cache_count: 0
vm.stats.vm.v_laundry_count: 0

$ sysctl kstat.zfs.misc.arcstats.size kstat.zfs.misc.arcstats.c_min vfs.zfs.arc_max
sysctl: unknown oid 'kstat.zfs.misc.arcstats.size'
sysctl: unknown oid 'kstat.zfs.misc.arcstats.c_min'
sysctl: unknown oid 'vfs.zfs.arc_max'

$ swapinfo -k; sysctl vm.swap_info | head
Device          1K-blocks     Used    Avail Capacity
/dev/mirror/swap   4194300        0  4194300     0%

$ sysctl dev.cpu.0.freq dev.cpu.0.freq_levels dev.cpu.0.temperature hw.acpi.thermal
sysctl: unknown oid 'dev.cpu.0.freq'
sysctl: unknown oid 'dev.cpu.0.freq_levels'
sysctl: unknown oid 'dev.cpu.0.temperature'
hw.acpi.thermal.tz0._TSP: -1
hw.acpi.thermal.tz0._TC2: -1
hw.acpi.thermal.tz0._TC1: -1
hw.acpi.thermal.tz0._ACx: -1 -1 -1 -1 -1 -1 -1 -1 -1 -1
hw.acpi.thermal.tz0._CRT: 60.1C
hw.acpi.thermal.tz0._HOT: -1
hw.acpi.thermal.tz0._CR3: -1
hw.acpi.thermal.tz0._PSV: -1
hw.acpi.thermal.tz0.thermal_flags: 0
hw.acpi.thermal.tz0.passive_cooling: 0
hw.acpi.thermal.tz0.active: -1
hw.acpi.thermal.tz0.temperature: 40.1C
hw.acpi.thermal.user_override: 0
hw.acpi.thermal.polling_rate: 10
hw.acpi.thermal.min_runtime: 0

$ sysctl hw.acpi.battery; acpiconf -i 0
sysctl: unknown oid 'hw.acpi.battery'
acpiconf: get battery info (0) failed: Operation not permitted

$ netstat -ibn
Name    Mtu Network         Address               Ipkts Ierrs Idrop      Ibytes     Opkts Oerrs      Obytes  Coll
re0    1500 <Link#1>        d0:27:88:31:70:23  43398306     0     0 10224076134  30686081     0  9129830082     0
re0       - 66.45.228.0/24  66.45.228.251      28750307     -     -  8892668559  30685248     -  8700201624     -
lo0   16384 <Link#2>        lo0                       0     0     0           0         0     0           0     0
lo0       - ::1/128         ::1                       0     -     -           0         0     -           0     -
lo0       - fe80::%lo0/64   fe80::1%lo0               0     -     -           0         0     -           0     -
lo0       - 127.0.0.0/8     127.0.0.1                 0     -     -           0         0     -           0     -

$ iostat -x -d 1 2
                        extended device statistics
device       r/s     w/s     kr/s     kw/s  ms/r  ms/w  ms/o  ms/t qlen  %b
ada0           0       1      0.2     20.7     4     3     0     3    0   0
ada1           0       1      0.2     20.7     4     3     0     3    0   0
pass0          0       0      0.0      0.0     0     0     0     0    0   0
pass1          0       0      0.0      0.0     0     0     0     0    0   0
                        extended device statistics
device       r/s     w/s     kr/s     kw/s  ms/r  ms/w  ms/o  ms/t qlen  %b
ada0           0       0      0.0      0.0     0     0     0     0    0   0
ada1           0       0      0.0      0.0     0     0     0     0    0   0
pass0          0       0      0.0      0.0     0     0     0     0    0   0
pass1          0       0      0.0      0.0     0     0     0     0    0   0

$ geom disk list | head -40
Geom name: ada0
Providers:
1. Name: ada0
   Mediasize: 1000204886016 (932G)
   Sectorsize: 512
   Mode: r3w3e6
   descr: WDC WD1002FAEX-00Z3A0
   lunid: 50014ee25c8c1c6e
   ident: WD-WMATR1299194
   rotationrate: unknown
   fwsectors: 63
   fwheads: 16

Geom name: ada1
Providers:
1. Name: ada1
   Mediasize: 1000204886016 (932G)
   Sectorsize: 512
   Mode: r3w3e6
   descr: WDC WD1001FALS-00E8B0
   lunid: 50014ee05688b311
   ident: WD-WMATV1284908
   rotationrate: 7200
   fwsectors: 63
   fwheads: 16

$ mount -p; df -k
/dev/mirror/root        /                       ufs     rw              1 1
devfs                   /dev                    devfs   rw              0 0
procfs                  /proc                   procfs  rw              0 0
fdescfs                 /dev/fd                 fdescfs rw              0 0
Filesystem       1024-blocks      Used     Avail Capacity  Mounted on
/dev/mirror/root   942033884 377957580 488713596    44%    /
devfs                      1         0         1     0%    /dev
procfs                     8         0         8     0%    /proc
fdescfs                    1         0         1     0%    /dev/fd

$ ps -axo pid,ppid,uid,user,state,nlwp,nice,rss,vsz,%cpu,%mem,time,comm,args | head -15
  PID  PPID  UID USER   STAT NLWP NI   RSS   VSZ %CPU %MEM    TIME COMMAND      COMMAND
34178 34146 1004 detain S       1  0 11840 24172  0.0  0.3 0:00.16 sshd-session sshd-session: detain@pts/82 (sshd-session)
34179 34178 1004 detain Ss      1  0  4648 15868  0.0  0.1 0:00.15 bash         -bash (bash)
35806 34179 1004 detain R+      1  0  2844 14488  0.0  0.1 0:00.00 ps           ps -axo pid,ppid,uid,user,state,nlwp,nice,rss,vsz,%cpu,%mem,time,comm,args
35807 34179 1004 detain SC+     1  0  2424 14012  0.0  0.1 0:00.01 head         head -15

$ procstat -a | head -5; procstat -f $$ | head; procstat -r $$ | head
  PID  PPID  PGID   SID  TSID THR LOGIN    WCHAN     EMUL          COMM
34178 34146 34146 34146     0   1 detain   select    FreeBSD ELF64 sshd-session
34179 34178 34179 34179 34179   1 detain   wait      FreeBSD ELF64 bash
35870 34179 35870 34179 34179   1 detain   -         FreeBSD ELF64 procstat
35871 34179 35870 34179 34179   1 detain   piperd    FreeBSD ELF64 head
  PID COMM                FD T V FLAGS    REF  OFFSET PRO NAME
34179 bash              text v r r-------   -       - -   /usr/local/bin/bash
34179 bash              ctty v c rw------   -       - -   /dev/pts/82
34179 bash               cwd v d r-------   -       - -   /usr/home/detain
34179 bash              root v d r-------   -       - -   /
34179 bash                 0 v c rw------   8   23853 -   /dev/pts/82
34179 bash                 1 v c rw------   8   23853 -   /dev/pts/82
34179 bash                 2 v c rw------   8   23853 -   /dev/pts/82
34179 bash               255 v c rw------   8   23853 -   /dev/pts/82
  PID COMM             RESOURCE                          VALUE
34179 bash             user time                    00:00:00.039032
34179 bash             system time                  00:00:00.128642
34179 bash             maximum RSS                             4632 KB
34179 bash             integral shared memory                 15656 KB
34179 bash             integral unshared data                  1368 KB
34179 bash             integral unshared stack                 2432 KB
34179 bash             page reclaims                           4210
34179 bash             page faults                                0
34179 bash             swaps                                      0

$ sysctl kern.proc.all >/dev/null && echo kern.proc.all-ok
kern.proc.all-ok
```

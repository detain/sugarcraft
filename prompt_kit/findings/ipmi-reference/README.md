# IPMI reference captures (candy-top `ipmi` box)

Captured 2026-10-08 read-only with `ipmitool` (as root, via `/dev/ipmi0`) on two live hosts. Serials, part numbers,
asset tags, IPs and MACs are **sanitized** (RFC 5737 / RFC 7042 documentation ranges); everything else is verbatim.

| Dir | Host | BMC | Notes |
|---|---|---|---|
| `skynet2-asus-ami/` | ASUS ESC8000A-E12 (EPYC 9254, 4× RTX PRO 6000) | AMI, mfr 2623, fw 1.02, `Provides Device SDRs: yes` | LAN on channel **1** (8 also valid, unconfigured); fans in **RPM** (GPU_FAN1-5 ~16.5k, SYS_FAN1-6 ~20k); temps CPU/TR/DIMM/Inlet with upper thresholds; voltages with all six thresholds; PSU1-4 Power In/Out watts via `sdr type "Power Supply"`; dcmi 5 s window |
| `kvm521-hp-ilo4/` | HP ProLiant DL360 Gen9 (2× E5-2678) | HP iLO 4, mfr 11, fw 2.82, `Provides Device SDRs: no` | LAN on channel **2** (1 → "Invalid channel"); fans in **percent** duty (plus `DutyCycle`/`Presence` duplicates and a "Fans: Fully Redundant" aggregate); numbered temps `01-Inlet Ambient`…; discrete UID / Sys Health LED; one PSU absent (`ns`); dcmi 300 s window; SEL 100 % full |

## Timings (wall clock, one call)
| Command | skynet2 | kvm521 |
|---|---|---|
| `mc info` / `fru print 0` / `chassis status` / `sel info` | 0.04-0.09 s | 0.06-0.21 s |
| `dcmi power reading` | 0.04 s | 0.06 s |
| `lan print N` | 0.11 s | 0.36-1.1 s |
| `sdr` (all) / `sensor list` | ~1.7-4 s | ~3-3.5 s |
| `sdr type Fan` / `sdr type "Power Supply"` | 1.7 / 3.7 s | 3.4 / 3.0 s |
| `sel elist last 3` | 1.4 s | ~1 s |

Every SDR walk is seconds → must never run on the loop thread; static data (mc/fru/lan) once per session.

## Format notes
- `sensor list` = `name | value | unit | status | lnr | lcr | lnc | unc | ucr | unr` (`na` when absent; discrete sensors
  show hex value + `discrete` + `0xNNNN` status). Best single source: values **and** thresholds.
- `sdr elist` adds sensor id + entity id (`29.1` fan entity, `10.x` PSU, `7.1` system) for grouping.
- `dcmi power reading`: instantaneous/min/max/avg watts + sampling period; "activated" state line.
- Status words: `ok`, `ns` (no sensor/disabled), `nc`/`cr`/`nr` (non-critical/critical/non-recoverable).
- Non-root: `/dev/ipmi0` is `crw------- root` → ipmitool fails ("Could not open device") → box must say so, not crash.

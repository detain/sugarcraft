# btop open upstream PRs: candy-top adoption review

Base: aristocratos/btop @ d3389d7 (local `/home/sites/btop`). Plan: `plan_top.md` §1 (Linux-first v1; GPU only via
`nvidia-smi`; no NVML/ROCm dlopen; no BSD/macOS collectors), §3 (architecture), §4 (P-A..P-G). Landed code
checked: `candy-top/src/Collect/*`, `candy-top/src/Config/{Schema,ConfigReader,ConfigWriter,ConfigFile}.php`,
`candy-top/src/Theme/*`, `sugar-dash/src/Plot/Braille/DualSampleGraph.php`.
Every PR was fetched with `gh pr view --json …` and `gh pr diff` on 2026-10-08. All 32 are OPEN.

Verdict key: **ADOPT** = in v1, lands in a P-B..P-G phase or lane. **ADOPT-LATER** = worth having, but post-v1
(it needs the GPU box panel, a new box, or FFI). **N/A** = FreeBSD-only, not applicable, or our code already
covers it. **REJECT** = bad idea.

## Config-compatibility rule (applies to every new key below)

- candy-top's file is `$XDG_CONFIG_HOME/candy-top/config.conf`. It never writes btop's file
  (`ConfigFile.php` DIR_NAME). The supported direction is "a btop `btop.conf` copied over loads".
- `ConfigReader` drops unknown keys without a warning. btop 1.4.7 does the same. **New keys are safe in both
  directions.** If a candy-top file is copied back to btop, btop ignores our extra keys and drops them on its
  next save.
- **Extending an existing enum** is a soft break, not a hard one. Examples: `graph_symbol=block2`, or
  `proc_sorting="io read"`. btop rejects the value with a warning and keeps its default. Allowed, but say so in
  the option description.
- **Never change the grammar or meaning of an existing key** so that a btop-format value parses differently.
  The `presets` grammar is the one at risk (#1476). Any extension must be a strict superset, so every btop
  string still parses the same way.
- Every new key gets an `Option::*` row in `Schema.php` with a `Lang::t` description, so `ConfigWriter` emits
  the `#*` comment. It also gets an entry in the P-F options screen.

---

## #1888: Add support for multiple Intel GPUs (+182/-85)
**What it does:** The vendored `intel_gpu_top` C used to probe only the first Intel DRM card. Now it walks every
Intel-vendor card, and for each one it finds the i915/xe PMU device, discovers its engines, inits the PMU, and
appends it to `gpus[]`. The result is iGPU and discrete Arc reported side by side.
**Our state:** We have no Intel GPU backend. `intel_gpu_top` vendoring is a §1 non-goal. The PMU path needs
`perf_event_open`, which PHP cannot call.
**Verdict: ADOPT-LATER (GPU wave).** Take the multi-device idea, not the mechanism.
- *Data sources (PHP-feasible):*
  - Enumerate `/sys/class/drm/card*/device/vendor == 0x8086`, which gives every Intel card.
  - Name comes from `device` + `/usr/share/hwdata/pci.ids`.
  - Frequency: `/sys/class/drm/cardN/gt_cur_freq_mhz` (i915) or `…/device/tile0/gt0/freq0/cur_freq` (xe).
  - Power and temperature: `…/device/hwmon/hwmon*/{energy1_input,temp*_input}` where present.
  - Utilization: DRM fdinfo (`/proc/*/fdinfo/*` keys `drm-pdev`, `drm-engine-render`/`-video`/`-compute`,
    in ns) summed per `drm-pdev` and turned into a delta rate. This is the same source as #1552's fallback.
    It only sees processes we may ptrace (same uid, or root).
  - Optional: shell out to `intel_gpu_top -J -s <ms> -o -`, mirroring the `nvidia-smi` law. It needs
    CAP_PERFMON or root.
- *Lands in:* `Collect/GpuIntel.php`, merged into the `GpuSnapshot` device list. `GpuDevice` already carries an
  index, and the list must be multi-device from day one.
- *Size:* M (~300 LOC + fixtures).
- *Risks:* fdinfo scan cost (see #1552), permissions, and the i915-vs-xe split.
- *Config:* none new. The existing `shown_gpus` list already includes `intel`.

## #1881: GPU boxes side by side + adaptive detail [AI] (+210/-70)
**What it does:** GPU boxes are laid out on a grid instead of a full-width vertical stack. Columns follow
terminal width; each box has its own width and stats-panel width. The level of detail adapts to box width. New
key `gpu_box_columns = "Auto"` (or 1-6).
**Our state:** The v1 layout has no GPU boxes (6 panels, plan §0). `Schema` already carries `shown_gpus` and
`gpu_mirror_graph` but nothing renders them.
**Verdict: ADOPT-LATER (GPU box panel, "P-H").** When the GPU box lands, build the grid placement from the
start. Do not port the stacked layout first.
- *Behaviour:*
  - `cols = gpu_box_columns === "Auto" ? max(1, floor(termW / minGpuBoxW)) : clamp(n, 1, 6)`.
  - `rows = ceil(nShown / cols)`. The last row's boxes stretch across the remaining width.
  - Detail tiers follow box width: graph only → graph + stats → full PCIe/enc/dec/clock lines.
- *Lands in:* `View/FrameBuilder` (calcSizes) + `View/GpuView`.
- *Size:* M.
- *Config:* new string key `gpu_box_columns`, default `"Auto"`. Additive and btop-safe.

## #1873: Container monitoring (+624/-49)
**What it does:**
- Detects containers per process from `/proc/<pid>/cgroup`, matched against path patterns:
  - `lxc.payload.<name>`, and `lxc/<name>` (Proxmox);
  - `machine-<name>.scope` (nspawn), excluding `machine-qemu*`;
  - `[<engine>-]<64-hex>[.scope]` (docker / libpod=podman / containerd / cri-o; `kubepods` means k8s).
- Per-container stats come from cgroup v2: `cpu.stat` usage_usec delta, `memory.current` minus reclaimable
  file cache (matches `docker stats`), and `memory.max`. On cgroup v1 it falls back to the sum of the
  container's processes.
- Docker names come from one `GET /containers/json` on the docker unix socket (honours `DOCKER_HOST=unix://`),
  with a 1 s timeout, only when a new id appears.
- New `ctr` box above proc (one third of that column; detail panel when ≥80 cols). Keys: `x` toggles the box,
  `[`/`]` select a container, a row click selects, `O` toggles "Omit ctr". Selecting a container filters the
  proc box to that container's processes.
- New key `proc_filter_containers` (default false). `ctr` is added to the `shown_boxes` vocabulary.

**Our state:** None of this exists. Everything is PHP-feasible: plain file reads, plus `stream_socket_client`
for the socket.
**Verdict: split.**
- **ADOPT (P-E):**
  - cgroup detection in `ProcList`. Read `/proc/<pid>/cgroup` once per process lifetime and cache it with
    cmd/user (same shape as `readStatic`). Add `Process::$container` (`?ContainerRef{engine, id, name,
    cgroupPath}`).
  - Option `proc_filter_containers` (bool, default false), the `O` key, and the "Omit ctr" title button in the
    proc box.
  - Size S-M.
- **ADOPT-LATER:**
  - The `ctr` box, the `x`/`[`/`]` keys, `Collect/Containers.php` (cgroup v2 `cpu.stat`/`memory.*`), and docker
    name resolution. This is a 7th box with layout changes. Size L (~500 LOC).
- *Risks:*
  - The docker socket needs group `docker` or root. Fall back to the short id.
  - Container names printed to the terminal must be sanitized (the PR restricts them to `[A-Za-z0-9_.-]`).
    This matches our TUI sanitize invariant.
- *Config:* `proc_filter_containers` is additive. `ctr` in `shown_boxes` is an enum extension; btop drops the
  unknown token with a warning.

## #1869: NVML: try next library candidate when nvmlInit fails [AI] (+30/-17)
**What it does:** On WSL2 with distro `nvidia-utils`, `libnvidia-ml.so` (native) loads but `nvmlInit` fails, so
btop never tries the working `libnvidia-ml.so.1` (the WSL lib). The fix runs the load and init loop over every
candidate, `dlclose`-ing the ones that fail.
**Our state:** No NVML (non-goal). **We share the same bug class through `nvidia-smi`, though.** `Gpu::which()`
resolves only the first `nvidia-smi` on `$PATH`, and before the first success a non-zero exit marks the GPU
absent for the collector's lifetime. On WSL2, distro `nvidia-utils` puts a native `/usr/bin/nvidia-smi`
("couldn't communicate with the NVIDIA driver") ahead of `/usr/lib/wsl/lib/nvidia-smi`.
**Verdict: ADOPT (the analog), Collect, now.**
- `Gpu` builds a candidate list: every `nvidia-smi` on `$PATH` (in order), plus `/usr/lib/wsl/lib/nvidia-smi`.
- Before first success, a non-zero exit or unparseable output on candidate *k* moves on to *k+1*. Only after
  every candidate fails is the GPU memoized absent.
- The winning binary is pinned for the collector's lifetime.
- Size S (~40 LOC + a runner-injected test).
- No config change.

## #1859: optional executable basename display [AI] (+65/-7)
**What it does:** New `proc_command_basename` (default false). The process list and tree show
`basename(argv[0]) + " " + args`; the detailed view, sorting and filtering keep the full command.
- The basename offset is recorded from the **real argv[0], before the NUL→space flattening**. That makes paths
  with spaces or Unicode safe, and slashes in later args are never mistaken for part of the path.
- Offset rules: `"/usr/bin/" → 0`, `"./firefox" → 2`, `"../bin/firefox" → 7`.
- The tree view keeps the shortened command even in narrow layouts.
**Our state:** `ProcList::readStatic` flattens with `str_replace("\0", ' ', $cmdline)` and keeps no argv[0]
boundary, so the offset cannot be recovered afterwards.
**Verdict: ADOPT (Collect now + P-E render).**
- `readStatic` computes `cmdBasenameOffset` from the first NUL-delimited segment:
  `strrpos($argv0, '/') + 1`, or 0 if the segment ends in `/` or has none. `Process` gets a new
  `int $cmdBasenameOffset`.
- `ProcView` applies it when the option is on.
- Size S.
- *Config:* new bool `proc_command_basename = false` (additive).

## #1858: skip CPU redraw when the panel is hidden [AI] (+1/-1)
**What it does:** Selecting a process with the CPU box hidden ran `Runner::run("cpu")` with stale or zero
dimensions, and `Draw::Graph` aborted on a negative `iota` bound.
**Our state:** No panels yet. **The hazard exists for us too:** `DualSampleGraph::new()` *throws*
`InvalidArgumentException` on width/height < 1 (by design). A panel that builds graphs from a zero-size layout
slot would crash the TEA loop.
**Verdict: N/A as a patch, but adopt the invariant (P-A/P-B).**
- `App::view()` / per-panel `update()` never build or render a panel whose box is not in `shown_boxes`.
- Any graph construction clamps its computed width and height with `max(1, …)`, or skips the graph when the
  slot is < 1.
- Add tests:
  - toggle every box off one at a time, then drive selection, resize and tick messages;
  - resize to 1×1.
- No config change.

## #1856: Linux proc stat parsing after name changes [AI] (+141/-13)
**What it does:** btop caches a per-process `name_offset` (the space count in comm). After a `prctl(PR_SET_NAME)`
rename the field indexes shift, so `starttime` is read as RSS ("11 MiB → 36G / 20 threads"). The fix splits each
stat record at the **last** `)` on every scan and drops the cached offset.
**Our state: already correct.**
- `ProcList::parseStat()` uses `strrpos($raw, ')')` on the whole record every scan, and parses fields from
  `$close + 2`.
- `name` comes from the current stat read, not from a cache (only cmd/user are cached, keyed on start time).
**Verdict: N/A (already covered).**
- Add the PR's cases as regression fixtures in `candy-top/tests/fixtures/linux/`: a comm containing `") S 1 "`,
  a comm with a newline, and a rename between two scans. They pin the behaviour.
- Size XS.

## #1854: AMD GPU model names via ROCm SMI market name + amdgpu.ids (+118/-6)
**What it does:**
- Strix Halo and Strix Point share PCI device ids across tiers. pci.ids gives a concatenated
  `"[Radeon Graphics / 8050S / 8060S]"` string, and truncation then shows the wrong model.
- The fix calls `rsmi_dev_market_name_get` first. Failing that, it resolves `(device_id, revision_id)` against
  `amdgpu.ids`, searching `/usr/share/libdrm/amdgpu.ids`, `/usr/local/share/libdrm/amdgpu.ids`, and the
  `/opt/rocm*/…/libdrm/amdgpu.ids` paths.
- The sysfs fallback stops printing `AMD GPU (1002:1586)`.
**Our state:** No AMD backend. btop's `Asysfs` backend is pure sysfs, though:
`/sys/class/drm/card*/device/{gpu_busy_percent,mem_info_vram_used,mem_info_vram_total,…}` plus hwmon temp and
power. PHP can do all of that without ROCm.
**Verdict: ADOPT-LATER (GPU wave).**
- Add `Collect/GpuAmdSysfs.php`, a port of `Asysfs`.
- Names resolve in this order:
  1. `amdgpu.ids` lookup by `device` + `revision` (from `/sys/…/device/{device,revision}`);
  2. pci.ids;
  3. `"AMD GPU (1002:xxxx)"`.
- Size M (sysfs backend ~200 LOC; ids lookup ~40).
- No ROCm and no FFI. No config change; `shown_gpus` already includes `amd`.

## #1851: FreeBSD memory stats accuracy (+19/-30)
FreeBSD-only: u_int overflow, `v_page_count`, laundry pages. **N/A.** It has no Linux analog, and PHP ints are
64-bit.

## #1849: rebuild battery meter on redraw so theme reload recolours it (+1/-0)
**What it does:** The function-static battery `Draw::Meter` cached its rendered strings per value, so a theme
change left it in the old colours until the charge hit an uncached value.
**Our state:** The theme engine has landed; the panels have not. Any memoized render cache (meter strings,
gradient ramps, the per-panel `data_same` memo, border embeds) can repeat this.
**Verdict: ADOPT as an invariant (P-G, and P-F `ctrl_r` hot-reload).**
- A theme change or config reload dispatches the same full-redraw path as `WindowSizeMsg`, which clears every
  memo, including the battery meter in the CPU border.
- Test: render, switch theme, render again, and assert no SGR from the old palette remains.
- Size XS. No config change.

## #1839: AMD Ryzen AI NPU utilization/power via amdxdna [AI] (+268/-6)
**What it does:**
- Enumerates `/sys/class/accel/accel*` with PCI vendor 0x1022 and driver `amdxdna`.
- Reads per-AIE-column utilization and power via `DRM_IOCTL_AMDXDNA_GET_INFO` on `/dev/accel/accelN`
  (world-readable). XDNA1 parts are skipped by probing.
- Shows the device as an "NPU" row through the GPU drawing path, using a `box_label`.
**Our state:** None. Plain PHP cannot issue the ioctl. It could through FFI (candy-pty already gates on FFI), or
possibly through DRM fdinfo engine keys if amdxdna exposes them.
**Verdict: ADOPT-LATER (GPU wave, FFI-gated).**
- Add a generic `Accelerator` device kind (`label` GPU/NPU) to the GPU model. It is shared with #985.
- Detection is pure sysfs. The utilization read is FFI ioctl behind `extension_loaded('ffi')`. Without it the
  device is listed as present with "n/a" stats, following the sentinel law.
- Size M.
- *Risk:* the ioctl ABI is vendored structs, and the kernel uapi could drift. No config change.

## #1830: FreeBSD battery rate/time sysctls as int (+12/-4)
FreeBSD-only. **N/A.**

## #1823: per-process IO stats columns + sorting (+167/-11)
**What it does:**
- Linux reads `/proc/[pid]/io` `read_bytes` / `write_bytes`. The rate is `Δbytes / Δuptime`, and the first
  sample is 0.
- New columns `IO/R` and `IO/W` (humanized B/s) appear when the proc box is ≥ 90 cols wide (`io_size = 14`).
- New sort keys `"io read"`, `"io write"`, `"io total"`.
- The detailed view shows totals. BSDs use block-op counts (`Op/R`, `Op/W`).
**Our state:** `ProcList` reads only stat, status and cmdline.
**Verdict: ADOPT (Collect + P-E).**
- *Collect:* `ProcList` reads `/proc/<pid>/io` and parses `read_bytes:` and `write_bytes:`.
  - Optionally subtract `cancelled_write_bytes` from writes (document the choice).
  - Keep per-pid previous counters and timestamp from the injected clock, then compute rates.
  - Add `Process::$ioRead`, `$ioWrite` (B/s) and `$ioReadTotal`, `$ioWriteTotal`.
  - **Unreadable** `io` (EACCES: other uid without CAP_SYS_PTRACE; `/proc/pid/io` needs PTRACE_MODE_READ)
    gives `Sentinel::UNMEASURED` and renders "-". It must never render as 0, which is the PR's weakness.
- *Cost:* one more open per pid per tick. Read `io` only when the IO columns are visible, the sort is an io
  key, or the detailed view is open for that pid. Gate this with a collector flag set from the view state.
- *P-E:*
  - Columns at width ≥ 90.
  - Extend `Schema::PROC_SORTING` with `'io read', 'io write', 'io total'`, appended after btop's 8 so
    left/right sort cycling keeps btop's order first.
  - The tree view sorts by these too.
- Size M (~200 LOC + fixtures with a fake `io` file and an EACCES case).
- *Config:* `proc_sorting` enum extension only. It is a soft break; btop resets an unknown value to `cpu lazy`.

## #1792: unify CPU frequency formatting across platforms (+35/-43)
**What it does:** Moves Linux `normalize_frequency` (MHz → `"x.y GHz"` truncated to 3 chars, trailing dot
dropped, plus a THz branch) into a shared `Tools::format_frequency_mhz`. BSD and mac now show units. Linux also
stops keeping a stale `cpuHz` when a read fails.
**Our state:** `Freq` already implements the Linux formatter, and a failed read gives `""` / UNMEASURED with no
stale carry-over. It matches the post-PR behaviour.
**Verdict: N/A (covered).** When #1785 lands, extract the label logic into a public static
`Freq::label(float $mhz): string` so the per-core grid reuses it. That is part of #1785's size.

## #1791: options/proc: group controls + preserve tree state [AI] (+326/-66)
**What it does:**
- (a) Tree sort uses **branch totals** (threads, mem, cpu_p, cpu_c summed over non-collapsed children, skipping
  state `X`) when `proc_aggregate` is off. Collapsing or expanding a branch then no longer moves it in the sort.
- (b) The options menu gets non-selectable **section headings**; arrows skip them.
- (c) Opt-in `proc_tree_persist_state` persists manual collapse choices keyed by **process-name ancestry**
  (`systemd→chromium→chromium`), not by PID. They are stored in an internal config key `proc_tree_state`.
**Verdict: split.**
- (a) **ADOPT (P-E):** a tree-sort comparator over branch totals. Size S, with a test that collapse/expand
  keeps row order.
- (b) **ADOPT (P-F):** headings are a non-focusable row type in the L8 OptionRow list. Size S.
- (c) **ADOPT-LATER, and REJECT its storage.**
  - Keep the opt-in bool `proc_tree_persist_state` (default false, additive).
  - Do **not** put an internal state blob into config.conf. It pollutes a btop-compatible file, and every
    save rewrites it.
  - Store it in `$XDG_STATE_HOME/candy-top/tree-state.json`, written atomically on exit and on change, keyed
    by the name-ancestry path. Size S-M.

## #1787: FreeBSD battery rate/time in mW + minutes (+10/-4)
FreeBSD-only. **N/A.** The general lesson (a flapping battery returns garbage mid-plug) is already covered by
the `Battery` sentinel and heuristic.

## #1785: CPU frequency per core (+191/-25)
**What it does:**
- New `show_core_freq = "off" | "value" | "graph"`, default `"off"`.
- The per-core grid gains a 6-col frequency value, or a 5-col frequency history graph plus value.
  - The graph uses a min/max-scaled gradient: max is `fmax - fmin`, offset is `-fmin`.
  - The core usage graph width shrinks by that footprint.
- Frequency is now read per core from `/sys/devices/system/cpu/cpuN/cpufreq/scaling_{cur,min,max}_freq`
  instead of `cpufreq/policyY`. This follows the kernel ABI, and on ARM `cpuN/cpufreq` symlinks to the shared
  policy, which fixes the #1288-class crash.
**Our state:** `Freq` reads `cpufreq/policy*` only and collapses them to one value.
**Verdict: ADOPT (Collect now, P-B render).**
- *Collect:*
  - `Freq` additionally returns `perCore: array<int, float>` (MHz, UNMEASURED per missing core) plus per-core
    min/max, read from `cpuN/cpufreq/`.
  - Only read per-core when `show_core_freq !== 'off'`, to bound file reads on 256-core hosts.
  - The aggregate keeps btop-d3389d7 policy semantics for the `freq_mode` collapse, so labels stay identical
    with the option off. Falls back to `/proc/cpuinfo` "cpu MHz" per processor.
- *P-B:*
  - Add the per-core freq column or graph to the core grid. Graphs come from `DualSampleGraph` with
    `maxValue = fmax - fmin`, `offset = -fmin`, and a 1024-cap history per core.
  - Use the `Freq::label()` extraction from #1792.
  - Width math must never produce a < 1 graph width (see #1858).
- Size M (~250 LOC).
- *Config:* new string enum key `show_core_freq`, default `"off"` (additive). Its values are
  `Schema::CORE_FREQ = ['off', 'value', 'graph']`.

## #1783: `block2` graph symbols (+67/-34)
**What it does:**
- New graph symbol family `block2`, built from 2×3 **sextant** glyphs (Unicode 13 "Symbols for Legacy
  Computing", U+1FB00 block).
- Up/down 5×5 tables with only 4 used levels per half-column: `clamp_max = 3`.
- Rounding `mod` is 0.6 when height == 1 and 0.2 otherwise (block, braille and tty keep 0.3 / 0.1).
- The value is added to `graph_symbol` and to every `graph_symbol_<box>`.
- A height-1 row fix-up trims the first glyph.
**Our state:** `sugar-dash` `DualSampleGraph` ports braille, block and tty verbatim (`SYMBOLS`,
`FAMILY_*` constants, index `prev*5 + cur`, underlay index 6). `Schema::GRAPH_SYMBOLS` and `GRAPH_SYMBOLS_DEF`
lack `block2`.
**Verdict: ADOPT (lib lane L3 extension, then the Schema enum).**
- `DualSampleGraph`:
  - Add `FAMILY_BLOCK2` and `block2_up` / `block2_down` tables copied verbatim from the PR.
  - Make the quantizer's `clampMax` and `mod` family-dependent: 3 for block2, 4 for the others; mod as above.
  - Underlay glyph is `block2_up[6]` = `🬭`.
  - Golden tests per height 1/2/n, both directions.
  - Investigate the PR's height-1 first-glyph trim before porting it. It looks like a btop-specific
    cursor-move artefact; verify with a cell-grid test instead of copying it blind.
- `Schema`: add `'block2'` to both enums.
- Size S-M.
- *Risks:*
  - Sextant font coverage. Iosevka, Cascadia, JetBrains Mono 2.3+, and Kitty/WezTerm/foot built-in box
    drawing render it; others show tofu. Keep `braille` the default.
  - `tty_mode` and `force_tty` must keep mapping to `tty`.
- *Config:* enum extension; btop 1.4.7 rejects `block2` with a warning and falls back.

## #1747: focused mem graphs option (+145/-50)
**What it does:** New `mem_selected = "default" | "used" | "available" | "cached" | "free" | "swap_used"`
(default `"default"`). Any value other than `"default"` makes the mem box draw **one** full-height graph of that
metric (`mem_width-2 × height-4`) with a Total/Swap header, instead of the stacked per-class meters and graphs.
It is set via config and the options menu only; there is no key.
**Verdict: ADOPT (P-B MEM panel).**
- Implement as an alternate `MemView` layout branch.
- Also bind it to a click on a mem-class label, which cycles focus. That is an extension; keep the btop
  behaviour of setting it via config/menu.
- Size S-M.
- *Config:* new string enum key `mem_selected`, default `"default"`. Additive.

## #1739: show zswap usage (+64/-6)
**What it does:**
- Reads `/proc/meminfo` `Zswap:` (compressed bytes in RAM) and `Zswapped:` (original bytes). These appeared in
  Linux ≥ 5.19.
- Adds a "Zswap" row to the swap section. When shown, swap "Used" becomes `swap_used - Zswapped`, i.e. on-disk
  only. It reuses the `cached` gradient.
- New `show_zswap` (Linux, default **true**).
- The PR has a reported crash (`unordered_map::at` in Mem). The PR author suspects a theme or key lookup issue.
**Our state:** `Memory` parses meminfo but ignores `Zswap`/`Zswapped`. `MemorySnapshot` has no fields for them.
**Verdict: ADOPT (Collect now, P-B render).**
- *Collect:* add `MemorySnapshot::$zswap` and `$zswapped` (bytes; UNMEASURED when the keys are absent, i.e.
  pre-5.19 or zswap disabled).
- *View:*
  - Draw the row only when the option is true **and** the value is measured.
  - Recompute "Used" only in that case.
  - Use a percentage of SwapTotal for the meter.
  - Look up the gradient with a **fallback**: `zswap` → `cached` → `used`. That avoids the PR's at() crash
    class, and no theme file needs changing.
- Size S.
- *Config:* new bool `show_zswap`, default true (matching the PR). Additive.

## #1730: allow GPU boxes to target any GPU [AI] (+567/-60)
**What it does:**
- GPU boxes become stable **panel slots** (number keys 5-0 toggle slots), each with an independently
  switchable GPU target.
- `shown_boxes` accepts `gpuN` for any index N, with at most 6 boxes shown at once.
- Prev/next mouse controls sit in the GPU box title.
- Title numbers stay stable when a middle box is removed, and boxes are ordered by slot.
**Verdict: ADOPT-LATER (GPU box panel "P-H", together with #1881).**
- Model each GPU box as `{slot, targetIndex}`. Add title `‹ ›` zones (candy-mouse) for cycling.
- `shown_boxes` parse: accept `gpu\d+`, cap 6 shown.
- Size M-L.
- *Config:* the `shown_boxes` vocabulary widens from gpu0..gpu5 to gpuN. It is a superset, so btop strings
  still parse. btop drops `gpu7`.

## #1728: FreeBSD RAM/ARC detection + overflow (+41/-14)
FreeBSD-only. **N/A.** Our Linux `Memory` already handles ZFS ARC via `zfs_arc_cached`.

## #1700: `disks_order` config (+60/-0)
**What it does:**
- New `disks_order` holds a whitespace-separated list of mountpoints, with the token `swap` standing for the
  swap pseudo-disk.
- Listed disks come first in the given order; the rest keep the default order (root first, then swap, then
  mount-table order).
- Empty means unchanged. One shared helper, `Mem::apply_disks_order`.
**Verdict: ADOPT (P-D).**
- A pure function over the `MountsSnapshot` order, placed with the `disks_filter` application in the disk view
  model.
- Unknown mountpoints in the list are ignored.
- Size XS-S.
- *Config:* new string `disks_order = ""`. Additive.

## #1683: mellow theme [AI] (+82/-0)
**What it does:** Adds `themes/mellow.theme`, built from the mellow.nvim palette (kvrohit). Its comments
duplicate the hex values; the reviewer flagged them as AI slop.
**Verdict: ADOPT (P-G, data).**
- Ship it as the 42nd bundled theme, with the comments trimmed to btop's house style. Credit the palette
  origin in the file header (mellow.nvim is MIT).
- **Must update the pinned counts:** `tests/Theme/ShippedThemesTest.php` (`testExactly41ThemesShipped`) and
  `ThemeRegistryTest.php:151` (`2 + 41 + 1`). Also update plan §1's "41".
- Size XS. No config change (`color_theme = "mellow"`).

## #1614: CPU panel GPU sub-graph widths (+11/-10)
**What it does:** Fixes `init_graphs()` arithmetic for per-GPU sub-graphs in the CPU panel when there are many
GPUs or the terminal is narrow. Adds a non-positive-width backstop in `Draw::Graph`.
**Our state:** **Already fixed upstream at d3389d7** by merged #1603 (`btop_draw.cpp:657-673`:
`max(1, (W - (n-1)) / n)`, with the last graph taking the remainder). The comment thread confirms it.
**Verdict: N/A, but port the d3389d7 math when the CPU panel's `show_gpu_info` sub-graphs land (P-B).**
- Our `DualSampleGraph` throws on width < 1, so the clamp is mandatory.
- Test: 8 GPUs at the minimum CPU box width (60 cols) builds without an exception, and widths sum to
  `W - (n-1)`.

## #1573: show/hide IP address option (+9/-1)
**What it does:** New `net_hide_ip` (default false). When true, the net box omits the IP address line. This is
useful for public IPs, screenshots and streams.
**Our state:** **Gap:** `NetInterface` carries no IP address at all. btop shows the iface IPv4 (falling back to
IPv6) in the net box (via `getifaddrs`).
**Verdict: ADOPT (Collect prereq + P-C).**
- *Collect:* `Net` fills `NetInterface::$ipv4` and `$ipv6` from PHP's `net_get_interfaces()` (standard,
  PHP ≥ 7.3). No shell-out. Injectable for tests.
- *P-C:* render per btop. When `net_hide_ip` is true, do not render the address.
- Size S.
- *Config:* new bool `net_hide_ip = false`. Additive.

## #1552: NVIDIA GPU per-process util + mem (+529/-63)
**What it does:**
- Per-process GPU utilization (`nvmlDeviceGetProcessUtilization`) and memory (Graphics and Compute
  RunningProcesses v1-v3).
- `/proc/[pid]/fdinfo` DRM fallback (`drm-engine-*`, `drm-memory-*`) for Intel and AMD.
- Columns `GMem` and `Gpu%`, optionally with a per-process GPU mini-graph.
- Sort keys `"gpu"` and `"gpu memory"`.
- `proc_gpu_only` filter (key `g`) and `proc_gpu_graphs` (default true).
- A reviewer measured that the fdinfo fallback scanned ~480 of 489 processes every tick on NVIDIA, where it can
  never succeed: about 10 ms per scan. The fallback needs a guard.
**Verdict: ADOPT-LATER (GPU wave, after P-E).**
- *NVIDIA source without NVML:*
  - Memory: `nvidia-smi --query-compute-apps=pid,used_memory --format=csv,noheader,nounits`.
  - Utilization: `nvidia-smi pmon -c 1 -s um` (or `--query-accounted-apps`).
  - Both run through the existing bounded-spawn and backoff law in `Gpu`, at the GPU cadence (≥ 5 s), never
    per frame.
- *DRM fdinfo* (Intel, AMD, others):
  - Scan only pids whose `/proc/<pid>/fd/*` contains a `/dev/dri/renderD*` or `card*` link.
  - Cache the fd numbers per pid lifetime and re-validate on a slow cadence.
  - Skip the scan when the only GPUs are NVIDIA proprietary. That driver exposes no `drm-*` keys; this is the
    reviewer's guard.
- *P-E:*
  - Columns `GMem` and `Gpu%`.
  - Sort keys `'gpu'` and `'gpu memory'` appended to `PROC_SORTING`.
  - Key `g` (check that it does not clash with btop's keys; btop's `g` is free at d3389d7 except under
    vim_keys, where `g`/`G` = top/bottom). With vim_keys on, bind it to a different key or require a modifier.
  - `proc_gpu_only` bool, default false.
  - `proc_gpu_graphs` bool, default true.
- Size L.
- *Risk:* fdinfo needs same-uid or root.
- *Config:* two additive bools, plus a sorting enum extension.

## #1546: cwd in process detail view (+54/-8)
**What it does:** The detailed view shows the working directory above the command (Linux:
`readlink /proc/[pid]/cwd`). The command area shrinks from 3 lines to 2.
**Verdict: ADOPT (P-E detailed view).**
- `readlink("/proc/$pid/cwd")`. PHP readlink works on these magic links.
- Resolve lazily, only for the detailed pid on each refresh. Never in the list scan.
- `false` (EACCES: other uid without CAP_SYS_PTRACE, or a zombie) renders `Lang::t('proc.cwd_unavailable')`.
- Keep a `" (deleted)"` suffix as-is.
- Sanitize control characters before rendering (the TUI sanitize invariant).
- Size S. No config change.

## #1476: adjustable-width proc box (+95/-12)
**What it does:**
- New `proc_box_width_percent` (default `Proc::width_p` = 55, clamped 0-100 at use and stored as-is).
- Keys: Shift+Left/Right adjust ±1%, Alt+Shift+Left/Right adjust ±10%. Direction depends on `proc_left`.
- Menu entry.
- **Preset grammar extension**: `proc:P:G:W`, where W is a width % (or `default`). 3-field presets still work.
**Verdict: ADOPT (P-F; layout math in FrameBuilder).**
- `FrameBuilder` reads the percent and clamps it to the computed min and max widths for mem/net and proc
  (btop minimums: proc 44, mem/net 36) without mutating the stored value.
- Keys go through candy-input kitty/CSI modifiers. Verify that Shift and Alt+Shift arrows decode on the
  supported terminals; legacy xterm sends `\e[1;2D` and `\e[1;4D`.
- Size S-M.
- *Config:*
  - New int key `proc_box_width_percent = 55`. Additive.
  - The preset 4th field is a **strict superset**: btop-format 3-field presets parse identically.
  - A 4-field preset is rejected by btop 1.4.7 as an invalid presets string, which resets presets to the
    default.
  - **Write the 4th field only if the user wrote it.** Document this in the option description.

## #1411: options category keys match box toggle keys (+64/-64)
**What it does:**
- Today, options tabs are selected with 1-6 (general, cpu, gpu, mem, net, proc), while box toggles are 1=cpu,
  2=mem, 3=net, 4=proc, 5+=gpu.
- The PR remaps the tabs to 0=general, 1=cpu, 2=mem, 3=net, 4=proc, 5=gpu, so a digit means the same box
  everywhere.
**Verdict: ADOPT (P-F).**
- Consistent with btop's own box keys. It costs nothing because we have no option-key muscle memory to break.
- Tab order follows the keys (general, cpu, mem, net, proc, gpu). The gpu tab exists only when a GPU is
  detected.
- Update the help overlay text.
- Size XS. No config change.

## #1008: improved CPU idle-ticks calculation (+70/-20)
**What it does:**
- Removes the `max(1, Δidle)` floor that capped total CPU at 99% on short intervals.
- When Δtotal == 0 it simulates one idle tick, which gives 0% instead of the `max(1, Δtotal)` bogus 100%.
**Our state:** **Already covered.**
- Upstream d3389d7 already has `max(0, Δidle)` on Linux (`btop_collect.cpp:1177`).
- Our `Cpu::sample()` uses `max(0, $idles - prev)` and returns `Sentinel::UNMEASURED` for Δtotal ≤ 0. That is
  arguably better than either btop variant; it is documented in the `Cpu` docblock.
**Verdict: N/A.**
- Follow-up for P-B: the CPU view must render UNMEASURED by **holding the previous ring value** (or skipping
  the push) so the graph shows no 0%/100% dip or spike. Add that as a P-B test.

## #985: Intel NPU utilization (+176/-17)
**What it does:**
- A proof of concept. It reads `/sys/bus/pci/drivers/intel_vpu/<bdf>/npu_busy_time_us` (cumulative µs) and
  optionally `npu_memory_utilization`.
- It appends the NPU into `gpus[]` with `is_npu_device`, so labels read NPU and RAM instead of GPU and VRAM.
- It was superseded by fork PR #1789, which also reads `npu_current_frequency_mhz`.
**Verdict: ADOPT-LATER (GPU wave, with #1839's Accelerator kind).**
- Pure sysfs, so it is PHP-easy:
  - utilization % = `Δnpu_busy_time_us / Δwall_us × 100`;
  - memory = `npu_memory_utilization` (bytes);
  - frequency = `npu_current_frequency_mhz`.
- Exclude NPUs from GPU averages and totals.
- Size S-M (it shares the Accelerator model). No config change, beyond an optional future `shown_gpus` token
  `npu`, which would be additive.

## #1888 / #1881 / #1730 / #1552 / #1854 / #985 / #1839: common prerequisite
All of these need the **GPU box panel**, which is not in v1 (plan §0 lists 6 panels). `Gpu` is NVIDIA-only.
Propose a post-v1 **P-H GPU**:
1. Multi-vendor `GpuSnapshot` (an Accelerator kind with GPU or NPU and a vendor tag).
2. AMD sysfs backend (#1854).
3. Intel sysfs + fdinfo backend (#1888).
4. NPU backends (#985 sysfs; #1839 FFI).
5. GPU box with slots and targets (#1730) on a grid layout (#1881).
6. Per-process GPU (#1552).

---

## Summary table

| PR | Verdict | What we want | Lands in |
|---|---|---|---|
| #1888 | ADOPT-LATER | Every Intel card enumerated; util via DRM fdinfo/sysfs (no PMU) | Collect `GpuIntel` / P-H |
| #1881 | ADOPT-LATER | GPU boxes on a grid, adaptive detail; `gpu_box_columns="Auto"` | FrameBuilder + GpuView / P-H |
| #1873 | ADOPT (filter) + LATER (box) | cgroup container tag per proc + `proc_filter_containers` + `O`; ctr box later | ProcList + P-E; ctr box post-v1 |
| #1869 | ADOPT (analog) | Try every `nvidia-smi` candidate (PATH + `/usr/lib/wsl/lib`) before memoizing absent | Collect `Gpu` (now) |
| #1859 | ADOPT | argv0 basename offset recorded pre-flatten; `proc_command_basename=false` | ProcList + P-E |
| #1858 | N/A (invariant) | Hidden panels never build or render; graph widths clamped (DualSampleGraph throws < 1) | P-A/P-B tests |
| #1856 | N/A (covered) | `parseStat` already splits at the last `)` per scan; add rename regression fixtures | Collect tests |
| #1854 | ADOPT-LATER | AMD sysfs backend + amdgpu.ids (device+revision) naming | Collect `GpuAmdSysfs` / P-H |
| #1851 | N/A | FreeBSD only | none |
| #1849 | ADOPT (invariant) | Theme/config reload clears every render memo (battery meter included) | P-F/P-G |
| #1839 | ADOPT-LATER | AMD NPU via `/sys/class/accel` + FFI ioctl; Accelerator kind "NPU" | Collect / P-H |
| #1830 | N/A | FreeBSD only | none |
| #1823 | ADOPT | `/proc/pid/io` rates, IO/R IO/W cols ≥ 90 cols, sorts io read/write/total; EACCES shown as "-" | ProcList + P-E |
| #1792 | N/A (covered) | Already the unified formatter; extract `Freq::label()` for #1785 | Collect |
| #1791 | ADOPT (a,b) / LATER (c) | Branch-total tree sort; options section headings; tree-state persistence in an XDG state file, not config | P-E, P-F, post-v1 |
| #1787 | N/A | FreeBSD only | none |
| #1785 | ADOPT | Per-core freq from `cpuN/cpufreq`; `show_core_freq=off\|value\|graph` | Freq + P-B CPU grid |
| #1783 | ADOPT | `block2` sextant family (clampMax 3, mod .6/.2) + enum value | sugar-dash L3 + Schema |
| #1747 | ADOPT | `mem_selected` single focused mem graph | P-B MemView |
| #1739 | ADOPT | Zswap/Zswapped from meminfo; `show_zswap=true`; on-disk "Used"; gradient fallback | Memory + P-B |
| #1730 | ADOPT-LATER | GPU box slots with switchable target; `gpuN` any N, max 6 | P-H |
| #1728 | N/A | FreeBSD only | none |
| #1700 | ADOPT | `disks_order=""` mountpoint/`swap` ordering | P-D |
| #1683 | ADOPT | Ship mellow as the 42nd theme (trim comments; bump pinned 41 counts) | themes/ P-G |
| #1614 | N/A (fixed at d3389d7) | Port the d3389d7 `max(1, …)` sub-graph width math + test | P-B CPU (show_gpu_info) |
| #1573 | ADOPT | IP addresses in Net via `net_get_interfaces()`; `net_hide_ip=false` | Net + P-C |
| #1552 | ADOPT-LATER | Per-proc GPU% and GMem via nvidia-smi compute-apps/pmon + guarded DRM fdinfo | Collect + P-E cols / P-H |
| #1546 | ADOPT | cwd in detailed view via `readlink /proc/pid/cwd`, lazy, "unavailable" on EACCES | P-E |
| #1476 | ADOPT | `proc_box_width_percent=55`, Shift/Alt+Shift arrows, optional preset 4th field | FrameBuilder + P-F |
| #1411 | ADOPT | Options tab digits match box toggles (0 general, 1 cpu, 2 mem, 3 net, 4 proc, 5 gpu) | P-F |
| #1008 | N/A (covered) | `Cpu` already gives UNMEASURED on Δ=0 with no idle floor; view must hold the last value | P-B test |
| #985 | ADOPT-LATER | Intel NPU via `intel_vpu` sysfs busy_time/mem/freq | Collect / P-H |

No outright REJECTs. The only rejected element is #1791's choice to store internal tree state in config.conf; the
feature itself is kept. Totals: 16 ADOPT (2 of them split with a later part: #1873, #1791), 7 ADOPT-LATER,
9 N/A.

## Proposed Wave U grouping (order of implementation)

**U0: collector correctness (now, before P-B; no new keys)**
1. #1869 analog: `nvidia-smi` candidate fallback in `Gpu`. S.
2. #1856: rename / paren / newline regression fixtures for `ProcList::parseStat`. XS.

**U1: collector data additions (now or alongside P-B; additive fields only)**
3. #1739: `MemorySnapshot` zswap and zswapped fields. S.
4. #1785 + #1792: per-core `Freq` from `cpuN/cpufreq`, plus `Freq::label()` extraction. M.
5. #1573 prereq: `NetInterface` ipv4/ipv6 via `net_get_interfaces()`. S.
6. ProcList bundle:
   - #1859 argv0 basename offset;
   - #1823 `/proc/pid/io` gated reader + rates + EACCES sentinel;
   - #1873 cgroup container tag cached per lifetime.

   M in total.

**U2: library lane (with L3)**
7. #1783 `block2` in sugar-dash `DualSampleGraph`, plus the `Schema` graph_symbol enums. S-M.

**U3: per-phase panel features (fold into each phase's acceptance)**

| Phase | Items |
|---|---|
| P-A/P-B | #1858 + #1614 invariants (hidden box, width clamp, 8-GPU min-width test); #1008 hold-on-UNMEASURED test |
| P-B | #1785 `show_core_freq`; #1747 `mem_selected`; #1739 `show_zswap` row |
| P-C | #1573 `net_hide_ip` |
| P-D | #1700 `disks_order` |
| P-E | #1859 `proc_command_basename`; #1823 IO cols + sorts; #1546 cwd; #1791a branch-total tree sort; #1873 `proc_filter_containers` + `O` |
| P-F | #1476 `proc_box_width_percent` + keys + preset W; #1411 tab digits; #1791b section headings; #1849 reload clears memos |
| P-G | #1683 mellow theme (bump the 41 pins); #1849 theme-switch recolour test |

**U4: post-v1 (ADOPT-LATER)**
- **P-H GPU wave**, in order:
  1. multi-vendor Accelerator `GpuSnapshot`;
  2. #1854 AMD sysfs + amdgpu.ids;
  3. #1888 Intel sysfs + fdinfo;
  4. #985 Intel NPU;
  5. #1839 AMD NPU (FFI);
  6. #1730 GPU box slots and targets on the #1881 grid;
  7. #1552 per-process GPU columns, sorts, `g`.
- **Containers box:** #1873 `ctr` box, `x`/`[`/`]`, cgroup v2 stats, docker names.
- **Tree-state persistence:** #1791c `proc_tree_persist_state` with an XDG state file.

New btop-compatible config keys introduced (all additive; the `ConfigReader` and btop unknown-key policy keeps
both directions safe):
- v1: `proc_command_basename`, `proc_filter_containers`, `show_core_freq`, `mem_selected`, `show_zswap`,
  `disks_order`, `net_hide_ip`, `proc_box_width_percent`.
- Later: `gpu_box_columns`, `proc_gpu_only`, `proc_gpu_graphs`, `proc_tree_persist_state`.

Enum extensions (soft break; btop warns and uses its default):
- `graph_symbol*` += `block2`.
- `proc_sorting` += `io read`, `io write`, `io total` (later also `gpu`, `gpu memory`).
- `shown_boxes` += `ctr` and `gpuN` for N > 5.
- Presets: an optional 4th proc field.

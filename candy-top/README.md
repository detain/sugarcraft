# candy-top

A full-screen terminal system monitor for PHP 8.3+, built on the SugarCraft TUI stack. candy-top shows live CPU, memory, disk, network, process and container panels with braille, block or sextant graphs, gradient meters and themes in the btop tradition, and follows the feature set of aristocratos/btop v1.4.7 plus a set of adopted upstream pull requests. Everything runs on the [candy-core](../candy-core) Model/Cmd runtime, and the drawing uses [sugar-dash](../sugar-dash), [sugar-bits](../sugar-bits) and [candy-sprinkles](../candy-sprinkles).

![candy-top demo](https://raw.githubusercontent.com/detain/sugarcraft/master/candy-top/.vhs/top.gif)

candy-top reads btop's config format and btop's `.theme` files, and its keys, menus and layout rules follow btop's source. Where it differs from btop, the [Differences from btop](#differences-from-btop) section says so.

## Contents

- [Requirements](#requirements)
- [Install](#install)
- [Run](#run)
- [The boxes](#the-boxes)
- [Menus and overlays](#menus-and-overlays)
- [Presets](#presets)
- [Key bindings](#key-bindings)
- [Mouse](#mouse)
- [Configuration](#configuration)
- [Config keys](#config-keys)
- [Themes](#themes)
- [TTY mode](#tty-mode)
- [Data sources and permissions](#data-sources-and-permissions)
- [Containers and VMs](#containers-and-vms)
- [GPU](#gpu)
- [Adopted upstream btop PRs](#adopted-upstream-btop-prs)
- [Differences from btop](#differences-from-btop)
- [Development](#development)

## Requirements

- PHP 8.3 or newer, CLI.
- A terminal with an interactive tty on both stdin and stdout. Like btop, candy-top refuses to start without one ("No tty detected!").
- Truecolor is recommended. With `truecolor = false` colours are mapped to the 256-colour cube, and in [TTY mode](#tty-mode) the 16 terminal colours are used.
- Optional PHP extensions:
  - `ext-posix` shows user names in the process list (otherwise numeric uids), sends signals (`posix_kill`) and detects a real console (`posix_ttyname`).
  - `ext-pcntl` renices processes (`pcntl_setpriority`; PHP's `proc_nice()` cannot renice another pid) and traps SIGTERM/SIGHUP so the config is still saved.
- Optional: `nvidia-smi` for NVIDIA [GPU](#gpu) readings. AMD and Intel GPUs and Intel/AMD NPUs are read from sysfs on Linux and need no extra tools.

## Install

```sh
composer require sugarcraft/candy-top
```

Add `:@dev` (`composer require sugarcraft/candy-top:@dev`) while your project's `minimum-stability` is `stable` and no tagged release exists. Composer installs the launcher as `vendor/bin/candy-top`. For a global tool, use `composer global require sugarcraft/candy-top` and put Composer's global `vendor/bin` on your `PATH`.

## Run

```sh
vendor/bin/candy-top                     # live host, default config
vendor/bin/candy-top --config ~/top.conf # another config file (also the save target)
vendor/bin/candy-top --fake              # deterministic demo data, never the real host
vendor/bin/candy-top --help              # options plus the full key list
```

| Flag | Effect |
|---|---|
| `--fake` | Uses the seeded fake collectors (`Source\Fake\*`) instead of the live host. The data is deterministic and the pids are invented, so it suits demos and VHS tapes. The fake host has two GPUs and one NPU (gpu boxes `gpu0`-`gpu2`), with per-process GPU use. |
| `--config <path>`, `--config=<path>` | Reads the config from `<path>` instead of `$XDG_CONFIG_HOME/candy-top/config.conf`. That file is also where the config is saved. A missing value is an error (exit 2). So is a following argument that starts with `-` in the space-separated form (`--config -x`); `--config=-x` is accepted as a path. |
| `-h`, `--help` | Prints usage and the KEYS section, which is generated from the same key table as the help overlay, then exits 0. Works without a tty. |

Any other argument exits with status 2. There is no `--tty` flag. TTY mode comes from the `force_tty` option or from running on a real console, as described in [TTY mode](#tty-mode). Config load warnings (bad values) are printed on stderr at startup, and the option keeps its default.

From a monorepo checkout, `php candy-top/bin/candy-top` works as well. `php candy-top/examples/top.php` runs the fake demo with a throwaway config path. The VHS tape `candy-top/.vhs/top.tape` records that demo.

## The boxes

The screen is split into four boxes, plus the [containers box](#ctr) and up to six [gpu boxes](#gpu-boxes), laid out by a PHP re-implementation of btop's `calcSizes`. Keys `1` to `4` toggle the four boxes, `x` toggles the containers box, keys `5` to `0` toggle gpu box slots, and the `shown_boxes` option sets which are shown. Placement flags move the boxes around: `cpu_bottom` puts cpu at the bottom, `mem_below_net` swaps mem and net, `proc_left` moves proc to the left, and `proc_box_width_percent` sets the proc width. Box titles and buttons are drawn into the borders, and each button's hotkey letter is highlighted. A clock (`clock_format`) sits in the top border. When the terminal is smaller than the layout's minimum, btop's "Terminal size too small" notice is shown instead. `q`, `1` to `4` and the gpu slot keys `5` to `0` still work behind that notice (`x` does not, as in btop #1873).

Hidden boxes are never sampled or drawn (btop #1858). Every graph dimension is clamped to at least 1 cell, so even odd layouts cannot crash a graph.

### cpu

- **Upper and lower graphs.** These are dual-sample graphs (`graph_symbol_cpu`) showing the fields picked by `cpu_graph_upper` and `cpu_graph_lower` (`total`, `user`, `system`, `iowait`, `steal`, and the other `/proc/stat` columns, plus the GPU fields described below). A divider row such as `total─▲▼─user` separates them. `cpu_invert_lower` inverts the lower graph, and `cpu_single_graph` turns it off.
- **Per-core grid.** Each core gets a mini graph on a dimmed underlay, its percentage, and its temperature when `show_coretemp` is on. Cores that have never reported are dimmed. A position-coloured meter row shows the total.
- **Frequency.** The title shows the frequency when `show_cpu_freq` is on, collapsed by `freq_mode` (`first`, `range`, `lowest`, `highest`, `average`). `show_core_freq` (btop #1785) adds a per-core value (`value`) or a mini history graph (`graph`) to each core. When a column is too narrow for the extra cells, it falls back from `graph` to `value` to `off` rather than overflowing.
- **Temperature.** `check_temp`, `cpu_sensor` (choose from a list in the options menu), `show_coretemp`, `cpu_core_map` and `temp_scale` (`celsius`, `fahrenheit`, `kelvin`, `rankine`) control the temperature readings.
- **Load, uptime and the `- 2000ms +` button.** The footer shows load averages and the uptime (`show_uptime`). The update-interval button in the border steps `update_ms`.
- **Battery.** A badge in the top border shows the percentage, a charge meter, the status, time to empty or full, and the draw in watts (`show_battery_watts`). It appears only when `show_battery` is on and a battery is present. `selected_battery` chooses between several batteries, and the clock makes room for the badge.
- **GPU sub-graphs.** These appear when a GPU answers and `show_gpu_info` is `On`, or `Auto` while some GPU has no [gpu box](#gpu-boxes) of its own (btop #1730). Under `Auto` the cpu box lists only the GPUs without a box; `On` lists every GPU. With `cpu_graph_lower = Auto`, the lower graph becomes `gpu-totals`, one graph per listed GPU separated by a divider column, with widths split as in btop #1614. The other choices are `gpu-vram-totals` and `gpu-pwr-totals` (one graph per GPU), and `gpu-average`, `gpu-vram-total` and `gpu-pwr-total` (one shared graph). The cores box also lists a brief row for each listed GPU. NPUs never appear in the cpu box. The cpu box keeps sampling the GPUs while `cpu_graph_upper` or `cpu_graph_lower` names a `gpu-*` field, even when every GPU has its own box. See [GPU](#gpu).

### mem

- **Memory.** Shows used, available, cached and free, either as graphs (`mem_graphs`, the default) or as meters. `mem_selected` (btop #1747) focuses on a single metric (`used`, `available`, `cached`, `free` or `swap_used`) and draws it as one large graph with the percentage overlaid.
- **Swap.** `show_swap` shows swap figures. `show_zswap` (btop #1739, Linux 5.19+) adds a zswap row, and swap `Used` then counts only the pages actually on disk.
- **Disks.** When `show_disks` is on (`d` toggles it), the right part of the box lists the mounts. Each mount shows used and free meters, read and write rates, and a busy-percentage graph (`show_io_stat`). `/` comes first, then the swap pseudo-disk (`swap_disk`), then the order of the mount table. `disks_order` (btop #1700) puts named mounts, or `swap`, first. `disks_filter` selects mounts (prefix the list with `exclude=` to invert it). `use_fstab`, `only_physical` and `zfs_hide_datasets` filter further.
- **IO mode.** `i` toggles `io_mode`, which draws large read and write graphs per disk, mirrored or combined (`io_graph_combined`). Each disk is scaled to `io_graph_speeds` (MiB/s per mount point).
- **ZFS.** With `zfs_arc_cached`, the ARC counts as cached memory, and as available memory above its minimum size.

### net

- **Graphs.** Download and upload graphs for the selected interface, plus a stats sub-box with the current rate, the top rate and the total for each direction. `swap_upload_download` puts upload on top.
- **Auto-scale.** `net_auto`, toggled by `a`, rescales the graphs with hysteresis (sugar-dash `NetAutoScale`), never going below 10 KiB. `net_sync`, toggled by `y`, gives both directions the same scale. With auto-scale off, `net_download` and `net_upload` set fixed ceilings in Mebibits. Toggling either option rescales at once.
- **Interface selection.** At startup the interface is `net_iface` if it exists. Otherwise the first connected interface with the most traffic is picked, and that pick sticks until the interface disappears. `b` and `n` cycle through the interfaces. That choice is not saved.
- **Zeroing.** `z` resets the totals of the current interface, and a second `z` restores them.
- **Addresses.** The interface's IPv4 address (otherwise its global IPv6 address) is shown in the title (btop #1573). `net_hide_ip` hides it.
- **Bitrate units.** `base_10_bitrate` (`Auto` follows `base_10_sizes`).

### proc

- **List.** Columns are pid, program, command, threads, user, memory, cpu% and a per-process mini cpu graph (`proc_cpu_graphs`). The colours come from the cpu gradient (`proc_colors`), with a darkening fade away from the selection (`proc_gradient`). When the box is at least 90 columns wide, IO/R and IO/W columns are added (btop #1823). `/proc/[pid]/io` is sampled while those columns show or an `io *` sort is active, so the io sorts also work in a narrower box, where the columns stay hidden. A process whose io file is unreadable shows `-`, never `0`.
- **GPU columns** (btop #1552). Once per-process GPU data has been measured on the host, the box adds `Gpu%` (GPU utilisation, summed over the process's GPUs and clamped to 100), a per-process mini GPU graph (`proc_gpu_graphs`, on by default) and `GMem` (GPU memory). They need room: with both mini graphs on, `Gpu%` and its graph appear from 86 columns and `GMem` from 92. Turning `proc_gpu_graphs` or `proc_cpu_graphs` off lowers each threshold by 5 (81/87), and turning both off by 10. While GPU data is measured the IO columns yield to them and need 106 columns (101 with one mini graph off, 96 with both off) instead of 90. Per-process data comes from `nvidia-smi --query-compute-apps` plus `nvidia-smi pmon` on NVIDIA and from DRM fdinfo on amdgpu, i915 and xe; it is held between GPU samples, and a pid the GPU sample no longer lists reads 0. It is collected only while a `Gpu%` column fits, `proc_gpu_only` is on, or a gpu sort is active.
- **Sorting.** `←` and `→` cycle through `pid`, `name`, `command`, `threads`, `user`, `memory`, `cpu direct`, `cpu lazy`, `io read`, `io write`, `io total`, `gpu` and `gpu memory`. `r` reverses the order. `cpu lazy` is btop's smoothed sort, which pulls hogs forward over time. The two gpu sorts (btop #1552) sort by per-process GPU utilisation and GPU memory.
- **Tree view.** `e` turns on the tree view. Space or `+`/`-` collapses or expands the selected branch, `C` collapses or expands its children, and `E` does all branches. `proc_aggregate` adds child usage, GPU use included, to the parent. `proc_tree_auto_collapse` collapses wide branches on entry. Siblings are sorted by branch totals (btop #1791a).
- **Filter.** `f` or `/` starts a live filter. A plain filter is a case-insensitive substring of the pid, name, command, user or container/VM name. A filter starting with `!` is a regular expression, searched in the pid, name and user and fully matched against the command. Enter keeps the filter, Esc restores the previous one, and `delete` clears it. `proc_filter_kernel` hides kernel threads. `proc_filter_containers`, toggled by `O`, hides processes in containers and VMs.
- **GPU-only filter** (btop #1552). `proc_gpu_only` shows only processes with non-zero GPU utilisation or GPU memory. `g` toggles it, as does `ctrl+g` and the `gpu-only` button in the box title. With `vim_keys`, `g` stays "top of list", so use `ctrl+g`. The filter and the button apply only once per-process GPU data has been measured; on a host without it the option is inert. In the tree view a GPU-idle process is hidden the way a non-matching text filter hides it.
- **Other display options.** `c` switches to per-core cpu% (`proc_per_core`). `%` switches memory between bytes and percent (`proc_mem_bytes`). `proc_command_basename` (btop #1859) shows only the executable's basename in the list.
- **Detailed view.** Enter on a selected row opens a detail pane with the status, elapsed time, parent, user, threads, nice, memory, IO totals, cwd (btop #1546) and the full command. Enter again closes it. With `proc_follow_detailed` on, the list follows the detailed process.
- **Signals.** Each of these acts on the selected row, or on the detailed process when no row is selected. `t` asks for confirmation, then sends SIGTERM. `k` asks for confirmation, then sends SIGKILL (`K` with `vim_keys`). `s` opens the signal chooser and `N` opens the renice menu. A failure (EPERM, ESRCH, ...) opens an error box.
- **Follow and pause.** `F` keeps the selected process centred as the list re-sorts, and `u` pauses list updates (`pause_proc_list`). While either is active, a banner sits on the list's last row.
- **Mouse.** Click a row to select it, and click the selected row to open its detail view. Click the `[-]`/`[+]` marker to collapse or expand a branch. Clicking the scrollbar pages at the arrows, drags the thumb, or jumps proportionally. The wheel scrolls by 3 rows.
- **Containers and VMs.** These are tagged in the command column. See [Containers and VMs](#containers-and-vms).

### ctr

The containers box (btop #1873) lists the containers that have processes running, one row each, and (unlike btop) the libvirt/KVM virtual machines too. `x` toggles it, as does the `x ctr` button on the cpu box's top border (shown when the cpu box is at least 76 columns wide), and `ctr` in `shown_boxes` or a preset turns it on.

- **Engine in the title.** When candy-top itself runs inside a container, the cpu box's top border shows the engine's name where the `x ctr` button would be, as btop does. The name is detected once at startup without starting any process. The checks run in this order: `KUBERNETES_SERVICE_HOST` (k8s), `/run/.containerenv` (podman), `/.dockerenv` (docker), the first word of `/run/systemd/container`, `container=` in `/proc/1/environ`, a container path in `/proc/self/cgroup`, and an overlay root mount in `/proc/self/mountinfo` whose layers are docker's, podman's or containerd's. systemd's names are shortened (`systemd-nspawn` to `nspawn`, `lxc-libvirt` to `lxc`). On FreeBSD a jail (`security.jail.jailed`) shows `jail`. The label is cut to fit before the clock. Clicking it, or pressing `x`, still toggles the box.

- **Placement.** The box sits at the top of the proc column and takes a third of it: at least 6 rows, and never so many that the proc box drops below 16. Without the proc box it takes the whole column. Its minimum size is 44x6.
- **Rows.** Each row shows the name (the docker name, the short id, or a VM's domain name), the engine (when the list is at least 52 columns wide), the process count, memory, a 5-cell cpu mini graph (`graph_symbol_proc`) and cpu%. The rows are sorted by name. Colours follow `proc_colors`. The bottom border shows `selected/count`.
- **Selecting.** `[` and `]` step through "no selection" and the containers, wrapping round. The halves of the `[ select ]` title button do the same, and clicking a row selects it or, if it is already selected, clears the selection. While a container is selected the proc box shows only that container's processes (in list and tree view) and its selection jumps back to the top. The selection is cleared when the container goes away or the box is hidden.
- **Detail.** With a container selected and the box at least 80 columns wide, a panel on the right shows its name and engine (and a VM's vCPU count), cpu%, a cpu history graph, and a memory meter against its `memory.max`. When that is unlimited, the meter uses a VM's configured memory, or else total memory. A VM's title also gives its cpu as a share of its own vCPUs (`Cpu 7.5% · 15.0% of 4 vCPU`); the list column stays a share of the host, as for containers.
- **Figures.** On cgroup v2, cpu is the `cpu.stat` `usage_usec` delta (percent of the whole machine, or of one core with `proc_per_core`). Memory is `memory.current` minus `inactive_file`, which is what `docker stats` shows. Where those files cannot be read (cgroup v1, or permissions) the box shows the sum of the container's processes instead. The cpu graph is always a percentage of total cpu power.
- **Docker names.** When a new docker container appears, one `GET /containers/json` is sent over the docker socket (`/var/run/docker.sock`, or `DOCKER_HOST` when it is a `unix://` path), with a 1 s deadline. Without access to the socket the short id stays. A name, once found, is kept until the container restarts, so a `docker rename` shows after the restart.
- **Cost.** The box reads the proc box's process scan rather than scanning `/proc` itself; opening it beside the proc box makes the proc box rescan at once. It runs its own scan only while the proc box is hidden. A sample covering less than half of `update_ms` (a proc rescan after a sort change, Enter or a toggle) is skipped, so the cgroup cpu% always spans a real interval; re-showing the box resets that window, so its first sample always counts. A hidden box costs nothing, and on FreeBSD nothing is scanned for it.
- **VMs.** Each libvirt/KVM guest is one row with engine `kvm`, the same word as its `[kvm:name]` process tag. Its processes are grouped by the guest's machined scope (`machine.slice/machine-qemu\x2d<id>\x2d<name>.scope`; the qemu process itself sits in its `libvirt/emulator` child). The row is named by the domain name: the emulator's `-name guest=` value, or else the scope's name with the systemd `\x2d` escapes decoded. The figures are the scope's cgroup v2 files, read as for a container. A guest's `memory.max` is normally `max`, so its memory limit is the RAM it was started with (`-m`, read from its command line; libvirt is never called). `memory.current` does not count hugetlb pages, so a hugepage-backed guest reads low, and QEMU's own overhead can push a guest past its `-m`, in which case the meter stops at 100%. VMs sort, select and filter the proc box exactly like containers. `ctr_show_vms = false` leaves them out, as btop does. A bare qemu started outside libvirt owns no cgroup and is not listed.
- **Not listed.** Flatpak and Snap are not containers. On FreeBSD the box stays empty.

### gpu boxes

Up to six gpu boxes can be shown at once (btop #1730), one per accelerator: `gpu0`, `gpu1`, ... in `shown_boxes`, numbered GPUs first and then NPUs. Data, collectors and caveats are in [GPU](#gpu).

- **Slots and keys.** The number keys `5`, `6`, `7`, `8`, `9` and `0` toggle gpu box slots 0 to 5, and each box's title shows its slot key (none for `0`). Opening a slot shows the first accelerator, from the slot's index on, that has no box yet. A toggle that would not fit the terminal opens the size-error box instead. Slots are runtime state: they are never saved, and changing `shown_boxes` any other way (options menu, preset, reload) resets them.
- **Retargeting.** While more than one accelerator exists and the box is wide enough, the title carries a `← gpuN →` selector. Clicking an arrow moves that box to the previous or next accelerator that has no box, keeping its slot. A move that would not fit is ignored.
- **Grid** (btop #1881). Gpu boxes are placed side by side, all one width and height, with one blank column between them. `gpu_box_columns` is `Auto` (as many per row as the terminal fits, a box being at least 34 columns wide) or a number from 1 to 6 to force the columns, still capped by what fits.
- **Detail levels** (btop #1881). The box width picks how much is drawn. `Full` (56 columns or more) shows everything, `Compact` (44 or more) drops the encoder/decoder and memory sections, and `Minimal` also drops the power row.
- **Contents.** A utilisation graph (`graph_symbol_gpu`, mirrored with `gpu_mirror_graph`) and meter, temperature, power with P-state, clocks, encoder/decoder use and VRAM used/total with its own graph, each where the device reports it. The stats sub-box is titled with `custom_gpu_nameN` or the model name.
- **NPUs** (btop #985, #1839). An NPU box is titled `npu`, its meter reads `NPU` and its memory reads `ram` instead of `vram`.
- **Startup and presets.** At startup a `gpuN` beyond the detected accelerators resets `shown_boxes` to the default, as btop does. A preset naming an accelerator that does not exist is refused with the size-error box.

## Menus and overlays

While a menu is open, the frame behind it is dimmed to `inactive_fg`. With `background_update = false`, and always in TTY mode, that dimmed frame is also frozen. Menus re-centre when the terminal is resized. The main, help, options and signal-chooser menus need at least 80x24, and the other menus need 50x20. On a smaller terminal a size-error box is shown instead.

- **Main menu** (`Esc`, `m`, or the `m` menu button in the cpu border). Shows the banner and three block-letter entries: Options, Help and Quit. Use the arrows, Tab or `j`/`k` to move and Enter or Space to open. Options and Help open on top of the main menu, which comes back when they close.
- **Options menu** (`F2`, `o`). A 78-column box with tabs 0 general, 1 cpu, 2 mem, 3 net, 4 proc and 5 gpu (btop #1411). The gpu tab appears only once a GPU has answered. Options are grouped under non-selectable headings (btop #1791b), and the selected option's description is shown on the right.
  - Up and down move between options, skipping headings. Page Up and Page Down flip pages.
  - Tab and Shift+Tab change tabs, and a digit picks a tab directly.
  - `←` and `→` flip a boolean, step a number (`update_ms` by 100), or cycle a list. `color_theme` cycles the installed themes. `cpu_graph_*`, `cpu_sensor` and `selected_battery` cycle what the collectors reported.
  - Enter, `e` or `E` edits a number or a text value inline. Enter applies the edit and Esc cancels it.
  - Every change applies live: the layout, colours, graph symbols and theme change at once. A theme change also re-paints the dimmed frame, so you can preview themes. A value btop would reject opens a warning box inside the menu instead of being applied.
  - Esc, `q`, `o`, Backspace or a click outside the box closes the menu.
- **Help** (`F1`, `?`, `h`, or `H` with `vim_keys`). Lists every key in the [key table](#key-bindings), paged when the terminal is short.
- **Signal chooser** (`s`). A 5-column grid of signals 1 to 31 (16, SIGSTKFLT, is skipped). Type a number, move with the arrows or `h`/`j`/`k`/`l`, and press Enter or Space to send.
- **Renice** (`N`). A nice value from -20 to 19. Type a value, step with up and down (by 1) or left and right (by 5), and press Enter to apply.
- **Message boxes.** These are the terminate and kill confirmations (Yes/No), the signal and renice failure box, the "terminal too small" box for a toggle or preset that would not fit, and warning boxes for an invalid option value, a config-save failure, or a load warning on `ctrl+r` reload.

## Presets

`p` cycles forward through the layout presets and `Shift+p` cycles backward. Preset 0 is always every box with default settings. The `presets` option defines up to 9 more, separated by whitespace. Each preset is a comma-separated list of boxes:

```text
box:P:G          P = 0|1 alternate position, G = graph symbol (default|braille|block|block2|tty)
proc:P:G:W       proc only: W = proc box width percent (0-100) or "default" (btop PR #1476)
```

The default is `cpu:1:default,proc:0:default cpu:0:default,mem:0:default,net:0:default cpu:0:block,net:0:tty`.

- The alternate position maps to `cpu_bottom`, `mem_below_net` and `proc_left`.
- A `ctr` entry (btop #1873) shows the containers box. Its position and graph symbol are ignored; the box draws with `graph_symbol_proc`.
- A proc entry always sets `proc_box_width_percent`, to its W value or to 55 when W is missing.
- W is written back only when you wrote it. Stock btop 1.4.7 rejects a whole `presets` string that contains a W field and falls back to its default.
- `disable_presets` turns off the default preset (`Default`), the custom presets (`Custom`) or all of them (`All`).
- A preset that would not fit the terminal opens the size-error box and keeps the current preset.
- Toggling a box, changing `shown_boxes`, `presets` or the proc width, or setting `disable_presets` to anything other than `Off` drops the preset index.

The `p` button in the cpu border cycles presets too.

**Proc box width** (btop PR #1476). When mem or net is shown alongside proc:

- `Shift+←` / `Shift+→` change `proc_box_width_percent` by 1%.
- `Alt+Shift+←` / `Alt+Shift+→` change it by 10%.
- `Ctrl+Shift+←` / `Ctrl+Shift+→` jump to the widest or narrowest width (mirrored with `proc_left`).
- `Ctrl+Shift+↓` resets it to 55.

The width is clamped to the narrowest and widest layout the window allows.

## Key bindings

The table below is generated from `SugarCraft\Top\Input\KeyTable`, the same roster the help overlay and `--help` print. `tests/Docs/ReadmeKeyTableDriftTest.php` fails when the two disagree, and `tests/Input/KeyTableTest.php` checks each documented key against the real handlers.

Context rules, following btop's input handling:

- `ctrl+c` always quits.
- An open menu takes every key first.
- An active process filter takes every key, including `q`.
- The proc box claims `+`, `-`, `=`, Space and `C` only while the tree view is on and a row is selected. Otherwise `+`, `-` and `=` step `update_ms`. Holding `+` or `-` accelerates to 1000 ms steps; `=` steps like `+` but never accelerates.
- `j`, `k`, `g`, `G`, `h` and `l` move only with `vim_keys`. Plain `k` is kill and plain `h` is help, so with `vim_keys` on, those become `K` and `H`.
- A modified arrow btop has no name for (for example Shift+↑ or Alt+←) is ignored.

<!-- BEGIN generated:keys -->
| Key | Action | btop key names |
|---|---|---|
| `Mouse 1` | Clicks buttons and selects in process list. | `mouse_click`, `mouse_drag`, `mouse_release` |
| `Mouse scroll` | Scrolls any scrollable list/text under cursor. | `mouse_scroll_up`, `mouse_scroll_down` |
| `Esc, m` | Toggles main menu. | `escape`, `m` |
| `p` | Cycle view presets forwards. | `p` |
| `shift + p` | Cycle view presets backwards. | `P` |
| `1` | Toggle CPU box. | `1` |
| `2` | Toggle MEM box. | `2` |
| `3` | Toggle NET box. | `3` |
| `4` | Toggle PROC box. | `4` |
| `5, 6, 7, 8, 9, 0` | Toggle GPU box. | `5`, `6`, `7`, `8`, `9`, `0` |
| `x` | Toggle CTR (containers) box. | `x` |
| `[, ]` | Select previous/next container in CTR box. | `[`, `]` |
| `d` | Toggle disks view in MEM box. | `d` |
| `F2, o` | Shows options. | `f2`, `o` |
| `F1, ?, h` | Shows this window. | `f1`, `?`, `h` |
| `ctrl + r` | Reloads config file from disk. | `ctrl+r` |
| `q, ctrl + c` | Quits program. | `q`, `ctrl+c` |
| `+, -, =` | Add/Subtract 100ms to/from update timer. | `+`, `-`, `=` |
| `Up, Down` | Select in process list. | `up`, `down` |
| `Enter` | Show detailed information for selected process. | `enter` |
| `Spacebar` | Expand/collapse the selected process in tree view. | `space` |
| `C` | Expand/collapse the selected process' children. | `C` |
| `Pg Up, Pg Down` | Jump 1 page in process list. | `page_up`, `page_down` |
| `Home, End` | Jump to first or last page in process list. | `home`, `end` |
| `Left, Right` | Select previous/next sorting column. | `left`, `right` |
| `b, n` | Select previous/next network device. | `b`, `n` |
| `i` | Toggle disks io mode with big graphs. | `i` |
| `z` | Toggle totals reset for current network device | `z` |
| `a` | Toggle auto scaling for the network graphs. | `a` |
| `y` | Toggle synced scaling mode for network graphs. | `y` |
| `f, /` | To enter a process filter. Start with ! for regex. | `f`, `/` |
| `F` | Follow selected process. | `F` |
| `u` | Pause process list. | `u` |
| `delete` | Clear any entered filter. | `delete` |
| `c` | Toggle per-core cpu usage of processes. | `c` |
| `r` | Reverse sorting order in processes box. | `r` |
| `e` | Toggle processes tree view. | `e` |
| `E` | Collapse/expand all processes in tree view. | `E` |
| `%` | Toggles memory display mode in processes box. | `%` |
| `O` | Toggle hiding containers and VMs in process list. | `O` |
| `g, ctrl + g` | Toggle GPU-only process filter (vim_keys: ctrl + g). | `g`, `ctrl+g` |
| `Selected +, -` | Expand/collapse the selected process in tree view. | `+`, `-`, `=` |
| `Selected t` | Terminate selected process with SIGTERM - 15. | `t` |
| `Selected k (vim K)` | Kill selected process with SIGKILL - 9. | `k` |
| `Selected s` | Select or enter signal to send to process. | `s` |
| `Selected N` | Select new nice value for selected process. | `N` |
| `Shift + Left, Right` | Adjust the proc box width by 1% (mem or net shown). | `shift_left`, `shift_right` |
| `Alt+Shift+Left/Right` | Adjust the proc box width by 10% (mem or net shown). | `alt_shift_left`, `alt_shift_right` |
| `Ctrl+Shift+Left` | Proc box to max width (min with proc_left). | `ctrl_shift_left` |
| `Ctrl+Shift+Right` | Proc box to min width (max with proc_left). | `ctrl_shift_right` |
| `Ctrl+Shift+Down` | Reset the proc box width percentage to 55%. | `ctrl_shift_down` |
| `h, j, k, l` | With vim_keys: left, down, up, right in lists. | `h`, `j`, `k`, `l` |
| `g, G` | With vim_keys: first / last process in the list. | `g`, `G` |
| `H, K` | With vim_keys: help and kill (h and k move). | `H`, `K` |
<!-- END generated:keys -->

## Mouse

Mouse reporting is on unless `disable_mouse` is set. The mode is button and motion tracking (`?1002h` + SGR `?1006h`), the same as btop, and flipping the option at runtime turns reporting on or off at once.

| Where | Action |
|---|---|
| cpu border: the `m` menu button, the `p` preset button, the `x ctr` button, `-` / `+` around the interval | Opens the main menu, cycles the preset, toggles the containers box, steps `update_ms` (the same as the keys, hold acceleration included). |
| mem border: `disks`, `io` | Toggle `show_disks` / `io_mode`. |
| net border: the `b`/`n`, `z`, `a` and `y` buttons | Previous or next interface, zero the totals, auto-scale, sync. The `a` and `y` buttons appear only when the box is wide enough. |
| proc border buttons | `filter`, clear filter, `O` containers, `per-core`, `reverse`, `tree`, `←` / `→` sorting, `pause`, `info` (detail), and with a selection `terminate`, `kill`, `signals`, `Nice`, `Follow`. |
| ctr border: `[ select ]`; ctr rows | The left and right halves of the button select the previous / next container. Clicking a row selects it, and clicking the selected row clears the selection. |
| proc list | Click selects. Clicking the selected row opens the detail view, and clicking a `[-]`/`[+]` marker toggles a branch. The wheel scrolls by 3. The scrollbar pages at its arrows, the thumb can be dragged, and a click on the track jumps proportionally. |
| any other left click | Clears the process selection, as btop does. |
| menus | Click entries, tabs, `←`/`→` arrows and buttons. The wheel moves selections and pages. A click outside a menu closes it. |

Only bare left presses act as clicks. Right and middle clicks and modified clicks are dropped, as in btop.

## Configuration

**Location.** The config is read from `$XDG_CONFIG_HOME/candy-top/config.conf`, or `~/.config/candy-top/config.conf` when `XDG_CONFIG_HOME` is unset or not absolute. `--config <path>` reads another file and makes that file the save target. The directory is named `candy-top` so btop's own file is never touched.

**Format.** The format is btop's own: `#` comments and `name = value` lines, with strings in double quotes. A btop `btop.conf` copied over loads as-is. Unknown keys are skipped silently, so files from other btop versions also load. A value that fails validation is dropped with a warning on stderr, and the option keeps its previous value. A later duplicate of a key wins.

**Persistence** (btop's `write_new` and `clean_quit`):

- The file is rewritten on exit when `save_config_on_exit` is on and something persisted changed. That includes options changed in the menu or by keys, box toggles and presets. A file that was missing, outdated or held invalid values is rewritten too.
- The save runs on every quit path: `q`, `ctrl+c`, the main menu's Quit, SIGTERM and SIGHUP (via `ext-pcntl`), and even after a crash, once the terminal has been restored.
- Turning `save_config_on_exit` off in the options menu writes the file at once, so the choice itself is saved.
- Writes are atomic: a temp file is written in the same directory, fsynced, then renamed into place. A symlinked config is followed and its target replaced, so the link survives. An existing file's permission bits are kept. A read-only file is never replaced; the failure is reported instead. A directory candy-top creates gets mode 0700.
- If a save fails mid-session, a warning box opens. If it fails on exit, the error is printed on stderr and candy-top exits with status 1.

**Tree state** (btop #1791c). With `proc_tree_persist_state` on, the branches you collapse or expand in the tree view (space, `+`/`-`/`=`, `C`, or a click on the `[+]`/`[-]` marker) are remembered across runs. They are stored in `$XDG_STATE_HOME/candy-top/tree-state.json`, or `~/.local/state/candy-top/tree-state.json` when `XDG_STATE_HOME` is unset or not absolute. They are not stored in config.conf, unlike in the btop PR, so the config stays btop-compatible and is not rewritten on every collapse. PIDs change between runs, so a choice is keyed by the chain of process names from the tree root (for example `systemd → sshd → bash`), and processes with the same chain share a choice. A remembered choice is applied when a process first appears, and it overrides `proc_tree_auto_collapse`. `E` (all branches) is not remembered. The file is written atomically about two seconds after a change, and on exit (including SIGTERM/SIGHUP and crash exits) when anything is still unsaved. Nothing is written while the option is off; turning it off drops a pending write. It keeps at most 512 chains, drops the least recently used first, and forgets a chain unused for 90 days. A missing, unreadable or corrupt file is ignored. A failed write is reported on stderr at exit and never changes the exit status.

**Reload.** `ctrl+r` reloads the file over the running config. Runtime state such as the filter, the selection and the detailed view is kept, the theme list is rescanned and the theme is reloaded. The first load warning, if any, is shown in a warning box. A reload is not counted as a change that needs saving.

## Config keys

These are all persisted keys, in config.conf write order, generated from `SugarCraft\Top\Config\Schema` and the options-menu catalog. `tests/Docs/ReadmeConfigTableDriftTest.php` fails when they disagree. "Options menu" gives the tab number, the tab and the section heading where the key is edited. Descriptions are the comment lines written into config.conf. Runtime-only state (`tty_mode`, `proc_filter`, `show_detailed`, `pause_proc_list`, ...) is never written and is not listed.

<!-- BEGIN generated:config -->
| Key | Type | Default | Allowed values | Options menu | Description |
|---|---|---|---|---|---|
| `color_theme` | string | `"pastel"` | any text | 0 general › Appearance | Name of a btop++/bpytop/bashtop formatted ".theme" file, "Default" and "TTY" for builtin themes.<br>candy-top's default is the bundled "pastel" theme (btop's is "Default").<br>Themes should be placed in "$XDG_CONFIG_HOME/candy-top/themes" or the candy-top themes directory. |
| `theme_background` | bool | `true` | `true`, `false` | 0 general › Appearance | If the theme set background should be shown, set to False if you want terminal background transparency. |
| `truecolor` | bool | `true` | `true`, `false` | 0 general › Appearance | Sets if 24-bit truecolor should be used, will convert 24-bit colors to 256 color (6x6x6 color cube) if false. |
| `force_tty` | bool | `false` | `true`, `false` | 0 general › Terminal and input | Set to true to force tty mode regardless if a real tty has been detected or not.<br>Will force 16-color mode and TTY theme, set all graph symbols to "tty" and swap out other non tty friendly symbols. |
| `disable_presets` | string | `"Off"` | `Off`, `Default`, `Custom`, `All` | 0 general › Layout | Option to disable presets. Either the default preset, custom presets, or all presets.<br>"Off" All presets are enabled.<br>"Default" preset is disabled.<br>"Custom" presets are disabled.<br>"All" presets are disabled. |
| `presets` | string | `"cpu:1:default,proc:0:default cpu:0:default,mem:0:default,net:0:default cpu:0:block,net:0:tty"` | validated text (see description) | 0 general › Layout | Define presets for the layout of the boxes. Preset 0 is always all boxes shown with default settings. Max 9 presets.<br>Format: "box_name:P:G,box_name:P:G" P=(0 or 1) for alternate positions, G=graph symbol to use for box.<br>proc box format can also be "proc:P:G:W" W=(0-100 or default) for proc width percentage.<br>Stock btop 1.4.7 rejects a presets value holding a W field and resets presets to its default.<br>Use whitespace " " as separator between different presets.<br>Example: "cpu:0:default,mem:0:tty,proc:1:default cpu:0:braille,proc:0:tty" |
| `vim_keys` | bool | `false` | `true`, `false` | 0 general › Terminal and input | Set to True to enable "h,j,k,l,g,G" keys for directional control in lists.<br>Conflicting keys for h:"help" and k:"kill" is accessible while holding shift. |
| `disable_mouse` | bool | `false` | `true`, `false` | 0 general › Terminal and input | Disable all mouse events. |
| `rounded_corners` | bool | `true` | `true`, `false` | 0 general › Runtime and rendering | Rounded corners on boxes, is ignored if TTY mode is ON. |
| `terminal_sync` | bool | `true` | `true`, `false` | 0 general › Runtime and rendering | **Accepted for btop.conf compatibility, not used yet.** Use terminal synchronized output sequences to reduce flickering on supported terminals. |
| `graph_symbol` | string | `"braille"` | `braille`, `block`, `block2`, `tty` | 0 general › Graph glyphs | Default symbols to use for graph creation, "braille", "block", "block2" or "tty".<br>"braille" offers the highest resolution but might not be included in all fonts.<br>"block" has half the resolution of braille but uses more common characters.<br>"block2"'s resolution is between braille and block, but it has a cleaner look than braille (needs a font with Unicode 13 sextants).<br>"tty" uses only 3 different symbols but will work with most fonts and should work in a real TTY.<br>Note that "tty" only has half the horizontal resolution of the other two, so will show a shorter historical view.<br>Stock btop 1.4.7 does not know "block2" and falls back to its default with a warning. |
| `graph_symbol_cpu` | string | `"default"` | `default`, `braille`, `block`, `block2`, `tty` | 0 general › Graph glyphs | Graph symbol to use for graphs in cpu box, "default", "braille", "block", "block2" or "tty". |
| `graph_symbol_gpu` | string | `"default"` | `default`, `braille`, `block`, `block2`, `tty` | 0 general › Graph glyphs | Graph symbol to use for graphs in gpu box, "default", "braille", "block", "block2" or "tty". |
| `graph_symbol_mem` | string | `"default"` | `default`, `braille`, `block`, `block2`, `tty` | 0 general › Graph glyphs | Graph symbol to use for graphs in mem box, "default", "braille", "block", "block2" or "tty". |
| `graph_symbol_net` | string | `"default"` | `default`, `braille`, `block`, `block2`, `tty` | 0 general › Graph glyphs | Graph symbol to use for graphs in net box, "default", "braille", "block", "block2" or "tty". |
| `graph_symbol_proc` | string | `"default"` | `default`, `braille`, `block`, `block2`, `tty` | 0 general › Graph glyphs | Graph symbol to use for graphs in proc box, "default", "braille", "block", "block2" or "tty". |
| `shown_boxes` | string | `"cpu mem net proc"` | validated text (see description) | 0 general › Layout | Manually set which boxes to show. Available values are "cpu mem net proc ctr" and "gpuN" for GPU index N, separate values with whitespace. Up to 6 GPU boxes can be shown at once. |
| `update_ms` | int | `2000` | `100`–`86400000` | 0 general › Runtime and rendering | Update time in milliseconds, recommended 2000 ms or above for better sample times for graphs. |
| `proc_sorting` | string | `"cpu lazy"` | `pid`, `name`, `command`, `threads`, `user`, `memory`, `cpu direct`, `cpu lazy`, `io read`, `io write`, `io total`, `gpu`, `gpu memory` | 4 proc › Order and tree | Processes sorting, "pid" "name" "command" "threads" "user" "memory" "cpu lazy" "cpu direct" "io read" "io write" "io total" "gpu" "gpu memory",<br>"cpu lazy" sorts top process over time (easier to follow), "cpu direct" updates top process directly.<br>"io read", "io write" and "io total" sort by disk IO rate, "gpu" and "gpu memory" by per-process GPU use; stock btop 1.4.7 does not know them and falls back to "cpu lazy" with a warning. |
| `proc_reversed` | bool | `false` | `true`, `false` | 4 proc › Order and tree | Reverse sorting order, True or False. |
| `proc_tree` | bool | `false` | `true`, `false` | 4 proc › Order and tree | Show processes as a tree. |
| `proc_command_basename` | bool | `false` | `true`, `false` | 4 proc › Rows and selection | Show only the executable basename in process commands, preserving arguments.<br>The detailed view still shows the full command. |
| `proc_tree_persist_state` | bool | `false` | `true`, `false` | 4 proc › Order and tree | Persist manual tree expand/collapse choices by process-name ancestry across runs.<br>Stored in $XDG_STATE_HOME/candy-top/tree-state.json, not in this file. |
| `proc_colors` | bool | `true` | `true`, `false` | 4 proc › Rows and selection | Use the cpu graph colors in the process list. |
| `proc_gradient` | bool | `true` | `true`, `false` | 4 proc › Rows and selection | Use a darkening gradient in the process list. |
| `proc_per_core` | bool | `false` | `true`, `false` | 4 proc › Rows and selection | If process cpu usage should be of the core it's running on or usage of the total available cpu power. |
| `proc_mem_bytes` | bool | `true` | `true`, `false` | 4 proc › Rows and selection | Show process memory as bytes instead of percent. |
| `proc_cpu_graphs` | bool | `true` | `true`, `false` | 4 proc › Rows and selection | Show cpu graph for each process. |
| `proc_gpu_graphs` | bool | `true` | `true`, `false` | 4 proc › Rows and selection | Show gpu graph for each process. |
| `proc_gpu_only` | bool | `false` | `true`, `false` | 4 proc › Rows and selection | Show only processes with active GPU usage or GPU memory allocation in the process list. |
| `proc_info_smaps` | bool | `false` | `true`, `false` | 4 proc › Rows and selection | **Accepted for btop.conf compatibility, not used yet.** Use /proc/[pid]/smaps for memory information in the process info box (very slow but more accurate) |
| `proc_box_width_percent` | int | `55` | `0`–`100` | 0 general › Layout | Percentage value for proc box width when mem or net is shown.<br>Values 0-100; the width is clamped to the narrowest/widest layout the window allows. |
| `proc_left` | bool | `false` | `true`, `false` | 0 general › Layout | Show proc box on left side of screen instead of right. |
| `proc_filter_kernel` | bool | `false` | `true`, `false` | 4 proc › Rows and selection | (Linux) Filter processes tied to the Linux kernel(similar behavior to htop). |
| `proc_filter_containers` | bool | `false` | `true`, `false` | 4 proc › Rows and selection | (Linux) Hide processes running in containers (docker, podman, kubernetes, lxc, systemd-nspawn...) from the process list. |
| `ctr_show_vms` | bool | `true` | `true`, `false` | 4 proc › Rows and selection | (Linux) Show libvirt/KVM virtual machines in the containers box, beside the containers. |
| `proc_follow_detailed` | bool | `true` | `true`, `false` | 4 proc › Rows and selection | Should the process list follow the selected process when detailed view is open. |
| `proc_aggregate` | bool | `false` | `true`, `false` | 4 proc › Order and tree | In tree-view, always accumulate child process resources in the parent process. |
| `proc_tree_auto_collapse` | int | `0` | `0`–`10000` | 4 proc › Order and tree | In tree-view, auto-collapse processes with this many or more direct children when<br>entering tree mode. 0 to disable. Useful for collapsing multi-process apps like browsers. |
| `keep_dead_proc_usage` | bool | `false` | `true`, `false` | 4 proc › Rows and selection | **Accepted for btop.conf compatibility, not used yet.** Should cpu and memory usage display be preserved for dead processes when paused. |
| `cpu_graph_upper` | string | `"Auto"` | any text | 1 cpu › Graphs | Sets the CPU stat shown in upper half of the CPU graph, "total" is always available.<br>Select from a list of detected attributes from the options menu. |
| `cpu_graph_lower` | string | `"Auto"` | any text | 1 cpu › Graphs | Sets the CPU stat shown in lower half of the CPU graph, "total" is always available.<br>Select from a list of detected attributes from the options menu. |
| `show_gpu_info` | string | `"Auto"` | `Auto`, `On`, `Off` | 1 cpu › Graphs | If gpu info should be shown in the cpu box. Available values = "Auto", "On" and "Off". |
| `gpu_box_columns` | string | `"Auto"` | validated text (see description) | 5 gpu › Display | How many gpu boxes to place side by side instead of stacking them vertically.<br>"Auto" uses as many columns as the terminal width allows, or set a number (1-6) to force it. |
| `cpu_invert_lower` | bool | `true` | `true`, `false` | 1 cpu › Graphs | Toggles if the lower CPU graph should be inverted. |
| `cpu_single_graph` | bool | `false` | `true`, `false` | 1 cpu › Graphs | Set to True to completely disable the lower CPU graph. |
| `cpu_bottom` | bool | `false` | `true`, `false` | 0 general › Layout | Show cpu box at bottom of screen instead of top. |
| `show_uptime` | bool | `true` | `true`, `false` | 1 cpu › CPU status | Shows the system uptime in the CPU box. |
| `show_cpu_watts` | bool | `true` | `true`, `false` | 1 cpu › CPU status | **Accepted for btop.conf compatibility, not used yet.** Shows the CPU package current power consumption in watts. Requires read access to the RAPL energy counters. |
| `check_temp` | bool | `true` | `true`, `false` | 1 cpu › Temperature sensors | Show cpu temperature. |
| `cpu_sensor` | string | `"Auto"` | any text | 1 cpu › Temperature sensors | **Restart needed.** Which sensor to use for cpu temperature, use options menu to select from list of available sensors. |
| `show_coretemp` | bool | `true` | `true`, `false` | 1 cpu › Temperature sensors | Show temperatures for cpu cores also if check_temp is True and sensors has been found. |
| `cpu_core_map` | string | `""` | validated text (see description) | 1 cpu › Temperature sensors | Set a custom mapping between core and coretemp, can be needed on certain cpus to get correct temperature for correct core.<br>Use lm-sensors or similar to see which cores are reporting temperatures on your machine.<br>Format "x:y" x=core with wrong temp, y=core with correct temp, use space as separator between multiple entries.<br>Example: "4:0 5:1 6:3" |
| `temp_scale` | string | `"celsius"` | `celsius`, `fahrenheit`, `kelvin`, `rankine` | 1 cpu › Temperature sensors | Which temperature scale to use, available values: "celsius", "fahrenheit", "kelvin" and "rankine". |
| `base_10_sizes` | bool | `false` | `true`, `false` | 0 general › Status and units | Use base 10 for bits/bytes sizes, KB = 1000 instead of KiB = 1024. |
| `show_cpu_freq` | bool | `true` | `true`, `false` | 1 cpu › CPU status | Show CPU frequency. |
| `freq_mode` | string | `"first"` | `first`, `range`, `lowest`, `highest`, `average` | 1 cpu › CPU status | **Restart needed.** How to calculate CPU frequency, available values: "first", "range", "lowest", "highest" and "average". |
| `show_core_freq` | string | `"off"` | `off`, `value`, `graph` | 1 cpu › CPU status | Show per-core CPU frequency, available values: "off", "value", "graph". |
| `clock_format` | string | `"%X"` | any text | 0 general › Status and units | Draw a clock at top of screen, formatting according to strftime, empty string to disable.<br>Special formatting: /host = hostname \| /user = username \| /uptime = system uptime |
| `background_update` | bool | `true` | `true`, `false` | 0 general › Status and units | Update main ui in background when menus are showing, set this to false if the menus is flickering too much for comfort. |
| `custom_cpu_name` | string | `""` | any text | 1 cpu › CPU status | Custom cpu model name, empty string to disable. |
| `disks_filter` | string | `""` | any text | 2 mem › Disk selection | Optional filter for shown disks, should be full path of a mountpoint, separate multiple values with whitespace " ".<br>Only disks matching the filter will be shown. Prepend exclude= to only show disks not matching the filter. Examples: disks_filter="/boot /home/user", disks_filter="exclude=/boot /home/user" |
| `disks_order` | string | `""` | any text | 2 mem › Disk selection | Optional custom display order for disks, full mountpoint paths separated by whitespace " ". Use "swap" to position the swap entry.<br>Listed disks are shown first in the given order, any remaining disks follow in their default order. Example: disks_order="/ swap /home" |
| `mem_graphs` | bool | `true` | `true`, `false` | 2 mem › Memory and swap | Show graphs instead of meters for memory values. |
| `mem_selected` | string | `"default"` | `default`, `used`, `available`, `cached`, `free`, `swap_used` | 2 mem › Memory and swap | Focuses only one kind of memory metric, available values: "default", "used", "available", "cached", "free", or "swap_used". |
| `mem_below_net` | bool | `false` | `true`, `false` | 0 general › Layout | Show mem box below net box instead of above. |
| `zfs_arc_cached` | bool | `true` | `true`, `false` | 2 mem › Disk selection | **Restart needed.** Count ZFS ARC in cached and available memory. |
| `show_swap` | bool | `true` | `true`, `false` | 2 mem › I/O graphs | If swap memory should be shown in memory box. |
| `show_zswap` | bool | `true` | `true`, `false` | 2 mem › I/O graphs | (Linux) If zswap usage should be shown in memory box. |
| `swap_disk` | bool | `true` | `true`, `false` | 2 mem › I/O graphs | Show swap as a disk, ignores show_swap value above, inserts itself after first disk. |
| `show_disks` | bool | `true` | `true`, `false` | 2 mem › Memory and swap | If mem box should be split to also show disks info. |
| `only_physical` | bool | `true` | `true`, `false` | 2 mem › Disk selection | Filter out non physical disks. Set this to False to include network disks, RAM disks and similar. |
| `use_fstab` | bool | `true` | `true`, `false` | 2 mem › Disk selection | Read disks list from /etc/fstab. This also disables only_physical. |
| `zfs_hide_datasets` | bool | `false` | `true`, `false` | 2 mem › Disk selection | Setting this to True will hide all datasets, and only show ZFS pools. (IO stats will be calculated per-pool) |
| `disk_free_priv` | bool | `false` | `true`, `false` | 2 mem › Disk selection | **Accepted for btop.conf compatibility, not used yet.** Set to true to show available disk space for privileged users. |
| `show_io_stat` | bool | `true` | `true`, `false` | 2 mem › I/O graphs | Toggles if io activity % (disk busy time) should be shown in regular disk usage view. |
| `io_mode` | bool | `false` | `true`, `false` | 2 mem › I/O graphs | Toggles io mode for disks, showing big graphs for disk read/write speeds. |
| `io_graph_combined` | bool | `false` | `true`, `false` | 2 mem › I/O graphs | Set to True to show combined read/write io graphs in io mode. |
| `io_graph_speeds` | string | `""` | validated text (see description) | 2 mem › I/O graphs | Set the top speed for the io graphs in MiB/s (100 by default), use format "mountpoint:speed" separate disks with whitespace " ".<br>Example: "/mnt/media:100 /:20 /boot:1". |
| `swap_upload_download` | bool | `false` | `true`, `false` | 3 net › Graphs and scale | Swap the positions of the upload and download speed graphs. When true, upload will be on top. |
| `net_download` | int | `100` | `0`–`2147483647` | 3 net › Graphs and scale | Set fixed values for network graphs in Mebibits. Is only used if net_auto is also set to False. |
| `net_upload` | int | `100` | `0`–`2147483647` | 3 net › Graphs and scale | — |
| `net_auto` | bool | `true` | `true`, `false` | 3 net › Graphs and scale | Use network graphs auto rescaling mode, ignores any values set above and rescales down to 10 Kibibytes at the lowest. |
| `net_sync` | bool | `true` | `true`, `false` | 3 net › Graphs and scale | Sync the auto scaling for download and upload to whichever currently has the highest scale. |
| `net_iface` | string | `""` | any text | 3 net › Interface and units | Starts with the Network Interface specified here. |
| `base_10_bitrate` | string | `"Auto"` | `Auto`, `True`, `False` | 3 net › Interface and units | "True" shows bitrates in base 10 (Kbps, Mbps). "False" shows bitrates in binary sizes (Kibps, Mibps, etc.). "Auto" uses base_10_sizes. |
| `net_hide_ip` | bool | `false` | `true`, `false` | 3 net › Interface and units | Toggles ip address visibility in the net box. |
| `show_battery` | bool | `true` | `true`, `false` | 0 general › Status and units | Show battery stats in top right if battery is present. |
| `selected_battery` | string | `"Auto"` | any text | 0 general › Status and units | Which battery to use if multiple are present. "Auto" for auto detection. |
| `show_battery_watts` | bool | `true` | `true`, `false` | 0 general › Status and units | Show power stats of battery next to charge indicator. |
| `log_level` | string | `"WARNING"` | `DISABLED`, `ERROR`, `WARNING`, `INFO`, `DEBUG` | 0 general › Diagnostics and persistence | **Accepted for btop.conf compatibility, not used yet.** Set loglevel for "~/.local/state/candy-top.log" levels are: "ERROR" "WARNING" "INFO" "DEBUG".<br>The level set includes all lower levels, i.e. "DEBUG" will show all logging info. |
| `save_config_on_exit` | bool | `true` | `true`, `false` | 0 general › Diagnostics and persistence | Automatically save current settings to config file on exit. |
| `nvml_measure_pcie_speeds` | bool | `true` | `true`, `false` | 5 gpu › Telemetry | **Accepted for btop.conf compatibility, not used yet.** Measure PCIe throughput on NVIDIA cards, may impact performance on certain cards. |
| `rsmi_measure_pcie_speeds` | bool | `true` | `true`, `false` | 5 gpu › Telemetry | **Accepted for btop.conf compatibility, not used yet.** Measure PCIe throughput on AMD cards, may impact performance on certain cards. |
| `gpu_mirror_graph` | bool | `true` | `true`, `false` | 5 gpu › Display | Horizontally mirror the GPU graph. |
| `shown_gpus` | string | `"nvidia amd intel apple"` | any text | 5 gpu › Display | Set which GPU vendors to show. Available values are "nvidia amd intel apple" |
| `custom_gpu_name0` | string | `""` | any text | 5 gpu › Names | Custom gpu0 model name, empty string to disable. |
| `custom_gpu_name1` | string | `""` | any text | 5 gpu › Names | Custom gpu1 model name, empty string to disable. |
| `custom_gpu_name2` | string | `""` | any text | 5 gpu › Names | Custom gpu2 model name, empty string to disable. |
| `custom_gpu_name3` | string | `""` | any text | 5 gpu › Names | Custom gpu3 model name, empty string to disable. |
| `custom_gpu_name4` | string | `""` | any text | 5 gpu › Names | Custom gpu4 model name, empty string to disable. |
| `custom_gpu_name5` | string | `""` | any text | 5 gpu › Names | Custom gpu5 model name, empty string to disable. |
<!-- END generated:config -->

## Themes

The default theme is **`pastel`**, candy-top's own: bright candy-shop pastels (bubblegum pink, lavender, electric mint, glowing peach, sky blue and lemon) on a deep plum-ink background. Load and temperature run mint → lemon → pink, the cpu graph sky → lavender → pink, and each box outline flows from one pastel into the next.

![candy-top with the pastel theme](https://raw.githubusercontent.com/detain/sugarcraft/master/candy-top/.assets/theme-pastel.png)

This is a deliberate deviation from btop, whose default is its builtin `Default` theme. `Default` is still in the list, and a btop config that sets `color_theme` (for example btop's own `color_theme = "Default"`) keeps its theme. `pastel` paints its own background, so it looks the same on dark and light terminals. With `theme_background = false` it uses the terminal's background instead, which suits dark terminals only: on a light one the near-white text is unreadable, so choose a light theme there (`flexoki-light`, `paper`, `solarized_light`, ...). Without `truecolor` its colours are mapped to the nearest 256-colour entries and the outlines are drawn flat. A side-by-side of `Default`, `mellow` and `pastel` is in [`.assets/theme-compare.png`](.assets/theme-compare.png).

`color_theme` names a btop/bpytop/bashtop `.theme` file, or one of the builtins `Default` and `TTY`. Themes are read from two directories:

1. The user directory, `$XDG_CONFIG_HOME/candy-top/themes`, or `~/.config/candy-top/themes`.
2. The bundled `candy-top/themes/` directory.

The list starts with `Default` and `TTY`, then every `*.theme` file from both directories in a single list sorted by file name. When both directories hold the same file name, both copies are listed, and the user copy comes first and wins every lookup by name.

To add a theme, drop any btop `.theme` file (`theme[key]="#rrggbb"` lines; `"r g b"` triplets and `#gg` greys also work) into the user directory, then pick it with `←`/`→` on `color_theme` in the options menu, or set `color_theme = "name"`. A user file with the same name as a bundled one shadows the bundled theme; the bundled copy stays in the list and can still be selected there (it is then saved by full path). The theme list is scanned at startup and again on `ctrl+r`. An unknown or unreadable theme falls back to `Default`. `theme_background = false` drops the theme's background for a transparent terminal.

<!-- BEGIN generated:themes -->
43 bundled theme files (`candy-top/themes/`) plus the builtin `Default` and `TTY`:

`Default` · `TTY` · `HotPurpleTrafficLight` · `adapta` · `adwaita-dark` · `adwaita` · `ayu` · `dracula` · `dusklight` · `elementarish` · `everforest-dark-hard` · `everforest-dark-medium` · `everforest-light-medium` · `flat-remix-light` · `flat-remix` · `flexoki-dark` · `flexoki-light` · `gotham` · `greyscale` · `gruvbox_dark` · `gruvbox_dark_v2` · `gruvbox_light` · `gruvbox_material_dark` · `horizon` · `kanagawa-dragon` · `kanagawa-lotus` · `kanagawa-wave` · `kyli0x` · `matcha-dark-sea` · `mellow` · `monokai` · `night-owl` · `nord` · `onedark` · `orange` · `paper` · `pastel` · `phoenix-night` · `solarized_dark` · `solarized_light` · `tokyo-night` · `tokyo-storm` · `tomorrow-night` · `twilight` · `whiteout`
<!-- END generated:themes -->

### Box outline flows

candy-top reads four pairs of optional theme keys that btop does not have: `cpu_box_mid`/`cpu_box_end`, `mem_box_mid`/`mem_box_end`, `net_box_mid`/`net_box_end` and `proc_box_mid`/`proc_box_end`. When a box's `_end` key is set, its outline sweeps diagonally from `<box>_box` at the top-left corner, through `_mid` (optional) to `_end` at the bottom-right. Every line drawn in the box colour flows: the outline, title and button junctions, the clock and battery junctions, and the dividers inside the box. Inner sub-box lines drawn in `div_line` (the cpu info box, the mem/disks split, the net stats box, the proc detail view and the gpu stats box) get a quieter echo of the same flow. Titles, hotkeys and labels keep their own colours. The gpu boxes follow the `cpu` flow and the ctr box follows the `proc` flow, as they already use those box colours.

```ini
theme[cpu_box]="#bfa3ff"
theme[cpu_box_mid]="#ff9ad2"
theme[cpu_box_end]="#ffc49a"
```

Flows are drawn in truecolor only. With 256 colours, the 16-colour `TTY` theme or `tty_mode`, the outline uses the flat `<box>_box` colour. btop ignores these keys, so a theme that uses them still loads in btop with flat outlines. Themes that do not set them, which includes `Default` and every btop theme, look exactly as they do in btop.

The bundled themes are ported from btop (Apache-2.0; see `themes/LICENSE` and `themes/README.md`). `mellow` comes from btop PR #1683. `pastel` is original to candy-top. Theme colours are rendered byte-exact against btop's theme engine (see `tests/Theme/BtopThemeOracleTest.php`).

## TTY mode

TTY mode is btop's mode for the Linux console and other limited terminals:

- It switches to the 16-colour `TTY` theme, whose gradients are stepped at 33/66 rather than interpolated.
- Every graph is forced to the `tty` symbol set and box corners are square.
- The dimmed frame behind menus is always frozen.

It turns on automatically when stdin is a real console, meaning `posix_ttyname()` starts with `/dev/tty` (`/dev/tty1`, a serial `/dev/ttyS0`, ...). Set `force_tty = true` to force it anywhere. There is no command-line flag; turning `force_tty` on in the options menu applies TTY mode at once, except on a real console, where it is always on.

## Data sources and permissions

Collectors are chosen per OS at run time (`SugarCraft\Top\Collect\Platform`). They never fail: a value that cannot be read becomes "not measured" and is shown as blank or `n/a`, never as `0`. A briefly failing reading keeps the last good value (btop #1008).

### Linux

| Box | Source |
|---|---|
| cpu | `/proc/stat` (per-core jiffies), `/proc/loadavg`, `/proc/uptime`; frequency from `/sys/devices/system/cpu/cpufreq/policy*/scaling_cur_freq` (per core from `cpuN/cpufreq`), falling back to `/proc/cpuinfo`; temperatures from `/sys/class/hwmon` (coretemp/k10temp-style `temp*_input`), falling back to `/sys/class/thermal`; battery from `/sys/class/power_supply` |
| mem | `/proc/meminfo` (zswap: `Zswap`/`Zswapped`), ZFS ARC from `/proc/spl/kstat/zfs/arcstats` |
| disks | `/proc/mounts` + statvfs, `/proc/diskstats`, `/sys/block` (physical filter), `/etc/fstab` (cached by mtime) |
| net | `/proc/net/dev`, `/sys/class/net/<if>/{carrier,operstate}`, addresses via `net_get_interfaces()` |
| proc | `/proc/[pid]/{stat,status,cmdline,cgroup,io}`, with `cwd` read for the detailed pid only |
| ctr | the proc scan's cgroup tags; `/sys/fs/cgroup/<container>/{cpu.stat,memory.current,memory.stat,memory.max}`; the docker socket for names |
| gpu | `nvidia-smi` (NVIDIA); amdgpu, i915/xe, intel_vpu and amdxdna nodes under `/sys/class/drm` and `/sys/class/accel`; per-process GPU use from `nvidia-smi` and `/proc/[pid]/fdinfo` (see [GPU](#gpu)) |

Permissions:

- Everything works unprivileged.
- `/proc/[pid]/io` and `cwd` of other users' processes need root or `CAP_SYS_PTRACE`. Without it, the IO columns show `-` and the cwd is empty. The same applies to `/proc/[pid]/fdinfo`, so without it the per-process GPU columns on AMD and Intel cover only your own processes.
- Signals and lowering nice values on other users' processes need the usual privileges. A refusal opens btop's error box.

Per-process cmdline, status and cgroup are read once per process lifetime. Only `stat` is re-read each tick, plus `io` while the IO columns show (box at least 90 columns wide, 106 once per-process GPU data is measured) or an `io *` sort is active.

### FreeBSD

On FreeBSD (`PHP_OS` = `FreeBSD`) a separate collector family reads the same figures through `sysctl(8)` and base-system tools: `kern.cp_times`, `vm.loadavg`, `kern.boottime`, `vm.stats.*`, `swapinfo -k`, `netstat -i -b -n -W` with `ifconfig -a`, `iostat -x -I`, `mount -p` with statvfs, `ps`, `procstat -f` (detail cwd), `dev.cpu.N.{freq,temperature}`, `hw.acpi.thermal.*` and `hw.acpi.battery.*`. Each tool runs as a short, time-bounded child with `LC_ALL=C`. The memory classes use the semantics of the four open FreeBSD upstream PRs (#1851, #1728, #1830, #1787): laundry counts as used, and cached is the buffer cache plus the reclaimable ARC.

The FreeBSD output parsing for `ps`, `netstat -W`, `ifconfig` and `iostat` has been checked against the man pages; captures from a live host are still pending.

Known limitations on FreeBSD:

- No per-process IO (FreeBSD exposes only block-op counts), so the IO columns show `-`.
- GPUs come from `nvidia-smi` only (no AMD, Intel or NPU readings).
- No zswap.
- No container or jail tags, so the containers box stays empty.
- One aggregate battery (`acpi`).
- With `security.bsd.see_other_uids=0`, an unprivileged user sees only their own processes.
- CPU interrupt time is counted as `irq` (busy) rather than dropped as btop's FreeBSD port does.
- ACPI thermal zones count as sensors even without coretemp.

### Other systems

Any other OS uses the Linux collectors, which read "not measured" wherever `/proc` and `/sys` are missing. The UI runs, but most readings stay blank.

## Containers and VMs

Each process's `/proc/[pid]/cgroup` is parsed (btop PR #1873). A process inside a container shows an `[engine:name]` tag at the start of its command column. The engines are docker, podman, k8s, containerd, lxc/Incus, systemd-nspawn, and others named by their OCI cgroup prefix. A nested container is attributed to the outermost one. The tag text is restricted to `[A-Za-z0-9_.:-]`.

KVM/QEMU guests are tagged too, with engine `kvm`. This candy-top extension is not a btop feature. A guest is recognised from its libvirt/machined cgroup scope (`machine-qemu\x2d<id>\x2d<name>.scope`, `qemu-<id>-<name>.libvirt-qemu`) or from a QEMU command line (`-name guest=...`, `-uuid`, `-smp`, `-m`). It is never recognised by the executable name alone. The guest name is shown in the program column, and helpers that share the scope (swtpm, virtiofsd) carry the VM's tag.

`O` toggles `proc_filter_containers`, which hides containers and VMs alike. The text filter also matches the container or VM name.

The [containers box](#ctr) groups the tagged processes per container and per libvirt guest and adds each one's own cgroup figures (`ctr_show_vms` turns the guests off). A machined scope is a VM exactly when it reads as `qemu-<id>-<name>` once its escapes are decoded, so an nspawn machine merely named like qemu (`machine-qemubox.scope`) is an nspawn container.

## GPU

GPUs and NPUs are read by a multi-vendor collector (`Collect\Gpu\Accelerators`), a PHP re-implementation of btop's `Gpu::collect` without its NVML/ROCm/PMU libraries, which would need FFI. Several vendors at once are normal, such as an Intel iGPU next to an NVIDIA card. The backends present are picked on the first sample:

- **NVIDIA**: an `nvidia-smi` shell-out for utilisation, memory, temperature, power and power limit, clocks, P-state, fan and encoder/decoder use. Every candidate binary is tried before the GPU is given up as absent: each `PATH` hit, then the WSL2 location `/usr/lib/wsl/lib/nvidia-smi` and container-toolkit locations (btop #1869). A GPU-less host stops spawning after that. Queries are at least 5 s apart, run as background children whose output the event loop collects (the UI never waits for them), are killed after 2 s, and are backed off exponentially after a failure, so a wedged driver never freezes the UI.
- **AMD** (btop #1854): amdgpu sysfs nodes (busy percentages, VRAM, hwmon temperature, power, clocks, fan). Names come from `amdgpu.ids`, then `pci.ids`. A card in runtime suspend is not woken.
- **Intel** (btop #1888): every i915 and xe card, with utilisation from DRM fdinfo, clocks, and power and temperature on discrete cards. VRAM is not read, because neither driver exposes it in sysfs.
- **NPUs**: Intel NPUs through `intel_vpu` sysfs (btop #985: utilisation, memory in use, clock) and AMD Ryzen AI NPUs through `amdxdna` (btop #1839, detection only: the device is listed, every reading `n/a`).

On FreeBSD only the NVIDIA backend runs. `shown_gpus` filters the GPU vendors live (NPUs are never filtered), and `custom_gpu_nameN` renames a device in its gpu box.

GPUs are numbered `0..n-1` in backend order (NVIDIA, AMD, Intel) and NPUs number after them. A device keeps its index when its backend briefly drops it, so its graphs never jump to another device. One shared sampler feeds the [gpu boxes](#gpu-boxes), the cpu box's [GPU sub-graphs](#cpu) and the proc box's [GPU columns](#proc), so they all show the same snapshot and the host is sampled once per update however many of them are shown.

A device that stops answering keeps its last values (btop #1008), but only for a bounded time: once it has measured nothing for `max(5, ceil(30 s / update_ms))` samples in a row, it is shown unmeasured (`n/a`) and its graph history is dropped, so a GPU that is gone for good does not show frozen numbers.

**Cost.** There is one GPU sampler, and it runs only while the cpu box (with GPU info), a gpu box or the proc box's GPU columns are shown and want GPUs (btop #1858). `nvidia-smi` queries at most once per 5 s, whatever the number of boxes. Per-process data is collected only while the proc box wants it (see [proc](#proc)), and stops within two updates after it no longer does. On NVIDIA that adds `nvidia-smi --query-compute-apps` and `nvidia-smi pmon` spawns. `pmon` takes about 0.25-1 s, but every `nvidia-smi` runs in the background: measured on a 4-GPU host, the UI used to stall about 0.7 s every query cycle; now the longest stall is the proc scan's 40-70 ms. The cpu box draws its CPU readings at once and its GPU rows when the query lands. The proc box joins the previous GPU sample instead of waiting, except right after `g` or a gpu sort, when it waits for fresh per-process values. The sysfs reads stay on the UI loop because they are cheap. On AMD and Intel the DRM fdinfo walk is spread across samples. Every `nvidia-smi` child is killed when candy-top exits. A child stuck in the driver ignores even SIGKILL, so after a short wait it is left to `init`.

## Adopted upstream btop PRs

candy-top includes these open btop pull requests (evaluated 2026-10-08). New keys are additive, so a btop.conf loads in candy-top and a candy-top config.conf loads in stock btop. The exceptions are new values (`block2`, the `io *` sorts and the `gpu` / `gpu memory` sorts), which stock btop rejects with a warning before falling back to its default, and the presets W field described above.

| PR | Feature |
|---|---|
| #1869 | Try every `nvidia-smi` candidate (WSL2 path included) before memoising "no GPU". |
| #1730 | GPU boxes for any GPU index: up to 6 `gpuN` boxes, slot keys `5`-`0`, `← gpuN →` title selectors, and the cpu box listing only GPUs without a box under `show_gpu_info = Auto`. |
| #1881 | GPU box grid (`gpu_box_columns`) with Full, Compact and Minimal detail levels. |
| #985 / #1839 | Intel and AMD NPUs, numbered after the GPUs in boxes titled `npu`. The AMD NPU is detected only, without readings. |
| #1854 / #1888 | AMD GPUs from amdgpu sysfs (with `amdgpu.ids` names) and multiple Intel i915/xe GPUs. |
| #1552 | Per-process `Gpu%` / `GMem` columns and mini GPU graph (`proc_gpu_graphs`), `proc_gpu_only` filter (`g`, `ctrl+g`), and `gpu` / `gpu memory` sorting. |
| #1856 | Process names with spaces or parentheses parse correctly (regression fixtures). |
| #1739 | zswap row and on-disk swap `Used` (`show_zswap`). |
| #1785 / #1792 | Per-core CPU frequency (`show_core_freq` = `off`/`value`/`graph`) and the shared frequency label. |
| #1573 | Interface IP address in the net box (`net_hide_ip`). |
| #1859 | Executable basename in process commands (`proc_command_basename`). |
| #1823 | Per-process IO/R and IO/W columns and `io read`/`io write`/`io total` sorting. |
| #1873 | Container tags, `proc_filter_containers` (`O`), and the containers box (`ctr`: `x`, `[`/`]`, row clicks, cgroup v2 figures, docker names). |
| #1783 | `block2` sextant graph symbols (`graph_symbol*`). |
| #1858 | Hidden boxes are never sampled or drawn, and graph sizes are clamped to at least 1. |
| #1614 | GPU sub-graph widths in the cpu box. |
| #1008 | Keep the last value when a collector briefly fails (bounded for GPUs, see [GPU](#gpu)). |
| #1747 | `mem_selected`: a single focused mem graph. |
| #1700 | `disks_order`. |
| #1546 | Process cwd in the detailed view. |
| #1791 | Tree siblings sorted by branch totals (a), grouped option headings (b), and (c) `proc_tree_persist_state`, stored in an XDG state file instead of config.conf. |
| #1476 | `proc_box_width_percent`, Shift/Alt/Ctrl+Shift arrow width keys, and the presets W field. |
| #1411 | Options tabs in box-toggle order (0-5) with digit selection. |
| #1849 | Theme or config reload invalidates every cached render, including the battery meter. |
| #1683 | The `mellow` theme. |
| #1851 / #1728 / #1830 / #1787 | FreeBSD memory and battery fixes, in the FreeBSD collectors. |

## Differences from btop

These are deliberate deviations and gaps. Each phase's full notes are in `CALIBER_LEARNINGS.md`.

**Not implemented (yet)**

- No PCIe TX/RX line in the gpu boxes (nothing measures PCIe throughput), and no Apple GPUs.
- AMD NPUs are detected but not measured; Intel GPUs report no VRAM.
- No `ctrl+z` suspend.
- No CPU package watts (`show_cpu_watts` has no RAPL reader).
- No ZFS pool IO.
- No MAC-address fallback for an interface without an IP.
- Accepted but not acted on: `terminal_sync` (synchronized output is always on), `log_level` (no log file), `disk_free_priv` (free space is always the unprivileged figure), `keep_dead_proc_usage`, `proc_info_smaps`, and the PCIe keys `nvml_measure_pcie_speeds` and `rsmi_measure_pcie_speeds`. The options menu marks these keys "not used yet".
- `freq_mode`, `cpu_sensor` and `zfs_arc_cached` take effect after a restart; the menu says so.

**Behaves differently**

- Config and themes live under `candy-top` (not `btop`), and saves are atomic.
- A save failure or a reload warning opens a warning box; btop only logs it.
- A failed renice opens the error box; btop ignores it.
- Disk IO is shown in bytes per second; btop shows bytes per update interval.
- The disks section is clipped above the bottom border.
- An unreadable `/etc/fstab` falls back to `only_physical`; btop shows no disks.
- With `cpu_bottom`, the battery badge moves to the bottom border.
- The cpu box draws before the first temperature sample arrives.
- Graphs of a disconnected interface keep rebuilding from history.
- Per-core temperatures map core *n* to sensor *n mod sensors* unless `cpu_core_map` says otherwise; btop reads core ids from cpuinfo.
- Batteries are re-scanned every sample, so a hot-plugged one appears without a restart.
- Mounts whose statvfs failed are retried after a while, not ignored for the process lifetime.
- `proc_filter_containers` also hides VMs, and the text filter also matches container and VM names.
- Containers box (#1873): libvirt/KVM guests are listed beside the containers (engine `kvm`, the domain's configured memory as the limit when `memory.max` is `max`, the vCPU count in the detail title), unless `ctr_show_vms` is off; btop skips every `machine-qemu*` scope. The engine candy-top runs in is detected from more markers than btop's three (`KUBERNETES_SERVICE_HOST`, `container=` in `/proc/1/environ`, `/proc/self/cgroup`, the overlay root in `/proc/self/mountinfo`; `jail` on FreeBSD), systemd's names are shortened, and the label takes the `x ctr` button's own slot cut to fit before the clock and stays clickable (btop prints the whole name two cells further right, with no click zone, whatever the width). Before the first sample the box shows its labels but not "No containers found". `[`/`]` with no containers does not reset the proc selection (btop resets it on every press). Conversely, the proc selection also resets when the container selection is cleared because the container vanished or the box was hidden (btop resets it only on a key or click). Container samples come from the proc box's scans, and one covering less than half of `update_ms` is skipped (btop collects containers on every proc collect). After the box is re-shown, its first cpu% is the sum of the container's processes; btop shows the cgroup delta averaged over the hidden period. The docker reply is capped at 8 MiB and the 1 s timeout bounds the whole exchange. A cgroup path containing `..` is never read. Only a bare left click (not a drag) acts on the box's buttons and rows. A translated `[ select ]` label keeps the button's right edge and splits it into halves. A `machine-qemu…` scope that does not read as `qemu-<id>-<name>` once decoded (for example `machine-qemubox.scope`) is listed as an nspawn container; btop skips every `machine-qemu*` scope.
- Tree siblings are sorted by branch totals (#1791a).
- Remembered tree collapse choices (#1791c) are stored in `$XDG_STATE_HOME/candy-top/tree-state.json`, not in btop's internal `proc_tree_state` config key.
- A remembered tree choice is applied once (at startup, when `proc_tree_persist_state` is turned on, and on entering the tree view), then only to newly appearing processes. btop re-applies it on every collect. So `E` is not undone by the next sample, but a process sharing the name chain of one you just collapsed or expanded is not updated until it is recreated or candy-top restarts.
- The IO columns take exactly the 14 cells they draw, so Cpu% stays in place.
- The `gpu` and `gpu memory` sorts come after `io total` in the sort cycle. btop #1552 inserts them before `cpu direct`; appending them keeps the stock sort positions.
- GPU box drawing is clipped above the box's bottom border; btop overdraws the border when the grid makes a box shorter than its sections.
- A `gpuN` in `shown_boxes` that names an accelerator which is not detected is not drawn, and a preset naming one is refused with the size-error box.
- A GPU that stops answering is held only for `max(5, ceil(30 s / update_ms))` samples, then shown as `n/a`; btop holds its last values indefinitely.
- GPUs are sampled once per update for all boxes, as in btop, but `nvidia-smi` runs in the background, while btop's NVML calls block its collector. So while a query is running, the cpu box's GPU rows arrive a moment after its CPU readings, and the proc box shows the previous sample's per-process GPU values. Per-process data appears one update after the proc box starts wanting it, except after `g` or a gpu sort, which wait for it.
- With a low `update_ms` and a slow `pmon`, the boxes that wait for a GPU sample (the gpu boxes and the cpu box's GPU rows) all get the same snapshot in one burst when the query lands. On a host with more than one GPU vendor, the AMD and Intel sysfs readings are taken in the same sample, so they arrive in that burst too. A vendor removed from `shown_gpus` is still sampled for one more update, because the other boxes' requests from the previous update still name it. A vendor added is sampled from the next sample.
- `F` follow and `u` pause are panel state, not config.
- The banner has no version line.
- The help page indicator sits on the box border.
- Option tab hit-boxes keep btop's fixed widths while the labels are translated.
- The theme list is scanned at startup and on `ctrl+r`, not on every menu open.
- `lowcolor` follows `truecolor` at startup.
- The proc list samples synchronously on the UI loop. Measured on a host with about 1160 processes, a sample takes roughly 40-47 ms with the IO columns off and 56-72 ms with them on.

## Development

```sh
cd candy-top
composer install
vendor/bin/phpunit
```

- **Golden fixtures.** Goldens live under `tests/fixtures/` (frames, panels, overlays). Regenerate them with `CANDY_TOP_UPDATE_GOLDENS=1 vendor/bin/phpunit <test>` and review the diff.
- **README tables.** The key, config and theme blocks above are generated. After a deliberate roster change, run `CANDY_TOP_UPDATE_DOCS=1 vendor/bin/phpunit tests/Docs` and review the README diff.
- **i18n.** User-facing strings are `Lang::t()` keys in `lang/en.php`.
- **Architecture.** For the architecture (Model–Update–View runtime, panel seam, input precedence, overlays) and implementation notes, read `CALIBER_LEARNINGS.md` at the monorepo root.

## License

MIT. The bundled themes and the ported btop logic are Apache-2.0 (Copyright 2021 Aristocratos); see `themes/LICENSE`.

## Credits & inspiration

Design antecedent: [aristocratos/btop](https://github.com/aristocratos/btop); SugarCraft is developed as a native PHP project.

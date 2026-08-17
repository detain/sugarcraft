# VHS tape — the shipped entry point, `bin/sugarcrush`, answering `--help`.
#
# The other four tapes drive `php examples/*.php` for determinism: the binary
# reads the developer's real ~/.sugar-crush/config.json and can reach a live
# provider, so its FRAMES differ per machine and per API key. That left the
# thing users actually run covered by no tape at all, which this fixes on the
# one surface of the binary that cannot vary:
#
#   * `--help` is dispatched from bin/sugarcrush BEFORE Bootstrap, before any
#     config read, before the TTY is claimed and before a provider exists (see
#     that script's "Parse argv first and dispatch" comment). Its output is a
#     static string from Cli\Help::screen() — same bytes on every machine.
#   * `--version` is deliberately NOT recorded here even though it shares that
#     property. The reason is the mechanism, not git: Help::version() defers to
#     Help::versionString(), which reads Composer's install metadata —
#     InstalledVersions::getPrettyVersion() plus, for a dev version,
#     getReference() — so the short hash it prints is the commit Composer
#     INSTALLED from, not one read out of git at run time. On a working
#     checkout the two routinely disagree, which is a good demonstration that
#     no git call is involved but no help at all here: CI's `composer install`
#     runs against the checked-out commit, so there the reference does track
#     HEAD, and a tape driving `--version` would rewrite the committed GIF on
#     every single commit. Determinism is the whole reason this tape exists; a
#     per-commit counter would undo it.
#
# `head` trims the output to the title + usage + options block. The
# environment-variable block below it is documented in full in
# docs/ENVIRONMENT.md, which does not have to fit in a terminal frame.
#
# ── Which renderer runs this, and what that fixes in place ─────────────────
#
# The upstream charmbracelet/vhs binary, NOT candy-vcr. In
# .github/workflows/vhs.yml the `render` job carries sugar-crush in its matrix,
# runs `working-directory: ${{ matrix.lib }}`, calls the `vhs` baked into the
# vhs-runner image, and uploads `${{ matrix.lib }}/.vhs/*.gif` as `gifs-<lib>`.
# The `vhs-candy-vcr` job in the same file is an explicitly non-blocking soak
# pinned to `lib: [candy-core]`, and the commit job only downloads
# `pattern: gifs-*` — so candy-vcr's output is never committed and never
# reaches this lib at all.
#
# Two consequences, both load-bearing in every tape here:
#
#   * The working directory is `sugar-crush/`, so a typed path is LIB-relative
#     — `./bin/sugarcrush`, never `./sugar-crush/bin/sugarcrush`. Run from
#     this directory under the bash vhs spawns, the repo-root form fails
#     outright: `bash: ./sugar-crush/bin/sugarcrush: No such file or
#     directory`, and `php sugar-crush/examples/echo-chat.php` answers
#     `Could not open input file`.
#   * `Output` is stored verbatim and vhs never chdirs, so it resolves against
#     that same working directory. The `.vhs/` prefix is what keeps the GIF
#     inside the path the workflow uploads and `git add */.vhs/*.gif` stages;
#     a bare `cli.gif` writes `sugar-crush/cli.gif`, which both miss. Verified
#     by rendering a probe tape at `lib/.vhs/probe.tape` from `lib/`: its
#     `Output out.gif` landed at `lib/out.gif`, not `lib/.vhs/out.gif`.
#
# ── No `Set Shell` ─────────────────────────────────────────────────────────
#
# vhs starts a shell unconditionally — there is no echo path to opt out of and
# none to opt into, so every tape in this directory runs its program for real.
# `Set Shell` only picks WHICH shell, and it is validated against a fixed
# nine-key map (bash, zsh, fish, powershell, pwsh, cmd, nu, osh, xonsh) in a
# pre-pass whose failure aborts the render before ttyd is even started.
# `sh` is not in that map: `vhs cli.tape` exits 1 with
# `failed to execute command: invalid shell sh` (reproduced against
# vhs v0.11.0), and the workflow's render step is a
# `set -euo pipefail; for tape in .vhs/*.tape; do vhs "$tape"; done` loop —
# so one rejected tape takes every later tape, the upload and the whole job
# down with it.
#
# The default needs no help anyway: vhs runs bash with `--noprofile --norc`
# and paints its own `> ` prompt, so there is no ~/.bashrc to source and no
# hostname in the prompt to churn the GIF between re-renders.
Output .vhs/cli.gif

Set FontSize 14
Set Width 1200
# 1200x560 at FontSize 14 is a 117x26 grid. Measured, not derived: vhs sizes
# the grid inside a headless browser, so the only trustworthy number comes
# from rendering a probe tape that ran `stty size` (117x24 at Height 520,
# 117x26 at 560, 117x31 at 640, 117x35 at 700 — all under vhs v0.11.0).
# The recording needs 22 of those rows: the command line, the 20 lines `head`
# lets through, and the prompt drawn underneath. `--help`'s widest line is 78
# columns, comfortably inside 117.
Set Height 560
Set TypingSpeed 60ms
Set Theme "TokyoNight"

# vhs draws its prompt before the tape's first keystroke and screenshots on a
# timer for as long as the tape runs, so this leading Sleep opens on a settled
# prompt and the trailing one below is both the read window for `--help` and
# the hold on the GIF's final frame. Neither needs a keystroke to flush it.
Sleep 1s
Type "./bin/sugarcrush --help | head -20"
Enter
Sleep 4s

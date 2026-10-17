# VHS tape — the media generation form's render states, plus one painted image.
#
# examples/media-demo.php drives the live MediaForm render model headlessly and
# echoes each frame itself: the form opens on Prompt, a prompt is committed
# through the same update() keystroke path the TUI pumps, Tab lands on
# Generation params, Hires. fix is enabled so its accordion opens, a CANNED
# MediaResponse answers a txt2img round (no server is dialled — the PNG bytes
# come from fixed integer arithmetic), those bytes are painted inline through
# sugar-image at halfblock truecolor, and the form closes. Seven beats, one
# screen clear each, ~1.1s apart inside the script; the Sleeps below just track
# that cadence so the GIF reads as states, not scrolling text.
#
# Halfblock is the committed mode on purpose. Kitty and sixel need a
# passthrough the headless ttyd behind vhs does not forward, so a frame built
# in either would record as escape-sequence garbage instead of a picture —
# the script's outro caption says so in-terminal too.
#
# Nothing here touches disk: MediaForm::open() with default arguments performs
# zero I/O, no MediaStore is constructed, and the demo's imagepng() call is
# single-argument, so no GIF, PNG or temp file can appear mid-render.
#
# ── Renderer, working directory, grid ──────────────────────────────────────
#
# .vhs/cli.tape's header carries the evidence; the short version is that CI
# renders this lib's tapes with the upstream charmbracelet/vhs binary from
# `candy-code/`. So the typed path is lib-relative, `Output` keeps its
# `.vhs/` prefix to land inside the artifact path the workflow uploads, and
# there is no `Set Shell` to add — vhs always spawns a shell.
#
# 1200x640 at FontSize 14 is a 117x31 grid (measured with `stty size` under
# vhs v0.11.0). Every beat is a one-line caption above a frame the script fits
# to that grid exactly — the form beats fill 30 rows beneath the caption, the
# painted-image beat is 17 — and no rendered row exceeds 117 columns.
Output .vhs/media-form.gif

Set FontSize 14
Set Width 1200
Set Height 640
Set TypingSpeed 60ms
Set Theme "TokyoNight"

# The leading Sleep opens on a settled prompt, as in every tape here; the
# script then runs ~8s at its own pace and exits, so the trailing beats hold
# the closing frame and the redrawn prompt underneath it.
Sleep 1s
Type "php examples/media-demo.php"
Enter

# Beats 1-2: form opens, prompt committed.
Sleep 3s

# Beats 3-4: Generation params, Hires. fix accordion open.
Sleep 3s

# Beats 5-6: canned MediaResponse status row, then the painted picture.
Sleep 3s

# Beat 7: closing frame, then the script exits.
Sleep 2s
Sleep 1s

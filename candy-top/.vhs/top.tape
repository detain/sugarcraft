# VHS tape — candy-top tour on its deterministic fake sources.
#
# `php examples/top.php` runs bin/candy-top with `--fake` (seeded collectors,
# invented pids — never the recording host's real processes) and a config
# path that never exists, so a developer's ~/.config/candy-top/config.conf
# cannot restyle the frames. Paths are lib-relative: the workflow renders
# from `candy-top/`, and `Output .vhs/top.gif` keeps the GIF where the
# commit job stages it.
#
# Storyline: frame → `+`/`-` update speed → `2` hides/restores the mem box →
# `e` tree view → `f` filter "php" → select + Enter detailed view → Enter
# closes it → Esc main menu → `h` help → `q` quits.
#
# Numeric values (sizes, durations) stay UNQUOTED like every other tape here:
# candy-vcr parses `Set Width "1200"` / `Sleep "1s"` as 1 col / 0s.
Output .vhs/top.gif

Set FontSize 12
Set Width 1200
Set Height 1000
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "php examples/top.php" Sleep 300ms
Enter Sleep 3s

# Update interval: 2000ms → 2200ms → 2100ms (cpu box header).
Type "+" Sleep 700ms
Type "+" Sleep 700ms
Type "-" Sleep 1200ms

# Box toggles: hide mem, then bring it back.
Type "2" Sleep 1500ms
Type "2" Sleep 1500ms

# Proc box: tree view on, then back to the flat list.
Type "e" Sleep 2500ms
Type "e" Sleep 1000ms

# Filter the process list down to php.
Type "f" Sleep 500ms
Type "php" Sleep 800ms
Enter Sleep 1500ms

# Select the match and open its detailed view, then close it.
Down Sleep 600ms
Enter Sleep 3s
Enter Sleep 1000ms

# Main menu, then the help overlay.
Escape Sleep 2500ms
Escape Sleep 600ms
Type "h" Sleep 3s
Escape Sleep 800ms

Type "q" Sleep 1s

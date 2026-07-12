# Exec smoke: prove `Set Shell` actually RUNS the typed command.
# Kept OUT of tests/golden/tapes/ on purpose — not a visual golden, so
# VisualRegressionTest / refresh-goldens never touch it. Rendered under a real
# PTY by FrameStreamExecTest, which asserts the shell's OUTPUT (not just the
# echoed keystrokes) lands in the frames.
Set Theme "TokyoNight"
Set FontSize 14
Set Width 700
Set Height 240
Set TypingSpeed 10ms
Set Shell "sh"
# Arithmetic expansion the shell must EVALUATE: the typed command carries
# `$((6*7))`, but only a shell that RAN it prints `vcr-ran-42`. Echo-only
# rendering could never produce the expanded `42`, so the output line is
# unforgeable proof the command executed.
Type "echo vcr-ran-$((6*7))"
Enter
Sleep 1s

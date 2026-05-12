# Render with: vhs sugar-tick/.vhs/push.tape
# Output: .vhs/push.gif
# Demo of the sugar-tick push CLI command (one-shot heartbeat writer)

Set FontSize 14
Set Width 800
Set Height 200
Set TypingSpeed 40ms
Set Theme "TokyoNight"

Type "SUGARTICK_DIR=/tmp/sugar-tick-test sugar-tick/bin/sugar-tick push sugarcraft php src/App.php 60"
Enter
Sleep 500ms
Type "SUGARTICK_DIR=/tmp/sugar-tick-test sugar-tick/bin/sugar-tick push sugarcraft php src/Model.php 45"
Enter
Sleep 500ms
Type "SUGARTICK_DIR=/tmp/sugar-tick-test sugar-tick/bin/sugar-tick push sugarcraft js assets/app.js 30"
Enter
Sleep 500ms
Type "echo '---Heartbeats written---' && cat /tmp/sugar-tick-test/$(date +%Y-%m-%d).jsonl"
Enter
Sleep 300ms
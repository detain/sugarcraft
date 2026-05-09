# Render with: vhs candy-wish/.vhs/spawn-bash.tape
#
# Demonstrates the InProcessTransport (default) spawning a real
# /bin/bash inside a candy-pty supervisor. The supervisor pumps
# bytes between VHS's terminal and the inner bash's slave PTY, so
# every keystroke / signal / resize behaves as if you were running
# bash directly — except the candy-wish middleware stack (Logger
# in this demo) ran first.
Output .vhs/spawn-bash.gif

Set FontSize 14
Set Width 820
Set Height 480
Set TypingSpeed 80ms
Set Theme "TokyoNight"

# Synthesise the SSH env vars Session::fromEnvironment() reads —
# in production sshd populates these; here VHS does.
Env USER "demo"
Env LOGNAME "demo"
Env TERM "xterm-256color"
Env COLUMNS "100"
Env LINES "28"
Env SSH_CONNECTION "127.0.0.1 12345 127.0.0.1 22"
Env LANG "C.UTF-8"

Type "php examples/spawn-bash.php"
Enter
Sleep 1500ms

# Inside the candy-pty-spawned bash:
Type "echo 'hello from in-process bash'"
Enter
Sleep 500ms

Type "uname -s && pwd"
Enter
Sleep 500ms

Type "stty size  # what the inner shell sees"
Enter
Sleep 500ms

# Exit cleanly — bash exits, supervisor reaps, Logger writes a
# session-end event, candy-wish returns to its caller (this VHS
# terminal) and we're back at the host shell prompt.
Type "exit"
Enter
Sleep 1500ms

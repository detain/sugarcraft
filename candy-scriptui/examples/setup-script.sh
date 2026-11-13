#!/usr/bin/env bash
# Demo of common candy-scriptui flows in a single setup script.
#
#   ./examples/setup-script.sh
#
# Interactive: run it from a terminal. It resolves the launcher once —
# a candy-scriptui on your PATH, else this checkout's bin/ — so the
# example works verbatim in a fresh clone without a global install.

set -euo pipefail

if command -v candy-scriptui >/dev/null 2>&1; then
	UI=candy-scriptui
else
	UI="$(dirname "$0")/../bin/candy-scriptui"
fi

# Choose a shell flavour.
SHELL_CHOICE=$("$UI" choose --header "Pick a shell:" bash zsh fish)
echo "you chose: $SHELL_CHOICE"

# Confirm with branded labels.
if "$UI" confirm \
	--affirmative "Let's go" --negative "Cancel" \
	"Set $SHELL_CHOICE as your default?"; then
	echo "configured"
else
	echo "skipped"
fi

# Capture an email address with a placeholder hint.
EMAIL=$("$UI" input \
	--header "Account info" \
	--prompt "✉  " \
	--placeholder "you@example.com" \
	--char-limit 64)
echo "email: $EMAIL"

# Render a styled status line.
"$UI" style \
	--foreground 10 \
	--bold \
	--border rounded \
	--border-foreground 8 \
	--padding "1 4" \
	"✓ All set up successfully."

# Pretty-print a Markdown blurb.
"$UI" format --theme dark <<'MD'
# Setup complete

You've been registered. Next steps:

- Run `candy-scriptui format README.md` to read the docs.
- Open `candy-scriptui --help` for the full command list.
MD

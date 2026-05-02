#!/usr/bin/env bash
# Demo of common candyshell flows in a single setup script.
#
#   ./examples/setup-script.sh

set -euo pipefail

# Choose a shell flavour.
SHELL_CHOICE=$(candyshell choose --header "Pick a shell:" bash zsh fish)
echo "you chose: $SHELL_CHOICE"

# Confirm with branded labels.
if candyshell confirm \
    --affirmative "Let's go" --negative "Cancel" \
    "Set $SHELL_CHOICE as your default?"; then
    echo "configured"
else
    echo "skipped"
fi

# Capture an email address with validation hint.
EMAIL=$(candyshell input \
    --header "Account info" \
    --prompt "✉  " \
    --placeholder "you@example.com" \
    --char-limit 64)
echo "email: $EMAIL"

# Render a styled status line.
candyshell style \
    --foreground 10 \
    --bold \
    --border rounded \
    --border-foreground 8 \
    --padding "1 4" \
    "✓ All set up successfully."

# Pretty-print a Markdown blurb.
candyshell format --theme dark <<'MD'
# Setup complete

You've been registered. Next steps:

- Run `bin/sugarglow README.md` to read the docs.
- Open `vendor/bin/candyshell --help` for the full command list.
MD

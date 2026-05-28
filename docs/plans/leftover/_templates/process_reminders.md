# Process reminders (read alongside every step file)

These two rules apply to every subagent working on a step file in
`plans/leftover/`. They are duplicated in `_templates/subagent_brief.md`
because they are the most-common failure modes — keep them visible.

## 1. `unset GITHUB_TOKEN` before EVERY `gh` invocation

Always. No exceptions. The ambient `GITHUB_TOKEN` in this environment
is invalid for the SugarCraft remotes; the local keychain auth works
but only when the ambient token has been removed from the env first.

```bash
unset GITHUB_TOKEN && gh pr create --title '…' --body '…'
unset GITHUB_TOKEN && gh pr view --json number -q .number
unset GITHUB_TOKEN && gh pr merge "$PR" --merge --delete-branch
unset GITHUB_TOKEN && gh pr list --state open
```

The pattern above is mandatory for every `gh` call — *including* the
ones you make a few seconds apart. Each `gh` runs in a fresh process;
the previous `unset` does not persist across `&&`-separated commands
in the same shell line if you split them across calls. Re-`unset`
every time.

## 2. End every task on `master` with a clean working tree

The complete subagent cycle for each step is:

```bash
git checkout master && git pull --ff-only        # start from clean master
git checkout -b ai/<slug>                        # branch named in step file
# ... edit files ...
cd <touched-lib> && composer install --quiet && vendor/bin/phpunit
cd /home/sites/sugarcraft
git add <specific files>                         # NEVER -A or .
git commit -m "$(cat <<'EOF'
<lib>: <summary> (leftover-rollout <step ID>)

<body>

EOF
)"                                               # Caliber pre-commit hook syncs
git push -u origin HEAD
unset GITHUB_TOKEN && gh pr create --title '…' --body "$(cat <<'EOF'
…
EOF
)"
PR=$(unset GITHUB_TOKEN && gh pr view --json number -q .number)
unset GITHUB_TOKEN && gh pr merge "$PR" --merge --delete-branch
git checkout master && git pull --ff-only         # END STATE: clean master
```

Verify the end state before stopping:

```bash
git rev-parse --abbrev-ref HEAD     # must print: master
git status --porcelain              # must print: (nothing)
```

If either check fails, the subagent is not done. The next subagent
assumes a clean master tree; leaving anything else is a regression
for whoever runs next.

If you genuinely cannot merge (CI red, blocker that can't be resolved
this turn) — that is fine, but still `git checkout master` before
stopping, leave the feature branch alive on `origin` for the next
session, and append a "Blockers" entry to `updates.md` naming the
branch + step + what the supervisor needs to decide.

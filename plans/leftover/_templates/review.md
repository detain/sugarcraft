# Review subagent — between-step substep

You are reviewing the work of the **just-completed** real step. The
supervisor told you the step ID, the branch name, and the PR number.

Read `_templates/subagent_brief.md` and `updates.md` first.

## Your task

Audit the merged PR for the named step. **Do not write any code.** You
are a fresh pair of eyes whose only output is review findings appended
to `updates.md`.

### Mechanical checks (run these first)

1. `git log -1 --format=%H origin/master` — confirm the PR landed.
2. `git show --stat <merge commit>` — list every file touched.
3. `cd <touched lib> && composer install --quiet && vendor/bin/phpunit`
   — confirm tests pass on master post-merge. Use the PHPUnit watchdog
   pattern from the brief if PTY/FFI tests are involved.
4. `php tools/check-path-repos.php` — confirm path-repo closure green.
5. `composer validate` (without `--strict`) in every modified lib.

If any of (1)–(5) fail, that is the entire review — record under
"Open review findings — <step ID>" with the failure output. The fix
subagent will pick it up.

### Substantive review (only if mechanical checks pass)

Per CLAUDE.md / AGENTS.md / the touched lib's CALIBER_LEARNINGS.md:

- `declare(strict_types=1);` present in every new PHP file?
- New public classes `final` unless contract requires extension?
- `with*()` methods return new instance via `mutate()` (immutability invariant)?
- Bare-named accessors (no `get` prefix)?
- Upstream cite (`Mirrors charmbracelet/<repo>.<Method>`) on ported methods?
- Public readonly properties for state where appropriate?
- User-facing strings go through `Lang::t(...)` rather than raw English?
- Edge cases handled: EINTR retry, EOF detection, `proc_close` -1 guard,
  FFI struct opacity (no field reads), null/empty input, multi-byte
  Unicode, embedded ANSI?
- No comments stating WHAT the code does (only WHY)?
- No new `proc_open` / `posix_isatty` / `stream_isatty` / `shell_exec`
  directly when `candy-pty` already provides the same surface?
- README of the touched lib reflects the new public API?
- New patterns logged in the touched lib's CALIBER_LEARNINGS.md?
- Backwards-compat: no public API removed without a deprecation
  marker (`@deprecated since v0.x, use <new>`).

### Step-specific deliverable check

Re-read the step file the previous subagent worked from. Was every
bullet under "Deliverable" actually delivered? Cross-check the
acceptance criteria. If the step said "delete X", confirm X is gone.

### Write findings

Append a section to `updates.md`:

```markdown
## Open review findings — <step ID>

- [ ] <one-line finding 1>
- [ ] <one-line finding 2>
- [ ] …
```

If nothing's wrong, **do not add the section**. Instead, append a
single line under "Done log":

```
review for step <ID> · clean · <PR#>
```

Stop and return to supervisor. The supervisor decides whether to spawn
a fix subagent (only if findings exist) or skip ahead to tests-ci.

## Process reminders (every subagent, every time)

- `unset GITHUB_TOKEN` **before every** `gh` invocation. Always. No
  exceptions.
- End on `master` with a clean working tree. If you wrote findings to
  `updates.md`, that's a normal commit on master via the pre-commit
  hook — confirm `git status` is clean before stopping. The next
  subagent assumes it starts on `master`.

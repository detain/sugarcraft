# Step 01.10 — candy-vcr RecordCommand polish: cassette doc + SIGINT rescue + env-allow-secrets + htop test

**Source:** `leftover_updates.md` P6.5-LO-01..04 (bundle)
**Branch:** `ai/record-cli-polish`
**Bundle hint:** four small follow-ups on P6.5 Shirley CLI

## Deliverable

Four loose ends on the `candy-vcr record` CLI:

1. Document `--idle-trim` dual-timestamp (`t` + `tRaw`) cassette shape.
2. Add SIGINT to the rescue-handlers set (currently only SIGTERM + SIGHUP).
3. Add `--env-allow-secrets` opt-out flag for `SECRET_KEY_REGEX`.
4. Add `ShirleyHtopTest.php` integration test exercising the alt-screen
   sequence (htop uses alt-screen; bash + vim don't fully exercise it).

## Files

**Create:**
- `candy-vcr/docs/CASSETTE.md` — document the JSONL cassette schema
  including `t`, `tRaw`, `output`, `input`, `resize`, `quit`,
  `windowSize`, header event types.
- `candy-vcr/tests/Integration/ShirleyHtopTest.php` — record
  `htop -n 1`, assert cassette starts with `\x1b[?1049h` (alt-screen
  enter) and ends with corresponding `\x1b[?1049l` on quit. Use
  `markTestSkipped` when `htop` is missing.

**Modify:**
- `candy-vcr/src/Cli/RecordCommand.php`:
  - `installRescueHandlers()` — also register SIGINT alongside SIGTERM
    and SIGHUP.
  - Add `--env-allow-secrets` flag. When set, skip `SECRET_KEY_REGEX`
    filtering. Docblock on the flag must explicitly warn this is a
    footgun for replays in untrusted environments.
- `candy-vcr/README.md` — document `--env-allow-secrets`; link to
  `docs/CASSETTE.md`.

## Acceptance

- `cd candy-vcr && vendor/bin/phpunit --testdox` green.
- `ls candy-vcr/docs/CASSETTE.md` exists.
- `candy-vcr record --help` lists `--env-allow-secrets`.
- ShirleyHtopTest passes locally when htop is installed; gracefully
  skips otherwise.

## Notes

- The rescue-handler SIGINT registration matters because Ctrl-C may
  reach the recorder process on macOS signal-quirk cases (controlling
  terminal is on the child by design, but signals do leak).
- The `htop -n 1` invocation runs htop for one screen refresh then
  exits — fast and deterministic for CI.
- The `--env-allow-secrets` flag is opt-in only; default behaviour
  (strip secrets) is unchanged.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.

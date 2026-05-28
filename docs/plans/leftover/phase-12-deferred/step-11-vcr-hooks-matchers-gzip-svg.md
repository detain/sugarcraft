# Step 12.11 — candy-vcr hooks / custom matchers / gzip / SVG (DEFERRED)

**Source:** `leftover_updates.md` P6.5-LO-06 + candy-vcr research §13
**Branch:** `ai/vcr-research-13-items`
**Deferred per user instruction.**

## Deliverable

The candy-vcr research §13 "extras" set. Most already shipped per the
research-audit findings (Hook system, custom matchers, gzip
compression are present in current `candy-vcr/src/`). Audit what
remains:

- Verify Hook, HookRegistry, MetadataHook, SanitizingHook fully wired.
- Verify Matcher family complete (ContentMatcher, PassthroughMatcher,
  TimingTolerantMatcher).
- Verify CompressedJsonlFormat exists.
- SVG rendering (term-transcript style): **may** be missing. Implement
  if so.

## Files

Audit-only first. If gaps found, fill them.

## Acceptance

- Every research §13 item either ships or has a documented reason for
  not shipping.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.

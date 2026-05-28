# Supervisor playbook — leftover_updates rollout

You are the **supervisor**. Your only job is to spawn subagents in the
right order, hand them their instruction file, and watch them finish.
You do not investigate, edit code, or read source files. You do not
read `plans/leftover_updates.md` or `plans/leftover_updates_later.md` —
the steps below carry every detail a subagent needs.

The only other file you should read regularly is **`updates.md`** in this
directory — subagents write to it when they discover something that
spans steps or must be carried forward. Skim it before spawning each
new subagent.

---

## How to spawn each subagent

Use the `Agent` tool with `subagent_type: general-purpose` unless a
step explicitly names a different subagent. Pass exactly this prompt
(fill in the bracketed placeholders):

```
You are working on the SugarCraft monorepo at /home/sites/sugarcraft.

Your task: <STEP_TITLE>. Read these files before starting:

1. /home/sites/sugarcraft/plans/leftover/_templates/subagent_brief.md
2. /home/sites/sugarcraft/plans/leftover/updates.md
3. /home/sites/sugarcraft/plans/leftover/<STEP_FILE>

Follow the step file's deliverables. Do NOT exceed scope. When done,
either ship a PR per the ship-as-you-go cadence (CLAUDE.md), or — if
you hit a blocker you cannot resolve — append a note to updates.md
under "Blockers" and stop with a clear message to the supervisor.

If you discover work that should be done later but is out of scope for
this step, append it to updates.md under "Carry-forward". Do not silently
expand the step.
```

For between-step subagents (review / fix / tests-ci / docs) the file
path is `_templates/<kind>.md` instead of a step file, and you must
also tell the subagent *which* preceding real step they are reviewing
(by branch name or step ID).

---

## The cycle for every real step

For every `phase-NN/step-MM-*.md` listed under "Master sequence" below,
run this exact five-substep cycle, then move to the next real step:

1. **Real step.** Spawn subagent with the step file. Wait for PR-merged.
2. **Review.** Spawn subagent with `_templates/review.md`. Tell it the
   branch/PR number and the step ID. It produces findings into
   `updates.md` under "Open review findings — <step ID>".
3. **Fix.** Spawn subagent with `_templates/fix.md`. It addresses every
   bullet under "Open review findings — <step ID>" and clears them.
   Ships a follow-up PR if anything changes; otherwise reports
   "no fixes needed" and the supervisor moves on.
4. **Tests + workflows.** Spawn subagent with `_templates/tests-ci.md`.
   It verifies test coverage, updates `.github/workflows/ci.yml` and
   `vhs.yml` matrices if needed, and confirms `tools/check-path-repos.php`
   passes. Ships a PR only if changes are needed.
5. **Documentation.** Spawn subagent with `_templates/docs.md`. It
   updates the touched lib's README, end-user docs, hub-admin docs,
   developer docs (`docs/lib/<slug>.md`), and PHPDoc docblocks. Ships
   a PR if anything changed.

**Hard rules each cycle:**

- Author every commit as `Joe Huss <detain@interserver.net>`.
- `unset GITHUB_TOKEN` **before** every `gh` command (the ambient
  token is invalid; local keychain auth works).
- Branch names: `ai/<slug>-<short>`. The step file names the slug.
- Use the ship-as-you-go cadence: commit → push → `gh pr create` →
  `gh pr merge <N> --merge --delete-branch` → `git checkout master &&
  git pull --ff-only`.
- If any substep above fails, the supervisor stops and reports to the
  user. Do not skip review/fix/tests/docs to "make progress".
- Do not parallelise steps. The project has a documented gotcha about
  concurrent writes to `MATCHUPS.md` / shared READMEs.

---

## Master sequence

Run phases 01 → 11 in order. **Phase 12 (deferred items) is the very
end and may be paused indefinitely** per the user's instruction —
macOS-specific items and Windows ConPTY are explicitly low priority.

### Phase 01 — PTY quickwins (`phase-01-pty-quickwins/`)
1. `step-01-x-windows-stub.md`
2. `step-02-drop-consumer-locks.md`
3. `step-03-pump-idle-vs-sigwinch.md`
4. `step-04-pumpoptions-sshdefault.md`
5. `step-05-drop-stty-from-posixbackend.md`
6. `step-06-slim-deprecated-facades.md`
7. `step-07-realprocess-deletion.md`
8. `step-08-pty-backend-env-var.md`
9. `step-09-pool-react-multipump-expect.md`
10. `step-10-record-cli-polish.md`
11. `step-11-check-path-repos-fix-flag.md`
12. `step-12-signal-forwarder-tests.md`
13. `step-13-mosaic-termios-isatty.md`
14. `step-14-editor-open-posixprocess.md`

### Phase 02 — SSOT foundation (`phase-02-ssot-foundation/`)
1. `step-01-theme-in-candy-sprinkles.md`
2. `step-02-styleparser-in-candy-sprinkles.md`
3. `step-03-candy-palette-probe.md`
4. `step-04-module-aligns-model.md`

### Phase 03 — Sugar-dash (`phase-03-sugar-dash/`)
1. `step-01-grid-reorg-foundation.md`
2. `step-02-grid-reorg-charts.md`
3. `step-03-grid-reorg-events-state.md`
4. `step-04-fix-external-module.md`
5. `step-05-import-canonical-primitives.md`
6. `step-06-modules-rewrite-model.md`
7. `step-07-dashboard-live-example.md`
8. `step-08-weather-module.md`
9. `step-09-notification-queue.md`
10. `step-10-responsive-breakpoint.md`
11. `step-11-plot-draw-cells.md`
12. `step-12-state-split.md`
13. `step-13-chart-dedup.md`
14. `step-14-inline-td-fixes.md`
15. `step-15-vhs-goldens-part-1.md`
16. `step-16-vhs-goldens-part-2.md`
17. `step-17-theme-propagation.md`
18. `step-18-cleanup-migration-scripts.md`

### Phase 04 — SSOT others (`phase-04-ssot-others/`)
1. `step-01-boxer-composes-sprinkles.md`
2. `step-02-stickers-composes-bits.md`
3. `step-03-crumbs-uses-zone.md`

### Phase 05 — i18n rollout (`phase-05-i18n/`)
1. `step-01-sugar-calendar.md`
2. `step-02-sugar-table.md`
3. `step-03-sugar-toast.md`
4. `step-04-sugar-boxer.md`
5. `step-05-sugar-crumbs.md`
6. `step-06-super-candy.md`
7. `step-07-sugar-stash.md`
8. `step-08-sugar-stickers.md`

### Phase 06 — Core / Shell / Wish features (`phase-06-core-shell-wish/`)
1. `step-01-core-subscriptions.md`
2. `step-02-core-screen-stack.md`
3. `step-03-core-component-lifecycle.md`
4. `step-04-core-worker-pool.md`
5. `step-05-shell-discovery-flagspec-valueenum.md`
6. `step-06-shell-enhanced-help.md`
7. `step-07-shell-completions-version-env.md`
8. `step-08-wish-context.md`
9. `step-09-wish-channel-handler.md`
10. `step-10-wish-expanded-auth.md`
11. `step-11-wish-subsystem.md`
12. `step-12-wish-session-metadata.md`
13. `step-13-wish-async-middleware.md`

### Phase 07 — VT / Zone / Mosaic / PTY / VCR (`phase-07-vt-zone-mosaic-pty/`)
1. `step-01-vt-decstbm.md`
2. `step-02-vt-decawm.md`
3. `step-03-vt-subparams.md`
4. `step-04-vt-scrollback.md`
5. `step-05-vt-sgr-underlines.md`
6. `step-06-vt-decom-decscusr-focus.md`
7. `step-07-vt-bce-combining-sync.md`
8. `step-08-zone-hover-tracker.md`
9. `step-09-zone-drag-tracker.md`
10. `step-10-zone-clickcounter-motion.md`
11. `step-11-mosaic-quarter-block.md`
12. `step-12-mosaic-delete-wezterm.md`
13. `step-13-mosaic-kitty-extras.md`
14. `step-14-mosaic-transparent-sixel-fallback.md`
15. `step-15-mosaic-animation.md`
16. `step-16-pty-openpty-ffi.md`
17. `step-17-pty-waitpid-ffi.md`
18. `step-18-pty-devtty-size-forward.md`
19. `step-19-pty-setctty-extraction.md`
20. `step-20-vcr-relative-timestamps.md`
21. `step-21-vcr-idle-trim-realtime.md`
22. `step-22-vcr-focuslostmsg.md`

### Phase 08 — Forms / Charts / Style / Freeze (`phase-08-forms-charts-style/`)
1. `step-01-prompt-validators.md`
2. `step-02-prompt-fuzzy.md`
3. `step-03-prompt-async-debounce.md`
4. `step-04-prompt-multiselect-validateall.md`
5. `step-05-bits-textinput-validate-restrict.md`
6. `step-06-bits-table-sort.md`
7. `step-07-bits-table-filter.md`
8. `step-08-bits-table-paginate.md`
9. `step-09-charts-axis-line-features.md`
10. `step-10-charts-aggregations-braille-themes.md`
11. `step-11-sprinkles-color-spacing-hsl-markup.md`
12. `step-12-sprinkles-gradient-patch-blink.md`
13. `step-13-freeze-segment-ligature-langdetect.md`
14. `step-14-freeze-themes-font-linehighlight.md`

### Phase 09 — Media / Games / Logs / Metrics / Query / Physics (`phase-09-media-games-logs/`)
1. `step-01-flip-imagecreatefromstring-timing.md`
2. `step-02-flip-dither-localcolor-transparency.md`
3. `step-03-flip-adaptive-cellsize-cache.md`
4. `step-04-hermit-item-filter-history.md`
5. `step-05-hermit-border-sigwinch-help.md`
6. `step-06-mines-chord-microtime-persist.md`
7. `step-07-mines-win-serialize-custom.md`
8. `step-08-tetris-srs-rotation.md`
9. `step-09-tetris-tspin-b2b-das.md`
10. `step-10-lister-filter-fuzzymatch.md`
11. `step-11-log-env-padlevel-keys.md`
12. `step-12-log-callerformatter-hooks-partsorder.md`
13. `step-13-metrics-histogram-descriptors.md`
14. `step-14-metrics-cardinality-instruments.md`
15. `step-15-query-schema-pagination-edit.md`
16. `step-16-query-savedqueries-explain-jsonnull.md`
17. `step-17-bounce-presets-cubicbezier.md`
18. `step-18-bounce-springchain-reducedmotion.md`
19. `step-19-flap-variable-gap.md`

### Phase 10 — Apps (`phase-10-apps/`)
1. `step-01-supercandy-copy-move-rename.md`
2. `step-02-supercandy-bulk-preview-async.md`
3. `step-03-stash-phase1.md`
4. `step-04-stash-phase2.md`
5. `step-05-stash-phase3.md`
6. `step-06-stash-phase4.md`
7. `step-07-toast-positions-escclose.md`
8. `step-08-toast-persistent-maxconcurrent.md`
9. `step-09-toast-progress-actions-history.md`
10. `step-10-table-virtualization-widths.md`
11. `step-11-table-multiline-borders.md`
12. `step-12-stickers-sticky-syncscroll.md`
13. `step-13-readline-history-arrows.md`
14. `step-14-readline-vi-emacs.md`
15. `step-15-readline-autosuggest-undo-highlight.md`
16. `step-16-spark-c0c1-underline-sospm.md`
17. `step-17-spark-json-streaming.md`
18. `step-18-tick-export-tags-ignore-gaps.md`
19. `step-19-tick-sqlite-ical-themes.md`
20. `step-20-calendar-range-eventstore.md`
21. `step-21-crumbs-pushdir-view-filter.md`
22. `step-22-crumbs-closable-url-semantic.md`
23. `step-23-boxer-align-margin.md`
24. `step-24-glow-syntax-streaming.md`
25. `step-25-glow-theme-watch-width.md`
26. `step-26-glow-additional-themes.md`
27. `step-27-wishlist-proxyjump-identityfiles.md`
28. `step-28-wishlist-import-sshconfig.md`
29. `step-29-crush-session-persistence.md`
30. `step-30-crush-streaming-compaction.md`
31. `step-31-crush-tools-syntax-slash.md`
32. `step-32-crush-mcp-claudemd.md`
33. `step-33-serve-osc52-http-smart.md`
34. `step-34-serve-git-daemon-real-daemon.md`
35. `step-35-skate-import-typo-ttl-stdin-tx.md`
36. `step-36-veil-backdrop-animation.md`
37. `step-37-veil-zindex-clickoutside-autosize-border.md`

### Phase 11 — Strategic-decision plans (`phase-11-strategic-plans/`)

Each writes a plan file only — no implementation.

1. `step-01-sugar-post-identity.md`
2. `step-02-candy-serve-tui.md`
3. `step-03-candy-vt-graphics.md`
4. `step-04-candy-flip-mosaic-split.md`

### Phase 12 — Deferred (`phase-12-deferred/`) — pause indefinitely per user

These are explicitly deferred. Do not start until the user signals it.

1. `step-01-macos-arm64-ioctl-fix.md`
2. `step-02-macos-flake-test-fix.md`
3. `step-03-darwin-stty-matrix.md`
4. `step-04-windows-conpty.md`
5. `step-05-sidecar-binary.md`
6. `step-06-pecl-extension.md`
7. `step-07-bsd-solaris-ioctls.md`
8. `step-08-multipump-promotion.md`
9. `step-09-expect-expansion.md`
10. `step-10-asciinema-cast-format.md`
11. `step-11-vcr-hooks-matchers-gzip-svg.md`
12. `step-12-posixprocess-async.md`
13. `step-13-ptypool-ssh-profile.md`

---

## Stop conditions

Stop immediately and surface to the user if:

- A subagent reports a blocker that cannot be resolved by the next-cycle
  fix subagent (e.g. an architectural decision needs the user's call).
- CI on master goes red and isn't fixed by a single follow-up step.
- The path-repo closure check (`tools/check-path-repos.php`) starts
  failing and the fix step can't restore it.
- A step file references a precondition that has not landed yet.

Otherwise: keep advancing the cycle. The user does not need approval
between steps; the supervisor advances autonomously through every
phase except phase 12.

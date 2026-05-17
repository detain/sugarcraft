# Documentation substep — between-step substep

After the real step + review + fix + tests-ci, freshen documentation
across the four audiences (heavily documented READMEs, end-user,
hub-admin, developer, and PHPDoc docblocks).

Read `_templates/subagent_brief.md` and `updates.md` first.

## Doc audiences (per touched lib)

For each lib the just-completed step touched, ensure the following
are current and consistent:

### 1. Lib's `README.md` — heavily documented

This is the canonical lib-level README. It must include:

- A one-paragraph "what it does" summary.
- `composer require sugarcraft/<slug>` install line.
- A "Quickstart" section with the simplest non-trivial example.
- A feature table or namespace table showing what the lib provides.
- Cross-reference to upstream (`Mirrors charmbracelet/<repo>`).
- A "Compared to" feature parity table when there's a clear external
  comparison (e.g. candy-pty's "Compared to node-pty / creack / portable-pty").
- A link to `.vhs/*.gif` for visual demos.
- A "Status" badge or row if the lib has one in `MATCHUPS.md`.
- An `## Architecture` section listing the public namespace tree if
  the lib has more than ~5 classes.

The just-completed step touched public API. The README must reflect
the new surface — methods, classes, options, env vars. If the README
already covered the surface conceptually, just add a row to the
relevant table.

### 2. End-user docs — `docs/lib/<slug>.md`

These pages are linked from `docs/index.html`. Audience: someone who
wants to USE the lib. Headings:

- **Install** — `composer require` + PHP version + ext requirements.
- **Quickstart** — copy-pasteable code that compiles.
- **Use cases** — three to five common usage patterns.
- **API reference** — link to PHPDoc-rendered output if the lib has it;
  otherwise list the public classes and one-line summaries.
- **Recipes** — a couple of practical scenarios.

If the step shipped a new visible feature (a CLI subcommand, an env
var, a public class, a flag), every doc page above gets an update.

### 3. Hub-admin docs — `docs/admin/<slug>.md` (if applicable)

Audience: someone deploying the lib in production (SSH servers,
dashboards, recording pipelines, etc.). Only relevant for libs that
run as services: candy-serve, candy-wish, candy-vcr, sugar-dash,
sugar-crush, candy-metrics.

Headings:

- **Operational concerns** — what the process does, signals it handles,
  files it writes.
- **Configuration** — every env var, config file format, CLI flag.
- **Monitoring** — what to scrape, healthcheck endpoints.
- **Failure modes** — what happens on crash, restart, version mismatch.
- **Backup / migration** — for state-bearing libs.

If the lib doesn't have a hub-admin page yet but the step graduated
it into "operational territory" (added a daemon mode, a recording CLI,
a metrics scraper), create the page.

### 4. Developer docs — `docs/dev/<slug>.md` (if applicable)

Audience: someone extending the lib. Only for libs with extension
points (plugin systems, middleware, custom backends): candy-pty,
candy-wish, sugar-dash, candy-vcr, candy-shell, candy-mosaic,
candy-flip.

Headings:

- **Extension points** — interfaces / abstract classes users implement.
- **Backend conventions** — for libs with multiple backends.
- **Testing your extension** — fixture patterns, mocks vs real.
- **Versioning policy** — what's stable, what's `@internal`.

### 5. PHPDoc docblocks

Every public class, method, property, and constant touched by the
step gets PHPDoc:

- One-line summary.
- `@param` / `@return` / `@throws` with concrete types and meaning.
- `Mirrors <upstream-repo>/<file>:<line>` when porting from a Go
  upstream.
- Non-obvious invariants explained inline.

No "increment counter" tier prose. No WHAT comments — only WHY.

### 6. `CALIBER_LEARNINGS.md` (touched lib's local file)

If a non-obvious pattern emerged during the step (a workaround, a
gotcha, an unexpected interaction), add a one-line entry:

```
[pattern:<short-slug>] — <one-line pattern + reason>
```

Don't dump everything; CALIBER_LEARNINGS is a short list, not a
journal.

### 7. `MATCHUPS.md` (root)

If the step bumped a lib's completion status (🔴 → 🟡, 🟡 → 🟢,
🟢 → 🚀), update the row.

### 8. `docs/index.html` (homepage)

If the step is a major user-visible feature (new lib status, headline
demo gif, new CLI subcommand), update the lib's tile description.
Major-only — most steps do nothing here.

## Output

Ship a docs PR on `ai/<original-slug>-docs` if anything changed.
If everything was already up-to-date, append
`docs for step <ID> · clean` to Done log and report to supervisor.

## Sequencing within this substep

You can do all four audience updates in one commit. They're related
and ship cleanly together. Don't try to ship four micro-PRs for one
step's docs.

## Process reminders (every subagent, every time)

- `unset GITHUB_TOKEN` **before every** `gh` invocation. Always. No
  exceptions.
- The full cycle ends on `master`: branch → commit → push →
  `unset GITHUB_TOKEN && gh pr create` →
  `unset GITHUB_TOKEN && gh pr merge <N> --merge --delete-branch` →
  `git checkout master && git pull --ff-only`. Confirm `git status`
  shows clean working tree on master before stopping.

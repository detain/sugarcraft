# BETWEEN-STEP — DOCS

> First read `plans/candy-query-fix/COMMON.md`. **Agent:** `oac:coder-agent`.
> The supervisor named the real step just completed; bring all documentation in line with it.

candy-query is meant to be **heavily documented**. Update every layer the step affects:

## Do

1. **PHPDoc docblocks** on every class/method the step added or changed: purpose, params,
   return, `@throws`; cite upstream (`Mirrors mysql-workbench <module>` / `Mirrors
   jorgerojas26/lazysql.<X>`); comment WHY not WHAT. Fill gaps in nearby code the step touched.
2. **README (`candy-query/README.md`)** — keep the feature matrix / quickstart / `composer
   require` accurate. If the step made a previously-dead feature actually work, move it from
   "planned" to "supported" and document its keybindings/usage.
3. **End-user docs** — usage of the affected admin page/feature (keys, what it shows, caveats),
   in the README's usage section (and any `docs/` user page if one exists for candy-query).
4. **Hub-admin / operator docs** — privileges required, server-version gating, MySQL vs
   Postgres vs SQLite differences, safety notes (e.g. KILL needs PROCESS priv; PS setup writes
   are privileged) for the affected feature.
5. **Developer docs** — architecture notes for the changed area (e.g. the async cache flow, the
   calc engine, the page lifecycle), in `CALIBER_LEARNINGS.md` (real gotchas only) and/or a
   `docs/` developer note. Update `docs/lib/candy-query.html` and the `docs/index.html` tile if
   the public API/feature set visibly changed.
6. Do NOT change code logic here — docs/comments only (docblocks are fine to add/edit).

## Ship

Branch `ai/candy-query-docs-<stepid>`, commit (author Joe Huss <detain@interserver.net>),
push, PR, merge, pull. End on master. Note in `updates.md` anything docs revealed (e.g. a
behaviour that contradicts the docs → `NOTE:`/`BLOCKER:` for follow-up).

> Reminder: `unset GITHUB_TOKEN` immediately before EVERY `gh` command. Do NOT run `caliber
> refresh`; if a hook stages Caliber files, unstage them.

# Brief — turn one comparison-1026 report into a validated patch fragment

Repo: `/home/sites/sugarcraft` (master, HEAD 5e9195687). Dir: `prompt_kit/findings/comparison-1026/`.
Target doc: `candy-code/COMPARISON.md`. It is **READ-ONLY** for you: never write it. Other agents read it concurrently.

## Goal

Convert ONE research report (named in your prompt) into a strict JSON patch fragment, `patch-<name>.json`, in this dir. Also write a short `patch-<name>.notes.md` next to it. These two files are your ONLY writes; scratch copies go under the session scratchpad if you need them.

The orchestrator merges all fragments and applies them once with `apply_comparison.py`, so your fragment must apply cleanly and without manual fixes.

## Doc facts

- §3 matrix rows look like `| ID | Feature | cc | web | claude | oc1 | oc2 | claw |`. Every row has exactly 9 `|`, so a cell must never contain `|` (use `/`).
- A cell is one glyph from `✅ 🟡 🔌 🧪 ❌ ➖ ❓ ·`, a space, then ≤130 characters of text.
  - The OpenCode 2.x column marks cells that differ from 1.x with `Δ` after the glyph (`✅ Δ …`); keep that convention.
- Appendix A holds one `<details><summary><b>ID</b> …` block per row, with exactly 6 bullets in column order:
  - `- **<label>** <glyph> — <text> — _Evidence:_ <evidence>`
  - The bullet glyph MUST equal the §3 cell glyph.
- Column keys are `cc` (candy-code), `web` (candy-code-web), `claude`, `oc1` (OpenCode 1.x), `oc2` (OpenCode 2.x) and `claw` (OpenClaw).
- **Voice: current-state reference.**
  - NO commit SHAs, wave/lane tags, dates-as-history, "fixed since", "remediated", "superseded", "now that", "was X", "_2026-10-xx …_" clauses.
  - If a report marks a fact UNVERIFIED, keep the doc's current wording for that part rather than inserting the caveat.
- Evidence style: candy-code paths are relative to `candy-code/` (e.g. `src/Chat.php:123`); web paths use `web:components/X.vue`. Competitor evidence keeps the report's citation style.

## Patch schema (exactly what `apply_comparison.py` accepts)

```json
{
  "cells": [
    {"row": "MOD-03", "col": "cc",
     "expect": "<first ~40 chars of the CURRENT §3 cell, verbatim from the doc>",
     "new": "<glyph> <text>",
     "detail": "<glyph> — <text> — _Evidence:_ <ev>",
     "why": "<one line>"}
  ],
  "new_rows": [
    {"id": "MCP-N2", "feature": "🆕 <feature name>",
     "cells": {"cc": "<glyph> …", "web": "…", "claude": "…", "oc1": "…", "oc2": "…", "claw": "…"},
     "details": {"cc": "<glyph> — … — _Evidence:_ …", "…": "…"}}
  ],
  "narrative": [ {"old": "<exact text, occurs EXACTLY once in the doc>", "new": "<replacement>"} ]
}
```

### `cells`

- `new` is omitted for detail-only changes. `detail` is omitted when the existing bullet stays valid AND the glyph did not change.
- If the glyph changes, you MUST also supply `detail`. Start from the current bullet text and update it, and give it the new glyph.
- Instruction-style report entries need to be resolved into full bullet bodies:
  - "drop the X clause"
  - "replace X with Y"
  - "cell change only; the existing detail stays valid"
  - To resolve one, read the current Appendix A bullet (`grep -n '<summary><b>ROW</b>' -A 8 candy-code/COMPARISON.md`) and write the full edited body.
- Get `expect` from the doc, not the report. Reports often wrap it in backticks or truncate it.

### `new_rows`

- Use the report's proposed ID.
- Fill all 6 columns.
- Rate the candy-code and candy-code-web cells yourself from the live source, with a quick targeted grep under `candy-code/src`, `candy-code/builtin-commands` and `candy-code-web/src-web`. Cite the evidence you used.
- For competitor columns, use the report's data where it has some; otherwise write `· Not assessed`.

### `narrative`

- Turn each `§n Lx: current "…" → new "…"` entry into one `old`/`new` pair.
- Verify that `old` occurs exactly once: `python3 -c "import sys;t=open('candy-code/COMPARISON.md').read();print(t.count(sys.argv[1]))" '<old>'`. If it occurs more than once, widen it until it is unique.
- Skip entries that only rename the product version in headers or bullet labels (e.g. "Claude Code 2.1.301" → "2.1.311"); the orchestrator handles those via a `headers` map. List them in your notes.

### General

- Do NOT emit a `headers` key.
- No two entries may target the same (row, col).
- No two narrative `old` strings may overlap.

## Validate before you finish

```sh
cd /home/sites/sugarcraft && python3 -I prompt_kit/findings/comparison-1026/apply_comparison.py candy-code/COMPARISON.md prompt_kit/findings/comparison-1026/patch-<name>.json --check
```

`--check` runs every step but writes nothing; it must exit 0. Then check the following with a small script over your JSON:

- every `new` and every new-row cell is ≤ 132 chars total (glyph + space + 130);
- none contains `|`;
- every `detail` glyph equals its cell glyph (the `new` glyph, else the current one).

`prompt_kit/findings/comparison-1026/reports_to_patch.py` parses the simple `### ROW · col` blocks. Use it as a starting point if it helps, but check its output: it does not resolve instruction-style details.

## `patch-<name>.notes.md` (short)

- Counts: cells, detail-only, new rows, narrative.
- Items skipped, with a reason.
- Items needing orchestrator judgment, e.g. a report claim you could not reconcile with the doc.
- Header/version renames the report asks for.
- Any scoreboard hints the report gives. Don't act on these; the applier recomputes the scoreboard.

Your final message: a 5-line summary with the counts and the path. Nothing else.

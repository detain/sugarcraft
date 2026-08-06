---
name: matchups-sync
description: Keeps docs/MATCHUPS.md and PROJECT_NAMES.md in sync whenever a new port lands. Automatically run at the end of any workflow stage that adds a library. Triggers on "sync matchups", "new port landed", or when a lib is added to the monorepo.
license: MIT
user-invocable: false
metadata:
  author: sugar-crush-team
  version: "1.0.0"
  phase: P7
  step: P7.S17
---

# Matchups Sync

Keeps `docs/MATCHUPS.md` and `PROJECT_NAMES.md` synchronized whenever a new SugarCraft port lands in the monorepo. This skill is **user-invocable: false** — it runs automatically at the end of any workflow stage that adds a lib, because concurrent hand-edits to these two files are an explicit collision risk.

## When It Runs

- After `scaffold-library` or `add-library-checklist` completes
- After any workflow stage that creates a new `<slug>/` directory with `composer.json`
- When an agent finishes adding a lib and commits

## What Gets Updated

### docs/MATCHUPS.md

Add a row to the upstream → SugarCraft mapping table:

```
| <upstream> | <sugarcraft-repo> | 🟢 | <notes> |
```

Status indicators:
- 🟢 — fully ported and tested
- 🟡 — partially ported, in progress
- 🔴 — stub only

### PROJECT_NAMES.md

Add to the naming rulebook if this is a new category prefix:

```
<prefix>  <category>   <example>
```

Also update the prefix cheat sheet for any new `candy-`/`sugar-`/`honey-` slug.

## Step-by-Step

### 1. Read Current State

```bash
# Read the last 20 lines of MATCHUPS.md to find the table structure
tail -20 docs/MATCHUPS.md

# Read the last 20 lines of PROJECT_NAMES.md
tail -20 PROJECT_NAMES.md
```

### 2. Identify the New Lib's Metadata

From `<slug>/composer.json`:
- `name` (e.g. `sugarcraft/sugar-charts`)
- `description`
- `keywords` (contains upstream Go repo name)

From `<slug>/README.md`:
- Upstream source URL
- Status

### 3. Add to MATCHUPS.md

```markdown
| <upstream-go-name> | <sugarcraft/slug> | 🟢 | <one-line description> |
```

Insert in alphabetical order by upstream name within the appropriate section.

### 4. Add to PROJECT_NAMES.md

If the lib uses a new prefix:
```
candy  foundation/system   candy-core, candy-sprinkles
sugar  components/data     sugar-bits, sugar-charts
honey  math/physics        honey-bounce, honey-fizz
```

If the lib adds a new slug under an existing prefix, add it to the examples list.

### 5. Verify No Collision

```bash
# Confirm the upstream name is not already in MATCHUPS.md
grep -c "<upstream>" docs/MATCHUPS.md  # must be 1 (the new entry only)

# Confirm the slug is not already in MATCHUPS.md
grep -c "sugarcraft/<slug>" docs/MATCHUPS.md  # must be 1
```

### 6. Commit with Context

```bash
git add docs/MATCHUPS.md PROJECT_NAMES.md
git commit -m "docs: sync MATCHUPS.md and PROJECT_NAMES.md for <slug>

- Added upstream mapping for <upstream>
- Updated PROJECT_NAMES.md prefix list
[ci skip]"
```

## Validation

After the sync, confirm the files parse cleanly:

```bash
# Verify MATCHUPS.md table is well-formed (basic check)
grep -E "^\|" docs/MATCHUPS.md | wc -l   # should be > 1 (header + rows)

# Verify PROJECT_NAMES.md is valid markdown
python3 -c "import markdown; open('PROJECT_NAMES.md').read()" 2>&1 || echo "PARSE ERROR"
```

## Auto-Invocation Contract

This skill is invoked automatically by the scaffold workflow. It should **not** be manually invoked — doing so risks stomping on concurrent edits. If you need to force a re-sync, coordinate with whoever last touched the files.

## File Ownership

| File | Purpose | Last-edit risk |
|------|---------|----------------|
| `docs/MATCHUPS.md` | Upstream → SugarCraft mapping | HIGH — many agents may touch it |
| `PROJECT_NAMES.md` | Naming conventions + prefix cheat sheet | MEDIUM — added during new-lib flow |

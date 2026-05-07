---
name: composer-upgrade
description: Guides PHP project upgrades using Composer commands. Use when helping users upgrade PHP packages, check for security vulnerabilities with `composer audit`, prioritize which packages to upgrade first, understand dependency conflicts, interpret `composer outdated` output, use `composer why-not` to diagnose version constraints, use `composer why` to trace dependencies, use `composer bump` to harden version constraints after upgrading, plan safe upgrade paths, resolve package version conflicts in composer.json, or resolve merge conflicts in composer.lock. Trigger this skill whenever the user mentions composer packages, PHP dependencies, outdated packages, CVEs in PHP projects, or security advisories.
---

# Composer Upgrade

## Upgrade Workflow

Follow this sequence when upgrading a PHP project:

1. **Check for security issues** → `composer audit` — fixes here are highest priority
2. **Identify what's outdated** → `composer outdated --format=json`
3. **Prioritize** — packages with CVEs AND outdated go first; see [references/audit.md](references/audit.md)
4. **Diagnose blockers** → `composer why-not vendor/package version` — note any sub-packages blocking the update
5. **Trace dependencies** → `composer why vendor/package`
6. **Update packages** → `composer update vendor/package` — if blocked, include identified blockers: `composer update vendor/package blocker/one blocker/two`
7. **Test**
8. **Harden constraints** → `composer bump` (applications only)
9. **Re-audit** → `composer audit` to confirm all advisories are resolved

See [references/commands.md](references/commands.md) for full flag reference, including global flags for non-interactive use (`--no-interaction --no-progress --no-ansi`).
See [references/upgrade-workflow.md](references/upgrade-workflow.md) for detailed strategies, including merge conflict resolution.
See [references/audit.md](references/audit.md) for security audit details, severity tiers, and how to build a prioritized package list.

## Resolving composer.lock Merge Conflicts

When `composer.lock` has a merge conflict, use `scripts/diff_lock.py` to compare both sides and generate the commands needed to reconcile them.

**During an active merge conflict:**

```bash
# Compare HEAD vs MERGE_HEAD automatically and output composer commands
python3 scripts/diff_lock.py --conflict

# Human-readable summary of what changed
python3 scripts/diff_lock.py --conflict --format=summary
```

**Compare any two branches or files:**

```bash
python3 scripts/diff_lock.py main:composer.lock feature-branch:composer.lock
python3 scripts/diff_lock.py HEAD:composer.lock MERGE_HEAD:composer.lock
python3 scripts/diff_lock.py old.lock new.lock
```

The script outputs `composer require` / `composer remove` commands that move packages from the source state to the target state. Run the generated commands, then commit the result.

See [references/upgrade-workflow.md](references/upgrade-workflow.md) — "Workflow: Merge Conflict in composer.lock" — for the full step-by-step process.

## Core Commands

See [references/commands.md](references/commands.md) for full flag reference and version constraint quick reference.

### composer outdated
```bash
composer outdated --format=json           # preferred when parsing output (fewer tokens)
composer outdated --direct --format=json  # only direct deps
```
**Red** = major bump (breaking changes likely). **Yellow** = minor/patch (safe). `!` = not semver-safe.

### composer why-not
```bash
composer why-not vendor/package 2.0      # what prevents this upgrade
composer why-not php 8.2                 # what blocks a PHP version bump
```
Output shows the dependency chain: which packages require conflicting versions.

### composer why
```bash
composer why vendor/package              # which installed packages depend on this one
```

### composer update
```bash
composer update vendor/package --no-interaction --no-progress --no-ansi
composer update vendor/package --dry-run --no-interaction --no-ansi   # preview first

# If blocked, first identify which sub-dependencies are preventing the update:
composer why-not vendor/package 2.0
# Then include only those specific blockers in the update command:
composer update vendor/package blocker/one blocker/two --no-interaction --no-progress --no-ansi
```

## Common Patterns

### "Why can't I update X?"

```bash
composer why-not vendor/package 3.0
```

Read the output to find which package constrains it, then check if that constraining package itself can be updated.

### "What's blocking my PHP version upgrade?"

```bash
composer why-not php 8.2
```

Lists every package that lacks a `php: ^8.2` constraint, sorted by most blocking.

### "Safe incremental upgrade"

Prefer updating direct dependencies one at a time with `--dry-run` first:

```bash
composer update vendor/package --dry-run --no-interaction --no-ansi
composer update vendor/package --no-interaction --no-progress --no-ansi
```

If blocked, use `composer why-not` to identify the specific packages preventing the update, then include only those in the update command:

```bash
composer why-not vendor/package 2.0
# → reveals blocker/one and blocker/two are preventing the update
composer update vendor/package blocker/one blocker/two --no-interaction --no-progress --no-ansi
```

### Bumping a major version constraint

Prefer `composer require` over hand-editing `composer.json` — it updates the constraint and resolves the lock in one step:

```bash
composer require vendor/package:"^3.0" --dry-run --no-interaction --no-ansi
composer require vendor/package:"^3.0" --no-interaction --no-progress --no-ansi
```

For risky upgrades (auth, permissions, database layers), use a dual constraint to test before committing:

```bash
# Allow both old and new major versions while testing
composer require vendor/package:"^2.5.0|^3.0" --no-interaction --no-progress --no-ansi
# If tests pass, lock in the new version:
composer require vendor/package:"^3.0" --no-interaction --no-progress --no-ansi
```

See [references/upgrade-workflow.md](references/upgrade-workflow.md) — "Techniques for Major Version Upgrades" — for more detail.

### Hardening constraints after upgrading (applications)

```bash
composer bump                  # raise lower bounds in composer.json to installed versions
composer bump --dev-only       # only require-dev (safe for libraries too)
```

Before: `"symfony/console": "^6.0"` → After: `"symfony/console": "^6.4.3"` (the `^` is preserved, so future minor/patch upgrades still work).

See [references/commands.md](references/commands.md) for auto-bump config and full flag reference.

> **Applications only**: Do not run `composer bump` (without `--dev-only`) on libraries — it narrows constraints in ways that break downstream consumers.

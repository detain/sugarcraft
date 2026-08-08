---
name: explore-codebase
description: Fast read-only pass for tracing an unfamiliar lib's structure before editing it. Use when you need to understand a candy-*/sugar-*/honey-* lib's layout, dependencies, and conventions before making changes — without spawning a full sub-agent. Triggers automatically when an agent first touches a file inside an unfamiliar lib.
license: MIT
metadata:
  author: sugar-crush-team
  version: "1.0.0"
  phase: P7
  step: P7.S17
---

# Explore Codebase

Fast, focused read-only pass to understand a library's structure before editing it. Designed for zero-side-effect auto-invocation.

## When to Use

- First time touching a file in an unfamiliar lib
- Before reading a lib's `CALIBER_LEARNINGS.md`
- Before drafting edits to a lib with nested instruction files
- When a preset needs to understand a lib's conventions without a full sub-agent spawn

## How It Works

### 1. Identify the Target Lib

From the file being edited, infer the lib slug:
```
src/Widget.php              -> sugar-widget (find in sugar-*/src/)
src/Charm.php               -> candy-charm  (find in candy-*/src/)
tests/WidgetTest.php        -> infer from src/Widget.php path
```

### 2. Map the Directory Layout

Read these files in order:

| File | Why |
|------|-----|
| `<slug>/composer.json` | PHP version, dependencies, autoload PSR-4 prefix |
| `<slug>/CALIBER_LEARNINGS.md` | Project-specific patterns and anti-patterns |
| `<slug>/README.md` | High-level purpose, upstream source |
| `<slug>/src/<Class>.php` | Entry point class, namespace, public API shape |
| `<slug>/tests/` | Test structure, naming, fixture conventions |
| `<slug>/phpunit.xml` | Bootstrap, source inclusion, bootstrap |

Stop once you have enough context. Do not read every source file.

### 3. Extract Key Conventions

From `composer.json`:
- PHP version constraint (e.g. `^8.3`)
- Namespace prefix (e.g. `SugarCraft\Shine\`)
- Internal dependencies (`sugarcraft/*` path repos)

From `CALIBER_LEARNINGS.md` (if present):
- Immutability patterns (e.g. `with*()` builder convention)
- i18n approach (`Lang::t()` vs direct gettext)
- Test invariants

From the entry class:
- Is it a `final class`?
- Does it use constructor promotion?
- What is the package's primary abstraction (Model, Component, Renderer, etc.)?

### 4. Output Summary

Emit a brief digest (do not write files):

```
Lib: sugar-bits (SugarCraft\Bits\)
PHP: ^8.3 | deps: candy-core
Type: TUI component library | upstream: charmbracelet/bubbletea
Entry: src/Stopwatch/Stopwatch.php — final class, readonly props, Model contract
Tests: behavior + snapshot | fixtures in tests/fixtures/
Conventions: with*() immutable builders, Lang::t() i18n, Theme::ansi() factory
```

## Rules

- **Read only.** Never write, edit, or create files during this pass.
- **No side effects.** This skill is safe for auto-invocation; it changes no state.
- **Stop early.** Once you have the shape of the lib, stop reading more files.
- **No deep dive.** This is a first-pass orientation, not an audit.

## Auto-Invocation

This skill is eligible for auto-invocation because it has no side effects. Any preset can call it when an agent first touches a file in a lib whose `CLAUDE.md` or `AGENTS.md` has not yet been loaded into the session context.

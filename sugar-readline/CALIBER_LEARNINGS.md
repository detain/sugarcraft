# SugarReadline — Caliber Learnings

## Accumulated patterns and gotchas

> Fill in as lessons are learned during implementation and testing.

## Key learnings

### 2026-05-30 — InputDriver is injectable for tests; production defaults to STDIN
Pattern: Accept an `InputDriver` in the constructor, defaulting to `StreamInputDriver::fromStdin()`. This makes the readline loop testable by injecting a driver over a fixture stream.
Anti-pattern: Reaching for `STDIN` directly in the run loop — hard to test, couples to the global TTY.
Source: step-21 ai/sugar-readline-input

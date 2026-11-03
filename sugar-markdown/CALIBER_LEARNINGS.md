# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:assert-golden-ansi]** Use `assertGoldenAnsi` for any new `render()` test. Fixture files live in `tests/fixtures/` with a `.golden` extension. `UPDATE_GOLDENS=1` is create-only — an existing differing fixture still fails; to re-baseline, delete the file and rerun with the flag set. Mirrors: `tests/GoldenRenderTest.php`.

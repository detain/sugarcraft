# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[env]** `unset GITHUB_TOKEN` before every `gh` invocation (`gh pr create`, `gh pr merge`, etc.) in this repo. The repo-level GITHUB_TOKEN clashes with the gh CLI's stored credentials and produces auth failures or wrong-account commits — used consistently across phase PRs to land cleanly.
- **[pattern]** When changes touch `candy-core`, regression-check the downstream packages before opening the PR: run `vendor/bin/phpunit` in `sugar-bits`, `sugar-prompt`, and `candy-shell` (chain them: `cd ... && vendor/bin/phpunit && cd ... && vendor/bin/phpunit`). They consume the runtime and break silently if a Cmd/Msg surface shifts.
- **[gotcha]** Don't bottom-stack a second `final class` inside an existing PSR-4 file (e.g. defining `Point` at the end of `src/Vector.php`). Composer autoloading expects one class per file matching the class name, so `use CandyCore\Bounce\Point` fails with `Class X not found` even though tests in the same namespace see other classes fine — extract each class into its own file matching the class name.
- **[pattern]** For Bubble-Tea-style runtime ports, when adding a new sentinel Msg type that the Program intercepts (e.g. `SequenceMsg`, `ExecRequest`, `SuspendMsg`), the dispatch order matters: handle the sentinel branches in `Program::dispatch()` BEFORE the `WithFilter` pre-processor and BEFORE `model->update()`, otherwise filters can drop runtime sentinels and the Program will hang waiting for a teardown that never fires.
